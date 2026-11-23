<?php
/**
 * Admin: Tools > Live Sync (settings, status, buttons, log).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Live Sync', 'Live Sync', 'manage_options', 'drwealth-live-sync', 'dw_sync_render' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'drwealth_live_sync',
			DW_SYNC_OPTION,
			array(
				'sanitize_callback' => function ( $in ) {
					$old = dw_sync_settings();

					return array(
						'url'          => esc_url_raw( isset( $in['url'] ) ? $in['url'] : $old['url'] ),
						'token'        => ( isset( $in['token'] ) && '' !== trim( $in['token'] ) ) ? trim( $in['token'] ) : $old['token'],
						'types'        => sanitize_text_field( isset( $in['types'] ) ? $in['types'] : 'post' ),
						'tables'       => isset( $in['tables'] ) ? preg_replace( '/[^0-9,]/', '', $in['tables'] ) : '',
						'drafts_since' => ( isset( $in['drafts_since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in['drafts_since'] ) ) ? $in['drafts_since'] : '',
						'since'        => ( isset( $in['since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in['since'] ) ) ? $in['since'] : '',
						'daily'        => empty( $in['daily'] ) ? 0 : 1,
						'mirror_removed' => empty( $in['mirror_removed'] ) ? 0 : 1,
						'conflict'     => ( isset( $in['conflict'] ) && 'overwrite' === $in['conflict'] ) ? 'overwrite' : 'skip',
						'category_map' => sanitize_textarea_field( isset( $in['category_map'] ) ? $in['category_map'] : '' ),
						'strip'        => isset( $in['strip'] ) ? trim( wp_unslash( $in['strip'] ) ) : $old['strip'],
						'force_ids'    => preg_replace( '/[^0-9,]/', '', isset( $in['force_ids'] ) ? $in['force_ids'] : '' ),
					);
				},
			)
		);
	}
);

dw_sync_action(
	'dw_sync_subs_check',
	function () {
		list( $u, $kept ) = dw_sync_subscriber_candidates();
		$n = dw_sync_subscriber_counts( $u );

		return sprintf( 'Subscriber clean-up check (nothing deleted): %d imported subscribers would be removed, with %d memberships and %d order/subscription links. %d imported users are kept (admins/editors/authors with posts, or you).', $n['users'], $n['memberships'], $n['orders'], $kept );
	}
);

dw_sync_action(
	'dw_sync_subs_delete',
	function () {
		$r = dw_sync_subscriber_delete( 25 );

		return sprintf( 'Subscriber clean-up: %d users removed (with their memberships and orders), about %d left. The rest continues in the background (refresh later and click Subscribers: check to see what remains); once it says 0, run ID fix.', $r['done'], $r['left'] );
	}
);

dw_sync_action(
	'dw_sync_subs_inspect',
	function () {
		global $wpdb;

		$out   = array();
		$roles = count_users();
		$out[] = 'Total users: ' . $roles['total_users'] . '. By role: ' . wp_json_encode( $roles['avail_roles'] ) . '.';

		$out[] = 'Users with a membership: ' . (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'wc_user_membership'" ) . '.'; // phpcs:ignore WordPress.DB

		$keys = $wpdb->get_results( "SELECT meta_key, COUNT(*) c FROM {$wpdb->usermeta} WHERE meta_key NOT IN ('nickname','first_name','last_name','description','rich_editing','syntax_highlighting','comment_shortcuts','admin_color','use_ssl','show_admin_bar_front','locale','session_tokens') AND meta_key NOT LIKE '{$wpdb->prefix}%' GROUP BY meta_key ORDER BY c DESC LIMIT 14" ); // phpcs:ignore WordPress.DB
		$k     = array();
		foreach ( $keys as $r ) {
			$k[] = $r->meta_key . ' (' . $r->c . ')';
		}
		$out[] = 'Top user meta keys: ' . implode( ', ', $k ) . '.';

		$days = $wpdb->get_results( "SELECT DATE(user_registered) d, COUNT(*) c FROM {$wpdb->users} GROUP BY d ORDER BY c DESC LIMIT 5" ); // phpcs:ignore WordPress.DB
		$d    = array();
		foreach ( $days as $r ) {
			$d[] = $r->d . ' (' . $r->c . ')';
		}
		$out[] = 'Busiest registration days: ' . implode( ', ', $d ) . '.';

		$out[] = 'Memberships by plan-author overlap with marked users: marked=' . (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = '_drwealth_substack_publications'" ) . '.'; // phpcs:ignore WordPress.DB

		return implode( ' ', $out );
	}
);

dw_sync_action(
	'dw_sync_rollout_audit',
	function () {
		global $wpdb;

		$out = array();

		// 1. Staging-only posts dated after 11 Mar 2025 (not from live, not Substack).
		$linked   = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dw_live_id'" ) ); // phpcs:ignore WordPress.DB
		$substack = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_drwealth_substack_post_id'" ) ); // phpcs:ignore WordPress.DB
		$skip     = array_flip( array_merge( $linked, $substack ) );
		$only     = array();

		foreach ( $wpdb->get_results( "SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('trash','auto-draft','inherit') AND post_date > '2025-03-11' ORDER BY post_date DESC" ) as $p ) { // phpcs:ignore WordPress.DB
			if ( ! isset( $skip[ (int) $p->ID ] ) ) {
				$only[] = '#' . $p->ID . ' "' . wp_html_excerpt( $p->post_title, 40 ) . '" (' . $p->post_status . ', ' . substr( $p->post_date, 0, 10 ) . ')';
			}
		}

		$out[] = '1) Staging-only posts after 11 Mar 2025 (not from live, not Substack): ' . count( $only ) . ( $only ? ' - ' . implode( '; ', array_slice( $only, 0, 30 ) ) : '' ) . '.';

		// 2. Writers.
		$w = array();
		foreach ( array( 'qiyang', 'shaurya' ) as $login ) {
			$u = get_user_by( 'slug', $login );
			$u = $u ? $u : get_user_by( 'login', $login );

			if ( ! $u ) {
				$w[] = $login . ': MISSING';
				continue;
			}

			$lid = get_user_meta( $u->ID, '_dw_live_user_id', true );
			$w[] = sprintf( '%s: "%s", role %s, bio %d chars, live user #%s, %d posts, %s photo', $login, $u->display_name, implode( '/', $u->roles ), strlen( $u->description ), $lid ? $lid : '?', count_user_posts( $u->ID, 'post', true ), false !== strpos( get_avatar_url( $u->ID ), 'gravatar' ) ? 'NO uploaded' : 'uploaded' );
		}
		$out[] = '2) Writers: ' . implode( ' | ', $w ) . '.';

		// 3. Specific posts against live.
		$r   = dw_sync_fetch( 'index', array( 'types' => dw_sync_types_arg() ) );
		$idx = array();

		if ( ! is_wp_error( $r ) ) {
			foreach ( $r['index'] as $row ) {
				$idx[ (int) $row['id'] ] = $row;
			}
		}

		$rows = array();
		foreach ( array( 114216, 114872, 146200645, 146204855, 94493, 96433, 31189, 146201611, 116388, 58571 ) as $id ) {
			$p      = get_post( $id );
			$liveid = $p ? (int) ( get_post_meta( $id, '_dw_live_id', true ) ? get_post_meta( $id, '_dw_live_id', true ) : $id ) : $id;
			$live   = isset( $idx[ $liveid ] ) ? $idx[ $liveid ]['status'] : ( is_wp_error( $r ) ? 'live unreachable' : 'NOT ON LIVE' );

			if ( $p ) {
				$rec  = get_post_meta( $id, '_dw_local_modified', true );
				$sync = get_post_meta( $id, '_dw_synced_at', true );
				$info = ( $rec && $rec !== $p->post_modified_gmt ? ', EDITED on staging' : '' ) . ', staging ' . $p->post_status . ', synced ' . ( $sync ? $sync : 'never' );
			} else {
				$info = ', not on staging';
			}

			$rows[] = '#' . $id . ': live ' . $live . $info;
		}
		$out[] = '3) Posts: ' . implode( ' | ', $rows ) . '.';

		return implode( ' ', $out );
	}
);

dw_sync_action(
	'dw_sync_removed_check',
	function () {
		$r = dw_sync_mirror_removed( false );

		if ( $r['error'] ) {
			return 'Removed-on-live check failed: ' . $r['error'];
		}

		return sprintf( 'Removed-on-live check (nothing changed): %d would go to Trash%s. %d kept (also edited on staging)%s.', count( $r['removed'] ), $r['removed'] ? ' - ' . implode( '; ', array_slice( $r['removed'], 0, 30 ) ) : '', count( $r['kept'] ), $r['kept'] ? ' - ' . implode( '; ', array_slice( $r['kept'], 0, 15 ) ) : '' );
	}
);

dw_sync_action(
	'dw_sync_removed_apply',
	function () {
		$r = dw_sync_mirror_removed( true );

		return $r['error'] ? 'Failed: ' . $r['error'] : sprintf( 'Moved %d posts to Trash (they can be restored from Posts > Trash). %d kept because they were also edited on staging.', count( $r['removed'] ), count( $r['kept'] ) );
	}
);

dw_sync_action(
	'dw_sync_author_photos',
	function () {
		$r = dw_sync_author_photos();

		return sprintf( 'Author profiles: %d synced this batch (%d with a live photo), %d could not be fetched, %d left. Click again until 0 are left.', $r['done'], $r['photos'], $r['none'], $r['left'] );
	}
);

dw_sync_action(
	'dw_sync_preview',
	function () {
		$st = dw_sync_preview( 25 );

		if ( $st['error'] ) {
			return 'Preview stopped: ' . $st['error'];
		}

		$n = $st['n'];

		if ( ! $st['done'] ) {
			return sprintf( 'Preview in progress (nothing is changed): %d live posts checked so far. Click "Full re-check: preview" again until it says complete.', $st['scanned'] );
		}

		return sprintf(
			'Full re-check preview COMPLETE (nothing changed). %d live posts checked: %d would be created%s. %d would be updated from live%s. %d would be linked as-is. %d unchanged (skipped). %d changed on both sides, staging kept%s.',
			$st['scanned'],
			$n['create'],
			$st['create'] ? ' - ' . implode( '; ', array_slice( $st['create'], 0, 25 ) ) : '',
			$n['update'],
			$st['update'] ? ' - ' . implode( '; ', array_slice( $st['update'], 0, 25 ) ) : '',
			$n['link'],
			$n['skip'],
			$n['conflict'],
			$st['conflict'] ? ' - ' . implode( '; ', array_slice( $st['conflict'], 0, 15 ) ) : ''
		);
	}
);

dw_sync_action(
	'dw_sync_drafts_check',
	function () {
		$r = dw_sync_drafts( false );

		if ( $r['error'] ) {
			return 'Live drafts check failed: ' . $r['error'];
		}

		$list = array();
		foreach ( array_slice( $r['todo'], 0, 40, true ) as $id => $slug ) {
			$list[] = '#' . $id . ' ' . $slug;
		}

		return sprintf( 'Live drafts check (nothing changed): %d draft/pending posts on live are not linked yet: %d match a draft already on staging (would be linked, and updated only if the text differs) and %d would be created new. %d older ones are ignored by the drafts cut-off. First ones%s.', count( $r['todo'] ), $r['link'], $r['create'], $r['older'], $list ? ' - ' . implode( '; ', $list ) : '' );
	}
);

dw_sync_action(
	'dw_sync_drafts_import',
	function () {
		$r = dw_sync_drafts( true, 25 );

		if ( $r['error'] ) {
			return 'Live drafts import stopped: ' . $r['error'];
		}

		$d = $r['done'];

		return sprintf( 'Live drafts: %d created, %d linked, %d updated, %d failed. %d left%s', $d['created'], $d['linked'], $d['updated'], $d['failed'], count( $r['todo'] ), count( $r['todo'] ) ? ' - click again until 0 are left.' : '.' );
	}
);

dw_sync_action(
	'dw_sync_reid_clear',
	function () {
		$r = dw_sync_reid_clear();

		return sprintf( 'Freed %d live IDs by deleting old revisions / trashed posts%s. Left alone (not revisions or trash): %d%s. Now click "ID fix: run".', count( $r['deleted'] ), $r['deleted'] ? ' - ' . implode( '; ', $r['deleted'] ) : '', count( $r['kept'] ), $r['kept'] ? ' - ' . implode( '; ', $r['kept'] ) : '' );
	}
);

dw_sync_action(
	'dw_sync_idblock_check',
	function () {
		$s = dw_sync_idblock_status();

		return sprintf(
			'ID block (read only): highest post ID on staging %d; the next new post or image will get ID %d. %s',
			$s['max'],
			$s['next'],
			$s['reserved'] ? 'A block is already reserved at #' . $s['reserved'] . '.' : 'No block reserved yet.'
		);
	}
);

dw_sync_action(
	'dw_sync_idblock_reserve',
	function () {
		return dw_sync_idblock_reserve( 147000000 );
	}
);

dw_sync_action(
	'dw_sync_sitemap_now',
	function () {
		if ( ! class_exists( 'Jetpack_Sitemap_Manager' ) ) {
			return 'Jetpack sitemaps are not available on this site.';
		}

		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		do_action( 'jp_sitemap_cron_hook' );
		dw_sync_log( 'info', 0, 0, 'Jetpack sitemap rebuilt now.' );

		return 'Jetpack sitemap rebuild ran. Check /sitemap.xml and the image sitemap.';
	}
);

dw_sync_action(
	'dw_sync_drafts_compare',
	function () {
		$r = dw_sync_drafts_compare();

		if ( $r['error'] ) {
			return 'Drafts compare failed: ' . $r['error'];
		}

		return sprintf(
			'Drafts compare (nothing changed): live has %d drafts, staging has %d. On live but not paired on staging: %d%s. On staging but not paired on live: %d%s. Staging draft but live is another status: %d%s.',
			$r['live'],
			$r['staging'],
			count( $r['missing'] ),
			$r['missing'] ? ' - ' . implode( '; ', array_slice( $r['missing'], 0, 20 ) ) : '',
			count( $r['stg_only'] ),
			$r['stg_only'] ? ' - ' . implode( '; ', array_slice( $r['stg_only'], 0, 20 ) ) : '',
			count( $r['status'] ),
			$r['status'] ? ' - ' . implode( '; ', array_slice( $r['status'], 0, 20 ) ) : ''
		);
	}
);

dw_sync_action(
	'dw_sync_tables_check',
	function () {
		$r = dw_sync_tables( false );

		if ( $r['error'] ) {
			return 'TablePress check failed: ' . $r['error'];
		}

		return sprintf( 'TablePress check (nothing changed): %d identical to live%s. %d differ and would be updated%s. Not on staging (would not be created): %d%s. Not on live: %d%s.', count( $r['same'] ), $r['same'] ? ' (' . implode( '; ', $r['same'] ) . ')' : '', count( $r['diff'] ), $r['diff'] ? ' - ' . implode( '; ', $r['diff'] ) : '', count( $r['no_local'] ), $r['no_local'] ? ' - ' . implode( '; ', $r['no_local'] ) : '', count( $r['no_live'] ), $r['no_live'] ? ' - ' . implode( '; ', $r['no_live'] ) : '' );
	}
);

dw_sync_action(
	'dw_sync_tables_copy',
	function () {
		$r = dw_sync_tables( true );

		if ( $r['error'] ) {
			return 'TablePress copy failed: ' . $r['error'];
		}

		return sprintf( 'TablePress: %d updated from live%s; %d already identical; %d failed%s.', count( $r['updated'] ), $r['updated'] ? ' (' . implode( '; ', $r['updated'] ) . ')' : '', count( $r['same'] ), count( $r['failed'] ), $r['failed'] ? ' - ' . implode( '; ', $r['failed'] ) : '' );
	}
);

/** An admin-post button: nonce + capability, then a one-line notice. */
function dw_sync_action( $name, $cb ) {
	add_action(
		'admin_post_' . $name,
		function () use ( $name, $cb ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'No.' );
			}

			check_admin_referer( $name );
			set_transient( 'dw_sync_notice', call_user_func( $cb ), 60 );
			wp_safe_redirect( admin_url( 'tools.php?page=drwealth-live-sync' ) );
			exit;
		}
	);
}

