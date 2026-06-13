<?php
/**
 * Logger service.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Logger;

use BazaarBashe\Sync\Models\Log_Repository;
use BazaarBashe\Sync\Models\Settings;

defined( 'ABSPATH' ) || exit;

class Logger {

	/**
	 * Repository.
	 *
	 * @var Log_Repository
	 */
	protected $repository;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $repository Repository.
	 */
	public function __construct( Log_Repository $repository, Settings $settings = null ) {
		$this->repository = $repository;
		$this->settings   = $settings ?: new Settings();
	}

	/**
	 * Log an event.
	 *
	 * @param string $action Action.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param array  $response Response.
	 * @return void
	 */
	public function log( $action, $status, $message, array $context = array(), array $response = array() ) {
		$diagnostics = Diagnostics::build( $status, $message, $context, $response );

		$this->repository->insert(
			array(
				'woo_product_id'         => $context['woo_product_id'] ?? null,
				'bazaarbashe_product_id' => $context['bazaarbashe_product_id'] ?? null,
				'action'                 => $action,
				'status'                 => $status,
				'message'                => $message,
				'context'                => array_merge( $context, array( 'diagnostics' => $diagnostics ) ),
				'response'               => $response,
			)
		);

		if ( in_array( $status, array( 'failed', 'skipped' ), true ) ) {
			$this->settings->update(
				array(
					'latest_issue' => array_merge(
						$diagnostics,
						array(
							'action'     => $action,
							'status'     => $status,
							'created_at' => current_time( 'mysql', true ),
						)
					),
				)
			);
			set_transient(
				'bbsync_admin_notice',
				array(
					'type'    => 'error',
					'message' => $diagnostics['title'] . ' - ' . $diagnostics['suggested_fix'],
				),
				MINUTE_IN_SECONDS * 10
			);
		} elseif ( 'success' === $status && in_array( $action, array( 'create', 'update', 'delete', 'sync' ), true ) ) {
			$this->settings->update( array( 'latest_issue' => array() ) );
		}
	}
}
