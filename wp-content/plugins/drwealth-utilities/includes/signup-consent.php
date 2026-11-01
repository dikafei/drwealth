<?php
/**
 * PDPA marketing-consent checkbox on the account sign-up form.
 *
 * - My Account > Register: checkbox, REQUIRED by default (Settings > Sign-up Consent can
 *   make it optional). Registering = joining the free newsletter, so the box says so.
 * - Checkout "create an account": same box, never required (buying isn't conditional on
 *   marketing consent).
 * - The consent is recorded on the user: when, the exact wording, and the form it came from.
 *   Shown on the user's profile.
 * - Other plugins (e.g. the Kit integration that adds Free sign-ups to the newsletter list)
 *   can hook  do_action( 'drwealth_marketing_consent_given', $user_id, $source ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DRWEALTH_CONSENT_OPTION = 'drwealth_signup_consent_settings';
const DRWEALTH_CONSENT_META   = '_drwealth_marketing_consent';

function drwealth_consent_settings() {
	$privacy = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';

	return wp_parse_args(
		get_option( DRWEALTH_CONSENT_OPTION, array() ),
		array(
			'enabled'  => 1,
			'required' => 1,
			'label'    => 'I agree to receive the Dr Wealth newsletter and occasional emails about Dr Wealth articles and plans, and for my email address to be used for this. I can unsubscribe at any time.'
				. ( $privacy ? ' See our <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">Privacy Policy</a>.' : '' ),
		)
	);
}

/* ---------------------------------------------------------------- Settings */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Sign-up Consent', 'Sign-up Consent', 'manage_options', 'drwealth-signup-consent', 'drwealth_consent_render_settings' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'drwealth_signup_consent',
			DRWEALTH_CONSENT_OPTION,
			array(
				'sanitize_callback' => function ( $in ) {
					$d = drwealth_consent_settings();

					return array(
						'enabled'  => empty( $in['enabled'] ) ? 0 : 1,
						'required' => empty( $in['required'] ) ? 0 : 1,
						'label'    => wp_kses( isset( $in['label'] ) ? $in['label'] : $d['label'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array(), 'em' => array() ) ),
					);
				},
			)
		);
	}
);

function drwealth_consent_render_settings() {
	$s = drwealth_consent_settings();
	$n = DRWEALTH_CONSENT_OPTION;
	?>
	<div class="wrap">
		<h1>Sign-up Consent (PDPA)</h1>
		<p>A marketing-consent checkbox on the sign-up form. The time and exact wording are saved on each user.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'drwealth_signup_consent' ); ?>
			<table class="form-table">
				<tr><th>Show checkbox</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> On My Account sign-up and checkout account creation</label></td></tr>
				<tr><th>Required</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1" <?php checked( $s['required'] ); ?> /> Must be ticked to register on My Account (never required at checkout)</label></td></tr>
				<tr><th>Wording</th><td><textarea class="large-text" rows="4" name="<?php echo esc_attr( $n ); ?>[label]"><?php echo esc_textarea( $s['label'] ); ?></textarea><p class="description">Links (&lt;a&gt;) allowed.</p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------- Front end */

function drwealth_consent_field( $required ) {
	$s = drwealth_consent_settings();
	?>
	<p class="woocommerce-form-row form-row dw-consent">
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display:flex;gap:8px;align-items:flex-start;">
			<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="dw_marketing_consent" value="1" <?php echo $required ? 'required' : ''; ?> <?php checked( ! empty( $_POST['dw_marketing_consent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification ?> />
			<span><?php echo wp_kses_post( $s['label'] ); ?><?php echo $required ? ' <span class="required">*</span>' : ''; ?></span>
		</label>
	</p>
	<?php
}

// My Account > Register.
add_action(
	'woocommerce_register_form',
	function () {
		$s = drwealth_consent_settings();

		if ( $s['enabled'] ) {
			drwealth_consent_field( (bool) $s['required'] );
		}
	},
	20
);

add_filter(
	'woocommerce_registration_errors',
	function ( $errors ) {
		$s = drwealth_consent_settings();

		// Only the My Account form posts the "register" field; checkout account creation does not come through here.
		if ( $s['enabled'] && $s['required'] && isset( $_POST['register'] ) && empty( $_POST['dw_marketing_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$errors->add( 'dw_consent_required', 'Please tick the box to agree to receive our newsletter and emails. It is how your free account works.' );
		}

		return $errors;
	}
);

// Checkout "Create an account?" (guests only). Optional.
add_action(
	'woocommerce_after_checkout_registration_form',
	function () {
		$s = drwealth_consent_settings();

		if ( $s['enabled'] && ! is_user_logged_in() ) {
			drwealth_consent_field( false );
		}
	}
);

/* ------------------------------------------------------------------ Record */

add_action(
	'woocommerce_created_customer',
	function ( $customer_id ) {
		$s = drwealth_consent_settings();

		if ( ! $s['enabled'] || empty( $_POST['dw_marketing_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$source = isset( $_POST['register'] ) ? 'my-account-register' : 'checkout'; // phpcs:ignore WordPress.Security.NonceVerification

		update_user_meta(
			$customer_id,
			DRWEALTH_CONSENT_META,
			array(
				'time'   => current_time( 'mysql' ),
				'text'   => wp_strip_all_tags( $s['label'] ),
				'source' => $source,
			)
		);

		do_action( 'drwealth_marketing_consent_given', $customer_id, $source );
	},
	5
);

/** Show it on the user's profile (read only). */
function drwealth_consent_profile( $user ) {
	$c = get_user_meta( $user->ID, DRWEALTH_CONSENT_META, true );
	?>
	<h2>Marketing consent (PDPA)</h2>
	<table class="form-table">
		<tr>
			<th>Consent</th>
			<td>
				<?php if ( is_array( $c ) && ! empty( $c['time'] ) ) : ?>
					Given <?php echo esc_html( $c['time'] ); ?> via <?php echo esc_html( $c['source'] ); ?>.<br />
					<em><?php echo esc_html( $c['text'] ); ?></em>
				<?php else : ?>
					No consent recorded at sign-up (older account or imported subscriber).
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'drwealth_consent_profile' );
add_action( 'edit_user_profile', 'drwealth_consent_profile' );
