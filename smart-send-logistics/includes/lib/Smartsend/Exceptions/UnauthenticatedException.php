<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/RequestException.php';

/**
 * The Smart Send API rejected the request as unauthenticated (HTTP 401):
 * the API token is missing, wrong or revoked.
 */
class UnauthenticatedException extends RequestException
{
}
