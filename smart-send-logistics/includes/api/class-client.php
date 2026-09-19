<?php

namespace Smart_Send\API;

use Smart_Send\API\Exceptions\Connection_Exception;
use Smart_Send\API\Exceptions\Request_Exception;

/**
 * The HTTP transport against the Smart Send API (wp_remote_* based).
 *
 * The client knows exactly two things about an exchange: whether it
 * completed, and whether the status was 2xx. A transport failure throws
 * Connection_Exception; a completed non-2xx exchange throws
 * Request_Exception (carrying the full Response); a 2xx exchange returns
 * the immutable Response value object. Whether the 2xx body matches the
 * expected format is endpoint-specific and judged by the resource layer,
 * never here.
 *
 * Stateless between requests (#141): no response state persists on this
 * instance, so interleaved calls on a shared client can never corrupt
 * each other's pending result. The client does no logging of its own -
 * it only invokes the injected request-logger callable (if any) once per
 * request, on every outcome, before any throw.
 */
class Client {

	const TIMEOUT = 30;

	/**
	 * The production Smart Send host. The host is the only part of the
	 * base URL a consumer may replace (the smart_send_api_endpoint filter,
	 * applied by Smart_Send\Support\API_Factory on the WordPress side); the API
	 * version path below is appended by the client itself, so the plugin
	 * can move to a newer API version without any consumer noticing (#170).
	 */
	const DEFAULT_API_HOST = 'https://app.smartsend.io';

	/** The API version path the client targets, appended to the host. */
	const API_VERSION_PATH = '/api/v1/';

	/** @var string The host (scheme + hostname, no API version path, no trailing slash). */
	private string $api_host = self::DEFAULT_API_HOST;
	// ?string: set_website() can receive null when Smart_Send\Support\API_Credentials'
	// parse_url() call fails to resolve a host (returns null on a malformed
	// site URL); set_api_token() can receive null when no token is configured
	// yet (Smart_Send\Support\API_Credentials::api_token() returns null).
	private ?string $website   = null;
	private ?string $api_token = null;
	/** @var callable|null Untyped: PHP does not support callable property types (the setter parameter below is still hinted `callable`). */
	private $request_logger;

	public function __construct( $api_token, $website, ?string $api_host = null ) {
		$this->set_api_token( $api_token );
		$this->set_website( $website );
		$this->set_api_host( null === $api_host ? self::DEFAULT_API_HOST : $api_host );
	}

	/**
	 * Set the host the client talks to (e.g. 'https://app.smartsend.io' or
	 * a sandbox host). A trailing slash is tolerated, and a host that still
	 * carries the API version path is normalized defensively so the client
	 * never builds a '/api/v1/api/v1/' URL - see strip_api_version_path().
	 *
	 * @param   string $api_host
	 * @return  void
	 */
	public function set_api_host( string $api_host ): void {
		$this->api_host = self::strip_api_version_path( $api_host );
	}

	/**
	 * Whether a host value still ends with the API version path (with or
	 * without a trailing slash) - the pre-9.0 shape of the
	 * smart_send_api_endpoint filter value, which now carries the host only.
	 *
	 * @param   string $api_host
	 * @return  bool
	 */
	public static function has_api_version_path( string $api_host ): bool {
		return self::strip_api_version_path( $api_host ) !== rtrim( trim( $api_host ), '/' );
	}

	/**
	 * Normalize a host value: trim whitespace, drop a trailing slash and
	 * strip a trailing API version path ('/api/v1' or '/api/v1/') if the
	 * caller still passed one.
	 *
	 * @param   string $api_host
	 * @return  string
	 */
	public static function strip_api_version_path( string $api_host ): string {
		$api_host     = rtrim( trim( $api_host ), '/' );
		$version_path = rtrim( self::API_VERSION_PATH, '/' );

		if ( substr( $api_host, -strlen( $version_path ) ) === $version_path ) {
			$api_host = rtrim( substr( $api_host, 0, -strlen( $version_path ) ), '/' );
		}

		return $api_host;
	}

	public function set_api_token( ?string $api_token ): void {
		$this->api_token = $api_token;
	}

