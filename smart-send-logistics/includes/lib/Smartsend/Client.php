<?php


namespace Smartsend;

require_once 'Models/Error.php';

use Smartsend\Models\Error;

class Client
{
    const TIMEOUT = 30;

    private $api_host = 'https://smartsend-prod.apigee.net/api/v1/';
    private $website;
    private $api_token;
    private $demo;
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
    protected $links;
    protected $error;

    public function __construct($api_token, $website, $demo=false)
    {
        $this->setApiToken($api_token);
        $this->setWebsite($website);
        $this->setDemo($demo);
    }

    protected function setApiToken($api_token)
    {
        $this->api_token = $api_token;
    }

    protected function setWebsite($website)
    {
        // Remove www. from the start of the website
        if (substr($website, 0, strlen('www.')) == 'www.') {
            $website = substr($website, strlen('www.'));
        }
        $this->website = $website;
    }

    protected function setDemo($demo)
    {
        $this->demo = $demo;
    }

    public function getApiEndpoint() {
        return $this->getApiHost().($this->getDemo() ? 'demo/' : '')."website/".$this->getWebsite()."/";
    }

    private function getApiHost() {
        return $this->api_host;
    }

    private function getWebsite() {
        return $this->website;
    }

    private function getApiToken() {
        return $this->api_token;
    }

    public function getDemo() {
        return $this->demo;
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
        if(isset($error->links->about)) {
            $error_string .= $delimiter."- <a href='".$error->links->about."'>Read more here</a>";
        }
        // Print unique error ID if one exists
        if(isset($error->id)) {
            $error_string .= $delimiter."Unique ID: ".$error->id;
        }
        // Print each error
        if(isset($error->errors)) {
            foreach($error->errors as $error_details) {
                if(is_array($error_details)) {
                    foreach($error_details as $error_description) {
                        $error_string .= $delimiter."- ".$error_description;
                    }
                } else {
                    $error_string .= $delimiter."- ".$error_details;
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
        // Throw an error if curl is not present
        if (!function_exists('curl_init') || !function_exists('curl_setopt')) {
            throw new \Exception("cURL support is required, but can't be found.");
        }

        // If the headers where not set, then use default
        if(empty($headers)) {
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

        if(!empty($args) && strpos($this->request_endpoint,'?') !== false) {
            $this->request_endpoint .= '&'.http_build_query($args, '', '&');
        } elseif(!empty($args)) {
            $this->request_endpoint .= '?'.http_build_query($args, '', '&');
        }

        // Set body (if $http_verb not delete)
        if($http_verb != 'get' && $http_verb != 'delete') {
            $this->request_body = ($body ? json_encode($body) : null);
        }

        // Find plugin version number
        $path = dirname(__FILE__);
        $path = preg_replace('/includes\/lib\/Smartsend$/', '', $path);

        // Make request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->request_endpoint);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WooCommerce/'. SS_SHIPPING_VERSION);
        curl_setopt($ch, CURLOPT_REFERER, 'example.com');
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

            $error = new Error();
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
                $error->code = (int) $this->http_status_code;
                $error->message = 'No API response';
                $error->errors = array();
                $this->error = $error;
            } elseif(empty($this->response)) {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = (int) $this->http_status_code;
                $error->message = $this->response;
                $error->errors = array();
                $this->error = $error;
            } else {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = (int) $this->http_status_code;
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
                $error->code = 'HTTP' . $this->http_status_code;
                $error->message = 'No API response';
                $error->errors = array();
                $this->error = $error;
                $this->success = false;
            } elseif(isset($this->response->data)) {
                $error = new Error();
                $error->links = null;
                $error->id = null;
                $error->code = 'NoResults';
                $error->message = 'No results found';
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
            if(isset($this->response->links)) {
                $this->links = $this->response->links;
            }
            $this->success = true;
            $this->data = $this->response->data;
        }
        return $this->success;
    }

}
