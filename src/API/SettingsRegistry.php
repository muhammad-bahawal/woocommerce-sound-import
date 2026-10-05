<?php
namespace WCErpSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry holding structure and definitions of settings fields.
 */
class SettingsRegistry {

	/**
	 * Get the settings schema definitions.
	 */
	public static function get_schema() {
		return [
			'client_adapter' => [
				'type'        => 'select',
				'label'       => __( 'Active ERP / API Client', 'wc-erp-sync' ),
				'description' => __( 'Choose whether to query the live scrape endpoint or a mock sandbox.', 'wc-erp-sync' ),
				'default'     => 'soundimports',
				'options'     => [
					'soundimports' => __( 'SoundImports Scraper API (Live)', 'wc-erp-sync' ),
					'dummy'        => __( 'Mock Sandbox (Dummy Testing)', 'wc-erp-sync' ),
				],
				'section'     => 'connection',
			],
			'api_url'       => [
				'type'        => 'url',
				'label'       => __( 'API Base URL', 'wc-erp-sync' ),
				'description' => __( 'Endpoint base prefix of the SoundImports Scraper API.', 'wc-erp-sync' ),
				'default'     => 'https://audio-status-scrapper-production.up.railway.app',
				'section'     => 'connection',
			],
			'auth_type'     => [
				'type'        => 'select',
				'label'       => __( 'Authentication Type', 'wc-erp-sync' ),
				'description' => __( 'Select authorization mechanism.', 'wc-erp-sync' ),
				'default'     => 'none',
				'options'     => [
					'none'   => __( 'Open API (No Auth Required)', 'wc-erp-sync' ),
					'apikey' => __( 'API Key / Bearer Token (Optional)', 'wc-erp-sync' ),
				],
				'section'     => 'connection',
			],
			'api_key'       => [
				'type'        => 'password',
				'label'       => __( 'API Key (Optional)', 'wc-erp-sync' ),
				'description' => __( 'Optional authorization key if backend requires token in headers.', 'wc-erp-sync' ),
				'default'     => '',
				'section'     => 'connection',
			],
			'webhook_secret' => [
				'type'        => 'text',
				'label'       => __( 'Webhook Secret Token', 'wc-erp-sync' ),
				'description' => __( 'Optional string key used to validate payload signatures from ERP calls.', 'wc-erp-sync' ),
				'default'     => '',
				'section'     => 'webhooks',
			],
			'sync_frequency' => [
				'type'        => 'select',
				'label'       => __( 'Sync Run Frequency', 'wc-erp-sync' ),
				'description' => __( 'How often background cron imports products and inventory.', 'wc-erp-sync' ),
				'default'     => 'hourly',
				'options'     => [
					'every_15_mins' => __( 'Every 15 Minutes', 'wc-erp-sync' ),
					'twicedaily'    => __( 'Twice Daily', 'wc-erp-sync' ),
					'hourly'        => __( 'Hourly', 'wc-erp-sync' ),
					'daily'         => __( 'Daily', 'wc-erp-sync' ),
					'manual'        => __( 'Manual Sync Only', 'wc-erp-sync' ),
				],
				'section'     => 'sync',
			],
			'batch_size'    => [
				'type'        => 'number',
				'label'       => __( 'Batch Size Limit', 'wc-erp-sync' ),
				'description' => __( 'Max elements fetched per page call.', 'wc-erp-sync' ),
				'default'     => 50,
				'section'     => 'sync',
			],
			'sync_test_limit' => [
				'type'        => 'number',
				'label'       => __( 'Default Initial Sync Limit', 'wc-erp-sync' ),
				'description' => __( 'Default number of products to sync during manual test runs (e.g. 50 products initially, set 0 for all).', 'wc-erp-sync' ),
				'default'     => 50,
				'section'     => 'sync',
			],
			'timeout'       => [
				'type'        => 'number',
				'label'       => __( 'Connection Timeout (seconds)', 'wc-erp-sync' ),
				'description' => __( 'Wait limits for HTTP queries.', 'wc-erp-sync' ),
				'default'     => 30,
				'section'     => 'sync',
			],
			'default_stock_qty' => [
				'type'        => 'number',
				'label'       => __( 'Default In-Stock Quantity (Fallback)', 'wc-erp-sync' ),
				'description' => __( 'Stock quantity assigned when a product is in stock but API returns null/uncounted stock (e.g. 10). Set 0 to disable fallback.', 'wc-erp-sync' ),
				'default'     => 10,
				'section'     => 'sync',
			],
			'always_manage_stock' => [
				'type'        => 'select',
				'label'       => __( 'Stock Quantity Management', 'wc-erp-sync' ),
				'description' => __( 'Whether to always track stock quantity in WooCommerce for imported products.', 'wc-erp-sync' ),
				'default'     => 'yes',
				'options'     => [
					'yes' => __( 'Always Enable Stock Tracking (Quantity & Manage Stock)', 'wc-erp-sync' ),
					'no'  => __( 'Only Enable When Exact API Stock Number is Available', 'wc-erp-sync' ),
				],
				'section'     => 'sync',
			],
			'retries'       => [
				'type'        => 'number',
				'label'       => __( 'Max Queue Retry Attempts', 'wc-erp-sync' ),
				'description' => __( 'How many times a failed item is retried before failing permanently.', 'wc-erp-sync' ),
				'default'     => 3,
				'section'     => 'sync',
			],
		];
	}

	/**
	 * Sanitize and save input parameters.
	 */
	public static function sanitize_and_save( $input ) {
		$schema  = self::get_schema();
		$clean   = [];
		$current = get_option( 'wc_erp_sync_settings', [] );

		foreach ( $schema as $key => $field ) {
			if ( ! isset( $input[ $key ] ) ) {
				$clean[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : $field['default'];
				continue;
			}

			$val = $input[ $key ];

			switch ( $field['type'] ) {
				case 'url':
					$clean[ $key ] = esc_url_raw( $val );
					break;
				case 'number':
					$clean[ $key ] = intval( $val );
					break;
				case 'select':
					$clean[ $key ] = isset( $field['options'][ $val ] ) ? sanitize_text_field( $val ) : $field['default'];
					break;
				case 'password':
				case 'text':
				default:
					$clean[ $key ] = sanitize_text_field( $val );
					break;
			}
		}

		update_option( 'wc_erp_sync_settings', $clean );
		return $clean;
	}
}
