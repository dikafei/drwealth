<?php
/**
 * Sends the "welcome to your membership" email to imported subscribers.
 *
 * Deliberately separate from the import step — importing a subscriber never
 * sends mail on its own. Sending only happens when triggered here, either
 * one at a time (per-row "Send welcome email" button) or in a batch (a fixed
 * number of not-yet-emailed subscribers per run). Once a subscriber has been
 * emailed, they're never emailed again by the batch — re-running the batch
 * (or re-importing) does not reset that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DrWealth_Substack_Mailer {

	const META_EMAILED_AT = '_drwealth_substack_welcome_emailed_at';

	const OPTION_SUBJECT = 'drwealth_substack_welcome_email_subject';
	const OPTION_BODY    = 'drwealth_substack_welcome_email_body';

	const DEFAULT_SUBJECT = 'Your {plan} is ready';

	const DEFAULT_BODY = '<p>Hi {first_name},</p>
<p>Your account on {site_name} is set up with <strong>{plan}</strong>{expiry_sentence}</p>
<p>Since this account was migrated over for you, you\'ll need to set a password before logging in for the first time:</p>
<p><a href="{reset_link}">Set your password</a></p>
<p>Once that\'s done, you can log in any time at <a href="{login_url}">{login_url}</a> using this email address ({email}).</p>
<p>Thanks,<br>{site_name}</p>';

	public static function get_subject() {
		$subject = get_option( self::OPTION_SUBJECT, '' );

		return $subject ? $subject : self::DEFAULT_SUBJECT;
	}

	public static function get_body() {
		$body = get_option( self::OPTION_BODY, '' );

		return $body ? $body : self::DEFAULT_BODY;
	}

	public static function save_template( $subject, $body ) {
		update_option( self::OPTION_SUBJECT, $subject );
		update_option( self::OPTION_BODY, $body );
	}

	/**
	 * When this user was last sent the welcome email, or '' if never.
	 */
	public static function get_emailed_at( $user_id ) {
		return get_user_meta( $user_id, self::META_EMAILED_AT, true );
	}

	/**
	 * Send the welcome email to a single user right now, regardless of
	 * whether they've already been emailed before (used by the per-row
	 * "Send welcome email" button, which is an explicit re-send).
	 *
	 * @return true|WP_Error
	 */
	public static function send_to_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'no_user', 'No such user.' );
		}

		$subject = self::render_template( self::get_subject(), $user );
		$body    = self::render_template( self::get_body(), $user );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		$sent = wp_mail( $user->user_email, $subject, $body );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'wp_mail() reported failure.' );
		}

		update_user_meta( $user_id, self::META_EMAILED_AT, current_time( 'mysql' ) );

		return true;
	}

	public static function html_content_type() {
		return 'text/html';
	}

	/**
	 * Send the welcome email to the next batch of imported subscribers who
	 * haven't been emailed yet — up to $limit of them, oldest-imported
	 * first. Subscribers who already have an emailed-at date are skipped, so
	 * running this repeatedly works through the backlog a chunk at a time
	 * without ever re-emailing anyone.
	 *
	 * @return array{sent: int, errors: string[]}
	 */
	public static function send_batch( $limit = 20 ) {
		$result = array(
			'sent'   => 0,
			'errors' => array(),
		);

		$limit = max( 1, min( 500, (int) $limit ) );

		$user_ids = self::get_pending_user_ids( $limit );

		foreach ( $user_ids as $user_id ) {
			$sent = self::send_to_user( $user_id );

			if ( is_wp_error( $sent ) ) {
				$result['errors'][] = 'User #' . $user_id . ': ' . $sent->get_error_message();
				continue;
			}

			$result['sent']++;
		}

		return $result;
	}

	/**
	 * How many imported subscribers are still waiting for their welcome
	 * email (used to show "X pending" next to the batch-send button).
	 */
	public static function count_pending() {
		$query = new WP_User_Query( self::pending_query_args( array( 'fields' => 'ID' ) ) );

		return (int) $query->get_total();
	}

	/**
	 * @return int[] User IDs imported via the Subscribers tab that have
	 *               never been sent the welcome email.
	 */
	protected static function get_pending_user_ids( $limit ) {
		$query = new WP_User_Query(
			self::pending_query_args(
				array(
					'fields' => 'ID',
					'number' => $limit,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			)
		);

		return $query->get_results();
	}

	protected static function pending_query_args( $extra = array() ) {
		return array_merge(
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => DrWealth_Substack_Subscriber_Importer::META_PUBLICATIONS,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_EMAILED_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			),
			$extra
		);
	}

	/**
	 * Replace {placeholders} in a subject/body template with this user's
	 * actual data.
	 */
	protected static function render_template( $template, $user ) {
		$info = DrWealth_Substack_Subscriber_Importer::get_effective_membership_info( $user->ID );

		$plan_label = DrWealth_Substack_Subscriber_Importer::get_plan_label( $info['slug'] );

		if ( DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG === $info['slug'] ) {
			$expiry_display   = 'no expiry — lifetime access';
			$expiry_sentence  = ', which never expires.';
		} elseif ( DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_FREE_SLUG === $info['slug'] ) {
			$expiry_display  = 'N/A';
			$expiry_sentence = '.';
		} else {
			$timestamp = $info['expiry'] ? strtotime( $info['expiry'] ) : false;

			if ( $timestamp ) {
				$expiry_display  = date_i18n( get_option( 'date_format' ), $timestamp );
				$expiry_sentence = ', valid until ' . $expiry_display . '.';
			} else {
				$expiry_display  = 'N/A';
				$expiry_sentence = '.';
			}
		}

		$first_name = $user->first_name ? $user->first_name : $user->display_name;

		// Escaped for HTML output — these values ultimately come from CSV
		// imports (names, plan data), so they shouldn't be trusted verbatim
		// in an email body.
		$replacements = array(
			'{first_name}'      => esc_html( $first_name ),
			'{display_name}'    => esc_html( $user->display_name ),
			'{email}'           => esc_html( $user->user_email ),
			'{plan}'            => esc_html( $plan_label ),
			'{expiry}'          => esc_html( $expiry_display ),
			'{expiry_sentence}' => esc_html( $expiry_sentence ),
			'{reset_link}'      => esc_url( self::build_reset_link( $user ) ),
			'{login_url}'       => esc_url( wp_login_url() ),
			'{site_name}'       => esc_html( get_bloginfo( 'name' ) ),
		);

		return strtr( $template, $replacements );
	}

	/**
	 * A direct "set your password" link, using WordPress's own password
	 * reset flow (same mechanism as the "lost password" link on wp-login.php).
	 */
	protected static function build_reset_link( $user ) {
		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return wp_login_url() . '?action=lostpassword';
		}

		return network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);
	}
}
