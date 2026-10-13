<?php
namespace WCErpSync\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Logger\Logger;

/**
 * Registers custom schedules and coordinates WP Cron callbacks.
 */
class Scheduler {

	const CRON_ACTION       = 'wc_erp_sync_run_cron';
	const STOCK_CRON_ACTION = 'wc_erp_sync_auto_stock_cron';

	/**
	 * Setup hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );
		add_action( self::CRON_ACTION, [ __CLASS__, 'run_scheduled_sync' ] );
		add_action( self::STOCK_CRON_ACTION, [ __CLASS__, 'run_scheduled_stock_sync' ] );

		self::reschedule_events();
	}

	/**
	 * Add custom intervals.
	 */
	public static function add_cron_intervals( $schedules ) {
		$schedules['every_15_mins'] = [
			'interval' => 900,
			'display'  => esc_html__( 'Every 15 Minutes', 'wc-erp-sync' ),
		];
		$schedules['every_30_mins'] = [
			'interval' => 1800,
			'display'  => esc_html__( 'Every 30 Minutes', 'wc-erp-sync' ),
		];
		$schedules['every_2_hours'] = [
			'interval' => 7200,
			'display'  => esc_html__( 'Every 2 Hours', 'wc-erp-sync' ),
		];
		$schedules['every_3_hours'] = [
			'interval' => 10800,
			'display'  => esc_html__( 'Every 3 Hours', 'wc-erp-sync' ),
		];
		$schedules['every_4_hours'] = [
			'interval' => 14400,
			'display'  => esc_html__( 'Every 4 Hours', 'wc-erp-sync' ),
		];
		$schedules['every_6_hours'] = [
			'interval' => 21600,
			'display'  => esc_html__( 'Every 6 Hours', 'wc-erp-sync' ),
		];
		$schedules['every_8_hours'] = [
			'interval' => 28800,
			'display'  => esc_html__( 'Every 8 Hours', 'wc-erp-sync' ),
		];
		$schedules['every_12_hours'] = [
			'interval' => 43200,
			'display'  => esc_html__( 'Every 12 Hours', 'wc-erp-sync' ),
		];
		return $schedules;
	}

	/**
	 * Periodically checks settings to match configured sync frequency.
	 */
	public static function reschedule_events() {
		$settings  = get_option( 'wc_erp_sync_settings', [] );

		// 1. Reschedule General Full Sync
		$frequency = isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly';
		if ( 'manual' === $frequency ) {
			self::clear_main_schedule();
		} else {
			$next = wp_next_scheduled( self::CRON_ACTION );
			if ( $next ) {
				$schedule = wp_get_schedule( self::CRON_ACTION );
				if ( $schedule !== $frequency ) {
					self::clear_main_schedule();
					wp_schedule_event( time() + 300, $frequency, self::CRON_ACTION );
					Logger::info( sprintf( 'Rescheduled full catalog cron sync with frequency: %s', $frequency ), 'scheduler' );
				}
			} else {
				wp_schedule_event( time() + 300, $frequency, self::CRON_ACTION );
				Logger::info( sprintf( 'Scheduled full catalog cron sync with frequency: %s', $frequency ), 'scheduler' );
			}
		}

		// 2. Reschedule Dedicated Auto Stock Sync
		$stock_enabled   = isset( $settings['auto_stock_sync_enabled'] ) ? $settings['auto_stock_sync_enabled'] : 'yes';
		$stock_frequency = isset( $settings['auto_stock_sync_frequency'] ) ? $settings['auto_stock_sync_frequency'] : 'hourly';

		if ( 'no' === $stock_enabled ) {
			self::clear_stock_schedule();
		} else {
			$next_stock = wp_next_scheduled( self::STOCK_CRON_ACTION );
			if ( $next_stock ) {
				$current_sched = wp_get_schedule( self::STOCK_CRON_ACTION );
				if ( $current_sched !== $stock_frequency ) {
					self::clear_stock_schedule();
					wp_schedule_event( time() + 120, $stock_frequency, self::STOCK_CRON_ACTION );
					Logger::info( sprintf( 'Rescheduled auto stock sync cron with frequency: %s', $stock_frequency ), 'scheduler' );
				}
			} else {
				wp_schedule_event( time() + 120, $stock_frequency, self::STOCK_CRON_ACTION );
				Logger::info( sprintf( 'Scheduled auto stock sync cron with frequency: %s', $stock_frequency ), 'scheduler' );
			}
		}
	}

