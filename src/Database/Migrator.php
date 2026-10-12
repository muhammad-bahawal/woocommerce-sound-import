<?php
namespace WCErpSync\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database table installation and updates.
 */
class Migrator {

	/**
	 * Run custom table migrations.
	 */
	public static function migrate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_queue    = $wpdb->prefix . 'wc_erp_sync_queue';
		$table_mappings = $wpdb->prefix . 'wc_erp_sync_mappings';
		$table_logs     = $wpdb->prefix . 'wc_erp_sync_logs';
		$table_history  = $wpdb->prefix . 'wc_erp_sync_history';

		$sql = [];

		// 1. Sync Queue Table
		$sql[] = "CREATE TABLE $table_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(50) NOT NULL,
			action varchar(20) NOT NULL,
			external_id varchar(100) DEFAULT NULL,
			local_id bigint(20) unsigned DEFAULT NULL,
			payload longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts int(11) NOT NULL DEFAULT 0,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_entity (entity_type)
		) $charset_collate;";

		// 2. Mappings Table
		$sql[] = "CREATE TABLE $table_mappings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(50) NOT NULL,
			external_id varchar(100) NOT NULL,
			local_id bigint(20) unsigned NOT NULL,
			hash varchar(64) DEFAULT NULL,
			last_sync_status varchar(20) NOT NULL DEFAULT 'success',
			created_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			last_stock_updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_entity_external (entity_type, external_id),
			KEY idx_entity_local (entity_type, local_id),
			KEY idx_entity_updated (entity_type, updated_at),
			KEY idx_entity_created (entity_type, created_at)
		) $charset_collate;";

		// 3. Database Logs Table
		$sql[] = "CREATE TABLE $table_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL,
			module varchar(50) NOT NULL,
			message text NOT NULL,
			context longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_level (level),
			KEY idx_created (created_at)
		) $charset_collate;";

		// 4. Sync Runs History Table
		$sql[] = "CREATE TABLE $table_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(50) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			records_processed int(11) NOT NULL DEFAULT 0,
			records_success int(11) NOT NULL DEFAULT 0,
			records_failed int(11) NOT NULL DEFAULT 0,
			duration int(11) NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			ended_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_job_status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		// Ensure created_at is populated for existing rows if created_at is NULL
		$wpdb->query( "UPDATE $table_mappings SET created_at = updated_at WHERE created_at IS NULL" );

		update_option( 'wc_erp_sync_db_version', WC_ERP_SYNC_VERSION );
	}
}
