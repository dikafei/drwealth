<?php
/**
 * Core: fetch from live, map users/terms/images, create or update one post, run loop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DW_SYNC_OPTION = 'drwealth_live_sync_settings';
const DW_SYNC_STATE  = 'drwealth_live_sync_state';
const DW_SYNC_LOG    = 'drwealth_live_sync_log';
const DW_SYNC_CRON   = 'drwealth_live_sync_cron';
const DW_SYNC_CONT   = 'drwealth_live_sync_continue';

/* ---------------------------------------------------------------- Settings */

function dw_sync_default_strip() {
	return implode(
		"\n",
		array(
			'~<!--\s*wp:html\s*-->(?:(?!<!--\s*/wp:html).)*?beehiiv.*?<!--\s*/wp:html\s*-->~is',
			'~<iframe[^>]*beehiiv[^>]*>.*?</iframe>~is',
			'~<script[^>]*beehiiv[^>]*>.*?</script>~is',
		)
	);
}

function dw_sync_settings() {
	return wp_parse_args(
		get_option( DW_SYNC_OPTION, array() ),
		array(
			'url'          => 'https://drwealth.com',
			'token'        => '',
			'types'        => 'post',
			'since'        => '',
			'drafts_since' => '2025-03-12',
			'tables'       => '13,86,95', // TablePress table IDs kept in sync from live (existing staging tables only).
			'daily'        => 1,
			'mirror_removed' => 1,
			'conflict'     => 'skip', // skip|overwrite: post also edited on staging since last sync.
			'category_map' => '',     // "live-slug=staging-slug", one per line.
			'strip'        => dw_sync_default_strip(),
			'force_ids'    => '',
		)
	);
}

function dw_sync_state() {
	return wp_parse_args(
		get_option( DW_SYNC_STATE, array() ),
		array(
			'cursor_mod' => '',
			'cursor_id'  => 0,
			'last_run'   => '',
			'last_done'  => '',
			'stats'      => array(),
		)
	);
}

function dw_sync_log( $level, $live_id, $local_id, $msg ) {
	$log = get_option( DW_SYNC_LOG, array() );
	array_unshift( $log, array( current_time( 'mysql' ), $level, (int) $live_id, (int) $local_id, $msg ) );
	update_option( DW_SYNC_LOG, array_slice( $log, 0, 400 ), false );
}

/* --------------------------------------------------------------- Live fetch */

function dw_sync_fetch( $route, $args = array() ) {
	$s = dw_sync_settings();

	if ( ! $s['url'] || ! $s['token'] ) {
		return new WP_Error( 'dw_nocfg', 'Set the live URL and token first.' );
	}

	$args['rest_route'] = '/drwealth-sync/v1/' . ltrim( $route, '/' );
	$url                = add_query_arg( $args, trailingslashit( $s['url'] ) );
	$res                = wp_remote_get(
		$url,
		array(
			'timeout' => 90,
			'headers' => array( 'X-DrWealth-Token' => $s['token'] ),
		)
	);

	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( 200 !== $code || ! is_array( $body ) ) {
		return new WP_Error( 'dw_http', 'Live answered HTTP ' . $code . ( is_array( $body ) && ! empty( $body['message'] ) ? ': ' . $body['message'] : '' ) );
	}

	return $body;
}

function dw_sync_types_arg() {
	$s = dw_sync_settings();

	return implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', $s['types'] ) ) ) );
}

/* ------------------------------------------------------------------ Images */

function dw_sync_live_base() {
	return untrailingslashit( dw_sync_settings()['url'] );
}

function dw_sync_live_host() {
	return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( dw_sync_live_base(), PHP_URL_HOST ) ) );
}

/** A live upload URL (also via the i0.wp.com proxy) -> the ORIGINAL file on live (no -WxH suffix). '' if not a live upload. */
function dw_sync_original_url( $url ) {
	$url = html_entity_decode( trim( $url ) );

	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	}

	$p = wp_parse_url( $url );

	if ( empty( $p['host'] ) ) {
		return '';
	}

	$host = strtolower( $p['host'] );
	$path = isset( $p['path'] ) ? $p['path'] : '';

	if ( preg_match( '/^i\d\.wp\.com$/', $host ) ) { // Jetpack image proxy: /drwealth.com/wp-content/...
		$parts = explode( '/', ltrim( $path, '/' ), 2 );
		$host  = strtolower( $parts[0] );
		$path  = '/' . ( isset( $parts[1] ) ? $parts[1] : '' );
	}

	if ( preg_replace( '/^www\./', '', $host ) !== dw_sync_live_host() || false === strpos( $path, '/wp-content/uploads/' ) ) {
		return '';
	}

	$path = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $path );

	return dw_sync_live_base() . $path;
}

/** Download a live file into the media library once (reused by source URL). Returns attachment ID or 0. */
function dw_sync_sideload( $original, $fallback = '', $title = '', $alt = '' ) {
	global $wpdb;

	$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dw_live_source_url' AND meta_value = %s LIMIT 1", $original ) ); // phpcs:ignore WordPress.DB

	if ( $found && get_post( $found ) ) {
		return $found;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	foreach ( array_unique( array_filter( array( $original, $fallback ) ) ) as $u ) {
		$tmp = download_url( $u, 60 );

		if ( is_wp_error( $tmp ) ) {
			continue;
		}

		$id = media_handle_sideload(
			array(
				'name'     => wp_basename( wp_parse_url( $u, PHP_URL_PATH ) ),
				'tmp_name' => $tmp,
			),
			0,
			$title ? $title : null
		);

		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			continue;
		}

		update_post_meta( $id, '_dw_live_source_url', $original );

		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return (int) $id;
	}

	dw_sync_log( 'warn', 0, 0, 'Image could not be downloaded: ' . $original );

	return 0;
}

/** Re-point every live upload URL inside a string to a local media-library copy. */
function dw_sync_localize_string( $html, &$attached, &$idmap = array() ) {
	if ( false === strpos( $html, 'wp-content' ) ) {
		return $html;
	}

	if ( false !== strpos( $html, '\\/wp-content\\/uploads' ) ) { // JSON-escaped slashes (e.g. Elementor data).
		$html = str_replace( '\\/', '/', $html );
	}

	// <img>: swap src, drop srcset/sizes (WordPress re-adds them), fix wp-image-ID.
	$html = preg_replace_callback(
		'#<img\b[^>]*>#i',
		function ( $m ) use ( &$attached, &$idmap ) {
			$tag = $m[0];

			if ( ! preg_match( '#\ssrc=("|\')(.*?)\1#i', $tag, $sm ) ) {
				return $tag;
			}

			$orig = dw_sync_original_url( $sm[2] );

			if ( ! $orig ) {
				return $tag;
			}

			$id = dw_sync_sideload( $orig, strtok( html_entity_decode( $sm[2] ), '?' ) );

			if ( ! $id ) {
				return $tag;
			}

			$attached[] = $id;
			$tag        = preg_replace( '#\s(?:srcset|sizes)=("|\').*?\1#is', '', $tag );
			$tag        = preg_replace( '#\ssrc=("|\').*?\1#i', ' src="' . esc_url( wp_get_attachment_url( $id ) ) . '"', $tag, 1 );

			if ( preg_match( '#\sclass=("|\')(.*?)\1#i', $tag, $cm ) ) {
				if ( preg_match( '/wp-image-(\d+)/', $cm[2], $im ) ) {
					$idmap[ (int) $im[1] ] = $id;
					$cls                   = preg_replace( '/wp-image-\d+/', 'wp-image-' . $id, $cm[2] );
				} else {
					$cls = $cm[2] . ' wp-image-' . $id;
				}
				$tag = str_replace( $cm[0], ' class="' . $cls . '"', $tag );
			} else {
				$tag = preg_replace( '#<img#i', '<img class="wp-image-' . $id . '"', $tag, 1 );
			}

			return $tag;
		},
		$html
	);

	// Any other live upload URL (links, backgrounds, PDFs...).
	$host = preg_quote( dw_sync_live_host(), '#' );
	$html = preg_replace_callback(
		'#(?:https?:)?//(?:i\d\.wp\.com/)?(?:www\.)?' . $host . '/wp-content/uploads/[^\s"\'<>)\\\\]+#i',
		function ( $m ) use ( &$attached ) {
			$orig = dw_sync_original_url( $m[0] );
			$id   = $orig ? dw_sync_sideload( $orig, strtok( html_entity_decode( $m[0] ), '?' ) ) : 0;

			if ( ! $id ) {
				return $m[0];
			}

			$attached[] = $id;

			return wp_get_attachment_url( $id );
		},
		$html
	);

	// Block comments still carry the live attachment IDs.
	if ( $idmap ) {
		$html = preg_replace_callback(
			'#<!--\s*wp:(?:image|cover|media-text|video|file)\s+\{.*?\}\s*-->#s',
			function ( $m ) use ( $idmap ) {
				return preg_replace_callback(
					'#("(?:id|mediaId)":)(\d+)#',
					function ( $n ) use ( $idmap ) {
						return $n[1] . ( isset( $idmap[ (int) $n[2] ] ) ? $idmap[ (int) $n[2] ] : $n[2] );
					},
					$m[0]
				);
			},
			$html
		);
	}

	return $html;
}

