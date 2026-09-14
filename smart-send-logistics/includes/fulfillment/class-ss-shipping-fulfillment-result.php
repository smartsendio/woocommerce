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
	 * auto-generate-return-label setting enabled), every booked
	 * SS_Shipping_Booked_Shipment, and the WooCommerce-side derivations
	 * the workflow produced for each of them (#177): the order note HTML
	 * (get_order_note()) and the rendered outputs. The uploads copy of a
	 * label lives on the shipment's label document (local_path/local_url).
	 *
	 * This is the third argument of smart_send_shipment_booked, so a
	 * listener sees the whole run - both labels when a return label was
	 * auto-generated - next to the shipment the action fires for.
	 *
	 * Deliberately holds NO live WC_Order reference so the result stays
	 * serializable for the Phase 7 async processing (#116).
	 *
	 * Each run entry is a plain array, one per attempted label in run
	 * order: array( 'is_return' => bool, 'error' => string ) for a failed
	 * label, or array( 'is_return' => bool, 'shipment' =>
	 * SS_Shipping_Booked_Shipment, 'order_note' => string, 'outputs_html'
	 * => string ) for a booked one. to_legacy_response_array() derives the
	 * response shape admin/js/ss-shipping-label.js parses from them
	 * (value.success.woocommerce.* / value.error); redesigning the
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
		 * The run entries, in the order the fulfillments ran (outbound
		 * first, then the auto-generated return label).
		 *
		 * @var array[]
		 */
		protected array $entries;

		/**
		 * Constructor.
		 *
		 * @param SS_Shipping_Booking|null $outbound_booking The outbound booking, if one was attempted.
		 * @param SS_Shipping_Booking|null $return_booking   The return booking, if one was attempted.
		 * @param array[]                  $entries          The run entries (see the class docblock).
		 */
		public function __construct( ?SS_Shipping_Booking $outbound_booking, ?SS_Shipping_Booking $return_booking, array $entries ) {
			$this->outbound_booking = $outbound_booking;
			$this->return_booking   = $return_booking;
			$this->entries          = $entries;
		}

		/**
		 * Whether every attempted fulfillment step succeeded.
		 *
		 * @return boolean
		 */
		public function is_successful(): bool {
			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['error'] ) ) {
					return false;
				}
			}

			return array() !== $this->entries;
		}

		/**
		 * Get every error message collected during the run.
		 *
		 * @return string[]
		 */
		public function get_error_messages(): array {
			$messages = array();

			foreach ( $this->entries as $entry ) {
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
		 * Every shipment the run booked AND fully fulfilled, in run order
		 * (outbound first, then the auto-generated return label). A booking
		 * whose WooCommerce-side steps failed (e.g. the uploads copy could
		 * not be saved) is not listed - it is reported as an error instead.
		 *
		 * @return SS_Shipping_Booked_Shipment[]
		 */
		public function shipments(): array {
			$shipments = array();

			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['shipment'] ) ) {
					$shipments[] = $entry['shipment'];
				}
			}

			return $shipments;
		}

		/**
		 * The fulfilled outbound shipment, if any.
		 *
		 * @return SS_Shipping_Booked_Shipment|null
		 */
		public function get_outbound_shipment(): ?SS_Shipping_Booked_Shipment {
			foreach ( $this->shipments() as $shipment ) {
				if ( ! $shipment->is_return() ) {
					return $shipment;
				}
			}

			return null;
		}

		/**
		 * The fulfilled return shipment, if any.
		 *
		 * @return SS_Shipping_Booked_Shipment|null
		 */
		public function get_return_shipment(): ?SS_Shipping_Booked_Shipment {
			foreach ( $this->shipments() as $shipment ) {
				if ( $shipment->is_return() ) {
					return $shipment;
				}
			}

			return null;
		}

		/**
		 * The HTML order note the workflow produced for a fulfilled
		 * shipment (label and document links, codes, tracking numbers),
		 * before the smart_send_shipping_label_comment filter. Null when
		 * the shipment is not part of this run.
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment One of shipments().
		 *
		 * @return string|null
		 */
		public function get_order_note( SS_Shipping_Booked_Shipment $shipment ): ?string {
			$entry = $this->find_entry( $shipment );

			return null === $entry ? null : $entry['order_note'];
		}

		/**
		 * The rendered outputs of a fulfilled shipment as shown on the
		 * order screen: a download link per document and every code. Null
		 * when the shipment is not part of this run.
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment One of shipments().
		 *
		 * @return string|null
		 */
		public function get_outputs_html( SS_Shipping_Booked_Shipment $shipment ): ?string {
			$entry = $this->find_entry( $shipment );

			return null === $entry ? null : $entry['outputs_html'];
		}

		/**
		 * The response entries in the array shape
		 * create_label_for_single_order_maybe_return() historically returned
		 * and admin/js/ss-shipping-label.js parses: one entry per attempted
		 * label, array( 'error' => $message ) or array( 'success' => $data )
		 * where $data carries the shipment's to_array() fields plus the
		 * 'woocommerce' block (label_url, order_note, return, outputs_html).
		 *
		 * @return array[]
		 */
		public function to_legacy_response_array(): array {
			$legacy = array();

			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['error'] ) ) {
					$legacy[] = array( 'error' => $entry['error'] );
					continue;
				}

				/** @var SS_Shipping_Booked_Shipment $shipment */
				$shipment = $entry['shipment'];
				$label    = $shipment->label_document();

				$legacy[] = array(
					'success' => (object) array_merge(
						$shipment->to_array(),
						array(
							'woocommerce' => array(
								'label_url'    => null === $label ? '' : $label->download_url(),
								'order_note'   => $entry['order_note'],
								'return'       => $entry['is_return'],
								'outputs_html' => $entry['outputs_html'],
							),
						)
					),
				);
			}

			return $legacy;
		}

		/**
		 * The run entry of a shipment: matched by identity first (the
		 * instance the action fires with), then by shipment id and
		 * direction (e.g. after a serialization round trip).
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment The shipment to look up.
		 *
		 * @return array|null
		 */
		protected function find_entry( SS_Shipping_Booked_Shipment $shipment ): ?array {
			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['shipment'] ) && $entry['shipment'] === $shipment ) {
					return $entry;
				}
			}

			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['shipment'] )
					&& $entry['shipment']->get_shipment_id() === $shipment->get_shipment_id()
					&& $entry['shipment']->is_return() === $shipment->is_return() ) {
					return $entry;
				}
			}

			return null;
		}
	}

endif;
