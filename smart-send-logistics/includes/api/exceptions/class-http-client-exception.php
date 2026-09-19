<?php

namespace Smart_Send\API\Exceptions;

/**
 * Base class for every exception thrown by the Smart Send API layer.
 *
 * The hierarchy separates the two things that can go wrong from the
 * caller's point of view:
 *
 * - Connection_Exception: the HTTP exchange never completed (DNS, TLS,
 *   timeout, ...) - there is no Response.
 * - Request_Exception: the exchange completed but the API answered with a
 *   non-2xx status - the full Response is attached. The resource classes
 *   re-throw these as domain-specific subclasses (Unauthenticated_Exception,
 *   Forbidden_Exception, Validation_Exception, Server_Exception) when the
 *   status code has a context-specific meaning for that call.
 * - Unexpected_Response_Exception: a 2xx response whose body does not match
 *   what the calling resource method expects.
 *
 * `catch (HTTP_Client_Exception $e)` therefore covers every failure mode of
 * an API call.
 *
 * This class deliberately stays WordPress-light and must never reference
 * a booking or fulfillment domain type.
 */
abstract class HTTP_Client_Exception extends \Exception {

}
