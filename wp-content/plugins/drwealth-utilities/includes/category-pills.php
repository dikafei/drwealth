<?php
/**
 * Highlight the publication categories (Finbite Insights, Growth Dragons) as
 * coloured "pills" in the category line on single posts, so readers can see at a
 * glance which publication an article comes from.
 *
 * Other categories (e.g. United States) are left as normal links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** category slug => pill modifier class. Colours live in the CSS below. */
function drwealth_pills_map() {
	return apply_filters(
		'drwealth_category_pills',
		array(
			'finbite-insights' => 'dw-pill--finbite',
			'growth-dragons'   => 'dw-pill--growth',
		)
	);
}

add_action(
	'wp_head',
	function () {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		?>
		<style id="drwealth-category-pills">
			.single .jeg_meta_category a.dw-pill {
				display: inline-block;
				padding: 2px 12px;
				margin: 0 6px 0 0;
				border-radius: 999px;
				font-size: .85em;
				font-weight: 600;
				line-height: 1.7;
				text-decoration: none;
				border: 1px solid transparent;
			}
			.single .jeg_meta_category a.dw-pill--finbite {
				background: #dbeafe;
				color: #1e40af;
				border-color: #bfdbfe;
			}
			.single .jeg_meta_category a.dw-pill--growth {
				background: #fee2e2;
				color: #991b1b;
				border-color: #fecaca;
			}
			.single .jeg_meta_category a.dw-pill:hover {
				filter: brightness(.95);
			}
		</style>
		<?php
	}
);

add_action(
	'template_redirect',
	function () {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		ob_start(
			function ( $html ) {
				if ( false === strpos( $html, 'jeg_meta_category' ) ) {
					return $html;
				}

				$map = drwealth_pills_map();

				// Work only inside each category meta block.
				return preg_replace_callback(
					'#<div[^>]*class="[^"]*jeg_meta_category[^"]*"[^>]*>.*?</div>#is',
					function ( $block ) use ( $map ) {
						$out = $block[0];

						foreach ( $map as $slug => $class ) {
							$out = preg_replace_callback(
								'#<a\b([^>]*href="[^"]*/category/(?:[^"/]+/)*' . preg_quote( $slug, '#' ) . '/?"[^>]*)>#i',
								function ( $a ) use ( $class ) {
									$attrs = $a[1];

									if ( preg_match( '#\bclass="([^"]*)"#i', $attrs ) ) {
										$attrs = preg_replace( '#\bclass="([^"]*)"#i', 'class="$1 dw-pill ' . $class . '"', $attrs, 1 );
									} else {
										$attrs .= ' class="dw-pill ' . $class . '"';
									}

									return '<a' . $attrs . '>';
								},
								$out
							);
						}

						// Pills carry their own spacing; drop the comma separators between categories.
						return preg_replace( '#</a>\s*,\s*(?=<a\b)#i', '</a> ', $out );
					},
					$html
				);
			}
		);
	},
	2
);
