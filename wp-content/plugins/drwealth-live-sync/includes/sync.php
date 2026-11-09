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
			'daily'        => 1,
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

function dw_sync_user( $U ) {
	if ( empty( $U['login'] ) ) {
		return 0;
	}

	$found = get_users( array( 'meta_key' => '_dw_live_user_id', 'meta_value' => (int) $U['id'], 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB

	if ( $found ) {
		return (int) $found[0];
	}

	$u = get_user_by( 'login', $U['login'] );

	if ( ! $u && ! empty( $U['email'] ) ) {
		$u = get_user_by( 'email', $U['email'] );
	}

	if ( $u ) {
		update_user_meta( $u->ID, '_dw_live_user_id', (int) $U['id'] );

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
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit')", $P['slug'], $P['type'] ) ); // phpcs:ignore WordPress.DB

	foreach ( $ids as $id ) {
		if ( dw_sync_is_substack( $id ) || get_post_meta( $id, '_dw_live_id', true ) ) {
			continue;
		}

		return array( (int) $id, false );
	}

	return array( 0, false );
}

function dw_sync_meta_skipped( $k ) {
	return 0 === strpos( $k, '_dw_' )
		|| '_thumbnail_id' === $k
		|| false !== strpos( $k, 'force_public' ) // Never bring over "force public" flags: they open Premium posts.
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
		} elseif ( $P['modified_gmt'] <= $mod_local ) {
			// Existing staging copy, not newer on live: just link it.
			update_post_meta( $local, '_dw_live_id', (int) $P['id'] );
			update_post_meta( $local, '_dw_live_hash', $P['hash'] );
			update_post_meta( $local, '_dw_local_modified', $mod_local );
			update_post_meta( $local, '_dw_live_url', $P['url'] );
			dw_sync_stamp( $local, 'linked' );

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
	dw_sync_log( 'info', $P['id'], $id, ucfirst( $result ) . ': "' . $P['title'] . '"' . ( $author ? '' : ' (author not mapped!)' ) );

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