dw_sync_action(
	'dw_sync_test',
	function () {
		$r = dw_sync_fetch( 'index', array( 'types' => dw_sync_types_arg() ) );

		return is_wp_error( $r ) ? 'Connection FAILED: ' . $r->get_error_message() : 'Connected. Live has ' . count( $r['index'] ) . ' items of the selected types.';
	}
);

dw_sync_action(
	'dw_sync_now',
	function () {
		$err = dw_sync_run( 25 );

		return $err ? 'Sync problem: ' . $err : 'Sync ran. If there is more to do it continues in the background; refresh to see progress.';
	}
);

dw_sync_action(
	'dw_sync_reset',
	function () {
		update_option( DW_SYNC_STATE, array_merge( dw_sync_state(), array( 'cursor_mod' => '', 'cursor_id' => 0 ) ), false );
		wp_schedule_single_event( time(), DW_SYNC_CONT );
		spawn_cron();

		return 'Cursor reset. A full pass is starting (unchanged posts are skipped quickly).';
	}
);

dw_sync_action(
	'dw_sync_audit',
	function () {
		global $wpdb;

		$types = array_filter( array_map( 'sanitize_key', explode( ',', dw_sync_types_arg() ) ) );
		$r     = dw_sync_fetch( 'index', array( 'types' => implode( ',', $types ) ) );

		if ( is_wp_error( $r ) ) {
			return 'Audit failed: ' . $r->get_error_message();
		}

		$since = dw_sync_since_gmt();
		$live  = array();

		foreach ( $r['index'] as $row ) {
			if ( in_array( $row['status'], array( 'publish', 'future', 'private', 'draft', 'pending' ), true ) ) {
				$live[ (int) $row['id'] ] = $row;
			}
		}

		// Staging side: linked posts, and unlinked candidates by type + slug (Substack imports excluded).
		$linked = array();
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dw_live_id'" ) as $l ) { // phpcs:ignore WordPress.DB
			$linked[ (int) $l->meta_value ] = (int) $l->post_id;
		}

		$substack = array_flip( array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_drwealth_substack_post_id'" ) ) ); // phpcs:ignore WordPress.DB
		$in       = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$local    = array();

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_name, post_modified_gmt FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status NOT IN ('trash','auto-draft','inherit')", $types ) ) as $p ) { // phpcs:ignore WordPress.DB
			if ( ! isset( $substack[ (int) $p->ID ] ) && ! in_array( (int) $p->ID, $linked, true ) && ! isset( $local[ $p->post_type . '|' . $p->post_name ] ) ) {
				$local[ $p->post_type . '|' . $p->post_name ] = $p;
			}
		}

		$c          = array( 'create' => 0, 'link' => 0, 'update' => 0, 'already' => 0, 'diffid' => 0 );
		$update     = array();
		$gone       = array();
		$create_ids = array();
		$diff_linked = array();
		$c['diffid_linked'] = 0;

		foreach ( $live as $id => $row ) {
			if ( $since && $row['modified'] < $since ) {
				continue;
			}

			if ( isset( $linked[ $id ] ) ) {
				$c['already']++;

				if ( (int) $linked[ $id ] !== $id ) {
					$c['diffid_linked']++; // Already synced under a different ID: its old Jetpack views will not line up.
					$diff_linked[] = 'live #' . $id . ' is staging #' . $linked[ $id ] . ' (' . $row['slug'] . ')';
				}

				continue;
			}

			$key = $row['type'] . '|' . $row['slug'];

			if ( ! isset( $local[ $key ] ) ) {
				$c['create']++;
				$create_ids[ $id ] = $row['slug'];
			} else {
				if ( (int) $local[ $key ]->ID !== $id ) {
					$c['diffid']++; // Matched post has a different ID than on live: its old Jetpack views won't line up.
				}

				if ( $row['modified'] <= $local[ $key ]->post_modified_gmt ) {
					$c['link']++; // Staging copy not older: just linked, not changed.
				} else {
					$c['update']++;
					$update[] = '#' . $id . ' ' . $row['slug'] . ' (live ' . $row['modified'] . ' vs staging ' . $local[ $key ]->post_modified_gmt . ')';
				}
			}
		}

		foreach ( $linked as $lid => $pid ) {
			if ( ! isset( $live[ $lid ] ) ) {
				$gone[] = '#' . $lid . ' (staging ' . $pid . ') no longer published on live';
			}
		}

		// Which live IDs for to-be-created posts are already used on staging (by any post type)?
		$blocked = array();
		$free    = 0;

		foreach ( array_chunk( array_keys( $create_ids ), 500 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE ID IN ($ph)", $chunk ) ); // phpcs:ignore WordPress.DB

			foreach ( $rows as $b ) {
				$blocked[ (int) $b->ID ] = '#' . $b->ID . ' is a staging ' . $b->post_type . ' "' . wp_html_excerpt( $b->post_title, 40 ) . '" (live post: ' . $create_ids[ (int) $b->ID ] . ')';
			}
		}

		$free = count( $create_ids ) - count( $blocked );

		set_transient( 'dw_sync_audit_detail', array( 'update' => $update, 'gone' => $gone, 'blocked' => array_values( $blocked ), 'diff_linked' => $diff_linked ), 600 );

		return sprintf(
			'Dry run (nothing changed): of %d live items, %d would be CREATED (not on staging), %d would be UPDATED (live edited after the staging copy), %d would only be LINKED (staging copy kept as is), %d already linked. %d linked posts are gone from live. VIEWS/IDs: of the %d to create, %d can keep the live ID and %d cannot (ID already used on staging). %d not-yet-linked matches have a different ID than live. Already-linked posts: %d of %d have a different staging ID than live (their old Jetpack views will not line up).',
			count( $live ),
			$c['create'],
			$c['update'],
			$c['link'],
			$c['already'],
			count( $gone ),
			count( $create_ids ),
			$free,
			count( $blocked ),
			$c['diffid'],
			$c['diffid_linked'],
			$c['already']
		);
	}
);

