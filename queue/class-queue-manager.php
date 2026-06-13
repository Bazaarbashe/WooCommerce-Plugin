<?php
/**
 * Queue manager.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Queue;

use BazaarBashe\Sync\Logger\Logger;
use BazaarBashe\Sync\Sync\Sync_Service;

defined( 'ABSPATH' ) || exit;

class Queue_Manager {

	/**
	 * Sync service.
	 *
	 * @var Sync_Service
	 */
	protected $sync_service;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param Sync_Service $sync_service Sync service.
	 * @param Logger       $logger Logger.
	 */
	public function __construct( Sync_Service $sync_service, Logger $logger ) {
		$this->sync_service = $sync_service;
		$this->logger       = $logger;
	}

	/**
	 * Register queue handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'bbsync_process_job', array( $this, 'handle_job' ), 10, 2 );
		add_action( 'bbsync_run_full_sync', array( $this, 'run_full_sync' ) );
	}

	/**
	 * Enqueue a job.
	 *
	 * @param string $operation Operation.
	 * @param int    $product_id Product ID.
	 * @param array  $extra Extra payload.
	 * @return void
	 */
	public function enqueue( $operation, $product_id, array $extra = array() ) {
		$job = wp_parse_args(
			$extra,
			array(
				'operation'      => $operation,
				'woo_product_id' => (int) $product_id,
				'attempt'        => 1,
				'source'         => 'event',
			)
		);

		$key = 'bbsync_job_' . md5( wp_json_encode( $job ) );

		if ( get_transient( $key ) ) {
			$this->logger->log(
				$operation,
				'skipped',
				'این عملیات قبلاً در صف یا در حال اجرا بوده است و درخواست تکراری رد شد.',
				array(
					'woo_product_id' => (int) $product_id,
					'source'         => $job['source'],
				)
			);
			return;
		}

		set_transient( $key, 1, MINUTE_IN_SECONDS * 20 );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( 'bbsync_process_job', array( $job, $key ), 'bazaarbashe-sync' );
			if ( empty( $action_id ) ) {
				delete_transient( $key );
				$this->logger->log(
					$operation,
					'failed',
					'Action Scheduler نتوانست جاب همگام‌سازی را ایجاد کند.',
					array(
						'woo_product_id' => (int) $product_id,
						'source'         => $job['source'],
					),
					$job
				);
				return;
			}
			$this->logger->log(
				$operation,
				'queued',
				'جاب همگام‌سازی در صف قرار گرفت.',
				array(
					'woo_product_id' => (int) $product_id,
					'attempt'        => (int) $job['attempt'],
					'source'         => $job['source'],
					'action_id'      => $action_id,
				),
				$job
			);
			return;
		}

		$scheduled = wp_schedule_single_event( time() + 5, 'bbsync_process_job', array( $job, $key ) );
		if ( false === $scheduled ) {
			delete_transient( $key );
			$this->logger->log(
				$operation,
				'failed',
				'WP-Cron نتوانست جاب همگام‌سازی را زمان‌بندی کند.',
				array(
					'woo_product_id' => (int) $product_id,
					'source'         => $job['source'],
				),
				$job
			);
			return;
		}
		$this->logger->log(
			$operation,
			'scheduled',
			'جاب همگام‌سازی با WP-Cron زمان‌بندی شد.',
			array(
				'woo_product_id' => (int) $product_id,
				'attempt'        => (int) $job['attempt'],
				'source'         => $job['source'],
			),
			$job
		);
	}

	/**
	 * Handle a queued job.
	 *
	 * @param array|string $payload Payload.
	 * @param string       $job_key Job key.
	 * @return void
	 */
	public function handle_job( $payload, $job_key = '' ) {
		if ( isset( $payload['job'] ) && is_array( $payload['job'] ) ) {
			$job     = $payload['job'];
			$job_key = $payload['job_key'] ?? $job_key;
		} else {
			$job = is_array( $payload ) ? $payload : array();
		}

		if ( empty( $job ) ) {
			$this->logger->log( 'unknown', 'failed', 'داده جاب همگام‌سازی خالی بود.' );
			return;
		}

		$this->logger->log(
			$job['operation'] ?? 'unknown',
			'started',
			'هندلر جاب فراخوانی شد.',
			array(
				'woo_product_id' => $job['woo_product_id'] ?? 0,
				'attempt'        => $job['attempt'] ?? 1,
				'source'         => $job['source'] ?? 'event',
			),
			$job
		);

		$result = $this->sync_service->process( $job );

		if ( is_wp_error( $result ) ) {
			$attempt = (int) ( $job['attempt'] ?? 1 );
			$this->logger->log(
				$job['operation'] ?? 'unknown',
				'failed',
				$result->get_error_message(),
				array(
					'woo_product_id' => $job['woo_product_id'] ?? 0,
					'attempt'        => $attempt,
				),
				(array) $result->get_error_data()
			);

			if ( $attempt < 3 ) {
				$job['attempt'] = $attempt + 1;
				delete_transient( $job_key );
				$this->logger->log(
					$job['operation'] ?? 'unknown',
					'retry',
					'جاب برای تلاش مجدد زمان‌بندی شد.',
					array(
						'woo_product_id' => $job['woo_product_id'] ?? 0,
						'attempt'        => $job['attempt'],
					),
					$job
				);
				$this->schedule_retry( $job );
				return;
			}
		} else {
			$this->logger->log(
				$job['operation'] ?? 'unknown',
				'success',
				'جاب همگام‌سازی با موفقیت انجام شد.',
				array(
					'woo_product_id' => $job['woo_product_id'] ?? 0,
					'attempt'        => $job['attempt'] ?? 1,
					'source'         => $job['source'] ?? 'event',
				)
			);
		}

		delete_transient( $job_key );
	}

	/**
	 * Run a sync immediately for debugging/manual execution.
	 *
	 * @param string $operation Operation.
	 * @param int    $product_id Woo product ID.
	 * @return true|\WP_Error
	 */
	public function run_now( $operation, $product_id, $source = 'manual-direct', $requested_message = '' ) {
		$job = array(
			'operation'      => $operation,
			'woo_product_id' => (int) $product_id,
			'attempt'        => 1,
			'source'         => $source,
			'direct'         => true,
		);

		if ( '' === $requested_message ) {
			$requested_message = 'manual-direct' === $source
				? 'درخواست همگام‌سازی دستی ثبت شد.'
				: 'درخواست همگام‌سازی خودکار ثبت شد.';
		}

		$this->logger->log(
			$operation,
			'started',
			$requested_message,
			array(
				'woo_product_id' => (int) $product_id,
				'source'         => $source,
			),
			$job
		);

		$this->logger->log(
			$operation,
			'started',
			'هندلر جاب فراخوانی شد.',
			array(
				'woo_product_id' => (int) $product_id,
				'source'         => $source,
			),
			$job
		);

		$result = $this->sync_service->run_manual_sync( (int) $product_id, $operation );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				$operation,
				'failed',
				$result->get_error_message(),
				array(
					'woo_product_id' => (int) $product_id,
					'source'         => $source,
				),
				(array) $result->get_error_data()
			);
		}

		return $result;
	}

	/**
	 * Schedule retry.
	 *
	 * @param array $job Job payload.
	 * @return void
	 */
	protected function schedule_retry( array $job ) {
		$delay   = max( 60, (int) $job['attempt'] * 120 );
		$key     = 'bbsync_job_' . md5( wp_json_encode( $job ) );

		set_transient( $key, 1, MINUTE_IN_SECONDS * 30 );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, 'bbsync_process_job', array( $job, $key ), 'bazaarbashe-sync' );
			return;
		}

		wp_schedule_single_event( time() + $delay, 'bbsync_process_job', array( $job, $key ) );
	}

	/**
	 * Enqueue full sync.
	 *
	 * @return void
	 */
	public function enqueue_full_sync() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'bbsync_run_full_sync', array(), 'bazaarbashe-sync' );
			return;
		}

		wp_schedule_single_event( time() + 5, 'bbsync_run_full_sync' );
	}

	/**
	 * Full sync handler.
	 *
	 * @return void
	 */
	public function run_full_sync() {
		$products = wc_get_products(
			array(
				'limit'  => -1,
				'status' => array( 'publish', 'draft', 'private' ),
				'return' => 'ids',
			)
		);

		foreach ( $products as $product_id ) {
			$this->run_now( 'sync', (int) $product_id );
		}
	}
}
