<?php

namespace Smartsend;

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Resources/AccountResource.php';
require_once __DIR__ . '/Resources/BookingResource.php';
require_once __DIR__ . '/Resources/PickupPointResource.php';

use Smartsend\Resources\AccountResource;
use Smartsend\Resources\BookingResource;
use Smartsend\Resources\PickupPointResource;

/**
 * The Smart Send API entry point: a plain resource registry over a
 * composed Client transport (#141) - each resource is responsible for
 * one API concern (account/connection, booking/labels, pick-up point
 * lookup) and is the single point where its v1 wire payload is built.
 * Every resource call returns an immutable Response value object on
 * success and throws a Smartsend\Exceptions\HttpClientException on any
 * failure (transport, non-2xx, unusable body); no response state lives
 * on this instance.
 */
class Api
{
    /** @var Client */
    private $client;

    /** @var AccountResource|null */
    private $account;

    /** @var BookingResource|null */
    private $bookings;

    /** @var PickupPointResource|null */
    private $pickup_points;

    public function __construct($api_token, $website, $demo=false)
    {
        $this->client = new Client($api_token, $website, $demo);
    }

    /**
     * Inject a callable that is invoked after every HTTP request with the
     * request/response data. See Client::setRequestLogger().
     *
     * @param   callable|null $request_logger
     * @return  void
     */
    public function setRequestLogger(?callable $request_logger): void
    {
        $this->client->setRequestLogger($request_logger);
    }

    /**
     * Account/connection calls.
     *
     * @return  AccountResource
     */
    public function account(): AccountResource
    {
        if (!$this->account) {
            $this->account = new AccountResource($this->client);
        }

        return $this->account;
    }

    /**
     * Shipment/label booking calls.
     *
     * @return  BookingResource
     */
    public function bookings(): BookingResource
    {
        if (!$this->bookings) {
            $this->bookings = new BookingResource($this->client);
        }

        return $this->bookings;
    }

    /**
     * Pick-up point lookup calls.
     *
     * @return  PickupPointResource
     */
    public function pickupPoints(): PickupPointResource
    {
        if (!$this->pickup_points) {
            $this->pickup_points = new PickupPointResource($this->client);
        }

        return $this->pickup_points;
    }
}
