<?php

namespace Smart_Send\API;

use Smart_Send\API\Resources\Account_Resource;
use Smart_Send\API\Resources\Booking_Resource;
use Smart_Send\API\Resources\Pickup_Point_Resource;

/**
 * The Smart Send API entry point: a plain resource registry over a
 * composed Client transport (#141) - each resource is responsible for
 * one API concern (account/connection, booking/labels, pick-up point
 * lookup) and is the single point where its v1 wire payload is built.
 * Every resource call returns an immutable Response value object on
 * success and throws a Smart_Send\API\Exceptions\HTTP_Client_Exception on any
 * failure (transport, non-2xx, unusable body); no response state lives
 * on this instance.
 */
class API {

	/** @var Client */
	private $client;

	/** @var Account_Resource|null */
	private $account;

	/** @var Booking_Resource|null */
	private $bookings;

	/** @var Pickup_Point_Resource|null */
	private $pickup_points;

	/**
	 * @param   string|null $api_token
	 * @param   string|null $website
	 * @param   string|null $api_host The host to talk to (no API version path); null for the production host. See Client::set_api_host().
	 */
	public function __construct( $api_token, $website, ?string $api_host = null ) {
		$this->client = new Client( $api_token, $website, $api_host );
	}

	/**
	 * Inject a callable that is invoked after every HTTP request with the
	 * request/response data. See Client::set_request_logger().
	 *
	 * @param   callable|null $request_logger
	 * @return  void
	 */
	public function set_request_logger( ?callable $request_logger ): void {
		$this->client->set_request_logger( $request_logger );
	}

	/**
	 * Account/connection calls.
	 *
	 * @return  Account_Resource
	 */
	public function account(): Account_Resource {
		if ( ! $this->account ) {
			$this->account = new Account_Resource( $this->client );
		}

		return $this->account;
	}

	/**
	 * Shipment/label booking calls.
	 *
	 * @return  Booking_Resource
	 */
	public function bookings(): Booking_Resource {
		if ( ! $this->bookings ) {
			$this->bookings = new Booking_Resource( $this->client );
		}

		return $this->bookings;
	}

	/**
	 * Pick-up point lookup calls.
	 *
	 * @return  Pickup_Point_Resource
	 */
	public function pickup_points(): Pickup_Point_Resource {
		if ( ! $this->pickup_points ) {
			$this->pickup_points = new Pickup_Point_Resource( $this->client );
		}

		return $this->pickup_points;
	}
}
