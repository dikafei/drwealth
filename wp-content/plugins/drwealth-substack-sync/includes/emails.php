<?php
/**
 * Customer emails:
 *   - "signup"  : sent when someone creates a free account (WooCommerce signup).
 *   - "premium" : sent when someone's Premium membership becomes active.
 *   - "brain"   : sent when someone's Brain membership becomes active.
 *
 * Imported Substack subscribers never get these (they get the migration email
 * from the Substack Sync plugin instead): users with the Substack publications
 * meta are skipped.
 *
 * Edit under Tools > Member Tools > Site Emails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DRWEALTH_EMAILS_OPTION        = 'drwealth_emails_settings';
const DRWEALTH_EMAILS_SENT_META     = '_drwealth_signup_email_sent';
const DRWEALTH_EMAILS_PREMIUM_META  = '_drwealth_premium_email_sent';
const DRWEALTH_EMAILS_BRAIN_META    = '_drwealth_brain_email_sent';
const DRWEALTH_EMAILS_PREMIUM_PLANS = array( 'premium-membership', 'premium-lifetime-membership' );
// Brain (top tier). The plan slug is still insiders-membership on staging; it will become
// brain-membership. Both are listed so the rename can happen in either order.
const DRWEALTH_EMAILS_BRAIN_PLANS   = array( 'brain-membership', 'insiders-membership' );

function drwealth_emails_defaults() {
	return array(
		'replace_woo_new_account' => 1,
		'signup_enabled'          => 1,
		'signup_subject'          => 'Welcome to Dr Wealth',
		'signup_body'             => '<p>Hi {first_name},</p>
<p>Thanks for signing up. Your free Dr Wealth account is ready.</p>
<p>Every Sunday morning, our free newsletter lands in your inbox with what moved in the markets and what we think.</p>
<p>You can also read {free_unlocks} Premium {unlock_articles} {unlock_period}, on us. Look for the Premium tag and tap Unlock this article.</p>
<p>Not sure where to start? Pick the market you invest in.</p>
<ul>
<li><a href="{site_url}/category/sg/">Singapore stocks</a></li>
<li><a href="{site_url}/category/us/">US stocks</a></li>
<li><a href="{site_url}/category/cn/">China and Hong Kong stocks</a></li>
<li><a href="{site_url}/category/my/">Malaysia stocks</a></li>
</ul>
<p>For a quick read every weekday morning, join our <a href="https://t.me/realDrWealth">Morning TLDR on Telegram</a>.</p>
<p>See you in the inbox,<br>Alvin Chow</p>',
		'premium_enabled'         => 1,
		'premium_subject'         => 'You are in. Welcome to Dr Wealth Premium',
		'premium_body'            => '<p>Hi {first_name},</p>
<p>Thanks for subscribing to Dr Wealth Premium. You now have full access to every article on drwealth.com. Here is what that means.</p>
<ul>
<li>Every Premium article, new and old</li>
<li>New articles in your inbox as they go out</li>
<li>Log in on drwealth.com to read the full article instead of the paywall</li>
</ul>
<p><a href="{premium_url}">Browse Premium articles</a></p>
<p>A few things to know.</p>
<ul>
<li>You manage your plan, card and receipts under My Account at <a href="{my_account_url}">{my_account_url}</a>.</li>
<li>You can cancel any time. You keep access until the end of the period you have paid for.</li>
<li>If an email does not arrive, check your Promotions or Spam folder and add {sender_email} to your contacts.</li>
</ul>
<p>Our articles are general information. We write them without knowing your finances or goals, so they are not a recommendation to buy or sell anything. Use them to make your own decisions.</p>
<p>Happy reading,<br>Alvin Chow</p>',
		'brain_enabled'           => 1,
		'brain_url'               => '',
		'brain_subject'           => 'Welcome to Dr Wealth Brain',
		'brain_body'              => '<p>Hi {first_name},</p>
<p>Welcome to Dr Wealth Brain. Thank you for backing what we do.</p>
<p>Brain is our top tier. You get everything in Premium, plus the extra material that comes with Brain. Start here: <a href="{brain_url}">What is in Dr Wealth Brain</a>.</p>
<p>Every Premium article is open to you too. <a href="{premium_url}">Browse Premium articles</a>.</p>
<p>A few things to know.</p>
<ul>
<li>You manage your plan, card and receipts under My Account at <a href="{my_account_url}">{my_account_url}</a>.</li>
<li>You can cancel any time. You keep access until the end of the period you have paid for.</li>
<li>If an email does not arrive, check your Promotions or Spam folder and add {sender_email} to your contacts.</li>
</ul>
<p>Our articles are general information. We write them without knowing your finances or goals, so they are not a recommendation to buy or sell anything. Use them to make your own decisions.</p>
<p>Happy reading,<br>Alvin Chow</p>',
	);
}

const DRWEALTH_EMAILS_SENDER_OPTION = 'drwealth_emails_sender';

/** Shared sender (From) for every email in this plugin: site emails + migration emails. */
function drwealth_emails_sender() {
	return wp_parse_args(
		get_option( DRWEALTH_EMAILS_SENDER_OPTION, array() ),
		array(
			'from_name'  => '',
			'from_email' => '',
		)
	);
}

