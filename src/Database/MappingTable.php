<?php
namespace WCErpSync\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access class for the Entity Mappings table.
 */
class MappingTable {

	/**
	 * Get the table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_erp_sync_mappings';
	}

	/**
	 * Add or update a mapping between an external ID and a local ID.
	 */
	public static function add_mapping( $entity_type, $external_id, $local_id, $hash = null, $last_sync_status = 'success' ) {
		global $wpdb;
		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		// Check if mapping exists
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE entity_type = %s AND external_id = %s",
			$entity_type,
			$external_id
		) );

		$data = [
			'entity_type'      => $entity_type,
			'external_id'      => $external_id,
			'local_id'         => $local_id,
			'hash'             => $hash,
			'last_sync_status' => $last_sync_status,
			'updated_at'       => $now,
		];
		$format = [ '%s', '%s', '%d', '%s', '%s', '%s' ];

		if ( $existing_id ) {
			$result = $wpdb->update(
				$table,
				$data,
				[ 'id' => $existing_id ],
				$format,
				[ '%d' ]
			);
			return $result !== false ? $existing_id : false;
		}

		$result = $wpdb->insert( $table, $data, $format );
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get local ID from external ID.
	 */
	public static function get_local_id( $entity_type, $external_id ) {
		global $wpdb;
		$table = self::get_table_name();

		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT local_id FROM $table WHERE entity_type = %s AND external_id = %s",
			$entity_type,
			$external_id
		) );

		return $val ? (int) $val : false;
	}

	/**
	 * Get external ID from local ID.
	 */
	public static function get_external_id( $entity_type, $local_id ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->get_var( $wpdb->prepare(
			"SELECT external_id FROM $table WHERE entity_type = %s AND local_id = %d",
			$entity_type,
			$local_id
		) );
	}

	/**
	 * Get mapping object.
	 */
	public static function get_mapping( $entity_type, $external_id ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE entity_type = %s AND external_id = %s",
			$entity_type,
			$external_id
		), ARRAY_A );
	}

	/**
	 * Delete mapping.
	 */
	public static function delete_mapping( $entity_type, $external_id ) {
		global $wpdb;
		$table = self::get_table_name();

		return $wpdb->delete(
			$table,
			[ 'entity_type' => $entity_type, 'external_id' => $external_id ],
			[ '%s', '%s' ]
		);
	}

	/**
	 * Query mapping records (for admin screens).
	 */
	public static function get_mappings( $entity_type = '', $limit = 50, $offset = 0, $search = '' ) {
		global $wpdb;
		$table = self::get_table_name();

		$conditions = [];
		$params     = [];

		if ( ! empty( $entity_type ) && $entity_type !== 'all' ) {
			$conditions[] = "entity_type = %s";
			$params[]     = $entity_type;
		}

		if ( ! empty( $search ) ) {
			$conditions[] = "(external_id LIKE %s OR local_id = %d)";
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]     = is_numeric( $search ) ? intval( $search ) : 0;
		}

		$where = '';
		if ( ! empty( $conditions ) ) {
			$where = "WHERE " . implode( " AND ", $conditions );
		}

		$query = "SELECT * FROM $table $where ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, $params );
		}

		$results = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Count total mapping records.
	 */
	public static function count_mappings( $entity_type = '', $search = '' ) {
		global $wpdb;
		$table = self::get_table_name();

		$conditions = [];
		$params     = [];

		if ( ! empty( $entity_type ) && $entity_type !== 'all' ) {
			$conditions[] = "entity_type = %s";
			$params[]     = $entity_type;
		}

		if ( ! empty( $search ) ) {
			$conditions[] = "(external_id LIKE %s OR local_id = %d)";
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]     = is_numeric( $search ) ? intval( $search ) : 0;
		}

		$where = '';
		if ( ! empty( $conditions ) ) {
			$where = "WHERE " . implode( " AND ", $conditions );
		}

		$query = "SELECT COUNT(id) FROM $table $where";

		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, $params );
		}

		return (int) $wpdb->get_var( $query );
	}
}
