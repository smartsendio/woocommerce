<?php

namespace Smartsend;

require_once __DIR__ . '/Models/Error.php';

use Smartsend\Models\Error;

/**
 * The immutable outcome of a single HTTP request made by Client (#141).
 *
 * Every Client http* call returns a fresh instance; no response state
 * persists on the client between calls, so concurrent or interleaved API
 * calls (a smart_send_* action listener making its own request, async
 * processing of several orders on one Api instance) can never corrupt
 * each other's pending result.
 *
 * This class deliberately stays WordPress-light and must never reference
 * an SS_Shipping_* type.
 */
final class Response
{
    private bool $success;

    /** @var mixed Decoded response data (the API envelope's "data" member), if any. */
    private $data;

    /** @var mixed Decoded response links (the API envelope's "links" member), if any. */
    private $links;

    private ?Error $error;

    /** @var int|string|null Untyped: wp_remote_retrieve_response_code() returns '' on transport failure. */
    private $status_code;

    private ?float $started_at;

    private ?float $completed_at;

    /**
     * @param   bool $success Whether the request succeeded.
     * @param   mixed $data Decoded response data, if any.
     * @param   mixed $links Decoded response links, if any.
     * @param   Error|null $error The error describing the failure, if any.
     * @param   int|string|null $status_code HTTP status code of the response ('' on transport failure).
     * @param   float|null $started_at Timestamp when the request started.
     * @param   float|null $completed_at Timestamp when the request finished.
     */
    public function __construct(bool $success, $data, $links, ?Error $error, $status_code, ?float $started_at, ?float $completed_at)
    {
        $this->success = $success;
        $this->data = $data;
        $this->links = $links;
        $this->error = $error;
        $this->status_code = $status_code;
        $this->started_at = $started_at;
        $this->completed_at = $completed_at;
    }

    /**
     * Whether the request succeeded.
     *
     * @return  bool
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * The decoded response data (the API envelope's "data" member), if any.
     *
     * @return  mixed
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * The decoded response links (the API envelope's "links" member), if any.
     *
     * @return  mixed
     */
    public function links()
    {
        return $this->links;
    }

    /**
     * The error describing the failure, if any.
     *
     * @return  Error|null
     */
    public function error(): ?Error
    {
        return $this->error;
    }

    /**
     * Render the error as a human-readable string: the message, a
     * 'Read more here' link, the unique error id and each field error,
     * joined by $delimiter.
     *
     * @param   string $delimiter Separator between the error's parts.
     * @return  string|null Null when the response carries no error.
     */
    public function errorString(string $delimiter = '<br>'): ?string
    {
        $error = $this->error;

        if ($error === null) {
            return null;
        }

        // Print error message
        $error_string = $error->message;
        // Print 'Read more here' link to error explenation
        if (isset($error->links->about)) {
            $error_string .= $delimiter."- <a href='".$error->links->about."' target='_blank'>Read more here</a>";
        }

        // Print unique error ID if one exists
        if (isset($error->id)) {
            $error_string .= $delimiter."Unique ID: ".$error->id;
        }

        // Print each error
        if (isset($error->errors)) {
            foreach ($error->errors as $error_field => $error_details) {
                if (is_array($error_details)) {
                    if (count($error_details) > 1) {
                        $error_string .= $delimiter . $error_field .':';
                        foreach ($error_details as $error_description) {
                            $error_string .= $delimiter . "- ". $error_description;
                        }
                    } else {
                        foreach ($error_details as $error_description) {
                            $error_string .= $delimiter . "- " . $error_field . ': ' . $error_description;
                        }
                    }
                } else {
                    $error_string .= $delimiter . "- " . $error_field . ': ' . $error_details;
                }
            }
        }

        return $error_string;
    }

    /**
     * HTTP status code of the response ('' on transport failure).
     *
     * @return  int|string|null
     */
    public function statusCode()
    {
        return $this->status_code;
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
