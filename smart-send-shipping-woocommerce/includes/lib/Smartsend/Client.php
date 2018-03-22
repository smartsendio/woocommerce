<?php


namespace Smartsend;

require_once 'Models/Error.php';

use Smartsend\Models\Error;

class Client
{
    const TIMEOUT = 10;

    private $api_endpoint = 'https://dumbledore.smartsend.io/api/v1/';//'http://dumbledore-smartsend-io-pni3xjp2uc43.runscope.net/api/v1/';
    private $api_token;
    protected $request_endpoint;
    protected $request_headers;
    protected $request_body;
    protected $response_headers;
    protected $response_body;
    protected $response;
    protected $http_status_code;
    protected $content_type;
    protected $debug;
    protected $meta;
    protected $success;
    protected $data;
    protected $error;

    public function __construct($api_token)
    {
        $this->api_token = $api_token;
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
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return mixed
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * @param mixed $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
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
        $this->error = null;
        $this->success = null;
        $this->http_status_code = null;
        $this->content_type = null;
        $this->debug = null;
    }

    /**
     * Make an HTTP DELETE request - for deleting data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (if any)
     * @param   array $headers Assoc array of headers
     * @param   array $body Assoc array of body (will be converted to json)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  array|true|false   Assoc array of API response, decoded from JSON
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
     * @return  array|true|false   Assoc array of API response, decoded from JSON
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
     * @return  array|true|false   Assoc array of API response, decoded from JSON
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
     * @return  array|true|false   Assoc array of API response, decoded from JSON
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
     * @return  array|true|false   Assoc array of API response, decoded from JSON
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
     * @return  array|true|false   Assoc array of API response, decoded from JSON
     * @throws \Exception
     */
    private function makeRequest($http_verb, $method, $args = array(), $headers=array(), $body=null, $timeout = self::TIMEOUT)
    {
        // Throw an error if curl is not present
        if (!function_exists('curl_init') || !function_exists('curl_setopt')) {
            throw new UnexpectedException("cURL support is required, but can't be found.");
        }

        // If the headers where not set, then use default
        if(empty($headers)) {
            $headers = array(
                'Accept: application/json',
                'Content-Type: application/json',
            );
        }

        // Append API key to the headers
        $args['api_token'] = $this->api_token;

        // Clear request and response from previous API call
        $this->clearAll();

        // Set URL (inc parameters $args)
        $this->request_endpoint = $this->api_endpoint.$method;
        if(!empty($args)) {
            $this->request_endpoint .= '?'.http_build_query($args, '', '&');
        }

        // Set body (if $http_verb not delete)
        if($http_verb != 'get' && $http_verb != 'delete') {
            $this->request_body = ($body ? json_encode($body) : null);
        }

        // Make request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->request_endpoint);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Smartsend-API/0.1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);

        switch ($http_verb) {
            case 'post':
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request_body);
                break;
            case 'get':
                break;
            case 'delete':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'patch':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request_body);
                break;
            case 'put':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request_body);
                break;
        }

        // execute request
        $this->response_body = curl_exec($ch);

        // Save http status code and headers
        $this->debug = curl_getinfo($ch);
        $this->request_headers = curl_getinfo($ch,CURLINFO_HEADER_OUT);
        $this->http_status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if(curl_errno($ch)) {
            $this->success = false;

            $error = new stdClass();
            $error->links = null;
            $error->id = null;
            $error->code = curl_errno($ch);
            $error->message = curl_error($ch);
            $error->errors = array();

            $this->error = $error;
            return $this->success;
        }
        // close connection
        curl_close($ch);

        // If response is JSON, then json_decode
        if(strpos($this->content_type, 'application/json') !== false || strpos($this->content_type, 'text/json') !== false) {
            $this->response = json_decode($this->response_body);
        }

        //Error if response is not 2xx
        if( $this->http_status_code < 200 || $this->http_status_code > 299 )
        {
            $this->success = false;
            if(!empty($this->response->message)) {
                $this->error = $this->response;
            } elseif(empty($this->response_body)) {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = 'HTTP'.$this->http_status_code;
                $error->message = 'No API response';
                $error->errors = array();
                $this->error = $error;
            } elseif(empty($this->response)) {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = 'HTTP'.$this->http_status_code;
                $error->message = $this->response;
                $error->errors = array();
                $this->error = $error;
            } else {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = 'HTTP'.$this->http_status_code;
                $error->message = 'Unknown API response';
                $error->errors = array();
                $this->error = $error;
            }
            return $this->success;
        }

        // if no response->data
        if(empty($this->response->data)) {
            if( $http_verb == 'delete') {
                //Return TRUE for DELETE with no BODY
                $this->success = true;
            } elseif(!empty($this->response->message)) {
                $this->error = $this->response;
                $this->success = false;
            } elseif(empty($this->response_body)) {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = 'HTTP'.$this->http_status_code;
                $error->message = 'No API response';
                $error->errors = array();
                $this->error = $error;
                $this->success = false;
            } else {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = 'HTTP'.$this->http_status_code;
                $error->message = $this->response_body;
                $error->errors = array();
                $this->error = $error;
                $this->success = false;
            }
        } else {
            $this->success = true;
            $this->data = $this->response->data;
        }
        return $this->success;
    }

}