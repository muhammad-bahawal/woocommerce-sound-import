<?php
namespace WCErpSync\Modules\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;
use WCErpSync\WooCommerce\OrderMapper;
use WCErpSync\API\DummyClient;

/**
 * Handles WooCommerce Order synchronization and status updates.
 */
class OrderModule extends BaseModule {

	public function get_entity_name() {
		return 'order';
	}

	public function get_title() {
		return __( 'Orders', 'wc-erp-sync' );
	}

	/**
	 * Import order updates from ERP (e.g. status changes, shipment tracking info).
	 */
	public function import_item( $raw_data ) {
		// Expects: { external_id: '...', status: 'completed', tracking_number: '...' }
		if ( empty( $raw_data['external_id'] ) ) {
			return false;
		}

		$external_id = $raw_data['external_id'];
		$local_id    = $this->get_local_id( $external_id );

		if ( ! $local_id ) {
			$this->log( 'warning', sprintf( 'Failed to update order: No local order found mapped to ERP ID %s', $external_id ) );
			return false;
		}

		$this->log( 'info', sprintf( 'Updating Order ID: %d from ERP status', $local_id ) );

		return WooConnector::update_order_status( $local_id, $raw_data );
	}

	/**
	 * Export order from WooCommerce to ERP.
	 */
	public function export_item( $local_id ) {
		$order = wc_get_order( $local_id );
		if ( ! $order ) {
			return false;
		}

		$this->log( 'info', sprintf( 'Exporting WooCommerce Order #%d to ERP API', $local_id ) );

		// 1. Map WooCommerce Order object to generic API format
		$payload = OrderMapper::to_api_format( $order );

		// 2. Transmit using client adapter
		// Determine active client (we load DummyClient for now, but we prepare for active client injection)
		$client = \WCErpSync\API\ClientFactory::get_client();
		$result = $client->push_order( $local_id, $payload );

		if ( ! empty( $result['success'] ) && ! empty( $result['external_id'] ) ) {
			$this->add_mapping( $result['external_id'], $local_id, null, 'success' );
			$this->log( 'info', sprintf( 'Successfully exported Order #%d to ERP. ERP Order ID: %s', $local_id, $result['external_id'] ) );
			
			// Store external reference
			$order->update_meta_data( '_erp_external_id', $result['external_id'] );
			$order->save();
			
			return $result;
		}

		$this->log( 'error', sprintf( 'Failed to export Order #%d: %s', $local_id, isset( $result['message'] ) ? $result['message'] : 'Unknown error' ) );
		return false;
	}
}
