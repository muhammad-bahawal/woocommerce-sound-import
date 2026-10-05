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
	 * Cron Execution Callback for full incremental sync.
	 */
	public static function run_scheduled_sync() {
		Logger::info( 'Starting scheduled sync loop...', 'scheduler' );
		
		// 1. Process sync queue items (e.g. pending orders, updates)
		$processed = Engine::process_sync_queue( 20 );
		if ( $processed > 0 ) {
			Logger::info( sprintf( 'Cron completed processing %d queue items.', $processed ), 'scheduler' );
		}

		// 2. Perform incremental product sync from ERP for changed products
		$res = Engine::run_incremental_sync();
		Logger::info( sprintf( 'Cron completed incremental sync. %d changed products queued for date %s.', $res['queued'], $res['since'] ), 'scheduler' );
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
