<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="logs-container">
	<div class="panel-card full-width">
		<div class="panel-header">
			<h3><?php esc_html_e( 'System Activity & Audit Logs', 'wc-erp-sync' ); ?></h3>

			<div class="log-controls">
				<!-- Level Filter -->
				<select id="log-level-filter">
					<option value="all"><?php esc_html_e( 'All Log Levels', 'wc-erp-sync' ); ?></option>
					<option value="info"><?php esc_html_e( 'Info', 'wc-erp-sync' ); ?></option>
					<option value="warning"><?php esc_html_e( 'Warning', 'wc-erp-sync' ); ?></option>
					<option value="error"><?php esc_html_e( 'Error', 'wc-erp-sync' ); ?></option>
					<option value="debug"><?php esc_html_e( 'Debug', 'wc-erp-sync' ); ?></option>
				</select>

				<!-- Search Input -->
				<input type="text" id="log-search-input" placeholder="<?php esc_html_e( 'Search message or module...', 'wc-erp-sync' ); ?>">

				<!-- Action Buttons -->
				<button id="btn-filter-logs" class="btn btn-primary"><?php esc_html_e( 'Filter Logs', 'wc-erp-sync' ); ?></button>
				<button id="btn-clear-logs" class="btn btn-outline-danger">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Wipe Log Database', 'wc-erp-sync' ); ?>
				</button>
			</div>
		</div>

		<div class="panel-body">
			<!-- AJAX Target Logs Table -->
			<div class="logs-table-wrapper">
				<table class="dash-table" id="logs-data-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Level', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Module Context', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Message Description', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wc-erp-sync' ); ?></th>
						</tr>
					</thead>
					<tbody id="logs-tbody">
						<tr>
							<td colspan="5" class="center-text">
								<span class="spinner is-active inline-spinner"></span>
								<?php esc_html_e( 'Loading log records...', 'wc-erp-sync' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- AJAX Pagination -->
			<div id="logs-pagination" class="pagination" style="display:none;"></div>
		</div>
	</div>
</div>

<!-- Modal for JSON details view -->
<div id="log-context-modal" class="modal-wrapper" style="display:none;">
	<div class="modal-card">
		<div class="modal-header">
			<h4><?php esc_html_e( 'Structured Log Context Details', 'wc-erp-sync' ); ?></h4>
			<span class="modal-close" onclick="document.getElementById('log-context-modal').style.display='none'">&times;</span>
		</div>
		<div class="modal-body">
			<pre id="log-context-json-viewer"></pre>
		</div>
	</div>
</div>
