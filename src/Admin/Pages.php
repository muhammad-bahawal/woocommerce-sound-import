<?php
namespace WCErpSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WordPress admin menus and routes template rendering.
 */
class Pages {

	/**
	 * Menu Page Slug.
	 */
	const MENU_SLUG = 'wc-erp-sync';

	/**
	 * Setup hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_menus' ] );
	}

	/**
	 * Register WooCommerce submenu or main menu.
	 */
	public static function register_admin_menus() {
		add_menu_page(
			__( 'ERP Sync Dashboard', 'wc-erp-sync' ),
			__( 'ERP Sync', 'wc-erp-sync' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_admin_page' ],
			'dashicons-randomize',
			56
		);
	}

	/**
	 * Render the unified control panel.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-erp-sync' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$valid_tabs = [
			'dashboard',
			'settings',
			'sync',
			'mappings',
			'queue',
			'logs',
			'status',
		];

		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'dashboard';
		}

		// Save parameters context for template use
		$template_file = WC_ERP_SYNC_PATH . 'templates/' . $tab . '.php';

		// Include main master wrapper view
		?>
		<div class="wrap wc-erp-sync-wrapper">
			<header class="erp-sync-header">
				<div class="header-logo">
					<span class="dashicons dashicons-randomize logo-icon"></span>
					<h1><?php esc_html_e( 'ERP Sync & Integration Framework', 'wc-erp-sync' ); ?></h1>
				</div>
				<div class="header-badge">
					<span class="badge-dot pulse"></span>
					<span class="badge-text"><?php esc_html_e( 'System Status: Active', 'wc-erp-sync' ); ?></span>
				</div>
			</header>

			<div class="erp-sync-layout">
				<!-- Sidebar Nav -->
				<aside class="erp-sync-sidebar">
					<nav class="sidebar-nav">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=dashboard' ) ); ?>" class="nav-item <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-dashboard"></span>
							<?php esc_html_e( 'Dashboard', 'wc-erp-sync' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings' ) ); ?>" class="nav-item <?php echo $tab === 'settings' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-admin-settings"></span>
							<?php esc_html_e( 'API & Settings', 'wc-erp-sync' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=sync' ) ); ?>" class="nav-item <?php echo $tab === 'sync' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Manual Sync', 'wc-erp-sync' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=mappings' ) ); ?>" class="nav-item <?php echo $tab === 'mappings' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Product Mapping', 'wc-erp-sync' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=queue' ) ); ?>" class="nav-item <?php echo $tab === 'queue' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-list-view"></span>
							<?php esc_html_e( 'Queue Management', 'wc-erp-sync' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=logs' ) ); ?>" class="nav-item <?php echo $tab === 'logs' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-welcome-write-blog"></span>
							<?php esc_html_e( 'Activity Logs', 'wc-erp-sync' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=status' ) ); ?>" class="nav-item <?php echo $tab === 'status' ? 'active' : ''; ?>">
							<span class="dashicons dashicons-info"></span>
							<?php esc_html_e( 'System Status', 'wc-erp-sync' ); ?>
						</a>
					</nav>
				</aside>

				<!-- Main Card Content Area -->
				<main class="erp-sync-main-content">
					<?php
					if ( file_exists( $template_file ) ) {
						include_once $template_file;
					} else {
						printf( '<p>%s</p>', esc_html__( 'Template file not found.', 'wc-erp-sync' ) );
					}
					?>
				</main>
			</div>
		</div>
		<?php
	}
}
