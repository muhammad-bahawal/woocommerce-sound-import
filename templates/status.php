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

	<!-- Cron Schedule Diagnostics & Setup Guide -->
	<div class="panel-card full-width" style="margin-top: 20px;">
		<div class="panel-header">
			<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Background Automation & Cron Schedules', 'wc-erp-sync' ); ?></h3>
		</div>
		<div class="panel-body">
			<?php
			$next_full  = wp_next_scheduled( \WCErpSync\Sync\Scheduler::CRON_ACTION );
			$sched_full = wp_get_schedule( \WCErpSync\Sync\Scheduler::CRON_ACTION );
			$next_stock = wp_next_scheduled( \WCErpSync\Sync\Scheduler::STOCK_CRON_ACTION );
			$sched_stock= wp_get_schedule( \WCErpSync\Sync\Scheduler::STOCK_CRON_ACTION );
			?>
			<table class="dash-table status-info-table" style="margin-bottom: 20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Automation Task', 'wc-erp-sync' ); ?></th>
						<th><?php esc_html_e( 'Hook Name', 'wc-erp-sync' ); ?></th>
						<th><?php esc_html_e( 'Configured Frequency', 'wc-erp-sync' ); ?></th>
						<th><?php esc_html_e( 'Next Scheduled Run', 'wc-erp-sync' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-erp-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Full / Incremental Catalog Sync', 'wc-erp-sync' ); ?></strong></td>
						<td><code>wc_erp_sync_run_cron</code></td>
						<td><?php echo esc_html( $sched_full ? $sched_full : __( 'Not Scheduled / Manual', 'wc-erp-sync' ) ); ?></td>
						<td><?php echo $next_full ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_full ) . ' (' . human_time_diff( time(), $next_full ) . ')' ) : '<em>None</em>'; ?></td>
						<td><span class="status-badge <?php echo $next_full ? 'completed' : 'failed'; ?>"><?php echo $next_full ? '✓ Scheduled' : 'Inactive'; ?></span></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Automated Stock-Only Sync', 'wc-erp-sync' ); ?></strong></td>
						<td><code>wc_erp_sync_auto_stock_cron</code></td>
						<td><?php echo esc_html( $sched_stock ? $sched_stock : __( 'Not Scheduled', 'wc-erp-sync' ) ); ?></td>
						<td><?php echo $next_stock ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_stock ) . ' (' . human_time_diff( time(), $next_stock ) . ')' ) : '<em>None</em>'; ?></td>
						<td><span class="status-badge <?php echo $next_stock ? 'completed' : 'failed'; ?>"><?php echo $next_stock ? '✓ Scheduled' : 'Inactive'; ?></span></td>
					</tr>
				</tbody>
			</table>

			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
				<h4 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1e293b;">
					<span class="dashicons dashicons-info" style="color: #2563eb;"></span> <?php esc_html_e( 'Important Note for Staging / Inactive Websites:', 'wc-erp-sync' ); ?>
				</h4>
				<p style="margin: 0 0 10px 0; font-size: 13px; color: #475569; line-height: 1.5;">
					<?php esc_html_e( 'WordPress built-in cron relies on frontend visitor traffic. If no visitors visit this staging website while you are logged out, WordPress background tasks stay asleep. To make background automation trigger 100% reliably 24/7 without logging in, add this command to your Server Crontab or cPanel Cron Jobs (Every 15-30 Minutes):', 'wc-erp-sync' ); ?>
				</p>
				<code style="display: block; background: #0f172a; color: #38bdf8; padding: 10px 14px; border-radius: 6px; font-size: 13px; user-select: all;">
					wget -q -O - <?php echo esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1
				</code>
			</div>

			<!-- Quick Database & Queue Maintenance Tools -->
			<div style="margin-top: 20px; padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
				<h4 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1e293b;">
					<span class="dashicons dashicons-admin-tools" style="color: #6366f1;"></span> <?php esc_html_e( 'System Maintenance & Cleanup Tools', 'wc-erp-sync' ); ?>
				</h4>
				<p style="margin: 0 0 12px 0; font-size: 13px; color: #64748b;">
					<?php esc_html_e( 'Use these maintenance tools to wipe old error logs and clear stuck pending queue backlogs with a single click.', 'wc-erp-sync' ); ?>
				</p>
				<div style="display: flex; gap: 10px; flex-wrap: wrap;">
					<button id="btn-clear-logs" class="btn btn-outline-danger" style="display: inline-flex; align-items: center; gap: 6px;">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Clear All System Error Logs', 'wc-erp-sync' ); ?>
					</button>
					<button id="btn-clear-queue" class="btn btn-outline-danger" style="display: inline-flex; align-items: center; gap: 6px;">
						<span class="dashicons dashicons-dismiss"></span>
						<?php esc_html_e( 'Reset & Clear Pending Sync Queue', 'wc-erp-sync' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=sync' ) ); ?>" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Run Manual Sync Batch', 'wc-erp-sync' ); ?>
					</a>
				</div>
			</div>
		</div>
	</div>
</div>