dw_sync_action(
	'dw_sync_reid_check',
	function () {
		$c = dw_sync_reid_candidates();

		set_transient(
			'dw_sync_audit_detail',
			array(
				'update'      => array(),
				'gone'        => array(),
				'blocked'     => array_map(
					function ( $b ) {
						return $b[1];
					},
					array_values( $c['blocked'] )
				),
				'diff_linked' => array(),
			),
			600
		);

		$edited = array();
		foreach ( $c['edited'] as $live => $loc ) {
			$edited[] = 'staging #' . $loc . ' (live #' . $live . ') "' . wp_html_excerpt( get_the_title( $loc ), 50 ) . '"';
		}

		return sprintf(
			'ID fix check (nothing changed): %d posts can be re-created with the live ID, %d are blocked (live ID used by something else on staging), %d were edited on staging since the sync (skipped so those edits are kept)%s.',
			count( $c['fixable'] ),
			count( $c['blocked'] ),
			count( $c['edited'] ),
			$edited ? ': ' . implode( '; ', $edited ) : ''
		);
	}
);

dw_sync_action(
	'dw_sync_reid_run',
	function () {
		$r = dw_sync_reid_run( 25, false );

		return sprintf( 'ID fix: %d re-created with the live ID, %d could not be (old copy kept), about %d left. Click again to continue until 0 are left.', $r['done'], $r['failed'], $r['left'] );
	}
);

