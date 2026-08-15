<?php


namespace Smartsend\Models;


/**
 * A plain error value object built by Smartsend\Client from a failed
 * request; consumers read the public properties directly (the fluent
 * accessors were removed as dead code, #141).
 */
class Error
{
    // $links: decoded JSON, observed as an object (Response::errorString()
    // reads $error->links->about) but could be an array depending on what the
    // API sends; PHP 7.4 has no union types to express "object|array|null".
    public $links;
    // $id: the API's error id can be a string or an int; no union types in
    // PHP 7.4 to express that.
    public $id;
    // $code: proven to vary in shape by the defensive is_scalar() guards in
    // SS_Shipping_Logger and SS_Shipping_Frontend before casting it to
    // string, so it is not safely typeable without union types.
    public $code;
    public ?string $message = null;
    // $errors: either the internal array() default or a decoded-JSON value
    // straight from the API (object or array depending on the response
    // shape) - iterated with foreach either way. No union types in PHP 7.4.
    public $errors;
}
