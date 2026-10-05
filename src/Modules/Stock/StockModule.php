<?php
namespace WCErpSync\Modules\Stock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\Database\MappingTable;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles Stock/Inventory quantity adjustments.
 */
class StockModule extends BaseModule {

	public function get_entity_name() {
		return 'stock';
	}

	public function get_title() {
		return __( 'Inventory / Stock', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		// Expects array: { external_id: '...', stock: 45 }
		if ( empty( $raw_data['external_id'] ) || ! isset( $raw_data['stock'] ) ) {
			return false;
		}

		$external_id = $raw_data['external_id'];
		$stock_qty   = intval( $raw_data['stock'] );

		// Look up product or variation mapping
		$local_id = MappingTable::get_local_id( 'product', $external_id );

		if ( ! $local_id ) {
			// fallback check variation mapping
			$local_id = MappingTable::get_local_id( 'variation', $external_id );
		}

		if ( ! $local_id ) {
			$this->log( 'warning', sprintf( 'Unable to update stock: No local mapping found for external ID %s', $external_id ) );
			return false;
		}

		$this->log( 'info', sprintf( 'Updating stock for local ID %d to %d', $local_id, $stock_qty ) );
		return WooConnector::update_stock( $local_id, $stock_qty );
	}

	public function export_item( $local_id ) {
		$product = wc_get_product( $local_id );
		if ( ! $product ) {
			return false;
		}

		return [
			'external_id' => MappingTable::get_external_id( 'product', $local_id ),
			'stock'       => $product->get_stock_quantity(),
		];
	}
}
