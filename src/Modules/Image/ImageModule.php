<?php
namespace WCErpSync\Modules\Image;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles downloading and assigning remote ERP images to WooCommerce.
 */
class ImageModule extends BaseModule {

	public function get_entity_name() {
		return 'image';
	}

	public function get_title() {
		return __( 'Product Images', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		// Expects: { external_id: '...', image_url: '...', parent_product_id: 123 }
		if ( empty( $raw_data['image_url'] ) ) {
			return false;
		}

		$image_url    = $raw_data['image_url'];
		$parent_id    = isset( $raw_data['parent_product_id'] ) ? intval( $raw_data['parent_product_id'] ) : 0;
		$external_id  = isset( $raw_data['external_id'] ) ? $raw_data['external_id'] : md5( $image_url );

		$this->log( 'info', sprintf( 'Sideloading Image: %s', $image_url ) );

		// Check if already sideloaded
		$attachment_id = $this->get_local_id( $external_id );

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			$this->log( 'info', sprintf( 'Image already imported. Local Attachment ID: %d', $attachment_id ) );
			return $attachment_id;
		}

		// Download and sideload attachment
		$attachment_id = WooConnector::sideload_image( $image_url, $parent_id );

		if ( $attachment_id ) {
			$this->add_mapping( $external_id, $attachment_id, null, 'success' );
			$this->log( 'info', sprintf( 'Successfully sideloaded remote image. Local Attachment ID: %d', $attachment_id ) );
			return $attachment_id;
		}

		$this->log( 'error', sprintf( 'Failed to sideload remote image: %s', $image_url ) );
		return false;
	}

	public function export_item( $local_id ) {
		if ( ! wp_attachment_is_image( $local_id ) ) {
			return false;
		}

		return [
			'external_id' => $this->get_external_id( $local_id ),
			'image_url'   => wp_get_attachment_url( $local_id ),
		];
	}
}
