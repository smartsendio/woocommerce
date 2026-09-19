<?php

namespace Smart_Send\API\Exceptions;

/**
 * The Smart Send API rejected the request as unauthorized (HTTP 403):
 * the token is valid but the account does not have access to the
 * requested capability.
 */
class Forbidden_Exception extends Request_Exception {

}
