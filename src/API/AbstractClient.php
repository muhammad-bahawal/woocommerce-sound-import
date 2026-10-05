<?php
namespace WCErpSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base helper class for API clients. Handles option loading, HTTP calls, and auth helpers.
 */
abstract class AbstractClient implements ClientInterface {

	/**
	 * Settings fields array.
	 *
	 * @var array
	 */
	protected $settings = [];

	/**
	 * Constructor. Loads settings options.
	 */
	public function __construct() {
		$this->load_settings();
	}

	/**
	 * Load current API settings from WP option DB.
	 */
	protected function load_settings() {
		$defaults = [
			'api_url'       => 'https://audio-status-scrapper-production.up.railway.app',
			'auth_type'     => 'none',
			'api_key'       => '',
			'timeout'       => 30,
			'batch_size'    => 50,
			'retries'       => 3,
		];

		$saved = get_option( 'wc_erp_sync_settings', [] );

		// Auto-migrate outdated/legacy Railway domain stored in database to active production scraper
		if ( ! empty( $saved['api_url'] ) && ( false !== strpos( $saved['api_url'], 'ce86' ) || false !== strpos( $saved['api_url'], 'soundimports-scraper-fastapi-framework' ) || false !== strpos( $saved['api_url'], 'sound-import-scrap' ) ) ) {
			$saved['api_url'] = 'https://audio-status-scrapper-production.up.railway.app';
			update_option( 'wc_erp_sync_settings', $saved );
		}

		$this->settings = wp_parse_args( $saved, $defaults );
	}

	/**
	 * Send an HTTP request.
	 *
	 * @param string $endpoint The endpoint path relative to api_url.
	 * @param string $method   GET, POST, PUT, DELETE.
	 * @param array  $body     Request payload.
	 * @param array  $headers  Additional HTTP headers.
	 * @return array|\WP_Error Response array or WP_Error on network issue.
	 */
	protected function request( $endpoint, $method = 'GET', $body = [], $headers = [] ) {
		$url = rtrim( $this->settings['api_url'], '/' ) . '/' . ltrim( $endpoint, '/' );

		// Set default headers based on authorization type
		$default_headers = [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		];

		if ( 'apikey' === $this->settings['auth_type'] && ! empty( $this->settings['api_key'] ) ) {
			$default_headers['Authorization'] = 'Bearer ' . $this->settings['api_key'];
		} elseif ( 'oauth' === $this->settings['auth_type'] && ! empty( $this->settings['access_token'] ) ) {
			$default_headers['Authorization'] = 'Bearer ' . $this->settings['access_token'];
		}

		$args = [
			'method'      => $method,
			'timeout'     => intval( $this->settings['timeout'] ),
			'redirection' => 5,
			'httpversion' => '1.0',
			'headers'     => wp_parse_args( $headers, $default_headers ),
			'sslverify'   => true,
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$msg = wp_remote_retrieve_response_message( $response );
			return new \WP_Error(
				'api_http_error',
				sprintf( __( 'API returned HTTP code %d: %s', 'wc-erp-sync' ), $code, $msg ),
				[ 'body' => wp_remote_retrieve_body( $response ) ]
			);
		}

		return $response;
	}
}
