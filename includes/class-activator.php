<?php
/**
 * Activation logic.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync;

defined( 'ABSPATH' ) || exit;

class Activator {

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$map_table       = $wpdb->prefix . 'bbsync_product_map';
		$log_table       = $wpdb->prefix . 'bbsync_logs';

		$sql_map = "CREATE TABLE {$map_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			woo_product_id bigint(20) unsigned NOT NULL,
			bazaarbashe_product_id bigint(20) unsigned NOT NULL,
			vendor_identifier varchar(191) NOT NULL DEFAULT '',
			last_synced_at datetime NULL,
			last_operation varchar(20) NOT NULL DEFAULT '',
			last_payload_hash varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY woo_product_id (woo_product_id),
			KEY bazaarbashe_product_id (bazaarbashe_product_id)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			woo_product_id bigint(20) unsigned NULL,
			bazaarbashe_product_id bigint(20) unsigned NULL,
			action varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			message text NULL,
			response longtext NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY action (action),
			KEY woo_product_id (woo_product_id)
		) {$charset_collate};";

		dbDelta( $sql_map );
		dbDelta( $sql_log );

		if ( false === get_option( 'bbsync_settings', false ) ) {
			add_option(
				'bbsync_settings',
				array(
					'access_token'       => '',
					'vendor_identifier'  => '',
					'enable_create'      => 'yes',
					'enable_update'      => 'yes',
					'enable_delete'      => 'yes',
					'enable_images'      => 'yes',
					'enable_price'       => 'yes',
					'enable_inventory'   => 'yes',
					'enable_discount'    => 'yes',
					'connection_status'  => 'disconnected',
					'connection_payload' => array(),
					'last_sync_at'       => '',
				),
				false
			);
		}
	}
}

