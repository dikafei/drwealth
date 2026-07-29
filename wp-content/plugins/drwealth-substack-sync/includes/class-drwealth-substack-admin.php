<?php
/**
 * Admin UI: Tools > Substack Sync, with two independent tabs — Posts and
 * Subscribers — each with its own upload form, its own admin-post handler,
 * and its own result notice, so the two imports can be run separately.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DrWealth_Substack_Admin {

	const NONCE_ACTION_POSTS       = 'drwealth_substack_import_posts';
	const NONCE_ACTION_SUBSCRIBERS = 'drwealth_substack_import_subscribers';

	const TRANSIENT_POSTS       = 'drwealth_substack_import_posts_result';
	const TRANSIENT_SUBSCRIBERS = 'drwealth_substack_import_subscribers_result';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_drwealth_substack_import_posts', array( __CLASS__, 'handle_import_posts' ) );
		add_action( 'admin_post_drwealth_substack_import_subscribers', array( __CLASS__, 'handle_import_subscribers' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
	}

	public static function add_menu() {
		add_management_page(
			'Substack Sync',
			'Substack Sync',
			'manage_options',
			'drwealth-substack-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = ( isset( $_GET['tab'] ) && 'subscribers' === $_GET['tab'] ) ? 'subscribers' : 'posts';
		$base_url = admin_url( 'tools.php?page=drwealth-substack-sync' );
		?>
		<div class="wrap">
			<h1>Substack Sync</h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'posts', $base_url ) ); ?>" class="nav-tab <?php echo 'posts' === $tab ? 'nav-tab-active' : ''; ?>">Posts</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'subscribers', $base_url ) ); ?>" class="nav-tab <?php echo 'subscribers' === $tab ? 'nav-tab-active' : ''; ?>">Subscribers</a>
			</h2>

			<?php if ( 'subscribers' === $tab ) : ?>
				<?php self::render_subscribers_tab(); ?>
			<?php else : ?>
				<?php self::render_posts_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function render_result_notice( $result ) {
		if ( ! $result ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo empty( $result['errors'] ) ? 'success' : 'error'; ?> is-dismissible">
			<p>
				<strong>Import finished.</strong>
				Created: <?php echo (int) $result['created']; ?>,
				Updated: <?php echo (int) $result['updated']; ?>,
				Skipped: <?php echo count( $result['skipped'] ); ?>,
				Errors: <?php echo count( $result['errors'] ); ?>
			</p>
			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<ul>
					<?php foreach ( $result['errors'] as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function render_publication_options() {
		foreach ( DrWealth_Substack_Importer::PUBLICATIONS as $key => $data ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $data['label'] ) );
		}
	}

	protected static function render_posts_tab() {
		$result = get_transient( self::TRANSIENT_POSTS );
		delete_transient( self::TRANSIENT_POSTS );
		self::render_result_notice( $result );
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION_POSTS ); ?>
			<input type="hidden" name="action" value="drwealth_substack_import_posts" />

			<table class="form-table">
				<tr>
					<th><label for="drwealth_publication">Publication</label></th>
					<td>
						<select name="publication" id="drwealth_publication" required>
							<?php self::render_publication_options(); ?>
						</select>
						<p class="description">Which Substack this export is from. Determines the market category (United States / China).</p>
					</td>
				</tr>
				<tr>
					<th><label for="drwealth_zip">Substack export (.zip)</label></th>
					<td><input type="file" name="substack_zip" id="drwealth_zip" accept=".zip" required /></td>
				</tr>
				<tr>
					<th><label for="drwealth_limit">Number of posts to import</label></th>
					<td>
						<input type="number" name="limit" id="drwealth_limit" value="10" min="1" max="500" />
						<p class="description">Most recent published posts, by date. Re-importing the same posts overwrites them instead of duplicating them.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Import Posts' ); ?>
		</form>

		<h2>Imported Substack posts</h2>
		<?php self::render_imported_posts_table(); ?>
		<?php
	}

	protected static function render_subscribers_tab() {
		$result = get_transient( self::TRANSIENT_SUBSCRIBERS );
		delete_transient( self::TRANSIENT_SUBSCRIBERS );
		self::render_result_notice( $result );
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION_SUBSCRIBERS ); ?>
			<input type="hidden" name="action" value="drwealth_substack_import_subscribers" />

			<table class="form-table">
				<tr>
					<th><label for="drwealth_sub_zip">Substack export (.zip)</label></th>
					<td>
						<input type="file" name="substack_zip" id="drwealth_sub_zip" accept=".zip" required />
						<p class="description">
							No publication picker needed — both Substacks feed into this one site, so the publication is detected automatically
							from the export's <code>email_list.&lt;publication&gt;.csv</code> filename and kept only as a historical label.
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="drwealth_sub_limit">Number of subscribers to import</label></th>
					<td>
						<input type="number" name="limit" id="drwealth_sub_limit" value="10" min="1" max="1000" />
						<p class="description">
							Most recently created subscribers first. Existing WordPress users (matched by email) are updated, not duplicated.
							Membership is Free unless there's an active subscription, in which case it's Premium (or Premium Lifetime for
							<code>plan == lifetime</code>). If the same person exists under both publications, they get whichever plan ranks highest.
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Import Subscribers' ); ?>
		</form>

		<h2>Imported Substack subscribers</h2>
		<?php self::render_imported_subscribers_table(); ?>
		<?php
	}

	protected static function render_imported_posts_table() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => DrWealth_Substack_Importer::META_SUBSTACK_ID,
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! $posts ) {
			echo '<p>No Substack posts imported yet.</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Title</th>
					<th>Publication</th>
					<th>Audience</th>
					<th>Categories</th>
					<th>Date</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $posts as $post ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
						<td><?php echo esc_html( get_post_meta( $post->ID, DrWealth_Substack_Importer::META_PUBLICATION, true ) ); ?></td>
						<td><?php echo esc_html( get_post_meta( $post->ID, DrWealth_Substack_Importer::META_AUDIENCE, true ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) ) ); ?></td>
						<td><?php echo esc_html( get_the_date( '', $post ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	protected static function render_imported_subscribers_table() {
		$users = get_users(
			array(
				'meta_key' => DrWealth_Substack_Subscriber_Importer::META_PUBLICATIONS,
				'number'   => 20,
				'orderby'  => 'registered',
				'order'    => 'DESC',
			)
		);

		if ( ! $users ) {
			echo '<p>No subscribers imported yet.</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Email</th>
					<th>Publication(s)</th>
					<th>Plan</th>
					<th>Active</th>
					<th>Combined membership</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $users as $user ) :
					$publications = DrWealth_Substack_Subscriber_Importer::get_tracked_publications( $user->ID );

					$plans   = array();
					$actives = array();

					foreach ( $publications as $pub ) {
						$plans[]   = get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_PLAN . '_' . $pub, true );
						$actives[] = get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_ACTIVE . '_' . $pub, true );
					}
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', $publications ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', $plans ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', $actives ) ); ?></td>
						<td><?php echo esc_html( DrWealth_Substack_Subscriber_Importer::determine_effective_membership( $user->ID ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Admin-only, read-only section on the WordPress user profile screen
	 * showing everything imported from Substack for this user, per
	 * publication. Historical reference only — nothing here is editable.
	 */
	public static function render_user_profile_fields( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$publications = DrWealth_Substack_Subscriber_Importer::get_tracked_publications( $user->ID );

		if ( ! $publications ) {
			return;
		}
		?>
		<h2>Substack Sync (admin only)</h2>
		<p class="description">Historical import data from Substack — read-only, only visible to admins.</p>
		<table class="form-table">
			<tr>
				<th>Combined membership</th>
				<td><strong><?php echo esc_html( DrWealth_Substack_Subscriber_Importer::determine_effective_membership( $user->ID ) ); ?></strong></td>
			</tr>
			<?php foreach ( $publications as $pub ) :
				$label = isset( DrWealth_Substack_Importer::PUBLICATIONS[ $pub ] ) ? DrWealth_Substack_Importer::PUBLICATIONS[ $pub ]['label'] : $pub;
				?>
				<tr>
					<th><?php echo esc_html( $label ); ?></th>
					<td>
						Plan: <?php echo esc_html( get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_PLAN . '_' . $pub, true ) ); ?><br />
						Active subscription: <?php echo esc_html( get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_ACTIVE . '_' . $pub, true ) ); ?><br />
						Expiry: <?php echo esc_html( get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_EXPIRY . '_' . $pub, true ) ); ?><br />
						Created: <?php echo esc_html( get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_CREATED_AT . '_' . $pub, true ) ); ?><br />
						This publication's own tier: <?php echo esc_html( get_user_meta( $user->ID, DrWealth_Substack_Subscriber_Importer::META_MEMBERSHIP . '_' . $pub, true ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	public static function handle_import_posts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_ACTION_POSTS );

		$publication = isset( $_POST['publication'] ) ? sanitize_key( wp_unslash( $_POST['publication'] ) ) : '';
		$limit       = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10;

		$uploaded = self::handle_upload( 'substack_zip' );

		if ( is_wp_error( $uploaded ) ) {
			self::redirect_with_result(
				self::TRANSIENT_POSTS,
				'tab=posts',
				array(
					'created' => 0,
					'updated' => 0,
					'skipped' => array(),
					'errors'  => array( $uploaded->get_error_message() ),
				)
			);
		}

		$result = DrWealth_Substack_Importer::import_zip( $uploaded, $publication, $limit );

		if ( file_exists( $uploaded ) ) {
			wp_delete_file( $uploaded );
		}

		self::redirect_with_result( self::TRANSIENT_POSTS, 'tab=posts', $result );
	}

	public static function handle_import_subscribers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_ACTION_SUBSCRIBERS );

		$limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10;

		$uploaded = self::handle_upload( 'substack_zip' );

		if ( is_wp_error( $uploaded ) ) {
			self::redirect_with_result(
				self::TRANSIENT_SUBSCRIBERS,
				'tab=subscribers',
				array(
					'created' => 0,
					'updated' => 0,
					'skipped' => array(),
					'errors'  => array( $uploaded->get_error_message() ),
				)
			);
		}

		$result = DrWealth_Substack_Subscriber_Importer::import_zip( $uploaded, $limit );

		if ( file_exists( $uploaded ) ) {
			wp_delete_file( $uploaded );
		}

		self::redirect_with_result( self::TRANSIENT_SUBSCRIBERS, 'tab=subscribers', $result );
	}

	/**
	 * Handle the uploaded zip file, shared by both import forms.
	 *
	 * @return string|WP_Error Absolute path to the uploaded file, or a WP_Error.
	 */
	protected static function handle_upload( $field ) {
		if ( empty( $_FILES[ $field ]['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No file was uploaded.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => array( 'zip' => 'application/zip' ),
		);

		$uploaded = wp_handle_upload( $_FILES[ $field ], $overrides );

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'upload_failed', $uploaded['error'] );
		}

		return $uploaded['file'];
	}

	protected static function redirect_with_result( $transient_key, $query_tab, $result ) {
		set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&' . $query_tab ) );
		exit;
	}
}
