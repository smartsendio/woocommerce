<?php


namespace Smartsend;

require_once __DIR__ . '/Models/Error.php';
require_once __DIR__ . '/Response.php';

use Smartsend\Models\Error;

/**
 * The HTTP transport against the Smart Send API (wp_remote_* based).
 *
 * Stateless between requests (#141): every http* call returns an
 * immutable Response value object carrying that call's outcome - no
 * response state persists on this instance, so interleaved calls on a
 * shared client can never corrupt each other's pending result.
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
     * @param   Response $response The outcome of the request
     * @param   string|null $response_body Raw response body
     * @return  void
     */
    private function logRequest($http_verb, $request_endpoint, $request_body, Response $response, $response_body)
    {
        if (!is_callable($this->request_logger)) {
            return;
        }

        call_user_func($this->request_logger, array(
            'method'        => strtoupper($http_verb),
            'endpoint'      => $request_endpoint,
            'request_body'  => $request_body,
            'status_code'   => $response->statusCode(),
            'response_body' => $response_body,
            'success'       => $response->isSuccessful(),
            'error'         => $response->error(),
            'start_time'    => $response->startedAt(),
            'end_time'      => $response->completedAt(),
        ));
    }

    /**
     * Make an HTTP DELETE request - for deleting data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (if any)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  Response
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
     * @return  Response
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
     * @return  Response
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
     * @return  Response
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
     * @return  Response
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

        // execute request
	    $response_body = wp_remote_retrieve_body($res);

        // Save http status code and headers
	    $http_status_code = wp_remote_retrieve_response_code($res);
	    $content_type = wp_remote_retrieve_header($res, 'content-type');

        // Transport-level failure: the request never produced an HTTP response
        if (is_wp_error($res)) {
            $response = $this->buildResponse(false, null, null, $this->createErrorFromWpError($res), $http_status_code, $request_started_at);
            $this->logRequest($http_verb, $request_endpoint, $request_body, $response, $response_body);
            return $response;
        }

        // If response is JSON, then json_decode
        $decoded = null;
        if (strpos($content_type, 'application/json') !== false || strpos($content_type, 'text/json') !== false) {
            $decoded = json_decode($response_body);
        }

        //Error if response is not 2xx
        if ($http_status_code < 200 || $http_status_code > 299 ) {
            if (is_object($decoded) && !empty($decoded->message)) {
                // Well-formed API error body (e.g. a validation error)
                $error = $this->createErrorFromApiResponse($decoded, $http_status_code);
            } elseif (empty($response_body)) {
                $error = $this->createError(
                    'api-empty-response',
                    'The Smart Send API returned an empty response (HTTP '.$http_status_code.'). Please try again later.'
                );
            } else {
                // Body present but not a parseable/recognisable JSON error
                $error = $this->createError(
                    'api-malformed-response',
                    'The Smart Send API returned an unexpected response (HTTP '.$http_status_code.'). Please try again later.',
                    array('response' => array($this->truncateForError($response_body)))
                );
            }

            $response = $this->buildResponse(false, null, null, $error, $http_status_code, $request_started_at);
            $this->logRequest($http_verb, $request_endpoint, $request_body, $response, $response_body);
            return $response;
        }

        // if no response->data
        if (empty($decoded->data)) {
            if ($http_verb == 'delete') {
                //Successful DELETE with no BODY
                $response = $this->buildResponse(true, null, null, null, $http_status_code, $request_started_at);
            } elseif (is_object($decoded) && !empty($decoded->message)) {
                $response = $this->buildResponse(false, null, null, $this->createErrorFromApiResponse($decoded, $http_status_code), $http_status_code, $request_started_at);
            } elseif (empty($response_body)) {
                $error = $this->createError(
                    'api-empty-response',
                    'The Smart Send API returned an empty response (HTTP '.$http_status_code.'). Please try again later.'
                );
                $response = $this->buildResponse(false, null, null, $error, $http_status_code, $request_started_at);
            } elseif (isset($decoded->data)) {
                $response = $this->buildResponse(false, null, null, $this->createError('NoResults', 'No results found'), $http_status_code, $request_started_at);
            } else {
                $error = $this->createError(
                    'api-malformed-response',
                    'The Smart Send API returned an unexpected response (HTTP '.$http_status_code.'). Please try again later.',
                    array('response' => array($this->truncateForError($response_body)))
                );
                $response = $this->buildResponse(false, null, null, $error, $http_status_code, $request_started_at);
            }
        } else {
            $links = isset($decoded->links) ? $decoded->links : null;
            $response = $this->buildResponse(true, $decoded->data, $links, null, $http_status_code, $request_started_at);
        }

        $this->logRequest($http_verb, $request_endpoint, $request_body, $response, $response_body);
        return $response;
    }

    /**
     * Build the immutable Response for a finished request, stamping the
     * completion time.
     *
     * @param   bool $success Whether the request succeeded
     * @param   mixed $data Decoded response data, if any
     * @param   mixed $links Decoded response links, if any
     * @param   Error|null $error The error describing the failure, if any
     * @param   int|string|null $status_code HTTP status code ('' on transport failure)
     * @param   float|null $started_at Timestamp when the request started
     * @return  Response
     */
    private function buildResponse($success, $data, $links, ?Error $error, $status_code, ?float $started_at): Response
    {
        return new Response($success, $data, $links, $error, $status_code, $started_at, microtime(true));
    }

    /**
     * Build a Smartsend Error value object.
     *
     * @param   string|int $code Machine-readable error code
     * @param   string $message Human-readable error message
     * @param   array $errors Optional assoc array of error details
     * @return  Error
     */
    private function createError($code, $message, $errors = array())
    {
        $error = new Error();
        $error->links = null;
        $error->id = null;
        $error->code = $code;
        $error->message = $message;
        $error->errors = $errors;

        return $error;
    }

    /**
     * Normalise a decoded API error body (stdClass) into an Error value
     * object, keeping the fields the API provided (links, id, code,
     * message, errors).
     *
     * @param   object $response Decoded JSON error body
     * @param   int|string|null $http_status_code HTTP status code of the response
     * @return  Error
     */
    private function createErrorFromApiResponse($response, $http_status_code)
    {
        $error = new Error();
        $error->links = isset($response->links) ? $response->links : null;
        $error->id = isset($response->id) ? $response->id : null;
        $error->code = !empty($response->code) ? $response->code : (int) $http_status_code;
        $error->message = $response->message;
        $error->errors = isset($response->errors) ? $response->errors : array();

        return $error;
    }

    /**
     * Map a WP_Error returned by the WordPress HTTP API to a meaningful,
     * distinguishable Smartsend Error. The raw WP_Error code and message
     * are preserved in the errors array for support/debugging.
     *
     * @param   \WP_Error $wp_error
     * @return  Error
     */
    private function createErrorFromWpError($wp_error)
    {
        $wp_error_code = $wp_error->get_error_code();
        $wp_error_message = $wp_error->get_error_message();

        if ($this->isTimeoutError($wp_error_message)) {
            $code = 'transport-timeout';
            $message = 'The connection to the Smart Send API timed out. Please try again. If the problem persists, ask your host whether outgoing requests to app.smartsend.io are blocked or slow.';
        } elseif ($this->isSslError($wp_error_message)) {
            $code = 'transport-ssl';
            $message = 'A secure (SSL/TLS) connection to the Smart Send API could not be established. Please ask your host to update the server\'s SSL/TLS libraries and CA certificates.';
        } elseif ($this->isConnectionError($wp_error_message)) {
            $code = 'transport-connection';
            $message = 'Could not connect to the Smart Send API. Please check that the server can reach app.smartsend.io (DNS and outgoing HTTPS connections) and try again.';
        } else {
            $code = 'transport-'.($wp_error_code ? $wp_error_code : 'unknown');
            $message = 'The request to the Smart Send API failed before a response was received: '.$wp_error_message;
        }

        return $this->createError($code, $message, array(
            'transport' => array($wp_error_code.': '.$wp_error_message),
        ));
    }

    /**
     * Whether a WP_Error message describes a timed-out request.
     *
     * @param   string $message
     * @return  bool
     */
    private function isTimeoutError($message)
    {
        return $this->messageContainsAny($message, array(
            'timed out',
            'timeout',
            'curl error 28',
        ));
    }

    /**
     * Whether a WP_Error message describes an SSL/TLS failure.
     *
     * @param   string $message
     * @return  bool
     */
    private function isSslError($message)
    {
        return $this->messageContainsAny($message, array(
            'ssl',
            'certificate',
            'curl error 35:',
            'curl error 51:',
            'curl error 58:',
            'curl error 60:',
        ));
    }

    /**
     * Whether a WP_Error message describes a DNS/connection failure.
     *
     * @param   string $message
     * @return  bool
     */
    private function isConnectionError($message)
    {
        return $this->messageContainsAny($message, array(
            'could not resolve',
            "couldn't resolve",
            'name or service not known',
            'connection refused',
            'failed to connect',
            'network is unreachable',
            'curl error 6:',
            'curl error 7:',
        ));
    }

    /**
     * Case-insensitive check whether a message contains any of the needles.
     *
     * @param   string $message
     * @param   array $needles
     * @return  bool
     */
    private function messageContainsAny($message, $needles)
    {
        foreach ($needles as $needle) {
            if (stripos((string) $message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Truncate a raw response body so it can be embedded in an error
     * without dumping an entire HTML page on the merchant.
     *
     * @param   string $body
     * @return  string
     */
    private function truncateForError($body)
    {
        $body = (string) $body;

        if (strlen($body) > 500) {
            return substr($body, 0, 500).'...';
        }

        return $body;
    }

}
