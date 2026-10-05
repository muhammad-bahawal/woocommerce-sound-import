<?php
namespace WCErpSync\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\QueueTable;

/**
 * Interface coordinator to queue synchronization jobs safely.
 */
class QueueManager {

	/**
	 * Queue an entity for import.
	 */
	public static function enqueue_import( $entity_type, $external_id, $payload = null ) {
		return QueueTable::add_item( $entity_type, 'import', $external_id, null, $payload );
	}

	/**
	 * Queue an entity for export.
	 */
	public static function enqueue_export( $entity_type, $local_id ) {
		return QueueTable::add_item( $entity_type, 'export', null, $local_id, null );
	}

	/**
	 * Retry all failed jobs.
	 */
	public static function retry_failed_jobs() {
		global $wpdb;
		$table = QueueTable::get_table_name();

		return $wpdb->query(
			"UPDATE $table SET status = 'pending', attempts = 0, error_message = NULL WHERE status = 'failed'"
		);
	}
}
