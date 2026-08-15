<?php
/**
 * Smart Send logger.
 *
 * @package SmartSendLogistics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Static logger delegating to WooCommerce's native logging.
 *
 * The class is a thin wrapper around wc_get_logger(): it owns the plugin's
 * debug gate, the `smart_send_logging` filter, the single log source name
 * and the API-token redaction — everything else (levels, retention, the
 * log viewer's context rendering) is left to WooCommerce core.
 *
 * Entries are concise single-line messages; structured data (plugin
 * version, endpoint, HTTP status, request/response bodies, timing) travels
 * in the `$context` array, which the WooCommerce log viewer renders
 * natively.
 *
 * Only the `debug` level is gated on the plugin's "Debug Log" setting;
 * `info`, `warning`, `error` and `critical` always log. The
 * `smart_send_logging` filter applies to all levels: it receives the
 * message and may rewrite it, or suppress the entry by returning null or
 * false.
 */
class SS_Shipping_Logger {

	/**
	 * The shared WC_Logger instance.
	 *
	 * Public and deliberately untyped: tests (and potentially merchant code)
	 * inject logger doubles that do not implement WC_Logger_Interface.
	 *
	 * @var WC_Logger_Interface|null
	 */
	public static $logger;

	private const LOG_SOURCE = 'smart-send-logistics';

	/**
	 * The WC log levels the logger dispatches to, matching wc_get_logger()'s
	 * level wrapper methods.
	 *
	 * @var string[]
	 */
	private const LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/**
	 * Log a debug trace message (gated on the plugin's debug setting).
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional structured context.
	 */
	public static function debug( $message, $context = array() ) {
		self::write( 'debug', $message, $context, self::is_enabled() );
	}

	/**
	 * Log an informational business event. Always logged, regardless of the
	 * debug setting.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional structured context.
	 */
	public static function info( $message, $context = array() ) {
		self::write( 'info', $message, $context, true );
	}

	/**
	 * Log a warning. Always logged, regardless of the debug setting.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional structured context.
	 */
	public static function warning( $message, $context = array() ) {
		self::write( 'warning', $message, $context, true );
	}

	/**
	 * Log an error. Always logged, regardless of the debug setting.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional structured context.
	 */
	public static function error( $message, $context = array() ) {
		self::write( 'error', $message, is_array( $context ) ? $context : array(), true );
	}

	/**
	 * Log a critical failure. Always logged, regardless of the debug setting.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional structured context.
	 */
	public static function critical( $message, $context = array() ) {
		self::write( 'critical', $message, $context, true );
	}

	/**
	 * Log an API request/response cycle as reported by the Smartsend client.
	 *
	 * Emits one concise line per request ("POST /shipments → 422 (312ms)")
	 * with the full detail (endpoint, bodies, error) in the context array.
	 * Successful calls are logged as debug traces (respecting the debug
	 * setting); failed calls are logged at the `error` level even when the
	 * debug setting is off.
	 *
	 * @param array $context {
	 *     Request/response data from \Smartsend\Client.
	 *
	 *     @type string      $method        HTTP verb (GET, POST, ...).
	 *     @type string      $endpoint      Full request URL.
	 *     @type string|null $request_body  JSON request body, if any.
	 *     @type int|string  $status_code   HTTP status code of the response.
	 *     @type string      $response_body Raw response body.
	 *     @type bool        $success       Whether the client deemed the call successful.
	 *     @type object|null $error         Smartsend\Models\Error describing the failure, if any.
	 *     @type float|null  $start_time    Timestamp when the request started.
	 *     @type float|null  $end_time      Timestamp when the request finished.
	 * }
	 */
	public static function log_api_request( $context ) {
		$status_code = isset( $context['status_code'] ) && '' !== $context['status_code'] && null !== $context['status_code']
			? $context['status_code']
			: 'n/a';

		$endpoint = self::redact_endpoint( isset( $context['endpoint'] ) ? $context['endpoint'] : '' );
		$path     = wp_parse_url( $endpoint, PHP_URL_PATH );

		$message = sprintf(
			'%1$s %2$s → %3$s',
			isset( $context['method'] ) ? $context['method'] : '',
			$path ? $path : $endpoint,
			$status_code
		);

		$log_context = array(
			'endpoint'    => $endpoint,
			'status_code' => isset( $context['status_code'] ) ? $context['status_code'] : null,
		);

		if ( isset( $context['start_time'], $context['end_time'] ) && is_numeric( $context['start_time'] ) && is_numeric( $context['end_time'] ) ) {
			$duration_ms                = (int) round( abs( $context['end_time'] - $context['start_time'] ) * 1000 );
			$message                    = sprintf( '%1$s (%2$dms)', $message, $duration_ms );
			$log_context['duration_ms'] = $duration_ms;
		}

		if ( ! empty( $context['request_body'] ) ) {
			$log_context['request_body'] = $context['request_body'];
		}

		$log_context['response_body'] = isset( $context['response_body'] ) && '' !== $context['response_body'] ? $context['response_body'] : '';

		if ( empty( $context['success'] ) && ! empty( $context['error'] ) && is_object( $context['error'] ) ) {
			$error_code    = isset( $context['error']->code ) && is_scalar( $context['error']->code ) ? (string) $context['error']->code : '';
			$error_message = isset( $context['error']->message ) && is_scalar( $context['error']->message ) ? (string) $context['error']->message : '';

			if ( '' !== $error_code || '' !== $error_message ) {
				$log_context['error'] = trim( $error_code . ' - ' . $error_message, ' -' );
			}
		}

		if ( ! empty( $context['success'] ) ) {
			self::debug( $message, $log_context );
		} else {
			self::error( $message, $log_context );
		}
	}

	/**
	 * Check if debug logging is enabled in the plugin settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		// v8 oddity, preserved: unlike the meta box's debug read, the logger
		// treats a never-saved setting as enabled.
		return ( new SS_Shipping_Settings() )->debug_log( true );
	}

	/**
	 * Get the WooCommerce log screen URL.
	 *
	 * @return string
	 */
	public static function get_log_url() {
		return admin_url( 'admin.php?page=wc-status&tab=logs' );
	}

	/**
	 * Write an entry through wc_get_logger().
	 *
	 * @param string $level   WC log level (debug, info, warning, error, critical).
	 * @param string $message Message to log.
	 * @param array  $context Structured context for the entry.
	 * @param bool   $enabled Whether logging is enabled for this entry (before the filter).
	 */
	private static function write( $level, $message, $context, $enabled ) {
		if ( ! $enabled || ! in_array( $level, self::LEVELS, true ) ) {
			return;
		}

		$context = array_merge(
			array( 'version' => SS_SHIPPING_VERSION ),
			is_array( $context ) ? $context : array(),
			array( 'source' => self::LOG_SOURCE )
		);

		/**
		 * Rewrite or suppress Smart Send log entries.
		 *
		 * Return a modified string to rewrite the message, or null/false to
		 * suppress the entry entirely.
		 *
		 * @param string $message The message being logged.
		 * @param string $level   The WC log level of the entry.
		 * @param array  $context The context array of the entry.
		 */
		$message = apply_filters( 'smart_send_logging', $message, $level, $context );

		if ( null === $message || false === $message ) {
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->{$level}( $message, $context );
	}

	/**
	 * Strip the API token from an endpoint URL before logging it.
	 *
	 * @param string $endpoint Request URL, possibly containing an api_token query parameter.
	 * @return string
	 */
	private static function redact_endpoint( $endpoint ) {
		return preg_replace( '/([?&]api_token=)[^&]*/', '${1}[REDACTED]', (string) $endpoint );
	}
}
