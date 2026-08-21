<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/HttpClientException.php';
require_once __DIR__ . '/../Response.php';

use Smartsend\Response;

/**
 * The Smart Send API answered 2xx, but the body does not match what the
 * calling resource method expects (missing/malformed "data", non-JSON
 * where JSON was required).
 *
 * Whether a body matches the expected format is endpoint-specific - an
 * empty or non-JSON body may be perfectly valid for one call and a
 * defect for another - so this exception is thrown by the resource
 * methods, never by the Client itself.
 */
class UnexpectedResponseException extends HttpClientException
{
    protected Response $response;

    /**
     * @param   Response $response The completed 2xx response with the unusable body.
     * @param   string|null $message Overriding message; defaults to a generic description including the (truncated) body.
     */
    public function __construct(Response $response, ?string $message = null)
    {
        $this->response = $response;

        parent::__construct($message !== null ? $message : self::defaultMessage($response));
    }

    /**
     * The completed HTTP response that caused this exception.
     *
     * @return  Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Generic message including the raw body, truncated so an entire HTML
     * page is never dumped on the merchant.
     *
     * @param   Response $response
     * @return  string
     */
    private static function defaultMessage(Response $response): string
    {
        if ($response->rawBody() === '') {
            return 'The Smart Send API returned an empty response (HTTP '.$response->statusCode().'). Please try again later.';
        }

        return 'The Smart Send API returned an unexpected response (HTTP '.$response->statusCode().'). Please try again later. Response: '.self::truncate($response->rawBody());
    }

    /**
     * Truncate a raw response body to 500 characters.
     *
     * @param   string $body
     * @return  string
     */
    private static function truncate($body)
    {
        if (strlen($body) > 500) {
            return substr($body, 0, 500).'...';
        }

        return $body;
    }
}
