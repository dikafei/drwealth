<?php
/**
 * Membership status pill (Free / Premium / Insiders) next to the logged-in
 * user's name in the header account link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Highest active plan for the user: insiders | premium | free. */
function drwealth_status_pill_level( $user_id ) {
	if ( ! function_exists( 'wc_memberships_is_user_active_member' ) ) {
		return 'free';
	}

	if ( wc_memberships_is_user_active_member( $user_id, 'insiders-membership' ) ) {
		return 'insiders';
	}

	if ( wc_memberships_is_user_active_member( $user_id, 'premium-membership' )
		|| wc_memberships_is_user_active_member( $user_id, 'premium-lifetime-membership' ) ) {
		return 'premium';
	}

	return 'free'; // Free plan, lapsed paid plan, or no plan.
}

add_action(
	'wp_head',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
		<style>
			.jeg_accountlink a.logged{white-space:nowrap}.jeg_accountlink a.logged img.avatar{display:none}.dw-account-stack{display:inline-flex;flex-direction:row;align-items:center;gap:6px;vertical-align:middle;margin-left:0;line-height:1.15}.dw-account-name{display:block;max-width:110px;overflow:hidden;text-overflow:ellipsis}.dw-status-pill{display:inline-block;margin:0;padding:0 6px;border-radius:999px;font-size:10px;font-weight:700;line-height:16px;letter-spacing:.02em;text-transform:uppercase;color:#fff;vertical-align:middle}
			.dw-status-pill--free{background:#6b7280}
			.dw-status-pill--premium{background:#1d4ed8}
			.dw-status-pill--insiders{background:#D26133}
		</style>
		<?php
	}
);

add_action(
	'template_redirect',
	function () {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$level  = drwealth_status_pill_level( get_current_user_id() );
		$labels = array(
			'free'     => 'Free',
			'premium'  => 'Premium',
			'insiders' => 'Insiders',
		);
		$pill   = '<span class="dw-status-pill dw-status-pill--' . $level . '">' . $labels[ $level ] . '</span>';

		ob_start(
			function ( $html ) use ( $pill ) {
				if ( false !== strpos( $html, 'dw-status-pill dw-status-pill--' ) ) {
					return $html;
				}

				// Inside every <a class="logged"> of the header account menu, before </a>.
				$out = preg_replace_callback(
					'#(<a\b[^>]*class="[^"]*\blogged\b[^"]*"[^>]*>)(.*?)(</a>)#s',
					function ( $m ) use ( $pill ) {
						// Name and pill side by side.
						$inner = preg_replace( '#(>)([^<>]*\S[^<>]*)$#', '$1<span class="dw-account-stack"><span class="dw-account-name">$2</span>' . $pill . '</span>', $m[2], 1, $n );

						if ( ! $n ) {
							$inner = $m[2] . '<span class="dw-account-stack">' . $pill . '</span>';
						}

						return $m[1] . $inner . $m[3];
					},
					$html
				);

				return null !== $out ? $out : $html;
			}
		);
	},
	2
);

/**
 * Desktop header spacing for everyone (logged in or out): the right column
 * (dark mode, search, Join Newsletter, Login/Register or account) was a few
 * pixels too wide for its slot and wrapped onto a second row.
 */
add_action(
	'wp_head',
	function () {
		?>
		<style>
			@media (min-width:1025px){
				.jeg_navbar .jeg_nav_right .item_wrap{flex-wrap:nowrap}
				.jeg_navbar .jeg_nav_right .jeg_nav_item.jeg_button_1 .btn{padding-left:12px;padding-right:12px}
				.jeg_navbar .jeg_nav_right .jeg_nav_item.jeg_search_wrapper{padding-left:8px;padding-right:8px}
				.jeg_navbar .jeg_nav_right .jeg_nav_item.jeg_dark_mode{padding-right:8px}
				.jeg_navbar .jeg_nav_right .jeg_nav_item.jeg_nav_account{padding-left:8px}
			}
			/* Narrower desktops: drop the dark-mode toggle and tighten logo / menu / button. */
			@media (min-width:1025px) and (max-width:1279px){
				.jeg_navbar .jeg_nav_right .jeg_dark_mode{display:none}
				.jeg_navbar .jeg_desktop_logo img{max-width:105px;height:auto}
				.jeg_navbar .jeg_nav_left,.jeg_navbar .jeg_nav_right{flex:0 0 auto}
				.jeg_navbar .jeg_nav_center{flex:1 1 auto;min-width:0}
				.jeg_navbar .jeg_main_menu{white-space:nowrap}
				.jeg_navbar .jeg_main_menu>li{margin-right:4px}
				.jeg_navbar .jeg_main_menu>li>a{font-size:12.5px}
				.jeg_navbar .jeg_nav_right .jeg_nav_item.jeg_button_1{padding-left:4px;padding-right:4px}
				.jeg_navbar .jeg_nav_right .jeg_button_1 .btn{font-size:11px;letter-spacing:.5px;padding-left:10px;padding-right:10px}
				.jeg_navbar .jeg_nav_right .jeg_nav_account{font-size:13px}
			}
		</style>
		<?php
	}
);
