<?php
namespace WCErpSync\Modules\Price;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\Database\MappingTable;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles Product Price synchronization.
 */
class PriceModule extends BaseModule {

	public function get_entity_name() {
		return 'price';
	}

	public function get_title() {
		return __( 'Pricing', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		// Expects: { external_id: '...', regular_price: 20.00, sale_price: 15.00 }
		if ( empty( $raw_data['external_id'] ) || ! isset( $raw_data['regular_price'] ) ) {
			return false;
		}

		$external_id   = $raw_data['external_id'];
		$regular_price = $raw_data['regular_price'];
		$sale_price    = isset( $raw_data['sale_price'] ) ? $raw_data['sale_price'] : '';

		$local_id = MappingTable::get_local_id( 'product', $external_id );
		if ( ! $local_id ) {
			$local_id = MappingTable::get_local_id( 'variation', $external_id );
		}

		if ( ! $local_id ) {
			$this->log( 'warning', sprintf( 'Unable to update price: No local mapping found for external ID %s', $external_id ) );
			return false;
		}

		$this->log( 'info', sprintf( 'Updating price for local ID %d: Reg %s, Sale %s', $local_id, $regular_price, $sale_price ) );
		return WooConnector::update_prices( $local_id, $regular_price, $sale_price );
	}

	public function export_item( $local_id ) {
		$product = wc_get_product( $local_id );
		if ( ! $product ) {
			return false;
		}

		return [
			'external_id'   => MappingTable::get_external_id( 'product', $local_id ),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
		];
	}
}
