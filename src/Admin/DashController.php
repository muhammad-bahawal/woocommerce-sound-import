<?php
namespace WCErpSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\MappingTable;
use WCErpSync\Database\QueueTable;
use WCErpSync\Database\LogTable;
use WCErpSync\Database\HistoryTable;

/**
 * Controller to extract performance analytics for the SaaS Dashboard UI.
 */
class DashController {

	/**
	 * Compute card KPI metrics.
	 */
	public static function get_summary_stats() {
		global $wpdb;

		// 1. Total products and categories mapped
		$mapped_products   = MappingTable::count_mappings( 'product' );
		$mapped_categories = MappingTable::count_mappings( 'category' );
		$mapped_vendors    = MappingTable::count_mappings( 'vendor' );

		// 2. Queue totals
		$pending_queue = QueueTable::count_by_status( 'pending' );
		$failed_queue  = QueueTable::count_by_status( 'failed' );

		// 3. Error counts (last 24 hours)
		$table_logs = LogTable::get_table_name();
		$errors_24h = (int) $wpdb->get_var(
			"SELECT COUNT(id) FROM $table_logs WHERE level = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
		);

		// 4. Calculate Sync Success Rate
		$table_history = HistoryTable::get_table_name();
		$history_stats = $wpdb->get_row(
			"SELECT SUM(records_success) as success, SUM(records_failed) as failed FROM $table_history",
			ARRAY_A
		);

		$success = isset( $history_stats['success'] ) ? intval( $history_stats['success'] ) : 0;
		$failed  = isset( $history_stats['failed'] ) ? intval( $history_stats['failed'] ) : 0;
		$total   = $success + $failed;

		$success_rate = $total > 0 ? round( ( $success / $total ) * 100, 1 ) : 100;

		return [
			'mapped_products'   => $mapped_products,
			'mapped_categories' => $mapped_categories,
			'mapped_vendors'    => $mapped_vendors,
			'pending_queue'     => $pending_queue,
			'failed_queue'      => $failed_queue,
			'errors_24h'        => $errors_24h,
			'success_rate'      => $success_rate,
			'total_synced'      => $success,
		];
	}

	/**
	 * Get the recent sync history list.
	 */
	public static function get_recent_history( $limit = 5 ) {
		return HistoryTable::get_history( $limit, 0 );
	}

	/**
	 * Get recent error entries.
	 */
	public static function get_recent_errors( $limit = 5 ) {
		return LogTable::get_logs( 'error', '', $limit, 0 );
	}
}
