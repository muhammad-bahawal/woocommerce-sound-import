<?php
namespace WCErpSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles enqueuing styles and scripts in wp-admin.
 */
class Assets {

	/**
	 * Setup hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue stylesheet and script files.
	 */
	public static function enqueue_assets( $hook ) {
		// Only enqueue on our plugin pages
		if ( strpos( $hook, 'wc-erp-sync' ) === false ) {
			return;
		}

		$css_ver = file_exists( WC_ERP_SYNC_PATH . 'assets/css/admin-dashboard.css' )
			? filemtime( WC_ERP_SYNC_PATH . 'assets/css/admin-dashboard.css' )
			: WC_ERP_SYNC_VERSION;

		$js_ver = file_exists( WC_ERP_SYNC_PATH . 'assets/js/admin-dashboard.js' )
			? filemtime( WC_ERP_SYNC_PATH . 'assets/js/admin-dashboard.js' )
			: WC_ERP_SYNC_VERSION;

		wp_enqueue_style(
			'wc-erp-sync-dashboard',
			WC_ERP_SYNC_URL . 'assets/css/admin-dashboard.css',
			[],
			$css_ver
		);

		wp_enqueue_script(
			'wc-erp-sync-dashboard',
			WC_ERP_SYNC_URL . 'assets/js/admin-dashboard.js',
			[ 'jquery' ],
			$js_ver,
			true
		);

		// Localize parameters for AJAX execution
		wp_localize_script(
			'wc-erp-sync-dashboard',
			'wcErpSyncLocal',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wc_erp_sync_admin_nonce' ),
				'strings'  => [
					'connecting' => __( 'Connecting...', 'wc-erp-sync' ),
					'running'    => __( 'Syncing running...', 'wc-erp-sync' ),
					'confirm'    => __( 'Are you sure you want to proceed?', 'wc-erp-sync' ),
				]
			]
		);
	}
}
