<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\MappingTable;

global $wpdb;

// Gather diagnostics
$php_version   = phpversion();
$wc_active     = class_exists( 'WooCommerce' ) ? 'Yes (v' . WC()->version . ')' : 'No';
$wp_version    = get_bloginfo( 'version' );
$db_version    = get_option( 'wc_erp_sync_db_version', 'N/A' );
$cron_enabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Disabled via config' : 'Enabled';
$write_logs    = wp_is_writable( wp_upload_dir()['basedir'] ) ? 'Writable' : 'Not Writable';

// Test DB tables count
$tables = [
	'wc_erp_sync_queue',
	'wc_erp_sync_mappings',
	'wc_erp_sync_logs',
	'wc_erp_sync_history',
];

$table_checks = [];
foreach ( $tables as $table_name ) {
	$table_exist = $wpdb->get_var( $wpdb->prepare(
		"SHOW TABLES LIKE %s",
		$wpdb->prefix . $table_name
	) );
	$table_checks[ $table_name ] = $table_exist ? true : false;
}
?>

<div class="status-container">
	<div class="dash-main-grid">
		<!-- System Environment Checklist -->
		<div class="panel-card double-width">
			<div class="panel-header">
				<h3><?php esc_html_e( 'System Check Diagnostic Report', 'wc-erp-sync' ); ?></h3>
			</div>
			
			<div class="panel-body">
				<table class="dash-table status-info-table">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Version', 'wc-erp-sync' ); ?></strong></td>
							<td>
								<code><?php echo esc_html( $php_version ); ?></code>
								<?php if ( version_compare( $php_version, '7.4', '>=' ) ) : ?>
									<span class="status-badge completed"><?php esc_html_e( 'Compatible', 'wc-erp-sync' ); ?></span>
								<?php else : ?>
									<span class="status-badge failed"><?php esc_html_e( 'Requires 7.4+', 'wc-erp-sync' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WordPress Core', 'wc-erp-sync' ); ?></strong></td>
							<td><code>v<?php echo esc_html( $wp_version ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WooCommerce Status', 'wc-erp-sync' ); ?></strong></td>
							<td>
								<code><?php echo esc_html( $wc_active ); ?></code>
								<span class="status-badge completed">✓ Active</span>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WordPress Background Cron', 'wc-erp-sync' ); ?></strong></td>
							<td>
								<code><?php echo esc_html( $cron_enabled ); ?></code>
								<?php if ( 'Enabled' === $cron_enabled ) : ?>
									<span class="status-badge completed">✓ OK</span>
								<?php else : ?>
									<span class="status-badge failed">⚠ Warning</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Uploads Directory Permission', 'wc-erp-sync' ); ?></strong></td>
							<td>
								<code><?php echo esc_html( $write_logs ); ?></code>
								<?php if ( 'Writable' === $write_logs ) : ?>
									<span class="status-badge completed">✓ OK</span>
								<?php else : ?>
									<span class="status-badge failed">⚠ Restrictive</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Active Mappings Count', 'wc-erp-sync' ); ?></strong></td>
							<td><code><?php echo esc_html( MappingTable::count_mappings() ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Plugin DB Version Option', 'wc-erp-sync' ); ?></strong></td>
							<td><code><?php echo esc_html( $db_version ); ?></code></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Database Tables Verify -->
		<div class="panel-card">
			<div class="panel-header">
				<h3><?php esc_html_e( 'Plugin Database Tables', 'wc-erp-sync' ); ?></h3>
			</div>

			<div class="panel-body">
				<p class="box-desc">
					<?php esc_html_e( 'Verifies if custom database tables were successfully created in the WordPress schema.', 'wc-erp-sync' ); ?>
				</p>

				<ul class="db-table-checklist">
					<?php foreach ( $table_checks as $tbl_name => $tbl_status ) : ?>
						<li class="table-check-item">
							<span class="dashicons <?php echo $tbl_status ? 'dashicons-database-export green' : 'dashicons-warning red'; ?>"></span>
							<div class="tbl-check-details">
								<code class="tbl-name"><?php echo esc_html( $wpdb->prefix . $tbl_name ); ?></code>
								<span class="tbl-status-text <?php echo $tbl_status ? 'green-text' : 'red-text'; ?>">
									<?php echo $tbl_status ? esc_html__( 'Connected & Ready', 'wc-erp-sync' ) : esc_html__( 'Table Missing', 'wc-erp-sync' ); ?>
								</span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div>
</div>
