<?php

namespace Smart_Send\API\Exceptions;

/**
 * The Smart Send API rejected the request payload (HTTP 422).
 *
 * This is the only exception whose per-field "errors" from the response
 * body are meaningful; errors() exposes them normalized to
 * array<string, string[]> (field => list of messages).
 */
class Validation_Exception extends Request_Exception {

	/**
	 * The per-field validation errors from the response body, normalized
	 * to field => list of messages.
	 *
	 * @return  array<string, string[]>
	 */
	public function errors(): array {
		$errors = array();

		foreach ( $this->get_response()->errors() as $field => $messages ) {
			if ( is_array( $messages ) ) {
				$normalized = array();
				foreach ( $messages as $message ) {
					$normalized[] = (string) $message;
				}
				$errors[ $field ] = $normalized;
			} else {
				$errors[ $field ] = array( (string) $messages );
			}
		}

		return $errors;
	}
}
