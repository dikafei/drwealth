<?php
/**
 * Tools > Force-public posts
 *
 * WooCommerce Memberships can flag an individual post as "force public" (readable by
 * everyone, ignoring membership rules). The old "3 free articles per month" snippet
 * flagged posts this way whenever a free member opened one, and the flag stays on the
 * post for EVERY visitor afterwards. This page lists flagged posts and unflags them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<int, array{post_id:int, key:string, value:string}> */
function drwealth_force_public_rows() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_key, pm.meta_value
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key LIKE '%wc_memberships%force_public%' AND p.post_type = 'post'
		 ORDER BY pm.post_id DESC",
		ARRAY_A
	);

	return $rows ? $rows : array();
}

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Force-public posts', 'Force-public posts', 'manage_options', 'drwealth-force-public', 'drwealth_force_public_render' );
	}
);

add_action(
	'admin_post_drwealth_unset_force_public',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'drwealth_unset_force_public' );

		$ids  = isset( $_POST['post_ids'] ) ? array_map( 'intval', (array) $_POST['post_ids'] ) : array();
		$done = 0;

		foreach ( drwealth_force_public_rows() as $row ) {
			if ( in_array( (int) $row['post_id'], $ids, true ) ) {
				delete_post_meta( (int) $row['post_id'], $row['meta_key'] );
				$done++;
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'drwealth-force-public', 'done' => $done ), admin_url( 'tools.php' ) ) );
		exit;
	}
);

function drwealth_force_public_render() {
	$rows = drwealth_force_public_rows();
	?>
	<div class="wrap">
		<h1>Force-public posts</h1>
		<?php if ( isset( $_GET['done'] ) ) : ?>
			<div class="notice notice-success"><p><?php echo (int) $_GET['done']; ?> post(s) unflagged.</p></div>
		<?php endif; ?>
		<p>Posts WooCommerce Memberships is treating as open to everyone, ignoring membership rules. If a Premium post is listed here by accident, tick it and unflag it so the paywall applies again.</p>
		<?php if ( ! $rows ) : ?>
			<p><strong>No posts are flagged.</strong></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'drwealth_unset_force_public' ); ?>
				<input type="hidden" name="action" value="drwealth_unset_force_public" />
				<table class="widefat striped" style="max-width:900px;">
					<thead><tr><th style="width:30px;"></th><th>Post</th><th>Categories</th><th>Flag</th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><input type="checkbox" name="post_ids[]" value="<?php echo (int) $row['post_id']; ?>" <?php checked( has_category( 'premium', (int) $row['post_id'] ) ); ?> /></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $row['post_id'] ) ); ?></a></td>
							<td><?php echo esc_html( implode( ', ', wp_get_post_categories( (int) $row['post_id'], array( 'fields' => 'names' ) ) ) ); ?></td>
							<td><code><?php echo esc_html( $row['meta_key'] . ' = ' . $row['meta_value'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Premium-category posts are pre-ticked.</p>
				<?php submit_button( 'Unflag selected' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
