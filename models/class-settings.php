<?php
/**
 * Settings model.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Models;

defined( 'ABSPATH' ) || exit;

class Settings {

	/**
	 * Option key.
	 */
	const OPTION_KEY = 'bbsync_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'access_token'       => '',
			'vendor_identifier'  => '',
			'enable_create'      => 'yes',
			'enable_update'      => 'yes',
			'enable_delete'      => 'yes',
			'enable_images'      => 'yes',
			'enable_price'       => 'yes',
			'enable_inventory'   => 'yes',
			'enable_discount'    => 'yes',
			'enable_immediate_sync' => 'yes',
			'connection_status'  => 'disconnected',
			'connection_payload' => array(),
			'last_sync_at'       => '',
			'latest_issue'       => array(),
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function all() {
		return wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), $this->defaults() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array $values New values.
	 * @return bool
	 */
	public function update( array $values ) {
		$current = $this->all();
		return update_option( self::OPTION_KEY, array_merge( $current, $values ), false );
	}

	/**
	 * Sanitize incoming settings.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function sanitize( array $input ) {
		return array(
			'access_token'      => sanitize_text_field( $input['access_token'] ?? '' ),
			'vendor_identifier' => sanitize_text_field( $input['vendor_identifier'] ?? '' ),
			'enable_create'     => ! empty( $input['enable_create'] ) ? 'yes' : 'no',
			'enable_update'     => ! empty( $input['enable_update'] ) ? 'yes' : 'no',
			'enable_delete'     => ! empty( $input['enable_delete'] ) ? 'yes' : 'no',
			'enable_images'     => ! empty( $input['enable_images'] ) ? 'yes' : 'no',
			'enable_price'      => ! empty( $input['enable_price'] ) ? 'yes' : 'no',
			'enable_inventory'  => ! empty( $input['enable_inventory'] ) ? 'yes' : 'no',
			'enable_discount'   => ! empty( $input['enable_discount'] ) ? 'yes' : 'no',
			'enable_immediate_sync' => ! empty( $input['enable_immediate_sync'] ) ? 'yes' : 'no',
		);
	}

	/**
	 * Check yes/no option.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function enabled( $key ) {
		return 'yes' === $this->get( $key, 'no' );
	}
}
