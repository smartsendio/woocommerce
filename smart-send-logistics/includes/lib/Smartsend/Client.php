<?php


namespace Smartsend;

require_once 'Models/Error.php';

use Smartsend\Models\Error;

class Client
{
    const TIMEOUT = 30;

    /** @var string Untyped: the value passes through the smart_send_api_endpoint filter, whose return value is not under our control. */
    private $api_host = 'https://app.smartsend.io/api/v1/';
    /** @var string|null Untyped: assigned via the public setWebsite() setter without casting. */
    private $website;
    /** @var string|null Untyped: assigned via the public setApiToken() setter without casting. */
    private $api_token;
    /** @var bool Untyped: assigned via the public setDemo() setter without casting. */
    private $demo;
    protected ?string $request_endpoint = null;
    protected ?array $request_headers = null;
    /** @var string|null Untyped: json_encode() can return false on encoding failure. */
    protected $request_body;
    /** @var array|\WpOrg\Requests\Utility\CaseInsensitiveDictionary|null Untyped: wp_remote_retrieve_headers() returns either shape. */
    protected $response_headers;
    protected ?string $response_body = null;
    /** @var mixed Decoded JSON response body. */
    protected $response;
    /** @var int|string|null Untyped: wp_remote_retrieve_response_code() returns '' on transport failure. */
    protected $http_status_code;
    /** @var string|array|null Untyped: wp_remote_retrieve_header() returns an array for duplicate headers. */
    protected $content_type;
    /** @var array|\WP_Error|null Untyped: holds the raw wp_remote_request() result. */
    protected $debug;
    /** @var mixed */
    protected $meta;
    protected ?bool $success = null;
    /** @var mixed API response data. */
    protected $data;
    /** @var mixed API response links. */
    protected $links;
    protected ?Error $error = null;
    /** @var callable|null Untyped: PHP does not support callable property types. */
    private $request_logger;
    private ?float $request_started_at = null;

    public function __construct($api_token, $website, $demo=false)
    {
        $this->setApiToken($api_token);
        $this->setWebsite($website);
        $this->setDemo($demo);

        $this->api_host = apply_filters( 'smart_send_api_endpoint', $this->api_host);
    }

    public function setApiToken($api_token)
    {
        $this->api_token = $api_token;
    }

    public function setWebsite($website)
    {
        // Remove www. from the start of the website
        if (substr($website, 0, strlen('www.')) == 'www.') {
            $website = substr($website, strlen('www.'));
        }

        $this->website = $website;
    }

    public function setDemo($demo)
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

    private function getWebsite() 
    {
        return $this->website;
    }

    private function getApiToken() 
    {
        return $this->api_token;
    }

    public function getDemo() 
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
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return mixed
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return string
     */
    public function getErrorString($delimiter='<br>')
    {
	    // Fetch error:
	    $error = $this->getError();

	    // Print error message
	    $error_string = $error->message;
	    // Print 'Read more here' link to error explenation
	    if (isset($error->links->about)) {
		    $error_string .= $delimiter."- <a href='".$error->links->about."' target='_blank'>Read more here</a>";
	    }

	    // Print unique error ID if one exists
	    if (isset($error->id)) {
		    $error_string .= $delimiter."Unique ID: ".$error->id;
	    }

	    // Print each error
	    if (isset($error->errors)) {
		    foreach ($error->errors as $error_field => $error_details) {
			    if (is_array($error_details)) {
				    if (count($error_details) > 1) {
					    $error_string .= $delimiter . $error_field .':';
					    foreach ($error_details as $error_description) {
						    $error_string .= $delimiter . "- ". $error_description;
					    }
				    } else {
					    foreach ($error_details as $error_description) {
						    $error_string .= $delimiter . "- " . $error_field . ': ' . $error_description;
					    }
				    }
			    } else {
				    $error_string .= $delimiter . "- " . $error_field . ': ' . $error_details;
			    }
		    }
	    }

	    return $error_string;
    }

    /**
     * @return void
     */
    public function printError()
    {
        echo $this->getErrorString('<br>');
    }

    /**
     * @return mixed
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * @return mixed
     */
    public function getRequestEndpoint()
    {
        return $this->request_endpoint;
    }

    /**
     * @return mixed
     */
    public function getRequestBody()
    {
        return $this->request_body;
    }

    /**
     * @return mixed
     */
    public function getRequestHeaders()
    {
        return $this->request_headers;
    }

    /**
     * @return mixed
     */
    public function getResponseBody()
    {
        return $this->response_body;
    }

