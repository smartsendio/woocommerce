<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/RequestException.php';

/**
 * The Smart Send API rejected the request payload (HTTP 422).
 *
 * This is the only exception whose per-field "errors" from the response
 * body are meaningful; errors() exposes them normalized to
 * array<string, string[]> (field => list of messages).
 */
class ValidationException extends RequestException
{
    /**
     * The per-field validation errors from the response body, normalized
     * to field => list of messages.
     *
     * @return  array<string, string[]>
     */
    public function errors(): array
    {
        $errors = array();

        foreach ($this->getResponse()->errors() as $field => $messages) {
            if (is_array($messages)) {
                $normalized = array();
                foreach ($messages as $message) {
                    $normalized[] = (string) $message;
                }
                $errors[$field] = $normalized;
            } else {
                $errors[$field] = array((string) $messages);
            }
        }

        return $errors;
    }
}