	/**
	 * Cron Execution Callback for full incremental sync and catalog batch processing.
	 */
	public static function run_scheduled_sync() {
		Logger::info( 'Starting scheduled sync loop...', 'scheduler' );
		
		$history_id      = \WCErpSync\Database\HistoryTable::start_job( 'cron_incremental' );
		$total_processed = 0;
		$total_success   = 0;
		$total_failed    = 0;

		// 1. Process pending sync queue items first (up to 50 items per cron cycle)
		$queue_processed = Engine::process_sync_queue( 50 );
		if ( $queue_processed > 0 ) {
			$total_processed += $queue_processed;
			$total_success   += $queue_processed;
			Logger::info( sprintf( 'Cron completed processing %d queue items.', $queue_processed ), 'scheduler' );
		}

		// 2. Check ERP for changed products and queue up to a capped batch
		$res = Engine::run_incremental_sync();
		Logger::info( sprintf( 'Cron completed incremental scan. %d changed products queued for date %s.', $res['queued'], $res['since'] ), 'scheduler' );

		// 3. Automated Catalog Synchronization Batch (fetches new & updated products page by page)
		try {
			$client      = \WCErpSync\API\ClientFactory::get_client();
			$prod_module = \WCErpSync\Modules\Registry::instance()->get_module( 'product' );

			if ( $prod_module && method_exists( $client, 'fetch_products' ) ) {
				$current_page = max( 1, (int) get_option( 'wc_erp_sync_next_page', 1 ) );
				$batch_limit  = 25; // 25 products per scheduled cycle keeps server execution fast (<15s)

				$cat_res = $client->fetch_products( [ 'page' => $current_page, 'limit' => $batch_limit ] );
				if ( ! empty( $cat_res['items'] ) ) {
					$cat_success = 0;
					$cat_failed  = 0;
					$cat_created = 0;
					$cat_updated = 0;

					foreach ( $cat_res['items'] as $item ) {
						$total_processed++;
						$sku         = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
						$ext_id      = isset( $item['external_id'] ) ? (string) $item['external_id'] : '';
						$existing_id = ! empty( $sku ) ? wc_get_product_id_by_sku( $sku ) : 0;
						if ( ! $existing_id && ! empty( $ext_id ) ) {
							$existing_id = \WCErpSync\Database\MappingTable::get_local_id( 'product', $ext_id );
						}
						$is_new = empty( $existing_id );

						$local_id = $prod_module->import_item( $item );
						if ( $local_id ) {
							$cat_success++;
							$total_success++;
							if ( $is_new ) {
								$cat_created++;
							} else {
								$cat_updated++;
							}
						} else {
							$cat_failed++;
							$total_failed++;
						}
					}

					$next_page = ! empty( $cat_res['has_more'] ) ? ( $current_page + 1 ) : 1;
					update_option( 'wc_erp_sync_next_page', $next_page );

					Logger::info( sprintf( 'Scheduled catalog batch (Page %d -> Next %d): %d synced (%d added, %d updated, %d failed).', $current_page, $next_page, $cat_success, $cat_created, $cat_updated, $cat_failed ), 'scheduler' );
				} elseif ( empty( $cat_res['error'] ) ) {
					// Reached end of catalog; reset to page 1 for continuous cyclical sync
					update_option( 'wc_erp_sync_next_page', 1 );
				}
			}
		} catch ( \Exception $e ) {
			Logger::error( 'Exception during scheduled catalog batch sync: ' . $e->getMessage(), 'scheduler' );
		}

		if ( $history_id ) {
			\WCErpSync\Database\HistoryTable::update_job_progress( $history_id, $total_processed, $total_success, $total_failed );
			\WCErpSync\Database\HistoryTable::complete_job( $history_id, 'completed' );
		}
	}

	/**
	 * Cron Execution Callback for automated stock-only updates.
	 */
	public static function run_scheduled_stock_sync() {
		Logger::info( 'Starting scheduled automated stock update run...', 'scheduler' );

		$settings        = get_option( 'wc_erp_sync_settings', [] );
		$stock_frequency = isset( $settings['auto_stock_sync_frequency'] ) ? $settings['auto_stock_sync_frequency'] : 'hourly';

		$hours_map = [
			'every_15_mins'  => 1,
			'every_30_mins'  => 1,
			'hourly'         => 1,
			'every_2_hours'  => 2,
			'every_3_hours'  => 3,
			'every_4_hours'  => 4,
			'every_6_hours'  => 6,
			'every_8_hours'  => 8,
			'every_12_hours' => 12,
			'twicedaily'     => 12,
			'daily'          => 24,
		];

		$since_hours = isset( $hours_map[ $stock_frequency ] ) ? $hours_map[ $stock_frequency ] : 1;
		$result      = Engine::run_stock_only_sync( $since_hours );

		Logger::info( sprintf( 'Scheduled stock update finished. Message: %s', isset( $result['message'] ) ? $result['message'] : 'Done' ), 'scheduler' );
	}

	/**
	 * Clear all scheduled cron events.
	 */
	public static function clear_schedules() {
		self::clear_main_schedule();
		self::clear_stock_schedule();
	}

	public static function clear_main_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_ACTION );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_ACTION );
		}
	}

	public static function clear_stock_schedule() {
		$timestamp = wp_next_scheduled( self::STOCK_CRON_ACTION );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::STOCK_CRON_ACTION );
		}
	}
}