	public function set_website( ?string $website ): void {
		// Remove www. from the start of the website
		if ( null !== $website && 'www.' === substr( $website, 0, strlen( 'www.' ) ) ) {
			$website = substr( $website, strlen( 'www.' ) );
		}

		$this->website = $website;
	}

	public function get_api_endpoint() {
		return $this->get_api_host() . self::API_VERSION_PATH . 'website/' . $this->get_website() . '/';
	}

	/**
	 * The host the client talks to, without the API version path.
	 *
	 * @return  string
	 */
	public function get_api_host(): string {
		return $this->api_host;
	}

	private function get_website(): ?string {
		return $this->website;
	}

	private function get_api_token(): ?string {
		return $this->api_token;
	}

	public function get_module_version() {
		return SS_SHIPPING_VERSION;
	}

	public function get_user_agent() {
		// Check if get_plugins() function exists. This is required on the front end of the
		// site, since it is in a file that is normally only loaded in the admin.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Find WooCommerce version number
		$woo_commerce_plugin_folder = get_plugins( '/woocommerce' );
		$woo_commerce_plugin_file   = 'woocommerce.php';
		if ( isset( $woo_commerce_plugin_folder[ $woo_commerce_plugin_file ]['Version'] ) ) {
			$woo_commerce_version = $woo_commerce_plugin_folder[ $woo_commerce_plugin_file ]['Version'];
		} else {
			$woo_commerce_version = '';
		}

		// Find the HTTP User-agent
		$user_agent        = array(
			'WordPress'   => get_bloginfo( 'version' ),
			'WooCommerce' => $woo_commerce_version,
			'SmartSend'   => $this->get_module_version(),
			'PHP'         => phpversion(),
		);
		$user_agent_string = str_replace( '=', '/', http_build_query( $user_agent, '', ' ' ) );

		return $user_agent_string;
	}

	/**
	 * Inject a callable that is invoked after every HTTP request with the
	 * request/response data. This keeps the client free of any logging
	 * concerns - the consumer decides what (if anything) to do with the data.
	 *
	 * The callable receives a single associative array:
	 * method, endpoint, request_body, status_code, response_body, success,
	 * error, start_time, end_time.
	 *
	 * @param   callable|null $request_logger
	 * @return  void
	 */
	public function set_request_logger( ?callable $request_logger ): void {
		$this->request_logger = $request_logger;
	}

	/**
	 * Invoke the injected request logger (if any) with the given
	 * request/response data.
	 *
	 * @param   string $http_verb The HTTP verb used for the request
	 * @param   string $request_endpoint Full request URL
	 * @param   string|null $request_body JSON request body, if any
	 * @param   int|string|null $status_code HTTP status code ('' on transport failure)
	 * @param   string|null $response_body Raw response body
	 * @param   bool $success Whether the exchange completed with a 2xx status
	 * @param   string|null $error_message Message describing the failure, if any
	 * @param   float|null $started_at Timestamp when the request started
	 * @return  void
	 */
	private function log_request( $http_verb, $request_endpoint, $request_body, $status_code, $response_body, $success, $error_message, $started_at ) {
		if ( ! is_callable( $this->request_logger ) ) {
			return;
		}

		call_user_func(
			$this->request_logger,
			array(
				'method'        => strtoupper( $http_verb ),
				'endpoint'      => $request_endpoint,
				'request_body'  => $request_body,
				'status_code'   => $status_code,
				'response_body' => $response_body,
				'success'       => $success,
				'error'         => $error_message,
				'start_time'    => $started_at,
				'end_time'      => microtime( true ),
			)
		);
	}