/** Meta value (string / array / numeric attachment ID) -> localized. */
function dw_sync_map_meta( $v, $P, &$attached ) {
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $x ) {
			$v[ $k ] = dw_sync_map_meta( $x, $P, $attached );
		}

		return $v;
	}

	if ( is_string( $v ) || is_numeric( $v ) ) {
		$atts = (array) $P['attachments'];

		if ( ctype_digit( (string) $v ) && isset( $atts[ (string) $v ] ) ) {
			$a  = $atts[ (string) $v ];
			$o  = dw_sync_original_url( $a['url'] );
			$id = $o ? dw_sync_sideload( $o, $a['url'], $a['title'], $a['alt'] ) : 0;

			if ( $id ) {
				$attached[] = $id;

				return (string) $id;
			}

			return $v;
		}

		if ( is_string( $v ) && false !== strpos( $v, 'wp-content' ) ) {
			return dw_sync_localize_string( $v, $attached );
		}
	}

	return $v;
}

/** Strip unwanted embeds (beehiiv subscribe boxes etc.) using the configured regexes. */
function dw_sync_clean( $content ) {
	foreach ( preg_split( '/\R/', dw_sync_settings()['strip'] ) as $re ) {
		$re = trim( $re );

		if ( '' === $re || false === @preg_match( $re, '' ) ) { // phpcs:ignore
			continue;
		}

		$content = preg_replace( $re, '', $content );
	}

	return $content;
}

/* ------------------------------------------------------------ Users, terms */

/**
 * Fallback when the API only knows a Gravatar: read the photo shown on the writer's live author page
 * (the <img> whose alt text is the display name).
 */
function dw_sync_scrape_author_photo( $U ) {
	if ( empty( $U['nicename'] ) ) {
		return '';
	}

	$r = wp_remote_get( dw_sync_live_base() . '/author/' . rawurlencode( $U['nicename'] ) . '/', array( 'timeout' => 20 ) );

	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return '';
	}

	if ( ! preg_match_all( '/<img\b[^>]*>/i', wp_remote_retrieve_body( $r ), $imgs ) ) {
		return '';
	}

	foreach ( $imgs[0] as $img ) {
		if ( ! preg_match( '/\balt=(["\'])(.*?)\1/i', $img, $alt ) || 0 !== strcasecmp( trim( html_entity_decode( $alt[2] ) ), trim( $U['display_name'] ) ) ) {
			continue;
		}

		if ( preg_match( '/\b(?:data-src|src)=(["\'])(.*?)\1/i', $img, $src ) && dw_sync_original_url( html_entity_decode( $src[2] ) ) ) {
			return html_entity_decode( $src[2] );
		}
	}

	return '';
}

/** Copy live's uploaded photo to an existing staging user that has none yet (never touches name, bio or role). */
function dw_sync_user_photo( $uid, $U ) {
	if ( get_user_meta( $uid, 'drwealth_avatar_id', true ) || get_user_meta( $uid, '_dw_photo_tried', true ) ) {
		return;
	}

	update_user_meta( $uid, '_dw_photo_tried', 1 ); // One attempt only: do not retry on every post.

	$src = ! empty( $U['avatar_url'] ) && dw_sync_original_url( $U['avatar_url'] ) ? $U['avatar_url'] : dw_sync_scrape_author_photo( $U );

	if ( $src && dw_sync_original_url( $src ) ) {
		$aid = dw_sync_sideload( dw_sync_original_url( $src ), $src );

		if ( $aid ) {
			update_user_meta( $uid, 'drwealth_avatar_id', $aid );
			dw_sync_log( 'info', 0, 0, 'Copied live photo for author ' . $U['login'] );
		}
	}
}

/** Mirror live's profile meta onto the staging user (only when it changed on live). Never touches role, password or the photo. */
function dw_sync_user_meta( $uid, $U ) {
	if ( empty( $U['meta'] ) || ! is_array( $U['meta'] ) ) {
		return;
	}

	$hash = md5( wp_json_encode( $U['meta'] ) );

	if ( get_user_meta( $uid, '_dw_user_meta_hash', true ) === $hash ) {
		return;
	}

	foreach ( $U['meta'] as $k => $v ) {
		if ( 0 === strpos( $k, '_dw_' ) || 'drwealth_avatar_id' === $k ) {
			continue;
		}

		update_user_meta( $uid, $k, $v );
	}

	update_user_meta( $uid, '_dw_user_meta_hash', $hash );
	dw_sync_log( 'info', 0, 0, 'Profile meta synced for author ' . $U['login'] . ' (' . count( $U['meta'] ) . ' fields).' );
}

