<?php
namespace WCErpSync\Modules\Attribute;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles Product Attributes & Values synchronization.
 */
class AttributeModule extends BaseModule {

	public function get_entity_name() {
		return 'attribute';
	}

	public function get_title() {
		return __( 'Product Attributes', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		// Expects: { external_id: 'color', name: 'Color', values: ['Red', 'Blue'] }
		if ( empty( $raw_data['external_id'] ) || empty( $raw_data['name'] ) ) {
			return false;
		}

		$slug = sanitize_title( $raw_data['external_id'] );
		$name = sanitize_text_field( $raw_data['name'] );

		$this->log( 'info', sprintf( 'Importing Product Attribute taxonomy: %s (%s)', $name, $slug ) );

		$attribute_id = WooConnector::upsert_attribute( $slug, $name );

		if ( $attribute_id ) {
			$this->add_mapping( $raw_data['external_id'], $attribute_id, null, 'success' );

			// Sideload any attribute values if defined
			if ( ! empty( $raw_data['values'] ) && is_array( $raw_data['values'] ) ) {
				$taxonomy = wc_attribute_taxonomy_name( $slug );
				foreach ( $raw_data['values'] as $value ) {
					WooConnector::upsert_term( $value, $taxonomy );
				}
			}
			return $attribute_id;
		}

		return false;
	}

	public function export_item( $local_id ) {
		// local_id is attribute_id
		global $wpdb;
		$attribute = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d", $local_id ), ARRAY_A );

		if ( ! $attribute ) {
			return false;
		}

		$slug  = wc_attribute_taxonomy_name( $attribute['attribute_name'] );
		$terms = get_terms( [
			'taxonomy'   => $slug,
			'hide_empty' => false,
		] );

		$values = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$values[] = $term->name;
			}
		}

		return [
			'external_id' => $this->get_external_id( $local_id ),
			'name'        => $attribute['attribute_label'],
			'values'      => $values,
		];
	}
}
