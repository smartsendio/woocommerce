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
 * Looks up carrier pick-up points ("agents").
 */
class Pickup_Point_Resource {

	const AGENT_TIMEOUT = 4;

	/** @var Client */
	protected $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Get the timeout used when looking up pick-up points.
	 *
	 * If no results are returned within the timespan, then the request
	 * times out. This prevents customers from waiting at checkout until
	 * the PHP script times out.
	 *
	 * @return  float Timeout in seconds.
	 */
	protected function get_timeout() {
		/*
		 * Filter the timeout used when searching for pick-up points.
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		return apply_filters( 'smart_send_pickup_point_timeout', self::AGENT_TIMEOUT );
	}

	/**
	 * Look up a single pick-up point by its agent number.
	 *
	 * @param   string $carrier  Carrier code (e.g. 'postnord').
	 * @param   string $country  ISO3166-A2 country code.
	 * @param   string $agent_no The pick-up point's agent number.
	 * @return  Response The response; data() is the pick-up point object.
	 * @throws  \Smart_Send\API\Exceptions\HTTP_Client_Exception
	 */
	public function find_by_agent_no( $carrier, $country, $agent_no ) {
		try {
			$response = $this->client->http_get(
				'agents/carrier/' . $carrier . '/country/' . $country . '/agentno/' . $agent_no,
				array(),
				array(),
				null,
				$this->get_timeout()
			);
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
	 * Find the pick-up points closest to an address (not necessarily an
	 * exact match).
	 *
	 * @param   string      $carrier     Carrier code (e.g. 'postnord').
	 * @param   string      $country     ISO3166-A2 country code.
	 * @param   string      $postal_code Postal code to search near.
	 * @param   string|null $city        City to search near.
	 * @param   string      $street      Street address to search near.
	 * @return  Response The response; data() is the (possibly empty) list of pick-up points.
	 * @throws  \Smart_Send\API\Exceptions\HTTP_Client_Exception
	 */
	public function find_closest_by_address( $carrier, $country, $postal_code, $city, $street ) {
		$method = 'agents/closest/carrier/' . $carrier . '/country/' . $country . '/postalcode/' . $postal_code;

		if ( $city ) {
			$method .= '/city/' . $city;
		}

		$method .= '/street/' . $street;

		try {
			$response = $this->client->http_get( $method, array(), array(), null, $this->get_timeout() );
		} catch ( Request_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw $this->domain_exception( $e );
		}

		// An empty list is a valid outcome here: no pick-up points near
		// the address. Anything that is not a list at all is a defect.
		if ( ! is_array( $response->data() ) ) {
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