/**
 * What WP Mail SMTP is set up to send as (if the plugin is active). With the Google
 * (Gmail API) mailer, Google only lets mail go out as the connected account / its aliases,
 * and DMARC is p=reject, so any other From address would be rewritten or rejected.
 *
 * @return array{active: bool, mailer: string, from_email: string, from_name: string, force_email: bool}
 */
function drwealth_emails_smtp_info() {
	$info = array(
		'active'      => false,
		'mailer'      => '',
		'from_email'  => '',
		'from_name'   => '',
		'force_email' => false,
	);

	if ( class_exists( '\\WPMailSMTP\\Options' ) ) {
		$o = \WPMailSMTP\Options::init();

		$info['active']      = true;
		$info['mailer']      = (string) $o->get( 'mail', 'mailer' );
		$info['from_email']  = (string) $o->get( 'mail', 'from_email' );
		$info['from_name']   = (string) $o->get( 'mail', 'from_name' );
		$info['force_email'] = (bool) $o->get( 'mail', 'from_email_force' );
	}

	return $info;
}

/** True when the From address is dictated by WP Mail SMTP, not by our Sender tab. */
function drwealth_emails_sender_locked() {
	$smtp = drwealth_emails_smtp_info();

	return $smtp['active'] && '' !== $smtp['from_email'] && ( 'gmail' === $smtp['mailer'] || $smtp['force_email'] );
}

function drwealth_emails_sender_email() {
	// Google mailer / forced From: use exactly what WP Mail SMTP sends as, so our From,
	// the {sender_email} in email bodies and the real envelope all agree.
	if ( drwealth_emails_sender_locked() ) {
		return drwealth_emails_smtp_info()['from_email'];
	}

	$sender = drwealth_emails_sender();

	return $sender['from_email'] ? $sender['from_email'] : get_option( 'admin_email' );
}

function drwealth_emails_from_email_filter() {
	return drwealth_emails_sender_email();
}

function drwealth_emails_from_name_filter( $name ) {
	$sender = drwealth_emails_sender();

	return $sender['from_name'] ? $sender['from_name'] : $name;
}

/** Apply the shared From name/address; call the *_remove() right after wp_mail(). */
function drwealth_emails_sender_apply() {
	add_filter( 'wp_mail_from', 'drwealth_emails_from_email_filter', 99 );
	add_filter( 'wp_mail_from_name', 'drwealth_emails_from_name_filter', 99 );
}

function drwealth_emails_sender_remove() {
	remove_filter( 'wp_mail_from', 'drwealth_emails_from_email_filter', 99 );
	remove_filter( 'wp_mail_from_name', 'drwealth_emails_from_name_filter', 99 );
}

function drwealth_emails_settings() {
	return wp_parse_args( get_option( DRWEALTH_EMAILS_OPTION, array() ), drwealth_emails_defaults() );
}

/* ----------------------------------------------------------------- Sending */

