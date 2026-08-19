<?php

namespace Smartsend\Exceptions;

/**
 * Base class for every exception thrown by the Smartsend API library.
 *
 * The hierarchy separates the two things that can go wrong from the
 * caller's point of view:
 *
 * - ConnectionException: the HTTP exchange never completed (DNS, TLS,
 *   timeout, ...) - there is no Response.
 * - RequestException: the exchange completed but the API answered with a
 *   non-2xx status - the full Response is attached. The resource classes
 *   re-throw these as domain-specific subclasses (UnauthenticatedException,
 *   ForbiddenException, ValidationException, ServerException) when the
 *   status code has a context-specific meaning for that call.
 * - UnexpectedResponseException: a 2xx response whose body does not match
 *   what the calling resource method expects.
 *
 * `catch (HttpClientException $e)` therefore covers every failure mode of
 * an API call.
 *
 * This class deliberately stays WordPress-light and must never reference
 * an SS_Shipping_* type.
 */
abstract class HttpClientException extends \Exception
{
}
