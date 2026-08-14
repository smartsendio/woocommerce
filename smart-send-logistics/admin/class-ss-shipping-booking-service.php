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
	 * (SS_Shipping_Fulfillment_Service) as a SS_Shipping_Booking.
	 *
	 * Outbound and return bookings are two separate entry points -
	 * book_outbound() and book_return() - rather than a single method
	 * taking an is_return boolean; see SS_Shipping_Shipment_Builder's
	 * class docblock for why. Neither method ever throws: a config error
	 * (e.g. no return method configured, raised internally by
	 * SS_Shipping_Shipment_Builder as a SS_Shipping_Booking_Exception) or
	 * an API-level error are both reported the same way, as a failed
	 * SS_Shipping_Booking - callers only need to check is_successful().
	 *
	 * The service is stateless and long-lived: it is constructed once
	 * (without an order) and takes the WC_Order per call, so one instance
	 * can safely book many orders sequentially. The short-lived per-order
	 * collaborators (SS_Shipping_Order_Reader, SS_Shipping_Shipment_Builder)
	 * are constructed fresh per call and keep their constructor-injected
	 * WC_Order.
	 */
	class SS_Shipping_Booking_Service {

		/**
		 * Order meta access component.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Constructor.
		 *
		 * @param SS_Shipping_Order_Meta $order_meta Order meta access component.
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta ) {
			$this->order_meta = $order_meta;
		}

		/**
		 * Book an outbound (normal) shipping label.
		 *
		 * @param WC_Order $order The WooCommerce order to book a shipment for.
		 *
		 * @return SS_Shipping_Booking
		 */
		public function book_outbound( WC_Order $order ): SS_Shipping_Booking {
			$builder = new SS_Shipping_Shipment_Builder( $order, new SS_Shipping_Order_Reader( $order ), $this->order_meta );

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
		 * @param WC_Order $order The WooCommerce order to book a shipment for.
		 *
		 * @return SS_Shipping_Booking
		 */
		public function book_return( WC_Order $order ): SS_Shipping_Booking {
			$builder = new SS_Shipping_Shipment_Builder( $order, new SS_Shipping_Order_Reader( $order ), $this->order_meta );

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