function drwealth_emails_is_imported_user( $user_id ) {
	return (bool) get_user_meta( $user_id, '_drwealth_substack_publications', true );
}

/**
 * Free-unlock allowance from Settings > Free Unlocks (drwealth-utilities), so emails
 * always quote the real number. Falls back to 3 a month if that plugin isn't active.
 */
function drwealth_emails_unlock_allowance() {
	if ( function_exists( 'drwealth_unlocks_settings' ) ) {
		$u = drwealth_unlocks_settings();

		return array(
			'limit'  => (int) $u['limit'],
			'period' => 'total' === $u['period'] ? 'in total' : 'a month',
		);
	}

	return array(
		'limit'  => 3,
		'period' => 'a month',
	);
}

/** Where "{brain_url}" points: Site Emails > Brain page URL, default /brain/. */
function drwealth_emails_brain_url() {
	$s = drwealth_emails_settings();

	return $s['brain_url'] ? $s['brain_url'] : home_url( '/brain/' );
}

function drwealth_emails_render( $template, $user, $extra = array() ) {
	$s          = drwealth_emails_settings();
	$first_name = $user->first_name ? $user->first_name : $user->display_name;

	$premium_cat = get_category_by_slug( 'premium' );
	$premium_url = $premium_cat ? get_category_link( $premium_cat ) : home_url( '/' );
	$sender      = drwealth_emails_sender_email();

	$map = array_merge(
		array(
			'{first_name}'     => esc_html( $first_name ),
			'{email}'          => esc_html( $user->user_email ),
			'{premium_url}'    => esc_url( $premium_url ),
			'{my_account_url}' => esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ) ),
			'{sender_email}'   => esc_html( $sender ),
			'{free_unlocks}'   => (int) drwealth_emails_unlock_allowance()['limit'],
			'{unlock_articles}' => 1 === (int) drwealth_emails_unlock_allowance()['limit'] ? 'article' : 'articles',
			'{unlock_period}'  => esc_html( drwealth_emails_unlock_allowance()['period'] ),
			'{brain_url}'      => esc_url( drwealth_emails_brain_url() ),
			'{site_url}'       => esc_url( untrailingslashit( home_url() ) ),
			'{site_name}'      => esc_html( get_bloginfo( 'name' ) ),
		),
		$extra
	);

	return strtr( $template, $map );
}

/**
 * Send an HTML email wrapped in the WooCommerce email template (logo, colours and
 * footer from WooCommerce > Settings > Emails), so every email on the site looks
 * the same. Falls back to a plain HTML wp_mail() if WooCommerce isn't available.
 * Applies the shared From name/address from the Sender tab.
 *
 * @return bool
 */
function drwealth_emails_deliver( $to, $subject, $body, $heading = '' ) {
	$subject = wp_strip_all_tags( $subject );
	$heading = '' !== $heading ? $heading : $subject;

	drwealth_emails_sender_apply();

	if ( function_exists( 'WC' ) && WC() && WC()->mailer() ) {
		$mailer  = WC()->mailer();
		$wrapped = $mailer->wrap_message( $heading, $body );
		$sent    = $mailer->send( $to, $subject, $wrapped, "Content-Type: text/html; charset=UTF-8\r\n" );
	} else {
		$html = function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $html );
		$sent = wp_mail( $to, $subject, $body );
		remove_filter( 'wp_mail_content_type', $html );
	}

	drwealth_emails_sender_remove();

	return (bool) $sent;
}

function drwealth_emails_send( $user, $subject, $body ) {
	// Never send an email that still has an unfilled "[Add your ...]" placeholder.
	if ( false !== stripos( $body, '[Add your' ) ) {
		return false;
	}

	return drwealth_emails_deliver( $user->user_email, $subject, $body );
}

