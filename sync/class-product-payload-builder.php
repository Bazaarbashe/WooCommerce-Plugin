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
	 * Last resolved price diagnostics.
	 *
	 * @var array
	 */
	protected $last_price_debug = array();

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
		$price         = $this->resolve_product_price( $product );
		$has_discount  = $this->settings->enabled( 'enable_discount' ) && ! empty( $this->last_price_debug['has_discount'] );
		$discount_pct  = $has_discount ? (int) ( $this->last_price_debug['discount_percent'] ?? 0 ) : 0;
		$categories    = $this->get_product_categories( $product );
		$weight_grams  = $this->get_weight_grams( $product );

		$payload = array(
			'product_name'        => $product->get_name(),
			'product_price'       => null === $price ? '' : $price,
			'product_description' => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'product_link'        => get_permalink( $product->get_id() ),
			'has_discount'        => $has_discount,
			'discount_percent'    => $discount_pct,
			'product_images'      => array_values( array_filter( array_map( 'intval', $image_ids ) ) ),
			'woo_categories'      => $categories,
			'woo_category_names'  => wp_list_pluck( $categories, 'name' ),
			'weight_grams'        => $weight_grams,
			'package_weight_grams' => $weight_grams,
		);

		if ( $has_discount && ! empty( $this->last_price_debug['discount_price'] ) ) {
			$payload['sale_price'] = $this->last_price_debug['discount_price'];
		}

		if ( $this->settings->enabled( 'enable_inventory' ) ) {
			$raw_stock                 = $product->get_stock_quantity();
			$stock_quantity            = $product->managing_stock() && null !== $raw_stock ? max( 0, (int) $raw_stock ) : ( $product->is_in_stock() ? 1 : 0 );
			$payload['stock']          = $stock_quantity;
			$payload['inventory']      = $product->managing_stock() ? $stock_quantity : null;
			$payload['stock_quantity'] = $product->managing_stock() ? $stock_quantity : null;
			$payload['in_stock']       = $product->is_in_stock();
		}

		return apply_filters( 'bbsync_product_payload', $payload, $product );
	}

	/**
	 * Get last resolved price diagnostics.
	 *
	 * @return array
	 */
	public function get_last_price_debug() {
		return $this->last_price_debug;
	}

	/**
	 * Resolve WooCommerce price without turning empty values into zero.
	 *
	 * @param \WC_Product $product Product.
	 * @return string|null
	 */
	protected function resolve_product_price( \WC_Product $product ) {
		$product_id = (int) $product->get_id();
		$fresh_product = wc_get_product( $product_id );
		if ( $fresh_product instanceof \WC_Product ) {
			$product = $fresh_product;
		}

		$raw_price         = $product->get_price();
		$raw_price_edit    = $product->get_price( 'edit' );
		$raw_regular       = $product->get_regular_price();
		$raw_regular_edit  = $product->get_regular_price( 'edit' );
		$raw_sale          = $product->get_sale_price();
		$raw_sale_edit     = $product->get_sale_price( 'edit' );
		$meta_price        = get_post_meta( $product_id, '_price', true );
		$meta_regular      = get_post_meta( $product_id, '_regular_price', true );
		$meta_sale         = get_post_meta( $product_id, '_sale_price', true );

		$active_candidates = array( $raw_price_edit, $meta_price, $raw_price );
		$regular_candidates = array( $raw_regular_edit, $meta_regular );
		$sale_candidates = array( $raw_sale_edit, $meta_sale );

		$active = $this->first_valid_price( $active_candidates );
		$regular = $this->first_valid_price( $regular_candidates );
		$sale = $this->first_valid_price( $sale_candidates );
		$has_discount = null !== $regular && null !== $sale && $this->price_to_float( $sale ) < $this->price_to_float( $regular );
		$selected = $has_discount ? $regular : ( null !== $active ? $active : $regular );
		if ( null === $selected && null !== $sale ) {
			$selected = $sale;
		}
		$discount_percent = $has_discount
			? (int) round( ( ( $this->price_to_float( $regular ) - $this->price_to_float( $sale ) ) / $this->price_to_float( $regular ) ) * 100 )
			: 0;
		$discount_percent = max( 0, min( 99, $discount_percent ) );

		$this->last_price_debug = array(
			'woo_product_id'            => $product_id,
			'product_type'              => $product->get_type(),
			'product_status'            => get_post_status( $product_id ),
			'raw_get_price'             => $raw_price,
			'raw_get_price_edit'        => $raw_price_edit,
			'raw_get_regular_price'     => $raw_regular,
			'raw_get_regular_price_edit' => $raw_regular_edit,
			'raw_get_sale_price'        => $raw_sale,
			'raw_get_sale_price_edit'   => $raw_sale_edit,
			'post_meta_price'           => $meta_price,
			'post_meta_regular_price'   => $meta_regular,
			'post_meta_sale_price'      => $meta_sale,
			'parsed_price'              => $active,
			'parsed_regular_price'      => $regular,
			'parsed_sale_price'         => $sale,
			'selected_price'            => $selected,
			'final_product_price'       => $selected,
			'discount_price'            => $has_discount ? $sale : null,
			'final_sale_price'          => $has_discount ? $sale : null,
			'has_discount'              => $has_discount,
			'discount_percent'          => $discount_percent,
			'payload_price'             => $selected ?: '',
		);

		return $selected;
	}

	/**
	 * Parse a WooCommerce price while preserving empty as null.
	 *
	 * @param mixed $value Raw price.
	 * @return string|null
	 */
	protected function parse_price_value( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = strtr(
			(string) $value,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
		$clean = preg_replace( '/[^0-9.]+/', '', $value );
		if ( '' === $clean || ! is_numeric( $clean ) || (float) $clean <= 0 ) {
			return null;
		}
		return $this->format_price_value( $clean );
	}

	/**
	 * Return the first valid price from candidate values.
	 *
	 * @param array $values Candidate values.
	 * @return string|null
	 */
	protected function first_valid_price( array $values ) {
		foreach ( $values as $value ) {
			$price = $this->parse_price_value( $value );
			if ( null !== $price ) {
				return $price;
			}
		}
		return null;
	}

	/**
	 * Convert normalized price string to float for calculations.
	 *
	 * @param string|null $value Price.
	 * @return float
	 */
	protected function price_to_float( $value ) {
		return (float) ( $value ?? 0 );
	}

	/**
	 * Format selected price for the API payload.
	 *
	 * @param mixed $value Price.
	 * @return string
	 */
	protected function format_price_value( $value ) {
		$formatted = rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' );
		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Normalize decimal values from WooCommerce.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	protected function normalize_decimal( $value ) {
		$value = strtr(
			(string) $value,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
		$clean = preg_replace( '/[^0-9.\-]/', '', $value );
		return '' === $clean || '-' === $clean ? 0.0 : (float) $clean;
	}

	/**
	 * Convert WooCommerce product weight to grams.
	 *
	 * @param \WC_Product $product Product.
	 * @return int
	 */
	protected function get_weight_grams( \WC_Product $product ) {
		$weight = $this->normalize_decimal( $product->get_weight( 'edit' ) );
		if ( $weight <= 0 ) {
			return 0;
		}

		$unit = strtolower( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
		switch ( $unit ) {
			case 'g':
				return (int) round( $weight );
			case 'lbs':
				return (int) round( $weight * 453.59237 );
			case 'oz':
				return (int) round( $weight * 28.349523125 );
			case 'kg':
			default:
				return (int) round( $weight * 1000 );
		}
	}

	/**
	 * Get WooCommerce product categories in a compact API-friendly shape.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	protected function get_product_categories( \WC_Product $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( $term ) {
					return array(
						'id'   => (int) $term->term_id,
						'name' => (string) $term->name,
						'slug' => (string) $term->slug,
					);
				},
				$terms
			)
		);
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
