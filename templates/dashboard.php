<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Admin\DashController;

$stats   = DashController::get_summary_stats();
$history = DashController::get_recent_history( 5 );
$errors  = DashController::get_recent_errors( 5 );
?>

<div class="dash-container">
	<!-- Time Window Filter Header -->
	<div class="dash-filter-bar" style="display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 14px 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 20px; box-shadow: var(--shadow-sm); flex-wrap: wrap; gap: 12px;">
		<div class="filter-title-group" style="display: flex; align-items: center; gap: 10px;">
			<span class="dashicons dashicons-chart-pie" style="font-size: 20px; width: 20px; height: 20px; color: var(--primary-color);"></span>
			<div>
				<h4 style="margin: 0; font-size: 15px; font-weight: 600; color: var(--text-primary);"><?php esc_html_e( 'Sync Activity & Inventory Performance', 'wc-erp-sync' ); ?></h4>
				<span style="font-size: 12px; color: var(--text-secondary);"><?php esc_html_e( 'View real-time synchronization metrics across customized time intervals.', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="filter-controls" style="display: flex; align-items: center; gap: 10px;">
			<label for="select-dash-time-range" style="font-size: 13px; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
				<span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Time Range:', 'wc-erp-sync' ); ?>
			</label>
			<select id="select-dash-time-range" class="input-select" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 13px; font-weight: 500; background: #f8fafc; color: var(--text-primary); cursor: pointer;">
				<option value="1"><?php esc_html_e( 'Last 1 Hour', 'wc-erp-sync' ); ?></option>
				<option value="6"><?php esc_html_e( 'Last 6 Hours', 'wc-erp-sync' ); ?></option>
				<option value="12"><?php esc_html_e( 'Last 12 Hours', 'wc-erp-sync' ); ?></option>
				<option value="24" selected><?php esc_html_e( 'Last 24 Hours (Default)', 'wc-erp-sync' ); ?></option>
				<option value="48"><?php esc_html_e( 'Last 48 Hours', 'wc-erp-sync' ); ?></option>
				<option value="168"><?php esc_html_e( 'Last 7 Days', 'wc-erp-sync' ); ?></option>
			</select>
			<span id="dash-stats-spinner" class="spinner" style="float: none; margin: 0; display: none;"></span>
		</div>
	</div>

	<!-- Top Summary Cards -->
	<section class="kpi-grid">
		<!-- 1. Mapped Products (Total) -->
		<div class="kpi-card">
			<div class="kpi-icon-wrapper blue">
				<span class="dashicons dashicons-archive"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-mapped-products"><?php echo esc_html( number_format_i18n( $stats['mapped_products'] ) ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Total Mapped Products', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<!-- 2. Products Updated in Selected Window -->
		<div class="kpi-card" title="<?php esc_attr_e( 'Catalog products modified and synchronized from ERP within selected timeframe', 'wc-erp-sync' ); ?>">
			<div class="kpi-icon-wrapper indigo" style="background: #eef2ff; color: #4f46e5;">
				<span class="dashicons dashicons-saved"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-products-updated"><?php echo esc_html( number_format_i18n( $stats['products_updated'] ) ); ?></span>
				<span class="kpi-label">
					<?php esc_html_e( 'Products Updated', 'wc-erp-sync' ); ?>
					<small class="time-range-badge" id="lbl-updated-range" style="display: block; font-size: 11px; color: #6366f1; font-weight: 500;">(Last 24h)</small>
				</span>
			</div>
		</div>

		<!-- 3. New Products Added in Selected Window -->
		<div class="kpi-card" title="<?php esc_attr_e( 'New products created and imported into store within selected timeframe', 'wc-erp-sync' ); ?>">
			<div class="kpi-icon-wrapper teal" style="background: #f0fdfa; color: #0d9488;">
				<span class="dashicons dashicons-plus-alt"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-products-added"><?php echo esc_html( number_format_i18n( $stats['products_added'] ) ); ?></span>
				<span class="kpi-label">
					<?php esc_html_e( 'New Products Added', 'wc-erp-sync' ); ?>
					<small class="time-range-badge" id="lbl-added-range" style="display: block; font-size: 11px; color: #0d9488; font-weight: 500;">(Last 24h)</small>
				</span>
			</div>
		</div>

		<!-- 4. Stock Updated Only in Selected Window -->
		<div class="kpi-card" title="<?php esc_attr_e( 'Products with stock levels or status updated within selected timeframe', 'wc-erp-sync' ); ?>">
			<div class="kpi-icon-wrapper cyan" style="background: #ecfeff; color: #0891b2;">
				<span class="dashicons dashicons-database-sync"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-stock-updated"><?php echo esc_html( number_format_i18n( $stats['stock_updated'] ) ); ?></span>
				<span class="kpi-label">
					<?php esc_html_e( 'Stock Only Updated', 'wc-erp-sync' ); ?>
					<small class="time-range-badge" id="lbl-stock-range" style="display: block; font-size: 11px; color: #0891b2; font-weight: 500;">(Last 24h)</small>
				</span>
			</div>
		</div>

		<!-- 5. Sync Success Rate -->
		<div class="kpi-card">
			<div class="kpi-icon-wrapper green">
				<span class="dashicons dashicons-cloud-saved"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-success-rate"><?php echo esc_html( $stats['success_rate'] ); ?>%</span>
				<span class="kpi-label"><?php esc_html_e( 'Sync Success Rate', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<!-- 6. Pending Queue Items -->
		<div class="kpi-card">
			<div class="kpi-icon-wrapper orange">
				<span class="dashicons dashicons-list-view"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-pending-queue"><?php echo esc_html( number_format_i18n( $stats['pending_queue'] ) ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Pending Queue Items', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<!-- 7. Errors in Selected Window -->
		<div class="kpi-card">
			<div class="kpi-icon-wrapper red">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value" id="kpi-errors"><?php echo esc_html( number_format_i18n( $stats['errors_window'] ) ); ?></span>
				<span class="kpi-label">
					<?php esc_html_e( 'System Errors', 'wc-erp-sync' ); ?>
					<small class="time-range-badge" id="lbl-error-range" style="display: block; font-size: 11px; color: #ef4444; font-weight: 500;">(Last 24h)</small>
				</span>
			</div>
		</div>
	</section>

	<div class="dash-main-grid">
		<!-- Left: Recent History -->
		<div class="panel-card double-width">
			<div class="panel-header">
				<h3><?php esc_html_e( 'Recent Synchronizations', 'wc-erp-sync' ); ?></h3>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=sync' ) ); ?>" class="btn btn-sm btn-outline"><?php esc_html_e( 'Manual Run', 'wc-erp-sync' ); ?></a>
			</div>
			<div class="panel-body">
				<?php if ( empty( $history ) ) : ?>
					<div class="empty-state">
						<span class="dashicons dashicons-info"></span>
						<p><?php esc_html_e( 'No synchronization history found. Run a manual sync to start.', 'wc-erp-sync' ); ?></p>
					</div>
				<?php else : ?>
					<table class="dash-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Job Type', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Processed', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Duration', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Started At', 'wc-erp-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $job ) : ?>
								<tr>
									<td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $job['job_type'] ) ) ); ?></strong></td>
									<td>
										<span class="status-badge <?php echo esc_attr( $job['status'] ); ?>">
											<?php echo esc_html( ucfirst( $job['status'] ) ); ?>
										</span>
									</td>
									<td>
										<span class="success-count"><?php echo esc_html( $job['records_success'] ); ?> ✓</span>
										<span class="failed-count"><?php echo esc_html( $job['records_failed'] ); ?> ✗</span>
									</td>
									<td><?php echo esc_html( $job['duration'] ); ?>s</td>
									<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $job['started_at'] ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- Right: Connection Check -->
		<div class="panel-card">
			<div class="panel-header">
				<h3><?php esc_html_e( 'Connection Health', 'wc-erp-sync' ); ?></h3>
			</div>
			<div class="panel-body">
				<div class="connection-health-box">
					<?php
					$saved_settings  = get_option( 'wc_erp_sync_settings', [] );
					$adapter_type    = isset( $saved_settings['client_adapter'] ) ? $saved_settings['client_adapter'] : 'soundimports';
					$adapter_name    = 'soundimports' === $adapter_type ? __( 'SoundImports Scraper API (Live)', 'wc-erp-sync' ) : __( 'Mock Sandbox (Dummy)', 'wc-erp-sync' );
					?>
					<div class="status-indicator">
						<span class="pulse green"></span>
						<strong><?php printf( esc_html__( 'Active Client Adapter: %s', 'wc-erp-sync' ), esc_html( $adapter_name ) ); ?></strong>
					</div>
					<p class="box-desc">
						<?php esc_html_e( 'The sync engine connects directly to the FastAPI scrape backend service to verify schemas and catalog syncs.', 'wc-erp-sync' ); ?>
					</p>
					
					<div class="btn-group">
						<button id="btn-test-connection" class="btn btn-primary btn-block">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Test ERP Connection', 'wc-erp-sync' ); ?>
						</button>
					</div>

					<div id="connection-test-result" class="test-result-alert" style="display:none;"></div>
				</div>
			</div>
		</div>
	</div>

	<!-- Bottom Section: Latest Errors -->
	<section class="panel-card full-width">
		<div class="panel-header">
			<h3><?php esc_html_e( 'Recent System Log Warnings & Errors', 'wc-erp-sync' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=logs' ) ); ?>" class="btn btn-sm btn-outline"><?php esc_html_e( 'View All Logs', 'wc-erp-sync' ); ?></a>
		</div>
		<div class="panel-body">
			<?php if ( empty( $errors ) ) : ?>
				<div class="empty-state">
					<span class="dashicons dashicons-yes-alt green"></span>
					<p><?php esc_html_e( 'Great! No errors recorded in the log files.', 'wc-erp-sync' ); ?></p>
				</div>
			<?php else : ?>
				<table class="dash-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Module', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Level', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Message', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Time', 'wc-erp-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $errors as $log ) : ?>
							<tr class="log-row-<?php echo esc_attr( $log['level'] ); ?>">
								<td><code><?php echo esc_html( $log['module'] ); ?></code></td>
								<td><span class="badge badge-<?php echo esc_attr( $log['level'] ); ?>"><?php echo esc_html( strtoupper( $log['level'] ) ); ?></span></td>
								<td class="log-message-td"><?php echo esc_html( $log['message'] ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log['created_at'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</section>
</div>
