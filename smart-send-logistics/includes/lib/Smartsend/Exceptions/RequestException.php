<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/HttpClientException.php';
require_once __DIR__ . '/../Response.php';

use Smartsend\Response;

/**
 * The HTTP exchange with the Smart Send API completed, but the API
 * answered with a non-2xx status code. The full Response is attached.
 *
 * The exception message is the API error body's "message" when one was
 * provided, otherwise a generic description of the HTTP status.
 *
 * Resource methods catch this and re-throw the domain-specific subclass
 * (UnauthenticatedException, ForbiddenException, ValidationException,
 * ServerException) appropriate for the call that was made.
 */
class RequestException extends HttpClientException
{
    protected Response $response;

    /**
     * @param   Response $response The completed non-2xx response.
     * @param   string|null $message Overriding message; defaults to the response body's message or a generic HTTP-status description.
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
     * Derive the exception message from the response: the API error
     * body's "message" when one was provided, otherwise a generic
     * description of the HTTP status.
     *
     * @param   Response $response
     * @return  string
     */
    private static function defaultMessage(Response $response): string
    {
        $message = $response->message();

        if ($message !== null && $message !== '') {
            return $message;
        }

        if ($response->rawBody() === '') {
            return 'The Smart Send API returned an empty response (HTTP '.$response->statusCode().'). Please try again later.';
        }

        return 'The Smart Send API returned an unexpected response (HTTP '.$response->statusCode().'). Please try again later.';
    }
}
