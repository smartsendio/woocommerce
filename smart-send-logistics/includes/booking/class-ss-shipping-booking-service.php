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
	 * This is also where the API v1 response becomes the typed
	 * SS_Shipping_Booked_Shipment (#177): map_v1_response() is the one
	 * place that knows the v1 response shape (shipment_id, carrier_code,
	 * pdf->link/base_64_encoded, parcels[]->tracking_code/tracking_link).
	 * The API v2 switch replaces that mapper and nothing else; nothing
	 * v1-shaped leaves this class.
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
		 * Order meta repository.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Shipping method resolver.
		 *
		 * @var SS_Shipping_Method_Resolver
		 */
		protected SS_Shipping_Method_Resolver $method_resolver;

		/**
		 * Constructor.
		 *
		 * @param SS_Shipping_Order_Meta      $order_meta      Order meta repository.
		 * @param SS_Shipping_Method_Resolver $method_resolver Shipping method resolver.
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Method_Resolver $method_resolver ) {
			$this->order_meta      = $order_meta;
			$this->method_resolver = $method_resolver;
		}

		/**
		 * Book an outbound (normal) shipping label.
		 *
		 * @param WC_Order $order The WooCommerce order to book a shipment for.
		 *
		 * @return SS_Shipping_Booking
		 */
		public function book_outbound( WC_Order $order ): SS_Shipping_Booking {
			$builder = new SS_Shipping_Shipment_Builder( $order, new SS_Shipping_Order_Reader( $order ), $this->order_meta, $this->method_resolver );

			try {
				$shipment = $builder->build_outbound();
			} catch ( SS_Shipping_Booking_Exception $e ) {
				return new SS_Shipping_Booking( false, $e->getMessage(), null );
			}

			return $this->send( $shipment, false );
		}

		/**
		 * Book a return shipping label.
		 *
		 * @param WC_Order $order The WooCommerce order to book a shipment for.
		 *
		 * @return SS_Shipping_Booking
		 */
		public function book_return( WC_Order $order ): SS_Shipping_Booking {
			$builder = new SS_Shipping_Shipment_Builder( $order, new SS_Shipping_Order_Reader( $order ), $this->order_meta, $this->method_resolver );

			try {
				$shipment = $builder->build_return();
			} catch ( SS_Shipping_Booking_Exception $e ) {
				return new SS_Shipping_Booking( false, $e->getMessage(), null );
			}

			return $this->send( $shipment, true );
		}

		/**
		 * Translate the shipment representation into the v1 wire model,
		 * send it to the Smart Send API and map the response into the
		 * typed booked shipment, wrapping the outcome into a
		 * SS_Shipping_Booking. Shared by book_outbound() and book_return()
		 * - the part of booking that never differs between the two.
		 *
		 * @param SS_Shipping_Shipment $shipment  The shipment representation to book.
		 * @param boolean              $is_return Whether the shipment is a return shipment.
		 *
		 * @return SS_Shipping_Booking
		 */
		protected function send( SS_Shipping_Shipment $shipment, bool $is_return ): SS_Shipping_Booking {
			$api = SS_SHIPPING_WC()->get_api_handle();

			$wire_shipment = $api->bookings()->fromShipment( $shipment );

			// Make API Request. The request and response (incl. HTTP status
			// code and endpoint) are logged by the client's request logger.
			try {
				$response = $api->bookings()->create( $wire_shipment );
			} catch ( \Smartsend\Exceptions\HttpClientException $e ) {
				return new SS_Shipping_Booking( false, $this->format_booking_error( $e ), null );
			}

			$data = $response->data();

			return new SS_Shipping_Booking(
				true,
				null,
				$this->map_v1_response( $data, $shipment, $is_return ),
				$this->v1_document_contents( $data )
			);
		}

		/**
		 * Map the API v1 create-shipment response into the typed booked
		 * shipment: one "label" document in "pdf" format from pdf->link,
		 * no codes (v1 never produces any), one booked parcel per
		 * parcels[] entry, and shipment-level tracking taken from the
		 * first parcel when the API gives none (v1 tracks per parcel).
		 * The carrier and method codes fall back to what was requested
		 * when the response does not repeat them.
		 *
		 * @param object               $data      The v1 response data (Smartsend\Response::data()).
		 * @param SS_Shipping_Shipment $shipment  The shipment representation that was booked.
		 * @param boolean              $is_return Whether the shipment is a return shipment.
		 *
		 * @return SS_Shipping_Booked_Shipment
		 */
		protected function map_v1_response( $data, SS_Shipping_Shipment $shipment, bool $is_return ): SS_Shipping_Booked_Shipment {
			$booked = new SS_Shipping_Booked_Shipment( isset( $data->shipment_id ) ? (string) $data->shipment_id : '' );

			$booked
				->set_carrier( ! empty( $data->carrier_code ) ? (string) $data->carrier_code : $shipment->get_shipping_carrier() )
				->set_service_code( $shipment->get_shipping_method() )
				->set_is_return( $is_return )
				->set_state( SS_Shipping_Booked_Shipment::STATE_BOOKED )
				->set_booked_at( gmdate( 'c' ) );

			foreach ( isset( $data->parcels ) ? (array) $data->parcels : array() as $parcel ) {
				$booked->add_parcel(
					new SS_Shipping_Booked_Parcel(
						isset( $parcel->parcel_internal_id ) ? (string) $parcel->parcel_internal_id : null,
						isset( $parcel->tracking_code ) ? (string) $parcel->tracking_code : null,
						isset( $parcel->tracking_link ) ? (string) $parcel->tracking_link : null
					)
				);
			}

			if ( ! empty( $data->tracking_code ) ) {
				$booked->set_tracking( (string) $data->tracking_code, isset( $data->tracking_link ) ? (string) $data->tracking_link : null );
			} elseif ( array() !== $booked->parcels() ) {
				$first = $booked->parcels()[0];
				$booked->set_tracking( $first->get_tracking_code(), $first->get_tracking_url() );
			}

			if ( isset( $data->pdf ) ) {
				$booked->add_document(
					new SS_Shipping_Shipment_Document(
						SS_Shipping_Shipment_Document::TYPE_LABEL,
						SS_Shipping_Shipment_Document::FORMAT_PDF,
						isset( $data->pdf->link ) ? (string) $data->pdf->link : ''
					)
				);
			}

			return $booked;
		}

		/**
		 * The inline base64 document contents of a v1 response, keyed by
		 * the index the document gets in map_v1_response(): v1 delivers
		 * the label PDF inline next to its link (the uploads copy is
		 * written from these bytes).
		 *
		 * @param object $data The v1 response data.
		 *
		 * @return string[]
		 */
		protected function v1_document_contents( $data ): array {
			if ( ! isset( $data->pdf ) ) {
				return array();
			}

			return array( 0 => isset( $data->pdf->base_64_encoded ) ? (string) $data->pdf->base_64_encoded : '' );
		}

		/**
		 * Render an API failure as the human-readable (HTML) error string
		 * shown to the merchant: the message, each field's validation
		 * errors and the API's Response-ID for support reference.
		 *
		 * @param \Smartsend\Exceptions\HttpClientException $e The failure.
		 *
		 * @return string
		 */
		protected function format_booking_error( \Smartsend\Exceptions\HttpClientException $e ): string {
			$delimiter    = '<br>';
			$error_string = $e->getMessage();

			if ( $e instanceof \Smartsend\Exceptions\ValidationException ) {
				foreach ( $e->errors() as $error_field => $error_details ) {
					if ( count( $error_details ) > 1 ) {
						$error_string .= $delimiter . $error_field . ':';
						foreach ( $error_details as $error_description ) {
							$error_string .= $delimiter . '- ' . $error_description;
						}
					} else {
						foreach ( $error_details as $error_description ) {
							$error_string .= $delimiter . '- ' . $error_field . ': ' . $error_description;
						}
					}
				}
			}

			if ( $e instanceof \Smartsend\Exceptions\RequestException && null !== $e->getResponse()->responseId() ) {
				$error_string .= $delimiter . 'Response ID: ' . $e->getResponse()->responseId();
			}

			return $error_string;
		}
	}

endif;
