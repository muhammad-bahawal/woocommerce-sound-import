<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\API\ClientFactory;

// Attempt to load API live stats and categories
$client              = ClientFactory::get_client();
$api_stats           = method_exists( $client, 'fetch_stats' ) ? $client->fetch_stats() : [ 'total_products' => 0, 'total_categories' => 0, 'total_brands' => 0, 'last_sync' => '' ];
$api_categories      = method_exists( $client, 'fetch_categories' ) ? $client->fetch_categories() : [];

if ( ! function_exists( 'wc_erp_sync_flatten_categories' ) ) {
	function wc_erp_sync_flatten_categories( $categories, $depth = 0, $prefix = '' ) {
		$flat = [];
		if ( ! is_array( $categories ) ) {
			return $flat;
		}
		foreach ( $categories as $cat ) {
			$cat_id   = isset( $cat['id'] ) ? $cat['id'] : ( isset( $cat['slug'] ) ? $cat['slug'] : '' );
			$cat_name = isset( $cat['name'] ) ? $cat['name'] : ( is_string( $cat ) ? $cat : '' );
			$cat_slug = isset( $cat['slug'] ) ? $cat['slug'] : '';
			if ( empty( $cat_name ) ) {
				continue;
			}
			$has_children = ! empty( $cat['children'] ) && is_array( $cat['children'] );
			$path         = $prefix ? $prefix . ' > ' . $cat_name : $cat_name;
			$indent       = str_repeat( '&nbsp;&nbsp;&nbsp;&nbsp;', $depth ) . ( $depth > 0 ? '— ' : '' );

			$flat[] = [
				'id'           => $cat_id,
				'name'         => $cat_name,
				'slug'         => $cat_slug,
				'path'         => $path,
				'display_name' => $indent . $cat_name,
				'depth'        => $depth,
				'has_children' => $has_children,
				'children'     => $has_children ? $cat['children'] : [],
				'parent_id'    => isset( $cat['parent_id'] ) ? $cat['parent_id'] : null,
			];

			if ( $has_children ) {
				$sub_flat = wc_erp_sync_flatten_categories( $cat['children'], $depth + 1, $path );
				$flat     = array_merge( $flat, $sub_flat );
			}
		}
		return $flat;
	}
}

$flat_api_categories = wc_erp_sync_flatten_categories( $api_categories );
?>

<script>
window.wcErpInitialCategories = <?php echo wp_json_encode( $api_categories ); ?>;
</script>

