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
 * Account/connection calls: everything about the Smart Send account the
 * API token belongs to.
 */
class AccountResource
{
    /** @var Client */
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Confirm the API token is valid and fetch the connected account.
     *
     * @return  Response The response; data() is the account object (email, website, ...).
     * @throws  \Smartsend\Exceptions\HttpClientException
     */
    public function getAuthenticatedUser(): Response
    {
        try {
            $response = $this->client->httpGet('');
        } catch (RequestException $e) {
            throw $this->domainException($e);
        }

        if (!is_object($response->data())) {
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