function dw_sync_user( $U ) {
	if ( empty( $U['login'] ) ) {
		return 0;
	}

	$found = get_users( array( 'meta_key' => '_dw_live_user_id', 'meta_value' => (int) $U['id'], 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB

	if ( $found ) {
		dw_sync_user_photo( (int) $found[0], $U );
		dw_sync_user_meta( (int) $found[0], $U );

		return (int) $found[0];
	}

	$u = get_user_by( 'login', $U['login'] );

	if ( ! $u && ! empty( $U['email'] ) ) {
		$u = get_user_by( 'email', $U['email'] );
	}

	if ( $u ) {
		update_user_meta( $u->ID, '_dw_live_user_id', (int) $U['id'] );
		dw_sync_user_photo( (int) $u->ID, $U );
		dw_sync_user_meta( (int) $u->ID, $U );

		return (int) $u->ID;
	}

	// Never create administrators from live.
	if ( 'administrator' === $U['role'] ) {
		$role = 'editor';
	} elseif ( $U['role'] && wp_roles()->is_role( $U['role'] ) ) {
		$role = $U['role'];
	} else {
		$role = 'author';
	}

	$uid = wp_insert_user(
		array(
			'user_login'    => $U['login'],
			'user_email'    => $U['email'],
			'user_pass'     => wp_generate_password( 24 ),
			'user_nicename' => $U['nicename'],
			'display_name'  => $U['display_name'],
			'nickname'      => $U['nickname'],
			'first_name'    => $U['first_name'],
			'last_name'     => $U['last_name'],
			'description'   => $U['description'],
			'user_url'      => $U['url'],
			'role'          => $role,
		)
	);

	if ( is_wp_error( $uid ) ) {
		dw_sync_log( 'error', 0, 0, 'Could not create author ' . $U['login'] . ': ' . $uid->get_error_message() );

		return 0;
	}

	update_user_meta( $uid, '_dw_live_user_id', (int) $U['id'] );

	// A real uploaded photo (not Gravatar) is copied over.
	if ( ! empty( $U['avatar_url'] ) && dw_sync_original_url( $U['avatar_url'] ) ) {
		$aid = dw_sync_sideload( dw_sync_original_url( $U['avatar_url'] ), $U['avatar_url'] );

		if ( $aid ) {
			update_user_meta( $uid, 'drwealth_avatar_id', $aid );
		}
	}

	dw_sync_user_meta( (int) $uid, $U );
	dw_sync_log( 'info', 0, 0, 'Created author ' . $U['login'] . ' (' . $role . ')' );

	return (int) $uid;
}

add_filter(
	'pre_get_avatar_data',
	function ( $args, $id_or_email ) {
		$uid = 0;

		if ( is_numeric( $id_or_email ) ) {
			$uid = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$uid = (int) $id_or_email->ID;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u   = get_user_by( 'email', $id_or_email );
			$uid = $u ? (int) $u->ID : 0;
		}

		$aid = $uid ? (int) get_user_meta( $uid, 'drwealth_avatar_id', true ) : 0;
		$url = $aid ? wp_get_attachment_image_url( $aid, array( $args['size'], $args['size'] ) ) : '';

		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	},
	10,
	2
);

function dw_sync_map_slug( $slug ) {
	foreach ( preg_split( '/\R/', dw_sync_settings()['category_map'] ) as $line ) {
		$bits = array_map( 'trim', explode( '=', $line, 2 ) );

		if ( 2 === count( $bits ) && $bits[0] === $slug && $bits[1] ) {
			return $bits[1];
		}
	}

	return $slug;
}

function dw_sync_term( $slug, $name, $tax, $parent = 0 ) {
	$slug = sanitize_title( dw_sync_map_slug( $slug ) );
	$t    = get_term_by( 'slug', $slug, $tax );

	if ( $t ) {
		return (int) $t->term_id;
	}

	$r = wp_insert_term( $name, $tax, array( 'slug' => $slug, 'parent' => (int) $parent ) );

	return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
}

function dw_sync_set_terms( $post_id, $terms ) {
	foreach ( (array) $terms as $tax => $list ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}

		$ids = array();

		foreach ( $list as $t ) {
			$parent = 0;

			foreach ( $t['ancestors'] as $a ) {
				$parent = dw_sync_term( $a['slug'], $a['name'], $tax, $parent );
			}

			$ids[] = dw_sync_term( $t['slug'], $t['name'], $tax, $parent );
		}

		wp_set_object_terms( $post_id, array_values( array_filter( $ids ) ), $tax );
	}
}

/* ------------------------------------------------------------ One post sync */

function dw_sync_is_substack( $post_id ) {
	return (bool) get_post_meta( $post_id, '_drwealth_substack_post_id', true );
}

/** @return array{0:int,1:bool} local post ID (0 = none), already linked to this live ID? */
function dw_sync_find_local( $P ) {
	global $wpdb;

	$by_id = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'meta_key' => '_dw_live_id', 'meta_value' => (int) $P['id'], 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) ); // phpcs:ignore WordPress.DB

	if ( $by_id ) {
		return array( (int) $by_id[0], true );
	}

	// Not linked yet: same slug + type, ignoring Substack imports and posts already linked to another live post.
	// (Drafts can have no slug yet: never match on an empty one.)
	if ( '' !== (string) $P['slug'] ) {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $P['slug'], $P['type'] ) ); // phpcs:ignore WordPress.DB

		foreach ( $ids as $id ) {
			if ( dw_sync_is_substack( $id ) || get_post_meta( $id, '_dw_live_id', true ) ) {
				continue;
			}

			return array( (int) $id, false );
		}
	}

	// A staging copy that kept the live ID (copies made from live keep IDs): same ID, same type, same title.
	$copy = get_post( (int) $P['id'] );

	if ( $copy && $copy->post_type === $P['type'] && ! in_array( $copy->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) && ! dw_sync_is_substack( $copy->ID ) && ! get_post_meta( $copy->ID, '_dw_live_id', true ) && ! empty( $P['title'] ) && 0 === strcasecmp( trim( $copy->post_title ), trim( $P['title'] ) ) ) {
		return array( (int) $copy->ID, false );
	}

	return array( 0, false );
}

/** Hash of the visible text only (no tags, scripts, block comments, punctuation or case), to tell "same article" from "different text". */
function dw_sync_text_hash( $html ) {
	$t = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );

	return md5( trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $t ) ) ) );
}

function dw_sync_meta_skipped( $k ) {
	return 0 === strpos( $k, '_dw_' )
		|| '_thumbnail_id' === $k
		|| false !== strpos( $k, 'force_public' ) // Never bring over "force public" flags: they open Premium posts.
		|| 0 === strpos( ltrim( $k, '_' ), 'aioseo' ) // All in One SEO is gone from staging: do not write its meta.
		|| in_array( $k, array( 'jetpack_seo_html_title', 'advanced_seo_description' ), true ) // SEO lives in these Jetpack fields on staging: never overwrite.
		|| in_array( $k, array( '_edit_lock', '_edit_last', '_elementor_css' ), true );
}

/** Mark a staging post as touched by the sync: what happened, when. Survives the log rolling over. */
function dw_sync_stamp( $post_id, $action ) {
	update_post_meta( $post_id, '_dw_synced_action', $action ); // created|updated|linked.
	update_post_meta( $post_id, '_dw_synced_at', current_time( 'mysql' ) );
}

/**
 * Create or update one staging post from a live payload.
 *
 * @return string created|updated|skipped|linked|conflict|failed
 */
