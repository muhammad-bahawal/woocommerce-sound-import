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

	const CRON_ACTION = 'wc_erp_sync_run_cron';

	/**
	 * Setup hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );
		add_action( self::CRON_ACTION, [ __CLASS__, 'run_scheduled_sync' ] );

		self::reschedule_events();
	}

	/**
	 * Add custom intervals.
	 */
	public static function add_cron_intervals( $schedules ) {
		$schedules['every_15_mins'] = [
			'interval' => 900, // 15 minutes in seconds
			'display'  => esc_html__( 'Every 15 Minutes', 'wc-erp-sync' ),
		];
		return $schedules;
	}

	/**
	 * Periodically checks settings to match configured sync frequency.
	 */
	public static function reschedule_events() {
		$settings  = get_option( 'wc_erp_sync_settings', [] );
		$frequency = isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly';

		// Clear if set to manual
		if ( 'manual' === $frequency ) {
			self::clear_schedules();
			return;
		}

		// If already scheduled with correct interval, do nothing
		$next = wp_next_scheduled( self::CRON_ACTION );
		if ( $next ) {
			$schedule = wp_get_schedule( self::CRON_ACTION );
			if ( $schedule === $frequency ) {
				return;
			}
			// Schedule changed, clear and reset
			self::clear_schedules();
		}

		// Schedule new interval
		wp_schedule_event( time() + 300, $frequency, self::CRON_ACTION );
		Logger::info( sprintf( 'Scheduled background cron sync with frequency: %s', $frequency ), 'scheduler' );
	}

	/**
	 * Cron Execution Callback.
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
	 * Remove scheduled cron events.
	 */
	public static function clear_schedules() {
		$timestamp = wp_next_scheduled( self::CRON_ACTION );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_ACTION );
		}
	}
}
