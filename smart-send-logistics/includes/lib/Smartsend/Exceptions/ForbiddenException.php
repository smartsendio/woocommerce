<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/RequestException.php';

/**
 * The Smart Send API rejected the request as unauthorized (HTTP 403):
 * the token is valid but the account does not have access to the
 * requested capability.
 */
class ForbiddenException extends RequestException
{
}
