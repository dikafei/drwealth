<?php
/**
 * Plugin Name: DrWealth Member Tools
 * Description: Substack migration (posts, subscribers, migration email) plus the site's signup and Premium emails, in one place.
 * Version: 0.1.0
 * Author: DrWealth
 * Text Domain: drwealth-substack-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'DRWEALTH_SUBSTACK_SYNC_VERSION', '0.1.0' );
define( 'DRWEALTH_SUBSTACK_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once DRWEALTH_SUBSTACK_SYNC_DIR . 'includes/class-drwealth-substack-zip-helper.php';
require_once DRWEALTH_SUBSTACK_SYNC_DIR . 'includes/class-drwealth-substack-importer.php';
require_once DRWEALTH_SUBSTACK_SYNC_DIR . 'includes/class-drwealth-substack-subscriber-importer.php';
require_once DRWEALTH_SUBSTACK_SYNC_DIR . 'includes/class-drwealth-substack-mailer.php';
require_once DRWEALTH_SUBSTACK_SYNC_DIR . 'includes/emails.php';
require_once DRWEALTH_SUBSTACK_SYNC_DIR . 'includes/class-drwealth-substack-admin.php';

add_action( 'plugins_loaded', array( 'DrWealth_Substack_Admin', 'init' ) );
