<?php
/**
 * Know exactly which posts and users the Substack imports touched, so they can be found
 * (and undone) later.
 *
 * Every imported post / subscriber gets:
 *   _drwealth_substack_origin       created | existing   set once: did the import create it, or did it already exist?
 *   _drwealth_substack_imported_at  last time an import touched it
 *
 * Posts > All Posts and Users > All Users get a "Substack import" filter and a column.
 * Items imported before this existed are still found by "Any Substack import" (via the
 * Substack ID / publications meta they already carried), but their origin is unknown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DRWEALTH_SUBSTACK_ORIGIN_META = '_drwealth_substack_origin';
const DRWEALTH_SUBSTACK_AT_META     = '_drwealth_substack_imported_at';

/** Called by the importers after each post / subscriber. $result is 'created' or 'updated'. */
function drwealth_substack_stamp( $type, $id, $result ) {
	$origin_key = DRWEALTH_SUBSTACK_ORIGIN_META;

	if ( 'user' === $type ) {
		if ( ! get_user_meta( $id, $origin_key, true ) ) {
			update_user_meta( $id, $origin_key, 'created' === $result ? 'created' : 'existing' );
		}

		update_user_meta( $id, DRWEALTH_SUBSTACK_AT_META, current_time( 'mysql' ) );

		return;
	}

	if ( ! get_post_meta( $id, $origin_key, true ) ) {
		update_post_meta( $id, $origin_key, 'created' === $result ? 'created' : 'existing' );
	}

	update_post_meta( $id, DRWEALTH_SUBSTACK_AT_META, current_time( 'mysql' ) );
}

function drwealth_substack_filter_options( $current ) {
	$opts = array(
		''         => 'Substack import: all',
		'any'      => 'Any Substack import',
		'created'  => 'Created by Substack import',
		'existing' => 'Already existed, updated by import',
	);

	foreach ( $opts as $v => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $current, $v, false ), esc_html( $label ) );
	}
}

function drwealth_substack_meta_query( $v, $any_key ) {
	if ( 'any' === $v ) {
		return array( 'key' => $any_key, 'compare' => 'EXISTS' );
	}

	return array( 'key' => DRWEALTH_SUBSTACK_ORIGIN_META, 'value' => $v );
}

/* ------------------------------------------------------------------- Posts */

add_action(
	'restrict_manage_posts',
	function ( $post_type ) {
		if ( 'attachment' === $post_type ) {
			return;
		}

		$cur = isset( $_GET['dw_substack'] ) ? sanitize_key( wp_unslash( $_GET['dw_substack'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<select name="dw_substack">';
		drwealth_substack_filter_options( $cur );
		echo '</select>';
	}
);

add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || empty( $_GET['dw_substack'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$v      = sanitize_key( wp_unslash( $_GET['dw_substack'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$meta   = (array) $q->get( 'meta_query' );
		$meta[] = drwealth_substack_meta_query( $v, '_drwealth_substack_post_id' );
		$q->set( 'meta_query', $meta );
	}
);

add_filter(
	'manage_posts_columns',
	function ( $cols ) {
		$cols['dw_substack'] = 'Substack';

		return $cols;
	}
);

add_action(
	'manage_posts_custom_column',
	function ( $col, $post_id ) {
		if ( 'dw_substack' !== $col ) {
			return;
		}

		if ( ! get_post_meta( $post_id, '_drwealth_substack_post_id', true ) ) {
			echo '&ndash;';

			return;
		}

		$origin = get_post_meta( $post_id, DRWEALTH_SUBSTACK_ORIGIN_META, true );
		$at     = get_post_meta( $post_id, DRWEALTH_SUBSTACK_AT_META, true );
		$pub    = get_post_meta( $post_id, '_drwealth_substack_publication', true );

		echo esc_html( $pub ) . '<br /><small>' . esc_html( ( $origin ? $origin : 'imported (origin unknown)' ) . ( $at ? ' ' . $at : '' ) ) . '</small>';
	},
	10,
	2
);

/* ------------------------------------------------------------------- Users */

add_action(
	'restrict_manage_users',
	function ( $which ) {
		$cur = '';

		foreach ( array( 'dw_substack_top', 'dw_substack_bottom' ) as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$cur = sanitize_key( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		echo '<select name="dw_substack_' . esc_attr( $which ) . '">';
		drwealth_substack_filter_options( $cur );
		echo '</select> <input type="submit" class="button" value="Filter" />';
	}
);

add_action(
	'pre_get_users',
	function ( $q ) {
		if ( ! is_admin() ) {
			return;
		}

		$v = '';

		foreach ( array( 'dw_substack_top', 'dw_substack_bottom' ) as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$v = sanitize_key( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}

		if ( ! $v ) {
			return;
		}

		$meta   = (array) $q->get( 'meta_query' );
		$meta[] = drwealth_substack_meta_query( $v, '_drwealth_substack_publications' );
		$q->set( 'meta_query', $meta );
	}
);

add_filter(
	'manage_users_columns',
	function ( $cols ) {
		$cols['dw_substack'] = 'Substack import';

		return $cols;
	}
);

add_filter(
	'manage_users_custom_column',
	function ( $out, $col, $user_id ) {
		if ( 'dw_substack' !== $col ) {
			return $out;
		}

		$pubs = get_user_meta( $user_id, '_drwealth_substack_publications', true );

		if ( ! $pubs ) {
			return '&ndash;';
		}

		$origin = get_user_meta( $user_id, DRWEALTH_SUBSTACK_ORIGIN_META, true );
		$at     = get_user_meta( $user_id, DRWEALTH_SUBSTACK_AT_META, true );

		return esc_html( $pubs ) . '<br /><small>' . esc_html( ( $origin ? $origin : 'imported (origin unknown)' ) . ( $at ? ' ' . $at : '' ) ) . '</small>';
	},
	10,
	3
);
