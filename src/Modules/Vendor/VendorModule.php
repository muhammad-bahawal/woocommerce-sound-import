<?php
namespace WCErpSync\Modules\Vendor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles Vendor / Supplier mapping and import.
 */
class VendorModule extends BaseModule {

	public function get_entity_name() {
		return 'vendor';
	}

	public function get_title() {
		return __( 'Vendors / Suppliers', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		if ( empty( $raw_data['external_id'] ) || empty( $raw_data['name'] ) ) {
			return false;
		}

		$external_id = $raw_data['external_id'];
		$this->log( 'info', sprintf( 'Importing Vendor: %s (ERP ID: %s)', $raw_data['name'], $external_id ) );

		// In WooCommerce, vendors can be stored as attribute terms (pa_vendor) or a custom taxonomy
		$taxonomy = 'pa_vendor';
		$local_id = WooConnector::upsert_term( $raw_data['name'], $taxonomy, $raw_data );

		if ( $local_id ) {
			$this->add_mapping( $external_id, $local_id, null, 'success' );
			return $local_id;
		}

		return false;
	}

	public function export_item( $local_id ) {
		$term = get_term( $local_id, 'pa_vendor' );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		return [
			'external_id' => $this->get_external_id( $local_id ),
			'name'        => $term->name,
		];
	}
}
