<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send label creation.
 *
 * Owns the AJAX label-generation endpoint and the full create-label flow
 * for a single order: calling the API via SS_Shipping_Booking_Service,
 * storing the shipment id, writing the order note, pushing tracking info
 * and saving the PDF file.
 *
 * @package  SS_Shipping_Label_Creator
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Label_Creator' ) ) :

	class SS_Shipping_Label_Creator {

		protected string $label_prefix = 'smart-send-label-';

		/**
		 * The order integration facade (passed on to SS_Shipping_Booking_Service).
		 *
		 * @var SS_Shipping_WC_Order
		 */
		protected SS_Shipping_WC_Order $order_integration;

		/**
		 * Order meta access component.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * @param SS_Shipping_WC_Order   $order_integration The order integration facade.
		 * @param SS_Shipping_Order_Meta $order_meta        Order meta access component.
		 */
		public function __construct( SS_Shipping_WC_Order $order_integration, SS_Shipping_Order_Meta $order_meta ) {
			$this->order_integration = $order_integration;
			$this->order_meta        = $order_meta;
		}

		/**
		 * Save Agent No. and Generate Label
		 */
		public function generate_label() {
			check_ajax_referer(
				'create-ss-shipping-label',
				'ss_shipping_label_nonce'
			); //This function dies if the referer is not correct
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: nonce-checked above, order id is wc_clean-ed and the flags are boolval-ed; changing the parcels input handling is out of scope for the #43 move.
			$order_id     = wc_clean( $_POST['order_id'] );
			$return       = boolval( $_POST['return_label'] );
			$split_parcel = boolval( $_POST['ss_shipping_split_parcel'] );

			// Save parcels input if set:
			$parcels = ( $split_parcel ) ? $_POST['ss_shipping_parcels'] : array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput
			$this->order_meta->save_ss_shipping_order_parcels( $order_id, $parcels );

			$response = $this->create_label_for_single_order_maybe_return( $order_id, $return, false );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Create label for a single WooCommerce order and maybe auto generate return label
		 *
		 * @param int $order_id Order ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 * @param boolean $setting_save_order_note Whether or not to save an order note with information about label
		 *
		 * @return array
		 */
		public function create_label_for_single_order_maybe_return(
			$order_id,
			$return = false, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing method signature, kept for backwards compatibility.
			$setting_save_order_note = true
		) {

			$reponse_arr = array();

			$ss_shipping_method_id = $this->order_meta->get_smart_send_method_id( $order_id, true );

			// If creating normal label and auto generate return flag is enabled, create both
			if ( ! $return &&
				isset( $ss_shipping_method_id['smart_send_auto_generate_return_label'] ) &&
				'yes' == $ss_shipping_method_id['smart_send_auto_generate_return_label'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.

				// Create the normal label
				$response = $this->create_label_for_single_order( $order_id, false, $setting_save_order_note );
				array_push( $reponse_arr, $response );

				// We're only creating the return label if the normal label creation is successful.
				if ( isset( $response['success']->woocommerce ) ) {
					// Create the return label
					$response = $this->create_label_for_single_order( $order_id, true, $setting_save_order_note );
					array_push( $reponse_arr, $response );
				}
			} else {
				$response = $this->create_label_for_single_order( $order_id, $return, $setting_save_order_note );
				array_push( $reponse_arr, $response );
			}

			return $reponse_arr;
		}

		/**
		 * Create label for a single WooCommerce order
		 *
		 * @param int $order_id Order ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 * @param boolean $setting_save_order_note Whether or not to save an order note with information about label
		 *
		 * @return array
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing method signature, kept for backwards compatibility.
		protected function create_label_for_single_order( $order_id, $return = false, $setting_save_order_note = true ) {
			// Load WC Order
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return array(
					'error' => sprintf(
						/* translators: %s: WooCommerce order id. */
						__( 'Order #%s: The order could not be found', 'smart-send-logistics' ),
						$order_id
					),
				);
			}

			$booking_service = new SS_Shipping_Booking_Service( $this->order_integration );
			$booking         = $return ? $booking_service->book_return( $order ) : $booking_service->book_outbound( $order );

			if ( $booking->is_successful() ) {

				//The request was successful, lets update WooCommerce
				$response = $booking->get_data();

				if ( SS_SHIPPING_WC()->get_setting_save_shipping_labels_in_uploads() ) {
					try {
						// Save the PDF file
						$label_url = $this->save_label_file(
							$response->shipment_id,
							$response->pdf->base_64_encoded,
							$return
						);
					} catch ( Exception $e ) {
						return array( 'error' => $e->getMessage() );
					}
				}

				// Get the label link
				$label_url = $response->pdf->link;

				// save order meta data
				$this->order_meta->save_ss_shipment_id_in_order_meta( $order_id, $response->shipment_id, $return );

				SS_Shipping_Logger::info(
					$return ? 'Return shipping label created' : 'Shipping label created',
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
					$return
				);
				$response->woocommerce['return']     = $return;

				// Save order note
				if ( $setting_save_order_note ) {
					/*
					 * Filter the order comment that is saved. The order comment can be seen in the WooCommerce backend
					 *
					 * @param string order note containing tracking link and link to pdf label
					 * @param WC_Order object
					 * @param boolean $return Whether or not the label is return (true) or normal (false)
					 */
					$order_note = apply_filters(
						'smart_send_shipping_label_comment',
						$response->woocommerce['order_note'],
						$order,
						$return
					);
					$order->add_order_note( $order_note, 0, true );

					SS_Shipping_Logger::info( 'Order note with label and tracking added', array( 'order_id' => $order_id ) );
				}

				// Add tracking info to "WooCommerce Shipment Tracking" plugin.
				foreach ( $response->parcels as $parcel ) {
					// Only add tracking info to "WooCommerce Shipment Tracking" plugin for non-return parcels.
					if ( ! $return ) {
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
				if ( ! $return ) {
					$this->set_order_status_after_label_generated( $order );
				}

				// Action when a shipping label has been created
				do_action( 'smart_send_shipping_label_created', $order_id, $response );

				// return the success data
				return array(
					'success'  => $response,
					'shipment' => $booking->get_wire_shipment(),
				);
			} else {
				// Something failed. Let's return them, so the error can be shown to the user
				return array( 'error' => $booking->get_error_message() );
			}
		}

		/**
		 * If set to change order after order generated, update order status
		 */
		protected function set_order_status_after_label_generated( $order ) {

			$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

			if ( ! empty( $ss_settings['order_status'] ) ) {
				$order->update_status( $ss_settings['order_status'] );
			}
		}

		/**
		 * Get tracking details from returned shipment details
		 */
		protected function get_tracking_details( $shipment ) {
			$tracking_array = array();
			foreach ( $shipment->parcels as $parcel ) {
				$tracking_array[ $parcel->parcel_internal_id ] = array(
					'carrier_code'  => $shipment->carrier_code,
					'carrier_name'  => $shipment->carrier_name,
					'tracking_code' => $parcel->tracking_code,
					/*
					 * Filter the tracking link
					 *
					 * @param string | tracking link
					 * @param string | carrier code
					 */
					'tracking_link' => apply_filters(
						'smart_send_tracking_url',
						$parcel->tracking_link,
						$shipment->carrier_code
					),
				);
			}
			return $tracking_array;
		}

		/**
		 * Get a formatted string containing link to PDF label, tracking code and tracking link.
		 * This note is inserted in the order comment.
		 *
		 * @param int $order_id Order ID
		 * @param mixed $api_shipment_response response for API call
		 * @param boolean $return true for return labels and false for normal labels (default)
		 *
		 * @return string HTML formatted note
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- pre-existing method signature, kept for backwards compatibility.
		protected function get_formatted_order_note_with_label_and_tracking( $order_id, $api_shipment_response, $return ) {

			$tracking_note = sprintf(
				'<label>%1$s: </label>%2$s',
				$return ? __( 'Return shipping label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' ),
				$this->get_ss_shipping_label_link( $api_shipment_response->woocommerce['label_url'], $return )
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
