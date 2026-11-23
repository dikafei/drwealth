<?php
/**
 * Post source: shows where each post came from (live site, Finbite Insights, Growth Dragons)
 * as a column on Posts > All Posts, with a filter dropdown, so the content can be checked
 * source by source. Admin only: nothing is shown to readers and no categories are used.
 *
 * Sources (from the post meta the Substack importer sets):
 *   live         no Substack meta: a WordPress post that is not in Substack
 *   live_match_*  was already on the site and matches a Substack post (linked, not changed)
 *   finbite/growth  created by the Substack import (_drwealth_substack_origin = created)
 * So "live" + "live_match" = what live had before the import.
 *
 * Also hides the extra list filters (Live Sync, Substack import, formats) that SinYee
 * doesn't need. Turn them back on with: add_filter( 'drwealth_show_extra_post_filters', '__return_true' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DRWEALTH_SRC_PUB_KEY    = '_drwealth_substack_publication';
const DRWEALTH_SRC_ORIGIN_KEY = '_drwealth_substack_origin';

/** source key => label */
function drwealth_post_source_labels() {
	return array(
		'live'       => 'WordPress (live site)',
		'live_match_finbite' => 'WordPress (live, same as a Finbite post)',
		'live_match_growth'  => 'WordPress (live, same as a Growth Dragons post)',
		'finbite'            => 'Finbite Insights (new)',
		'growth'             => 'Growth Dragons (new)',
	);
}

/** Which source a post came from. */
function drwealth_post_source( $post_id ) {
	$pub = get_post_meta( $post_id, DRWEALTH_SRC_PUB_KEY, true );

	if ( ! in_array( $pub, array( 'finbiteinsights', 'growthdragons' ), true ) ) {
		return 'live';
	}

	if ( 'created' !== get_post_meta( $post_id, DRWEALTH_SRC_ORIGIN_KEY, true ) ) {
		return 'finbiteinsights' === $pub ? 'live_match_finbite' : 'live_match_growth';
	}

	return 'finbiteinsights' === $pub ? 'finbite' : 'growth';
}

add_filter(
	'manage_post_posts_columns', // Posts only (not products, plans, orders...).
	function ( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['dw_source'] = 'Source';
			}
		}

		return $out;
	}
);

add_action(
	'manage_post_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'dw_source' !== $column ) {
			return;
		}

		$labels = drwealth_post_source_labels();
		$source = drwealth_post_source( $post_id );
		echo esc_html( $labels[ $source ] );

	},
	10,
	2
);

/** Number of posts per source (excluding trash and auto-draft). */
function drwealth_post_source_counts() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT pub.meta_value AS pub, o.meta_value AS origin, COUNT(*) AS n
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} pub ON pub.post_id = p.ID AND pub.meta_key = '" . DRWEALTH_SRC_PUB_KEY . "'
		LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '" . DRWEALTH_SRC_ORIGIN_KEY . "'
		WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft')
		GROUP BY pub.meta_value, o.meta_value" // phpcs:ignore WordPress.DB
	);

	$out = array_fill_keys( array_keys( drwealth_post_source_labels() ), 0 );

	foreach ( $rows as $r ) {
		if ( ! in_array( $r->pub, array( 'finbiteinsights', 'growthdragons' ), true ) ) {
			$out['live'] += (int) $r->n;
		} elseif ( 'created' !== $r->origin ) {
			$out[ 'finbiteinsights' === $r->pub ? 'live_match_finbite' : 'live_match_growth' ] += (int) $r->n;
		} elseif ( 'finbiteinsights' === $r->pub ) {
			$out['finbite'] += (int) $r->n;
		} else {
			$out['growth'] += (int) $r->n;
		}
	}

	return $out;
}

add_action(
	'restrict_manage_posts',
	function ( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}

		$current = isset( $_GET['dw_source'] ) ? sanitize_key( wp_unslash( $_GET['dw_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$counts  = drwealth_post_source_counts();

		echo '<select name="dw_source"><option value="">All sources</option>';

		foreach ( drwealth_post_source_labels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s (%d)</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $label ),
				(int) $counts[ $key ]
			);
		}

		echo '</select>';
	}
);

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || 'post' !== ( $query->get( 'post_type' ) ?: 'post' ) ) {
			return;
		}

		if ( empty( $_GET['dw_source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$source = sanitize_key( wp_unslash( $_GET['dw_source'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$pubs   = array( 'finbiteinsights', 'growthdragons' );

		switch ( $source ) {
			case 'finbite':
			case 'growth':
				$meta = array(
					array( 'key' => DRWEALTH_SRC_PUB_KEY, 'value' => 'finbite' === $source ? 'finbiteinsights' : 'growthdragons' ),
					array( 'key' => DRWEALTH_SRC_ORIGIN_KEY, 'value' => 'created' ),
				);
				break;
			case 'live_match_finbite':
			case 'live_match_growth':
				$meta = array(
					array( 'key' => DRWEALTH_SRC_PUB_KEY, 'value' => 'live_match_finbite' === $source ? 'finbiteinsights' : 'growthdragons' ),
					array(
						'relation' => 'OR',
						array( 'key' => DRWEALTH_SRC_ORIGIN_KEY, 'compare' => 'NOT EXISTS' ),
						array( 'key' => DRWEALTH_SRC_ORIGIN_KEY, 'value' => 'created', 'compare' => '!=' ),
					),
				);
				break;
			case 'live':
				$meta = array(
					'relation' => 'OR',
					array( 'key' => DRWEALTH_SRC_PUB_KEY, 'compare' => 'NOT EXISTS' ),
					array( 'key' => DRWEALTH_SRC_PUB_KEY, 'value' => $pubs, 'compare' => 'NOT IN' ),
				);
				break;
			default:
				return;
		}

		$meta['relation'] = $meta['relation'] ?? 'AND';
		$existing         = (array) $query->get( 'meta_query' );
		$query->set( 'meta_query', $existing ? array( 'relation' => 'AND', $existing, $meta ) : $meta );
	}
);

/* Hide the filters SinYee doesn't need (Live Sync status, Substack import, formats). */
add_filter(
	'disable_formats_dropdown',
	function ( $disable ) {
		return apply_filters( 'drwealth_show_extra_post_filters', false ) ? $disable : true;
	}
);

add_action(
	'admin_head-edit.php',
	function () {
		if ( apply_filters( 'drwealth_show_extra_post_filters', false ) ) {
			return;
		}

		echo '<style>select[name="dw_sync"],select[name="dw_substack"]{display:none!important}</style>';
	}
);
