<?php
/**
 * Membership status pill (Free / Premium / Brain), theme independent.
 *
 * Where it shows:
 *  - shortcode  [drwealth_status_pill]   (put it in any header/menu/widget that runs shortcodes)
 *  - nav menus: appended to the "My Account" menu item, or to any menu item given the
 *    CSS class  dw-status-pill-item  (Appearance > Menus > Screen Options > CSS classes)
 *  - the header's Log in / Members / My account link (GeneratePress child theme "drwealth":
 *    <header class="dw-top"> ... <a class="login">). Injected into the final HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plan slug => level. Both the old (insiders-*) and new (brain-*) slugs are listed. */
function drwealth_status_pill_plans() {
	return apply_filters(
		'drwealth_status_pill_plans',
		array(
			'brain'   => array( 'brain-membership', 'insiders-membership' ),
			'premium' => array( 'premium-membership', 'premium-lifetime-membership' ),
		)
	);
}

/** Highest active plan for the user: brain | premium | free. */
function drwealth_status_pill_level( $user_id ) {
	if ( function_exists( 'wc_memberships_is_user_active_member' ) ) {
		foreach ( drwealth_status_pill_plans() as $level => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( wc_memberships_is_user_active_member( $user_id, $slug ) ) {
					return $level;
				}
			}
		}
	}

	return 'free'; // Free plan, lapsed paid plan, or no plan.
}

function drwealth_status_pill_html( $user_id ) {
	$level  = drwealth_status_pill_level( $user_id );
	$labels = array(
		'free'    => 'Free',
		'premium' => 'Premium',
		'brain'   => 'Brain',
	);

	return '<span class="dw-status-pill dw-status-pill--' . esc_attr( $level ) . '">' . esc_html( $labels[ $level ] ) . '</span>';
}

add_shortcode(
	'drwealth_status_pill',
	function () {
		return is_user_logged_in() ? drwealth_status_pill_html( get_current_user_id() ) : '';
	}
);

add_action(
	'wp_head',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
		<style>
			.dw-status-pill{display:inline-block;margin-left:6px;padding:0 6px;border-radius:999px;font-size:10px;font-weight:700;line-height:16px;letter-spacing:.02em;text-transform:uppercase;color:#fff;vertical-align:middle}
			.dw-top a.login{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
			.dw-top a.login .dw-status-pill{margin-left:0}
			.dw-status-pill--free{background:#6b7280}
			.dw-status-pill--premium{background:#1d4ed8}
			.dw-status-pill--brain{background:#D26133}
		</style>
		<?php
	}
);

/** Append the pill to the My Account (or dw-status-pill-item) menu item. */
add_filter(
	'nav_menu_item_title',
	function ( $title, $item ) {
		if ( ! is_user_logged_in() || is_admin() ) {
			return $title;
		}

		$classes = is_array( $item->classes ) ? $item->classes : array();
		$account = function_exists( 'wc_get_page_permalink' ) ? trailingslashit( wc_get_page_permalink( 'myaccount' ) ) : '';
		$is_acct = $account && trailingslashit( (string) $item->url ) === $account && 0 === (int) $item->menu_item_parent;

		if ( in_array( 'dw-status-pill-item', $classes, true ) || $is_acct ) {
			$title .= drwealth_status_pill_html( get_current_user_id() );
		}

		return $title;
	},
	10,
	2
);

/**
 * Add the pill after the text of the header's account link (logged in only).
 * Looks inside the first <header> for a link classed login / account / my-account.
 */
add_action(
	'template_redirect',
	function () {
		if ( is_admin() || ! is_user_logged_in() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$pill = drwealth_status_pill_html( get_current_user_id() );

		ob_start(
			function ( $html ) use ( $pill ) {
				if ( false !== strpos( $html, 'dw-status-pill dw-status-pill--' ) ) {
					return $html;
				}

				return preg_replace_callback(
					'#<header\b.*?</header>#is',
					function ( $header ) use ( $pill ) {
						$done = false;

						return preg_replace_callback(
							'#(<a\b[^>]*class="[^"]*\b(?:login|account|my-account)\b[^"]*"[^>]*>)(.*?)(</a>)#is',
							function ( $m ) use ( $pill, &$done ) {
								if ( $done ) {
									return $m[0];
								}
								$done = true;

								return $m[1] . $m[2] . $pill . $m[3];
							},
							$header[0]
						);
					},
					$html,
					1
				);
			}
		);
	},
	2
);
