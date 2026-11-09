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
						'since'        => ( isset( $in['since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $in['since'] ) ) ? $in['since'] : '',
						'daily'        => empty( $in['daily'] ) ? 0 : 1,
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
			if ( in_array( $row['status'], array( 'publish', 'future', 'private' ), true ) ) {
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

		$c      = array( 'create' => 0, 'link' => 0, 'update' => 0, 'already' => 0 );
		$update = array();
		$gone   = array();

		foreach ( $live as $id => $row ) {
			if ( $since && $row['modified'] < $since ) {
				continue;
			}

			if ( isset( $linked[ $id ] ) ) {
				$c['already']++;
				continue;
			}

			$key = $row['type'] . '|' . $row['slug'];

			if ( ! isset( $local[ $key ] ) ) {
				$c['create']++;
			} elseif ( $row['modified'] <= $local[ $key ]->post_modified_gmt ) {
				$c['link']++; // Staging copy not older: just linked, not changed.
			} else {
				$c['update']++;
				$update[] = '#' . $id . ' ' . $row['slug'] . ' (live ' . $row['modified'] . ' vs staging ' . $local[ $key ]->post_modified_gmt . ')';
			}
		}

		foreach ( $linked as $lid => $pid ) {
			if ( ! isset( $live[ $lid ] ) ) {
				$gone[] = '#' . $lid . ' (staging ' . $pid . ') no longer published on live';
			}
		}

		set_transient( 'dw_sync_audit_detail', array( 'update' => $update, 'gone' => $gone ), 600 );

		return sprintf(
			'Dry run (nothing changed): of %d live items, %d would be CREATED (not on staging), %d would be UPDATED (live edited after the staging copy), %d would only be LINKED (staging copy kept as is), %d already linked. %d linked posts are gone from live.',
			count( $live ),
			$c['create'],
			$c['update'],
			$c['link'],
			$c['already'],
			count( $gone )
		);
	}
);

function dw_sync_button( $action, $label, $primary = false, $confirm = '' ) {
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

		if ( $detail && ( $detail['update'] || $detail['gone'] ) ) :
			?>
			<details open><summary><strong>Dry-run detail</strong></summary>
				<?php if ( $detail['update'] ) : ?>
					<p>Would be updated (first 60):<br /><?php echo esc_html( implode( '; ', array_slice( $detail['update'], 0, 60 ) ) ); ?></p>
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
			dw_sync_button( 'dw_sync_reset', 'Full re-check', false, 'Re-check every post from the start? Unchanged ones are skipped.' );
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
				<tr><th>Daily sync</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[daily]" value="1" <?php checked( $s['daily'] ); ?> /> Run once a day</label></td></tr>
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

		echo '#' . (int) $lid . ' ' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">live</a>' : '' ) . '<br /><small>' . esc_html( get_post_meta( $post_id, '_dw_synced_action', true ) . ' ' . get_post_meta( $post_id, '_dw_synced_at', true ) ) . '</small>';
	},
	10,
	2
);
