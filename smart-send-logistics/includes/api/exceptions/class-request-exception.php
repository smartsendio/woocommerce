<?php

namespace Smart_Send\API\Exceptions;

use Smart_Send\API\Response;

/**
 * The HTTP exchange with the Smart Send API completed, but the API
 * answered with a non-2xx status code. The full Response is attached.
 *
 * The exception message is the API error body's "message" when one was
 * provided, otherwise a generic description of the HTTP status.
 *
 * Resource methods catch this and re-throw the domain-specific subclass
 * (Unauthenticated_Exception, Forbidden_Exception, Validation_Exception,
 * Server_Exception) appropriate for the call that was made.
 */
class Request_Exception extends HTTP_Client_Exception {

	protected Response $response;

	/**
	 * @param   Response $response The completed non-2xx response.
	 * @param   string|null $message Overriding message; defaults to the response body's message or a generic HTTP-status description.
	 */
	public function __construct( Response $response, ?string $message = null ) {
		$this->response = $response;

		parent::__construct( null !== $message ? $message : self::default_message( $response ) );
	}

	/**
	 * The completed HTTP response that caused this exception.
	 *
	 * @return  Response
	 */
	public function get_response(): Response {
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
	private static function default_message( Response $response ): string {
		$message = $response->message();

		if ( null !== $message && '' !== $message ) {
			return $message;
		}

		if ( $response->raw_body() === '' ) {
			return 'The Smart Send API returned an empty response (HTTP ' . $response->status_code() . '). Please try again later.';
		}

		return 'The Smart Send API returned an unexpected response (HTTP ' . $response->status_code() . '). Please try again later.';
	}
}
