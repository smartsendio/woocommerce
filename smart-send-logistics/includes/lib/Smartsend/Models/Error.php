<?php


namespace Smartsend\Models;


class Error
{
    // $links: decoded JSON, observed as an object (getErrorString() reads
    // $error->links->about) but could be an array depending on what the API
    // sends; PHP 7.4 has no union types to express "object|array|null".
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

    /**
     * @return mixed
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * @param mixed $links
     * @return self
     */
    public function setLinks($links): self
    {
        $this->links = $links;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return self
     */
    public function setId($id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @param mixed $code
     * @return self
     */
    public function setCode($code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @param string|null $message
     * @return self
     */
    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param mixed $errors
     * @return self
     */
    public function setErrors($errors): self
    {
        $this->errors = $errors;
        return $this;
    }

}