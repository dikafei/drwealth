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

	// Two migration emails: one for people who end up on the Free plan, one for
	// people who end up on a paid plan. Option names are "<prefix>_<variant>".
	const VARIANT_FREE = 'free';
	const VARIANT_PAID = 'paid';

	const OPTION_SUBJECT_PREFIX = 'drwealth_substack_welcome_email_subject';
	const OPTION_BODY_PREFIX    = 'drwealth_substack_welcome_email_body';

	const DEFAULT_SUBJECT_FREE = 'Your free newsletter is moving to Dr Wealth';

	const DEFAULT_BODY_FREE = '<p>Hi {first_name},</p>
<p>We’re bringing Finbite Insights and Growth Dragons together on drwealth.com, alongside Dr Wealth’s content.</p>
<p>Our articles and newsletters have lived across different websites. This move brings them under one roof, making it easier for you to follow our analysis of Singapore, China and the US.</p>
<h3>Your subscription stays free</h3>
<p>You’ll continue receiving our free newsletters by email. You don’t need to pay to stay subscribed. Premium articles will remain available to Premium subscribers.</p>
<h3>One place to read more</h3>
<p>Your Dr Wealth account will let you browse our free content without switching between separate newsletter sites.</p>
<p>If you’d like access to our Premium articles, Dr Wealth Premium brings together Dr Wealth’s premium content, Finbite Insights and Growth Dragons for one fixed subscription price.</p>
<p><a href="{subscription_url}">Explore Dr Wealth Premium</a></p>
<h3>Set up your Dr Wealth login</h3>
<p>Your account will use the same email address as your Substack subscription. Set your password below whenever you’d like to read on the website. You don’t need to do this to keep receiving free emails.</p>
<p><a href="{reset_link}">Set up my Dr Wealth account</a></p>
<p>This is our first step towards bringing Dr Wealth’s services under one account. If you’re also a course student, your course recordings haven’t moved yet. Please continue using your existing course platform.</p>
<p>Thank you for reading and being part of our community. We look forward to welcoming you to Dr Wealth.</p>
<p>Alvin Chow<br>Dr Wealth</p>';

	const DEFAULT_SUBJECT_PAID = 'Finbite Insights and Growth Dragons are moving to Dr Wealth';

	const DEFAULT_BODY_PAID = '<p>Hi {first_name},</p>
