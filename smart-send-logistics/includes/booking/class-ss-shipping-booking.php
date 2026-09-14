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
	 * book_return() attempt: either the typed SS_Shipping_Booked_Shipment
	 * the API produced, or an error message when the booking failed
	 * (config error or API error).
	 *
	 * Nothing API-version shaped is exposed (#177): the raw response and
	 * the v1 wire request model stay inside SS_Shipping_Booking_Service
	 * and the Smartsend lib. The one v1 bridge is get_document_content():
	 * API v1 delivers the label PDF inline (base64) next to its URL, and
	 * the fulfillment workflow needs those bytes to store the uploads
	 * copy. The bytes are keyed by document index, kept off the DTO
	 * (never persisted or queued) and gone once API v2 delivers URLs only.
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
		 * The booked shipment, set only when the booking succeeded.
		 *
		 * @var SS_Shipping_Booked_Shipment|null
		 */
		protected ?SS_Shipping_Booked_Shipment $shipment;

		/**
		 * Inline document contents the API delivered next to the document
		 * URLs, base64-encoded, keyed by the document's index in
		 * $shipment->documents().
		 *
		 * @var string[]
		 */
		protected array $document_contents;

		/**
		 * Constructor.
		 *
		 * @param boolean                          $successful        Whether the booking succeeded.
		 * @param string|null                      $error_message     The error message, set only when the booking failed.
		 * @param SS_Shipping_Booked_Shipment|null $shipment          The booked shipment, set only when the booking succeeded.
		 * @param string[]                         $document_contents Inline base64 document contents keyed by document index.
		 */
		public function __construct( bool $successful, ?string $error_message, ?SS_Shipping_Booked_Shipment $shipment, array $document_contents = array() ) {
			$this->successful        = $successful;
			$this->error_message     = $error_message;
			$this->shipment          = $shipment;
			$this->document_contents = $document_contents;
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
		 * Get the booked shipment, if the booking succeeded.
		 *
		 * @return SS_Shipping_Booked_Shipment|null
		 */
		public function shipment(): ?SS_Shipping_Booked_Shipment {
			return $this->shipment;
		}

		/**
		 * Get the inline base64 content the API delivered for a document,
		 * if any (API v1 sends the label PDF inline; see the class docblock).
		 *
		 * @param int $document_index The document's index in shipment()->documents().
		 *
		 * @return string|null
		 */
		public function get_document_content( int $document_index ): ?string {
			return isset( $this->document_contents[ $document_index ] ) ? $this->document_contents[ $document_index ] : null;
		}
	}

endif;