/** 10) Free signup. */
add_action(
	'woocommerce_created_customer',
	function ( $customer_id, $new_customer_data = array(), $password_generated = false ) {
		$s = drwealth_emails_settings();

		if ( ! $s['signup_enabled'] || drwealth_emails_is_imported_user( $customer_id ) || get_user_meta( $customer_id, DRWEALTH_EMAILS_SENT_META, true ) ) {
			return;
		}

		$user = get_userdata( $customer_id );

		if ( ! $user ) {
			return;
		}

		$body = drwealth_emails_render( $s['signup_body'], $user );

		// Password was auto-generated (e.g. created at checkout): give them a way to set one.
		if ( $password_generated ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$link  = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
				$body .= '<p>Set your password here: <a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
			}
		}

		if ( drwealth_emails_send( $user, drwealth_emails_render( $s['signup_subject'], $user ), $body ) ) {
			update_user_meta( $customer_id, DRWEALTH_EMAILS_SENT_META, current_time( 'mysql' ) );
		}
	},
	10,
	3
);

// Replace WooCommerce's own new-account email with ours.
add_filter(
	'woocommerce_email_enabled_customer_new_account',
	function ( $enabled ) {
		$s = drwealth_emails_settings();

		return ( $s['replace_woo_new_account'] && $s['signup_enabled'] ) ? false : $enabled;
	}
);

/**
 * 11) Premium / Brain membership becomes active: send that tier's welcome email.
 * Which email: by the plan's slug. Sent once per membership, and again whenever the same
 * membership becomes active again (e.g. paid again after a lapse).
 */
function drwealth_emails_membership_type( $plan_slug ) {
	if ( in_array( $plan_slug, DRWEALTH_EMAILS_BRAIN_PLANS, true ) ) {
		return 'brain';
	}

	if ( in_array( $plan_slug, DRWEALTH_EMAILS_PREMIUM_PLANS, true ) ) {
		return 'premium';
	}

	return '';
}

function drwealth_emails_maybe_send_premium( $user_membership, $force = false ) {
	$s = drwealth_emails_settings();

	if ( ! is_object( $user_membership ) || ! method_exists( $user_membership, 'get_status' ) ) {
		return;
	}

	if ( ! in_array( $user_membership->get_status(), array( 'active', 'complimentary', 'free_trial' ), true ) ) {
		return;
	}

	$plan = $user_membership->get_plan();
	$type = $plan ? drwealth_emails_membership_type( $plan->get_slug() ) : '';

	if ( ! $type || empty( $s[ $type . '_enabled' ] ) ) {
		return;
	}

	$meta    = 'brain' === $type ? DRWEALTH_EMAILS_BRAIN_META : DRWEALTH_EMAILS_PREMIUM_META;
	$user_id = $user_membership->get_user_id();

	if ( drwealth_emails_is_imported_user( $user_id ) ) {
		return;
	}

	// Once per membership by default; $force = the membership just (re)became active
	// (e.g. paid again after a lapse), so send again. The 2 minute guard stops the
	// several Memberships hooks firing for one event from sending twice.
	$last = (int) strtotime( (string) get_user_meta( $user_id, $meta, true ) );
	$mid  = (int) $user_membership->get_id();

	if ( $last && ( time() - $last ) < 120 ) {
		return;
	}

	if ( ! $force && get_post_meta( $mid, $meta, true ) ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( $user && drwealth_emails_send( $user, drwealth_emails_render( $s[ $type . '_subject' ], $user ), drwealth_emails_render( $s[ $type . '_body' ], $user ) ) ) {
		update_user_meta( $user_id, $meta, current_time( 'mysql' ) );
		update_post_meta( $mid, $meta, current_time( 'mysql' ) );
	}
}

// A membership that is trashed/deleted or leaves "active" is forgotten, so when it
// comes back (re-subscribe may restore the same record) the email goes out again.
add_action(
	'wp_trash_post',
	function ( $post_id ) {
		if ( 'wc_user_membership' === get_post_type( $post_id ) ) {
			delete_post_meta( $post_id, DRWEALTH_EMAILS_PREMIUM_META );
			delete_post_meta( $post_id, DRWEALTH_EMAILS_BRAIN_META );
		}
	}
);
add_action(
	'untrashed_post',
	function ( $post_id ) {
		if ( 'wc_user_membership' === get_post_type( $post_id ) && function_exists( 'wc_memberships_get_user_membership' ) ) {
			drwealth_emails_maybe_send_premium( wc_memberships_get_user_membership( $post_id ), true );
		}
	}
);

// Status change (e.g. paused/pending -> active).
add_action(
	'wc_memberships_user_membership_status_changed',
	function ( $user_membership, $old_status = '', $new_status = '' ) {
		// Became active from any other state (lapsed, expired, paused, new): always send.
		if ( 'active' === $new_status && 'active' !== $old_status ) {
			drwealth_emails_maybe_send_premium( $user_membership, true );
		} elseif ( 'active' !== $new_status && is_object( $user_membership ) ) {
			delete_post_meta( $user_membership->get_id(), DRWEALTH_EMAILS_PREMIUM_META );
			delete_post_meta( $user_membership->get_id(), DRWEALTH_EMAILS_BRAIN_META );
		}
	},
	20,
	3
);

// Brand-new membership created already active (no status change fires), and any save.
add_action(
	'wc_memberships_user_membership_saved',
	function ( $plan, $args = array() ) {
		$id = isset( $args['user_membership_id'] ) ? (int) $args['user_membership_id'] : 0;

		if ( $id && function_exists( 'wc_memberships_get_user_membership' ) ) {
			drwealth_emails_maybe_send_premium( wc_memberships_get_user_membership( $id ) );
		}
	},
	20,
	2
);

/**
 * Memberships are virtual and auto-complete, so Woo's "order received" (processing)
 * email is redundant next to the "order complete" one. Skip it for orders that
 * contain only virtual items.
 */
add_filter(
	'woocommerce_email_enabled_customer_processing_order',
	function ( $enabled, $order ) {
		if ( ! $enabled || ! $order instanceof WC_Order ) {
			return $enabled;
		}

		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;

			if ( ! $product || ! $product->is_virtual() ) {
				return $enabled; // Physical goods: keep the normal email.
			}
		}

		return false;
	},
	10,
	2
);

