<?php
/**
 * Branded fallback image for WooCommerce products that have no image.
 *
 * WooCommerce shows its grey placeholder wherever a product image is expected
 * (order emails, cart, checkout, My Account). This swaps it for a Dr Wealth tile
 * (assets/product-placeholder.png). Products with their own image are unaffected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'woocommerce_placeholder_img_src',
	function () {
		return plugins_url( 'assets/product-placeholder.png', dirname( __FILE__ ) );
	}
);
