<?php

namespace Smartsend\Resources;

require_once __DIR__ . '/../Client.php';

use Smartsend\Client;

/**
 * Looks up carrier pick-up points ("agents").
 */
class PickupPointResource
{
    const AGENT_TIMEOUT = 4;

    /** @var Client */
    protected $client;

    public function __construct(Client $client)
    {
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
    protected function getTimeout()
    {
        /*
         * Filter the timeout used when searching for pick-up points.
         *
         * @param int $timeout Timeout in seconds.
         */
        return apply_filters('smart_send_pickup_point_timeout', self::AGENT_TIMEOUT);
    }

    /**
     * Look up a single pick-up point by its agent number.
     *
     * @param   string $carrier  Carrier code (e.g. 'postnord').
     * @param   string $country  ISO3166-A2 country code.
     * @param   string $agent_no The pick-up point's agent number.
     * @return  \Smartsend\Response
     */
    public function findByAgentNo($carrier, $country, $agent_no)
    {
        return $this->client->httpGet(
            'agents/carrier/'.$carrier.'/country/'.$country.'/agentno/'.$agent_no,
            array(),
            array(),
            null,
            $this->getTimeout()
        );
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
     * @return  \Smartsend\Response
     */
    public function findClosestByAddress($carrier, $country, $postal_code, $city, $street)
    {
        $method = 'agents/closest/carrier/'.$carrier.'/country/'.$country.'/postalcode/'.$postal_code;

        if ($city) {
            $method .= '/city/'.$city;
        }

        $method .= '/street/'.$street;

        return $this->client->httpGet($method, array(), array(), null, $this->getTimeout());
    }
}