<p>We’re bringing Finbite Insights and Growth Dragons together on drwealth.com, alongside Dr Wealth’s premium content.</p>
<p>Our articles have been spread across separate websites and subscriptions. We’re bringing them together so you can find more of what you need in one place.</p>
<h3>Three sources of articles, one subscription</h3>
<p>You’ll get Dr Wealth’s premium content, Finbite Insights and Growth Dragons for one fixed subscription price, with coverage across Singapore, China and the US.</p>
<p>You won’t need separate newsletter subscriptions or different logins to read them.</p>
<h3>Still delivered to your inbox</h3>
<p>You’ll continue receiving our articles by email. You can also use your Dr Wealth login to read it on drwealth.com.</p>
<p>This is our first step towards bringing Dr Wealth’s services under one account. Course recordings haven’t moved yet, so please continue accessing those through your existing course platform.</p>
<h3>You keep all the subscription time you’ve paid for</h3>
<p>Any remaining time on your existing subscription will carry over to your Dr Wealth subscription. Your access currently runs until: <strong>{expiry}</strong>.</p>
<p>If you’ve paid for both Finbite Insights and Growth Dragons, we’ll add the remaining periods together. For example, four months left on Finbite Insights plus eight months on Growth Dragons gives you 12 months of Dr Wealth Premium, with access to all three throughout that period.</p>
<h3>When your subscription time runs out</h3>
<p>You don’t need to do anything today, and nothing will be charged automatically. When your time is up and you’d like to continue, choose a plan (monthly or yearly) on our <a href="{subscription_url}">subscription page</a> and check out using this same email address. Your new plan continues on your existing Dr Wealth account.</p>
<h3>Set up your Dr Wealth login</h3>
<p>Your account will use the same email address as your Substack subscription. Click below to set your password.</p>
<p><a href="{reset_link}">Set up my Dr Wealth account</a></p>
<p>Thank you for supporting our work. We look forward to bringing you more articles through one Dr Wealth subscription.</p>
<p>If you have questions or trouble accessing your account, reply to this email and we’ll help.</p>
<p>Alvin Chow<br>Dr Wealth</p>';

	/**
	 * Which email a user gets: Free plan -> free email, any paid plan -> paid email.
	 */
	public static function variant_for_user( $user_id ) {
		$info = DrWealth_Substack_Subscriber_Importer::get_effective_membership_info( $user_id );

		return DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_FREE_SLUG === $info['slug'] ? self::VARIANT_FREE : self::VARIANT_PAID;
	}

	public static function get_subject( $variant = self::VARIANT_PAID ) {
		$subject = get_option( self::OPTION_SUBJECT_PREFIX . '_' . $variant, '' );

		if ( $subject ) {
			return $subject;
		}

		return self::VARIANT_FREE === $variant ? self::DEFAULT_SUBJECT_FREE : self::DEFAULT_SUBJECT_PAID;
	}

	public static function get_body( $variant = self::VARIANT_PAID ) {
		$body = get_option( self::OPTION_BODY_PREFIX . '_' . $variant, '' );

		if ( $body ) {
			return $body;
		}

		return self::VARIANT_FREE === $variant ? self::DEFAULT_BODY_FREE : self::DEFAULT_BODY_PAID;
	}

	public static function save_template( $variant, $subject, $body ) {
		update_option( self::OPTION_SUBJECT_PREFIX . '_' . $variant, $subject );
		update_option( self::OPTION_BODY_PREFIX . '_' . $variant, $body );
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

		$variant      = self::variant_for_user( $user_id );
		$raw_body     = self::get_body( $variant );

		// Safety: never send an email that still contains an unfilled
		// "[Insert ...]" placeholder (the paid email has one for renewal details).
		if ( false !== stripos( $raw_body, '[Insert' ) ) {
			return new WP_Error( 'unfilled_placeholder', 'The ' . $variant . ' email still contains an "[Insert ...]" placeholder. Edit it on the Migration Email tab first.' );
		}

		$subject = self::render_template( self::get_subject( $variant ), $user );
		$body    = self::render_template( $raw_body, $user );

		$sent = self::deliver( $user->user_email, $subject, $body, $subject );

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'wp_mail() reported failure.' );
		}

		update_user_meta( $user_id, self::META_EMAILED_AT, current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Send a sample of a migration email (free|paid) to a user, using made-up
	 * plan/expiry values and a harmless reset link. Never marks anyone as emailed.
	 *
	 * @return true|WP_Error
	 */
	public static function send_test( $variant, $user ) {
		$variant = self::VARIANT_FREE === $variant ? self::VARIANT_FREE : self::VARIANT_PAID;

		$info = array(
			'slug'        => self::VARIANT_FREE === $variant ? DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_FREE_SLUG : DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_PREMIUM_SLUG,
			'publication' => 'finbiteinsights',
			'plan_raw'    => 'yearly',
			'expiry'      => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
		);

		$subject = '[TEST] ' . self::render_template( self::get_subject( $variant ), $user, $info, true );
		$body    = self::render_template( self::get_body( $variant ), $user, $info, true );

		$sent = self::deliver( $user->user_email, $subject, $body, substr( $subject, 7 ) );

		return $sent ? true : new WP_Error( 'send_failed', 'wp_mail() reported failure.' );
	}

	/** Sends via the WooCommerce-branded template (see emails.php); plain HTML mail if unavailable. */
	protected static function deliver( $to, $subject, $body, $heading ) {
		if ( function_exists( 'drwealth_emails_deliver' ) ) {
			return drwealth_emails_deliver( $to, $subject, $body, $heading );
		}

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		$sent = wp_mail( $to, $subject, $body );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );

		return $sent;
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
	protected static function render_template( $template, $user, $info = null, $test = false ) {
		if ( null === $info ) {
			$info = DrWealth_Substack_Subscriber_Importer::get_effective_membership_info( $user->ID );
		}

		$plan_label = DrWealth_Substack_Subscriber_Importer::get_plan_label( $info['slug'] );

		if ( DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG === $info['slug'] ) {
			$expiry_display   = 'no end date (lifetime access)';
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

		// Instruction for continuing after the imported time runs out. No order or
		// subscription exists for imported members, so they simply buy a plan as normal.
		// Lifetime members don't need it.
		if ( DrWealth_Substack_Subscriber_Importer::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG === $info['slug'] ) {
			$renewal_note = '';
		} else {
			$renewal_note = '<h3>When your subscription time runs out</h3>'
				. '<p>You don’t need to do anything today, and nothing will be charged automatically. When your time is up and you’d like to continue, '
				. 'choose a plan (monthly or yearly) on our <a href="' . esc_url( home_url( '/subscription/' ) ) . '">subscription page</a> and check out using this same email address. '
				. 'Your new plan continues on your existing Dr Wealth account.</p>';
		}

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
			'{reset_link}'      => esc_url( $test ? wp_lostpassword_url() : self::build_reset_link( $user ) ),
			'{renewal_note}'    => $renewal_note,
			'{login_url}'       => esc_url( wp_login_url() ),
			'{subscription_url}' => esc_url( home_url( '/subscription/' ) ),
			'{brain_url}'        => esc_url( function_exists( 'drwealth_emails_brain_url' ) ? drwealth_emails_brain_url() : home_url( '/brain/' ) ),
			'{free_unlocks}'    => function_exists( 'drwealth_emails_unlock_allowance' ) ? (int) drwealth_emails_unlock_allowance()['limit'] : 3,
			'{unlock_period}'   => function_exists( 'drwealth_emails_unlock_allowance' ) ? esc_html( drwealth_emails_unlock_allowance()['period'] ) : 'a month',
			'{sender_email}'    => esc_html( function_exists( 'drwealth_emails_sender_email' ) ? drwealth_emails_sender_email() : get_option( 'admin_email' ) ),
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
