<?php
namespace WCErpSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\API\DummyClient;
use WCErpSync\API\SettingsRegistry;
use WCErpSync\Sync\Engine;
use WCErpSync\Sync\BatchProcessor;
use WCErpSync\Sync\QueueManager;
use WCErpSync\Database\LogTable;
use WCErpSync\Database\MappingTable;
use WCErpSync\Database\QueueTable;

/**
 * Handles all AJAX endpoints triggered by the administration screens.
 */
class AJAXHandler {

	/**
	 * Setup hooks.
	 */
	public static function init() {
		$ajax_actions = [
			'test_connection',
			'save_settings',
			'run_manual_sync',
			'process_manual_batch',
			'reset_sync_page',
			'get_sync_status',
			'load_logs',
			'clear_logs',
			'save_mapping',
			'delete_mapping',
			'retry_queue',
			'clear_queue',
			'sync_single_sku',
			'sync_changed_products',
			'get_product_stats',
			'trigger_sync',
			'check_health',
			'get_categories',
			'run_category_sync',
			'process_category_batch',
		];

		foreach ( $ajax_actions as $action ) {
			add_action( 'wp_ajax_wc_erp_sync_' . $action, [ __CLASS__, 'handle_' . $action ] );
		}
	}



	/**
	 * Verify permissions and nonce check.
	 */
	private static function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden. Insufficient permissions.', 'wc-erp-sync' ) ], 403 );
		}

		check_ajax_referer( 'wc_erp_sync_admin_nonce', 'security' );

		// Ensure WooCommerce inventory management option is active
		if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			update_option( 'woocommerce_manage_stock', 'yes' );
		}
	}

	/**
	 * Test API credentials connection.
	 */
	public static function handle_test_connection() {
		self::verify_request();

		$client = \WCErpSync\API\ClientFactory::get_client();
		$result = $client->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Save dynamic settings panel options.
	 */
	public static function handle_save_settings() {
		self::verify_request();

		if ( ! isset( $_POST['settings'] ) || ! is_array( $_POST['settings'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid settings payload.', 'wc-erp-sync' ) ] );
		}

		$clean = SettingsRegistry::sanitize_and_save( $_POST['settings'] );

		// Flush cron triggers if schedule updated
		\WCErpSync\Sync\Scheduler::reschedule_events();

		wp_send_json_success( [
			'message'  => __( 'Settings saved successfully!', 'wc-erp-sync' ),
			'settings' => $clean,
		] );
	}

	/**
	 * Kickoff manual product sync: initializes job and syncs metadata quickly.
	 */
	public static function handle_run_manual_sync() {
		self::verify_request();

		$limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 50;

		$history_id = Engine::start_manual_sync( 'manual_full', $limit );

		if ( $history_id ) {
			wp_send_json_success( [
				'message'    => sprintf( __( 'Sync job initialized (Limit: %s products).', 'wc-erp-sync' ), $limit > 0 ? $limit : __( 'All', 'wc-erp-sync' ) ),
				'history_id' => $history_id,
				'limit'      => $limit,
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to trigger synchronization job.', 'wc-erp-sync' ) ] );
		}
	}

	/**
	 * Process a single batch chunk of products during manual sync.
	 */
	public static function handle_process_manual_batch() {
		self::verify_request();

		$history_id = isset( $_POST['history_id'] ) ? intval( $_POST['history_id'] ) : 0;
		$page       = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
		$limit      = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 50;
		$batch_size = 5; // Process 5 items per AJAX request for fast, timeout-free execution

		// Prevent execution timeout issues during product & image imports
		@set_time_limit( 300 );
		@ini_set( 'max_execution_time', '300' );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( ! $history_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid history ID.', 'wc-erp-sync' ) ] );
		}

		$result = Engine::process_manual_batch( $history_id, $page, $batch_size, $limit );
		wp_send_json_success( $result );
	}

	/**
	 * Reset page pointer back to Page 1 via AJAX.
	 */
	public static function handle_reset_sync_page() {
		self::verify_request();

		update_option( 'wc_erp_sync_next_page', 1 );
		wp_send_json_success( [ 'message' => __( 'Sync page pointer reset to Page 1.', 'wc-erp-sync' ), 'next_page' => 1 ] );
	}

	/**
	 * Fetch status of the queue and current job progress.
	 */
	public static function handle_get_sync_status() {
		self::verify_request();

		$status = BatchProcessor::get_current_status();
		wp_send_json_success( $status );
	}

	/**
	 * Fetch filtered DB logs.
	 */
	public static function handle_load_logs() {
		self::verify_request();

		$level  = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : '';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		$page   = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		$logs  = LogTable::get_logs( $level, $search, $limit, $offset );
		$total = LogTable::count_logs( $level, $search );

		// Format output context dates nicely
		foreach ( $logs as &$log ) {
			$log['created_at_formatted'] = date_i18n(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $log['created_at'] )
			);
		}

		wp_send_json_success( [
			'logs'  => $logs,
			'total' => $total,
			'pages' => ceil( $total / $limit ),
		] );
	}

	/**
	 * Clear DB activity logs.
	 */
	public static function handle_clear_logs() {
		self::verify_request();

		$result = LogTable::clear_logs();

		if ( $result !== false ) {
			wp_send_json_success( [ 'message' => __( 'Logs cleared successfully.', 'wc-erp-sync' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to clear logs.', 'wc-erp-sync' ) ] );
		}
	}

	/**
	 * Create/Edit a mapping pair.
	 */
	public static function handle_save_mapping() {
		self::verify_request();

		$type   = isset( $_POST['entity_type'] ) ? sanitize_text_field( $_POST['entity_type'] ) : '';
		$ext_id = isset( $_POST['external_id'] ) ? sanitize_text_field( $_POST['external_id'] ) : '';
		$loc_id = isset( $_POST['local_id'] ) ? intval( $_POST['local_id'] ) : 0;

		if ( empty( $type ) || empty( $ext_id ) || ! $loc_id ) {
			wp_send_json_error( [ 'message' => __( 'All mapping fields are required.', 'wc-erp-sync' ) ] );
		}

		$result = MappingTable::add_mapping( $type, $ext_id, $loc_id );

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Mapping saved successfully.', 'wc-erp-sync' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to save mapping. Does it already exist?', 'wc-erp-sync' ) ] );
		}
	}

	/**
	 * Remove a mapping index.
	 */
	public static function handle_delete_mapping() {
		self::verify_request();

		$type   = isset( $_POST['entity_type'] ) ? sanitize_text_field( $_POST['entity_type'] ) : '';
		$ext_id = isset( $_POST['external_id'] ) ? sanitize_text_field( $_POST['external_id'] ) : '';

		if ( empty( $type ) || empty( $ext_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid mapping parameters.', 'wc-erp-sync' ) ] );
		}

		$result = MappingTable::delete_mapping( $type, $ext_id );

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Mapping deleted.', 'wc-erp-sync' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to delete mapping.', 'wc-erp-sync' ) ] );
		}
	}

	/**
	 * Re-enqueue failed queue tasks.
	 */
	public static function handle_retry_queue() {
		self::verify_request();

		$count = QueueManager::retry_failed_jobs();
		wp_send_json_success( [ 'message' => sprintf( __( 'Re-enqueued %d failed sync items.', 'wc-erp-sync' ), $count ) ] );
	}

	/**
	 * Purge background queue table.
	 */
	public static function handle_clear_queue() {
		self::verify_request();

		QueueTable::clear_queue( 'all' );
		wp_send_json_success( [ 'message' => __( 'Background sync queue cleared.', 'wc-erp-sync' ) ] );
	}

	/**
	 * Sync a single product by SKU via AJAX.
	 */
	public static function handle_sync_single_sku() {
		self::verify_request();

		$sku = isset( $_POST['sku'] ) ? sanitize_text_field( $_POST['sku'] ) : '';

		if ( empty( $sku ) ) {
			wp_send_json_error( [ 'message' => __( 'SKU parameter is required.', 'wc-erp-sync' ) ] );
		}

		$module   = \WCErpSync\Modules\Registry::instance()->get_module( 'product' );
		$local_id = $module ? $module->import_product_by_sku( $sku ) : false;

		if ( $local_id ) {
			wp_send_json_success( [
				'message'  => sprintf( __( 'Successfully synced product SKU: %s (Local Product ID: %d)', 'wc-erp-sync' ), $sku, $local_id ),
				'local_id' => $local_id,
			] );
		} else {
			$client  = \WCErpSync\API\ClientFactory::get_client();
			$settings = get_option( 'wc_erp_sync_settings', [] );
			$api_url  = ! empty( $settings['api_url'] ) ? $settings['api_url'] : 'Not Configured';
			wp_send_json_error( [
				'message' => sprintf( __( 'Failed to sync product SKU: %s from ERP API. (Configured API Base URL: %s). Check Activity Logs or API Settings.', 'wc-erp-sync' ), $sku, $api_url ),
			] );
		}
	}

	/**
	 * Sync changed products since a given date via AJAX.
	 */
	public static function handle_sync_changed_products() {
		self::verify_request();

		$since  = isset( $_POST['since'] ) ? sanitize_text_field( $_POST['since'] ) : '';
		$result = Engine::run_incremental_sync( $since );

		wp_send_json_success( [
			'message' => sprintf( __( 'Found %d changed products since %s (%d queued for update).', 'wc-erp-sync' ), $result['total'], $result['since'], $result['queued'] ),
			'result'  => $result,
		] );
	}

	/**
	 * Fetch product stats from ERP API via AJAX.
	 */
	public static function handle_get_product_stats() {
		self::verify_request();

		$client = \WCErpSync\API\ClientFactory::get_client();
		$stats  = $client->fetch_stats();

		wp_send_json_success( $stats );
	}

	/**
	 * Trigger remote scraping/sync job via AJAX.
	 */
	public static function handle_trigger_sync() {
		self::verify_request();

		$incremental = isset( $_POST['incremental'] ) ? filter_var( $_POST['incremental'], FILTER_VALIDATE_BOOLEAN ) : false;

		$client = \WCErpSync\API\ClientFactory::get_client();
		$result = $client->trigger_sync( $incremental );

		if ( isset( $result['status'] ) && ( 'failed' === $result['status'] || 'error' === $result['status'] ) ) {
			wp_send_json_error( $result );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Check API health status via AJAX.
	 */
	public static function handle_check_health() {
		self::verify_request();

		$client = \WCErpSync\API\ClientFactory::get_client();
		$health = $client->check_health();

		if ( isset( $health['status'] ) && 'ok' === $health['status'] ) {
			wp_send_json_success( $health );
		} else {
			wp_send_json_error( $health );
		}
	}

	/**
	 * Fetch category list/tree from API client via AJAX.
	 */
	public static function handle_get_categories() {
		self::verify_request();

		$client     = \WCErpSync\API\ClientFactory::get_client();
		$categories = $client->fetch_categories();

		wp_send_json_success( [ 'categories' => $categories ] );
	}

	/**
	 * Kickoff category product sync: initializes job in history table.
	 */
	public static function handle_run_category_sync() {
		self::verify_request();

		$category_id = isset( $_POST['category_id'] ) ? sanitize_text_field( $_POST['category_id'] ) : '';
		if ( empty( $category_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Category ID is required.', 'wc-erp-sync' ) ] );
		}

		$history_id = \WCErpSync\Database\HistoryTable::start_job( 'manual_category' );

		if ( $history_id ) {
			wp_send_json_success( [
				'message'     => sprintf( __( 'Category sync job initialized for category "%s".', 'wc-erp-sync' ), $category_id ),
				'history_id'  => $history_id,
				'category_id' => $category_id,
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to trigger category sync job.', 'wc-erp-sync' ) ] );
		}
	}

	/**
	 * Process a single batch chunk of category products via AJAX.
	 */
	public static function handle_process_category_batch() {
		self::verify_request();

		// Prevent execution timeout issues during heavy product & image imports
		@set_time_limit( 300 );
		@ini_set( 'max_execution_time', '300' );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$history_id       = isset( $_POST['history_id'] ) ? intval( $_POST['history_id'] ) : 0;
		$category_id      = isset( $_POST['category_id'] ) ? sanitize_text_field( $_POST['category_id'] ) : '';
		$page             = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
		$include_children = isset( $_POST['include_children'] ) ? filter_var( $_POST['include_children'], FILTER_VALIDATE_BOOLEAN ) : true;
		$batch_size       = isset( $_POST['batch_size'] ) ? max( 1, min( 20, intval( $_POST['batch_size'] ) ) ) : 3;

		if ( ! $history_id || empty( $category_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request parameters for category batch.', 'wc-erp-sync' ) ] );
		}

		$result = Engine::process_category_manual_batch( $history_id, $category_id, $page, $batch_size, $include_children );
		wp_send_json_success( $result );
	}
}


