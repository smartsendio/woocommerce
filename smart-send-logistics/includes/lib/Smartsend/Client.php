<?php


namespace Smartsend;

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Exceptions/HttpClientException.php';
require_once __DIR__ . '/Exceptions/ConnectionException.php';
require_once __DIR__ . '/Exceptions/RequestException.php';

use Smartsend\Exceptions\ConnectionException;
use Smartsend\Exceptions\RequestException;

/**
 * The HTTP transport against the Smart Send API (wp_remote_* based).
 *
 * The client knows exactly two things about an exchange: whether it
 * completed, and whether the status was 2xx. A transport failure throws
 * ConnectionException; a completed non-2xx exchange throws
 * RequestException (carrying the full Response); a 2xx exchange returns
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
class Client
{
    const TIMEOUT = 30;

    /** @var string Untyped: the value passes through the smart_send_api_endpoint filter, whose return value is not under our control. */
    private $api_host = 'https://app.smartsend.io/api/v1/';
    // ?string: setWebsite() can receive null when SS_Shipping_Api_Credentials'
    // parse_url() call fails to resolve a host (returns null on a malformed
    // site URL); setApiToken() can receive null when no token is configured
    // yet (SS_Shipping_Api_Credentials::api_token() returns null).
    private ?string $website = null;
    private ?string $api_token = null;
    private bool $demo = false;
    /** @var callable|null Untyped: PHP does not support callable property types (the setter parameter below is still hinted `callable`). */
    private $request_logger;

    public function __construct($api_token, $website, $demo=false)
    {
        $this->setApiToken($api_token);
        $this->setWebsite($website);
        $this->setDemo($demo);

        $this->api_host = apply_filters( 'smart_send_api_endpoint', $this->api_host);
    }

    public function setApiToken(?string $api_token): void
    {
        $this->api_token = $api_token;
    }

    public function setWebsite(?string $website): void
    {
        // Remove www. from the start of the website
        if ($website !== null && substr($website, 0, strlen('www.')) == 'www.') {
            $website = substr($website, strlen('www.'));
        }

        $this->website = $website;
    }

    public function setDemo(bool $demo): void
    {
        $this->demo = $demo;
    }

    public function getApiEndpoint()
    {
        return $this->getApiHost().($this->getDemo() ? 'demo/' : '')."website/".$this->getWebsite()."/";
    }

    private function getApiHost()
    {
        return $this->api_host;
    }

    private function getWebsite(): ?string
    {
        return $this->website;
    }

    private function getApiToken(): ?string
    {
        return $this->api_token;
    }

    public function getDemo(): bool
    {
        return $this->demo;
    }

    public function getModuleVersion()
    {
        return SS_SHIPPING_VERSION;
    }

    public function getUserAgent()
    {
        // Check if get_plugins() function exists. This is required on the front end of the
        // site, since it is in a file that is normally only loaded in the admin.
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Find WooCommerce version number
        $wooCommercePluginFolder = get_plugins( '/' . 'woocommerce' );
        $wooCommercePluginFile = 'woocommerce.php';
        if (isset($wooCommercePluginFolder[$wooCommercePluginFile]['Version'])) {
            $wooCommerceVersion = $wooCommercePluginFolder[$wooCommercePluginFile]['Version'];
        } else {
            $wooCommerceVersion = '';
        }

        // Find the HTTP User-agent
        $userAgent = array(
            "WordPress"     => get_bloginfo('version'),
            "WooCommerce"   => $wooCommerceVersion,
            "SmartSend"     => $this->getModuleVersion(),
            "PHP"           => phpversion(),
        );
        $userAgentString = str_replace('=', '/', http_build_query($userAgent, '', ' '));

        return $userAgentString;
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
    public function setRequestLogger(?callable $request_logger): void
    {
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
    private function logRequest($http_verb, $request_endpoint, $request_body, $status_code, $response_body, $success, $error_message, $started_at)
    {
        if (!is_callable($this->request_logger)) {
            return;
        }

        call_user_func($this->request_logger, array(
            'method'        => strtoupper($http_verb),
            'endpoint'      => $request_endpoint,
            'request_body'  => $request_body,
            'status_code'   => $status_code,
            'response_body' => $response_body,
            'success'       => $success,
            'error'         => $error_message,
            'start_time'    => $started_at,
            'end_time'      => microtime(true),
        ));
    }

    /**
     * Make an HTTP DELETE request - for deleting data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (if any)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  Response The completed 2xx response.
     * @throws  \Smartsend\Exceptions\ConnectionException When the exchange never completed (transport failure).
     * @throws  \Smartsend\Exceptions\RequestException When the API answered with a non-2xx status.
     */
    public function httpDelete($method, $args = array(), $headers = array(), $body=null, $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('delete', $method, $args, $headers, $body, $timeout);
    }
    /**
     * Make an HTTP GET request - for retrieving data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  Response The completed 2xx response.
     * @throws  \Smartsend\Exceptions\ConnectionException When the exchange never completed (transport failure).
     * @throws  \Smartsend\Exceptions\RequestException When the API answered with a non-2xx status.
     */
    public function httpGet($method, $args = array(), $headers = array(), $body=null, $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('get', $method, $args, $headers, $body, $timeout);
    }
    /**
     * Make an HTTP PATCH request - for performing partial updates
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  Response The completed 2xx response.
     * @throws  \Smartsend\Exceptions\ConnectionException When the exchange never completed (transport failure).
     * @throws  \Smartsend\Exceptions\RequestException When the API answered with a non-2xx status.
     */
    public function httpPatch($method, $args = array(), $headers = array(), $body=null, $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('patch', $method, $args, $headers, $body, $timeout);
    }
    /**
     * Make an HTTP POST request - for creating and updating items
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  Response The completed 2xx response.
     * @throws  \Smartsend\Exceptions\ConnectionException When the exchange never completed (transport failure).
     * @throws  \Smartsend\Exceptions\RequestException When the API answered with a non-2xx status.
     */
    public function httpPost($method, $args = array(), $headers = array(), $body=null, $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('post', $method, $args, $headers, $body, $timeout);
    }
    /**
     * Make an HTTP PUT request - for creating new items
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  Response The completed 2xx response.
     * @throws  \Smartsend\Exceptions\ConnectionException When the exchange never completed (transport failure).
     * @throws  \Smartsend\Exceptions\RequestException When the API answered with a non-2xx status.
     */
    public function httpPut($method, $args = array(), $headers = array(), $body=null, $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('put', $method, $args, $headers, $body, $timeout);
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
    private function makeRequest($http_verb, $method, $args = array(), $headers=array(), $body=null, $timeout = self::TIMEOUT)
    {
        // If the headers where not set, then use default
        if (empty($headers)) {
            $headers = array(
                'Accept: application/json',
                'Content-Type: application/json',
            );
        }

        // Append API key to the headers
        $args['api_token'] = $this->getApiToken();

        // Set URL (inc parameters $args)
        $request_endpoint = $this->getApiEndpoint().$method;

        if (!empty($args) && strpos($request_endpoint, '?') !== false) {
            $request_endpoint .= '&'.http_build_query($args, '', '&');
        } elseif (!empty($args)) {
            $request_endpoint .= '?'.http_build_query($args, '', '&');
        }

        // Set body (if $http_verb not delete)
        $request_body = null;
        if ($http_verb != 'get' && $http_verb != 'delete') {
            $request_body = ($body ? json_encode($body) : null);
        }

        if (!isset($headers['referer'])) {
	        $headers['referer'] = $this->getWebsite();
        }

        // Split headers into key-value array
	    $headers_key_value = array();
	    foreach ($headers as $header) {
		    $tmp = explode(': ', $header, 2);
		    if (isset($tmp[1])) {
			    $headers_key_value[$tmp[0]] = $tmp[1];
		    }
	    }

	    // Make request
	    $request_started_at = microtime(true);
	    $res = wp_remote_request($request_endpoint, array(
		    'method'     => strtoupper($http_verb),
		    'user-agent' => $this->getUserAgent(),
			'headers'    => $headers_key_value,
		    'body'       => $request_body,
		    'timeout'    => $timeout,
		    'httpversion' => '1.1',
            'sslverify'  => apply_filters('smart_send_sslverify', true),
            // Equivalent to using wp_safe_remote_*(): the endpoint is
            // filterable at runtime (smart_send_api_endpoint), so reject
            // requests that resolve to a private/internal IP range (SSRF
            // hardening). Independent of sslverify above and does not
            // affect the mocked pre_http_request tests, which short-circuit
            // before this check runs.
            'reject_unsafe_urls' => true,
	    ));

        // Transport-level failure: the request never produced an HTTP response
        if (is_wp_error($res)) {
            $exception = ConnectionException::fromWpError($res);
            $this->logRequest($http_verb, $request_endpoint, $request_body, '', '', false, $exception->getMessage(), $request_started_at);
            throw $exception;
        }

        $response_body = wp_remote_retrieve_body($res);
        $http_status_code = wp_remote_retrieve_response_code($res);
        $content_type = wp_remote_retrieve_header($res, 'content-type');
        $response_id = wp_remote_retrieve_header($res, 'response-id');

        // If response is JSON, then json_decode
        $decoded = null;
        if (strpos($content_type, 'application/json') !== false || strpos($content_type, 'text/json') !== false) {
            $decoded = json_decode($response_body);
        }

        $response = new Response(
            is_object($decoded) && isset($decoded->data) ? $decoded->data : null,
            is_object($decoded) && isset($decoded->message) ? (string) $decoded->message : null,
            is_object($decoded) && isset($decoded->errors) ? (array) $decoded->errors : array(),
            (string) $response_body,
            $http_status_code,
            is_string($response_id) && $response_id !== '' ? $response_id : null,
            $request_started_at,
            microtime(true)
        );

        // Throw if response is not 2xx
        if ($http_status_code < 200 || $http_status_code > 299) {
            $exception = new RequestException($response);
            $this->logRequest($http_verb, $request_endpoint, $request_body, $http_status_code, $response_body, false, $exception->getMessage(), $request_started_at);
            throw $exception;
        }

        $this->logRequest($http_verb, $request_endpoint, $request_body, $http_status_code, $response_body, true, null, $request_started_at);

        return $response;
    }
}
