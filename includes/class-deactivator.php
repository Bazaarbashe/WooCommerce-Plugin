<?php
/**
 * Deactivation logic.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'bbsync_process_job' );
		wp_clear_scheduled_hook( 'bbsync_run_full_sync' );
	}
}

