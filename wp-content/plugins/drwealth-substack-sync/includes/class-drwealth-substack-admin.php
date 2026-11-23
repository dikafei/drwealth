<?php
/**
 * Admin UI: Tools > Substack Sync, with three independent tabs — Posts,
 * Subscribers, and Welcome Email — each with its own form(s), its own
 * admin-post handler(s), and its own result notice, so imports, welcome
 * emails, and the email template can all be managed separately.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DrWealth_Substack_Admin {

	const NONCE_ACTION_POSTS           = 'drwealth_substack_import_posts';
	const NONCE_ACTION_SUBSCRIBERS     = 'drwealth_substack_import_subscribers';
	const NONCE_ACTION_EMAIL_SETTINGS  = 'drwealth_substack_save_email_settings';
	const NONCE_ACTION_SEND_EMAIL      = 'drwealth_substack_send_welcome_email';
	const NONCE_ACTION_SEND_BATCH      = 'drwealth_substack_send_welcome_batch';

	const TRANSIENT_POSTS          = 'drwealth_substack_import_posts_result';
	const TRANSIENT_SUBSCRIBERS    = 'drwealth_substack_import_subscribers_result';
	const TRANSIENT_EMAIL_SETTINGS = 'drwealth_substack_email_settings_result';
	const TRANSIENT_EMAIL_SEND     = 'drwealth_substack_email_send_result';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_drwealth_substack_import_posts', array( __CLASS__, 'handle_import_posts' ) );
		add_action( 'admin_post_drwealth_substack_import_subscribers', array( __CLASS__, 'handle_import_subscribers' ) );
		add_action( 'admin_post_drwealth_send_test', array( __CLASS__, 'handle_send_test' ) );
		add_action( 'admin_post_drwealth_substack_save_email_settings', array( __CLASS__, 'handle_save_email_settings' ) );
		add_action( 'admin_post_drwealth_substack_send_welcome_email', array( __CLASS__, 'handle_send_welcome_email' ) );
		add_action( 'admin_post_drwealth_substack_send_welcome_batch', array( __CLASS__, 'handle_send_welcome_batch' ) );
		add_action( 'admin_post_drwealth_substack_leftovers', array( __CLASS__, 'handle_leftovers' ) );
		add_action( 'admin_post_drwealth_substack_undo_run', array( __CLASS__, 'handle_undo_run' ) );
		add_action( 'wp_ajax_drwealth_substack_import_one', array( __CLASS__, 'handle_ajax_import_one' ) );
		add_action( 'admin_post_drwealth_substack_duplicates', array( __CLASS__, 'handle_duplicates' ) );
		add_action( 'admin_post_drwealth_substack_reclean', array( __CLASS__, 'handle_reclean' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_profile_fields' ) );
	}

	public static function add_menu() {
		add_management_page(
			'Member Tools',
			'Member Tools',
			'manage_options',
			'drwealth-substack-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$allowed_tabs = array( 'posts', 'subscribers', 'email', 'site-emails', 'sender' );
		$tab          = ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], $allowed_tabs, true ) ) ? $_GET['tab'] : 'posts';
		$base_url     = admin_url( 'tools.php?page=drwealth-substack-sync' );
		?>
		<div class="wrap">
			<h1>Member Tools</h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'posts', $base_url ) ); ?>" class="nav-tab <?php echo 'posts' === $tab ? 'nav-tab-active' : ''; ?>">Posts</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'subscribers', $base_url ) ); ?>" class="nav-tab <?php echo 'subscribers' === $tab ? 'nav-tab-active' : ''; ?>">Subscribers</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'email', $base_url ) ); ?>" class="nav-tab <?php echo 'email' === $tab ? 'nav-tab-active' : ''; ?>">Migration Email</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'site-emails', $base_url ) ); ?>" class="nav-tab <?php echo 'site-emails' === $tab ? 'nav-tab-active' : ''; ?>">Site Emails</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'sender', $base_url ) ); ?>" class="nav-tab <?php echo 'sender' === $tab ? 'nav-tab-active' : ''; ?>">Sender</a>
			</h2>

			<?php if ( 'subscribers' === $tab ) : ?>
				<?php self::render_subscribers_tab(); ?>
			<?php elseif ( 'email' === $tab ) : ?>
				<?php self::render_email_tab(); ?>
			<?php elseif ( 'site-emails' === $tab ) : ?>
				<?php
				$notice = get_transient( self::TRANSIENT_EMAIL_SETTINGS );
				delete_transient( self::TRANSIENT_EMAIL_SETTINGS );
				self::render_simple_notice( $notice );
				?>
				<?php drwealth_emails_render_settings(); ?>
			<?php elseif ( 'sender' === $tab ) : ?>
				<?php drwealth_emails_render_sender(); ?>
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
				<?php if ( isset( $result['matched'] ) ) : ?>
					Already on the site (left as is): <?php echo count( $result['matched'] ); ?>,
				<?php endif; ?>
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

	protected static function render_simple_notice( $result ) {
		if ( ! $result ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo empty( $result['is_error'] ) ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $result['message'] ); ?></p>
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
					<th><label for="drwealth_author">Author</label></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'             => 'author_id',
								'id'               => 'drwealth_author',
								'selected'         => (int) get_option( 'drwealth_substack_author_id', 0 ),
								'show_option_none' => '— Select author —',
								'option_none_value' => 0,
								'capability'       => array( 'edit_posts' ),
							)
						);
						?>
						<p class="description">The Substack export has no author field, so every imported post is assigned to this user. Re-importing overwrites the author too.</p>
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
						<p class="description">First N published posts in the file, top to bottom. A post that is already on the site (same Substack ID or same slug) is never duplicated and keeps its categories.</p>
					</td>
				</tr>
				<tr>
					<th>Posts already on the site</th>
					<td>
						<label><input type="checkbox" name="overwrite_existing" value="1" /> Also overwrite the title, content and date of posts that are already on the site</label>
						<p class="description">Off by default: those posts are only linked to their Substack ID and left untouched. Categories are never changed on existing posts.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Import Posts' ); ?>
		</form>

		<?php self::render_batch_box(); ?>

		<?php self::render_runs_box(); ?>

		<?php self::render_leftovers_box(); ?>
		<?php self::render_reclean_box(); ?>

		<h2>Imported Substack posts</h2>
		<?php self::render_imported_posts_table(); ?>
		<?php
	}

	protected static function render_subscribers_tab() {
		$import_result = get_transient( self::TRANSIENT_SUBSCRIBERS );
		delete_transient( self::TRANSIENT_SUBSCRIBERS );
		self::render_result_notice( $import_result );

		$email_result = get_transient( self::TRANSIENT_EMAIL_SEND );
		delete_transient( self::TRANSIENT_EMAIL_SEND );
		self::render_simple_notice( $email_result );
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
							Import creates the user and saves the Substack data only. <strong>No membership is created here.</strong>
							Import both publications' files first; the membership is created later, together with the email (see below).
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Import Subscribers' ); ?>
		</form>

		<h2>Activate memberships + send emails</h2>
		<p>
			<?php echo (int) DrWealth_Substack_Mailer::count_pending(); ?> imported subscriber(s) haven't been activated and emailed yet.
			Each run, for every person, in one step: add up the remaining time from both newsletters (counted from today, rounded up to the
			end of the month), create their membership with that end date, and send the migration email. Lifetime plans get no end date.
			Importing never does any of this, and each subscriber is only processed once this way (the per-row "Resend" button re-sends the
			email only, with the same end date).
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION_SEND_BATCH ); ?>
			<input type="hidden" name="action" value="drwealth_substack_send_welcome_batch" />
			<label>
				Emails to send this run:
				<input type="number" name="batch_limit" value="20" min="1" max="500" />
			</label>
			<?php submit_button( 'Activate + email batch', 'secondary', 'submit', false ); ?>
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
					<th>Membership</th>
					<th>Activation + email</th>
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

					$emailed_at = DrWealth_Substack_Mailer::get_emailed_at( $user->ID );
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', $publications ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', $plans ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', $actives ) ); ?></td>
						<td>
							<?php
							$activation = DrWealth_Substack_Subscriber_Importer::get_activation( $user->ID );

							if ( $activation ) {
								echo esc_html( $activation['slug'] . ( $activation['end'] ? ' until ' . mysql2date( get_option( 'date_format' ), $activation['end'] ) : '' ) );
							} else {
								$preview = DrWealth_Substack_Subscriber_Importer::plan_activation( $user->ID );
								echo '<em>Not activated.</em> Would get: ' . esc_html( $preview['slug'] . ( $preview['end'] ? ' until ' . mysql2date( get_option( 'date_format' ), $preview['end'] ) : '' ) );
							}
							?>
						</td>
						<td>
							<?php if ( $emailed_at ) : ?>
								Sent <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $emailed_at ) ); ?><br />
							<?php else : ?>
								<em>Not sent</em><br />
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( DrWealth_Substack_Admin::NONCE_ACTION_SEND_EMAIL ); ?>
								<input type="hidden" name="action" value="drwealth_substack_send_welcome_email" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
								<button type="submit" class="button button-small"><?php echo $emailed_at ? 'Resend' : 'Activate + email'; ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	protected static function render_email_tab() {
		$result = get_transient( self::TRANSIENT_EMAIL_SETTINGS );
		delete_transient( self::TRANSIENT_EMAIL_SETTINGS );
		self::render_simple_notice( $result );

		$variants = array(
			DrWealth_Substack_Mailer::VARIANT_FREE => 'Free members email',
			DrWealth_Substack_Mailer::VARIANT_PAID => 'Paid members email',
		);
		?>
		<p class="description">
			The migration email — sent from the Subscribers tab, either one at a time or in a batch, never automatically on import.
			Each person gets the <strong>Free</strong> or <strong>Paid</strong> version depending on the plan they end up on.
			Placeholders: <code>{first_name}</code>, <code>{display_name}</code>, <code>{email}</code>,
			<code>{plan}</code>, <code>{expiry}</code>, <code>{reset_link}</code>, <code>{login_url}</code>,
			<code>{subscription_url}</code>, <code>{sender_email}</code>, <code>{free_unlocks}</code>, <code>{unlock_period}</code>, <code>{site_name}</code>.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION_EMAIL_SETTINGS ); ?>
			<input type="hidden" name="action" value="drwealth_substack_save_email_settings" />

			<?php foreach ( $variants as $variant => $heading ) : ?>
				<h2><?php echo esc_html( $heading ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="drwealth_email_subject_<?php echo esc_attr( $variant ); ?>">Subject</label></th>
						<td><input type="text" class="large-text" name="subject_<?php echo esc_attr( $variant ); ?>" id="drwealth_email_subject_<?php echo esc_attr( $variant ); ?>" value="<?php echo esc_attr( DrWealth_Substack_Mailer::get_subject( $variant ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label>Body</label></th>
						<td>
							<?php
							wp_editor(
								DrWealth_Substack_Mailer::get_body( $variant ),
								'drwealth_email_body_' . $variant,
								array(
									'textarea_name' => 'body_' . $variant,
									'textarea_rows' => 16,
									'media_buttons' => false,
								)
							);
							?>
						</td>
					</tr>
				</table>
			<?php endforeach; ?>

			<?php submit_button( 'Save Email Templates' ); ?>
		</form>
		<?php drwealth_emails_render_test_form( array( 'migration_free' => 'Send test: Free email', 'migration_paid' => 'Send test: Paid email' ), 'email' ); ?>
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
		$author_id   = isset( $_POST['author_id'] ) ? (int) $_POST['author_id'] : 0;

		// Remember the choice for next time.
		if ( $author_id > 0 ) {
			update_option( 'drwealth_substack_author_id', $author_id );
		}

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

		$overwrite = ! empty( $_POST['overwrite_existing'] );
		$result    = DrWealth_Substack_Importer::import_zip( $uploaded, $publication, $limit, $author_id, $overwrite );

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

	public static function handle_send_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'drwealth_send_test' );

		$type = isset( $_POST['test_type'] ) ? sanitize_key( wp_unslash( $_POST['test_type'] ) ) : '';
		$tab  = ( isset( $_POST['return_tab'] ) && 'site-emails' === $_POST['return_tab'] ) ? 'site-emails' : 'email';
		$user = wp_get_current_user();

		if ( 'migration_free' === $type || 'migration_paid' === $type ) {
			$sent = DrWealth_Substack_Mailer::send_test( 'migration_free' === $type ? DrWealth_Substack_Mailer::VARIANT_FREE : DrWealth_Substack_Mailer::VARIANT_PAID, $user );
			$ok   = ! is_wp_error( $sent );
			$msg  = $ok ? '' : $sent->get_error_message();
		} elseif ( in_array( $type, array( 'signup', 'premium', 'brain' ), true ) ) {
			$ok  = drwealth_emails_send_test( $type, $user );
			$msg = $ok ? '' : 'wp_mail() reported failure.';
		} else {
			$ok  = false;
			$msg = 'Unknown test type.';
		}

		set_transient(
			self::TRANSIENT_EMAIL_SETTINGS,
			array(
				'message'  => $ok ? 'Test email sent to ' . $user->user_email . '.' : 'Test email failed: ' . $msg,
				'is_error' => ! $ok,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=' . $tab ) );
		exit;
	}

	public static function handle_save_email_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_ACTION_EMAIL_SETTINGS );

		foreach ( array( DrWealth_Substack_Mailer::VARIANT_FREE, DrWealth_Substack_Mailer::VARIANT_PAID ) as $variant ) {
			$subject = isset( $_POST[ 'subject_' . $variant ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'subject_' . $variant ] ) ) : '';
			$body    = isset( $_POST[ 'body_' . $variant ] ) ? wp_kses_post( wp_unslash( $_POST[ 'body_' . $variant ] ) ) : '';

			DrWealth_Substack_Mailer::save_template( $variant, $subject, $body );
		}

		set_transient(
			self::TRANSIENT_EMAIL_SETTINGS,
			array(
				'message'  => 'Email template saved.',
				'is_error' => false,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=email' ) );
		exit;
	}

	public static function handle_send_welcome_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_ACTION_SEND_EMAIL );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$sent    = DrWealth_Substack_Mailer::send_to_user( $user_id );

		if ( is_wp_error( $sent ) ) {
			$message  = 'Could not send: ' . $sent->get_error_message();
			$is_error = true;
		} else {
			$message  = 'Welcome email sent.';
			$is_error = false;
		}

		set_transient(
			self::TRANSIENT_EMAIL_SEND,
			array(
				'message'  => $message,
				'is_error' => $is_error,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=subscribers' ) );
		exit;
	}

	/** AJAX: import one post sent by the browser (batch import). Returns JSON. */
	public static function handle_ajax_import_one() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'Not allowed.' ), 403 );
		}

		check_ajax_referer( 'drwealth_substack_import_one', 'nonce' );

		@set_time_limit( 150 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$field = function ( $key ) {
			return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security
		};

		$row = array(
			'post_id'   => sanitize_text_field( $field( 'post_id' ) ),
			'post_date' => sanitize_text_field( $field( 'post_date' ) ),
			'audience'  => sanitize_key( $field( 'audience' ) ),
			'title'     => sanitize_text_field( $field( 'title' ) ),
			'subtitle'  => sanitize_text_field( $field( 'subtitle' ) ),
		);

		$result = DrWealth_Substack_Importer::import_one(
			$row,
			(string) $field( 'html' ), // Raw HTML; the importer sanitises it (wp_kses_post).
			sanitize_key( $field( 'publication' ) ),
			(int) $field( 'author_id' ),
			! empty( $_POST['overwrite'] ), // phpcs:ignore WordPress.Security.NonceVerification
			(string) $field( 'run_id' )
		);

		if ( 'error' === $result['outcome'] ) {
			wp_send_json_error( $result );
		}

		wp_send_json_success( $result );
	}

	/** Box on the Posts tab: batch import. The .zip is read in the browser, one post per request, with a progress bar. */
	protected static function render_batch_box() {
		$config = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'drwealth_substack_import_one' ),
		);
		?>
		<h2>Batch import (recommended)</h2>
		<p>
			Pick the export .zip and it is read <strong>in your browser</strong>: nothing is uploaded, and each post is sent to the site one at a time, so there are no timeouts.
			Keep this page open until it finishes. If you close it or press Stop, run it again: posts already imported are recognised and left as they are.
			Each run is tagged so it can be undone below.
		</p>
		<table class="form-table" id="dw-batch">
			<tr>
				<th><label for="dw-b-pub">Publication</label></th>
				<td><select id="dw-b-pub"><?php self::render_publication_options(); ?></select></td>
			</tr>
			<tr>
				<th><label for="dw-b-author">Author</label></th>
				<td>
					<?php
					wp_dropdown_users(
						array(
							'name'              => 'dw_b_author',
							'id'                => 'dw-b-author',
							'selected'          => (int) get_option( 'drwealth_substack_author_id', 0 ),
							'show_option_none'  => '— Select author —',
							'option_none_value' => 0,
							'capability'        => array( 'edit_posts' ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="dw-b-file">Substack export (.zip)</label></th>
				<td><input type="file" id="dw-b-file" accept=".zip" /></td>
			</tr>
			<tr>
				<th>Which posts</th>
				<td>
					Skip the first <input type="number" id="dw-b-skip" value="0" min="0" style="width:80px" /> published posts, then import
					<input type="number" id="dw-b-count" value="0" min="0" style="width:80px" /> (0 = all the rest).
				</td>
			</tr>
			<tr>
				<th>Posts already on the site</th>
				<td><label><input type="checkbox" id="dw-b-overwrite" /> Also overwrite their title, content and date (off = only link them, categories never change)</label></td>
			</tr>
		</table>
		<p>
			<button type="button" class="button button-primary" id="dw-b-start">Start batch import</button>
			<button type="button" class="button" id="dw-b-stop" disabled>Stop</button>
		</p>
		<div id="dw-b-bar" style="display:none;max-width:640px;background:#dcdcde;border-radius:3px;height:18px;overflow:hidden"><div id="dw-b-fill" style="background:#2271b1;height:18px;width:0"></div></div>
		<p id="dw-b-status" style="font-weight:600"></p>
		<pre id="dw-b-log" style="max-height:220px;overflow:auto;background:#f6f7f7;padding:8px;display:none;max-width:900px"></pre>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $config ); ?>;
			var $ = function (id) { return document.getElementById(id); };
			var stopRequested = false;

			function parseCSV(text) {
				if (text.charCodeAt(0) === 0xFEFF) { text = text.slice(1); }
				var rows = [], row = [], f = '', q = false, i, c;
				for (i = 0; i < text.length; i++) {
					c = text[i];
					if (q) {
						if (c === '"') { if (text[i + 1] === '"') { f += '"'; i++; } else { q = false; } } else { f += c; }
					} else if (c === '"') { q = true; }
					else if (c === ',') { row.push(f); f = ''; }
					else if (c === '\n') { row.push(f); rows.push(row); row = []; f = ''; }
					else if (c !== '\r') { f += c; }
				}
				if (f !== '' || row.length) { row.push(f); rows.push(row); }
				return rows;
			}

			function pad(n) { return (n < 10 ? '0' : '') + n; }
			function runId() {
				var d = new Date();
				return d.getUTCFullYear() + pad(d.getUTCMonth() + 1) + pad(d.getUTCDate()) + '-' + pad(d.getUTCHours()) + pad(d.getUTCMinutes()) + pad(d.getUTCSeconds());
			}

			function log(msg) {
				var el = $('dw-b-log');
				el.style.display = 'block';
				el.textContent += msg + '\n';
				el.scrollTop = el.scrollHeight;
			}

			async function sendOne(payload) {
				var fd = new FormData();
				fd.append('action', 'drwealth_substack_import_one');
				fd.append('nonce', cfg.nonce);
				Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
				var lastErr = '';
				for (var attempt = 0; attempt < 3; attempt++) {
					try {
						var r = await fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
						var j = await r.json();
						if (j && j.success) { return j.data; }
						lastErr = (j && j.data && j.data.error) ? j.data.error : ('HTTP ' + r.status);
						if (j && j.data && j.data.outcome === 'error') { return j.data; }
					} catch (e) { lastErr = String(e); }
					await new Promise(function (res) { setTimeout(res, 2000); });
				}
				return { outcome: 'error', id: 0, error: lastErr };
			}

			async function start() {
				var file = $('dw-b-file').files[0];
				var author = parseInt($('dw-b-author').value, 10) || 0;
				if (!file) { alert('Choose the export .zip first.'); return; }
				if (!author) { alert('Choose the author.'); return; }
				if (typeof JSZip === 'undefined') { alert('Could not load the zip reader (cdnjs.cloudflare.com is blocked?).'); return; }

				var pub = $('dw-b-pub').value;
				var overwrite = $('dw-b-overwrite').checked ? '1' : '';
				var skip = Math.max(0, parseInt($('dw-b-skip').value, 10) || 0);
				var count = Math.max(0, parseInt($('dw-b-count').value, 10) || 0);

				$('dw-b-start').disabled = true;
				$('dw-b-stop').disabled = false;
				stopRequested = false;
				$('dw-b-bar').style.display = 'block';
				$('dw-b-log').textContent = '';
				$('dw-b-status').textContent = 'Reading the zip...';

				var zip, csvEntry, base, rows, header, list = [];
				try {
					zip = await JSZip.loadAsync(file);
					csvEntry = zip.file(/(^|\/)posts\.csv$/)[0];
					if (!csvEntry) { throw new Error('posts.csv not found in the zip.'); }
					base = csvEntry.name.slice(0, csvEntry.name.length - 'posts.csv'.length);
					rows = parseCSV(await csvEntry.async('string'));
					header = rows.shift();
					rows.forEach(function (r) {
						if (r.length !== header.length) { return; }
						var o = {};
						header.forEach(function (h, i) { o[h] = r[i]; });
						if (o.is_published === 'true') { list.push(o); }
					});
				} catch (e) {
					$('dw-b-status').textContent = 'Error: ' + e.message;
					$('dw-b-start').disabled = false; $('dw-b-stop').disabled = true;
					return;
				}

				list = list.slice(skip, count ? skip + count : undefined);
				var run = runId();
				var tally = { created: 0, updated: 0, matched: 0, skipped: 0, error: 0 };
				log('Run ' + run + ': ' + list.length + ' post(s) from ' + pub + ', author #' + author + (overwrite ? ', overwrite ON' : ''));

				for (var i = 0; i < list.length; i++) {
					if (stopRequested) { log('Stopped by you after ' + i + ' post(s).'); break; }
					var row = list[i];
					var entry = zip.file(base + 'posts/' + row.post_id + '.html');
					var out;
					if (!entry) {
						out = { outcome: 'skipped', error: 'no HTML file' };
					} else {
						out = await sendOne({
							publication: pub, author_id: author, overwrite: overwrite, run_id: run,
							post_id: row.post_id, post_date: row.post_date || '', audience: row.audience || '',
							title: row.title || '', subtitle: row.subtitle || '', html: await entry.async('string')
						});
					}
					tally[out.outcome] = (tally[out.outcome] || 0) + 1;
					if (out.outcome === 'error' || out.outcome === 'skipped') { log('#' + (skip + i + 1) + ' ' + row.post_id + ': ' + out.outcome + (out.error ? ' - ' + out.error : '')); }
					$('dw-b-fill').style.width = Math.round(((i + 1) / list.length) * 100) + '%';
					$('dw-b-status').textContent = (i + 1) + ' / ' + list.length + ' - created ' + tally.created + ', already on site ' + tally.matched + ', updated ' + tally.updated + ', skipped ' + tally.skipped + ', errors ' + tally.error;
				}

				log('Finished. Reload this page to see the run in "Undo an import run".');
				$('dw-b-start').disabled = false;
				$('dw-b-stop').disabled = true;
			}

			$('dw-b-start').addEventListener('click', start);
			$('dw-b-stop').addEventListener('click', function () { stopRequested = true; });
		})();
		</script>
		<?php
	}

	const TRANSIENT_UNDO = 'drwealth_substack_undo_result';

	/** Import runs that created posts or images, newest first: array( run_id => array( 'posts' => n, 'images' => n ) ). */
	protected static function get_runs() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT m.meta_value AS run, p.post_type AS type, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s GROUP BY m.meta_value, p.post_type ORDER BY m.meta_value DESC",
				DrWealth_Substack_Importer::META_RUN
			)
		);

		$runs = array();

		foreach ( (array) $rows as $row ) {
			if ( ! isset( $runs[ $row->run ] ) ) {
				$runs[ $row->run ] = array( 'posts' => 0, 'images' => 0 );
			}

			$runs[ $row->run ][ 'attachment' === $row->type ? 'images' : 'posts' ] += (int) $row->n;
		}

		return $runs;
	}

	/** Box on the Posts tab: undo an import run (permanently deletes what that run created). */
	protected static function render_runs_box() {
		$result = get_transient( self::TRANSIENT_UNDO );
		delete_transient( self::TRANSIENT_UNDO );
		self::render_simple_notice( $result );

		$runs = self::get_runs();
		?>
		<h2>Undo an import run</h2>
		<p>
			Each post import is tagged with a run ID (date and time). Undoing a run <strong>permanently deletes</strong> the posts and images that run created.
			Posts that were already on the site are never touched. Undo the newest run first, because a later run can reuse an earlier run's images.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
			<?php wp_nonce_field( 'drwealth_substack_duplicates' ); ?>
			<input type="hidden" name="action" value="drwealth_substack_duplicates" />
			<strong>Duplicates of posts already on the site:</strong>
			<button type="submit" name="mode" value="check" class="button">Check</button>
			<button type="submit" name="mode" value="apply" class="button" onclick="return confirm('Delete the NEW copy of every post that duplicates a post already on the site (and the images it downloaded)? The old posts are kept.');">Delete the new duplicates</button>
		</form>
		<?php if ( ! $runs ) : ?>
			<p><em>No runs yet. Runs appear here after an import creates posts.</em></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:640px">
				<thead><tr><th>Run</th><th>Posts created</th><th>Images created</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $runs as $run => $counts ) : ?>
						<tr>
							<td><code><?php echo esc_html( $run ); ?></code></td>
							<td><?php echo (int) $counts['posts']; ?></td>
							<td><?php echo (int) $counts['images']; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'drwealth_substack_undo_run' ); ?>
									<input type="hidden" name="action" value="drwealth_substack_undo_run" />
									<input type="hidden" name="run" value="<?php echo esc_attr( $run ); ?>" />
									<button type="submit" class="button" onclick="return confirm('PERMANENTLY delete the <?php echo (int) $counts['posts']; ?> post(s) and <?php echo (int) $counts['images']; ?> image(s) created by run <?php echo esc_js( $run ); ?>? This cannot be undone.');">Undo this run</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Posts created by an import run that duplicate a post which was already on the site (the old
	 * copy has the Substack ID in front of its slug, e.g. "146202208.my-post").
	 *
	 * @return array<int,array{new:int,old:int}> new post ID => pair.
	 */
	protected static function find_duplicate_pairs() {
		global $wpdb;

		$new_ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'meta_key'         => DrWealth_Substack_Importer::META_RUN, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			)
		);

		$pairs = array();

		foreach ( $new_ids as $new_id ) {
			if ( 'created' !== get_post_meta( $new_id, DRWEALTH_SUBSTACK_ORIGIN_META, true ) ) {
				continue;
			}

			$new_post = get_post( $new_id );

			if ( ! $new_post ) {
				continue;
			}

			// Dates are stored in site-local time on both copies, so they compare directly.
			$old = DrWealth_Substack_Importer::find_match_by_content( $new_post->post_title, $new_post->post_date, $new_post->post_name, $new_id );

			if ( $old ) {
				$pairs[ (int) $new_id ] = array( 'new' => (int) $new_id, 'old' => (int) $old );
			}
		}

		return $pairs;
	}

	public static function handle_duplicates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'drwealth_substack_duplicates' );

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/post.php';

		$apply = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'];
		$pairs = self::find_duplicate_pairs();

		if ( ! $apply ) {
			$sample  = array_slice( $pairs, 0, 5 );
			$lines   = array_map(
				function ( $p ) {
					return '#' . $p['new'] . ' (new, /' . get_post_field( 'post_name', $p['new'] ) . ') duplicates #' . $p['old'] . ' (old, /' . get_post_field( 'post_name', $p['old'] ) . ')';
				},
				$sample
			);
			$message = sprintf( '%d new post(s) duplicate a post that was already on the site. Applying deletes the NEW copy (and the images it downloaded) and keeps the old post. %s', count( $pairs ), $lines ? 'Examples: ' . implode( '; ', $lines ) : '' );
		} else {
			$deadline = time() + 20;
			$deleted  = 0;
			$images   = 0;

			foreach ( $pairs as $pair ) {
				if ( time() > $deadline ) {
					break;
				}

				// Link the old post to its Substack ID first (so a re-import recognises it).
				$sid = get_post_meta( $pair['new'], DrWealth_Substack_Importer::META_SUBSTACK_ID, true );
				$pub = get_post_meta( $pair['new'], DrWealth_Substack_Importer::META_PUBLICATION, true );

				if ( ! get_post_meta( $pair['old'], DrWealth_Substack_Importer::META_SUBSTACK_ID, true ) ) {
					update_post_meta( $pair['old'], DrWealth_Substack_Importer::META_SUBSTACK_ID, $sid );
					update_post_meta( $pair['old'], DrWealth_Substack_Importer::META_PUBLICATION, $pub );
				}

				$attachments = get_posts(
					array(
						'post_type'        => 'attachment',
						'post_status'      => 'any',
						'post_parent'      => $pair['new'],
						'meta_key'         => DrWealth_Substack_Importer::META_RUN, // phpcs:ignore WordPress.DB.SlowDBQuery
						'fields'           => 'ids',
						'numberposts'      => -1,
						'suppress_filters' => true,
					)
				);

				foreach ( $attachments as $att ) {
					// An image can be shared (the importer reuses a download): only delete it when no other
					// post uses it as content or as its featured image.
					$file = (string) get_post_meta( $att, '_wp_attached_file', true );
					$stem = pathinfo( $file, PATHINFO_FILENAME );
					$used = false;

					if ( '' !== $stem ) {
						$used = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB
							$wpdb->prepare(
								"SELECT 1 FROM {$wpdb->posts} WHERE post_type = 'post' AND ID <> %d AND post_content LIKE %s LIMIT 1",
								$pair['new'],
								'%' . $wpdb->esc_like( $stem ) . '%'
							)
						);
					}

					if ( ! $used ) {
						$used = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB
							$wpdb->prepare(
								"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d AND post_id <> %d LIMIT 1",
								$att,
								$pair['new']
							)
						);
					}

					if ( ! $used && wp_delete_attachment( $att, true ) ) {
						$images++;
					}
				}

				if ( wp_delete_post( $pair['new'], true ) ) {
					$deleted++;
				}
			}

			$left    = count( self::find_duplicate_pairs() );
			$message = sprintf( 'Deleted %d duplicate new post(s) and %d image(s); kept the old posts. %s', $deleted, $images, $left ? $left . ' left: click again.' : 'No duplicates left.' );
		}

		set_transient( self::TRANSIENT_UNDO, array( 'message' => $message, 'is_error' => false ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=posts' ) );
		exit;
	}

	public static function handle_undo_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'drwealth_substack_undo_run' );

		$run = isset( $_POST['run'] ) ? preg_replace( '/[^0-9-]/', '', wp_unslash( $_POST['run'] ) ) : '';

		if ( '' === $run ) {
			wp_die( 'No run given.' );
		}

		require_once ABSPATH . 'wp-admin/includes/post.php';

		$deadline = time() + 20; // Short batches: click again if some are left.
		$posts    = 0;
		$images   = 0;
		$kept     = 0;
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'inherit' );

		$post_ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => $statuses,
				'meta_key'         => DrWealth_Substack_Importer::META_RUN, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $run, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'numberposts'      => 300,
				'suppress_filters' => true,
			)
		);

		foreach ( $post_ids as $id ) {
			if ( time() > $deadline ) {
				break;
			}

			// Only posts the import itself created. Anything else is released from the run, not deleted.
			if ( 'created' !== get_post_meta( $id, DRWEALTH_SUBSTACK_ORIGIN_META, true ) ) {
				delete_post_meta( $id, DrWealth_Substack_Importer::META_RUN );
				$kept++;
				continue;
			}

			if ( wp_delete_post( $id, true ) ) {
				$posts++;
			}
		}

		if ( time() <= $deadline ) {
			$attachment_ids = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'any',
					'meta_key'         => DrWealth_Substack_Importer::META_RUN, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => $run, // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'           => 'ids',
					'numberposts'      => 300,
					'suppress_filters' => true,
				)
			);

			foreach ( $attachment_ids as $id ) {
				if ( time() > $deadline ) {
					break;
				}

				if ( wp_delete_attachment( $id, true ) ) {
					$images++;
				}
			}
		}

		$left    = self::get_runs();
		$left    = isset( $left[ $run ] ) ? $left[ $run ] : array( 'posts' => 0, 'images' => 0 );
		$message = sprintf( 'Run %s: deleted %d post(s) and %d image(s).', $run, $posts, $images );

		if ( $kept ) {
			$message .= sprintf( ' %d item(s) were not created by the import and were left alone.', $kept );
		}

		$message .= ( $left['posts'] || $left['images'] ) ? sprintf( ' Still to delete: %d post(s), %d image(s). Click "Undo this run" again to continue.', $left['posts'], $left['images'] ) : ' Nothing left from this run.';

		set_transient( self::TRANSIENT_UNDO, array( 'message' => $message, 'is_error' => false ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=posts' ) );
		exit;
	}

	const TRANSIENT_RECLEAN = 'drwealth_substack_reclean_result';

	/** Box on the Posts tab: re-run the content clean-up on posts already on the site. */
	protected static function render_reclean_box() {
		$result = get_transient( self::TRANSIENT_RECLEAN );
		delete_transient( self::TRANSIENT_RECLEAN );
		self::render_simple_notice( $result );
		?>
		<h2>Re-clean posts already on the site</h2>
		<p>
			Re-applies the content clean-up without re-importing: links to old Substack posts become links on this site (also inside embedded post cards),
			Mighty Networks links are removed (text kept), and Substack image addresses in posts the import created are downloaded and replaced by local copies.
			Post dates are not changed. Apply works in short batches: click again until none are left.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'drwealth_substack_reclean' ); ?>
			<input type="hidden" name="action" value="drwealth_substack_reclean" />
			<button type="submit" name="mode" value="check" class="button">Check</button>
			<button type="submit" name="mode" value="apply" class="button button-primary">Apply</button>
		</form>
		<?php
	}

	public static function handle_reclean() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'drwealth_substack_reclean' );

		$apply = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'];
		$r     = DrWealth_Substack_Importer::reclean_run( $apply, time() + 20 );

		$message = $apply
			? sprintf( 'Cleaned %d post(s). %d still to do (click Apply again until 0; a few may stay if an image could not be downloaded).', $r['changed'], $r['left'] )
			: sprintf( '%d post(s) would be cleaned.', $r['found'] );

		set_transient( self::TRANSIENT_RECLEAN, array( 'message' => $message, 'is_error' => false ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=posts' ) );
		exit;
	}

	const TRANSIENT_LEFTOVERS = 'drwealth_substack_leftovers_result';

	/** Box on the Posts tab: find / delete Substack export files left in the public uploads folder. */
	protected static function render_leftovers_box() {
		$result = get_transient( self::TRANSIENT_LEFTOVERS );
		delete_transient( self::TRANSIENT_LEFTOVERS );
		self::render_simple_notice( $result );
		?>
		<h2>Leftover export files</h2>
		<p>
			New imports keep the uploaded export in a private temp folder and delete it as soon as the import finishes. This looks for export files
			(the old <code>drwealth-substack-tmp</code> folder, export .zip files, posts.csv / email_list CSVs) left in the public uploads folder by earlier versions.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'drwealth_substack_leftovers' ); ?>
			<input type="hidden" name="action" value="drwealth_substack_leftovers" />
			<button type="submit" name="mode" value="check" class="button">Check</button>
			<button type="submit" name="mode" value="delete" class="button" onclick="return confirm('Permanently delete the leftover export files found by Check?');">Delete leftover files</button>
		</form>
		<?php
	}

	public static function handle_leftovers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( 'drwealth_substack_leftovers' );

		$mode = isset( $_POST['mode'] ) && 'delete' === $_POST['mode'] ? 'delete' : 'check';

		if ( 'delete' === $mode ) {
			$deleted = DrWealth_Substack_Zip_Helper::delete_leftovers();
			$left    = count( DrWealth_Substack_Zip_Helper::find_leftovers() );
			$message = sprintf( 'Deleted %d leftover export item(s). %d remaining.', $deleted, $left );
		} else {
			$found   = DrWealth_Substack_Zip_Helper::find_leftovers();
			$upload  = wp_get_upload_dir();
			$names   = array_map(
				function ( $p ) use ( $upload ) {
					return str_replace( untrailingslashit( $upload['basedir'] ), 'uploads', $p );
				},
				$found
			);
			$message = $found ? sprintf( '%d leftover item(s): %s', count( $found ), implode( '; ', $names ) ) : 'No leftover export files found.';
		}

		set_transient( self::TRANSIENT_LEFTOVERS, array( 'message' => $message, 'is_error' => false ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=posts' ) );
		exit;
	}

	public static function handle_send_welcome_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		check_admin_referer( self::NONCE_ACTION_SEND_BATCH );

		$limit  = isset( $_POST['batch_limit'] ) ? (int) $_POST['batch_limit'] : 20;
		$result = DrWealth_Substack_Mailer::send_batch( $limit );

		$message = sprintf( 'Activated memberships and sent %d email(s).', $result['sent'] );

		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . count( $result['errors'] ) . ' error(s): ' . implode( '; ', $result['errors'] );
		}

		set_transient(
			self::TRANSIENT_EMAIL_SEND,
			array(
				'message'  => $message,
				'is_error' => ! empty( $result['errors'] ),
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&tab=subscribers' ) );
		exit;
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

		// Keep the export OUT of the public uploads folder (it contains the subscriber list):
		// move it to a private temp file instead of using wp_handle_upload().
		$name = sanitize_file_name( wp_unslash( $_FILES[ $field ]['name'] ) );
		$tmp  = $_FILES[ $field ]['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'upload_failed', 'Please upload the Substack export as a .zip file.' );
		}

		if ( ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'upload_failed', 'The upload did not complete. Please try again.' );
		}

		$dest = wp_tempnam( $name );

		if ( ! $dest || ! move_uploaded_file( $tmp, $dest ) ) {
			return new WP_Error( 'upload_failed', 'Could not store the upload in the temp folder.' );
		}

		return $dest;
	}

	protected static function redirect_with_result( $transient_key, $query_tab, $result ) {
		set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=drwealth-substack-sync&' . $query_tab ) );
		exit;
	}
}
