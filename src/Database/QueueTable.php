<?php
namespace WCErpSync\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access class for the background Sync Queue table.
 */
class QueueTable {

	/**
	 * Get the table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_erp_sync_queue';
	}

	/**
	 * Add an item to the sync queue.
	 */
	public static function add_item( $entity_type, $action, $external_id = null, $local_id = null, $payload = null ) {
		global $wpdb;

		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		// Check if it already exists as pending to prevent duplicates
		$query = $wpdb->prepare(
			"SELECT id FROM $table WHERE entity_type = %s AND action = %s AND status = 'pending'",
			$entity_type,
			$action
		);

		if ( $external_id !== null ) {
			$query .= $wpdb->prepare( " AND external_id = %s", $external_id );
		} else {
			$query .= " AND external_id IS NULL";
		}

		if ( $local_id !== null ) {
			$query .= $wpdb->prepare( " AND local_id = %d", $local_id );
		} else {
			$query .= " AND local_id IS NULL";
		}

		$existing = $wpdb->get_var( $query );

		if ( $existing ) {
			// Update the updated_at timestamp and maybe update payload if given
			$update_data = [ 'updated_at' => $now ];
			$update_format = [ '%s' ];
			if ( $payload !== null ) {
				$update_data['payload'] = is_array( $payload ) || is_object( $payload ) ? wp_json_encode( $payload ) : $payload;
				$update_format[] = '%s';
			}
			$wpdb->update( $table, $update_data, [ 'id' => $existing ], $update_format, [ '%d' ] );
			return $existing;
		}

		// Insert new queue job
		$data = [
			'entity_type'   => $entity_type,
			'action'        => $action,
			'external_id'   => $external_id,
			'local_id'      => $local_id,
			'payload'       => is_array( $payload ) || is_object( $payload ) ? wp_json_encode( $payload ) : $payload,
			'status'        => 'pending',
			'attempts'      => 0,
			'created_at'    => $now,
			'updated_at'    => $now,
		];

		$format = [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ];

		$result = $wpdb->insert( $table, $data, $format );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get pending queue items.
	 */
	public static function get_pending_items( $limit = 50 ) {
		global $wpdb;
		$table = self::get_table_name();

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE status = 'pending' AND attempts < 5 ORDER BY id ASC LIMIT %d",
			$limit
		), ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Update item status.
	 */
	public static function update_status( $id, $status, $error_message = '' ) {
		global $wpdb;
		$table = self::get_table_name();

		$data = [
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		];
		$format = [ '%s', '%s' ];

		if ( ! empty( $error_message ) ) {
			$data['error_message'] = $error_message;
			$format[] = '%s';
		}

		return $wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
	}

	/**
	 * Increment attempts for a queue item.
	 */
	public static function increment_attempts( $id ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->query( $wpdb->prepare(
			"UPDATE $table SET attempts = attempts + 1, updated_at = %s WHERE id = %d",
			current_time( 'mysql' ),
			$id
		) );
	}

	/**
	 * Get items by status.
	 */
	public static function get_items_by_status( $status, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::get_table_name();

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$status,
			$limit,
			$offset
		), ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Count total queue items by status.
	 */
	public static function count_by_status( $status = 'all' ) {
		global $wpdb;
		$table = self::get_table_name();

		if ( 'all' === $status ) {
			return (int) $wpdb->get_var( "SELECT COUNT(id) FROM $table" );
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(id) FROM $table WHERE status = %s",
			$status
		) );
	}

	/**
	 * Retrieve queue dashboard stats.
	 */
	public static function get_queue_stats() {
		global $wpdb;
		$table = self::get_table_name();

		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM $table GROUP BY status",
			ARRAY_A
		);

		$stats = [
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
		];

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				if ( isset( $row['status'], $stats[ $row['status'] ] ) ) {
					$stats[ $row['status'] ] = (int) $row['count'];
				}
			}
		}

		return $stats;
	}

	/**
	 * Clear queue items.
	 */
	public static function clear_queue( $status = 'all' ) {
		global $wpdb;
		$table = self::get_table_name();

		if ( 'all' === $status ) {
			return $wpdb->query( "TRUNCATE TABLE $table" );
		}

		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM $table WHERE status = %s",
			$status
		) );
	}

	/**
	 * Delete a single item.
	 */
	public static function delete_item( $id ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}
}
