<?php
namespace WCErpSync\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles batch processing states for manual triggers.
 */
class BatchProcessor {

	/**
	 * Safe fetcher that gets details of current sync queue.
	 */
	public static function get_current_status() {
		$pending    = \WCErpSync\Database\QueueTable::count_by_status( 'pending' );
		$processing = \WCErpSync\Database\QueueTable::count_by_status( 'processing' );
		$failed     = \WCErpSync\Database\QueueTable::count_by_status( 'failed' );
		$completed  = \WCErpSync\Database\QueueTable::count_by_status( 'completed' );

		$latest_history = \WCErpSync\Database\HistoryTable::get_latest_job();

		return [
			'queue' => [
				'pending'    => $pending,
				'processing' => $processing,
				'failed'     => $failed,
				'completed'  => $completed,
				'total'      => $pending + $processing + $failed + $completed,
			],
			'latest_job' => $latest_history ? $latest_history : null,
		];
	}
}