function dw_sync_process_post( $P, $force = false ) {
	global $wpdb;

	$s = dw_sync_settings();

	list( $local, $linked ) = dw_sync_find_local( $P );

	if ( $local && $linked && ! $force && get_post_meta( $local, '_dw_live_hash', true ) === $P['hash'] ) {
		return 'skipped'; // Untouched on live: leave it alone.
	}

	if ( $local && ! $force ) {
		$mod_local = get_post_field( 'post_modified_gmt', $local );

		if ( $linked ) {
			$recorded = get_post_meta( $local, '_dw_local_modified', true );

			if ( $recorded && $recorded !== $mod_local && 'overwrite' !== $s['conflict'] ) {
				if ( get_post_meta( $local, '_dw_conflict_hash', true ) !== $P['hash'] ) {
					update_post_meta( $local, '_dw_conflict_hash', $P['hash'] );
					dw_sync_log( 'warn', $P['id'], $local, 'Changed on live AND on staging; staging kept. "' . $P['title'] . '"' );
				}

				return 'conflict';
			}
		} elseif ( $P['modified_gmt'] <= $mod_local && dw_sync_text_hash( dw_sync_clean( $P['content'] ) ) === dw_sync_text_hash( get_post_field( 'post_content', $local ) ) ) {
			// Existing staging copy, not newer on live AND the same text: just link it.
			// A differing text falls through to the update below, so a stale copy is never linked as-is.
			update_post_meta( $local, '_dw_live_id', (int) $P['id'] );
			update_post_meta( $local, '_dw_live_hash', $P['hash'] );
			update_post_meta( $local, '_dw_local_modified', $mod_local );
			update_post_meta( $local, '_dw_live_url', $P['url'] );
			dw_sync_stamp( $local, 'linked' );
			update_post_meta( $local, '_dw_id_kept', (int) $local === (int) $P['id'] ? 'yes' : 'no' );

			return 'linked';
		}
	}

	$attached = array();
	$idmap    = array();
	$content  = dw_sync_localize_string( dw_sync_clean( $P['content'] ), $attached, $idmap );
	$author   = dw_sync_user( $P['author'] );

	$parent = 0;
	if ( $P['parent'] ) {
		$pp     = get_posts( array( 'post_type' => $P['type'], 'post_status' => 'any', 'meta_key' => '_dw_live_id', 'meta_value' => (int) $P['parent'], 'numberposts' => 1, 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB
		$parent = $pp ? (int) $pp[0] : 0;
	}

	$postarr = array(
		'post_type'      => $P['type'],
		'post_status'    => $P['status'],
		'post_title'     => $P['title'],
		'post_content'   => $content,
		'post_excerpt'   => $P['excerpt'],
		'post_name'      => $P['slug'],
		'post_date'      => $P['date'],
		'post_date_gmt'  => $P['date_gmt'],
		'post_author'    => $author ? $author : get_current_user_id(),
		'post_parent'    => $parent,
		'menu_order'     => $P['menu_order'],
		'comment_status' => $P['comment_status'],
		'ping_status'    => $P['ping_status'],
	);

	if ( $local ) {
		$postarr['ID'] = $local;
	} elseif ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $P['id'] ) ) ) { // phpcs:ignore WordPress.DB
		// Keep the live post ID when it is free here: Jetpack Stats views are stored per post ID,
		// so the same ID lets the old view history line up after launch.
		$postarr['import_id'] = (int) $P['id'];
	}

	$id = wp_insert_post( wp_slash( $postarr ), true );

	if ( is_wp_error( $id ) || ! $id ) {
		dw_sync_log( 'error', $P['id'], $local, 'Save failed: ' . ( is_wp_error( $id ) ? $id->get_error_message() : '?' ) );

		return 'failed';
	}

	// Meta: everything from live (SEO Framework etc.), localized.
	foreach ( $P['meta'] as $k => $vals ) {
		if ( dw_sync_meta_skipped( $k ) ) {
			continue;
		}

		delete_post_meta( $id, $k );

		foreach ( (array) $vals as $v ) {
			add_post_meta( $id, $k, wp_slash( dw_sync_map_meta( $v, $P, $attached ) ) );
		}
	}

	// Featured image.
	$atts = (array) $P['attachments'];

	if ( $P['thumbnail_id'] && isset( $atts[ (string) $P['thumbnail_id'] ] ) ) {
		$a = $atts[ (string) $P['thumbnail_id'] ];
		$o = dw_sync_original_url( $a['url'] );
		$t = $o ? dw_sync_sideload( $o, $a['url'], $a['title'], $a['alt'] ) : 0;

		if ( $t ) {
			set_post_thumbnail( $id, $t );
			$attached[] = $t;
		}
	}

	dw_sync_set_terms( $id, $P['terms'] );

	// Attach the downloaded images to the post.
	foreach ( array_unique( $attached ) as $aid ) {
		if ( ! wp_get_post_parent_id( $aid ) ) {
			$wpdb->update( $wpdb->posts, array( 'post_parent' => $id ), array( 'ID' => $aid ) ); // phpcs:ignore WordPress.DB
		}
	}

	dw_sync_comments( $id, $P );

	delete_post_meta( $id, '_elementor_css' );

	// Mirror live's modified date, so "touched on staging?" can be detected later.
	$wpdb->update( $wpdb->posts, array( 'post_modified' => $P['modified'], 'post_modified_gmt' => $P['modified_gmt'] ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
	clean_post_cache( $id );

	update_post_meta( $id, '_dw_live_id', (int) $P['id'] );
	update_post_meta( $id, '_dw_live_hash', $P['hash'] );
	update_post_meta( $id, '_dw_live_url', $P['url'] );
	update_post_meta( $id, '_dw_local_modified', $P['modified_gmt'] );
	delete_post_meta( $id, '_dw_conflict_hash' );

	$result = $local ? 'updated' : 'created';
	dw_sync_stamp( $id, $result );
	update_post_meta( $id, '_dw_id_kept', (int) $id === (int) $P['id'] ? 'yes' : 'no' );
	dw_sync_log( 'info', $P['id'], $id, ucfirst( $result ) . ': "' . $P['title'] . '"' . ( (int) $id === (int) $P['id'] ? ' (same ID as live)' : ' (new ID ' . $id . ', live ID was taken or differs)' ) . ( $author ? '' : ' (author not mapped!)' ) );

	return $result;
}

function dw_sync_comments( $post_id, $P ) {
	global $wpdb;

	$map = array();

	foreach ( (array) $P['comments'] as $c ) {
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_dw_live_comment_id' AND meta_value = %d AND comment_id IN (SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d) LIMIT 1", $c['id'], $post_id ) ); // phpcs:ignore WordPress.DB

		if ( $exists ) {
			$map[ $c['id'] ] = $exists;
			continue;
		}

		$user = $c['user_login'] ? get_user_by( 'login', $c['user_login'] ) : false;
		$cid  = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post_id,
					'comment_author'       => $c['author'],
					'comment_author_email' => $c['author_email'],
					'comment_author_url'   => $c['author_url'],
					'comment_content'      => $c['content'],
					'comment_date_gmt'     => $c['date_gmt'],
					'comment_date'         => get_date_from_gmt( $c['date_gmt'] ),
					'comment_approved'     => 1,
					'comment_parent'       => isset( $map[ $c['parent'] ] ) ? $map[ $c['parent'] ] : 0,
					'user_id'              => $user ? $user->ID : 0,
				)
			)
		);

		if ( $cid ) {
			add_comment_meta( $cid, '_dw_live_comment_id', (int) $c['id'] );
			$map[ $c['id'] ] = $cid;
		}
	}

	if ( $map ) {
		wp_update_comment_count( $post_id );
	}
}

/* ---------------------------------------------------------------- The run */

function dw_sync_since_gmt() {
	$d = dw_sync_settings()['since'];

	return $d ? gmdate( 'Y-m-d H:i:s', strtotime( $d . ' 00:00:00' ) ) : '';
}

/**
 * Process posts from the saved cursor until none are left or the time budget is spent
 * (then a follow-up run is scheduled). Returns '' or an error message.
 */
function dw_sync_run( $budget = 40 ) {
	if ( get_transient( 'dw_sync_lock' ) ) {
		return 'Another sync is already running.';
	}

	set_transient( 'dw_sync_lock', 1, 300 );
	@set_time_limit( 300 ); // phpcs:ignore

	$s     = dw_sync_settings();
	$state = dw_sync_state();
	$start = time();
	$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'linked' => 0, 'conflict' => 0, 'failed' => 0 );
	$more  = false;
	$err   = '';

	// 1) Posts you asked to force (Live Sync > "Force these live IDs").
	$force = array_filter( array_map( 'absint', explode( ',', $s['force_ids'] ) ) );

	if ( $force ) {
		$r = dw_sync_fetch( 'posts', array( 'types' => dw_sync_types_arg(), 'ids' => implode( ',', $force ) ) );

		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			foreach ( $r['posts'] as $P ) {
				$stats[ dw_sync_process_post( $P, true ) ]++;
			}

			$s['force_ids'] = '';
			update_option( DW_SYNC_OPTION, $s );
		}
	}

	// 2) Everything changed since the cursor.
	while ( ! $err ) {
		$r = dw_sync_fetch(
			'posts',
			array(
				'types'    => dw_sync_types_arg(),
				'after'    => $state['cursor_mod'],
				'after_id' => $state['cursor_id'],
				'limit'    => 5,
				'since'    => dw_sync_since_gmt(),
			)
		);

		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
			break;
		}

		if ( empty( $r['posts'] ) ) {
			break;
		}

		foreach ( $r['posts'] as $P ) {
			$stats[ dw_sync_process_post( $P ) ]++;
			$state['cursor_mod'] = $P['modified_gmt'];
			$state['cursor_id']  = $P['id'];
			update_option( DW_SYNC_STATE, array_merge( $state, array( 'last_run' => current_time( 'mysql' ) ) ), false );

			if ( time() - $start > $budget ) {
				$more = true;
				break 2;
			}
		}
	}

	$state             = dw_sync_state();
	$state['last_run'] = current_time( 'mysql' );
	$state['stats']    = $stats;

	if ( ! $more && ! $err ) {
		$state['last_done'] = current_time( 'mysql' );
	}

	update_option( DW_SYNC_STATE, $state, false );
	delete_transient( 'dw_sync_lock' );

	if ( ! $more && ! $err && ! empty( $s['mirror_removed'] ) ) {
		dw_sync_mirror_removed( true );
	}

	if ( ! $more && ! $err && ! empty( $s['tables'] ) ) {
		dw_sync_tables( true );
	}

	if ( $err ) {
		dw_sync_log( 'error', 0, 0, 'Sync stopped: ' . $err );
	} elseif ( $more ) {
		wp_schedule_single_event( time() + 20, DW_SYNC_CONT );
	}

	dw_sync_log( 'info', 0, 0, sprintf( 'Run: %d created, %d updated, %d unchanged, %d linked, %d conflicts, %d failed%s', $stats['created'], $stats['updated'], $stats['skipped'], $stats['linked'], $stats['conflict'], $stats['failed'], $more ? ' (more to do, continuing)' : '' ) );

	return $err;
}

