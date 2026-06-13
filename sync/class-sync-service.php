<?php
/**
 * Sync service.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Sync;

use BazaarBashe\Sync\Api\Client;
use BazaarBashe\Sync\Logger\Logger;
use BazaarBashe\Sync\Models\Product_Map_Repository;
use BazaarBashe\Sync\Models\Settings;

defined( 'ABSPATH' ) || exit;

class Sync_Service {

	/**
	 * API client.
	 *
	 * @var Client
	 */
	protected $client;

	/**
	 * Payload builder.
	 *
	 * @var Product_Payload_Builder
	 */
	protected $builder;

	/**
	 * Map repository.
	 *
	 * @var Product_Map_Repository
	 */
	protected $map_repository;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Client $client, Product_Payload_Builder $builder, Product_Map_Repository $map_repository, Logger $logger, Settings $settings ) {
		$this->client         = $client;
		$this->builder        = $builder;
		$this->map_repository = $map_repository;
		$this->logger         = $logger;
		$this->settings       = $settings;
	}

	/**
	 * Process a queued sync job.
	 *
	 * @param array $job Job payload.
	 * @return true|\WP_Error
	 */
	public function process( array $job ) {
		$job = wp_parse_args(
			$job,
			array(
				'operation'      => '',
				'woo_product_id' => 0,
				'attempt'        => 1,
				'source'         => 'event',
			)
		);

		if ( 'delete' === $job['operation'] ) {
			return $this->delete_product( (int) $job['woo_product_id'] );
		}

		return $this->upsert_product( (int) $job['woo_product_id'], (string) $job['operation'] );
	}

	/**
	 * Run a direct manual sync immediately.
	 *
	 * @param int    $woo_product_id WooCommerce product ID.
	 * @param string $operation Operation.
	 * @return true|\WP_Error
	 */
	public function run_manual_sync( $woo_product_id, $operation = 'sync' ) {
		return $this->process(
			array(
				'operation'      => $operation,
				'woo_product_id' => (int) $woo_product_id,
				'attempt'        => 1,
				'source'         => 'manual-direct',
				'direct'         => true,
			)
		);
	}

	/**
	 * Create or update a product remotely.
	 *
	 * @param int    $woo_product_id Product ID.
	 * @param string $operation Operation label.
	 * @return true|\WP_Error
	 */
	protected function upsert_product( $woo_product_id, $operation ) {
		$product = wc_get_product( $woo_product_id );

		if ( ! $product ) {
			return new \WP_Error( 'bbsync_product_missing', 'محصول ووکامرس پیدا نشد.', array( 'detail' => 'wc_get_product() returned null.' ) );
		}

		$this->logger->log(
			$operation,
			'started',
			'محصول ووکامرس با موفقیت بارگذاری شد.',
			array(
				'woo_product_id' => $woo_product_id,
				'product_name'   => $product->get_name(),
			)
		);

		$vendor_identifier = (string) $this->settings->get( 'vendor_identifier', '' );

		if ( '' === $vendor_identifier ) {
			return new \WP_Error( 'bbsync_missing_vendor', 'شناسه یا آدرس فروشگاه قبل از همگام‌سازی باید ثبت شود.', array( 'detail' => 'vendor_identifier is empty in plugin settings.' ) );
		}

		$vendor_id         = $this->client->resolve_vendor_id();

		if ( is_wp_error( $vendor_id ) ) {
			return $vendor_id;
		}

		$image_ids = $this->sync_images( $product );
		if ( is_wp_error( $image_ids ) ) {
			return $image_ids;
		}

		$payload = $this->builder->build( $product, $image_ids );
		$hash    = $this->builder->hash( $product, $image_ids );
		$mapping = $this->map_repository->get_by_woo_product_id( $woo_product_id );

		$this->logger->log(
			$operation,
			'started',
			'داده ارسالی محصول ساخته شد.',
			array(
				'woo_product_id' => $woo_product_id,
			),
			$payload
		);

		if ( $mapping && $mapping->last_payload_hash === $hash && 'update' === $operation ) {
			return true;
		}

		if ( $mapping && ! empty( $mapping->bazaarbashe_product_id ) ) {
			$this->logger->log(
				'update',
				'started',
				'درخواست API ارسال شد.',
				array(
					'woo_product_id'         => $woo_product_id,
					'bazaarbashe_product_id' => (int) $mapping->bazaarbashe_product_id,
				),
				$payload
			);
			$response = $this->client->update_product( $vendor_id, (int) $mapping->bazaarbashe_product_id, $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$this->logger->log(
				'update',
				'started',
				'پاسخ API دریافت شد.',
				array(
					'woo_product_id'         => $woo_product_id,
					'bazaarbashe_product_id' => (int) $mapping->bazaarbashe_product_id,
				),
				$response['body']
			);

			$this->map_repository->upsert( $woo_product_id, (int) $mapping->bazaarbashe_product_id, $vendor_identifier, 'update', $hash );
			update_post_meta( $woo_product_id, '_bbsync_last_image_ids', $image_ids );
			update_option( 'bbsync_last_sync_at', current_time( 'mysql', true ), false );
			$this->logger->log( 'update', 'success', 'محصول با موفقیت در بازارباشه بروزرسانی شد.', array(
				'woo_product_id'         => $woo_product_id,
				'bazaarbashe_product_id' => (int) $mapping->bazaarbashe_product_id,
			), $response['body'] );
			return true;
		}

		$this->logger->log(
			'create',
			'started',
			'درخواست API ارسال شد.',
			array(
				'woo_product_id' => $woo_product_id,
			),
			$payload
		);
		$response = $this->client->create_product( $vendor_identifier, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->logger->log(
			'create',
			'started',
			'پاسخ API دریافت شد.',
			array(
				'woo_product_id' => $woo_product_id,
			),
			$response['body']
		);

		$bazaar_product_id = (int) ( $response['body']['product_id'] ?? $response['body']['id'] ?? 0 );

		if ( $bazaar_product_id <= 0 ) {
			return new \WP_Error( 'bbsync_missing_remote_id', 'شناسه محصول از سمت بازارباشه برنگشت.', array( 'detail' => wp_json_encode( $response['body'], JSON_UNESCAPED_UNICODE ) ) );
		}

		$this->map_repository->upsert( $woo_product_id, $bazaar_product_id, $vendor_identifier, 'create', $hash );
		update_post_meta( $woo_product_id, '_bbsync_last_image_ids', $image_ids );
		update_option( 'bbsync_last_sync_at', current_time( 'mysql', true ), false );
		$this->logger->log( 'create', 'success', 'محصول با موفقیت در بازارباشه ایجاد شد.', array(
			'woo_product_id'         => $woo_product_id,
			'bazaarbashe_product_id' => $bazaar_product_id,
		), $response['body'] );

		return true;
	}

	/**
	 * Delete product remotely.
	 *
	 * @param int $woo_product_id WooCommerce product ID.
	 * @return true|\WP_Error
	 */
	protected function delete_product( $woo_product_id ) {
		$mapping = $this->map_repository->get_by_woo_product_id( $woo_product_id );

		if ( ! $mapping || empty( $mapping->bazaarbashe_product_id ) ) {
			return true;
		}

		$vendor_id = $this->client->resolve_vendor_id();

		if ( is_wp_error( $vendor_id ) ) {
			return $vendor_id;
		}

		$response = $this->client->delete_product( $vendor_id, (int) $mapping->bazaarbashe_product_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->map_repository->delete_by_woo_product_id( $woo_product_id );
		$this->logger->log( 'delete', 'success', 'محصول با موفقیت از بازارباشه حذف شد.', array(
			'woo_product_id'         => $woo_product_id,
			'bazaarbashe_product_id' => (int) $mapping->bazaarbashe_product_id,
		), $response['body'] );

		return true;
	}

	/**
	 * Upload and resolve image IDs.
	 *
	 * @param \WC_Product $product Product.
	 * @return array|\WP_Error
	 */
	protected function sync_images( \WC_Product $product ) {
		if ( ! $this->settings->enabled( 'enable_images' ) ) {
			return (array) get_post_meta( $product->get_id(), '_bbsync_last_image_ids', true );
		}

		$attachment_ids = array_filter(
			array_merge(
				array( $product->get_image_id() ),
				$product->get_gallery_image_ids()
			)
		);

		$attachment_ids = array_slice( array_values( array_unique( array_map( 'intval', $attachment_ids ) ) ), 0, 10 );

		$image_ids = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$remote_id = (int) get_post_meta( $attachment_id, '_bbsync_remote_file_id', true );
			$file_path = get_attached_file( $attachment_id );
			$file_hash = $file_path && file_exists( $file_path ) ? md5_file( $file_path ) : '';
			$last_hash = (string) get_post_meta( $attachment_id, '_bbsync_remote_file_hash', true );

			if ( $remote_id > 0 && $file_hash && $file_hash === $last_hash ) {
				$image_ids[] = $remote_id;
				continue;
			}

			$response = $this->client->upload_file( $file_path );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$remote_id = (int) ( $response['body']['id'] ?? 0 );

			if ( $remote_id <= 0 ) {
				return new \WP_Error( 'bbsync_image_upload_failed', 'آپلود تصویر انجام شد اما شناسه فایل برنگشت.', array( 'detail' => wp_json_encode( $response['body'], JSON_UNESCAPED_UNICODE ) ) );
			}

			update_post_meta( $attachment_id, '_bbsync_remote_file_id', $remote_id );
			update_post_meta( $attachment_id, '_bbsync_remote_file_hash', $file_hash );

			$image_ids[] = $remote_id;
		}

		return $image_ids;
	}
}
