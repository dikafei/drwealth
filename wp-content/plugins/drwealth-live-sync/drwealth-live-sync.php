<?php
/**
 * Plugin Name: DrWealth Live Sync
 * Description: STAGING only. Pulls new and changed posts from the live site (needs the "DrWealth Live Export" plugin on live): metadata/SEO, taxonomies, authors, comments and images. Runs daily; Tools > Live Sync.
 * Author:      Dika / Jelly Pixel Studio
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/sync.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( DW_SYNC_CRON ) ) {
			wp_schedule_event( time() + 600, 'daily', DW_SYNC_CRON );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( DW_SYNC_CRON );
		wp_clear_scheduled_hook( DW_SYNC_CONT );
	}
);
