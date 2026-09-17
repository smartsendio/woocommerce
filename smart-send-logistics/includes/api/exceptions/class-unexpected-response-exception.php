<?php

namespace Smart_Send\API\Exceptions;

use Smart_Send\API\Response;

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
class Unexpected_Response_Exception extends HTTP_Client_Exception {

	protected Response $response;

	/**
	 * @param   Response $response The completed 2xx response with the unusable body.
	 * @param   string|null $message Overriding message; defaults to a generic description including the (truncated) body.
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
	 * Generic message including the raw body, truncated so an entire HTML
	 * page is never dumped on the merchant.
	 *
	 * @param   Response $response
	 * @return  string
	 */
	private static function default_message( Response $response ): string {
		if ( $response->raw_body() === '' ) {
			return 'The Smart Send API returned an empty response (HTTP ' . $response->status_code() . '). Please try again later.';
		}

		return 'The Smart Send API returned an unexpected response (HTTP ' . $response->status_code() . '). Please try again later. Response: ' . self::truncate( $response->raw_body() );
	}

	/**
	 * Truncate a raw response body to 500 characters.
	 *
	 * @param   string $body
	 * @return  string
	 */
	private static function truncate( $body ) {
		if ( strlen( $body ) > 500 ) {
			return substr( $body, 0, 500 ) . '...';
		}

		return $body;
	}
}
