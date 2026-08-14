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
		 * Order meta access component.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * The booking service (stateless, order passed per call).
		 *
		 * @var SS_Shipping_Booking_Service
		 */
		protected SS_Shipping_Booking_Service $booking_service;

		/**
		 * Constructor.
		 *
		 * @param SS_Shipping_Order_Meta      $order_meta      Order meta access component.
		 * @param SS_Shipping_Booking_Service $booking_service The booking service.
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Booking_Service $booking_service ) {
			$this->order_meta      = $order_meta;
			$this->booking_service = $booking_service;
		}

		/**
		 * Fulfill an outbound (normal) shipping label for the order, and -
		 * when the order's shipping method has the auto-generate-return-label
		 * setting enabled and the outbound fulfillment succeeded - the return
		 * label too.
		 *
		 * @param int|WC_Order $order           Order id or order object.
		 * @param boolean      $save_order_note Whether to save an order note with information about the label.
		 *
		 * @return SS_Shipping_Fulfillment_Result
		 */
		public function fulfill_outbound( $order, $save_order_note = true ): SS_Shipping_Fulfillment_Result {
			$order = $this->resolve_order( $order );

			if ( ! $order instanceof WC_Order ) {
				return new SS_Shipping_Fulfillment_Result( null, null, array( $order ) );
			}

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
		 * @param int|WC_Order $order           Order id or order object.
		 * @param boolean      $save_order_note Whether to save an order note with information about the label.
		 *
		 * @return SS_Shipping_Fulfillment_Result
		 */
		public function fulfill_return( $order, $save_order_note = true ): SS_Shipping_Fulfillment_Result {
			$order = $this->resolve_order( $order );

			if ( ! $order instanceof WC_Order ) {
				return new SS_Shipping_Fulfillment_Result( null, null, array( $order ) );
			}

			$return_booking = $this->booking_service->book_return( $order );

			$entries = array( $this->complete_fulfillment( $order, $return_booking, true, $save_order_note ) );

			return new SS_Shipping_Fulfillment_Result( null, $return_booking, $entries );
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
			$ss_shipping_method_id = $this->order_meta->get_smart_send_method_id( $order->get_id(), true );

			return isset( $ss_shipping_method_id['smart_send_auto_generate_return_label'] ) &&
				'yes' == $ss_shipping_method_id['smart_send_auto_generate_return_label']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for this extraction.
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

			if ( SS_SHIPPING_WC()->get_setting_save_shipping_labels_in_uploads() ) {
				try {
					// Save the PDF file
					$label_url = $this->save_label_file(
						$response->shipment_id,
						$response->pdf->base_64_encoded,
						$is_return
					);
				} catch ( Exception $e ) {
					return array( 'error' => $e->getMessage() );
				}
			}

			// Get the label link
			$label_url = $response->pdf->link;

			// save order meta data
			$this->order_meta->save_ss_shipment_id_in_order_meta( $order_id, $response->shipment_id, $is_return );

			SS_Shipping_Logger::info(
				$is_return ? 'Return shipping label created' : 'Shipping label created',
				array(
					'order_id'    => $order_id,
					'shipment_id' => $response->shipment_id,
					'carrier'     => isset( $response->carrier_name ) ? $response->carrier_name : null,
				)
			);

			// Get formatted order comment
			$response->woocommerce['label_url']  = $label_url;
			$response->woocommerce['order_note'] = $this->get_formatted_order_note_with_label_and_tracking(
				$order_id,
				$response,
				$is_return
			);
			$response->woocommerce['return']     = $is_return;

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
					$response->woocommerce['order_note'],
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

			// Action when a shipping label has been created
			do_action( 'smart_send_shipping_label_created', $order_id, $response );

			// return the success data
			return array(
				'success'  => $response,
				'shipment' => $booking->get_wire_shipment(),
			);
		}

		/**
		 * If set to change order after order generated, update order status
		 *
		 * @param WC_Order $order The WooCommerce order.
		 */
		protected function set_order_status_after_label_generated( $order ) {

			$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

			if ( ! empty( $ss_settings['order_status'] ) ) {
				$order->update_status( $ss_settings['order_status'] );
			}
		}

		/**
		 * Get a formatted string containing link to PDF label, tracking code and tracking link.
		 * This note is inserted in the order comment.
		 *
		 * @param int $order_id Order ID
		 * @param mixed $api_shipment_response response for API call
		 * @param boolean $is_return true for return labels and false for normal labels (default)
		 *
		 * @return string HTML formatted note
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- pre-existing method signature, kept for backwards compatibility.
		protected function get_formatted_order_note_with_label_and_tracking( $order_id, $api_shipment_response, $is_return ) {

			$tracking_note = sprintf(
				'<label>%1$s: </label>%2$s',
				$is_return ? __( 'Return shipping label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' ),
				$this->get_ss_shipping_label_link( $api_shipment_response->woocommerce['label_url'], $is_return )
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

		public function get_label_url_from_shipment_id( $shipment_id ) {
			$upload_path = wp_upload_dir();
			return $upload_path['url'] . '/' . $this->get_label_name_from_shipment_id( $shipment_id );
		}

		public function get_label_path_from_shipment_id( $shipment_id ) {
			$upload_path = wp_upload_dir();
			return $upload_path['path'] . '/' . $this->get_label_name_from_shipment_id( $shipment_id );
		}

		protected function get_label_name_from_shipment_id( $shipment_id ) {
			if ( $this->label_prefix ) {
				$shipment_id = $this->label_prefix . $shipment_id;
			}
			return $shipment_id . '.pdf';
		}

		/*
		 * Gets label URL post meta array for an order
		 *
		 * @param int  $order_id  Order ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 *
		 * @return string URL label link
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_label_url_from_order_id( $order_id, $return ): string {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return '';
			}
			if ( $return ) {
				$shipment_id = $order->get_meta( '_ss_shipping_return_label_id', true );
			} else {
				$shipment_id = $order->get_meta( '_ss_shipping_label_id', true );
			}
			return $this->get_label_url_from_shipment_id( $shipment_id );
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
