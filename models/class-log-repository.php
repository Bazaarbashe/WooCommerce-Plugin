<?php
/**
 * Log repository.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Models;

defined( 'ABSPATH' ) || exit;

class Log_Repository {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bbsync_logs';
	}

	/**
	 * Add a log entry.
	 *
	 * @param array $data Log data.
	 * @return void
	 */
	public function insert( array $data ) {
		global $wpdb;

		$wpdb->insert(
			$this->table,
			array(
				'woo_product_id'         => isset( $data['woo_product_id'] ) ? (int) $data['woo_product_id'] : null,
				'bazaarbashe_product_id' => isset( $data['bazaarbashe_product_id'] ) ? (int) $data['bazaarbashe_product_id'] : null,
				'action'                 => sanitize_key( $data['action'] ?? '' ),
				'status'                 => sanitize_key( $data['status'] ?? '' ),
				'message'                => wp_kses_post( $data['message'] ?? '' ),
				'response'               => wp_json_encode( $data['response'] ?? array() ),
				'context'                => wp_json_encode( $data['context'] ?? array() ),
				'created_at'             => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get logs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_logs( $limit = 100 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} ORDER BY created_at DESC, id DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Count failed logs.
	 *
	 * @return int
	 */
	public function count_failed() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
				'failed'
			)
		);
	}

	/**
	 * Count successful sync actions.
	 *
	 * @return int
	 */
	public function count_successful_syncs() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status = %s AND action IN (%s,%s,%s)",
				'success',
				'create',
				'update',
				'delete'
			)
		);
	}
}
