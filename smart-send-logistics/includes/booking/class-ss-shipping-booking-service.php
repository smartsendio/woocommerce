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
	 * Books a shipment for a WooCommerce order with the Smart Send API -
	 * the booking stage (#177): everything from "build the shipment" to
	 * "API response", and nothing that happens on the order afterwards.
	 *
	 * book() takes the WC_Order plus the SS_Shipping_Delivery_Details
	 * fulfillment decided on (method, pickup point, parcel plan) and:
	 *
	 *   1. builds the request DTO, SS_Shipping_Shipment, through
	 *      SS_Shipping_Shipment_Builder (#113) and runs the
	 *      smart_send_booking_request filter on it;
	 *   2. hands it to \Smartsend\Resources\BookingResource (#112), which
	 *      translates it into the v1 wire request and sends it;
	 *   3. maps the response into the result DTO,
	 *      SS_Shipping_Booked_Shipment, fires smart_send_booking_completed
	 *      and returns it - or, when the API rejected the shipment, fires
	 *      smart_send_booking_failed and throws
	 *      SS_Shipping_Booking_Exception (the API exception as previous,
	 *      validation errors on errors()).
	 *
	 * Booking pragmatically receives the WC_Order (a booking always comes
	 * from an order, and the order reader needs it) but never reads order
	 * meta and never resolves shipping methods: that is
	 * SS_Shipping_Fulfillment_Service's job, before book() is called.
	 * Outbound and return bookings are the same operation, hence one
	 * method with an is_return flag - fulfillment, whose two flows
	 * genuinely differ, keeps two entry points.
	 *
	 * This is also where the API v1 response becomes the typed
	 * SS_Shipping_Booked_Shipment: map_v1_response() is the one place
	 * that knows the v1 response shape (shipment_id, carrier_code,
	 * pdf->link/base_64_encoded, parcels[]->tracking_code/tracking_link).
	 * The API v2 switch replaces that mapper and nothing else; nothing
	 * v1-shaped leaves this class.
	 *
	 * The service is stateless and long-lived: it is constructed once and
	 * takes the WC_Order per call, so one instance can safely book many
	 * orders sequentially. The short-lived per-order collaborators
	 * (SS_Shipping_Order_Reader, SS_Shipping_Shipment_Builder) are
	 * constructed fresh per call.
	 */
	class SS_Shipping_Booking_Service {

		/**
		 * Book a shipment for the order.
		 *
		 * @param WC_Order                     $order     The WooCommerce order the shipment is for.
		 * @param SS_Shipping_Delivery_Details $details   The delivery details to ship with (method set, pickup point and parcel plan as decided).
		 * @param boolean                      $is_return Whether the shipment is a return shipment.
		 *
		 * @throws SS_Shipping_Booking_Exception When the shipment cannot be built or the API rejects it.
		 *
		 * @return SS_Shipping_Booked_Shipment
		 */
		public function book( WC_Order $order, SS_Shipping_Delivery_Details $details, bool $is_return ): SS_Shipping_Booked_Shipment {
			$builder  = new SS_Shipping_Shipment_Builder( $order, new SS_Shipping_Order_Reader( $order ) );
			$shipment = $builder->build( $details );

			/*
			 * Filter the shipment about to be booked: the complete request
			 * representation (receiver, pickup point, parcels with item
			 * lines, amounts) after the order has been read and the
			 * delivery details applied, right before it is sent to the
			 * Smart Send API. Return the (modified) SS_Shipping_Shipment.
			 *
			 * @since 9.0.0
			 *
			 * @param SS_Shipping_Shipment $shipment  The shipment to book.
			 * @param WC_Order             $order     The WooCommerce order.
			 * @param boolean              $is_return Whether the shipment is a return shipment.
			 *
			 * @return SS_Shipping_Shipment The shipment to book.
			 */
			$shipment = apply_filters( 'smart_send_booking_request', $shipment, $order, $is_return );

			$api = SS_SHIPPING_WC()->get_api_handle();

			$wire_shipment = $api->bookings()->fromShipment( $shipment );

			// Make API Request. The request and response (incl. HTTP status
			// code and endpoint) are logged by the client's request logger.
			try {
				$response = $api->bookings()->create( $wire_shipment );
			} catch ( \Smartsend\Exceptions\HttpClientException $e ) {
				$exception = SS_Shipping_Booking_Exception::from_api_exception( $e );

				/*
				 * Action when the Smart Send API rejected a shipment. Fires
				 * right before the SS_Shipping_Booking_Exception is thrown
				 * (getMessage(), errors() for per-field validation errors,
				 * response_id(), getPrevious() for the API client exception).
				 *
				 * @since 9.0.0
				 *
				 * @param SS_Shipping_Booking_Exception $exception The failure.
				 * @param SS_Shipping_Shipment          $shipment  The shipment that was sent.
				 * @param WC_Order                      $order     The WooCommerce order.
				 */
				do_action( 'smart_send_booking_failed', $exception, $shipment, $order );

				throw $exception;
			}

			$booked = $this->map_v1_response( $response->data(), $shipment, $is_return );

			/*
			 * Action when the Smart Send API booked a shipment. Fires
			 * before anything is written to the WooCommerce order - listen
			 * to smart_send_order_fulfilled for the order-side outcome.
			 *
			 * @since 9.0.0
			 *
			 * @param SS_Shipping_Booked_Shipment $booked   The booked shipment (shipment id, tracking, parcels, documents, codes).
			 * @param SS_Shipping_Shipment        $shipment The shipment that was sent.
			 * @param WC_Order                    $order    The WooCommerce order.
			 */
			do_action( 'smart_send_booking_completed', $booked, $shipment, $order );

			return $booked;
		}

		/**
		 * Map the API v1 create-shipment response into the typed booked
		 * shipment: one "label" document in "pdf" format from pdf->link
		 * (with the inline base64 bytes as its transient inline_content),
		 * no codes (v1 never produces any), one booked parcel per
		 * parcels[] entry - enriched with the weight, dimensions and
		 * reference of the request parcel at the same index, which the API
		 * does not echo back -, and shipment-level tracking taken from the
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

			$response_parcels = isset( $data->parcels ) ? array_values( (array) $data->parcels ) : array();
			// The API returns one response parcel per request parcel, in the
			// order they were sent, and echoes none of their measures back:
			// the weight, dimensions and reference come from the request
			// parcel at the same index. A count mismatch is not fatal - the
			// booking succeeded - so it is logged and the measures of the
			// unmatched parcels are left out.
			$request_parcels = array_values( $shipment->get_parcels() );

			if ( count( $request_parcels ) !== count( $response_parcels ) ) {
				SS_Shipping_Logger::warning(
					'The Smart Send API returned a different number of parcels than were booked - the booked parcels carry no weight, dimensions or reference.',
					array(
						'shipment_id'      => $booked->get_shipment_id(),
						'request_parcels'  => count( $request_parcels ),
						'response_parcels' => count( $response_parcels ),
					)
				);

				$request_parcels = array();
			}

			foreach ( $response_parcels as $index => $parcel ) {
				$requested = isset( $request_parcels[ $index ] ) ? $request_parcels[ $index ] : null;

				$booked->add_parcel(
					new SS_Shipping_Booked_Parcel(
						isset( $parcel->parcel_internal_id ) ? (string) $parcel->parcel_internal_id : null,
						isset( $parcel->tracking_code ) ? (string) $parcel->tracking_code : null,
						isset( $parcel->tracking_link ) ? (string) $parcel->tracking_link : null,
						null === $requested ? null : $requested->get_weight(),
						null === $requested ? null : $requested->get_length(),
						null === $requested ? null : $requested->get_width(),
						null === $requested ? null : $requested->get_height(),
						null === $requested ? null : $requested->get_internal_reference()
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
				$document = new SS_Shipping_Shipment_Document(
					SS_Shipping_Shipment_Document::TYPE_LABEL,
					SS_Shipping_Shipment_Document::FORMAT_PDF,
					isset( $data->pdf->link ) ? (string) $data->pdf->link : ''
				);

				// v1 delivers the label PDF inline next to its link; the
				// uploads copy is written from these bytes (v1 bridge, see
				// SS_Shipping_Shipment_Document::get_inline_content()).
				$document->set_inline_content( isset( $data->pdf->base_64_encoded ) ? (string) $data->pdf->base_64_encoded : null );

				$booked->add_document( $document );
			}

			return $booked;
		}
	}

endif;
