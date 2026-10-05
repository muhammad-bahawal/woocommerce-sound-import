<?php
/**
 * Plugin Name: WooCommerce ERP Sync Integration Framework
 * Plugin URI:  https://github.com/your-repo/wc-erp-sync-framework
 * Description: A complete, modular, and scalable framework for integrating third-party ERP, inventory, or supplier APIs with WooCommerce.
 * Version:     1.0.0
 * Author:      Antigravity / DeepMind
 * License:     GPL2
 * Text Domain: wc-erp-sync
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires At Least: 5.6
 * WC requires at least: 5.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Plugin Constants
define( 'WC_ERP_SYNC_VERSION', '1.0.0' );
define( 'WC_ERP_SYNC_FILE', __FILE__ );
define( 'WC_ERP_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_ERP_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register PSR-4 Autoloader
 */
spl_autoload_register( function ( $class ) {
	// Prefix for namespace
	$prefix = 'WCErpSync\\';

	// Base directory for namespace prefix
	$base_dir = WC_ERP_SYNC_PATH . 'src/';

	// Does the class use the prefix?
	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	// Get the relative class name
	$relative_class = substr( $class, $len );

	// Replace namespace separators with directory separators in relative class name, append with .php
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	// If the file exists, require it
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Handle Plugin Activation
 */
function wc_erp_sync_activate() {
	// Enable WooCommerce stock management option
	if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	// Run DB Migrations
	WCErpSync\Database\Migrator::migrate();

	// Flush rewrite rules
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wc_erp_sync_activate' );

/**
 * Handle Plugin Deactivation
 */
function wc_erp_sync_deactivate() {
	// Clear scheduled cron jobs
	WCErpSync\Sync\Scheduler::clear_schedules();
}
register_deactivation_hook( __FILE__, 'wc_erp_sync_deactivate' );

/**
 * Bootstrap the Plugin Components
 */
add_action( 'plugins_loaded', function () {
	// Ensure WooCommerce is active
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'WooCommerce ERP Sync Framework requires WooCommerce to be installed and active.', 'wc-erp-sync' ); ?></p>
			</div>
			<?php
		} );
		return;
	}

	// Ensure WooCommerce manage_stock is enabled so stock quantity is tracked in store
	if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	// Auto-check and run DB migration if DB tables missing or version outdated
	if ( get_option( 'wc_erp_sync_db_version' ) !== WC_ERP_SYNC_VERSION ) {
		WCErpSync\Database\Migrator::migrate();
	}

	// Initialize Logger
	WCErpSync\Logger\Logger::init();

	// Initialize Sync Registry & load core modules
	WCErpSync\Modules\Registry::instance()->init();

	// Initialize Scheduler
	WCErpSync\Sync\Scheduler::init();

	// Initialize Admin Menu and Pages
	if ( is_admin() ) {
		WCErpSync\Admin\Pages::init();
		WCErpSync\Admin\Assets::init();
		WCErpSync\Admin\AJAXHandler::init();
	}
} );
