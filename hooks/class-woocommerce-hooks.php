<?php
/**
 * WooCommerce event hooks.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Hooks;

use BazaarBashe\Sync\Logger\Logger;
use BazaarBashe\Sync\Models\Settings;
use BazaarBashe\Sync\Queue\Queue_Manager;

defined( 'ABSPATH' ) || exit;

class WooCommerce_Hooks {

	/**
	 * Queue manager.
	 *
	 * @var Queue_Manager
	 */
	protected $queue;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param Queue_Manager $queue Queue manager.
	 * @param Settings      $settings Settings.
	 * @param Logger        $logger Logger.
	 */
	public function __construct( Queue_Manager $queue, Settings $settings, Logger $logger ) {
		$this->queue    = $queue;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_new_product', array( $this, 'on_product_created' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'on_product_deleted' ), 20, 1 );
		add_action( 'save_post_product', array( $this, 'on_product_saved' ), 20, 3 );
	}

	/**
	 * Handle product create.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function on_product_created( $product_id ) {
		if ( ! $this->settings->enabled( 'enable_create' ) ) {
			$this->logger->log( 'create', 'skipped', 'همگام‌سازی خودکار ایجاد محصول غیرفعال است.', array( 'woo_product_id' => (int) $product_id ) );
			return;
		}

		$this->logger->log( 'create', 'started', 'هوک ایجاد محصول ووکامرس اجرا شد.', array( 'woo_product_id' => (int) $product_id ) );

		if ( $this->settings->enabled( 'enable_immediate_sync' ) ) {
			$this->queue->run_now( 'create', (int) $product_id, 'woocommerce-hook', 'درخواست همگام‌سازی خودکار ثبت شد.' );
			return;
		}

		$this->queue->enqueue( 'create', (int) $product_id, array( 'source' => 'woocommerce-hook' ) );
	}

	/**
	 * Handle product update.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function on_product_updated( $product_id ) {
		if ( ! $this->settings->enabled( 'enable_update' ) ) {
			$this->logger->log( 'update', 'skipped', 'همگام‌سازی خودکار بروزرسانی محصول غیرفعال است.', array( 'woo_product_id' => (int) $product_id ) );
			return;
		}

		$this->logger->log( 'update', 'started', 'هوک بروزرسانی محصول ووکامرس اجرا شد.', array( 'woo_product_id' => (int) $product_id ) );

		if ( $this->settings->enabled( 'enable_immediate_sync' ) ) {
			$this->queue->run_now( 'update', (int) $product_id, 'woocommerce-hook', 'درخواست همگام‌سازی خودکار ثبت شد.' );
			return;
		}

		$this->queue->enqueue( 'update', (int) $product_id, array( 'source' => 'woocommerce-hook' ) );
	}

	/**
	 * Handle product delete.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_product_deleted( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			$this->logger->log( 'delete', 'skipped', 'حذف ثبت‌شده مربوط به محصول ووکامرس نیست؛ همگام‌سازی انجام نشد.', array( 'woo_product_id' => (int) $post_id ) );
			return;
		}

		if ( ! $this->settings->enabled( 'enable_delete' ) ) {
			$this->logger->log( 'delete', 'skipped', 'همگام‌سازی خودکار حذف محصول غیرفعال است.', array( 'woo_product_id' => (int) $post_id ) );
			return;
		}

		$this->logger->log( 'delete', 'started', 'هوک حذف محصول ووکامرس اجرا شد.', array( 'woo_product_id' => (int) $post_id ) );

		if ( $this->settings->enabled( 'enable_immediate_sync' ) ) {
			$this->queue->run_now( 'delete', (int) $post_id, 'woocommerce-hook', 'درخواست همگام‌سازی خودکار ثبت شد.' );
			return;
		}

		$this->queue->enqueue( 'delete', (int) $post_id, array( 'source' => 'woocommerce-hook' ) );
	}

	/**
	 * Reliable fallback hook for product saves.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an update.
	 * @return void
	 */
	public function on_product_saved( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			$this->logger->log( 'sync', 'skipped', 'این ذخیره‌سازی از نوع autosave یا revision است؛ همگام‌سازی انجام نشد.', array( 'woo_product_id' => (int) $post_id ) );
			return;
		}

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			$this->logger->log( 'sync', 'skipped', 'آیتم ذخیره‌شده محصول ووکامرس نیست؛ همگام‌سازی انجام نشد.', array( 'woo_product_id' => (int) $post_id ) );
			return;
		}

		if ( $update ) {
			$this->on_product_updated( $post_id );
			return;
		}

		$this->on_product_created( $post_id );
	}
}
