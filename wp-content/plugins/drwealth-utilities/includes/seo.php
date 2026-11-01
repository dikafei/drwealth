<?php
/**
 * SEO utilities: staging noindex + paywalled JSON-LD cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 2) Noindex everything that isn't the production host.
 *    Host-based, so it can't accidentally travel to production the way the
 *    "Discourage search engines" setting can when a DB is migrated.
 * ---------------------------------------------------------------------- */

function drwealth_seo_is_production() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = strtolower( (string) $host );

	// Production = drwealth.com and www.drwealth.com. Override via filter if needed.
	$production_hosts = apply_filters( 'drwealth_seo_production_hosts', array( 'drwealth.com', 'www.drwealth.com' ) );

	return in_array( $host, $production_hosts, true );
}

if ( ! drwealth_seo_is_production() ) {
	// HTTP header — covers HTML, feeds, PDFs, everything WordPress serves.
	add_action(
		'send_headers',
		function () {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		}
	);

	// Meta tag + core robots handling.
	add_filter(
		'wp_robots',
		function ( $robots ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
			return $robots;
		},
		999
	);

	// Disallow all in virtual robots.txt (only applies if no physical robots.txt exists).
	add_filter(
		'robots_txt',
		function () {
			return "User-agent: *\nDisallow: /\n";
		},
		999
	);
}

/* -------------------------------------------------------------------------
 * 1) Paywall: strip full text from JSON-LD on gated posts + mark as paywalled.
 *    Works on the final HTML, so it doesn't depend on JNews internals.
 * ---------------------------------------------------------------------- */

/**
 * Is the current singular post gated for the current visitor?
 */
function drwealth_seo_post_is_gated() {
	if ( ! is_singular() ) {
		return false;
	}

	$post_id = get_queried_object_id();

	if ( ! $post_id ) {
		return false;
	}

	// A free unlock counts as "can view": the full text is on the page anyway.
	if ( function_exists( 'drwealth_user_has_unlocked' ) && drwealth_user_has_unlocked( $post_id ) ) {
		return false;
	}

	$user_id    = get_current_user_id();
	$restricted = function_exists( 'wc_memberships_is_post_content_restricted' ) && wc_memberships_is_post_content_restricted( $post_id );
	$in_premium = has_category( 'premium', $post_id );

	// Logged-out visitors can never view restricted content, so skip the
	// per-user capability check for them.
	$can_view = false;
	if ( $user_id ) {
		$can_view = function_exists( 'wc_memberships_user_can' )
			? (bool) wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) )
			: current_user_can( 'edit_post', $post_id );
	}

	$gated = ( $restricted || $in_premium ) && ! $can_view;

	return $gated;
}

/**
 * Recursively clean one decoded JSON-LD node.
 */
function drwealth_seo_clean_node( $node, $css_selector ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}

	foreach ( $node as $key => $value ) {
		if ( is_array( $value ) ) {
			$node[ $key ] = drwealth_seo_clean_node( $value, $css_selector );
		}
	}

	// The body text must never be exposed, whatever the node type is.
	unset( $node['articleBody'] );

	// JNews emits lowercase types ("article", "hentry"), so compare case-insensitively.
	$type  = isset( $node['@type'] ) ? array_map( 'strtolower', array_filter( (array) $node['@type'], 'is_string' ) ) : array();
	$is_ld = array_intersect( $type, array( 'article', 'newsarticle', 'blogposting', 'webpage', 'hentry' ) );

	if ( $is_ld ) {
		unset( $node['text'] );

		// "article" isn't a valid schema.org type (case-sensitive); fix it so Google reads the paywall markup.
		if ( isset( $node['@type'] ) && 'article' === $node['@type'] ) {
			$node['@type'] = 'Article';
		}

		// Anything else long enough to be body text gets cut to a teaser.
		foreach ( array( 'description', 'abstract' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && mb_strlen( $node[ $key ] ) > 300 ) {
				$node[ $key ] = mb_substr( wp_strip_all_tags( $node[ $key ] ), 0, 297 ) . '...';
			}
		}

		// Google's paywalled-content markup.
		$node['isAccessibleForFree'] = false;
		$node['hasPart']             = array(
			'@type'               => 'WebPageElement',
			'isAccessibleForFree' => false,
			'cssSelector'         => $css_selector,
		);
	}

	return $node;
}

function drwealth_seo_filter_html( $html ) {
	if ( false === stripos( $html, 'application/ld+json' ) ) {
		return $html;
	}

	// Selector wrapping the gated part of the article. Adjust if the theme differs.
	$css_selector = apply_filters( 'drwealth_seo_paywall_css_selector', '.content-inner, .entry-content, .wp-block-post-content' );

	return preg_replace_callback(
		'#(<script[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
		function ( $m ) use ( $css_selector ) {
			$data = json_decode( trim( $m[2] ), true );

			if ( null === $data ) {
				// Unparseable — fail safe by dropping the block rather than leaking it.
				return '';
			}

			$data = drwealth_seo_clean_node( $data, $css_selector );

			return $m[1] . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . $m[3];
		},
		$html
	);
}

add_action(
	'template_redirect',
	function () {
		if ( drwealth_seo_post_is_gated() ) {
			ob_start( 'drwealth_seo_filter_html' );
		}
	},
	0
);