add_action(
	DW_SYNC_CRON,
	function () {
		if ( dw_sync_settings()['daily'] ) {
			dw_sync_run( 45 );
		}
	}
);

add_action(
	DW_SYNC_CONT,
	function () {
		dw_sync_run( 45 );
	}
);


/* ------------------------------------------------- Mirror posts removed on live */

/**
 * Linked staging posts whose live post is now trashed or gone get moved to the staging Trash
 * (recoverable, never permanently deleted). Posts also edited on staging are kept unless the
 * conflict policy is "overwrite". Refuses to act if the live index looks incomplete.
 *
 * @param bool $apply false = report only.
 * @return array{removed:array,kept:array,error:string}
 */
function dw_sync_mirror_removed( $apply = false ) {
	global $wpdb;

	$res = array( 'removed' => array(), 'kept' => array(), 'error' => '' );
	$s   = dw_sync_settings();
	$r   = dw_sync_fetch( 'index', array( 'types' => dw_sync_types_arg() ) );

	if ( is_wp_error( $r ) || empty( $r['index'] ) ) {
		$res['error'] = is_wp_error( $r ) ? $r->get_error_message() : 'Live returned an empty index.';

		return $res;
	}

	$live = array();
	foreach ( $r['index'] as $row ) {
		$live[ (int) $row['id'] ] = $row['status'];
	}

	$rows = $wpdb->get_results( "SELECT m.post_id, m.meta_value live_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_dw_live_id' AND p.post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB

	// Safety: if far fewer of our linked posts appear on live than expected, the index is not trustworthy.
	$present = 0;
	foreach ( $rows as $x ) {
		if ( isset( $live[ (int) $x->live_id ] ) ) {
			$present++;
		}
	}

	if ( $rows && $present < 0.5 * count( $rows ) ) {
		$res['error'] = 'Live index matches under half of the linked posts; refusing to remove anything.';

		return $res;
	}

	foreach ( $rows as $x ) {
		$lid    = (int) $x->live_id;
		$status = isset( $live[ $lid ] ) ? $live[ $lid ] : 'missing';

		if ( 'trash' !== $status && 'missing' !== $status ) {
			continue;
		}

		$loc      = (int) $x->post_id;
		$title    = get_the_title( $loc );
		$recorded = get_post_meta( $loc, '_dw_local_modified', true );
		$edited   = $recorded && $recorded !== get_post_field( 'post_modified_gmt', $loc );

		if ( $edited && 'overwrite' !== $s['conflict'] ) {
			$res['kept'][] = '#' . $loc . ' "' . wp_html_excerpt( $title, 40 ) . '" (' . $status . ' on live, but edited on staging)';
			continue;
		}

		$res['removed'][] = '#' . $loc . ' "' . wp_html_excerpt( $title, 40 ) . '" (' . $status . ' on live)';

		if ( $apply ) {
			update_post_meta( $loc, '_dw_removed_on_live', current_time( 'mysql' ) );
			wp_trash_post( $loc );
			dw_sync_log( 'info', $lid, $loc, 'Moved to Trash: ' . $status . ' on live. "' . $title . '"' );
		}
	}

	if ( $apply && $res['error'] ) {
		dw_sync_log( 'warn', 0, 0, 'Removed-on-live check skipped: ' . $res['error'] );
	}

	return $res;
}

/* ------------------------------------------------- Re-create posts with the live ID */

/**
 * Linked posts whose staging ID differs from the live ID, sorted into:
 *  fixable : live ID is free here and the post was not edited on staging
 *  blocked : live ID is used by something else on staging
 *  edited  : changed on staging since the sync (would lose those edits)
 * Each entry: live_id => staging_id (blocked: live_id => array( staging_id, description )).
 */
function dw_sync_reid_candidates() {
	global $wpdb;

	$out = array( 'fixable' => array(), 'blocked' => array(), 'edited' => array() );

	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dw_live_id'" ) as $r ) { // phpcs:ignore WordPress.DB
		$live = (int) $r->meta_value;
		$loc  = (int) $r->post_id;

		if ( $live === $loc ) {
			continue;
		}

		$taken = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE ID = %d", $live ) ); // phpcs:ignore WordPress.DB

		if ( $taken ) {
			$out['blocked'][ $live ] = array( $loc, '#' . $live . ' is a staging ' . $taken->post_type . ' "' . wp_html_excerpt( $taken->post_title, 40 ) . '" (needed for staging #' . $loc . ')' );
			continue;
		}

		$rec = get_post_meta( $loc, '_dw_local_modified', true );
		$mod = get_post_field( 'post_modified_gmt', $loc );

		if ( $rec && $rec !== $mod ) {
			$out['edited'][ $live ] = $loc;
			continue;
		}

		$out['fixable'][ $live ] = $loc;
	}

	return $out;
}

/**
 * Re-create fixable posts under the live ID, one by one, safely: the old copy is parked
 * (draft, temp slug, unlinked) first, the new one is created, and only when it really got
 * the live ID is the old copy deleted. Otherwise the old copy is restored untouched.
 *
 * @return array{done:int,failed:int,left:int}
 */
function dw_sync_reid_run( $budget = 25, $include_edited = false ) {
	global $wpdb;

	@set_time_limit( 300 ); // phpcs:ignore

	$start = time();
	$c     = dw_sync_reid_candidates();
	$list  = $c['fixable'] + ( $include_edited ? $c['edited'] : array() );
	$done  = 0;
	$fail  = 0;

	foreach ( $list as $live => $loc ) {
		if ( time() - $start > $budget ) {
			break;
		}

		$r = dw_sync_fetch( 'posts', array( 'types' => dw_sync_types_arg(), 'ids' => $live ) );

		if ( is_wp_error( $r ) || empty( $r['posts'] ) ) {
			dw_sync_log( 'warn', $live, $loc, 'ID fix skipped: could not fetch the live post.' );
			$fail++;
			continue;
		}

		$P   = $r['posts'][0];
		$old = get_post( $loc );

		if ( ! $old ) {
			continue;
		}

		$orig = array(
			'name'   => $old->post_name,
			'status' => $old->post_status,
			'hash'   => get_post_meta( $loc, '_dw_live_hash', true ),
		);

		// Park the old copy so the slug is free and it no longer counts as linked.
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'dw-old-' . $loc, 'post_status' => 'draft' ), array( 'ID' => $loc ) ); // phpcs:ignore WordPress.DB
		delete_post_meta( $loc, '_dw_live_id' );
		clean_post_cache( $loc );

		dw_sync_process_post( $P, true );
		list( $new, $linked ) = dw_sync_find_local( $P );

		if ( $new && $linked && (int) $new === (int) $live && (int) $new !== (int) $loc ) {
			wp_delete_post( $loc, true );
			dw_sync_log( 'info', $live, $new, 'ID fixed: staging #' . $loc . ' replaced by #' . $new . ' (same ID as live). "' . $P['title'] . '"' );
			$done++;
			continue;
		}

		// It did not get the live ID: undo.
		if ( $new && (int) $new !== (int) $loc ) {
			wp_delete_post( $new, true );
		}

		$wpdb->update( $wpdb->posts, array( 'post_name' => $orig['name'], 'post_status' => $orig['status'] ), array( 'ID' => $loc ) ); // phpcs:ignore WordPress.DB
		update_post_meta( $loc, '_dw_live_id', (int) $live );
		clean_post_cache( $loc );
		dw_sync_log( 'warn', $live, $loc, 'ID fix undone (old copy restored): the live ID could not be used.' );
		$fail++;
	}

	return array( 'done' => $done, 'failed' => $fail, 'left' => max( 0, count( $list ) - $done - $fail ) );
}

