<?php

namespace Smartsend\Resources;

require_once __DIR__ . '/../Client.php';
require_once __DIR__ . '/../Exceptions/UnauthenticatedException.php';
require_once __DIR__ . '/../Exceptions/ForbiddenException.php';
require_once __DIR__ . '/../Exceptions/ValidationException.php';
require_once __DIR__ . '/../Exceptions/ServerException.php';
require_once __DIR__ . '/../Exceptions/UnexpectedResponseException.php';

use Smartsend\Client;
use Smartsend\Exceptions\ForbiddenException;
use Smartsend\Exceptions\RequestException;
use Smartsend\Exceptions\ServerException;
use Smartsend\Exceptions\UnauthenticatedException;
use Smartsend\Exceptions\UnexpectedResponseException;
use Smartsend\Exceptions\ValidationException;
use Smartsend\Response;

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
     * @return  Response The response; data() is the pick-up point object.
     * @throws  \Smartsend\Exceptions\HttpClientException
     */
    public function findByAgentNo($carrier, $country, $agent_no)
    {
        try {
            $response = $this->client->httpGet(
                'agents/carrier/'.$carrier.'/country/'.$country.'/agentno/'.$agent_no,
                array(),
                array(),
                null,
                $this->getTimeout()
            );
        } catch (RequestException $e) {
            throw $this->domainException($e);
        }

        if (!is_object($response->data())) {
            throw new UnexpectedResponseException($response);
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
     * @throws  \Smartsend\Exceptions\HttpClientException
     */
    public function findClosestByAddress($carrier, $country, $postal_code, $city, $street)
    {
        $method = 'agents/closest/carrier/'.$carrier.'/country/'.$country.'/postalcode/'.$postal_code;

        if ($city) {
            $method .= '/city/'.$city;
        }

        $method .= '/street/'.$street;

        try {
            $response = $this->client->httpGet($method, array(), array(), null, $this->getTimeout());
        } catch (RequestException $e) {
            throw $this->domainException($e);
        }

        // An empty list is a valid outcome here: no pick-up points near
        // the address. Anything that is not a list at all is a defect.
        if (!is_array($response->data())) {
            throw new UnexpectedResponseException($response);
        }

        return $response;
    }

    /**
     * Re-throw a RequestException as the domain exception this resource's
     * calls give the status code.
     *
     * @param   RequestException $e
     * @return  RequestException
     */
    private function domainException(RequestException $e): RequestException
    {
        $status_code = (int) $e->getResponse()->statusCode();

        if ($status_code === 401) {
            return new UnauthenticatedException($e->getResponse(), $e->getMessage());
        }
        if ($status_code === 403) {
            return new ForbiddenException($e->getResponse(), $e->getMessage());
        }
        if ($status_code === 422) {
            return new ValidationException($e->getResponse(), $e->getMessage());
        }
        if ($status_code >= 500) {
            return new ServerException($e->getResponse(), $e->getMessage());
        }

        return $e;
    }
}
