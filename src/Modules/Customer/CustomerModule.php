<?php
namespace WCErpSync\Modules\Customer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles WordPress/WooCommerce Customer accounts syncing.
 */
class CustomerModule extends BaseModule {

	public function get_entity_name() {
		return 'customer';
	}

	public function get_title() {
		return __( 'Customers', 'wc-erp-sync' );
	}

	public function import_item( $raw_data ) {
		if ( empty( $raw_data['external_id'] ) || empty( $raw_data['email'] ) ) {
			return false;
		}

		$external_id = $raw_data['external_id'];
		$this->log( 'info', sprintf( 'Importing Customer: %s (ERP ID: %s)', $raw_data['email'], $external_id ) );

		$local_id = WooConnector::upsert_customer( $raw_data );

		if ( $local_id ) {
			$this->add_mapping( $external_id, $local_id, null, 'success' );
			return $local_id;
		}

		return false;
	}

	public function export_item( $local_id ) {
		$user = get_userdata( $local_id );
		if ( ! $user ) {
			return false;
		}

		return [
			'external_id' => $this->get_external_id( $local_id ),
			'email'       => $user->user_email,
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
		];
	}
}