/* ------------------------------------------------- Remove imported subscribers (staging clean-up) */

/**
 * Users to remove: marked by our importer, or whose ONLY role is subscriber (the earlier developer's
 * bulk import). Never anyone who can edit posts / manage the site, has authored posts, or is you.
 * Returns [ids to delete, kept count].
 */
function dw_sync_subscriber_candidates() {
	global $wpdb;

	$marked = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_drwealth_substack_publications'" ); // phpcs:ignore WordPress.DB
	$subs   = get_users( array( 'role' => 'subscriber', 'fields' => 'ID', 'number' => -1 ) );
	$all    = array_unique( array_map( 'intval', array_merge( $marked, $subs ) ) );

	if ( ! $all ) {
		return array( array(), 0 );
	}

	$authors = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('auto-draft')" ) ); // phpcs:ignore WordPress.DB
	$staff   = array_map( 'intval', get_users( array( 'role__in' => array( 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ), 'fields' => 'ID', 'number' => -1 ) ) );
	$keep    = array_flip( array_merge( $authors, $staff, array( get_current_user_id() ) ) );
	$del     = array();
	$kept    = 0;

	foreach ( $all as $id ) {
		if ( isset( $keep[ $id ] ) ) {
			$kept++;
			continue;
		}

		$del[] = $id;
	}

	return array( $del, $kept );
}

