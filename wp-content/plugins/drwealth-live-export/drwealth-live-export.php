<?php
/**
 * Plugin Name: DrWealth Live Export
 * Description: Install on the LIVE site (drwealth.com). Read-only, token-protected endpoint that lets the staging site pull posts (with all meta, taxonomies, author, comments, image URLs). Changes nothing on this site.
 * Author:      Dika / Jelly Pixel Studio
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DRWEALTH_EXPORT_TOKEN_OPTION = 'drwealth_export_token';

/* ------------------------------------------------------------------- Token */

function drwealth_export_token() {
	$t = get_option( DRWEALTH_EXPORT_TOKEN_OPTION, '' );

	if ( ! $t ) {
		$t = wp_generate_password( 48, false, false );
		update_option( DRWEALTH_EXPORT_TOKEN_OPTION, $t, false );
	}

	return $t;
}

register_activation_hook(
	__FILE__,
	function () {
		drwealth_export_token();
	}
);

add_action(
	'admin_menu',
	function () {
		add_management_page(
			'Live Export',
			'Live Export',
			'manage_options',
			'drwealth-live-export',
			function () {
				if ( isset( $_POST['dw_regen'] ) && check_admin_referer( 'dw_regen' ) ) {
					delete_option( DRWEALTH_EXPORT_TOKEN_OPTION );
				}
				?>
				<div class="wrap">
					<h1>Live Export</h1>
					<p>Read-only. Lets the staging site copy posts from here. It exposes <strong>everything</strong> about posts (including Premium article text), so keep the token secret.</p>
					<p>Endpoint: <code><?php echo esc_html( rest_url( 'drwealth-sync/v1/' ) ); ?></code></p>
					<p>Token (paste into staging: Tools &rarr; Live Sync):</p>
					<p><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( drwealth_export_token() ); ?>" /></p>
					<form method="post">
						<?php wp_nonce_field( 'dw_regen' ); ?>
						<button class="button" name="dw_regen" value="1" onclick="return confirm('Make a new token? Staging stops syncing until you paste the new one.');">Generate a new token</button>
					</form>
				</div>
				<?php
			}
		);
	}
);

/* -------------------------------------------------------------------- REST */

function drwealth_export_authorized( WP_REST_Request $r ) {
	$given = (string) $r->get_header( 'x-drwealth-token' );

	return ( '' !== $given && hash_equals( drwealth_export_token(), $given ) )
		? true
		: new WP_Error( 'dw_forbidden', 'Bad token.', array( 'status' => 403 ) );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'drwealth-sync/v1',
			'/posts',
			array(
				'methods'             => 'GET',
				'callback'            => 'drwealth_export_posts',
				'permission_callback' => 'drwealth_export_authorized',
			)
		);
		register_rest_route(
			'drwealth-sync/v1',
			'/index',
			array(
				'methods'             => 'GET',
				'callback'            => 'drwealth_export_index',
				'permission_callback' => 'drwealth_export_authorized',
			)
		);
	}
);

function drwealth_export_types( WP_REST_Request $r ) {
	$types = array_filter( array_map( 'sanitize_key', explode( ',', (string) $r->get_param( 'types' ) ) ) );

	return $types ? $types : array( 'post' );
}

/** Posts changed after (modified_gmt, id) cursor, oldest first. */
function drwealth_export_posts( WP_REST_Request $r ) {
	global $wpdb;

	$types = drwealth_export_types( $r );
	$limit = max( 1, min( 25, (int) $r->get_param( 'limit' ) ?: 10 ) );
	$mod   = (string) $r->get_param( 'after' );
	$id    = (int) $r->get_param( 'after_id' );
	$since = (string) $r->get_param( 'since' ); // Only posts published on/after this date (GMT).

	$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$q  = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','future','private','draft','pending')";
	$a  = $types;

	// Explicit list of IDs (used to force a re-sync of particular posts): no cursor.
	$want = array_filter( array_map( 'absint', explode( ',', (string) $r->get_param( 'ids' ) ) ) );

	if ( $want ) {
		$posts = array();
		foreach ( $want as $pid ) {
			$p = get_post( $pid );
			if ( $p && in_array( $p->post_type, $types, true ) && in_array( $p->post_status, array( 'publish', 'future', 'private', 'draft', 'pending' ), true ) ) {
				$posts[] = drwealth_export_post( $pid );
			}
		}

		return array( 'posts' => $posts, 'count' => count( $posts ) );
	}

	if ( '' !== $mod ) {
		$q .= ' AND (post_modified_gmt > %s OR (post_modified_gmt = %s AND ID > %d))';
		array_push( $a, $mod, $mod, $id );
	}

	if ( '' !== $since ) {
		// Drafts have no publish date yet, so they are matched on last-modified instead.
		$q .= " AND (post_date_gmt >= %s OR (post_status IN ('draft','pending') AND post_modified_gmt >= %s))";
		array_push( $a, $since, $since );
	}

	$q .= ' ORDER BY post_modified_gmt ASC, ID ASC LIMIT %d';
	$a[] = $limit;

	$ids   = $wpdb->get_col( $wpdb->prepare( $q, $a ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$posts = array();

	foreach ( $ids as $pid ) {
		$posts[] = drwealth_export_post( (int) $pid );
	}

	return array( 'posts' => $posts, 'count' => count( $posts ) );
}

/* ------------------------------------------------------------ TablePress tables */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'drwealth-sync/v1',
			'/tables',
			array(
				'methods'             => 'GET',
				'callback'            => 'drwealth_export_tables',
				'permission_callback' => 'drwealth_export_authorized',
			)
		);
	}
);