/**
 * Buttons for one-off jobs that are finished (ID fix, ID block, full re-check, subscriber
 * clean-up). They still work; they are just not shown. Show them again with:
 *   add_filter( 'drwealth_sync_show_all_buttons', '__return_true' );
 */
function dw_sync_retired_actions() {
	return array(
		'dw_sync_preview',
		'dw_sync_reset',
		'dw_sync_reid_check',
		'dw_sync_reid_clear',
		'dw_sync_reid_run',
		'dw_sync_idblock_check',
		'dw_sync_idblock_reserve',
		'dw_sync_subs_check',
		'dw_sync_subs_inspect',
		'dw_sync_subs_delete',
		'dw_sync_audit',
		'dw_sync_tables_check',
		'dw_sync_tables_copy',
		'dw_sync_drafts_check',
		'dw_sync_drafts_compare',
		'dw_sync_drafts_import',
		'dw_sync_sitemap_now',
		'dw_sync_rollout_audit',
		'dw_sync_author_photos',
		'dw_sync_removed_check',
		'dw_sync_removed_apply',
	);
}

function dw_sync_button( $action, $label, $primary = false, $confirm = '' ) {
	if ( in_array( $action, dw_sync_retired_actions(), true ) && ! apply_filters( 'drwealth_sync_show_all_buttons', false ) ) {
		return;
	}

	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
		<?php wp_nonce_field( $action ); ?>
		<button class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

function dw_sync_render() {
	$s      = dw_sync_settings();
	$st     = dw_sync_state();
	$n      = DW_SYNC_OPTION;
	$log    = get_option( DW_SYNC_LOG, array() );
	$notice = get_transient( 'dw_sync_notice' );
	$next   = wp_next_scheduled( DW_SYNC_CRON );

	delete_transient( 'dw_sync_notice' );
	?>
	<div class="wrap">
		<h1>Live Sync</h1>
		<p>Copies new and changed posts from the live site into this site, one way (live to staging): content, SEO and other meta, categories and tags, authors, comments and images. Untouched posts are left alone. A post edited on <em>both</em> sides is skipped and logged, so staging work is never overwritten by accident. Needs the "DrWealth Live Export" plugin on live.</p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php
		$detail = get_transient( 'dw_sync_audit_detail' );

		if ( $detail && ( $detail['update'] || $detail['gone'] || ! empty( $detail['blocked'] ) || ! empty( $detail['diff_linked'] ) ) ) :
			?>
			<details open><summary><strong>Dry-run detail</strong></summary>
				<?php if ( $detail['update'] ) : ?>
					<p>Would be updated (first 60):<br /><?php echo esc_html( implode( '; ', array_slice( $detail['update'], 0, 60 ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $detail['diff_linked'] ) ) : ?>
					<p>Linked, but with a different ID than live (first 60 of <?php echo count( $detail['diff_linked'] ); ?>):<br /><?php echo esc_html( implode( '; ', array_slice( $detail['diff_linked'], 0, 60 ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $detail['blocked'] ) ) : ?>
					<p>Live IDs already used on staging (first 60 of <?php echo count( $detail['blocked'] ); ?>):<br /><?php echo esc_html( implode( '; ', array_slice( $detail['blocked'], 0, 60 ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( $detail['gone'] ) : ?>
					<p>Gone from live (first 40):<br /><?php echo esc_html( implode( '; ', array_slice( $detail['gone'], 0, 40 ) ) ); ?></p>
				<?php endif; ?>
			</details>
		<?php endif; ?>

		<h2>Status</h2>
		<?php
		$ago      = function ( $mysql_local ) {
			return $mysql_local ? human_time_diff( strtotime( get_gmt_from_date( $mysql_local ) . ' UTC' ), time() ) . ' ago' : 'never';
		};
		$running  = (bool) get_transient( 'dw_sync_lock' );
		$done     = $st['last_done'] && $st['last_done'] >= $st['last_run'];
		$stats    = wp_parse_args( $st['stats'], array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'linked' => 0, 'conflict' => 0, 'failed' => 0 ) );
		$state    = $running ? array( 'Running now', '#2271b1' ) : ( ! $st['last_run'] ? array( 'Not run yet', '#787c82' ) : ( $done ? array( 'Up to date', '#1a7f37' ) : array( 'Not finished, continues automatically', '#b35900' ) ) );
		$caught   = $st['cursor_mod'] ? wp_date( 'j M Y, g:i a', strtotime( $st['cursor_mod'] . ' UTC' ) ) : '-';
		$tiles    = array(
			array( 'Created', $stats['created'], '#1a7f37' ),
			array( 'Updated', $stats['updated'], '#2271b1' ),
			array( 'Linked', $stats['linked'], '#6b46c1' ),
			array( 'Unchanged', $stats['skipped'], '#787c82' ),
			array( 'Conflicts', $stats['conflict'], $stats['conflict'] ? '#b35900' : '#787c82' ),
			array( 'Failed', $stats['failed'], $stats['failed'] ? '#d63638' : '#787c82' ),
		);
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;max-width:900px;">
			<p style="margin:0 0 12px;font-size:14px;">
				<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:<?php echo esc_attr( $state[1] ); ?>;color:#fff;font-weight:600;"><?php echo esc_html( $state[0] ); ?></span>
				&nbsp; Last run <strong><?php echo esc_html( $ago( $st['last_run'] ) ); ?></strong>
				<?php echo $st['last_run'] ? '<span style="color:#787c82">(' . esc_html( $st['last_run'] ) . ')</span>' : ''; ?>
			</p>
			<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
				<?php foreach ( $tiles as $t ) : ?>
					<div style="flex:1;min-width:100px;border:1px solid #dcdcde;border-top:3px solid <?php echo esc_attr( $t[2] ); ?>;border-radius:6px;padding:8px 12px;text-align:center;">
						<div style="font-size:24px;font-weight:700;line-height:1.2;color:<?php echo esc_attr( $t[2] ); ?>;"><?php echo (int) $t[1]; ?></div>
						<div style="color:#50575e;"><?php echo esc_html( $t[0] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
			<p style="margin:0;color:#50575e;">Counts are from the last run only.</p>
			<table style="margin-top:10px;border-collapse:collapse;">
				<tr><td style="padding:2px 16px 2px 0;color:#50575e;">Synced up to live changes from</td><td><strong><?php echo esc_html( $caught ); ?></strong></td></tr>
				<tr><td style="padding:2px 16px 2px 0;color:#50575e;">Last fully caught up</td><td><strong><?php echo esc_html( $st['last_done'] ? $ago( $st['last_done'] ) : 'never' ); ?></strong></td></tr>
				<tr><td style="padding:2px 16px 2px 0;color:#50575e;">Next daily run</td><td><strong><?php echo esc_html( $next ? wp_date( 'D j M, g:i a', $next ) . ' (in ' . human_time_diff( time(), $next ) . ')' : ( $s['daily'] ? 'not scheduled (deactivate and re-activate the plugin)' : 'off' ) ); ?></strong></td></tr>
			</table>
		</div>
		<p>
			<?php
			dw_sync_button( 'dw_sync_test', 'Test connection' );
			dw_sync_button( 'dw_sync_now', 'Sync now', true );
			dw_sync_button( 'dw_sync_audit', 'Dry run (predict create / update / link, changes nothing)' );
			dw_sync_button( 'dw_sync_tables_check', 'TablePress: check' );
			dw_sync_button( 'dw_sync_tables_copy', 'TablePress: copy from live' );
			dw_sync_button( 'dw_sync_drafts_check', 'Live drafts: check' );
			dw_sync_button( 'dw_sync_drafts_compare', 'Drafts: compare live vs staging' );
			dw_sync_button( 'dw_sync_drafts_import', 'Live drafts: bring over' );
			dw_sync_button( 'dw_sync_preview', 'Full re-check: preview' );
			dw_sync_button( 'dw_sync_reset', 'Full re-check', false, 'Re-check every post from the start? Unchanged ones are skipped.' );
			dw_sync_button( 'dw_sync_reid_check', 'ID fix: check' );
			dw_sync_button( 'dw_sync_reid_clear', 'ID fix: free revisions/trash/sitemap', false, 'PERMANENTLY delete old revisions, already-trashed posts and Jetpack cached sitemap files that are holding live IDs? (Jetpack rebuilds the sitemaps.) Orders, memberships, images and everything else are left alone.' );
			dw_sync_button( 'dw_sync_reid_run', 'ID fix: run', false, 'Re-create the posts whose ID differs from live, using the live ID? Each old copy is only deleted after its replacement is created with the right ID (otherwise it is restored). Batches of ~25 seconds.' );
			dw_sync_button( 'dw_sync_idblock_check', 'ID block: check' );
			dw_sync_button( 'dw_sync_idblock_reserve', 'ID block: reserve (before Substack import)', false, 'Move staging\'s ID counter up to 147,000,000 by creating one hidden placeholder post? Everything the Substack import creates will then get IDs above anything live will use. Nothing existing changes.' );
			dw_sync_button( 'dw_sync_sitemap_now', 'Sitemap: rebuild now' );
			dw_sync_button( 'dw_sync_rollout_audit', 'Roll-out audit' );
			dw_sync_button( 'dw_sync_author_photos', 'Sync author profiles' );
			dw_sync_button( 'dw_sync_removed_check', 'Removed on live: check' );
			dw_sync_button( 'dw_sync_removed_apply', 'Removed on live: move to Trash', false, 'Move staging posts to Trash when they are trashed/deleted on live?' );
			dw_sync_button( 'dw_sync_subs_check', 'Subscribers: check' );
			dw_sync_button( 'dw_sync_subs_inspect', 'Subscribers: inspect' );
			dw_sync_button( 'dw_sync_subs_delete', 'Subscribers: DELETE imported', false, 'PERMANENTLY delete all Substack-imported subscribers on THIS site, with their memberships, subscriptions and orders? Admins/editors/authors are kept. This cannot be undone.' );
			?>
		</p>

		<h2>Settings</h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'drwealth_live_sync' ); ?>
			<table class="form-table">
				<tr><th>Live site URL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $n ); ?>[url]" value="<?php echo esc_attr( $s['url'] ); ?>" /></td></tr>
				<tr><th>Token</th><td><input type="password" class="large-text" autocomplete="off" name="<?php echo esc_attr( $n ); ?>[token]" placeholder="<?php echo $s['token'] ? 'saved (leave blank to keep)' : 'paste from live: Tools > Live Export'; ?>" /></td></tr>
				<tr><th>Post types</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[types]" value="<?php echo esc_attr( $s['types'] ); ?>" /> <span class="description">comma separated, e.g. <code>post</code> or <code>post,page</code></span></td></tr>
				<tr><th>Only posts published since</th><td><input type="date" name="<?php echo esc_attr( $n ); ?>[since]" value="<?php echo esc_attr( $s['since'] ); ?>" /> <span class="description">optional (blank = all)</span></td></tr>
				<tr><th>Drafts edited since</th><td><input type="date" name="<?php echo esc_attr( $n ); ?>[drafts_since]" value="<?php echo esc_attr( $s['drafts_since'] ); ?>" /> <span class="description">live drafts last edited before this date are not brought over (blank = all)</span></td></tr>
				<tr><th>TablePress tables</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[tables]" value="<?php echo esc_attr( $s['tables'] ); ?>" /> <span class="description">table IDs kept in sync from live, e.g. <code>13,86,95</code>. Only existing staging tables are updated; blank = off</span></td></tr>
				<tr><th>Daily sync</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[daily]" value="1" <?php checked( $s['daily'] ); ?> /> Run once a day</label></td></tr>
				<tr><th>Removed on live</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[mirror_removed]" value="1" <?php checked( $s['mirror_removed'] ); ?> /> Move posts to Trash here when they are trashed or deleted on live (recoverable)</label></td></tr>
				<tr><th>If edited on staging too</th><td>
					<select name="<?php echo esc_attr( $n ); ?>[conflict]">
						<option value="skip" <?php selected( $s['conflict'], 'skip' ); ?>>Keep staging's version and log it (recommended)</option>
						<option value="overwrite" <?php selected( $s['conflict'], 'overwrite' ); ?>>Overwrite with live's version</option>
					</select>
				</td></tr>
				<tr><th>Category slug map</th><td><textarea class="large-text code" rows="3" name="<?php echo esc_attr( $n ); ?>[category_map]" placeholder="live-slug=staging-slug"><?php echo esc_textarea( $s['category_map'] ); ?></textarea><p class="description">One per line. E.g. <code>premium-membership=premium</code> if live still has a category staging merged.</p></td></tr>
				<tr><th>Remove from content</th><td><textarea class="large-text code" rows="4" name="<?php echo esc_attr( $n ); ?>[strip]"><?php echo esc_textarea( $s['strip'] ); ?></textarea><p class="description">One regex per line, removed from imported content. The default removes beehiiv subscribe boxes (already stripped on staging, so they don't come back).</p></td></tr>
				<tr><th>Force these live IDs</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[force_ids]" value="<?php echo esc_attr( $s['force_ids'] ); ?>" placeholder="114216,114872" /> <span class="description">Re-imported on the next run even if staging has them (cleared afterwards).</span></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Log</h2>
		<table class="widefat striped">
			<thead><tr><th>Time</th><th>Level</th><th>Live ID</th><th>Staging ID</th><th>Message</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $log, 0, 120 ) as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row[0] ); ?></td>
					<td><?php echo esc_html( $row[1] ); ?></td>
					<td><?php echo $row[2] ? (int) $row[2] : ''; ?></td>
					<td><?php echo $row[3] ? '<a href="' . esc_url( get_edit_post_link( $row[3] ) ) . '">' . (int) $row[3] . '</a>' : ''; ?></td>
					<td><?php echo esc_html( $row[4] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/* ------------------------------------------------- Find the posts we touched */

// Posts list: filter by what the sync did (created / updated / linked / any).
add_action(
	'restrict_manage_posts',
	function ( $post_type ) {
		if ( 'attachment' === $post_type ) {
			return;
		}

		$cur = isset( $_GET['dw_sync'] ) ? sanitize_key( wp_unslash( $_GET['dw_sync'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<select name="dw_sync">
			<option value="">Live Sync: all</option>
			<option value="any" <?php selected( $cur, 'any' ); ?>>Touched by Live Sync</option>
			<option value="created" <?php selected( $cur, 'created' ); ?>>Created by Live Sync</option>
			<option value="updated" <?php selected( $cur, 'updated' ); ?>>Updated by Live Sync</option>
			<option value="linked" <?php selected( $cur, 'linked' ); ?>>Linked only (not changed)</option>
		</select>
		<?php
	}
);

add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || empty( $_GET['dw_sync'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$v    = sanitize_key( wp_unslash( $_GET['dw_sync'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$meta = (array) $q->get( 'meta_query' );

		$meta[] = 'any' === $v
			? array( 'key' => '_dw_live_id', 'compare' => 'EXISTS' )
			: array( 'key' => '_dw_synced_action', 'value' => $v );

		$q->set( 'meta_query', $meta );
		$q->set( 'orderby', 'meta_value' === $q->get( 'orderby' ) ? 'date' : $q->get( 'orderby' ) );
	}
);

// A "Live" column: live ID + what happened + when.
add_filter(
	'manage_posts_columns',
	function ( $cols ) {
		$cols['dw_live'] = 'Live Sync';

		return $cols;
	}
);

add_action(
	'manage_posts_custom_column',
	function ( $col, $post_id ) {
		if ( 'dw_live' !== $col ) {
			return;
		}

		$lid = get_post_meta( $post_id, '_dw_live_id', true );

		if ( ! $lid ) {
			echo '&ndash;';

			return;
		}

		$url = get_post_meta( $post_id, '_dw_live_url', true );

		$kept = get_post_meta( $post_id, '_dw_id_kept', true );

		echo '#' . (int) $lid . ' ' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">live</a>' : '' ) . '<br /><small>' . esc_html( get_post_meta( $post_id, '_dw_synced_action', true ) . ' ' . get_post_meta( $post_id, '_dw_synced_at', true ) ) . '</small>'
			. ( 'yes' === $kept ? '<br /><small style="color:#1a7f37">same ID as live</small>' : ( 'no' === $kept ? '<br /><small style="color:#b35900">different ID than live</small>' : '' ) );
	},
	10,
	2
);
