<?php
/**
 * WooCommerce Smart Send Shipping Order Payload.
 *
 * @package  SS_Shipping_Shipment
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment' ) ) :

	/**
	 * Sends a WooCommerce order to the Smart Send API as a shipment.
	 *
	 * SS_Shipping_Shipment_Builder assembles the new internal shipment
	 * representation (#113); \Smartsend\Resources\BookingResource (#112)
	 * translates that representation into the v1 wire request
	 * (Smartsend\Models\Shipment and its sub-models) and sends it. This
	 * class is now only the order-level orchestrator: build the
	 * representation, hand it to the booking resource, and expose the
	 * result to callers (SS_Shipping_Label_Creator).
	 */
	class SS_Shipping_Shipment {

		/**
		 * The WooCommerce order.
		 *
		 * Deliberately untyped: wc_get_order() can return false (or a
		 * WC_Order_Refund), and the constructor relies on that falsy value.
		 *
		 * @var WC_Order|false|null
		 */
		protected $order = null;

		/**
		 * Order data access utility.
		 *
		 * @var SS_Shipping_Order_Data|null
		 */
		protected ?SS_Shipping_Order_Data $order_data = null;

		/**
		 * The admin order integration.
		 *
		 * @var SS_Shipping_WC_Order|null
		 */
		protected ?SS_Shipping_WC_Order $shipping_order = null;

		/**
		 * The internal shipment representation assembled by
		 * SS_Shipping_Shipment_Builder, or an error array.
		 *
		 * @var array|null
		 */
		protected ?array $representation = null;

		/**
		 * The v1 wire shipment model translated from the representation.
		 *
		 * @var \Smartsend\Models\Shipment|null
		 */
		protected ?\Smartsend\Models\Shipment $shipment = null;

		/**
		 * Init and hook in the integration.
		 *
		 * @param WC_Order|integer     $order          The order (object or id) to build a shipment for.
		 * @param SS_Shipping_WC_Order $shipping_order The admin order integration.
		 */
		public function __construct( $order, $shipping_order ) {
			if ( is_numeric( $order ) && $order > 0 ) {
				$this->order = wc_get_order( $order );

				if ( ! $this->order ) {
					return;
				}
			} elseif ( $order instanceof WC_Order ) {
				$this->order = $order;
			} else {
				return;
			}

			$this->order_data     = new SS_Shipping_Order_Data( $this->order );
			$this->shipping_order = $shipping_order;

			// Wire shipment model, empty until the representation is
			// translated. Kept even on a build error so a caller that
			// still sends the (empty) request behaves as it always has.
			$this->shipment = new \Smartsend\Models\Shipment();
		}

		/**
		 * Create single order.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return boolean
		 */
		public function make_single_shipment_api_call( $is_return ) {
			$this->make_single_shipment_api_payload( $is_return );
			$this->make_single_shipment_api_request();

			if ( SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Get API call data.
		 *
		 * @return object
		 */
		public function get_shipping_data() {
			return SS_SHIPPING_WC()->get_api_handle()->getData();
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_msg() {
			return SS_SHIPPING_WC()->get_api_handle()->getErrorString();
		}

		/**
		 * Get shipment object.
		 *
		 * @return \Smartsend\Models\Shipment
		 */
		public function get_shipment() {
			return $this->shipment;
		}

		/**
		 * Build the internal shipment representation and translate it into
		 * the v1 wire shipment model. The translation itself is
		 * \Smartsend\Resources\BookingResource::fromRepresentation() (#112)
		 * - the single point where the v1 wire payload is built.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return array|void The representation, only when it is an error.
		 */
		protected function make_single_shipment_api_payload( $is_return ) {
			$builder              = new SS_Shipping_Shipment_Builder( $this->order, $this->order_data, $this->shipping_order );
			$this->representation = $builder->build( $is_return );

			if ( isset( $this->representation['error'] ) ) {
				return $this->representation;
			}

			$this->shipment = SS_SHIPPING_WC()->get_api_handle()->bookings()->fromRepresentation( $this->representation );
		}

		/**
		 * Call Smart Send Shipment API, log response.
		 *
		 * @return void
		 */
		protected function make_single_shipment_api_request() {
			// Make API Request. The request and response (incl. HTTP status
			// code and endpoint) are logged by the client's request logger.
			SS_SHIPPING_WC()->get_api_handle()->bookings()->create( $this->shipment );
		}
	}

endif;