/** Named TablePress tables (ids=13,86,95): name, description, data, options, visibility, plus a hash of those. */
function drwealth_export_tables( WP_REST_Request $r ) {
	if ( ! class_exists( 'TablePress' ) ) {
		return new WP_Error( 'dw_notp', 'TablePress is not active here.', array( 'status' => 404 ) );
	}

	if ( empty( TablePress::$model_table ) ) {
		TablePress::$model_table = TablePress::load_model( 'table' );
	}

	$out = array();

	foreach ( array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $r->get_param( 'ids' ) ) ) ) as $id ) {
		$t = TablePress::$model_table->load( $id, true, true );

		if ( is_wp_error( $t ) || empty( $t ) ) {
			$out[] = array( 'id' => (string) $id, 'missing' => true );
			continue;
		}

		$core = array(
			'name'        => $t['name'],
			'description' => $t['description'],
			'data'        => $t['data'],
			'options'     => $t['options'],
			'visibility'  => $t['visibility'],
		);

		unset( $core['options']['last_editor'] ); // Who edited it last differs per site: not part of the comparison.

		$out[] = array_merge( array( 'id' => (string) $id, 'last_modified' => isset( $t['last_modified'] ) ? $t['last_modified'] : '' ), $core, array( 'hash' => md5( wp_json_encode( $core ) ) ) );
	}

	return array( 'tables' => $out );
}

/** Light index of everything in scope, for audits. */
function drwealth_export_index( WP_REST_Request $r ) {
	global $wpdb;

	$types = drwealth_export_types( $r );
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID AS id, post_type AS type, post_status AS status, post_name AS slug, post_modified_gmt AS modified FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status NOT IN ('auto-draft','inherit')", $types ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return array( 'index' => $rows );
}

function drwealth_export_skip_meta( $key ) {
	$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_pingme', '_encloseme', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	return in_array( $key, $skip, true ) || (bool) apply_filters( 'drwealth_export_skip_meta', false, $key );
}

function drwealth_export_attachment( $aid ) {
	$url = wp_get_attachment_url( $aid );

	if ( ! $url ) {
		return null;
	}

	$p = get_post( $aid );

	return array(
		'id'      => (int) $aid,
		'url'     => $url,
		'title'   => $p ? $p->post_title : '',
		'caption' => $p ? $p->post_excerpt : '',
		'alt'     => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
		'mime'    => $p ? $p->post_mime_type : '',
	);
}

function drwealth_export_user( $uid ) {
	$u = get_userdata( $uid );

	if ( ! $u ) {
		return null;
	}

	$role = $u->roles ? $u->roles[0] : '';

	return array(
		'id'           => (int) $u->ID,
		'login'        => $u->user_login,
		'email'        => $u->user_email,
		'display_name' => $u->display_name,
		'nicename'     => $u->user_nicename,
		'first_name'   => $u->first_name,
		'last_name'    => $u->last_name,
		'nickname'     => $u->nickname,
		'description'  => $u->description,
		'url'          => $u->user_url,
		'role'         => $role,
		'avatar_url'   => drwealth_export_avatar_url( $u->ID ),
		'meta'         => drwealth_export_user_meta( $u->ID ),
	);
}

/**
 * Profile meta for a writer account, minus anything private or tied to this site:
 * sessions, passwords/tokens/keys, capabilities, per-site (table-prefixed) settings,
 * WooCommerce billing/shipping/session data and avatar attachment IDs (the photo travels separately).
 */
function drwealth_export_user_meta( $uid ) {
	global $wpdb;

	$out = array();

	foreach ( get_user_meta( $uid ) as $k => $vals ) {
		if ( 0 === strpos( $k, $wpdb->prefix ) || 0 === strpos( $k, 'wp_' ) || 0 === strpos( $k, 'billing_' ) || 0 === strpos( $k, 'shipping_' ) || 0 === strpos( $k, '_woocommerce' ) || 0 === strpos( $k, 'wc_' ) || 0 === strpos( $k, '_dw_' ) ) {
			continue;
		}

		if ( preg_match( '/session|password|token|secret|api_?key|capabilit|user_level|avatar|application_passwords|activation|reset|nonce|last_login|dismissed|closedpostboxes|metaboxhidden|meta-box-order/i', $k ) ) {
			continue;
		}

		$out[ $k ] = maybe_unserialize( reset( $vals ) );
	}

	return $out;
}

