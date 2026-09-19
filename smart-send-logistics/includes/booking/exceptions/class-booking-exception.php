<?php
/**
 * WooCommerce Smart Send booking exception.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Booking\Exceptions;

use Smart_Send\Booking\Booking_Service;
use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Exceptions\Not_Connected_Exception;
use Smart_Send\Fulfillment\Fulfillment_Result;
use Smart_Send\Fulfillment\Fulfillment_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * A shipment could not be booked (#177).
 *
 * Thrown by Booking_Service::book() for every failure the
 * booking stage produces: the Smart Send API rejected the shipment
 * (the original \Smart_Send\API\Exceptions\HTTP_Client_Exception is the
 * previous exception and, for a 422, the per-field validation errors
 * are on errors()), or the shipment could not be built at all (no
 * shipping method on the delivery details). It is also what
 * Method_Resolver::resolve_return() throws for a missing
 * return method - the one deliberate delivery -> booking reference.
 *
 * Fulfillment_Service catches it and records the outcome
 * on the Fulfillment_Result; the HTML rendering for the
 * merchant happens there, not here. The message is plain text.
 *
 * Not_Connected_Exception (no API token configured) is
 * deliberately separate: that is a plugin configuration state, not a
 * booking outcome.
 */
class Booking_Exception extends \Exception {

	/**
	 * Per-field validation errors from the API, field => list of
	 * messages (e.g. 'receiver.postal_code' => array( 'The postal code
	 * is invalid.' )). Empty for every non-validation failure.
	 *
	 * @var array<string, string[]>
	 */
	protected array $errors;

	/**
	 * Constructor.
	 *
	 * @param string          $message  The failure message (plain text).
	 * @param array           $errors   Per-field validation errors, field => list of messages.
	 * @param \Throwable|null $previous The original API exception, when there is one.
	 */
	public function __construct( string $message, array $errors = array(), ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->errors = $errors;
	}

	/**
	 * Wrap an API client failure: the client's message, the validation
	 * errors when the API rejected the shipment as invalid, and the
	 * original exception as previous.
	 *
	 * @param \Smart_Send\API\Exceptions\HTTP_Client_Exception $e The API failure.
	 *
	 * @return self
	 */
	public static function from_api_exception( \Smart_Send\API\Exceptions\HTTP_Client_Exception $e ): self {
		$errors = $e instanceof \Smart_Send\API\Exceptions\Validation_Exception ? $e->errors() : array();

		return new self( $e->getMessage(), $errors, $e );
	}

	/**
	 * The per-field validation errors, field => list of messages.
	 * Empty when the failure was not a validation error.
	 *
	 * @return array<string, string[]>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * The Smart Send API's Response-ID of the failed request, for
	 * support reference, when the failure came from an API response.
	 *
	 * @return string|null
	 */
	public function response_id(): ?string {
		$previous = $this->getPrevious();

		if ( $previous instanceof \Smart_Send\API\Exceptions\Request_Exception ) {
			return $previous->get_response()->response_id();
		}

		return null;
	}
}
