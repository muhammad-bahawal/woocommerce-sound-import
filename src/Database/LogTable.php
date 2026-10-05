<?php
namespace WCErpSync\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access class for the DB-based Sync Logs table.
 */
class LogTable {

	/**
	 * Get the table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_erp_sync_logs';
	}

	/**
	 * Add a log entry.
	 */
	public static function add_log( $level, $module, $message, $context = null ) {
		global $wpdb;
		$table = self::get_table_name();

		$data = [
			'level'      => $level,
			'module'     => $module,
			'message'    => $message,
			'context'    => is_array( $context ) || is_object( $context ) ? wp_json_encode( $context ) : $context,
			'created_at' => current_time( 'mysql' ),
		];

		return $wpdb->insert(
			$table,
			$data,
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Get filtered logs.
	 */
	public static function get_logs( $level = '', $search = '', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::get_table_name();

		$conditions = [];
		$params     = [];

		if ( ! empty( $level ) && $level !== 'all' ) {
			$conditions[] = "level = %s";
			$params[]     = $level;
		}

		if ( ! empty( $search ) ) {
			$conditions[] = "(message LIKE %s OR module LIKE %s)";
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where = '';
		if ( ! empty( $conditions ) ) {
			$where = "WHERE " . implode( " AND ", $conditions );
		}

		$query = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Count filtered logs.
	 */
	public static function count_logs( $level = '', $search = '' ) {
		global $wpdb;
		$table = self::get_table_name();

		$conditions = [];
		$params     = [];

		if ( ! empty( $level ) && $level !== 'all' ) {
			$conditions[] = "level = %s";
			$params[]     = $level;
		}

		if ( ! empty( $search ) ) {
			$conditions[] = "(message LIKE %s OR module LIKE %s)";
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where = '';
		if ( ! empty( $conditions ) ) {
			$where = "WHERE " . implode( " AND ", $conditions );
		}

		$query = "SELECT COUNT(id) FROM $table $where";

		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) );
		}

		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Clear all logs.
	 */
	public static function clear_logs() {
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->query( "TRUNCATE TABLE $table" );
	}

	/**
	 * Purge logs older than X days.
	 */
	public static function purge_old_logs( $days = 30 ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}
}
