<?php

namespace Smartsend;

/**
 * The immutable outcome of a single completed HTTP exchange with the
 * Smart Send API (#141).
 *
 * The Client returns an instance on 2xx and attaches one to the
 * RequestException it throws on any other status - so both success and
 * failure carry the same value object. No response state persists on the
 * client between calls, so concurrent or interleaved API calls (a
 * smart_send_* action listener making its own request, async processing
 * of several orders on one Api instance) can never corrupt each other's
 * pending result.
 *
 * Only the body's "data", "message" and "errors" members are read; the
 * response id comes from the Response-ID HTTP header.
 *
 * This class deliberately stays WordPress-light and must never reference
 * an SS_Shipping_* type.
 */
final class Response
{
    /** @var mixed Decoded response data (the API envelope's "data" member), if any. */
    private $data;

    private ?string $message;

    /** @var array The body's "errors" member (field => details), empty when absent. */
    private array $errors;

    private string $raw_body;

    /** @var int|string|null Untyped: wp_remote_retrieve_response_code() can return '' for unusual responses. */
    private $status_code;

    private ?string $response_id;

    private ?float $started_at;

    private ?float $completed_at;

    /**
     * @param   mixed $data Decoded response data (the body's "data" member), if any.
     * @param   string|null $message The body's "message" member, if any.
     * @param   array $errors The body's "errors" member (field => details), empty when absent.
     * @param   string $raw_body Raw (undecoded) response body.
     * @param   int|string|null $status_code HTTP status code of the response.
     * @param   string|null $response_id The Response-ID header, if the API sent one.
     * @param   float|null $started_at Timestamp when the request started.
     * @param   float|null $completed_at Timestamp when the request finished.
     */
    public function __construct($data, ?string $message, array $errors, string $raw_body, $status_code, ?string $response_id, ?float $started_at, ?float $completed_at)
    {
        $this->data = $data;
        $this->message = $message;
        $this->errors = $errors;
        $this->raw_body = $raw_body;
        $this->status_code = $status_code;
        $this->response_id = $response_id;
        $this->started_at = $started_at;
        $this->completed_at = $completed_at;
    }

    /**
     * The decoded response data (the body's "data" member), if any.
     *
     * @return  mixed
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * The body's "message" member, if any.
     *
     * @return  string|null
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * The body's "errors" member (field => details), empty when absent.
     *
     * Only meaningful on validation-failure (HTTP 422) responses - see
     * Exceptions\ValidationException::errors() for the normalized view.
     *
     * @return  array
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The raw (undecoded) response body, for endpoint-specific format
     * checks in the resource layer.
     *
     * @return  string
     */
    public function rawBody(): string
    {
        return $this->raw_body;
    }

    /**
     * HTTP status code of the response.
     *
     * @return  int|string|null
     */
    public function statusCode()
    {
        return $this->status_code;
    }

    /**
     * The unique id of this API response (the Response-ID header), if the
     * API sent one. Useful in support tickets to trace a specific call.
     *
     * @return  string|null
     */
    public function responseId(): ?string
    {
        return $this->response_id;
    }

    /**
     * Timestamp when the request started.
     *
     * @return  float|null
     */
    public function startedAt(): ?float
    {
        return $this->started_at;
    }

    /**
     * Timestamp when the request finished.
     *
     * @return  float|null
     */
    public function completedAt(): ?float
    {
        return $this->completed_at;
    }
}
