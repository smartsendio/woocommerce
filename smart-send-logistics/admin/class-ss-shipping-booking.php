<?php
/**
 * WooCommerce Smart Send booking outcome.
 *
 * @package  SS_Shipping_Booking
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Booking' ) ) :

	/**
	 * Wraps the outcome of a SS_Shipping_Booking_Service::book_outbound()/
	 * book_return() attempt: either the raw API response data (unchanged
	 * pass-through of Smartsend\Client::getData()'s dynamic stdClass shape -
	 * remodelling that shape is out of scope here, see #112) and the
	 * translated v1 wire shipment model, or an error message when the
	 * booking failed (config error or API error).
	 */
	class SS_Shipping_Booking {

		/**
		 * Whether the booking succeeded.
		 *
		 * @var boolean
		 */
		protected bool $successful;

		/**
		 * The error message, set only when the booking failed.
		 *
		 * @var string|null
		 */
		protected ?string $error_message;

		/**
		 * The raw API response data, set only when the booking succeeded.
		 *
		 * @var object|null
		 */
		protected ?object $data;

		/**
		 * The v1 wire shipment model translated from the
		 * SS_Shipping_Shipment representation, or null when no shipment
		 * could be built (e.g. no return method configured).
		 *
		 * @var \Smartsend\Models\Shipment|null
		 */
		protected ?\Smartsend\Models\Shipment $wire_shipment;

		/**
		 * Constructor.
		 *
		 * @param boolean                          $successful    Whether the booking succeeded.
		 * @param string|null                      $error_message The error message, set only when the booking failed.
		 * @param object|null                      $data          The raw API response data, set only when the booking succeeded.
		 * @param \Smartsend\Models\Shipment|null   $wire_shipment The v1 wire shipment model, if one was built.
		 */
		public function __construct( bool $successful, ?string $error_message, ?object $data, ?\Smartsend\Models\Shipment $wire_shipment ) {
			$this->successful    = $successful;
			$this->error_message = $error_message;
			$this->data          = $data;
			$this->wire_shipment = $wire_shipment;
		}

		/**
		 * Whether the booking succeeded.
		 *
		 * @return boolean
		 */
		public function is_successful(): bool {
			return $this->successful;
		}

		/**
		 * Get the error message, if the booking failed.
		 *
		 * @return string|null
		 */
		public function get_error_message(): ?string {
			return $this->error_message;
		}

		/**
		 * Get the raw API response data, if the booking succeeded.
		 *
		 * Same dynamic stdClass shape Smartsend\Client::getData() returns
		 * today: shipment_id, pdf->base_64_encoded, pdf->link, carrier_name,
		 * parcels[]->tracking_code/tracking_link, woocommerce, etc.
		 *
		 * @return object|null
		 */
		public function get_data(): ?object {
			return $this->data;
		}

		/**
		 * Get the v1 wire shipment model translated from the shipment
		 * representation, if one was built.
		 *
		 * @return \Smartsend\Models\Shipment|null
		 */
		public function get_wire_shipment(): ?\Smartsend\Models\Shipment {
			return $this->wire_shipment;
		}
	}

endif;
