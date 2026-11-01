<?php
/**
 * Plugin Name: DrWealth Utilities
 * Description: Small site-specific fixes and tweaks for drwealth.com, one module per file in includes/.
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DRWEALTH_UTILITIES_DIR', plugin_dir_path( __FILE__ ) );

// Add new modules here: one file per feature in includes/.
require_once DRWEALTH_UTILITIES_DIR . 'includes/free-unlocks.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/seo.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/category-pills.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/product-placeholder.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/register-link.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/force-public.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/header-status-pill.php';
require_once DRWEALTH_UTILITIES_DIR . 'includes/signup-consent.php';
