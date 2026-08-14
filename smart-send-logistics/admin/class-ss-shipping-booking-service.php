<?php
/**
 * WooCommerce Smart Send booking service.
 *
 * @package  SS_Shipping_Booking_Service
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Booking_Service' ) ) :

	/**
	 * Books a WooCommerce order with the Smart Send API.
	 *
	 * SS_Shipping_Shipment_Builder assembles the internal shipment
	 * representation (#113); \Smartsend\Resources\BookingResource (#112)
	 * translates that representation into the v1 wire request
	 * (Smartsend\Models\Shipment and its sub-models) and sends it. This
	 * class is the order-level orchestrator: build the representation, hand
	 * it to the booking resource, and expose the result to callers
	 * (SS_Shipping_Label_Creator) as a SS_Shipping_Booking.
	 *
	 * Outbound and return bookings are two separate entry points -
	 * book_outbound() and book_return() - rather than a single method
	 * taking an is_return boolean; see SS_Shipping_Shipment_Builder's
	 * class docblock for why. Neither method ever throws: a config error
	 * (e.g. no return method configured, raised internally by
	 * SS_Shipping_Shipment_Builder as a SS_Shipping_Booking_Exception) or
	 * an API-level error are both reported the same way, as a failed
	 * SS_Shipping_Booking - callers only need to check is_successful().
	 */
	class SS_Shipping_Booking_Service {

		/**
		 * The WooCommerce order.
		 *
		 * @var WC_Order
		 */
		protected WC_Order $order;

		/**
		 * Order data access utility.
		 *
		 * @var SS_Shipping_Order_Reader
		 */
		protected SS_Shipping_Order_Reader $order_reader;

		/**
		 * The admin order integration.
		 *
		 * @var SS_Shipping_WC_Order
		 */
		protected SS_Shipping_WC_Order $shipping_order;

		/**
		 * Constructor.
		 *
		 * @param WC_Order             $order          The WooCommerce order to book a shipment for.
		 * @param SS_Shipping_WC_Order $shipping_order The admin order integration.
		 */
		public function __construct( WC_Order $order, SS_Shipping_WC_Order $shipping_order ) {
			$this->order          = $order;
			$this->order_reader   = new SS_Shipping_Order_Reader( $order );
			$this->shipping_order = $shipping_order;
		}

		/**
		 * Book an outbound (normal) shipping label.
		 *
		 * @return SS_Shipping_Booking
		 */
		public function book_outbound(): SS_Shipping_Booking {
			$builder = new SS_Shipping_Shipment_Builder( $this->order, $this->order_reader, $this->shipping_order );

			try {
				$shipment = $builder->build_outbound();
			} catch ( SS_Shipping_Booking_Exception $e ) {
				return new SS_Shipping_Booking( false, $e->getMessage(), null, null );
			}

			return $this->send( $shipment );
		}

		/**
		 * Book a return shipping label.
		 *
		 * @return SS_Shipping_Booking
		 */
		public function book_return(): SS_Shipping_Booking {
			$builder = new SS_Shipping_Shipment_Builder( $this->order, $this->order_reader, $this->shipping_order );

			try {
				$shipment = $builder->build_return();
			} catch ( SS_Shipping_Booking_Exception $e ) {
				return new SS_Shipping_Booking( false, $e->getMessage(), null, null );
			}

			return $this->send( $shipment );
		}

		/**
		 * Translate the shipment representation into the v1 wire model and
		 * send it to the Smart Send API, wrapping the outcome into a
		 * SS_Shipping_Booking. Shared by book_outbound() and book_return()
		 * - the part of booking that never differs between the two.
		 *
		 * @param SS_Shipping_Shipment $shipment The shipment representation to book.
		 *
		 * @return SS_Shipping_Booking
		 */
		protected function send( SS_Shipping_Shipment $shipment ): SS_Shipping_Booking {
			$api = SS_SHIPPING_WC()->get_api_handle();

			$wire_shipment = $api->bookings()->fromShipment( $shipment );

			// Make API Request. The request and response (incl. HTTP status
			// code and endpoint) are logged by the client's request logger.
			$api->bookings()->create( $wire_shipment );

			if ( $api->isSuccessful() ) {
				return new SS_Shipping_Booking( true, null, $api->getData(), $wire_shipment );
			}

			return new SS_Shipping_Booking( false, $api->getErrorString(), null, $wire_shipment );
		}
	}

endif;
