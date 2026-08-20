<?php
/**
 * WooCommerce Smart Send fulfillment service.
 *
 * @package  SS_Shipping_Fulfillment_Service
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Fulfillment_Service' ) ) :

	/**
	 * Owns the post-booking fulfillment workflow for a single order:
	 * book the label via SS_Shipping_Booking_Service, then - on success -
	 * save the label PDF to uploads (setting-gated), store the shipment id
	 * in order meta, write the order note (smart_send_shipping_label_comment
	 * filter), push tracking numbers to the optional Shipment Tracking
	 * plugin (outbound only), update the order status when configured
	 * (outbound only) and fire smart_send_shipping_label_created. On a
	 * failed booking nothing is written.
	 *
	 * fulfill_outbound() also runs the return fulfillment when the order's
	 * shipping method has the auto-generate-return-label setting enabled -
	 * that workflow decision lives here, not in the AJAX controller.
	 *
	 * The service is stateless and long-lived: constructed once, no
	 * per-order state on properties, safe to call for many orders
	 * sequentially. It lives in includes/ (not admin/) because Phase 7's
	 * async processing will call it outside an admin-screen context.
	 */
	class SS_Shipping_Fulfillment_Service {

		/**
		 * Filename prefix for label PDFs saved in the uploads folder.
		 *
		 * @var string
		 */
		protected string $label_prefix = 'smart-send-label-';

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
		 * Booked shipment id accessor.
		 *
		 * @var SS_Shipping_Shipment_Ids
		 */
		protected SS_Shipping_Shipment_Ids $shipment_ids;

		/**
		 * The booking service (stateless, order passed per call).
		 *
		 * @var SS_Shipping_Booking_Service
		 */
		protected SS_Shipping_Booking_Service $booking_service;

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * Constructor.
		 *
		 * @param SS_Shipping_Order_Meta      $order_meta      Order meta repository.
		 * @param SS_Shipping_Method_Resolver $method_resolver Shipping method resolver.
		 * @param SS_Shipping_Shipment_Ids    $shipment_ids    Booked shipment id accessor.
		 * @param SS_Shipping_Booking_Service $booking_service The booking service.
		 * @param SS_Shipping_Settings|null   $settings        Typed plugin settings reader (stateless; a fresh default is safe).
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Method_Resolver $method_resolver, SS_Shipping_Shipment_Ids $shipment_ids, SS_Shipping_Booking_Service $booking_service, ?SS_Shipping_Settings $settings = null ) {
			$this->order_meta      = $order_meta;
			$this->method_resolver = $method_resolver;
			$this->shipment_ids    = $shipment_ids;
			$this->booking_service = $booking_service;
			$this->settings        = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * Fulfill an outbound (normal) shipping label for the order, and -
		 * when the order's shipping method has the auto-generate-return-label
		 * setting enabled and the outbound fulfillment succeeded - the return
		 * label too.
		 *
		 * @param int|WC_Order                      $order             Order id or order object.
		 * @param boolean                           $save_order_note   Whether to save an order note with information about the label.
		 * @param SS_Shipping_Delivery_Details|null $delivery_overrides Partial delivery details submitted with the request (e.g. the meta box parcel split), persisted through the repository before booking.
		 *
		 * @return SS_Shipping_Fulfillment_Result
		 */
		public function fulfill_outbound( $order, $save_order_note = true, ?SS_Shipping_Delivery_Details $delivery_overrides = null ): SS_Shipping_Fulfillment_Result {
			$order = $this->resolve_order( $order );

			if ( ! $order instanceof WC_Order ) {
				return new SS_Shipping_Fulfillment_Result( null, null, array( $order ) );
			}

			$this->apply_delivery_overrides( $order, $delivery_overrides );

			$outbound_booking = $this->booking_service->book_outbound( $order );

			$entries   = array();
			$entries[] = $this->complete_fulfillment( $order, $outbound_booking, false, $save_order_note );

			$return_booking = null;

			// We're only creating the return label if the outbound BOOKING succeeded.
			// Deliberate behaviour change from the historic flow, which gated on the
			// whole outbound entry: a successful API booking whose local PDF save
			// failed used to silently skip the configured auto-return label; now the
			// return label is still attempted and both outcomes are reported.
			if ( $outbound_booking->is_successful() && $this->is_auto_return_enabled( $order ) ) {
				$return_booking = $this->booking_service->book_return( $order );
				$entries[]      = $this->complete_fulfillment( $order, $return_booking, true, $save_order_note );
			}

			return new SS_Shipping_Fulfillment_Result( $outbound_booking, $return_booking, $entries );
		}

		/**
		 * Fulfill a return shipping label for the order.
		 *
		 * @param int|WC_Order                      $order             Order id or order object.
		 * @param boolean                           $save_order_note   Whether to save an order note with information about the label.
		 * @param SS_Shipping_Delivery_Details|null $delivery_overrides Partial delivery details submitted with the request, persisted through the repository before booking.
		 *
		 * @return SS_Shipping_Fulfillment_Result
		 */
		public function fulfill_return( $order, $save_order_note = true, ?SS_Shipping_Delivery_Details $delivery_overrides = null ): SS_Shipping_Fulfillment_Result {
			$order = $this->resolve_order( $order );

			if ( ! $order instanceof WC_Order ) {
				return new SS_Shipping_Fulfillment_Result( null, null, array( $order ) );
			}

			$this->apply_delivery_overrides( $order, $delivery_overrides );

			$return_booking = $this->booking_service->book_return( $order );

			$entries = array( $this->complete_fulfillment( $order, $return_booking, true, $save_order_note ) );

			return new SS_Shipping_Fulfillment_Result( null, $return_booking, $entries );
		}

		/**
		 * Persist the delivery details submitted with a fulfillment request
		 * (e.g. the parcel split from the order meta box) before booking,
		 * deliberately keeping the historic save-before-book behaviour: the
		 * submitted configuration sticks even when the booking then fails.
		 *
		 * @param WC_Order                          $order              The WooCommerce order.
		 * @param SS_Shipping_Delivery_Details|null $delivery_overrides Partial delivery details, or null when the request carried none.
		 *
		 * @return void
		 */
		protected function apply_delivery_overrides( WC_Order $order, ?SS_Shipping_Delivery_Details $delivery_overrides ) {
			if ( null !== $delivery_overrides ) {
				$this->order_meta->write( $order, $delivery_overrides );
			}
		}

		/**
		 * Resolve the order argument to a WC_Order exactly once - the layers
		 * below all receive the object and never re-load it by id.
		 *
		 * @param int|WC_Order $order Order id or order object.
		 *
		 * @return WC_Order|array The order, or a legacy error entry when it could not be loaded.
		 */
		protected function resolve_order( $order ) {
			if ( $order instanceof WC_Order ) {
				return $order;
			}

			$loaded = wc_get_order( $order );

			if ( $loaded instanceof WC_Order ) {
				return $loaded;
			}

			return array(
				'error' => sprintf(
					/* translators: %s: WooCommerce order id. */
					__( 'Order #%s: The order could not be found', 'smart-send-logistics' ),
					$order
				),
			);
		}

		/**
		 * Whether the order's Smart Send shipping method has the
		 * auto-generate-return-label setting enabled.
		 *
		 * @param WC_Order $order The WooCommerce order.
		 *
		 * @return boolean
		 */
		protected function is_auto_return_enabled( WC_Order $order ): bool {
			return $this->method_resolver->is_auto_return_enabled( $order );
		}

		/**
		 * Run the post-booking workflow for one booked label and produce the
		 * legacy response entry. The order of operations is deliberate and
		 * unchanged from the historic create_label_for_single_order(): label
		 * file, order meta, order note, tracking, THEN the status update -
		 * the status change must come after meta and tracking so both are
		 * included in the email sent via Shipment Tracking.
		 *
		 * On a failed booking nothing is written and the error entry is
		 * returned.
		 *
		 * @param WC_Order            $order           The WooCommerce order.
		 * @param SS_Shipping_Booking $booking         The booking outcome.
		 * @param boolean             $is_return       Whether the label is a return label.
		 * @param boolean             $save_order_note Whether to save an order note with information about the label.
		 *
		 * @return array A legacy response entry: array('success' => ..., 'shipment' => ...) or array('error' => ...).
		 */
		protected function complete_fulfillment( WC_Order $order, SS_Shipping_Booking $booking, $is_return, $save_order_note ) {
			if ( ! $booking->is_successful() ) {
				// Something failed. Let's return the error, so it can be shown to the user
				return array( 'error' => $booking->get_error_message() );
			}

			$order_id = $order->get_id();

			//The request was successful, lets update WooCommerce
			$response = $booking->get_data();

			if ( $this->settings->save_labels_in_uploads() ) {
				try {
					// Save the PDF file and link to the local copy. Deliberate
					// v9 fix (#139): the computed uploads URL used to be
					// discarded (unconditionally overwritten with the API
					// link); with the setting enabled, the note/response now
					// actually link the uploads copy.
					$label_url = $this->save_label_file(
						$response->shipment_id,
						$response->pdf->base_64_encoded,
						$is_return
					);
				} catch ( Exception $e ) {
					return array( 'error' => $e->getMessage() );
				}
			} else {
				// Get the label link
				$label_url = $response->pdf->link;
			}

			// save order meta data
			$this->shipment_ids->save( $order, $response->shipment_id, $is_return );

			SS_Shipping_Logger::info(
				$is_return ? 'Return shipping label created' : 'Shipping label created',
				array(
					'order_id'    => $order_id,
					'shipment_id' => $response->shipment_id,
					'carrier'     => isset( $response->carrier_name ) ? $response->carrier_name : null,
				)
			);

			// Get formatted order comment
			$order_note_html = $this->get_formatted_order_note_with_label_and_tracking(
				$label_url,
				$response,
				$is_return
			);

			// Save order note
			if ( $save_order_note ) {
				/*
				 * Filter the order comment that is saved. The order comment can be seen in the WooCommerce backend
				 *
				 * @param string order note containing tracking link and link to pdf label
				 * @param WC_Order object
				 * @param boolean $is_return Whether or not the label is return (true) or normal (false)
				 */
				$order_note = apply_filters(
					'smart_send_shipping_label_comment',
					$order_note_html,
					$order,
					$is_return
				);
				$order->add_order_note( $order_note, 0, true );

				SS_Shipping_Logger::info( 'Order note with label and tracking added', array( 'order_id' => $order_id ) );
			}

			// Add tracking info to "WooCommerce Shipment Tracking" plugin.
			foreach ( $response->parcels as $parcel ) {
				// Only add tracking info to "WooCommerce Shipment Tracking" plugin for non-return parcels.
				if ( ! $is_return ) {
					$this->save_tracking_in_shipment_tracking(
						$order_id,
						$parcel->tracking_code,
						$parcel->tracking_link,
						$response->carrier_name,
						null
					);

					SS_Shipping_Logger::info(
						'Tracking number stored',
						array(
							'order_id'      => $order_id,
							'tracking_code' => $parcel->tracking_code,
							'carrier'       => $response->carrier_name,
						)
					);
				}
			}

			// Set order status after label generation
			// Important to update AFTER saving meta fields and tracking information (otherwise not included in email via Shipment Tracking)
			if ( ! $is_return ) {
				$this->set_order_status_after_label_generated( $order );
			}

			/*
			 * Action when a shipping label has been created.
			 *
			 * Deliberate v9 breaking change (#139): $response is now the
			 * pristine API booking response - the presentation data that
			 * used to be mutated into $response->woocommerce (label_url,
			 * order_note, return) travels in the typed
			 * SS_Shipping_Label_Entry third argument instead.
			 *
			 * @param int                    $order_id The WooCommerce order id.
			 * @param object                 $response The raw API booking response.
			 * @param SS_Shipping_Label_Entry $entry    The created label: label URL, order note HTML, return flag, response.
			 */
			do_action(
				'smart_send_shipping_label_created',
				$order_id,
				$response,
				new SS_Shipping_Label_Entry( $order_id, $is_return, $label_url, $order_note_html, $response )
			);

			// The legacy AJAX/bulk response entry keeps the frozen shape
			// (admin/js/ss-shipping-label.js parses success.woocommerce.*),
			// built on a clone so the raw response stays unmutated.
			$legacy_response              = clone $response;
			$legacy_response->woocommerce = array(
				'label_url'  => $label_url,
				'order_note' => $order_note_html,
				'return'     => $is_return,
			);

			// return the success data
			return array(
				'success'  => $legacy_response,
				'shipment' => $booking->get_wire_shipment(),
			);
		}

		/**
		 * If set to change order after order generated, update order status
		 *
		 * @param WC_Order $order The WooCommerce order.
		 */
		protected function set_order_status_after_label_generated( $order ) {
			$order_status = $this->settings->order_status_after_label();

			if ( null !== $order_status ) {
				$order->update_status( $order_status );
			}
		}

		/**
		 * Get a formatted string containing link to PDF label, tracking code and tracking link.
		 * This note is inserted in the order comment.
		 *
		 * @param string $label_url The label download URL.
		 * @param mixed $api_shipment_response response for API call
		 * @param boolean $is_return true for return labels and false for normal labels (default)
		 *
		 * @return string HTML formatted note
		 */
		protected function get_formatted_order_note_with_label_and_tracking( $label_url, $api_shipment_response, $is_return ) {

			$tracking_note = sprintf(
				'<label>%1$s: </label>%2$s',
				$is_return ? __( 'Return shipping label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' ),
				$this->get_ss_shipping_label_link( $label_url, $is_return )
			);

			foreach ( $api_shipment_response->parcels as $parcel ) {
				$tracking_note .= sprintf(
					'<br><label>%1$s: </label><a href="%2$s" target="_blank">%3$s</a>',
					__( 'Tracking number', 'smart-send-logistics' ),
					$parcel->tracking_link,
					$parcel->tracking_code
				);
			}

			return $tracking_note;
		}

		/**
		 * Save label file in "uploads" folder
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- pre-existing method signature, kept for backwards compatibility.
		public function save_label_file( $shipment_id, $label_data, $return ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-existing behaviour: exception messages are returned to the admin AJAX response as data, not printed as HTML; escaping is a behaviour change out of scope for the #43 move.

			if ( empty( $shipment_id ) ) {
				throw new Exception( __( 'Shipment id is empty', 'smart-send-logistics' ) );
			}

			if ( empty( $label_data ) ) {
				throw new Exception( __( 'Label data empty', 'smart-send-logistics' ) );
			}

			$label_data_decoded = base64_decode( $label_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the Smart Send API delivers the label PDF base64-encoded; this is transport decoding, not obfuscation.
			$file_ret           = wp_upload_bits(
				$this->get_label_name_from_shipment_id( $shipment_id ),
				null,
				$label_data_decoded,
				null
			);

			if ( empty( $file_ret['url'] ) ) {
				throw new Exception(
					__(
						'Label file cannot be saved',
						'smart-send-logistics'
					)
				); //This exception is not caught
			}
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

			return $file_ret['url'];
		}

		protected function get_label_name_from_shipment_id( $shipment_id ) {
			if ( $this->label_prefix ) {
				$shipment_id = $this->label_prefix . $shipment_id;
			}
			return $shipment_id . '.pdf';
		}

		/**
		 * Get formatted label link
		 *
		 * @param string $url label url
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 *
		 * @return string html label link
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_ss_shipping_label_link( $url, $return ) {
			if ( $return ) {
				$message = __( 'Download return shipping label', 'smart-send-logistics' );
			} else {
				$message = __( 'Download shipping label', 'smart-send-logistics' );
			}
			return '<a href="' . $url . '" target="_blank">' . $message . '</a>';
		}

		/**
		 * Save tracking number in Shipment Tracking
		 *
		 * @param int $order_id Order ID
		 * @param string $tracking_number Unique tracking code for parcel
		 * @param string $tracking_url Url for tracking parcel delivery
		 * @param string $provider Carrier provider
		 * @param string $date_shipped Shipping data in format YYYY-mm-dd
		 *
		 * @return void
		 */
		public function save_tracking_in_shipment_tracking(
			$order_id,
			$tracking_number,
			$tracking_url,
			$provider = 'Smart Send',
			$date_shipped = null
		) {

			// The WooCommerce Shipment Tracking plugin is optional.
			if ( function_exists( 'wc_st_add_tracking_number' ) ) {
				wc_st_add_tracking_number( $order_id, $tracking_number, $provider, $date_shipped, $tracking_url );
			}
		}
	}

endif;
