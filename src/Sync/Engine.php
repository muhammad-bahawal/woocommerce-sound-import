<?php
namespace WCErpSync\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\HistoryTable;
use WCErpSync\Modules\Registry;
use WCErpSync\API\DummyClient;
use WCErpSync\Logger\Logger;

/**
 * Synchronization Engine orchestrating cron, manual, full and incremental jobs.
 */
class Engine {

	/**
	 * Kickoff manual sync job: creates history record and syncs metadata (categories, brands).
	 */
	public static function start_manual_sync( $job_type = 'manual_full', $max_products = 50 ) {
		$history_id = HistoryTable::start_job( $job_type );
		if ( ! $history_id ) {
			return false;
		}

		if ( 0 === $max_products ) {
			update_option( 'wc_erp_sync_next_page', 1 );
		}

		$start_page = (int) get_option( 'wc_erp_sync_next_page', 1 );
		set_transient( 'wc_erp_sync_start_page_' . $history_id, $start_page, 3600 );

		$limit_label = $max_products > 0 ? (string) $max_products : 'All';
		Logger::info( sprintf( 'Starting batch manual sync initialization. Run ID: %d, Start Page: %d, Max Limit: %s', $history_id, $start_page, $limit_label ), 'sync_engine' );

		try {
			$client = \WCErpSync\API\ClientFactory::get_client();

			// 1. Sync Categories first
			$categories  = $client->fetch_categories();
			$cat_module  = Registry::instance()->get_module( 'category' );
			$cat_success = 0;
			if ( $cat_module && is_array( $categories ) ) {
				if ( method_exists( $cat_module, 'import_all_categories' ) ) {
					$cat_success = $cat_module->import_all_categories( $categories );
				} else {
					foreach ( $categories as $cat ) {
						if ( $cat_module->import_item( $cat ) ) {
							$cat_success++;
						}
					}
				}
			}

			// 2. Sync Vendors
			$vendors    = $client->fetch_vendors();
			$vnd_module = Registry::instance()->get_module( 'vendor' );
			if ( $vnd_module && is_array( $vendors ) ) {
				foreach ( $vendors as $vnd ) {
					$vnd_module->import_item( $vnd );
				}
			}

			// 3. Sync Brands
			$brands     = $client->fetch_brands();
			$brd_module = Registry::instance()->get_module( 'brand' );
			if ( $brd_module && is_array( $brands ) ) {
				foreach ( $brands as $brd ) {
					$brd_module->import_item( $brd );
				}
			}

			return $history_id;
		} catch ( \Exception $e ) {
			Logger::error( 'Exception during manual sync start: ' . $e->getMessage(), 'sync_engine' );
			return $history_id;
		}
	}

