<?php

namespace Smartsend\Resources;

require_once __DIR__ . '/../Client.php';

use Smartsend\Client;
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
     * @return  Response
     */
    public function getAuthenticatedUser(): Response
    {
        return $this->client->httpGet('');
    }
}
