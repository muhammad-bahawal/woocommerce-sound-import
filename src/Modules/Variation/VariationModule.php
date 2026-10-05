<?php
namespace WCErpSync\Modules\Variation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\Database\MappingTable;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles variable product variations imports.
 */
class VariationModule extends BaseModule {

	public function get_entity_name() {
		return 'variation';
	}

	public function get_title() {
		return __( 'Variations', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		// Expects variation payload with parent_external_id
		if ( empty( $raw_data['external_id'] ) || empty( $raw_data['parent_external_id'] ) ) {
			return false;
		}

		$external_id        = $raw_data['external_id'];
		$parent_external_id = $raw_data['parent_external_id'];

		$parent_local_id = MappingTable::get_local_id( 'product', $parent_external_id );

		if ( ! $parent_local_id ) {
			$this->log( 'error', sprintf( 'Failed to import variation %s: Parent product %s not mapped yet.', $external_id, $parent_external_id ) );
			return false;
		}

		$this->log( 'info', sprintf( 'Importing variation: %s for variable product ID: %d', $external_id, $parent_local_id ) );

		$local_id = WooConnector::upsert_variation( $parent_local_id, $raw_data );

		if ( $local_id ) {
			$this->add_mapping( $external_id, $local_id, null, 'success' );
			return $local_id;
		}

		return false;
	}

	public function export_item( $local_id ) {
		$variation = wc_get_product( $local_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return false;
		}

		return [
			'external_id'        => $this->get_external_id( $local_id ),
			'parent_external_id' => MappingTable::get_external_id( 'product', $variation->get_parent_id() ),
			'sku'                => $variation->get_sku(),
			'price'              => $variation->get_price(),
			'stock'              => $variation->get_stock_quantity(),
			'attributes'         => $variation->get_attributes(),
		];
	}
}