/**
 * The photo visitors actually see. Profile plugins (ProfilePress etc.) swap the picture in the
 * rendered <img>, not in get_avatar_url(), so read the src from get_avatar() first.
 */
function drwealth_export_avatar_url( $uid ) {
	$html = get_avatar( $uid, 256 );

	if ( $html && preg_match( '/src=(["\'])(.*?)\1/i', $html, $m ) && false === strpos( $m[2], 'gravatar.com' ) && false === strpos( $m[2], 'secure.gravatar' ) ) {
		return html_entity_decode( $m[2] );
	}

	return get_avatar_url( $uid, array( 'size' => 256, 'default' => '404' ) );
}

function drwealth_export_post( $id ) {
	$p = get_post( $id );

	// Terms with their ancestors, so staging can rebuild the hierarchy.
	$terms = array();
	foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
		$list = wp_get_object_terms( $id, $tax );

		if ( is_wp_error( $list ) ) {
			continue;
		}

		foreach ( $list as $t ) {
			$chain = array();
			$cur   = $t;
			while ( $cur && $cur->parent ) {
				$cur     = get_term( $cur->parent, $tax );
				$chain[] = $cur && ! is_wp_error( $cur ) ? array( 'slug' => $cur->slug, 'name' => $cur->name ) : null;
				if ( ! $cur || is_wp_error( $cur ) ) {
					break;
				}
			}
			$terms[ $tax ][] = array(
				'slug'      => $t->slug,
				'name'      => $t->name,
				'ancestors' => array_reverse( array_values( array_filter( $chain ) ) ), // Top first.
			);
		}
	}

	// All meta + any attachment records the meta points to.
	$meta        = array();
	$attachments = array();

	foreach ( get_post_meta( $id ) as $k => $vals ) {
		if ( drwealth_export_skip_meta( $k ) ) {
			continue;
		}

		$out = array();
		foreach ( $vals as $v ) {
			$v     = maybe_unserialize( $v );
			$out[] = $v;

			if ( is_scalar( $v ) && ctype_digit( (string) $v ) && (int) $v > 0 && 'attachment' === get_post_type( (int) $v ) ) {
				$att = drwealth_export_attachment( (int) $v );
				if ( $att ) {
					$attachments[ (int) $v ] = $att;
				}
			}
		}
		$meta[ $k ] = $out;
	}

	$thumb = (int) get_post_thumbnail_id( $id );
	if ( $thumb && empty( $attachments[ $thumb ] ) ) {
		$att = drwealth_export_attachment( $thumb );
		if ( $att ) {
			$attachments[ $thumb ] = $att;
		}
	}

	$comments = array();
	foreach ( get_comments( array( 'post_id' => $id, 'status' => 'approve', 'type' => 'comment', 'orderby' => 'comment_ID', 'order' => 'ASC' ) ) as $c ) {
		$comments[] = array(
			'id'           => (int) $c->comment_ID,
			'parent'       => (int) $c->comment_parent,
			'author'       => $c->comment_author,
			'author_email' => $c->comment_author_email,
			'author_url'   => $c->comment_author_url,
			'date_gmt'     => $c->comment_date_gmt,
			'content'      => $c->comment_content,
			'user_login'   => $c->user_id ? ( ( $cu = get_userdata( $c->user_id ) ) ? $cu->user_login : '' ) : '',
		);
	}

	$data = array(
		'id'             => (int) $p->ID,
		'type'           => $p->post_type,
		'status'         => $p->post_status,
		'slug'           => $p->post_name,
		'title'          => $p->post_title,
		'content'        => $p->post_content,
		'excerpt'        => $p->post_excerpt,
		'date'           => $p->post_date,
		'date_gmt'       => $p->post_date_gmt,
		'modified'       => $p->post_modified,
		'modified_gmt'   => $p->post_modified_gmt,
		'parent'         => (int) $p->post_parent,
		'menu_order'     => (int) $p->menu_order,
		'comment_status' => $p->comment_status,
		'ping_status'    => $p->ping_status,
		'url'            => get_permalink( $p ),
		'author'         => drwealth_export_user( (int) $p->post_author ),
		'terms'          => $terms,
		'meta'           => $meta,
		'thumbnail_id'   => $thumb,
		'attachments'    => (object) $attachments,
		'comments'       => $comments,
	);

	$data['hash'] = md5( wp_json_encode( array( $data['title'], $data['content'], $data['excerpt'], $data['slug'], $data['status'], $data['date_gmt'], $data['author'] ? $data['author']['login'] : '', $data['terms'], $data['meta'], $data['thumbnail_id'], $data['parent'], $data['comment_status'] ) ) );

	return $data;
}