    /**
     * @return mixed
     */
    public function getResponseHeaders()
    {
        return $this->response_headers;
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
    public function setRequestLogger($request_logger)
    {
        $this->request_logger = $request_logger;
    }

    /**
     * Invoke the injected request logger (if any) with the current
     * request/response state.
     *
     * @param   string $http_verb The HTTP verb used for the request
     * @return  void
     */
    private function logRequest($http_verb)
    {
        if (!is_callable($this->request_logger)) {
            return;
        }

        call_user_func($this->request_logger, array(
            'method'        => strtoupper($http_verb),
            'endpoint'      => $this->request_endpoint,
            'request_body'  => $this->request_body,
            'status_code'   => $this->http_status_code,
            'response_body' => $this->response_body,
            'success'       => (bool) $this->success,
            'error'         => $this->error,
            'start_time'    => $this->request_started_at,
            'end_time'      => microtime(true),
        ));
    }

    /**
     * Was the API response contain link to next page of results
     * @return  boolean
     */
    public function isSuccessful()
    {
        return $this->success;
    }

    /**
     * Return all request and response traces
     * @return  void
     */
    private function clearAll()
    {
        $this->request_endpoint = null;
        $this->request_headers = null;
        $this->request_body = null;
        $this->response_headers = null;
        $this->response_body = null;
        $this->response = null;
        $this->meta = null;
        $this->data = null;
        $this->links = null;
        $this->error = null;
        $this->success = null;
        $this->http_status_code = null;
        $this->content_type = null;
        $this->debug = null;
        $this->request_started_at = null;
    }

    /**
     * Make an HTTP DELETE request - for deleting data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (if any)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  object|true|false   Assoc array of API response, decoded from JSON
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
     * @return  object|true|false   Assoc array of API response, decoded from JSON
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
     * @return  object|true|false   Assoc array of API response, decoded from JSON
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
     * @return  object|true|false   Assoc array of API response, decoded from JSON
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
     * @return  object|true|false   Assoc array of API response, decoded from JSON
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
     * @return  object|true|false   Assoc array of API response, decoded from JSON
     *
     * @throws \Exception
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

        // Clear request and response from previous API call
        $this->clearAll();

        // Set URL (inc parameters $args)
        $this->request_endpoint = $this->getApiEndpoint().$method;

        if (!empty($args) && strpos($this->request_endpoint, '?') !== false) {
            $this->request_endpoint .= '&'.http_build_query($args, '', '&');
        } elseif (!empty($args)) {
            $this->request_endpoint .= '?'.http_build_query($args, '', '&');
        }

        // Set body (if $http_verb not delete)
        if ($http_verb != 'get' && $http_verb != 'delete') {
            $this->request_body = ($body ? json_encode($body) : null);
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
	    $this->request_started_at = microtime(true);
	    $this->request_headers = $headers_key_value;
	    $res = wp_remote_request($this->request_endpoint, array(
		    'method'     => strtoupper($http_verb),
		    'user-agent' => $this->getUserAgent(),
			'headers'    => $headers_key_value,
		    'body'       => $this->request_body,
		    'timeout'    => $timeout,
		    'httpversion' => '1.1',
            'sslverify'  => apply_filters('smart_send_sslverify', true),
	    ));

        // execute request
	    $this->response_body = wp_remote_retrieve_body($res);

        // Save http status code and headers
	    $this->debug = $res;
	    $this->response_headers = wp_remote_retrieve_headers($res);
	    $this->http_status_code = wp_remote_retrieve_response_code($res);
	    $this->content_type = wp_remote_retrieve_header($res, 'content-type');

        // Transport-level failure: the request never produced an HTTP response
        if (is_wp_error($res)) {
            $this->success = false;
            $this->error = $this->createErrorFromWpError($res);
            $this->logRequest($http_verb);
            return $this->success;
        }

        // If response is JSON, then json_decode
        if (strpos($this->content_type, 'application/json') !== false || strpos($this->content_type, 'text/json') !== false) {
            $this->response = json_decode($this->response_body);
        }

        //Error if response is not 2xx
        if ($this->http_status_code < 200 || $this->http_status_code > 299 ) {
            $this->success = false;

            if (is_object($this->response) && !empty($this->response->message)) {
                // Well-formed API error body (e.g. a validation error)
                $this->error = $this->createErrorFromApiResponse($this->response);
            } elseif (empty($this->response_body)) {
                $this->error = $this->createError(
                    'api-empty-response',
                    'The Smart Send API returned an empty response (HTTP '.$this->http_status_code.'). Please try again later.'
                );
            } else {
                // Body present but not a parseable/recognisable JSON error
                $this->error = $this->createError(
                    'api-malformed-response',
                    'The Smart Send API returned an unexpected response (HTTP '.$this->http_status_code.'). Please try again later.',
                    array('response' => array($this->truncateForError($this->response_body)))
                );
            }

            $this->logRequest($http_verb);
            return $this->success;
        }

        // if no response->data
        if (empty($this->response->data)) {
            if ($http_verb == 'delete') {
                //Return TRUE for DELETE with no BODY
                $this->success = true;
            } elseif (is_object($this->response) && !empty($this->response->message)) {
                $this->error = $this->createErrorFromApiResponse($this->response);
                $this->success = false;
            } elseif (empty($this->response_body)) {
                $this->error = $this->createError(
                    'api-empty-response',
                    'The Smart Send API returned an empty response (HTTP '.$this->http_status_code.'). Please try again later.'
                );
                $this->success = false;
            } elseif (isset($this->response->data)) {
                $this->error = $this->createError('NoResults', 'No results found');
                $this->success = false;
            } else {
                $this->error = $this->createError(
                    'api-malformed-response',
                    'The Smart Send API returned an unexpected response (HTTP '.$this->http_status_code.'). Please try again later.',
                    array('response' => array($this->truncateForError($this->response_body)))
                );
                $this->success = false;
            }
        } else {
            if (isset($this->response->links)) {
                $this->links = $this->response->links;
            }

            $this->success = true;
            $this->data = $this->response->data;
        }

        $this->logRequest($http_verb);
        return $this->success;
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
     * @return  Error
     */
    private function createErrorFromApiResponse($response)
    {
        $error = new Error();
        $error->links = isset($response->links) ? $response->links : null;
        $error->id = isset($response->id) ? $response->id : null;
        $error->code = !empty($response->code) ? $response->code : (int) $this->http_status_code;
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
