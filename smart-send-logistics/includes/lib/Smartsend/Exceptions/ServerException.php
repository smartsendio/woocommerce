<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/RequestException.php';

/**
 * The Smart Send API failed with a server-side error (HTTP 5xx).
 */
class ServerException extends RequestException
{
}
