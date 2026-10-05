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
	<!-- Top Summary Cards -->
	<section class="kpi-grid">
		<div class="kpi-card">
			<div class="kpi-icon-wrapper blue">
				<span class="dashicons dashicons-archive"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['mapped_products'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Mapped Products', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="kpi-card">
			<div class="kpi-icon-wrapper green">
				<span class="dashicons dashicons-cloud-saved"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['success_rate'] ); ?>%</span>
				<span class="kpi-label"><?php esc_html_e( 'Sync Success Rate', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="kpi-card">
			<div class="kpi-icon-wrapper orange">
				<span class="dashicons dashicons-list-view"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['pending_queue'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Pending Queue Items', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="kpi-card">
			<div class="kpi-icon-wrapper red">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['errors_24h'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Errors (24h)', 'wc-erp-sync' ); ?></span>
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
