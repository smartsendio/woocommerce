<?php
/**
 * WooCommerce Smart Send booking exception.
 *
 * @package  SS_Shipping_Booking_Exception
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Booking_Exception' ) ) :

	/**
	 * A shipment could not be booked (#177).
	 *
	 * Thrown by SS_Shipping_Booking_Service::book() for every failure the
	 * booking stage produces: the Smart Send API rejected the shipment
	 * (the original \Smartsend\Exceptions\HttpClientException is the
	 * previous exception and, for a 422, the per-field validation errors
	 * are on errors()), or the shipment could not be built at all (no
	 * shipping method on the delivery details). It is also what
	 * SS_Shipping_Method_Resolver::resolve_return() throws for a missing
	 * return method - the one deliberate delivery -> booking reference.
	 *
	 * SS_Shipping_Fulfillment_Service catches it and records the outcome
	 * on the SS_Shipping_Fulfillment_Result; the HTML rendering for the
	 * merchant happens there, not here. The message is plain text.
	 *
	 * SS_Shipping_Not_Connected_Exception (no API token configured) is
	 * deliberately separate: that is a plugin configuration state, not a
	 * booking outcome.
	 */
	class SS_Shipping_Booking_Exception extends \Exception {

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
		 * @param \Smartsend\Exceptions\HttpClientException $e The API failure.
		 *
		 * @return self
		 */
		public static function from_api_exception( \Smartsend\Exceptions\HttpClientException $e ): self {
			$errors = $e instanceof \Smartsend\Exceptions\ValidationException ? $e->errors() : array();

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

			if ( $previous instanceof \Smartsend\Exceptions\RequestException ) {
				return $previous->getResponse()->responseId();
			}

			return null;
		}
	}

endif;
