<?php
/**
 * Highlight the publication categories (Finbite Insights, Growth Dragons) as
 * coloured "pills" in the category line on single posts, so readers can see at a
 * glance which publication an article comes from.
 *
 * Theme independent: pills are applied to category links inside the post's category
 * line, found by common class names (JNews, core Post Terms block, Astra/GeneratePress/
 * Kadence-style cat-links...). Add more with the drwealth_category_pill_containers filter.
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
			.single a.dw-pill:not(.tag) {
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
			.single a.dw-pill.dw-pill--finbite {
				background: #dbeafe;
				color: #1e40af;
				border-color: #bfdbfe;
			}
			.single a.dw-pill.dw-pill--growth {
				background: #fee2e2;
				color: #991b1b;
				border-color: #fecaca;
			}
			.single a.dw-pill:hover {
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
				$containers = apply_filters(
					'drwealth_category_pill_containers',
					array( 'jeg_meta_category', 'tags', 'cat-links', 'post-categories', 'entry-categories', 'meta-category', 'taxonomy-category', 'wp-block-post-terms', 'post-meta-categories', 'entry-meta-categories' )
				);
				$class_re   = implode( '|', array_map( 'preg_quote', $containers ) );

				if ( ! preg_match( '#class="[^"]*(?:' . $class_re . ')#', $html ) ) {
					return $html;
				}

				$map = drwealth_pills_map();

				// Work only inside each category meta block.
				return preg_replace_callback(
					'#<(div|span|ul|p)\b[^>]*class="[^"]*(?:' . $class_re . ')[^"]*"[^>]*>.*?</\1>#is',
					function ( $block ) use ( $map ) {
						// $block[0] is the whole container (group 1 is just its tag name).
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
