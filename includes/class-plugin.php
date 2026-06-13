<?php
/**
 * Main plugin bootstrap.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync;

use BazaarBashe\Sync\Admin\Admin_Menu;
use BazaarBashe\Sync\Api\Client;
use BazaarBashe\Sync\Hooks\WooCommerce_Hooks;
use BazaarBashe\Sync\Logger\Logger;
use BazaarBashe\Sync\Models\Log_Repository;
use BazaarBashe\Sync\Models\Product_Map_Repository;
use BazaarBashe\Sync\Models\Settings;
use BazaarBashe\Sync\Queue\Queue_Manager;
use BazaarBashe\Sync\Sync\Product_Payload_Builder;
use BazaarBashe\Sync\Sync\Sync_Service;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Settings model.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Return singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		$this->settings = new Settings();

		$log_repository = new Log_Repository();
		$map_repository = new Product_Map_Repository();
		$logger         = new Logger( $log_repository, $this->settings );
		$client         = new Client( $this->settings, $logger );
		$builder        = new Product_Payload_Builder( $this->settings );
		$sync_service   = new Sync_Service( $client, $builder, $map_repository, $logger, $this->settings );
		$queue_manager  = new Queue_Manager( $sync_service, $logger );

		( new Admin_Menu( $this->settings, $client, $map_repository, $log_repository, $queue_manager ) )->register();
		( new WooCommerce_Hooks( $queue_manager, $this->settings, $logger ) )->register();
		$queue_manager->register();

		add_filter( 'plugin_action_links_' . BBSYNC_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'admin.php?page=bbsync-settings' );

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Settings', 'bazaarbashe-sync' )
			)
		);

		return $links;
	}
}
