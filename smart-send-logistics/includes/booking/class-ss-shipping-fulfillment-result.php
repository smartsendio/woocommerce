<?php
/**
 * WooCommerce Smart Send fulfillment outcome.
 *
 * @package  SS_Shipping_Fulfillment_Result
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Fulfillment_Result' ) ) :

	/**
	 * Wraps the outcome of a SS_Shipping_Fulfillment_Service::fulfill_outbound()/
	 * fulfill_return() run: the outbound booking (when one was attempted), the
	 * auto-generated return booking (when the shipping method has the
	 * auto-generate-return-label setting enabled), and the legacy response
	 * entries the pre-existing consumers are built around.
	 *
	 * Deliberately holds NO live WC_Order reference so the result stays
	 * serializable for the Phase 7 async processing (#116).
	 *
	 * The legacy response array is byte-compatible with what
	 * SS_Shipping_Label_Creator::create_label_for_single_order_maybe_return()
	 * used to return: a list of entries, each either
	 * array( 'success' => $response, 'shipment' => $wire_shipment ) or
	 * array( 'error' => $message ). admin/js/ss-shipping-label.js parses this
	 * shape (value.success.woocommerce / value.error); redesigning the
	 * request/response boundary is deferred to Phase 7 (#116).
	 */
	class SS_Shipping_Fulfillment_Result {

		/**
		 * The outbound booking, or null when no outbound label was attempted
		 * (return-only fulfillment, or the order could not be loaded).
		 *
		 * @var SS_Shipping_Booking|null
		 */
		protected ?SS_Shipping_Booking $outbound_booking;

		/**
		 * The return booking, or null when no return label was attempted.
		 *
		 * @var SS_Shipping_Booking|null
		 */
		protected ?SS_Shipping_Booking $return_booking;

		/**
		 * The legacy-shaped response entries, in the order the fulfillments
		 * ran (outbound first, then the auto-generated return label).
		 *
		 * @var array[]
		 */
		protected array $legacy_response_array;

		/**
		 * Constructor.
		 *
		 * @param SS_Shipping_Booking|null $outbound_booking      The outbound booking, if one was attempted.
		 * @param SS_Shipping_Booking|null $return_booking        The return booking, if one was attempted.
		 * @param array[]                  $legacy_response_array The legacy-shaped response entries.
		 */
		public function __construct( ?SS_Shipping_Booking $outbound_booking, ?SS_Shipping_Booking $return_booking, array $legacy_response_array ) {
			$this->outbound_booking      = $outbound_booking;
			$this->return_booking        = $return_booking;
			$this->legacy_response_array = $legacy_response_array;
		}

		/**
		 * Whether every attempted fulfillment step succeeded.
		 *
		 * @return boolean
		 */
		public function is_successful(): bool {
			foreach ( $this->legacy_response_array as $entry ) {
				if ( isset( $entry['error'] ) ) {
					return false;
				}
			}

			return array() !== $this->legacy_response_array;
		}

		/**
		 * Get every error message collected during the run.
		 *
		 * @return string[]
		 */
		public function get_error_messages(): array {
			$messages = array();

			foreach ( $this->legacy_response_array as $entry ) {
				if ( isset( $entry['error'] ) ) {
					$messages[] = $entry['error'];
				}
			}

			return $messages;
		}

		/**
		 * Get the first error message, or null when the run succeeded.
		 *
		 * @return string|null
		 */
		public function get_first_error_message(): ?string {
			$messages = $this->get_error_messages();

			return array() === $messages ? null : $messages[0];
		}

		/**
		 * Get the outbound booking, if one was attempted.
		 *
		 * @return SS_Shipping_Booking|null
		 */
		public function get_outbound_booking(): ?SS_Shipping_Booking {
			return $this->outbound_booking;
		}

		/**
		 * Get the return booking, if one was attempted.
		 *
		 * @return SS_Shipping_Booking|null
		 */
		public function get_return_booking(): ?SS_Shipping_Booking {
			return $this->return_booking;
		}

		/**
		 * The response entries in the exact array shape
		 * create_label_for_single_order_maybe_return() historically returned
		 * (and admin/js/ss-shipping-label.js still parses).
		 *
		 * @return array[]
		 */
		public function to_legacy_response_array(): array {
			return $this->legacy_response_array;
		}
	}

endif;
