<?php
/**
 * Product mapping repository.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Models;

defined( 'ABSPATH' ) || exit;

class Product_Map_Repository {

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
		$this->table = $wpdb->prefix . 'bbsync_product_map';
	}

	/**
	 * Get mapping by WooCommerce product ID.
	 *
	 * @param int $woo_product_id Product ID.
	 * @return object|null
	 */
	public function get_by_woo_product_id( $woo_product_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE woo_product_id = %d LIMIT 1",
				$woo_product_id
			)
		);
	}

	/**
	 * Upsert a product mapping.
	 *
	 * @param int    $woo_product_id Woo product ID.
	 * @param int    $bazaar_product_id BazaarBashe product ID.
	 * @param string $vendor_identifier Vendor identifier.
	 * @param string $operation Last operation.
	 * @param string $hash Payload hash.
	 * @return void
	 */
	public function upsert( $woo_product_id, $bazaar_product_id, $vendor_identifier, $operation, $hash ) {
		global $wpdb;

		$existing = $this->get_by_woo_product_id( $woo_product_id );
		$data     = array(
			'woo_product_id'         => (int) $woo_product_id,
			'bazaarbashe_product_id' => (int) $bazaar_product_id,
			'vendor_identifier'      => (string) $vendor_identifier,
			'last_synced_at'         => current_time( 'mysql', true ),
			'last_operation'         => (string) $operation,
			'last_payload_hash'      => (string) $hash,
			'updated_at'             => current_time( 'mysql', true ),
		);

		if ( $existing ) {
			$wpdb->update( $this->table, $data, array( 'id' => (int) $existing->id ) );
			return;
		}

		$data['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( $this->table, $data );
	}

	/**
	 * Delete mapping.
	 *
	 * @param int $woo_product_id Woo product ID.
	 * @return void
	 */
	public function delete_by_woo_product_id( $woo_product_id ) {
		global $wpdb;
		$wpdb->delete( $this->table, array( 'woo_product_id' => (int) $woo_product_id ) );
	}

	/**
	 * Count mappings.
	 *
	 * @return int
	 */
	public function count_all() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}