	/**
	 * Process a single batch chunk of products for a manual sync run.
	 */
	public static function process_manual_batch( $history_id, $page = 1, $batch_size = 10, $max_products = 50 ) {
		$client      = \WCErpSync\API\ClientFactory::get_client();
		$prod_module = Registry::instance()->get_module( 'product' );

		if ( ! $prod_module || ! $history_id ) {
			return [ 'completed' => true, 'processed' => 0, 'success' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'next_page' => 1 ];
		}

		$start_page     = (int) get_transient( 'wc_erp_sync_start_page_' . $history_id );
		if ( ! $start_page ) {
			$start_page = 1;
		}
		$effective_page = $start_page + ( $page - 1 );

		global $wpdb;
		$table       = HistoryTable::get_table_name();
		$current_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $history_id ), ARRAY_A );

		$processed = $current_job ? intval( $current_job['records_processed'] ) : 0;
		$success   = $current_job ? intval( $current_job['records_success'] ) : 0;
		$failed    = $current_job ? intval( $current_job['records_failed'] ) : 0;

		$stats = get_transient( 'wc_erp_sync_job_counts_' . $history_id );
		if ( ! is_array( $stats ) ) {
			$stats = [ 'created' => 0, 'updated' => 0 ];
		}

		$fetch_limit = $batch_size;
		if ( $max_products > 0 && ( $max_products - $processed ) < $batch_size ) {
			$fetch_limit = $max_products - $processed;
		}

		if ( $fetch_limit <= 0 ) {
			HistoryTable::complete_job( $history_id, 'completed' );
			update_option( 'wc_erp_sync_next_page', $effective_page );
			return [ 'completed' => true, 'processed' => $processed, 'success' => $success, 'failed' => $failed, 'created' => $stats['created'], 'updated' => $stats['updated'], 'next_page' => $effective_page ];
		}

		$response = $client->fetch_products( [ 'page' => $effective_page, 'limit' => $fetch_limit ] );

		if ( empty( $response['items'] ) ) {
			// Only reset page pointer to 1 if reached end of products naturally without API error
			if ( empty( $response['error'] ) ) {
				update_option( 'wc_erp_sync_next_page', 1 );
				$next_pg = 1;
			} else {
				$next_pg = $effective_page;
			}
			HistoryTable::complete_job( $history_id, 'completed' );
			return [
				'completed' => true,
				'processed' => $processed,
				'success'   => $success,
				'failed'    => $failed,
				'created'   => $stats['created'],
				'updated'   => $stats['updated'],
				'next_page' => $next_pg,
				'error'     => ! empty( $response['error'] ) ? $response['error'] : '',
			];
		}

		foreach ( $response['items'] as $item ) {
			$processed++;

			// Determine if product is NEW (created) or EXISTING (updated) before importing
			$sku         = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
			$ext_id      = isset( $item['external_id'] ) ? (string) $item['external_id'] : '';
			$existing_id = ! empty( $sku ) ? wc_get_product_id_by_sku( $sku ) : 0;
			if ( ! $existing_id && ! empty( $ext_id ) ) {
				$existing_id = \WCErpSync\Database\MappingTable::get_local_id( 'product', $ext_id );
			}
			$is_new = empty( $existing_id );

			$local_id = $prod_module->import_item( $item );
			if ( $local_id ) {
				$success++;
				if ( $is_new ) {
					$stats['created']++;
				} else {
					$stats['updated']++;
				}
			} else {
				$failed++;
			}

			HistoryTable::update_job_progress( $history_id, $processed, $success, $failed );
			set_transient( 'wc_erp_sync_job_counts_' . $history_id, $stats, 3600 );

			if ( $max_products > 0 && $processed >= $max_products ) {
				$next_page = ! empty( $response['has_more'] ) ? ( $effective_page + 1 ) : 1;
				update_option( 'wc_erp_sync_next_page', $next_page );
				HistoryTable::complete_job( $history_id, 'completed' );
				return [ 'completed' => true, 'processed' => $processed, 'success' => $success, 'failed' => $failed, 'created' => $stats['created'], 'updated' => $stats['updated'], 'next_page' => $next_page ];
			}
		}

		$has_more  = ! empty( $response['has_more'] ) && ( $max_products <= 0 || $processed < $max_products );
		$next_page = $has_more ? ( $effective_page + 1 ) : 1;
		update_option( 'wc_erp_sync_next_page', $next_page );

		if ( ! $has_more ) {
			HistoryTable::complete_job( $history_id, 'completed' );
		}

		return [
			'completed' => ! $has_more,
			'processed' => $processed,
			'success'   => $success,
			'failed'    => $failed,
			'created'   => $stats['created'],
			'updated'   => $stats['updated'],
			'page'      => $page + 1,
			'next_page' => $next_page,
		];
	}

	/**
	 * Run full synchronization manually or via trigger.
	 *
	 * @param string $job_type Job classification label.
	 * @param int    $max_products Maximum products to process (0 for unlimited, default 50 for testing).
	 * @return int|bool History record ID, or false.
	 */
	public static function run_full_sync( $job_type = 'manual_full', $max_products = 50 ) {
		$history_id = HistoryTable::start_job( $job_type );
		if ( ! $history_id ) {
			return false;
		}

		$limit_label = $max_products > 0 ? (string) $max_products : 'All';
		Logger::info( sprintf( 'Starting full synchronization job. Run ID: %d, Max Limit: %s', $history_id, $limit_label ), 'sync_engine' );

		try {
			// Instantiate the active API Client adapter using Factory
			$client = \WCErpSync\API\ClientFactory::get_client();

			// 1. Sync Categories first
			$categories = $client->fetch_categories();
			$cat_module = Registry::instance()->get_module( 'category' );
			$cat_success = 0;
			if ( $cat_module && is_array( $categories ) ) {
				if ( method_exists( $cat_module, 'import_all_categories' ) ) {
					$cat_success = $cat_module->import_all_categories( $categories );
				} else {
					foreach ( $categories as $cat ) {
						if ( $cat_module->import_item( $cat ) ) {
							$cat_success++;
						}
					}
				}
			}
			Logger::info( sprintf( 'Synced categories: %d / %d successful.', $cat_success, count( $categories ) ), 'sync_engine' );

			// 2. Sync Vendors
			$vendors = $client->fetch_vendors();
			$vnd_module = Registry::instance()->get_module( 'vendor' );
			if ( $vnd_module && is_array( $vendors ) ) {
				foreach ( $vendors as $vnd ) {
					$vnd_module->import_item( $vnd );
				}
			}

			// 3. Sync Brands
			$brands = $client->fetch_brands();
			$brd_module = Registry::instance()->get_module( 'brand' );
			if ( $brd_module && is_array( $brands ) ) {
				foreach ( $brands as $brd ) {
					$brd_module->import_item( $brd );
				}
			}

			// 4. Sync Products in Batches
			$page        = 1;
			$batch_limit = 50;
			$processed   = 0;
			$success     = 0;
			$failed      = 0;
			$has_more    = true;
			$prod_module = Registry::instance()->get_module( 'product' );

			if ( $prod_module ) {
				while ( $has_more ) {
					// Calculate batch limit considering max_products limit
					$fetch_limit = ( $max_products > 0 && ( $max_products - $processed ) < $batch_limit ) ? ( $max_products - $processed ) : $batch_limit;
					if ( $fetch_limit <= 0 ) {
						break;
					}

					$response = $client->fetch_products( [ 'page' => $page, 'limit' => $fetch_limit ] );
					
					if ( empty( $response['items'] ) ) {
						break;
					}

					foreach ( $response['items'] as $item ) {
						$processed++;
						$local_id = $prod_module->import_item( $item );
						if ( $local_id ) {
							$success++;
						} else {
							$failed++;
						}

						// Update progress inside DB every item
						HistoryTable::update_job_progress( $history_id, $processed, $success, $failed );

						if ( $max_products > 0 && $processed >= $max_products ) {
							$has_more = false;
							break;
						}
					}

					if ( ! $has_more ) {
						break;
					}

					$has_more = ! empty( $response['has_more'] );
					$page++;

					// Prevent execution timeout issues by checking page limits if needed
					if ( $page > 100 ) { // Safety valve
						break;
					}
				}
			}

			HistoryTable::complete_job( $history_id, 'completed' );
			Logger::info( sprintf( 'Completed full sync run. Processed: %d, Success: %d, Failed: %d.', $processed, $success, $failed ), 'sync_engine' );
			return $history_id;

		} catch ( \Exception $e ) {
			Logger::error( 'Exception caught during sync: ' . $e->getMessage(), 'sync_engine' );
			HistoryTable::complete_job( $history_id, 'failed' );
			return $history_id;
		}
	}

	/**
	 * Processes a page/batch of queue items (Incremental/Queue Sync).
	 *
	 * @param int $batch_size Number of queue items to process.
	 * @return int Number of items processed.
	 */
	public static function process_sync_queue( $batch_size = 20 ) {
		$pending = \WCErpSync\Database\QueueTable::get_pending_items( $batch_size );
		if ( empty( $pending ) ) {
			return 0;
		}

		$processed = 0;
		$registry  = Registry::instance();

		foreach ( $pending as $item ) {
			$processed++;
			$id          = $item['id'];
			$entity_type = $item['entity_type'];
			$action      = $item['action'];
			$payload     = ! empty( $item['payload'] ) ? json_decode( $item['payload'], true ) : [];

			$module = $registry->get_module( $entity_type );
			if ( ! $module ) {
				\WCErpSync\Database\QueueTable::update_status( $id, 'failed', sprintf( 'No active module found for entity %s', $entity_type ) );
				continue;
			}

			\WCErpSync\Database\QueueTable::update_status( $id, 'processing' );
			\WCErpSync\Database\QueueTable::increment_attempts( $id );

			try {
				if ( 'import' === $action ) {
					$result = $module->import_item( $payload );
				} elseif ( 'export' === $action ) {
					$result = $module->export_item( $item['local_id'] );
				} else {
					$result = false;
				}

				if ( $result ) {
					\WCErpSync\Database\QueueTable::update_status( $id, 'completed' );
				} else {
					\WCErpSync\Database\QueueTable::update_status( $id, 'failed', 'Processing returned false status.' );
				}
			} catch ( \Exception $e ) {
				\WCErpSync\Database\QueueTable::update_status( $id, 'failed', $e->getMessage() );
			}
		}

		return $processed;
	}

	/**
	 * Run incremental synchronization for changed products since a given date.
	 *
	 * @param string $since Date in YYYY-MM-DD format (defaults to last sync date or 1 day ago).
	 * @return array Result summary with total count and queued count.
	 */
	public static function run_incremental_sync( $since = '' ) {
		if ( empty( $since ) ) {
			$last_sync = get_option( 'wc_erp_sync_last_date', '' );
			$since     = ! empty( $last_sync ) ? $last_sync : date( 'Y-m-d', strtotime( '-1 day' ) );
		}

		Logger::info( sprintf( 'Starting incremental sync for changes since %s...', $since ), 'sync_engine' );

		$client = \WCErpSync\API\ClientFactory::get_client();
		$result = $client->fetch_changed_products( $since );

		$queued = 0;
		if ( ! empty( $result['product_ids'] ) ) {
			foreach ( $result['product_ids'] as $ext_id ) {
				// Queue item for background processing
				QueueManager::enqueue_import( 'product', (string) $ext_id, [ 'external_id' => (string) $ext_id ] );
				$queued++;
			}
		}

		// Store current date as last sync date
		update_option( 'wc_erp_sync_last_date', date( 'Y-m-d' ) );

		Logger::info( sprintf( 'Incremental sync completed. %d products queued for update since %s.', $queued, $since ), 'sync_engine' );

		return [
			'since'       => $since,
			'total'       => isset( $result['total'] ) ? $result['total'] : count( $result['product_ids'] ),
			'queued'      => $queued,
			'product_ids' => $result['product_ids'],
		];
	}

	/**
	 * Process a single batch chunk of products for a category-specific sync run.
	 */
	public static function process_category_manual_batch( $history_id, $category_id, $page = 1, $batch_size = 10, $include_children = false ) {
		$client      = \WCErpSync\API\ClientFactory::get_client();
		$prod_module = Registry::instance()->get_module( 'product' );

		if ( ! $prod_module || ! $history_id || empty( $category_id ) ) {
			return [ 'completed' => true, 'processed' => 0, 'success' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'next_page' => 1 ];
		}

		global $wpdb;
		$table       = HistoryTable::get_table_name();
		$current_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $history_id ), ARRAY_A );

		$processed = $current_job ? intval( $current_job['records_processed'] ) : 0;
		$success   = $current_job ? intval( $current_job['records_success'] ) : 0;
		$failed    = $current_job ? intval( $current_job['records_failed'] ) : 0;

		$stats = get_transient( 'wc_erp_sync_job_counts_' . $history_id );
		if ( ! is_array( $stats ) ) {
			$stats = [ 'created' => 0, 'updated' => 0 ];
		}

		$response = $client->fetch_products_by_category( $category_id, [
			'page'             => $page,
			'limit'            => $batch_size,
			'include_children' => $include_children,
		] );

		if ( empty( $response['items'] ) ) {
			HistoryTable::complete_job( $history_id, 'completed' );
			return [
				'completed' => true,
				'processed' => $processed,
				'success'   => $success,
				'failed'    => $failed,
				'created'   => $stats['created'],
				'updated'   => $stats['updated'],
				'next_page' => $page,
				'error'     => ! empty( $response['error'] ) ? $response['error'] : '',
			];
		}

		foreach ( $response['items'] as $item ) {
			$processed++;

			$sku         = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
			$ext_id      = isset( $item['external_id'] ) ? (string) $item['external_id'] : '';
			$existing_id = ! empty( $sku ) ? wc_get_product_id_by_sku( $sku ) : 0;
			if ( ! $existing_id && ! empty( $ext_id ) ) {
				$existing_id = \WCErpSync\Database\MappingTable::get_local_id( 'product', $ext_id );
			}
			$is_new = empty( $existing_id );

			$local_id = $prod_module->import_item( $item );
			if ( $local_id ) {
				$success++;
				if ( $is_new ) {
					$stats['created']++;
				} else {
					$stats['updated']++;
				}
			} else {
				$failed++;
			}

			HistoryTable::update_job_progress( $history_id, $processed, $success, $failed );
			set_transient( 'wc_erp_sync_job_counts_' . $history_id, $stats, 3600 );
		}

		@set_time_limit( 300 );
		$has_more  = ! empty( $response['has_more'] );
		$next_page = $has_more ? ( $page + 1 ) : $page;

		if ( ! $has_more ) {
			HistoryTable::complete_job( $history_id, 'completed' );
		}

		return [
			'completed' => ! $has_more,
			'processed' => $processed,
			'success'   => $success,
			'failed'    => $failed,
			'created'   => $stats['created'],
			'updated'   => $stats['updated'],
			'page'      => $page + 1,
			'next_page' => $next_page,
			'total'     => isset( $response['total'] ) ? intval( $response['total'] ) : 0,
		];
	}

	/**
	 * Run fast stock-only update directly from API.
	 * Updates stock quantity and stock status in WooCommerce without re-downloading images or rewriting metadata.
	 *
	 * @param int $since_hours Number of hours to look back (default 1).
	 * @return array Stats of updated products [ 'success' => bool, 'updated' => int, 'skipped' => int, 'failed' => int, 'message' => string ]
	 */
	public static function run_stock_only_sync( $since_hours = 1 ) {
		$hours = max( 1, intval( $since_hours ) );
		Logger::info( sprintf( 'Starting automated stock-only sync (since_hours=%d)...', $hours ), 'stock_sync' );

		$client  = \WCErpSync\API\ClientFactory::get_client();
		$updated = 0;
		$skipped = 0;
		$failed  = 0;

		// 1. Primary Method: Use dedicated delta endpoint GET /api/stock-updates (with pagination to update ALL products)
		$page          = 1;
		$limit         = 200;
		$has_more      = true;
		$found_updates = false;

		while ( $has_more ) {
			$stock_res = method_exists( $client, 'fetch_stock_updates' ) ? $client->fetch_stock_updates( $hours, [ 'page' => $page, 'limit' => $limit ] ) : null;

			if ( empty( $stock_res['updates'] ) || ! is_array( $stock_res['updates'] ) ) {
				break;
			}

			$found_updates = true;
			foreach ( $stock_res['updates'] as $update ) {
				$sku = isset( $update['sku'] ) ? sanitize_text_field( $update['sku'] ) : '';
				if ( empty( $sku ) ) {
					continue;
				}

				$product_id = wc_get_product_id_by_sku( $sku );
				if ( ! $product_id ) {
					$skipped++;
					continue;
				}

				$new_stock  = isset( $update['new_stock'] ) && null !== $update['new_stock'] ? max( 0, intval( $update['new_stock'] ) ) : null;
				$new_status = isset( $update['new_status'] ) ? strtolower( trim( (string) $update['new_status'] ) ) : '';

				$res = self::apply_product_stock_update( $product_id, $new_stock, $new_status );
				if ( $res ) {
					$updated++;
					Logger::info( sprintf( 'Stock updated for SKU %s (WC ID: %d) -> Stock: %s, Status: %s', $sku, $product_id, null !== $new_stock ? $new_stock : 'auto', $new_status ), 'stock_sync' );
				} else {
					$failed++;
				}
			}

			$total_avail = isset( $stock_res['total'] ) ? intval( $stock_res['total'] ) : 0;
			if ( count( $stock_res['updates'] ) < $limit || ( $total_avail > 0 && ( $page * $limit ) >= $total_avail ) || $page >= 20 ) {
				$has_more = false;
			} else {
				$page++;
			}
		}

		if ( $found_updates ) {
			$msg = sprintf( 'Stock sync completed via /api/stock-updates. %d products updated, %d skipped, %d failed.', $updated, $skipped, $failed );
			Logger::info( $msg, 'stock_sync' );

			return [
				'success' => true,
				'updated' => $updated,
				'skipped' => $skipped,
				'failed'  => $failed,
				'source'  => 'stock_updates_endpoint',
				'message' => $msg,
			];
		}

		// 2. Safe Fallback: If /api/stock-updates returned empty or backend has temporary 500 error,
		// query changed products by date and update their stock without downloading images!
		$date_since = date( 'Y-m-d', strtotime( "-{$hours} hours" ) );
		Logger::info( sprintf( 'Notice: /api/stock-updates returned 0 items. Running safe fallback for changed products since %s...', $date_since ), 'stock_sync' );

		$changed = method_exists( $client, 'fetch_changed_products' ) ? $client->fetch_changed_products( $date_since ) : [];
		if ( ! empty( $changed['product_ids'] ) && is_array( $changed['product_ids'] ) ) {
			foreach ( $changed['product_ids'] as $ext_id ) {
				$local_id = \WCErpSync\Database\MappingTable::get_local_id( 'product', (string) $ext_id );
				if ( ! $local_id ) {
					continue;
				}

				$product = wc_get_product( $local_id );
				if ( ! $product ) {
					continue;
				}

				$sku = $product->get_sku();
				if ( empty( $sku ) ) {
					continue;
				}

				$detail = $client->fetch_product_by_sku( $sku );
				if ( ! empty( $detail ) ) {
					$new_stock  = isset( $detail['stock'] ) ? $detail['stock'] : null;
					$new_status = isset( $detail['stock_status'] ) ? $detail['stock_status'] : '';
					$res = self::apply_product_stock_update( $local_id, $new_stock, $new_status );
					if ( $res ) {
						$updated++;
					}
				}
			}
		}

		$msg = sprintf( 'Stock sync completed. %d products updated (fallback mode for changes since %s).', $updated, $date_since );
		Logger::info( $msg, 'stock_sync' );

		return [
			'success' => true,
			'updated' => $updated,
			'skipped' => $skipped,
			'failed'  => $failed,
			'source'  => 'fallback',
			'message' => $msg,
		];
	}

	/**
	 * Apply stock quantity and status updates to a WooCommerce product.
	 */
	public static function apply_product_stock_update( $product_id, $new_stock, $new_status = '' ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		if ( null !== $new_stock ) {
			$stock_qty = max( 0, intval( $new_stock ) );
			$status    = $stock_qty > 0 ? 'instock' : 'outofstock';

			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_qty );
			$product->set_stock_status( $status );
			$product->set_backorders( 'no' );
			$product->save();

			update_post_meta( $product_id, '_manage_stock', 'yes' );
			update_post_meta( $product_id, '_stock', $stock_qty );
			update_post_meta( $product_id, '_stock_status', $status );
			update_post_meta( $product_id, '_backorders', 'no' );

			global $wpdb;
			if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
				$wpdb->update(
					$wpdb->wc_product_meta_lookup,
					[
						'stock_quantity' => $stock_qty,
						'stock_status'   => $status,
					],
					[ 'product_id' => $product_id ]
				);
			}

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			clean_post_cache( $product_id );

			return true;
		} elseif ( ! empty( $new_status ) ) {
			$raw_status   = strtolower( trim( (string) $new_status ) );
			$status_clean = in_array( $raw_status, [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) ? 'outofstock' : 'instock';

			$product->set_stock_status( $status_clean );
			$product->save();

			update_post_meta( $product_id, '_stock_status', $status_clean );

			global $wpdb;
			if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
				$wpdb->update(
					$wpdb->wc_product_meta_lookup,
					[ 'stock_status' => $status_clean ],
					[ 'product_id' => $product_id ]
				);
			}

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			clean_post_cache( $product_id );

			return true;
		}

		return false;
	}
}