/** Counts of what deleting these users would remove. */
function dw_sync_subscriber_counts( $users ) {
	global $wpdb;

	$n = array( 'users' => count( $users ), 'memberships' => 0, 'orders' => 0 );

	if ( ! $users ) {
		return $n;
	}

	$in = implode( ',', array_map( 'intval', $users ) );

	$n['memberships'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership' AND post_author IN ($in)" ); // phpcs:ignore WordPress.DB

	if ( function_exists( 'wc_get_orders' ) ) {
		$n['orders'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value IN ($in) AND meta_value <> '0'" ); // phpcs:ignore WordPress.DB
	}

	return $n;
}

const DW_SYNC_SUBS_EVENT = 'drwealth_live_sync_subs_delete';

/** Delete one batch: each user's memberships, orders/subscriptions, then the user. Re-schedules itself while users are left. */
function dw_sync_subscriber_delete( $budget = 25 ) {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/user.php';
	@set_time_limit( 300 ); // phpcs:ignore

	list( $users ) = dw_sync_subscriber_candidates();
	$start = time();
	$done  = 0;

	foreach ( $users as $uid ) {
		if ( time() - $start > $budget ) {
			break;
		}

		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership' AND post_author = %d", $uid ) ) as $m ) { // phpcs:ignore WordPress.DB
			wp_delete_post( (int) $m, true );
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( wc_get_orders( array( 'customer_id' => $uid, 'limit' => -1, 'return' => 'objects', 'type' => array( 'shop_order', 'shop_subscription' ), 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
				$o->delete( true );
			}
		}

		wp_delete_user( $uid );
		$done++;
	}

	$left = max( 0, count( $users ) - $done );

	if ( $left && ! wp_next_scheduled( DW_SYNC_SUBS_EVENT ) ) {
		wp_schedule_single_event( time() + 5, DW_SYNC_SUBS_EVENT );
	}

	return array( 'done' => $done, 'left' => $left );
}

add_action(
	DW_SYNC_SUBS_EVENT,
	function () {
		dw_sync_subscriber_delete( 40 );
	}
);

/**
 * Re-sync photo, username and profile meta for every author of a live-linked post, in short batches
 * (click again until nothing is left). Authors whose posts were linked before the sync ever resolved them
 * get linked to their live account now (by login, then email).
 *
 * @return array{done:int,photos:int,none:int,left:int}
 */
function dw_sync_author_photos( $budget = 20 ) {
	global $wpdb;

	@set_time_limit( 120 ); // phpcs:ignore

	$start = time();
	$seen  = get_transient( 'dw_sync_authors_done' );
	$seen  = is_array( $seen ) ? $seen : array();
	$done  = 0;
	$none  = 0;
	$photo = 0;

	$rows = $wpdb->get_results( "SELECT p.post_author AS uid, MAX(p.ID) AS pid FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dw_live_id' WHERE p.post_type = 'post' GROUP BY p.post_author" ); // phpcs:ignore WordPress.DB
	$todo = array();

	foreach ( $rows as $row ) {
		if ( ! isset( $seen[ (int) $row->uid ] ) ) {
			$todo[] = $row;
		}
	}

	foreach ( $todo as $row ) {
		if ( time() - $start > $budget ) {
			break;
		}

		$seen[ (int) $row->uid ] = 1; // Mark first so a slow or failing author is not retried forever.

		$r = dw_sync_fetch( 'posts', array( 'types' => dw_sync_types_arg(), 'ids' => (int) get_post_meta( (int) $row->pid, '_dw_live_id', true ) ) );

		if ( is_wp_error( $r ) || empty( $r['posts'][0]['author'] ) ) {
			$none++;
			continue;
		}

		$U   = $r['posts'][0]['author'];
		$uid = dw_sync_user( $U ); // Links or creates, then applies photo and meta.

		if ( ! $uid ) {
			$none++;
			continue;
		}

		$seen[ $uid ] = 1;

		// Username as on live (WordPress has no screen for this) when that name is free here.
		$cur = get_userdata( $uid );

		if ( $cur && ! empty( $U['login'] ) && $cur->user_login !== $U['login'] && ! username_exists( $U['login'] ) ) {
			$wpdb->update( $wpdb->users, array( 'user_login' => $U['login'] ), array( 'ID' => $uid ) ); // phpcs:ignore WordPress.DB
			clean_user_cache( $uid );
			dw_sync_log( 'info', 0, 0, 'Username changed to match live: ' . $cur->user_login . ' -> ' . $U['login'] );
		}

		delete_user_meta( $uid, '_dw_photo_tried' );
		dw_sync_user_photo( $uid, $U );
		dw_sync_user_meta( $uid, $U );

		if ( get_user_meta( $uid, 'drwealth_avatar_id', true ) ) {
			$photo++;
		}

		$done++;
	}

	$left = max( 0, count( $todo ) - $done - $none );

	if ( $left ) {
		set_transient( 'dw_sync_authors_done', $seen, HOUR_IN_SECONDS );
	} else {
		delete_transient( 'dw_sync_authors_done' );
	}

	return array( 'done' => $done, 'photos' => $photo, 'none' => $none, 'left' => $left );
}

/* ------------------------------------------------- Preview of a full re-check (changes nothing) */

/**
 * Walk live's posts exactly like a full re-check would and say what would happen to each one, without
 * writing anything. Works in batches (state kept in a transient); call again until ['done'] is true.
 *
 * @return array state: n (counts), create/update/conflict (labels), scanned, done
 */
function dw_sync_preview( $budget = 25, $restart = false ) {
	@set_time_limit( 120 ); // phpcs:ignore

	$s  = dw_sync_settings();
	$st = $restart ? false : get_transient( 'dw_sync_preview' );

	if ( ! is_array( $st ) ) {
		$st = array(
			'mod'      => '',
			'id'       => 0,
			'scanned'  => 0,
			'n'        => array( 'create' => 0, 'skip' => 0, 'update' => 0, 'conflict' => 0, 'link' => 0 ),
			'create'   => array(),
			'update'   => array(),
			'conflict' => array(),
			'done'     => false,
			'error'    => '',
		);
	}

	$start = time();

	while ( ! $st['done'] && time() - $start <= $budget ) {
		$r = dw_sync_fetch(
			'posts',
			array(
				'types'    => dw_sync_types_arg(),
				'after'    => $st['mod'],
				'after_id' => $st['id'],
				'limit'    => 10,
				'since'    => dw_sync_since_gmt(),
			)
		);

		if ( is_wp_error( $r ) ) {
			$st['error'] = $r->get_error_message();
			break;
		}

		if ( empty( $r['posts'] ) ) {
			$st['done'] = true;
			break;
		}

		foreach ( $r['posts'] as $P ) {
			list( $local, $linked ) = dw_sync_find_local( $P );
			$label                  = '#' . $P['id'] . ' "' . wp_html_excerpt( $P['title'], 45 ) . '" (' . $P['status'] . ')';

			if ( ! $local ) {
				$st['n']['create']++;
				$st['create'][] = $label;
			} elseif ( $linked ) {
				if ( get_post_meta( $local, '_dw_live_hash', true ) === $P['hash'] ) {
					$st['n']['skip']++;
				} else {
					$rec    = get_post_meta( $local, '_dw_local_modified', true );
					$edited = $rec && $rec !== get_post_field( 'post_modified_gmt', $local );

					if ( $edited && 'overwrite' !== $s['conflict'] ) {
						$st['n']['conflict']++;
						$st['conflict'][] = $label . ' - changed on both; staging kept';
					} else {
						$st['n']['update']++;
						$st['update'][] = $label . ( $edited ? ' - staging edits WILL be overwritten' : '' );
					}
				}
			} elseif ( $P['modified_gmt'] <= get_post_field( 'post_modified_gmt', $local ) && dw_sync_text_hash( dw_sync_clean( $P['content'] ) ) === dw_sync_text_hash( get_post_field( 'post_content', $local ) ) ) {
				$st['n']['link']++;
			} else {
				$st['n']['update']++;
				$st['update'][] = $label . ' - unlinked staging copy with different text';
			}

			$st['scanned']++;
			$st['mod'] = $P['modified_gmt'];
			$st['id']  = $P['id'];
		}
	}

	if ( $st['done'] || $st['error'] ) {
		delete_transient( 'dw_sync_preview' );
	} else {
		set_transient( 'dw_sync_preview', $st, HOUR_IN_SECONDS );
	}

	return $st;
}

/* ------------------------------------------------- Bring over live drafts only */

/**
 * Live drafts / pending posts that are not on staging yet (found from the light index, so this is fast),
 * created or linked like any other post. Nothing else is re-checked.
 *
 * @param bool $apply false = only list what would come over.
 * @return array{todo:array,done:array,error:string}
 */
function dw_sync_drafts( $apply = false, $budget = 25 ) {
	global $wpdb;

	@set_time_limit( 120 ); // phpcs:ignore

	$res = array( 'link' => 0, 'create' => 0, 'older' => 0, 'todo' => array(), 'done' => array( 'created' => 0, 'linked' => 0, 'updated' => 0, 'skipped' => 0, 'conflict' => 0, 'failed' => 0 ), 'error' => '' );
	$r   = dw_sync_fetch( 'index', array( 'types' => dw_sync_types_arg() ) );

	if ( is_wp_error( $r ) ) {
		$res['error'] = $r->get_error_message();

		return $res;
	}

	$d      = dw_sync_settings()['drafts_since'];
	$since  = $d ? gmdate( 'Y-m-d H:i:s', strtotime( $d . ' 00:00:00' ) ) : '';
	$older  = 0;
	$linked = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dw_live_id'" ) ) ); // phpcs:ignore WordPress.DB

	foreach ( $r['index'] as $row ) {
		if ( ! in_array( $row['status'], array( 'draft', 'pending' ), true ) || isset( $linked[ (int) $row['id'] ] ) ) {
			continue;
		}

		if ( $since && $row['modified'] < $since ) {
			$older++; // Last edited before the drafts cut-off: ignored.

			continue;
		}

		$res['todo'][ (int) $row['id'] ] = $row['slug'] ? $row['slug'] : '(no slug yet)';

		// Would it link to an existing staging draft (same type and slug, or same ID with the same slug), or create a new one?
		$hit = 0;

		if ( '' !== (string) $row['slug'] ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $row['slug'], $row['type'] ) ) as $cid ) { // phpcs:ignore WordPress.DB
				if ( ! dw_sync_is_substack( $cid ) && ! get_post_meta( $cid, '_dw_live_id', true ) ) {
					$hit = (int) $cid;
					break;
				}
			}
		}

		if ( ! $hit ) {
			$c = get_post( (int) $row['id'] );

			if ( $c && $c->post_type === $row['type'] && $c->post_name === (string) $row['slug'] && ! in_array( $c->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) && ! get_post_meta( $c->ID, '_dw_live_id', true ) ) {
				$hit = (int) $c->ID;
			}
		}

		$hit ? $res['link']++ : $res['create']++;
	}

	$res['older'] = $older;

	if ( ! $apply || ! $res['todo'] ) {
		return $res;
	}

	$start = time();

	foreach ( array_chunk( array_keys( $res['todo'] ), 5 ) as $ids ) {
		if ( time() - $start > $budget ) {
			break;
		}

		$p = dw_sync_fetch( 'posts', array( 'types' => dw_sync_types_arg(), 'ids' => implode( ',', $ids ) ) );

		if ( is_wp_error( $p ) ) {
			$res['error'] = $p->get_error_message();
			break;
		}

		foreach ( $p['posts'] as $P ) {
			$o = dw_sync_process_post( $P );

			$res['done'][ $o ]++;
			unset( $res['todo'][ (int) $P['id'] ] );
		}
	}

	return $res;
}

/**
 * Free live IDs that are blocked only by old revisions or by posts already in the Trash on staging.
 * Everything else holding a live ID (orders, memberships, media, sitemaps...) is left alone and reported.
 *
 * @return array{deleted:array,kept:array}
 */
function dw_sync_reid_clear() {
	global $wpdb;

	$res     = array( 'deleted' => array(), 'kept' => array() );
	$rebuild = false;
	$c       = dw_sync_reid_candidates();

	foreach ( $c['blocked'] as $live => $info ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE ID = %d", (int) $live ) ); // phpcs:ignore WordPress.DB

		if ( ! $row ) {
			continue;
		}

		$sitemap = in_array( $row->post_type, array( 'jp_sitemap', 'jp_img_sitemap', 'jp_sitemap_master', 'jp_sitemap_index' ), true ); // Jetpack's cached sitemap files: it rebuilds them.

		if ( 'revision' === $row->post_type || 'trash' === $row->post_status || $sitemap ) {
			wp_delete_post( (int) $row->ID, true );
			$res['deleted'][] = '#' . $row->ID . ' (' . ( 'revision' === $row->post_type ? 'revision' : ( $sitemap ? 'Jetpack sitemap file' : 'trashed ' . $row->post_type ) ) . ' "' . wp_html_excerpt( $row->post_title, 30 ) . '")';
			dw_sync_log( 'info', (int) $live, (int) $row->ID, 'Freed live ID: deleted ' . $row->post_type . ' (' . $row->post_status . ') that was holding it.' );

			if ( $sitemap ) {
				$rebuild = true;
			}
		} else {
			$res['kept'][] = '#' . $row->ID . ' (' . $row->post_type . ' "' . wp_html_excerpt( $row->post_title, 30 ) . '")';
		}
	}

	if ( $rebuild ) {
		dw_sync_sitemap_rebuild();
	}

	return $res;
}