	/**
	 * Make an HTTP DELETE request - for deleting data
	 * @param   string $method URL of the API request method
	 * @param   array $args Assoc array of arguments (if any)
	 * @param   array $headers Assoc array of headers
	 * @param   array $body Assoc array of body (will be converted to json)
	 * @param   int $timeout Timeout limit for request in seconds
	 * @return  Response The completed 2xx response.
	 * @throws  \Smart_Send\API\Exceptions\Connection_Exception When the exchange never completed (transport failure).
	 * @throws  \Smart_Send\API\Exceptions\Request_Exception When the API answered with a non-2xx status.
	 */
	public function http_delete( $method, $args = array(), $headers = array(), $body = null, $timeout = self::TIMEOUT ) {
		return $this->make_request( 'delete', $method, $args, $headers, $body, $timeout );
	}
	/**
	 * Make an HTTP GET request - for retrieving data
	 * @param   string $method URL of the API request method
	 * @param   array $args Assoc array of arguments (usually your data)
	 * @param   array $headers Assoc array of headers
	 * @param   array $body Assoc array of body (will be converted to json)
	 * @param   int $timeout Timeout limit for request in seconds
	 * @return  Response The completed 2xx response.
	 * @throws  \Smart_Send\API\Exceptions\Connection_Exception When the exchange never completed (transport failure).
	 * @throws  \Smart_Send\API\Exceptions\Request_Exception When the API answered with a non-2xx status.
	 */
	public function http_get( $method, $args = array(), $headers = array(), $body = null, $timeout = self::TIMEOUT ) {
		return $this->make_request( 'get', $method, $args, $headers, $body, $timeout );
	}
	/**
	 * Make an HTTP PATCH request - for performing partial updates
	 * @param   string $method URL of the API request method
	 * @param   array $args Assoc array of arguments (usually your data)
	 * @param   array $headers Assoc array of headers
	 * @param   array $body Assoc array of body (will be converted to json)
	 * @param   int $timeout Timeout limit for request in seconds
	 * @return  Response The completed 2xx response.
	 * @throws  \Smart_Send\API\Exceptions\Connection_Exception When the exchange never completed (transport failure).
	 * @throws  \Smart_Send\API\Exceptions\Request_Exception When the API answered with a non-2xx status.
	 */
	public function http_patch( $method, $args = array(), $headers = array(), $body = null, $timeout = self::TIMEOUT ) {
		return $this->make_request( 'patch', $method, $args, $headers, $body, $timeout );
	}
	/**
	 * Make an HTTP POST request - for creating and updating items
	 * @param   string $method URL of the API request method
	 * @param   array $args Assoc array of arguments (usually your data)
	 * @param   array $headers Assoc array of headers
	 * @param   array $body Assoc array of body (will be converted to json)
	 * @param   int $timeout Timeout limit for request in seconds
	 * @return  Response The completed 2xx response.
	 * @throws  \Smart_Send\API\Exceptions\Connection_Exception When the exchange never completed (transport failure).
	 * @throws  \Smart_Send\API\Exceptions\Request_Exception When the API answered with a non-2xx status.
	 */
	public function http_post( $method, $args = array(), $headers = array(), $body = null, $timeout = self::TIMEOUT ) {
		return $this->make_request( 'post', $method, $args, $headers, $body, $timeout );
	}
	/**
	 * Make an HTTP PUT request - for creating new items
	 * @param   string $method URL of the API request method
	 * @param   array $args Assoc array of arguments (usually your data)
	 * @param   array $headers Assoc array of headers
	 * @param   array $body Assoc array of body (will be converted to json)
	 * @param   int $timeout Timeout limit for request in seconds
	 * @return  Response The completed 2xx response.
	 * @throws  \Smart_Send\API\Exceptions\Connection_Exception When the exchange never completed (transport failure).
	 * @throws  \Smart_Send\API\Exceptions\Request_Exception When the API answered with a non-2xx status.
	 */
	public function http_put( $method, $args = array(), $headers = array(), $body = null, $timeout = self::TIMEOUT ) {
		return $this->make_request( 'put', $method, $args, $headers, $body, $timeout );
	}
	/**
	 * Performs the underlying HTTP request. Not very exciting.
	 * @param   string $http_verb The HTTP verb to use: get, post, put, patch, delete
	 * @param   string $method The API method to be called
	 * @param   array $args Assoc array of query parameters to be passed
	 * @param   array $headers Assoc array of headers
	 * @param   array $body Assoc array of body (will be converted to json)
	 * @param   int $timeout
	 * @return  Response
	 */
	private function make_request( $http_verb, $method, $args = array(), $headers = array(), $body = null, $timeout = self::TIMEOUT ) {
		// If the headers where not set, then use default
		if ( empty( $headers ) ) {
			$headers = array(
				'Accept: application/json',
				'Content-Type: application/json',
			);
		}

		// Append API key to the headers
		$args['api_token'] = $this->get_api_token();

		// Set URL (inc parameters $args)
		$request_endpoint = $this->get_api_endpoint() . $method;

		if ( ! empty( $args ) && strpos( $request_endpoint, '?' ) !== false ) {
			$request_endpoint .= '&' . http_build_query( $args, '', '&' );
		} elseif ( ! empty( $args ) ) {
			$request_endpoint .= '?' . http_build_query( $args, '', '&' );
		}

		// Set body (if $http_verb not delete)
		$request_body = null;
		if ( 'get' !== $http_verb && 'delete' !== $http_verb ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve the v1 wire serializer, including its invalid-UTF-8 behavior.
			$request_body = ( $body ? json_encode( $body ) : null );
		}

		if ( ! isset( $headers['referer'] ) ) {
			$headers['referer'] = $this->get_website();
		}

		// Split headers into key-value array
		$headers_key_value = array();
		foreach ( $headers as $header ) {
			$tmp = explode( ': ', $header, 2 );
			if ( isset( $tmp[1] ) ) {
				$headers_key_value[ $tmp[0] ] = $tmp[1];
			}
		}

		// Make request
		$request_started_at = microtime( true );
		$res                = wp_remote_request(
			$request_endpoint,
			array(
				'method'             => strtoupper( $http_verb ),
				'user-agent'         => $this->get_user_agent(),
				'headers'            => $headers_key_value,
				'body'               => $request_body,
				'timeout'            => $timeout,
				'httpversion'        => '1.1',
				'sslverify'          => apply_filters( 'smart_send_sslverify', true ),
				// Equivalent to using wp_safe_remote_*(): the host is
				// filterable at runtime (smart_send_api_endpoint), so reject
				// requests that resolve to a private/internal IP range (SSRF
				// hardening). Independent of sslverify above and does not
				// affect the mocked pre_http_request tests, which short-circuit
				// before this check runs.
				'reject_unsafe_urls' => true,
			)
		);

		// Transport-level failure: the request never produced an HTTP response
		if ( is_wp_error( $res ) ) {
			$exception = Connection_Exception::from_wp_error( $res );
			$this->log_request( $http_verb, $request_endpoint, $request_body, '', '', false, $exception->getMessage(), $request_started_at );
			throw $exception;
		}

		$response_body    = wp_remote_retrieve_body( $res );
		$http_status_code = wp_remote_retrieve_response_code( $res );
		$content_type     = wp_remote_retrieve_header( $res, 'content-type' );
		$response_id      = wp_remote_retrieve_header( $res, 'response-id' );

		// If response is JSON, then json_decode
		$decoded = null;
		if ( strpos( $content_type, 'application/json' ) !== false || strpos( $content_type, 'text/json' ) !== false ) {
			$decoded = json_decode( $response_body );
		}

		$response = new Response(
			is_object( $decoded ) && isset( $decoded->data ) ? $decoded->data : null,
			is_object( $decoded ) && isset( $decoded->message ) ? (string) $decoded->message : null,
			is_object( $decoded ) && isset( $decoded->errors ) ? (array) $decoded->errors : array(),
			(string) $response_body,
			$http_status_code,
			is_string( $response_id ) && '' !== $response_id ? $response_id : null,
			$request_started_at,
			microtime( true )
		);

		// Throw if response is not 2xx
		if ( $http_status_code < 200 || $http_status_code > 299 ) {
			$exception = new Request_Exception( $response );
			$this->log_request( $http_verb, $request_endpoint, $request_body, $http_status_code, $response_body, false, $exception->getMessage(), $request_started_at );
			throw $exception;
		}

		$this->log_request( $http_verb, $request_endpoint, $request_body, $http_status_code, $response_body, true, null, $request_started_at );

		return $response;
	}
}
