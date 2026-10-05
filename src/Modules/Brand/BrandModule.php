<?php
namespace WCErpSync\Modules\Brand;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles Product Brand mapping and import.
 */
class BrandModule extends BaseModule {

	public function get_entity_name() {
		return 'brand';
	}

	public function get_title() {
		return __( 'Brands', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		$name = isset( $raw_data['name'] ) ? sanitize_text_field( $raw_data['name'] ) : '';
		if ( empty( $name ) ) {
			return false;
		}

		$external_id = isset( $raw_data['external_id'] ) ? $raw_data['external_id'] : sanitize_title( $name );
		$this->log( 'info', sprintf( 'Importing Brand: %s (ERP ID: %s)', $name, $external_id ) );

		$taxonomy = 'pa_brand';
		$local_id = WooConnector::upsert_term( $name, $taxonomy, $raw_data );

		if ( $local_id ) {
			$this->add_mapping( $external_id, $local_id, null, 'success' );
			return $local_id;
		}

		return false;
	}

	public function export_item( $local_id ) {
		$term = get_term( $local_id, 'pa_brand' );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		return [
			'external_id' => $this->get_external_id( $local_id ),
			'name'        => $term->name,
		];
	}
}
