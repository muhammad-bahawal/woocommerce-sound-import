<?php
namespace WCErpSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory class to retrieve the configured client adapter instance.
 */
class ClientFactory {

	/**
	 * Instantiate and return the active client adapter.
	 *
	 * @return ClientInterface
	 */
	public static function get_client() {
		$settings = get_option( 'wc_erp_sync_settings', [] );
		$adapter  = isset( $settings['client_adapter'] ) ? $settings['client_adapter'] : 'dummy';

		if ( 'soundimports' === $adapter ) {
			return new SoundImportsClient();
		}

		return new DummyClient();
	}
}