<div class="sync-container">
	<!-- Top Remote API Stats Banner -->
	<div class="panel-card mb-20" style="margin-bottom: 20px;">
		<div class="panel-header">
			<h3><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Remote Scraper Service Overview', 'wc-erp-sync' ); ?></h3>
			<button type="button" id="btn-refresh-api-stats" class="btn btn-sm btn-outline">
				<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Refresh Stats', 'wc-erp-sync' ); ?>
			</button>
		</div>
		<div class="panel-body">
			<div class="progress-stats-grid">
				<div class="stat-box">
					<span class="stat-num blue" id="api-stat-products"><?php echo esc_html( number_format_i18n( $api_stats['total_products'] ) ); ?></span>
					<span class="stat-lbl"><?php esc_html_e( 'Scraper Total Products', 'wc-erp-sync' ); ?></span>
				</div>
				<div class="stat-box">
					<span class="stat-num green" id="api-stat-categories"><?php echo esc_html( number_format_i18n( $api_stats['total_categories'] ) ); ?></span>
					<span class="stat-lbl"><?php esc_html_e( 'Scraper Total Categories', 'wc-erp-sync' ); ?></span>
				</div>
				<div class="stat-box">
					<span class="stat-num orange" id="api-stat-brands"><?php echo esc_html( number_format_i18n( $api_stats['total_brands'] ) ); ?></span>
					<span class="stat-lbl"><?php esc_html_e( 'Scraper Total Brands', 'wc-erp-sync' ); ?></span>
				</div>
				<div class="stat-box">
					<span class="stat-num purple" id="api-stat-last-sync" style="font-size: 13px;">
						<?php echo ! empty( $api_stats['last_sync'] ) ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $api_stats['last_sync'] ) ) ) : __( 'N/A', 'wc-erp-sync' ); ?>
					</span>
					<span class="stat-lbl"><?php esc_html_e( 'Last Scraper Run', 'wc-erp-sync' ); ?></span>
				</div>
			</div>
		</div>
	</div>

	<!-- Main Manual Sync Trigger Card -->
	<div class="panel-card">
		<div class="panel-header">
			<h3><?php esc_html_e( 'Manual System Synchronization', 'wc-erp-sync' ); ?></h3>
		</div>
		
		<div class="panel-body">
			<p class="sync-intro-text">
				<?php esc_html_e( 'Triggering a manual sync will fetch Categories, Vendors, Brands, and Products from the API client adapter. Select your product import limit below for testing or full synchronization.', 'wc-erp-sync' ); ?>
			</p>

			<div class="sync-limit-selector-box" style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
				<label for="select-sync-limit" style="font-weight: 600; display: block; margin-bottom: 6px;">
					<span class="dashicons dashicons-filter"></span> <?php esc_html_e( 'Select Product Batch Limit (Initial Testing):', 'wc-erp-sync' ); ?>
				</label>
				<select id="select-sync-limit" class="input-select" style="max-width: 340px; font-size: 14px; padding: 6px 12px; border-radius: 4px;">
					<option value="50" selected><?php esc_html_e( '50 Products (Recommended Initial Test Sync)', 'wc-erp-sync' ); ?></option>
					<option value="100"><?php esc_html_e( '100 Products', 'wc-erp-sync' ); ?></option>
					<option value="250"><?php esc_html_e( '250 Products', 'wc-erp-sync' ); ?></option>
					<option value="500"><?php esc_html_e( '500 Products', 'wc-erp-sync' ); ?></option>
					<option value="0"><?php esc_html_e( 'All Products (Full Sync)', 'wc-erp-sync' ); ?></option>
				</select>
				<span class="description" style="display: block; margin-top: 6px; color: #64748b; font-size: 12px;">
					<?php
					$next_pg = (int) get_option( 'wc_erp_sync_next_page', 1 );
					printf( esc_html__( 'Next sync will fetch new products starting from Page %s.', 'wc-erp-sync' ), '<strong id="sync-page-pointer-display">' . $next_pg . '</strong>' );
					?>
					<a href="#" id="btn-reset-sync-page" style="color: #2563eb; text-decoration: underline; margin-left: 8px; font-weight: 500;"><?php esc_html_e( 'Reset to Page 1', 'wc-erp-sync' ); ?></a>
				</span>
			</div>

			<div class="sync-entities-checklist">
				<h4><?php esc_html_e( 'Synchronized Modules Included:', 'wc-erp-sync' ); ?></h4>
				<ul class="checklist-grid">
					<li><span class="dashicons dashicons-yes green"></span> Category Mapping</li>
					<li><span class="dashicons dashicons-yes green"></span> Brand Mapping</li>
					<li><span class="dashicons dashicons-yes green"></span> Vendor/Supplier Info</li>
					<li><span class="dashicons dashicons-yes green"></span> Product Import (Simple & Variable)</li>
					<li><span class="dashicons dashicons-yes green"></span> Pricing Data</li>
					<li><span class="dashicons dashicons-yes green"></span> Inventory / Stock Levels</li>
					<li><span class="dashicons dashicons-yes green"></span> Product Galleries / Images</li>
				</ul>
			</div>

			<!-- Trigger Card Button -->
			<div class="sync-action-box">
				<button id="btn-start-manual-sync" class="btn btn-primary btn-lg">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Run Synchronization Now', 'wc-erp-sync' ); ?>
				</button>
			</div>

			<!-- Progress Box -->
			<div id="sync-progress-box" class="sync-progress-box" style="display:none;">
				<div class="progress-header">
					<h4 id="sync-progress-status"><?php esc_html_e( 'Sync Job Initializing...', 'wc-erp-sync' ); ?></h4>
					<span id="sync-timer-counter" class="timer-badge">0s</span>
				</div>

				<div class="progress-bar-wrapper">
					<div id="sync-progress-bar-inner" class="progress-bar-inner" style="width: 0%;"></div>
				</div>

				<div class="progress-stats-grid">
					<div class="stat-box">
						<span class="stat-num" id="stat-processed">0</span>
						<span class="stat-lbl"><?php esc_html_e( 'Processed', 'wc-erp-sync' ); ?></span>
					</div>
					<div class="stat-box">
						<span class="stat-num green" id="stat-success">0</span>
						<span class="stat-lbl"><?php esc_html_e( 'Success', 'wc-erp-sync' ); ?></span>
					</div>
					<div class="stat-box">
						<span class="stat-num red" id="stat-failed">0</span>
						<span class="stat-lbl"><?php esc_html_e( 'Failed', 'wc-erp-sync' ); ?></span>
					</div>
				</div>

				<div class="sync-running-alert">
					<span class="spinner is-active"></span>
					<p><?php esc_html_e( 'Please keep this window open while the synchronization is in progress.', 'wc-erp-sync' ); ?></p>
				</div>
			</div>

			<!-- Success Summary -->
			<div id="sync-complete-summary" class="sync-complete-summary" style="display:none;">
				<div class="alert-box success">
					<span class="dashicons dashicons-yes-alt alert-icon"></span>
					<div class="alert-content">
						<h4><?php esc_html_e( 'Synchronization Completed Successfully!', 'wc-erp-sync' ); ?></h4>
						<p id="sync-summary-text"></p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Category-Based Product Sync Card -->
	<div class="panel-card mb-20" style="margin-top: 20px;">
		<div class="panel-header">
			<h3><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Sync Products by Category', 'wc-erp-sync' ); ?></h3>
		</div>
		
		<div class="panel-body">
			<p class="sync-intro-text">
				<?php esc_html_e( 'Select a specific Parent or Child category to fetch and import all its products into WooCommerce. Full category taxonomy hierarchy will be automatically assigned.', 'wc-erp-sync' ); ?>
			</p>

			<!-- Mode Radio Selector -->
			<div class="cat-mode-selector-box" style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 16px; background: #f1f5f9; padding: 12px 16px; border-radius: 6px; border: 1px solid #cbd5e1;">
				<label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; color: #1e293b;">
					<input type="radio" name="cat_sync_mode" value="parent_child" checked>
					<?php esc_html_e( 'Option 1: Sync Child Category (Parent + Child Category)', 'wc-erp-sync' ); ?>
				</label>
				<label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; color: #1e293b;">
					<input type="radio" name="cat_sync_mode" value="single">
					<?php esc_html_e( 'Option 2: Sync Single Category (No Child Category)', 'wc-erp-sync' ); ?>
				</label>
			</div>

			<div style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
				<!-- Option 1 Container: Parent + Child -->
				<div id="cat-mode-parent-child" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; margin-bottom: 16px;">
					<div style="flex: 1 1 240px;">
						<label for="select-cat-parent" style="font-weight: 600; display: block; margin-bottom: 6px;">
							<?php esc_html_e( '1. Select Parent Category:', 'wc-erp-sync' ); ?>
						</label>
						<select id="select-cat-parent" class="input-select" style="width: 100%; font-size: 14px; padding: 8px; border-radius: 4px; border: 1px solid #cbd5e1;">
							<option value=""><?php esc_html_e( '-- Select Parent Category --', 'wc-erp-sync' ); ?></option>
							<?php
							if ( ! empty( $flat_api_categories ) ) {
								foreach ( $flat_api_categories as $cat_item ) {
									if ( $cat_item['has_children'] ) {
										echo '<option value="' . esc_attr( $cat_item['id'] ) . '" data-slug="' . esc_attr( $cat_item['slug'] ) . '">' . esc_html( $cat_item['path'] ) . '</option>';
									}
								}
							}
							?>
						</select>
					</div>

					<div style="flex: 1 1 240px;">
						<label for="select-cat-child" style="font-weight: 600; display: block; margin-bottom: 6px;">
							<?php esc_html_e( '2. Select Child Category:', 'wc-erp-sync' ); ?>
						</label>
						<select id="select-cat-child" class="input-select" style="width: 100%; font-size: 14px; padding: 8px; border-radius: 4px; border: 1px solid #cbd5e1;" disabled>
							<option value=""><?php esc_html_e( '-- Select Parent Category First --', 'wc-erp-sync' ); ?></option>
						</select>
					</div>
				</div>

				<!-- Option 2 Container: Single Category -->
				<div id="cat-mode-single" style="display: none; margin-bottom: 16px;">
					<div style="max-width: 480px;">
						<label for="select-cat-single" style="font-weight: 600; display: block; margin-bottom: 6px;">
							<?php esc_html_e( 'Select Category:', 'wc-erp-sync' ); ?>
						</label>
						<select id="select-cat-single" class="input-select" style="width: 100%; font-size: 14px; padding: 8px; border-radius: 4px; border: 1px solid #cbd5e1;">
							<option value=""><?php esc_html_e( '-- Select Category --', 'wc-erp-sync' ); ?></option>
							<?php
							if ( ! empty( $flat_api_categories ) ) {
								foreach ( $flat_api_categories as $cat_item ) {
									echo '<option value="' . esc_attr( $cat_item['id'] ) . '" data-slug="' . esc_attr( $cat_item['slug'] ) . '">' . $cat_item['display_name'] . '</option>';
								}
							}
							?>
						</select>
					</div>
				</div>

				<!-- Action Submit Button -->
				<div>
					<button type="button" id="btn-start-category-sync" class="btn btn-primary" style="padding: 10px 24px;" disabled>
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Sync Category Products', 'wc-erp-sync' ); ?>
					</button>
				</div>
			</div>

			<!-- Category Sync Progress Box -->
			<div id="cat-sync-progress-box" class="sync-progress-box" style="display:none;">
				<div class="progress-header">
					<h4 id="cat-sync-progress-status"><?php esc_html_e( 'Category Sync Initializing...', 'wc-erp-sync' ); ?></h4>
					<span id="cat-sync-timer-counter" class="timer-badge">0s</span>
				</div>

				<div class="progress-bar-wrapper">
					<div id="cat-sync-progress-bar-inner" class="progress-bar-inner" style="width: 0%;"></div>
				</div>

				<div class="progress-stats-grid">
					<div class="stat-box">
						<span class="stat-num" id="cat-stat-processed">0</span>
						<span class="stat-lbl"><?php esc_html_e( 'Processed', 'wc-erp-sync' ); ?></span>
					</div>
					<div class="stat-box">
						<span class="stat-num green" id="cat-stat-success">0</span>
						<span class="stat-lbl"><?php esc_html_e( 'Success', 'wc-erp-sync' ); ?></span>
					</div>
					<div class="stat-box">
						<span class="stat-num red" id="cat-stat-failed">0</span>
						<span class="stat-lbl"><?php esc_html_e( 'Failed', 'wc-erp-sync' ); ?></span>
					</div>
				</div>

				<div class="sync-running-alert">
					<span class="spinner is-active"></span>
					<p><?php esc_html_e( 'Please keep this window open while category product synchronization is in progress.', 'wc-erp-sync' ); ?></p>
				</div>
			</div>

			<!-- Category Sync Complete Summary -->
			<div id="cat-sync-complete-summary" class="sync-complete-summary" style="display:none;">
				<div class="alert-box success">
					<span class="dashicons dashicons-yes-alt alert-icon"></span>
					<div class="alert-content">
						<h4><?php esc_html_e( 'Category Synchronization Completed!', 'wc-erp-sync' ); ?></h4>
						<p id="cat-sync-summary-text"></p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Extra Testing Tools Grid -->
	<div class="dash-main-grid mt-20" style="margin-top: 20px;">
		<!-- 1. Single SKU Quick Sync Tool -->
		<div class="panel-card">
			<div class="panel-header">
				<h3><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Sync Single Product by SKU', 'wc-erp-sync' ); ?></h3>
			</div>
			<div class="panel-body">
				<p class="box-desc">
					<?php esc_html_e( 'Test fetching and importing a single specific product from the FastAPI scraper by SKU.', 'wc-erp-sync' ); ?>
				</p>
				<div class="form-group mb-12" style="margin-bottom: 12px;">
					<input type="text" id="input-single-sku" class="input-text" placeholder="<?php esc_attr_e( 'Enter Product SKU (e.g. 230-212)', 'wc-erp-sync' ); ?>" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #cbd5e1;">
				</div>
				<button type="button" id="btn-sync-single-sku" class="btn btn-outline btn-block">
					<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Import SKU Product', 'wc-erp-sync' ); ?>
				</button>
				<div id="sku-sync-result" class="test-result-alert" style="display:none; margin-top: 10px;"></div>
			</div>
		</div>

		<!-- 2. Remote Scraper Trigger Tool -->
		<div class="panel-card">
			<div class="panel-header">
				<h3><span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'Trigger Remote FastAPI Scraper', 'wc-erp-sync' ); ?></h3>
			</div>
			<div class="panel-body">
				<p class="box-desc">
					<?php esc_html_e( 'Initiate live web scraping job directly on the remote FastAPI backend server.', 'wc-erp-sync' ); ?>
				</p>
				<div class="form-group mb-12" style="margin-bottom: 12px;">
					<label style="font-size: 13px; cursor: pointer;">
						<input type="checkbox" id="chk-remote-incremental" value="1">
						<?php esc_html_e( 'Run Incremental Scrape Only', 'wc-erp-sync' ); ?>
					</label>
				</div>
				<button type="button" id="btn-trigger-remote-sync" class="btn btn-outline btn-block">
					<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Trigger Remote Scrape Job', 'wc-erp-sync' ); ?>
				</button>
				<div id="remote-trigger-result" class="test-result-alert" style="display:none; margin-top: 10px;"></div>
			</div>
		</div>

		<!-- 3. Incremental Changed Products Sync -->
		<div class="panel-card">
			<div class="panel-header">
				<h3><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Sync Changed Products', 'wc-erp-sync' ); ?></h3>
			</div>
			<div class="panel-body">
				<p class="box-desc">
					<?php esc_html_e( 'Queue product updates modified/scraped since a specific target date.', 'wc-erp-sync' ); ?>
				</p>
				<div class="form-group mb-12" style="margin-bottom: 12px;">
					<input type="date" id="input-changed-since" class="input-text" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( '-1 day' ) ) ); ?>" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #cbd5e1;">
				</div>
				<button type="button" id="btn-sync-incremental-date" class="btn btn-outline btn-block">
					<span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Queue Changed Products', 'wc-erp-sync' ); ?>
				</button>
				<div id="incremental-sync-result" class="test-result-alert" style="display:none; margin-top: 10px;"></div>
			</div>
		</div>
	</div>
</div>
