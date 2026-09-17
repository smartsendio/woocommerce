<?php

namespace Smart_Send\API\Exceptions;

/**
 * The Smart Send API rejected the request as unauthenticated (HTTP 401):
 * the API token is missing, wrong or revoked.
 */
class Unauthenticated_Exception extends Request_Exception {

}