/* ---------------------------------------------------------------- Settings */

add_action(
	'admin_init',
	function () {
		register_setting(
			'drwealth_emails_sender',
			DRWEALTH_EMAILS_SENDER_OPTION,
			array(
				'sanitize_callback' => function ( $in ) {
					return array(
						'from_name'  => sanitize_text_field( isset( $in['from_name'] ) ? $in['from_name'] : '' ),
						'from_email' => sanitize_email( isset( $in['from_email'] ) ? $in['from_email'] : '' ),
					);
				},
			)
		);
		register_setting(
			'drwealth_emails',
			DRWEALTH_EMAILS_OPTION,
			array(
				'sanitize_callback' => function ( $in ) {
					$d = drwealth_emails_defaults();

					return array(
						'replace_woo_new_account' => empty( $in['replace_woo_new_account'] ) ? 0 : 1,
						'signup_enabled'          => empty( $in['signup_enabled'] ) ? 0 : 1,
						'signup_subject'          => sanitize_text_field( isset( $in['signup_subject'] ) ? $in['signup_subject'] : $d['signup_subject'] ),
						'signup_body'             => wp_kses_post( isset( $in['signup_body'] ) ? $in['signup_body'] : $d['signup_body'] ),
						'premium_enabled'         => empty( $in['premium_enabled'] ) ? 0 : 1,
						'premium_subject'         => sanitize_text_field( isset( $in['premium_subject'] ) ? $in['premium_subject'] : $d['premium_subject'] ),
						'premium_body'            => wp_kses_post( isset( $in['premium_body'] ) ? $in['premium_body'] : $d['premium_body'] ),
						'brain_enabled'           => empty( $in['brain_enabled'] ) ? 0 : 1,
						'brain_url'               => isset( $in['brain_url'] ) ? esc_url_raw( $in['brain_url'] ) : '',
						'brain_subject'           => sanitize_text_field( isset( $in['brain_subject'] ) ? $in['brain_subject'] : $d['brain_subject'] ),
						'brain_body'              => wp_kses_post( isset( $in['brain_body'] ) ? $in['brain_body'] : $d['brain_body'] ),
					);
				},
			)
		);
	}
);

