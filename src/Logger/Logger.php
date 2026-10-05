<?php
namespace WCErpSync\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\LogTable;

/**
 * Robust Logger System for ERP Sync Framework.
 * Integrates custom Database logging with WooCommerce's native file-logging.
 */
class Logger {

	/**
	 * WC Logger instance.
	 *
	 * @var \WC_Logger|null
	 */
	private static $wc_logger = null;

	/**
	 * Log Handle.
	 */
	const LOG_HANDLE = 'wc-erp-sync-framework';

	/**
	 * Initialize the Logger.
	 */
	public static function init() {
		if ( function_exists( 'wc_get_logger' ) ) {
			self::$wc_logger = wc_get_logger();
		}
	}

	/**
	 * Core log method. Writes to DB table and WooCommerce logs.
	 */
	public static function log( $level, $message, $module = 'core', $context = null ) {
		// 1. Write to custom DB log table
		LogTable::add_log( $level, $module, $message, $context );

		// 2. Write to WC native file logger
		if ( self::$wc_logger ) {
			$wc_context = [
				'source' => self::LOG_HANDLE,
				'module' => $module,
			];

			if ( ! empty( $context ) ) {
				$wc_context['context'] = is_array( $context ) || is_object( $context ) ? wp_json_encode( $context ) : $context;
			}

			// Format message for file
			$file_msg = sprintf( '[%s] %s', strtoupper( $module ), $message );

			// Call appropriate level on WC logger
			switch ( strtolower( $level ) ) {
				case 'error':
				case 'critical':
				case 'alert':
				case 'emergency':
					self::$wc_logger->error( $file_msg, $wc_context );
					break;
				case 'warning':
					self::$wc_logger->warning( $file_msg, $wc_context );
					break;
				case 'info':
				case 'notice':
					self::$wc_logger->info( $file_msg, $wc_context );
					break;
				case 'debug':
				default:
					self::$wc_logger->debug( $file_msg, $wc_context );
					break;
			}
		}
	}

	/**
	 * Log Info.
	 */
	public static function info( $message, $module = 'core', $context = null ) {
		self::log( 'info', $message, $module, $context );
	}

	/**
	 * Log Warning.
	 */
	public static function warning( $message, $module = 'core', $context = null ) {
		self::log( 'warning', $message, $module, $context );
	}

	/**
	 * Log Error.
	 */
	public static function error( $message, $module = 'core', $context = null ) {
		self::log( 'error', $message, $module, $context );
	}

	/**
	 * Log Debug.
	 */
	public static function debug( $message, $module = 'core', $context = null ) {
		self::log( 'debug', $message, $module, $context );
	}
}
