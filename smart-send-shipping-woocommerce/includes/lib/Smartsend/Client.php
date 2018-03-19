<?php


namespace Smartsend;

require_once 'Exceptions/BadRequestException.php';
require_once 'Exceptions/ApiErrorException.php';
require_once 'Exceptions/NotFoundException.php';
require_once 'Exceptions/UnexpectedException.php';
require_once 'Exceptions/TimeoutErrorException.php';

use Smartsend\Exceptions\BadRequestException;
use Smartsend\Exceptions\ApiErrorException;
use Smartsend\Exceptions\NotFoundException;
use Smartsend\Exceptions\UnexpectedException;
use Smartsend\Exceptions\TimeoutErrorException;

class Client
{
    const TIMEOUT = 10;

    private $api_endpoint = 'http://smartsend-test.apigee.net/';
    private $api_key;
    public $request_endpoint;
    public $request_headers;
    public $request_body;
    public $response_headers;
    public $response_body;
    public $response;
    public $http_status_code;
    public $content_type;
    public $debug;
    private $meta;
    private $links;
    private $data;
    private $errors;

    public function __construct($apikey)
    {
        $this->api_key = $apikey;
    }

    /**
     * Was the API response contain link to next page of results
     * @return  boolean
     */
    public function isSuccessful()
    {
        return ( ((int) $this->http_status_code) >= 200 && ((int) $this->http_status_code) < 300 );
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
        $this->links = null;
        $this->data = null;
        $this->errors = null;
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
        $headers[] = 'Authorization: apikey ' . $this->api_key;

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
            $this->throwCurlError($ch);
        }
        //Throw UnexpectedError if response is not 2xx
        if( !$this->isSuccessful() )
        {
            // If the cURL error did not cause an exception, then throw UnexpectedException
            throw new UnexpectedException( 'Unexpected HTTP status code: '.$this->http_status_code );
        }

        //Throw UnexpectedError with cURL error if no body and request is not DELETE
        if( $http_verb != 'delete' && !$this->response_body)
        {
            throw new UnexpectedException('No body from cURL');
        }

        // close connection
        curl_close($ch);

        //Return TRUE for DELETE with no BODY
        if( $http_verb == 'delete' && !$this->response_body )
        {
            return true;
        }

        // Save response (function that: save body, header and json_encoded)
        $this->handleResponse();

        if( $http_verb != 'delete' && empty($this->response->data))
        {
            throw new UnexpectedException('No data in body from cURL');
        }

        return $this->response->data;
    }

    private function throwCurlError($ch) {
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        switch ($errno) {
            case 28:
                throw new TimeoutErrorException('API response reached timeout limit');
                break;
            default:
                throw new UnexpectedException('cURL error ('.$errno.'): '.$error);
        }
    }

    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);

        return $length === 0 ||
            (substr($haystack, -$length) === $needle);
    }

    private function handleResponse()
    {
        if( strpos($this->content_type,'json') !== false ) {
            $json_body = json_decode($this->response_body);

            // If the response contains error, throw the first one
            if(isset($json_body->errors[0]->code)) {
                throw new ApiErrorException($json_body->errors[0]->code);
            }

            // If the response contains no data, throw an UnexpectedError
            if(!isset($json_body->data)) {
                throw new UnexpectedException('No data returned');
            }

            if(isset($json_body->links)) {
                $this->links = $json_body->links;
            }

            $this->response = $json_body;
        }

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


}