/** Ask Jetpack to rebuild its sitemaps in the background (it keeps them as cached files). */
function dw_sync_sitemap_rebuild() {
	if ( ! class_exists( 'Jetpack_Sitemap_Manager' ) ) {
		return false;
	}

	if ( ! wp_next_scheduled( 'jp_sitemap_cron_hook' ) ) {
		wp_schedule_single_event( time() + 15, 'jp_sitemap_cron_hook' );
	}

	spawn_cron();
	dw_sync_log( 'info', 0, 0, 'Jetpack sitemap rebuild scheduled.' );

	return true;
}

/* ------------------------------------------------- Compare live drafts with staging drafts */

/**
 * Pair every live draft with its staging counterpart (linked live ID, else same ID with same slug, else same slug)
 * and report what does not pair up. Read-only.
 *
 * @return array{live:int,staging:int,missing:array,stg_only:array,status:array,error:string}
 */
function dw_sync_drafts_compare() {
	global $wpdb;

	$res = array( 'live' => 0, 'staging' => 0, 'missing' => array(), 'stg_only' => array(), 'status' => array(), 'error' => '' );
	$r   = dw_sync_fetch( 'index', array( 'types' => 'post' ) );

	if ( is_wp_error( $r ) ) {
		$res['error'] = $r->get_error_message();

		return $res;
	}

	$idx    = array();
	$bySlug = array();

	foreach ( $r['index'] as $row ) {
		$idx[ (int) $row['id'] ] = $row;

		if ( '' !== (string) $row['slug'] && 'post' === $row['type'] ) {
			$bySlug[ $row['slug'] ][] = (int) $row['id'];
		}
	}

	$link = array(); // Staging post ID => live ID.
	$rev  = array(); // Live ID => staging post IDs (any status).

	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dw_live_id'" ) as $m ) { // phpcs:ignore WordPress.DB
		$link[ (int) $m->post_id ]         = (int) $m->meta_value;
		$rev[ (int) $m->meta_value ][] = (int) $m->post_id;
	}

	$stg = $wpdb->get_results( "SELECT ID, post_name, post_title, post_status FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'draft'" ); // phpcs:ignore WordPress.DB
	$res['staging'] = count( $stg );
	$paired = array();

	foreach ( $stg as $p ) {
		$sid = (int) $p->ID;
		$lid = 0;

		if ( isset( $link[ $sid ] ) ) {
			$lid = $link[ $sid ];
		} elseif ( isset( $idx[ $sid ] ) && 'post' === $idx[ $sid ]['type'] && (string) $idx[ $sid ]['slug'] === (string) $p->post_name ) {
			$lid = $sid;
		} elseif ( '' !== (string) $p->post_name && ! empty( $bySlug[ $p->post_name ] ) ) {
			$lid = (int) $bySlug[ $p->post_name ][0];
		}

		if ( ! $lid || ! isset( $idx[ $lid ] ) ) {
			$res['stg_only'][] = '#' . $sid . ' "' . wp_html_excerpt( $p->post_title, 40 ) . '"' . ( $p->post_name ? '' : ' (no slug)' ) . ( $lid ? ' (live #' . $lid . ' no longer exists)' : '' );
			continue;
		}

		$paired[ $lid ] = $sid;

		if ( 'draft' !== $idx[ $lid ]['status'] ) {
			$res['status'][] = 'staging draft #' . $sid . ' "' . wp_html_excerpt( $p->post_title, 35 ) . '" but live #' . $lid . ' is ' . $idx[ $lid ]['status'];
		}
	}

	foreach ( $idx as $lid => $row ) {
		if ( 'draft' !== $row['status'] || 'post' !== $row['type'] ) {
			continue;
		}

		$res['live']++;

		if ( isset( $paired[ $lid ] ) ) {
			continue;
		}

		// Is there a staging counterpart in another status?
		$other = '';
		foreach ( isset( $rev[ $lid ] ) ? $rev[ $lid ] : array() as $sid ) {
			$other = 'staging #' . $sid . ' is ' . get_post_status( $sid );
		}

		$res['missing'][] = 'live #' . $lid . ' ' . ( $row['slug'] ? $row['slug'] : '(no slug)' ) . ( $other ? ' - ' . $other : ' - nothing on staging' );
	}

	return $res;
}


/* ------------------------------------------------- TablePress tables (listed IDs only) */

/** Hash of a TablePress table the same way the live exporter computes it. */
function dw_sync_table_hash( $t ) {
	$core = array(
		'name'        => $t['name'],
		'description' => $t['description'],
		'data'        => $t['data'],
		'options'     => $t['options'],
		'visibility'  => $t['visibility'],
	);

	unset( $core['options']['last_editor'] );

	return md5( wp_json_encode( $core ) );
}

/**
 * Copy the listed TablePress tables from live onto the SAME table ID on staging.
 * Only existing staging tables are updated (never created), and only the IDs in the settings.
 *
 * @param bool $apply false = report only.
 * @return array{same:array,diff:array,updated:array,no_local:array,no_live:array,failed:array,error:string}
 */
function dw_sync_tables( $apply = false ) {
	$res = array( 'same' => array(), 'diff' => array(), 'updated' => array(), 'no_local' => array(), 'no_live' => array(), 'failed' => array(), 'error' => '' );
	$ids = implode( ',', array_filter( array_map( 'trim', explode( ',', (string) dw_sync_settings()['tables'] ) ), 'ctype_digit' ) );

	if ( '' === $ids ) {
		return $res;
	}

	if ( ! class_exists( 'TablePress' ) ) {
		$res['error'] = 'TablePress is not active on staging.';

		return $res;
	}

	if ( empty( TablePress::$model_table ) ) {
		TablePress::$model_table = TablePress::load_model( 'table' );
	}

	$r = dw_sync_fetch( 'tables', array( 'ids' => $ids ) );

	if ( is_wp_error( $r ) ) {
		$res['error'] = $r->get_error_message();

		return $res;
	}

	foreach ( (array) $r['tables'] as $T ) {
		$id = (string) $T['id'];

		if ( ! empty( $T['missing'] ) ) {
			$res['no_live'][] = $id;
			continue;
		}

		$local = TablePress::$model_table->load( $id, true, true );

		if ( is_wp_error( $local ) || empty( $local ) ) {
			$res['no_local'][] = $id . ' "' . $T['name'] . '"';
			continue;
		}

		if ( dw_sync_table_hash( $local ) === $T['hash'] ) {
			$res['same'][] = $id . ' "' . $T['name'] . '"';
			continue;
		}

		$res['diff'][] = $id . ' "' . $T['name'] . '"';

		if ( ! $apply ) {
			continue;
		}

		$new                  = $local;
		$new['name']          = $T['name'];
		$new['description']   = $T['description'];
		$new['data']          = $T['data'];
		$new['visibility']    = $T['visibility'];
		$editor               = isset( $local['options']['last_editor'] ) ? $local['options']['last_editor'] : null;
		$new['options']       = $T['options'];
		$new['last_modified'] = current_time( 'mysql' );

		if ( null !== $editor ) {
			$new['options']['last_editor'] = $editor;
		}

		$saved = TablePress::$model_table->save( $new );

		if ( is_wp_error( $saved ) ) {
			$res['failed'][] = $id . ': ' . $saved->get_error_message();
			dw_sync_log( 'error', 0, 0, 'TablePress table ' . $id . ' copy failed: ' . $saved->get_error_message() );
			continue;
		}

		$res['updated'][] = $id . ' "' . $T['name'] . '"';
		dw_sync_log( 'info', 0, 0, 'TablePress table ' . $id . ' "' . $T['name'] . '" updated from live.' );
		do_action( 'tablepress_event_saved_table', $id );
	}

	return $res;
}
