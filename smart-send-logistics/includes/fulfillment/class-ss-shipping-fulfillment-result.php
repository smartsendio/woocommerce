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
	 * The outcome of a SS_Shipping_Fulfillment_Service::fulfill_outbound()/
	 * fulfill_return() run (#177): per attempted shipment (outbound first,
	 * then the auto-generated return label) either the booked
	 * SS_Shipping_Booked_Shipment with the WooCommerce-side outcomes the
	 * workflow produced for it - the order note HTML (get_order_note()),
	 * the rendered outputs (get_outputs_html()) and which side-effect steps
	 * ran (get_steps()) - or the failure: the error shown to the merchant
	 * and, for an API validation failure, the per-field errors. The
	 * uploads copy of a label lives on the shipment's label document
	 * (local_path/local_url).
	 *
	 * This is the second argument of smart_send_order_fulfilled, so a
	 * listener sees the whole run - both labels when a return label was
	 * auto-generated, and a failed return leg next to a booked outbound.
	 *
	 * Deliberately holds NO live WC_Order reference so the result stays
	 * serializable for the Phase 7 async processing (#116).
	 *
	 * Each run entry is a plain array, one per attempted shipment in run
	 * order: array( 'is_return' => bool, 'error' => string, 'errors' =>
	 * array ) for a failed one, or array( 'is_return' => bool, 'shipment'
	 * => SS_Shipping_Booked_Shipment, 'order_note' => string,
	 * 'outputs_html' => string, 'steps' => array ) for a booked one.
	 * to_legacy_response_array() derives the response shape
	 * admin/js/ss-shipping-label.js parses from them
	 * (value.success.woocommerce.* / value.error); redesigning the
	 * request/response boundary is deferred to Phase 7 (#116).
	 */
	class SS_Shipping_Fulfillment_Result {

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
		 * @param array[] $entries The run entries (see the class docblock).
		 */
		public function __construct( array $entries ) {
			$this->entries = $entries;
		}

		/**
		 * A failed run entry.
		 *
		 * @param boolean $is_return Whether the failed shipment was a return shipment.
		 * @param string  $error     The error message shown to the merchant (HTML).
		 * @param array   $errors    Per-field validation errors from the API, field => list of messages.
		 *
		 * @return array
		 */
		public static function failed_entry( bool $is_return, string $error, array $errors = array() ): array {
			return array(
				'is_return' => $is_return,
				'error'     => $error,
				'errors'    => $errors,
			);
		}

		/**
		 * A fulfilled run entry.
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment     The booked shipment.
		 * @param string                      $order_note   The order note HTML (after the smart_send_fulfillment_order_note filter).
		 * @param string                      $outputs_html The rendered outputs (document links and codes).
		 * @param array                       $steps        Which side-effect steps ran (see get_steps()).
		 *
		 * @return array
		 */
		public static function fulfilled_entry( SS_Shipping_Booked_Shipment $shipment, string $order_note, string $outputs_html, array $steps ): array {
			return array(
				'is_return'    => $shipment->is_return(),
				'shipment'     => $shipment,
				'order_note'   => $order_note,
				'outputs_html' => $outputs_html,
				'steps'        => $steps,
			);
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
		 * The error of the outbound leg, or null when it was not attempted
		 * or was fulfilled.
		 *
		 * @return string|null
		 */
		public function get_outbound_error(): ?string {
			return $this->find_error( false );
		}

		/**
		 * The error of the return leg, or null when it was not attempted
		 * or was fulfilled.
		 *
		 * @return string|null
		 */
		public function get_return_error(): ?string {
			return $this->find_error( true );
		}

		/**
		 * The per-field validation errors the API reported for a leg
		 * (field => list of messages), empty when the leg was not
		 * attempted, was fulfilled, or failed for another reason.
		 *
		 * @param boolean $is_return Which leg.
		 *
		 * @return array<string, string[]>
		 */
		public function get_validation_errors( bool $is_return ): array {
			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['error'] ) && $entry['is_return'] === $is_return ) {
					return isset( $entry['errors'] ) ? $entry['errors'] : array();
				}
			}

			return array();
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
		 * after the smart_send_fulfillment_order_note filter - an empty
		 * string when the filter suppressed the note. Null when the
		 * shipment is not part of this run.
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
		 * Which side-effect steps ran for a fulfilled shipment:
		 * 'save_documents' (bool - a local copy of the documents was
		 * stored), 'order_note' (bool - the note was added to the order),
		 * 'tracking' (bool - tracking was pushed to Shipment Tracking) and
		 * 'order_status' (the status the order was set to, or false). Null
		 * when the shipment is not part of this run.
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment One of shipments().
		 *
		 * @return array|null
		 */
		public function get_steps( SS_Shipping_Booked_Shipment $shipment ): ?array {
			$entry = $this->find_entry( $shipment );

			return null === $entry ? null : $entry['steps'];
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
		 * The error message of a leg, or null.
		 *
		 * @param boolean $is_return Which leg.
		 *
		 * @return string|null
		 */
		protected function find_error( bool $is_return ): ?string {
			foreach ( $this->entries as $entry ) {
				if ( isset( $entry['error'] ) && $entry['is_return'] === $is_return ) {
					return $entry['error'];
				}
			}

			return null;
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
