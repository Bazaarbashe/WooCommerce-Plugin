<?php
/**
 * API client.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Api;

use BazaarBashe\Sync\Logger\Logger;
use BazaarBashe\Sync\Models\Settings;

defined( 'ABSPATH' ) || exit;

class Client {

	/**
	 * API base URL.
	 */
	const BASE_URL = 'https://api.bazaarbashe.ir';

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
	 * @param Settings $settings Settings.
	 * @param Logger   $logger Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Test API connection.
	 *
	 * @return array
	 */
	public function test_connection() {
		$credentials = $this->validate_credentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$response = $this->request( 'GET', '/v1/users/me' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body              = $response['body'];
		$vendor_identifier = $this->settings->get( 'vendor_identifier', '' );
		$matched_store     = $this->match_store( $body, $vendor_identifier );

		return array(
			'user'  => $body['user'] ?? array(),
			'store' => $matched_store,
			'raw'   => $body,
		);
	}

	/**
	 * Resolve vendor ID from configured identifier.
	 *
	 * @return int|string|\WP_Error
	 */
	public function resolve_vendor_id() {
		$identifier = (string) $this->settings->get( 'vendor_identifier', '' );

		if ( '' === $identifier ) {
			return new \WP_Error( 'bbsync_missing_vendor', 'شناسه یا آدرس فروشگاه وارد نشده است.', array( 'detail' => 'Vendor identifier is empty.' ) );
		}

		if ( is_numeric( $identifier ) ) {
			return (int) $identifier;
		}

		$connection = $this->test_connection();

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		if ( ! empty( $connection['store']['id'] ) ) {
			return (int) $connection['store']['id'];
		}

		return new \WP_Error( 'bbsync_vendor_not_found', 'فروشگاه ثبت‌شده با این توکن پیدا نشد.', array( 'detail' => 'Configured vendor does not match any approved website store.' ) );
	}

	/**
	 * Upload a file to BazaarBashe.
	 *
	 * @param string $file_path File path.
	 * @return array|\WP_Error
	 */
	public function upload_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( 'bbsync_missing_file', 'فایل تصویر محصول پیدا نشد.', array( 'detail' => $file_path, 'endpoint' => self::BASE_URL . '/v1/files' ) );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error( 'bbsync_missing_curl', 'کتابخانه cURL برای آپلود تصویر در دسترس نیست.', array( 'detail' => 'curl_init() is not available.', 'endpoint' => self::BASE_URL . '/v1/files' ) );
		}

		$file = function_exists( 'curl_file_create' )
			? curl_file_create( $file_path, mime_content_type( $file_path ) ?: 'application/octet-stream', basename( $file_path ) )
			: new \CURLFile( $file_path );

		$ch = curl_init( self::BASE_URL . '/v1/files' );

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 45,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . $this->settings->get( 'access_token', '' ),
					'Accept: application/json',
				),
				CURLOPT_POSTFIELDS     => array(
					'file' => $file,
				),
			)
		);

		$raw_body    = curl_exec( $ch );
		$curl_error  = curl_error( $ch );
		$status_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $curl_error ) {
			return new \WP_Error( 'bbsync_upload_transport_error', 'آپلود تصویر با خطای ارتباطی روبه‌رو شد.', array( 'detail' => $curl_error, 'endpoint' => self::BASE_URL . '/v1/files' ) );
		}

		$decoded = json_decode( (string) $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'bbsync_api_error',
				$decoded['message'] ?? 'آپلود تصویر محصول ناموفق بود.',
				array(
					'status' => $status_code,
					'body'   => $decoded,
					'endpoint' => self::BASE_URL . '/v1/files',
					'detail' => wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ),
				)
			);
		}

		return array(
			'status' => $status_code,
			'body'   => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Create a product.
	 *
	 * @param string|int $vendor_identifier Vendor URL or ID.
	 * @param array      $payload Payload.
	 * @return array|\WP_Error
	 */
	public function create_product( $vendor_identifier, array $payload ) {
		return $this->request( 'POST', '/v1/vendors/' . rawurlencode( (string) $vendor_identifier ) . '/products', $payload );
	}

	/**
	 * Update a product.
	 *
	 * @param int   $vendor_id Vendor ID.
	 * @param int   $product_id Product ID.
	 * @param array $payload Payload.
	 * @return array|\WP_Error
	 */
	public function update_product( $vendor_id, $product_id, array $payload ) {
		return $this->request( 'PATCH', '/v1/vendors/' . (int) $vendor_id . '/products/' . (int) $product_id, $payload );
	}

	/**
	 * Delete a product.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @param int $product_id Product ID.
	 * @return array|\WP_Error
	 */
	public function delete_product( $vendor_id, $product_id ) {
		return $this->request( 'DELETE', '/v1/vendors/' . (int) $vendor_id . '/products/' . (int) $product_id );
	}

	/**
	 * Perform a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path API path.
	 * @param array  $body JSON body.
	 * @return array|\WP_Error
	 */
	public function request( $method, $path, array $body = array() ) {
		$credentials = $this->validate_credentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->settings->get( 'access_token', '' ),
			'Accept'        => 'application/json',
		);

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 45,
		);

		if ( ! empty( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$endpoint = self::BASE_URL . $path;
		$response = wp_remote_request( $endpoint, $args );

		return $this->normalize_response( $response, $endpoint );
	}

	/**
	 * Normalize the HTTP response.
	 *
	 * @param mixed $response Response.
	 * @return array|\WP_Error
	 */
	protected function normalize_response( $response, $endpoint = '' ) {
		if ( is_wp_error( $response ) ) {
			$message = (string) $response->get_error_message();
			$detail  = wp_json_encode( $response->get_error_data(), JSON_UNESCAPED_UNICODE );
			$code    = 'bbsync_api_unreachable';

			if ( false !== strpos( strtolower( $message ), 'ssl' ) ) {
				$code = 'bbsync_ssl_error';
			} elseif ( false !== strpos( strtolower( $message ), 'timed out' ) || false !== strpos( strtolower( $message ), 'timeout' ) ) {
				$code = 'bbsync_timeout';
			} elseif ( false !== strpos( strtolower( $message ), 'resolve host' ) || false !== strpos( strtolower( $message ), 'dns' ) ) {
				$code = 'bbsync_dns_error';
			}

			return new \WP_Error( $code, 'ارتباط با API بازارباشه برقرار نشد.', array( 'detail' => trim( $message . ' ' . $detail ), 'endpoint' => $endpoint ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$error_code = 401 === $status ? 'bbsync_invalid_token' : 'bbsync_api_error';
			return new \WP_Error(
				$error_code,
				$body['message'] ?? 'درخواست API با خطا مواجه شد.',
				array(
					'status' => $status,
					'body'   => $body,
					'endpoint' => $endpoint,
					'detail' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
				)
			);
		}

		return array(
			'status' => $status,
			'body'   => is_array( $body ) ? $body : array(),
		);
	}

	/**
	 * Validate required credentials.
	 *
	 * @return true|\WP_Error
	 */
	protected function validate_credentials() {
		$token             = (string) $this->settings->get( 'access_token', '' );
		$vendor_identifier = (string) $this->settings->get( 'vendor_identifier', '' );

		if ( '' === $token ) {
			return new \WP_Error( 'bbsync_missing_token', 'توکن دسترسی وارد نشده است.', array( 'detail' => 'Access Token is empty.' ) );
		}

		if ( '' === $vendor_identifier ) {
			return new \WP_Error( 'bbsync_missing_vendor', 'شناسه یا آدرس فروشگاه وارد نشده است.', array( 'detail' => 'Vendor identifier is empty.' ) );
		}

		return true;
	}

	/**
	 * Find the configured store.
	 *
	 * @param array  $payload User payload.
	 * @param string $identifier Vendor identifier.
	 * @return array
	 */
	protected function match_store( array $payload, $identifier ) {
		$stores = $payload['user']['stores'] ?? array();

		foreach ( $stores as $store ) {
			if ( ! empty( $store['id'] ) && (string) $store['id'] === (string) $identifier ) {
				return $store;
			}

			if ( ! empty( $store['website_domain'] ) && $store['website_domain'] === $identifier ) {
				return $store;
			}
		}

		return array();
	}
}
