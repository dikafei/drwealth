<?php
/**
 * Footer utilities: keep the copyright year current.
 *
 * The JNews footer copyright is a static customizer string ("© Dr Wealth 2025").
 * Instead of editing it every January, rewrite the year in the rendered
 * <p class="copyright"> to the current year.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'template_redirect',
	function () {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start(
			function ( $html ) {
				if ( false === strpos( $html, 'class="copyright"' ) ) {
					return $html;
				}

				$year = wp_date( 'Y' );

				return preg_replace_callback(
					'#(<p[^>]*class="[^"]*\bcopyright\b[^"]*"[^>]*>)(.*?)(</p>)#is',
					function ( $m ) use ( $year ) {
						// Replace any 4-digit year (or a range like 2022-2025) with the current year.
						$text = preg_replace( '/\b(?:19|20)\d{2}(?:\s*[-–]\s*(?:19|20)\d{2})?\b/', $year, $m[2] );
						return $m[1] . $text . $m[3];
					},
					$html
				);
			}
		);
	},
	1
);
