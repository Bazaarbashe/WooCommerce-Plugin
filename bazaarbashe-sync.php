<?php
/**
 * Plugin Name: بازارباشه
 * Plugin URI:  https://bazaarbashe.ir
 * Description: با افزونه بازارباشه می‌توانید محصولات ووکامرس خود را به‌صورت خودکار با پلتفرم بازارباشه همگام‌سازی کنید. افزودن محصول، ویرایش محصول و حذف محصول در ووکامرس به‌صورت خودکار در بازارباشه نیز اعمال می‌شود.
 * Version:     1.0.0
 * Author:      BazaarBashe
 * Author URI:  https://bazaarbashe.ir
 * Text Domain: bazaarbashe-sync
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package BazaarBasheSync
 */

defined( 'ABSPATH' ) || exit;

define( 'BBSYNC_VERSION', '1.0.0' );
define( 'BBSYNC_FILE', __FILE__ );
define( 'BBSYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BBSYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'BBSYNC_BASENAME', plugin_basename( __FILE__ ) );

require_once BBSYNC_PATH . 'includes/class-autoloader.php';
require_once BBSYNC_PATH . 'includes/class-activator.php';
require_once BBSYNC_PATH . 'includes/class-deactivator.php';
require_once BBSYNC_PATH . 'includes/class-plugin.php';

\BazaarBashe\Sync\Autoloader::register();

register_activation_hook( __FILE__, array( '\BazaarBashe\Sync\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\BazaarBashe\Sync\Deactivator', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function() {
		load_plugin_textdomain( 'bazaarbashe-sync', false, dirname( BBSYNC_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		\BazaarBashe\Sync\Plugin::instance()->boot();
	}
);
