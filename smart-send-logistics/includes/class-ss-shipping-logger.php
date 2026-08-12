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
 * Static logger modeled on WC_Stripe_Logger.
 *
 * The class owns all logging concerns: it checks the plugin's debug setting,
 * formats every entry consistently (version-stamped header, optional timing
 * block) and writes through WC_Logger under a single source name.
 *
 * Whether an entry is written can be forced on/off with the
 * `smart_send_logging` filter.
 */
class SS_Shipping_Logger {

	/**
	 * The shared WC_Logger instance.
	 *
	 * @var WC_Logger_Interface
	 */
	public static $logger;

	const WC_LOG_FILENAME = 'smart-send-logistics';

	/**
	 * Log a debug trace message.
	 *
	 * Written at the `debug` level and only when the plugin's "Debug Log"
	 * setting is enabled (unless forced via the `smart_send_logging` filter).
	 *
	 * @param string   $message    Message to log.
	 * @param int|null $start_time Optional start timestamp for a timing block.
	 * @param int|null $end_time   Optional end timestamp for a timing block.
	 */
	public static function log( $message, $start_time = null, $end_time = null ) {
		self::write( 'debug', $message, self::is_enabled(), $start_time, $end_time );
	}

	/**
	 * Log an error message.
	 *
	 * Written at the `error` level and logged even when the plugin's debug
	 * setting is off (unless suppressed via the `smart_send_logging` filter).
	 *
	 * @param string   $message    Message to log.
	 * @param int|null $start_time Optional start timestamp for a timing block.
	 * @param int|null $end_time   Optional end timestamp for a timing block.
	 */
	public static function error( $message, $start_time = null, $end_time = null ) {
		self::write( 'error', $message, true, $start_time, $end_time );
	}

	/**
	 * Log an API request/response cycle as reported by the Smartsend client.
	 *
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
	 *     @type int|null    $start_time    Timestamp when the request started.
	 *     @type int|null    $end_time      Timestamp when the request finished.
	 * }
	 */
	public static function log_api_request( $context ) {
		$status_code = isset( $context['status_code'] ) && '' !== $context['status_code'] && null !== $context['status_code']
			? $context['status_code']
			: 'n/a';

		$message = ( isset( $context['method'] ) ? $context['method'] : '' )
			. ' ' . self::redact_endpoint( isset( $context['endpoint'] ) ? $context['endpoint'] : '' )
			. ' [HTTP ' . $status_code . ']';

		if ( ! empty( $context['request_body'] ) ) {
			$message .= "\n" . 'Request body: ' . $context['request_body'];
		}

		$message .= "\n" . 'Response body: ' . ( isset( $context['response_body'] ) && '' !== $context['response_body'] ? $context['response_body'] : '(empty)' );

		if ( empty( $context['success'] ) && ! empty( $context['error'] ) && is_object( $context['error'] ) ) {
			$error_code    = isset( $context['error']->code ) && is_scalar( $context['error']->code ) ? (string) $context['error']->code : '';
			$error_message = isset( $context['error']->message ) && is_scalar( $context['error']->message ) ? (string) $context['error']->message : '';

			if ( '' !== $error_code || '' !== $error_message ) {
				$message .= "\n" . 'Error: ' . trim( $error_code . ' - ' . $error_message, ' -' );
			}
		}

		$start_time = isset( $context['start_time'] ) ? $context['start_time'] : null;
		$end_time   = isset( $context['end_time'] ) ? $context['end_time'] : null;

		if ( ! empty( $context['success'] ) ) {
			self::log( $message, $start_time, $end_time );
		} else {
			self::error( $message, $start_time, $end_time );
		}
	}

	/**
	 * Check if debug logging is enabled in the plugin settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'woocommerce_' . SS_SHIPPING_METHOD_ID . '_settings' );
		$ss_debug = isset( $settings['ss_debug'] ) ? $settings['ss_debug'] : 'yes';

		return 'yes' === $ss_debug;
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
	 * Format and write an entry through WC_Logger.
	 *
	 * @param string   $level      WC log level (debug, info, error, ...).
	 * @param string   $message    Message to log.
	 * @param bool     $enabled    Whether logging is enabled for this entry (before the filter).
	 * @param int|null $start_time Optional start timestamp for a timing block.
	 * @param int|null $end_time   Optional end timestamp for a timing block.
	 */
	private static function write( $level, $message, $enabled, $start_time = null, $end_time = null ) {
		if ( ! class_exists( 'WC_Logger' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		/**
		 * Force Smart Send logging on or off, or inspect messages.
		 *
		 * @param bool   $enabled Whether this entry will be written.
		 * @param string $message The message being logged.
		 * @param string $level   The WC log level of the entry.
		 */
		if ( ! apply_filters( 'smart_send_logging', $enabled, $message, $level ) ) {
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		$log_entry = "\n" . '====Smart Send Version: ' . SS_SHIPPING_VERSION . '====' . "\n";

		if ( ! is_null( $start_time ) ) {
			$formatted_start_time = date_i18n( get_option( 'date_format' ) . ' g:ia', $start_time );
			$end_time             = is_null( $end_time ) ? time() : $end_time;
			$formatted_end_time   = date_i18n( get_option( 'date_format' ) . ' g:ia', $end_time );
			$elapsed_time         = round( abs( $end_time - $start_time ) / 60, 2 );

			$log_entry .= '====Start Log ' . $formatted_start_time . '====' . "\n" . $message . "\n";
			$log_entry .= '====End Log ' . $formatted_end_time . ' (' . $elapsed_time . ')====' . "\n\n";
		} else {
			$log_entry .= '====Start Log====' . "\n" . $message . "\n" . '====End Log====' . "\n\n";
		}

		self::$logger->log( $level, $log_entry, array( 'source' => self::WC_LOG_FILENAME ) );
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