function drwealth_emails_render_settings() {  // Rendered inside the Site Emails tab.
	$s = drwealth_emails_settings();
	$n = DRWEALTH_EMAILS_OPTION;
	?>
	<div>
		<p>Placeholders: <code>{first_name}</code> <code>{email}</code> <code>{premium_url}</code> <code>{my_account_url}</code> <code>{sender_email}</code> <code>{free_unlocks}</code> (number of free Premium unlocks) <code>{unlock_period}</code> ("a month" / "in total") <code>{unlock_articles}</code> ("article" / "articles") <code>{brain_url}</code> <code>{site_url}</code> <code>{site_name}</code>.
		Imported Substack subscribers are never sent these.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'drwealth_emails' ); ?>

			<h2>Free signup email</h2>
			<table class="form-table">
				<tr><th>Enabled</th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[signup_enabled]" value="1" <?php checked( $s['signup_enabled'] ); ?> /> Send on new account</label><br />
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[replace_woo_new_account]" value="1" <?php checked( $s['replace_woo_new_account'] ); ?> /> Replace WooCommerce's default new-account email</label>
				</td></tr>
				<tr><th>Subject</th><td><input type="text" class="large-text" name="<?php echo esc_attr( $n ); ?>[signup_subject]" value="<?php echo esc_attr( $s['signup_subject'] ); ?>" /></td></tr>
				<tr><th>Body</th><td><?php wp_editor( $s['signup_body'], 'dw_signup_body', array( 'textarea_name' => $n . '[signup_body]', 'textarea_rows' => 12, 'media_buttons' => false ) ); ?></td></tr>
			</table>

			<h2>Premium signup email</h2>
			<table class="form-table">
				<tr><th>Enabled</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[premium_enabled]" value="1" <?php checked( $s['premium_enabled'] ); ?> /> Send when a Premium membership becomes active</label></td></tr>
				<tr><th>Subject</th><td><input type="text" class="large-text" name="<?php echo esc_attr( $n ); ?>[premium_subject]" value="<?php echo esc_attr( $s['premium_subject'] ); ?>" /></td></tr>
				<tr><th>Body</th><td><?php wp_editor( $s['premium_body'], 'dw_premium_body', array( 'textarea_name' => $n . '[premium_body]', 'textarea_rows' => 16, 'media_buttons' => false ) ); ?></td></tr>
			</table>

						<h2>Brain signup email</h2>
			<table class="form-table">
				<tr><th>Enabled</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[brain_enabled]" value="1" <?php checked( $s['brain_enabled'] ); ?> /> Send when a Brain membership becomes active</label></td></tr>
				<tr><th>Brain page URL</th><td><input type="url" class="large-text" name="<?php echo esc_attr( $n ); ?>[brain_url]" value="<?php echo esc_attr( $s['brain_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/brain/' ) ); ?>" /><p class="description">What <code>{brain_url}</code> becomes. Blank = <?php echo esc_html( home_url( '/brain/' ) ); ?></p></td></tr>
				<tr><th>Subject</th><td><input type="text" class="large-text" name="<?php echo esc_attr( $n ); ?>[brain_subject]" value="<?php echo esc_attr( $s['brain_subject'] ); ?>" /></td></tr>
				<tr><th>Body</th><td><?php wp_editor( $s['brain_body'], 'dw_brain_body', array( 'textarea_name' => $n . '[brain_body]', 'textarea_rows' => 16, 'media_buttons' => false ) ); ?></td></tr>
			</table>

<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

