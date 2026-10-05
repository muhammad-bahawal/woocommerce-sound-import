<?php
namespace WCErpSync\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access class for the Sync Execution History table.
 */
class HistoryTable {

	/**
	 * Get the table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_erp_sync_history';
	}

	/**
	 * Insert a new sync history job when it starts.
	 */
	public static function start_job( $job_type ) {
		global $wpdb;
		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		$data = [
			'job_type'          => $job_type,
			'status'            => 'running',
			'records_processed' => 0,
			'records_success'   => 0,
			'records_failed'    => 0,
			'duration'          => 0,
			'started_at'        => $now,
			'ended_at'          => null,
		];

		$result = $wpdb->insert(
			$table,
			$data,
			[ '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update the progress counts of a running sync job.
	 */
	public static function update_job_progress( $id, $records_processed, $records_success, $records_failed ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->update(
			$table,
			[
				'records_processed' => $records_processed,
				'records_success'   => $records_success,
				'records_failed'    => $records_failed,
			],
			[ 'id' => $id ],
			[ '%d', '%d', '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Complete a sync history job and calculate its duration.
	 */
	public static function complete_job( $id, $status = 'completed' ) {
		global $wpdb;
		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		$job = $wpdb->get_row( $wpdb->prepare( "SELECT started_at FROM $table WHERE id = %d", $id ), ARRAY_A );
		$duration = 0;

		if ( $job ) {
			$duration = time() - strtotime( $job['started_at'] );
		}

		return $wpdb->update(
			$table,
			[
				'status'   => $status,
				'duration' => $duration,
				'ended_at' => $now,
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Get list of sync history entries.
	 */
	public static function get_history( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::get_table_name();

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table ORDER BY started_at DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		), ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Count total history items.
	 */
	public static function count_history() {
		global $wpdb;
		$table = self::get_table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table" );
	}

	/**
	 * Get the latest job of a specific type.
	 */
	public static function get_latest_job( $job_type = '' ) {
		global $wpdb;
		$table = self::get_table_name();

		if ( ! empty( $job_type ) ) {
			return $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table WHERE job_type = %s ORDER BY started_at DESC LIMIT 1",
				$job_type
			), ARRAY_A );
		}

		return $wpdb->get_row( "SELECT * FROM $table ORDER BY started_at DESC LIMIT 1", ARRAY_A );
	}

	/**
	 * Clear history.
	 */
	public static function clear_history() {
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->query( "TRUNCATE TABLE $table" );
	}
}
