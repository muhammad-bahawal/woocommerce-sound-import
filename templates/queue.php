<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\QueueTable;

$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'pending';
$paged         = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit         = 20;
$offset        = ( $paged - 1 ) * $limit;

$queue_items   = QueueTable::get_items_by_status( $status_filter, $limit, $offset );
$total_items   = QueueTable::count_by_status( $status_filter );
$total_pages   = ceil( $total_items / $limit );

$stats         = QueueTable::get_queue_stats();
?>

<div class="queue-container">
	<!-- Summary Stats Bar -->
	<section class="kpi-grid">
		<div class="kpi-card <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>" onclick="window.location='<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=queue&status=pending' ) ); ?>'">
			<div class="kpi-icon-wrapper orange">
				<span class="dashicons dashicons-clock"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['pending'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Pending Tasks', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="kpi-card <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>" onclick="window.location='<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=queue&status=processing' ) ); ?>'">
			<div class="kpi-icon-wrapper blue">
				<span class="dashicons dashicons-update"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['processing'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Processing Tasks', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="kpi-card <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>" onclick="window.location='<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=queue&status=completed' ) ); ?>'">
			<div class="kpi-icon-wrapper green">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['completed'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Completed Tasks', 'wc-erp-sync' ); ?></span>
			</div>
		</div>

		<div class="kpi-card <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>" onclick="window.location='<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=queue&status=failed' ) ); ?>'">
			<div class="kpi-icon-wrapper red">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<div class="kpi-data">
				<span class="kpi-value"><?php echo esc_html( $stats['failed'] ); ?></span>
				<span class="kpi-label"><?php esc_html_e( 'Failed Tasks', 'wc-erp-sync' ); ?></span>
			</div>
		</div>
	</section>

	<div class="panel-card full-width">
		<div class="panel-header">
			<h3><?php printf( esc_html__( 'Synchronization Queue: %s Items', 'wc-erp-sync' ), ucfirst( $status_filter ) ); ?></h3>
			
			<div class="btn-group">
				<button id="btn-retry-failed" class="btn btn-outline-warning">
					<span class="dashicons dashicons-redo"></span>
					<?php esc_html_e( 'Retry Failed Jobs', 'wc-erp-sync' ); ?>
				</button>
				<button id="btn-clear-queue" class="btn btn-outline-danger">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear All Queue', 'wc-erp-sync' ); ?>
				</button>
			</div>
		</div>

		<div class="panel-body">
			<?php if ( empty( $queue_items ) ) : ?>
				<div class="empty-state">
					<span class="dashicons dashicons-clock"></span>
					<p><?php printf( esc_html__( 'No %s items in the queue.', 'wc-erp-sync' ), $status_filter ); ?></p>
				</div>
			<?php else : ?>
				<table class="dash-table">
					<thead>
						<tr>
							<th>ID</th>
							<th><?php esc_html_e( 'Entity Type', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Ext Ref', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Local ID', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'wc-erp-sync' ); ?></th>
							<?php if ( 'failed' === $status_filter ) : ?>
								<th><?php esc_html_e( 'Error Message', 'wc-erp-sync' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Enqueued At', 'wc-erp-sync' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wc-erp-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $queue_items as $item ) : 
							$entity_type_str = ! empty( $item['entity_type'] ) ? strtoupper( $item['entity_type'] ) : 'N/A';
							$action_str      = ! empty( $item['action'] ) ? strtoupper( $item['action'] ) : 'N/A';
							$ext_id_str      = ! empty( $item['external_id'] ) ? $item['external_id'] : '-';
							$local_id_str    = ! empty( $item['local_id'] ) ? $item['local_id'] : '-';
							$attempts_str    = isset( $item['attempts'] ) ? $item['attempts'] : 0;
							$error_msg       = ! empty( $item['error_message'] ) ? $item['error_message'] : '-';
							$created_date    = ! empty( $item['created_at'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['created_at'] ) ) : '-';
							?>
							<tr>
								<td>#<?php echo esc_html( isset( $item['id'] ) ? $item['id'] : '' ); ?></td>
								<td><span class="entity-type-tag <?php echo esc_attr( strtolower( $entity_type_str ) ); ?>"><?php echo esc_html( $entity_type_str ); ?></span></td>
								<td><code><?php echo esc_html( $action_str ); ?></code></td>
								<td><code><?php echo esc_html( $ext_id_str ); ?></code></td>
								<td><code><?php echo esc_html( $local_id_str ); ?></code></td>
								<td><?php echo esc_html( $attempts_str ); ?>/5</td>
								<?php if ( 'failed' === $status_filter ) : ?>
									<td class="red-text"><code><?php echo esc_html( $error_msg ); ?></code></td>
								<?php endif; ?>
								<td><?php echo esc_html( $created_date ); ?></td>
								<td>
									<button class="btn-delete-queue-item btn btn-xs btn-outline-danger" data-id="<?php echo esc_attr( isset( $item['id'] ) ? $item['id'] : 0 ); ?>">
										<span class="dashicons dashicons-trash"></span>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<!-- Pagination -->
				<?php if ( $total_pages > 1 ) : ?>
					<div class="pagination">
						<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=queue&status=' . $status_filter . '&paged=' . $i ) ); ?>" class="page-numbers <?php echo $paged === $i ? 'current' : ''; ?>">
								<?php echo $i; ?>
							</a>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