function drwealth_emails_render_sender() {
	$n      = DRWEALTH_EMAILS_SENDER_OPTION;
	$sender = drwealth_emails_sender();
	?>
	<div>
		<p>The From name and address used for <strong>every</strong> email in this plugin: the signup and Premium emails, and the Substack migration emails.
		Leave blank to use the site defaults (WP Mail SMTP / admin email). The address should match the one WP Mail SMTP is authorised to send from.</p>
			<?php
			$smtp = drwealth_emails_smtp_info();

			if ( ! $smtp['active'] ) :
				?>
				<p><em>WP Mail SMTP is not active here, so mail goes out through the server's default mailer.</em></p>
			<?php elseif ( drwealth_emails_sender_locked() ) : ?>
				<div class="notice notice-info inline"><p>
					<strong>The From address is set by WP Mail SMTP</strong> (mailer: <code><?php echo esc_html( $smtp['mailer'] ); ?></code>): all emails go out as
					<code><?php echo esc_html( $smtp['from_email'] ); ?></code>. <?php echo 'gmail' === $smtp['mailer'] ? 'Google only allows the connected account (or its aliases) as the From address' : 'WP Mail SMTP is set to force this From address'; ?>, so the address below is ignored and <code>{sender_email}</code> uses this one.
					<?php
					$typed = drwealth_emails_sender();
					if ( $typed['from_email'] && strtolower( $typed['from_email'] ) !== strtolower( $smtp['from_email'] ) ) :
						?>
						<br /><strong>Your saved address (<?php echo esc_html( $typed['from_email'] ); ?>) differs and is not being used.</strong> Clear it, or change it in WP Mail SMTP.
					<?php endif; ?>
				</p></div>
			<?php else : ?>
				<p><em>WP Mail SMTP sends as <code><?php echo esc_html( $smtp['from_email'] ); ?></code> by default (mailer <code><?php echo esc_html( $smtp['mailer'] ); ?></code>). The address below must be authorised with that mailer, and its domain must pass SPF/DKIM/DMARC.</em></p>
			<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'drwealth_emails_sender' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="dw_from_name">From name</label></th>
					<td><input type="text" class="regular-text" id="dw_from_name" name="<?php echo esc_attr( $n ); ?>[from_name]" value="<?php echo esc_attr( $sender['from_name'] ); ?>" placeholder="Alvin Chow" /></td>
				</tr>
				<tr>
					<th><label for="dw_from_email">From address</label></th>
					<td>
						<input type="email" class="regular-text" id="dw_from_email" name="<?php echo esc_attr( $n ); ?>[from_email]" value="<?php echo esc_attr( $sender['from_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						<p class="description">Also available in email bodies as <code>{sender_email}</code> (used in the "add … to your contacts" line).</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php drwealth_emails_render_test_form( array( 'signup' => 'Send test: free signup email', 'premium' => 'Send test: Premium signup email', 'brain' => 'Send test: Brain signup email' ), 'site-emails' ); ?>
	</div>
	<?php
}

/**
 * Send a sample of a site email (signup|premium) to a user, marked [TEST].
 * Skips the "[Add your ...]" safety check so unfinished drafts can be previewed.
 *
 * @return bool
 */
function drwealth_emails_send_test( $type, $user ) {
	$s       = drwealth_emails_settings();
	$prefix  = in_array( $type, array( 'premium', 'brain' ), true ) ? $type : 'signup';
	$subject = '[TEST] ' . drwealth_emails_render( $s[ $prefix . '_subject' ], $user );
	$body    = drwealth_emails_render( $s[ $prefix . '_body' ], $user );

	return drwealth_emails_deliver( $user->user_email, $subject, $body, drwealth_emails_render( $s[ $prefix . '_subject' ], $user ) );
}

/** "Send test to me" buttons. $options: array of value => label. */
function drwealth_emails_render_test_form( $options, $return_tab ) {
	$me = wp_get_current_user();
	?>
	<hr />
	<h2>Send a test</h2>
	<p>Sends a sample to <strong><?php echo esc_html( $me->user_email ); ?></strong> (your admin email), subject prefixed [TEST]. Uses the <em>saved</em> templates, so save first. Nobody else is emailed and nothing is marked as sent.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'drwealth_send_test' ); ?>
		<input type="hidden" name="action" value="drwealth_send_test" />
		<input type="hidden" name="return_tab" value="<?php echo esc_attr( $return_tab ); ?>" />
		<?php foreach ( $options as $value => $label ) : ?>
			<button type="submit" name="test_type" value="<?php echo esc_attr( $value ); ?>" class="button"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</form>
	<?php
}
