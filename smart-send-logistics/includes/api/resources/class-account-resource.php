<?php

namespace Smart_Send\API\Resources;

use Smart_Send\API\Client;
use Smart_Send\API\Exceptions\Forbidden_Exception;
use Smart_Send\API\Exceptions\Request_Exception;
use Smart_Send\API\Exceptions\Server_Exception;
use Smart_Send\API\Exceptions\Unauthenticated_Exception;
use Smart_Send\API\Exceptions\Unexpected_Response_Exception;
use Smart_Send\API\Exceptions\Validation_Exception;
use Smart_Send\API\Response;

/**
 * Account/connection calls: everything about the Smart Send account the
 * API token belongs to.
 */
class Account_Resource {

	/** @var Client */
	protected $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Confirm the API token is valid and fetch the connected account.
	 *
	 * @return  Response The response; data() is the account object (email, website, ...).
	 * @throws  \Smart_Send\API\Exceptions\HTTP_Client_Exception
	 */
	public function get_authenticated_user(): Response {
		try {
			$response = $this->client->http_get( '' );
		} catch ( Request_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw $this->domain_exception( $e );
		}

		if ( ! is_object( $response->data() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw new Unexpected_Response_Exception( $response );
		}

		return $response;
	}

	/**
	 * Re-throw a Request_Exception as the domain exception this resource's
	 * calls give the status code.
	 *
	 * @param   Request_Exception $e
	 * @return  Request_Exception
	 */
	private function domain_exception( Request_Exception $e ): Request_Exception {
		$status_code = (int) $e->get_response()->status_code();

		if ( 401 === $status_code ) {
			return new Unauthenticated_Exception( $e->get_response(), $e->getMessage() );
		}
		if ( 403 === $status_code ) {
			return new Forbidden_Exception( $e->get_response(), $e->getMessage() );
		}
		if ( 422 === $status_code ) {
			return new Validation_Exception( $e->get_response(), $e->getMessage() );
		}
		if ( $status_code >= 500 ) {
			return new Server_Exception( $e->get_response(), $e->getMessage() );
		}

		return $e;
	}
}
