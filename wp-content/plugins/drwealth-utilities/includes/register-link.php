<?php
/**
 * Deep link to the registration form.
 *
 * The My Account page shows the login form first, with a "Register" toggle. Linking
 * to  /my-account/?action=register  (or  /my-account/#register ) opens it with the
 * registration form already showing. Use this for "Sign Up" buttons.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_footer',
	function () {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
			return;
		}
		?>
		<script id="drwealth-open-register">
		(function () {
			if ( ! /[?&]action=register\b/.test( location.search ) && '#register' !== location.hash ) {
				return;
			}

			// Same DOM changes the theme's own "Register" toggle makes (it shows the register
			// column, hides the login column, swaps the labels). Done directly, because the
			// theme's click handler isn't attached yet when this runs.
			function openRegister() {
				var login    = document.querySelector( '#customer_login .u-column1' );
				var register = document.querySelector( '#customer_login .u-column2' );

				if ( ! login || ! register ) {
					return;
				}

				// Plain WooCommerce shows both columns side by side: just scroll to the
				// registration form and leave the login form alone.
				if ( 'none' !== window.getComputedStyle( register ).display ) {
					register.scrollIntoView( { block: 'start' } );
					return;
				}

				login.style.display    = 'none';
				register.style.display = 'block';

				// JNews' labels (harmless if the theme doesn't have them).
				document.querySelectorAll( '.singup-account-lable' ).forEach( function ( el ) { el.style.display = 'none'; } );
				document.querySelectorAll( '.singin-account-lable' ).forEach( function ( el ) { el.style.display = 'block'; } );
			}

			openRegister();
			window.addEventListener( 'load', openRegister );
		})();
		</script>
		<?php
	},
	99
);

/** URL for "Sign Up" buttons. */
function drwealth_register_url() {
	$base = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );

	return add_query_arg( 'action', 'register', $base );
}
