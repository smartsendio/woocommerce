<?php

namespace Smartsend;

require_once 'Client.php';
require_once 'Resources/BookingResource.php';
require_once 'Resources/PickupPointResource.php';

use Smartsend\Resources\BookingResource;
use Smartsend\Resources\PickupPointResource;

/**
 * The Smart Send API client: the transport (inherited from Client) plus
 * accessors for the API's resource groups (#112) - each resource is
 * responsible for one API concern (booking/labels, pick-up point lookup)
 * and is the single point where its v1 wire payload is built. Response
 * state (isSuccessful()/getData()/getError()/...) stays on this instance
 * (inherited from Client), since every resource call goes through it.
 */
class Api extends Client
{
    /** @var BookingResource|null */
    private $bookings;

    /** @var PickupPointResource|null */
    private $pickup_points;

    /**
     * Confirm the API token is valid and fetch the connected account.
     *
     * @return  object|true|false
     */
    public function getAuthenticatedUser()
    {
        return $this->httpGet('');
    }

    /**
     * Shipment/label booking calls.
     *
     * @return  BookingResource
     */
    public function bookings(): BookingResource
    {
        if (!$this->bookings) {
            $this->bookings = new BookingResource($this);
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
            $this->pickup_points = new PickupPointResource($this);
        }

        return $this->pickup_points;
    }
}
