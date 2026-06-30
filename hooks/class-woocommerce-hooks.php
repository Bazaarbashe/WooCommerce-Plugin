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
	 * Product events handled during the current request.
	 *
	 * @var array
	 */
	protected $handled_events = array();

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
		add_action( 'woocommerce_after_product_object_save', array( $this, 'on_product_object_saved' ), 30, 1 );
		add_action( 'before_delete_post', array( $this, 'on_product_deleted' ), 20, 1 );
		add_action( 'save_post_product', array( $this, 'on_product_saved' ), 20, 3 );
	}

	/**
	 * Main automatic sync hook after WooCommerce has saved product data.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	public function on_product_object_saved( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id = (int) $product->get_id();
		$operation = get_post_meta( $product_id, '_bbsync_seen_product', true ) ? 'update' : 'create';
		update_post_meta( $product_id, '_bbsync_seen_product', 1 );

		if ( 'update' === $operation ) {
			$this->on_product_updated( $product_id );
			return;
		}

		$this->on_product_created( $product_id );
	}

	/**
	 * Handle product create.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function on_product_created( $product_id ) {
		if ( $this->already_handled( 'create', $product_id ) ) {
			return;
		}

		if ( ! $this->is_syncable_product_state( $product_id ) ) {
			return;
		}

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
		if ( $this->already_handled( 'update', $product_id ) ) {
			return;
		}

		if ( ! $this->is_syncable_product_state( $product_id ) ) {
			return;
		}

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
		if ( $this->already_handled( 'delete', $post_id ) ) {
			return;
		}

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
		if ( get_post_meta( (int) $post_id, '_bbsync_seen_product', true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			$this->logger->log( 'sync', 'skipped', 'این ذخیره‌سازی از نوع autosave یا revision است؛ همگام‌سازی انجام نشد.', array( 'woo_product_id' => (int) $post_id ) );
			return;
		}

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			$this->logger->log( 'sync', 'skipped', 'آیتم ذخیره‌شده محصول ووکامرس نیست؛ همگام‌سازی انجام نشد.', array( 'woo_product_id' => (int) $post_id ) );
			return;
		}

		if ( ! $this->is_syncable_product_state( $post_id ) ) {
			return;
		}

		if ( $update ) {
			if ( ! $this->settings->enabled( 'enable_update' ) ) {
				return;
			}
			$this->queue->enqueue( 'update', (int) $post_id, array( 'source' => 'save-post-fallback' ) );
			return;
		}

		if ( ! $this->settings->enabled( 'enable_create' ) ) {
			return;
		}
		$this->queue->enqueue( 'create', (int) $post_id, array( 'source' => 'save-post-fallback' ) );
	}

	/**
	 * Avoid duplicate WooCommerce/save_post events in the same request.
	 *
	 * @param string $operation Operation.
	 * @param int    $product_id Product ID.
	 * @return bool
	 */
	protected function already_handled( $operation, $product_id ) {
		$key = sanitize_key( $operation ) . ':' . (int) $product_id;
		if ( isset( $this->handled_events[ $key ] ) ) {
			return true;
		}
		$this->handled_events[ $key ] = true;
		return false;
	}

	/**
	 * Check whether the current product state should be synced as create/update.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	protected function is_syncable_product_state( $product_id ) {
		$post = get_post( (int) $product_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return false;
		}

		return 'AUTO-DRAFT' !== strtoupper( trim( (string) $post->post_title ) );
	}
}
