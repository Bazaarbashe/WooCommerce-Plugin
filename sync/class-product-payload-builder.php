<?php
/**
 * Product payload builder.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Sync;

use BazaarBashe\Sync\Models\Settings;

defined( 'ABSPATH' ) || exit;

class Product_Payload_Builder {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build product payload.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $image_ids Uploaded image IDs.
	 * @return array
	 */
	public function build( \WC_Product $product, array $image_ids ) {
		$regular_price = (float) $product->get_regular_price();
		$sale_price    = (float) $product->get_sale_price();
		$price         = $sale_price > 0 ? $sale_price : (float) $product->get_price();
		$has_discount  = $this->settings->enabled( 'enable_discount' ) && $regular_price > 0 && $sale_price > 0 && $sale_price < $regular_price;
		$discount_pct  = $has_discount ? (int) round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 ) : 0;

		$payload = array(
			'product_name'        => $product->get_name(),
			'product_price'       => (string) ( $this->settings->enabled( 'enable_price' ) ? $price : $product->get_price() ),
			'product_description' => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'product_link'        => get_permalink( $product->get_id() ),
			'has_discount'        => $has_discount,
			'discount_percent'    => $discount_pct,
			'product_images'      => array_values( array_filter( array_map( 'intval', $image_ids ) ) ),
		);

		if ( $this->settings->enabled( 'enable_inventory' ) ) {
			$payload['inventory']      = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
			$payload['stock_quantity'] = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
			$payload['in_stock']       = $product->is_in_stock();
		}

		return apply_filters( 'bbsync_product_payload', $payload, $product );
	}

	/**
	 * Generate a sync hash for duplicate detection.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $image_ids Image IDs.
	 * @return string
	 */
	public function hash( \WC_Product $product, array $image_ids ) {
		return hash( 'sha256', wp_json_encode( $this->build( $product, $image_ids ) ) );
	}
}

