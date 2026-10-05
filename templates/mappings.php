<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\MappingTable;

$active_type = isset( $_GET['entity_type'] ) ? sanitize_text_field( $_GET['entity_type'] ) : 'all';
$search      = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

$paged       = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit       = 20;
$offset      = ( $paged - 1 ) * $limit;

$mappings    = MappingTable::get_mappings( $active_type, $limit, $offset, $search );
$total_items = MappingTable::count_mappings( $active_type, $search );
$total_pages = ceil( $total_items / $limit );

$entity_types = [
	'all'      => __( 'All Entities', 'wc-erp-sync' ),
	'product'  => __( 'Products', 'wc-erp-sync' ),
	'category' => __( 'Categories', 'wc-erp-sync' ),
	'vendor'   => __( 'Vendors / Suppliers', 'wc-erp-sync' ),
	'brand'    => __( 'Brands', 'wc-erp-sync' ),
	'image'    => __( 'Images', 'wc-erp-sync' ),
	'variation'=> __( 'Variations', 'wc-erp-sync' ),
	'customer' => __( 'Customers', 'wc-erp-sync' ),
	'order'    => __( 'Orders', 'wc-erp-sync' ),
];
?>

<div class="mappings-container">
	<div class="mappings-layout">
		<!-- Main Mapping List -->
		<div class="panel-card double-width">
			<div class="panel-header">
				<h3><?php esc_html_e( 'Active Mappings Index', 'wc-erp-sync' ); ?></h3>
				
				<!-- Filter Forms -->
				<form method="get" class="filter-search-form">
					<input type="hidden" name="page" value="wc-erp-sync">
					<input type="hidden" name="tab" value="mappings">
					
					<select name="entity_type" onchange="this.form.submit()">
						<?php foreach ( $entity_types as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $active_type, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<div class="search-input-wrapper">
						<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_html_e( 'Search ID or SKU...', 'wc-erp-sync' ); ?>">
						<button type="submit" class="btn btn-outline"><?php esc_html_e( 'Search', 'wc-erp-sync' ); ?></button>
					</div>
				</form>
			</div>

			<div class="panel-body">
				<?php if ( empty( $mappings ) ) : ?>
					<div class="empty-state">
						<span class="dashicons dashicons-admin-links"></span>
						<p><?php esc_html_e( 'No mapped records found matching current query filters.', 'wc-erp-sync' ); ?></p>
					</div>
				<?php else : ?>
					<table class="dash-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Entity Type', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'External ERP ID', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Local WP / WC ID', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Hash Checked', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Last Sync Status', 'wc-erp-sync' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wc-erp-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $mappings as $mapping ) : ?>
								<tr>
									<td><span class="entity-type-tag <?php echo esc_attr( $mapping['entity_type'] ); ?>"><?php echo esc_html( strtoupper( $mapping['entity_type'] ) ); ?></span></td>
									<td><code><?php echo esc_html( $mapping['external_id'] ); ?></code></td>
									<td><code><?php echo esc_html( $mapping['local_id'] ); ?></code></td>
									<td><span class="hash-tag"><?php echo esc_html( substr( $mapping['hash'], 0, 8 ) ); ?>...</span></td>
									<td>
										<span class="status-badge <?php echo esc_attr( $mapping['last_sync_status'] ); ?>">
											<?php echo esc_html( ucfirst( $mapping['last_sync_status'] ) ); ?>
										</span>
									</td>
									<td>
										<button class="btn-delete-mapping btn btn-xs btn-outline-danger" data-type="<?php echo esc_attr( $mapping['entity_type'] ); ?>" data-ext-id="<?php echo esc_attr( $mapping['external_id'] ); ?>">
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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-erp-sync&tab=mappings&entity_type=' . $active_type . '&s=' . $search . '&paged=' . $i ) ); ?>" class="page-numbers <?php echo $paged === $i ? 'current' : ''; ?>">
									<?php echo $i; ?>
								</a>
							<?php endfor; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- Sidebar: Add Manual Mapping -->
		<div class="panel-card sidebar-panel">
			<div class="panel-header">
				<h3><?php esc_html_e( 'Create Manual Mapping Link', 'wc-erp-sync' ); ?></h3>
			</div>
			<div class="panel-body">
				<form id="add-manual-mapping-form" method="post">
					<div class="form-group">
						<label for="map_entity_type"><?php esc_html_e( 'Entity Type', 'wc-erp-sync' ); ?></label>
						<select id="map_entity_type" name="entity_type" required>
							<option value="product"><?php esc_html_e( 'Product', 'wc-erp-sync' ); ?></option>
							<option value="category"><?php esc_html_e( 'Category', 'wc-erp-sync' ); ?></option>
							<option value="vendor"><?php esc_html_e( 'Vendor', 'wc-erp-sync' ); ?></option>
							<option value="brand"><?php esc_html_e( 'Brand', 'wc-erp-sync' ); ?></option>
							<option value="order"><?php esc_html_e( 'Order', 'wc-erp-sync' ); ?></option>
							<option value="customer"><?php esc_html_e( 'Customer', 'wc-erp-sync' ); ?></option>
						</select>
					</div>

					<div class="form-group">
						<label for="map_external_id"><?php esc_html_e( 'External ERP ID', 'wc-erp-sync' ); ?></label>
						<input type="text" id="map_external_id" name="external_id" required placeholder="e.g. ERP-PROD-998">
					</div>

					<div class="form-group">
						<label for="map_local_id"><?php esc_html_e( 'Local WP / WooCommerce ID', 'wc-erp-sync' ); ?></label>
						<input type="number" id="map_local_id" name="local_id" required placeholder="e.g. 1422">
					</div>

					<button type="submit" class="btn btn-primary btn-block">
						<span class="dashicons dashicons-plus"></span>
						<?php esc_html_e( 'Link Mapping Record', 'wc-erp-sync' ); ?>
					</button>

					<div id="mapping-result-alert" class="alert-box-result" style="display:none;"></div>
				</form>
			</div>
		</div>
	</div>
</div>
