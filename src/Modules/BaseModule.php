<?php
namespace WCErpSync\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Logger\Logger;
use WCErpSync\Database\MappingTable;

/**
 * Base class for all sync modules.
 */
abstract class BaseModule {

	/**
	 * Get the unique entity key identifier.
	 *
	 * @return string
	 */
	abstract public function get_entity_name();

	/**
	 * Get the human readable label.
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Process importing a raw item from ERP.
	 *
	 * @param array $raw_data The payload structure from the API.
	 * @return int|bool Local WP ID if successful, false on error.
	 */
	abstract public function import_item( $raw_data );

	/**
	 * Process exporting an item to the ERP.
	 *
	 * @param int $local_id WooCommerce ID.
	 * @return array|bool Response structure or false.
	 */
	abstract public function export_item( $local_id );

	/**
	 * Shortcut helper to log from the module context.
	 */
	protected function log( $level, $message, $context = null ) {
		Logger::log( $level, $message, $this->get_entity_name() . '_module', $context );
	}

	/**
	 * Helper to lookup local WP ID mapped to ERP external ID.
	 */
	protected function get_local_id( $external_id ) {
		return MappingTable::get_local_id( $this->get_entity_name(), $external_id );
	}

	/**
	 * Helper to lookup external ERP ID mapped to local WP ID.
	 */
	protected function get_external_id( $local_id ) {
		return MappingTable::get_external_id( $this->get_entity_name(), $local_id );
	}

	/**
	 * Helper to insert or update mapping index.
	 */
	protected function add_mapping( $external_id, $local_id, $hash = null, $last_status = 'success' ) {
		return MappingTable::add_mapping( $this->get_entity_name(), $external_id, $local_id, $hash, $last_status );
	}
}
