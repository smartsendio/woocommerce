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
	 * The fulfillment stage (#177): owns the WooCommerce order and decides
	 * WHAT ships and HOW, hands that decision to the booking stage, and
	 * writes the outcome back onto the order.
	 *
	 * Two flows, because they genuinely differ (what ships, the
	 * auto-return decision, order status and tracking push are
	 * outbound-only, the meta keys differ):
	 *
	 *   fulfill_outbound( $order, ... ) - the outbound label, plus the
	 *     return label when the order's shipping method has the
	 *     auto-generate-return-label setting enabled;
	 *   fulfill_return( $order, ... )   - a return label on its own.
	 *
	 * Each leg runs the same steps:
	 *
	 *   1. Delivery details = the stored order configuration (repository)
	 *      + the method resolved from the order's shipping item (+ the
	 *      overrides submitted with the request, persisted first), passed
	 *      through the smart_send_delivery_details filter.
	 *   2. SS_Shipping_Booking_Service::book() - ONE operation for both
	 *      directions - returns the SS_Shipping_Booked_Shipment or throws
	 *      SS_Shipping_Booking_Exception, which is caught here and
	 *      recorded on the result (rendered as HTML for the merchant by
	 *      format_booking_error()).
	 *   3. Side effects on the order, one step each, each with a filter:
	 *      shipment id in meta (no filter), local copy of the documents
	 *      (smart_send_fulfillment_save_documents), order note
	 *      (smart_send_fulfillment_order_note), tracking push
	 *      (smart_send_fulfillment_tracking), order status
	 *      (smart_send_fulfillment_order_status).
	 *
	 * Once every leg is done, smart_send_order_fulfilled fires once with
	 * the order and the complete SS_Shipping_Fulfillment_Result.
	 *
	 * Every step reads the typed SS_Shipping_Booked_Shipment the booking
	 * produced - documents, codes and parcels - never an API response, so
	 * the rendering never assumes "one PDF": every document gets a
	 * download link and every code is shown.
	 *
	 * The service is stateless and long-lived: constructed once, no
	 * per-order state on properties, safe to call for many orders
	 * sequentially. It lives in includes/ (not admin/) because Phase 7's
	 * async processing will call it outside an admin-screen context.
	 */
	class SS_Shipping_Fulfillment_Service {

		/**
		 * Filename prefix for label documents saved in the uploads folder.
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
		 * setting enabled and the outbound booking succeeded - the return
		 * label too.
		 *
		 * @param int|WC_Order                      $order              Order id or order object.
		 * @param boolean                           $save_order_note    Whether to save an order note with information about the label.
		 * @param SS_Shipping_Delivery_Details|null $delivery_overrides Partial delivery details submitted with the request (e.g. the meta box parcel split), persisted through the repository before booking.
		 *
		 * @return SS_Shipping_Fulfillment_Result
		 */
		public function fulfill_outbound( $order, $save_order_note = true, ?SS_Shipping_Delivery_Details $delivery_overrides = null ): SS_Shipping_Fulfillment_Result {
			$order = $this->resolve_order( $order );

			if ( ! $order instanceof WC_Order ) {
				return new SS_Shipping_Fulfillment_Result( array( $order ) );
			}

			$this->apply_delivery_overrides( $order, $delivery_overrides );

			$entries   = array();
			$entries[] = $this->fulfill_leg( $order, false, $save_order_note, $outbound_booked );

			// We're only creating the return label if the outbound BOOKING succeeded.
			// Deliberate behaviour change from the historic flow, which gated on the
			// whole outbound entry: a successful API booking whose local PDF save
			// failed used to silently skip the configured auto-return label; now the
			// return label is still attempted and both outcomes are reported.
			if ( $outbound_booked && $this->method_resolver->is_auto_return_enabled( $order ) ) {
				$entries[] = $this->fulfill_leg( $order, true, $save_order_note, $return_booked );
			}

			return $this->finish( $order, new SS_Shipping_Fulfillment_Result( $entries ) );
		}

		/**
		 * Fulfill a return shipping label for the order.
		 *
		 * @param int|WC_Order                      $order              Order id or order object.
		 * @param boolean                           $save_order_note    Whether to save an order note with information about the label.
		 * @param SS_Shipping_Delivery_Details|null $delivery_overrides Partial delivery details submitted with the request, persisted through the repository before booking.
		 *
		 * @return SS_Shipping_Fulfillment_Result
		 */
		public function fulfill_return( $order, $save_order_note = true, ?SS_Shipping_Delivery_Details $delivery_overrides = null ): SS_Shipping_Fulfillment_Result {
			$order = $this->resolve_order( $order );

			if ( ! $order instanceof WC_Order ) {
				return new SS_Shipping_Fulfillment_Result( array( $order ) );
			}

			$this->apply_delivery_overrides( $order, $delivery_overrides );

			$entries = array( $this->fulfill_leg( $order, true, $save_order_note, $return_booked ) );

			return $this->finish( $order, new SS_Shipping_Fulfillment_Result( $entries ) );
		}

		/**
		 * The delivery details a label for the order ships with, before
		 * the smart_send_delivery_details filter: the stored order
		 * configuration (pickup point, parcel plan) with the shipping
		 * method resolved from the order's shipping item. An outbound
		 * label uses the order's Smart Send shipping method and keeps the
		 * stored pickup point; a return label uses the configured return
		 * method and drops the pickup point - unless the return method
		 * could not be resolved to the dedicated smart_send_return_method
		 * meta (free-shipping/vConnect orders, see
		 * SS_Shipping_Method_Resolver::return_uses_stored_pickup_point()),
		 * in which case the stored selection applies like on an outbound
		 * label.
		 *
		 * Public so Phase 7 can queue "order id + details" (#116).
		 *
		 * @param WC_Order $order     The WooCommerce order.
		 * @param boolean  $is_return Whether the label is a return label.
		 *
		 * @throws SS_Shipping_Booking_Exception When no return method is configured (from the resolver).
		 *
		 * @return SS_Shipping_Delivery_Details
		 */
		public function resolve_delivery_details( WC_Order $order, bool $is_return ): SS_Shipping_Delivery_Details {
			$details = $this->order_meta->read( $order->get_id() );

			if ( $is_return ) {
				$details->set_shipping_method( $this->method_resolver->resolve_return( $order ) );

				if ( ! $this->method_resolver->return_uses_stored_pickup_point( $order ) ) {
					$details->set_pickup_point( null );
				}
			} else {
				$details->set_shipping_method( $this->method_resolver->resolve_outbound( $order ) );
			}

			return $details;
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
		 * @return WC_Order|array The order, or a failed entry when it could not be loaded.
		 */
		protected function resolve_order( $order ) {
			if ( $order instanceof WC_Order ) {
				return $order;
			}

			$loaded = wc_get_order( $order );

			if ( $loaded instanceof WC_Order ) {
				return $loaded;
			}

			return SS_Shipping_Fulfillment_Result::failed_entry(
				false,
				sprintf(
					/* translators: %s: WooCommerce order id. */
					__( 'Order #%s: The order could not be found', 'smart-send-logistics' ),
					$order
				)
			);
		}

		/**
		 * Announce a completed run: smart_send_order_fulfilled fires once,
		 * after every write, tracking push and status update of both
		 * labels is done, when at least one shipment was fulfilled - so
		 * listeners see the complete result (both labels when a return
		 * label was auto-generated). A run that fulfilled nothing is not
		 * announced; its errors are on the result for the caller.
		 *
		 * @param WC_Order                       $order  The WooCommerce order.
		 * @param SS_Shipping_Fulfillment_Result $result The completed run.
		 *
		 * @return SS_Shipping_Fulfillment_Result The same result, for chaining.
		 */
		protected function finish( WC_Order $order, SS_Shipping_Fulfillment_Result $result ): SS_Shipping_Fulfillment_Result {
			if ( array() !== $result->shipments() ) {
				/*
				 * Action when an order has been fulfilled: at least one
				 * shipment was booked and the WooCommerce side (order
				 * meta, documents, order note, tracking, status) is
				 * updated. Fires once per run with the complete result -
				 * shipments(), get_outbound_shipment(),
				 * get_return_shipment(), get_order_note( $shipment ),
				 * get_steps( $shipment ) and the errors of a failed leg.
				 *
				 * @since 9.0.0
				 *
				 * @param WC_Order                       $order  The WooCommerce order.
				 * @param SS_Shipping_Fulfillment_Result $result The whole fulfillment run.
				 */
				do_action( 'smart_send_order_fulfilled', $order, $result );
			}

			return $result;
		}

		/**
		 * Run one leg - decide the delivery details, book, apply the side
		 * effects - and produce the run entry. The order of operations is
		 * deliberate and unchanged from the historic
		 * create_label_for_single_order(): documents, order meta, order
		 * note, tracking, THEN the status update - the status change must
		 * come after meta and tracking so both are included in the email
		 * sent via Shipment Tracking.
		 *
		 * On a failed booking nothing is written and a failed entry is
		 * returned; $booked reports whether the API booked the shipment
		 * (the auto-return gate), independent of the side effects.
		 *
		 * @param WC_Order $order           The WooCommerce order.
		 * @param boolean  $is_return       Whether the label is a return label.
		 * @param boolean  $save_order_note Whether to save an order note with information about the label.
		 * @param boolean  $booked          Set to whether the booking itself succeeded.
		 *
		 * @return array A run entry in the shape SS_Shipping_Fulfillment_Result documents.
		 */
		protected function fulfill_leg( WC_Order $order, bool $is_return, $save_order_note, &$booked = null ): array {
			$booked = false;

			try {
				$details = $this->resolve_delivery_details( $order, $is_return );

				/*
				 * Filter the delivery details a shipping label is booked
				 * with, after the stored configuration and the resolved
				 * method have been merged and before booking is called.
				 * One typed extension point for everything Smart Send knows
				 * about how the order ships: override the shipping method,
				 * clear or replace the pickup point (SS_Shipping_Pickup_Point),
				 * or declare a parcel plan (SS_Shipping_Parcel_Plan of
				 * SS_Shipping_Parcel_Spec rows - specs may carry dimensions
				 * and an explicit weight with no item allocations at all).
				 *
				 * Replaces the removed smart_send_shipping_label_args,
				 * smart_send_order_parcels, smart_send_order_pickup_point and
				 * smart_send_parcel_weight filters (v9).
				 *
				 * @since 9.0.0
				 *
				 * @param SS_Shipping_Delivery_Details $details   The merged delivery details.
				 * @param WC_Order                     $order     The WooCommerce order.
				 * @param boolean                      $is_return Whether the label is a return label.
				 *
				 * @return SS_Shipping_Delivery_Details The delivery details to book with.
				 */
				$details = apply_filters( 'smart_send_delivery_details', $details, $order, $is_return );

				$shipment = $this->booking_service->book( $order, $details, $is_return );
			} catch ( SS_Shipping_Booking_Exception $e ) {
				// The booking failed. Record the error, so it can be shown to the user.
				return SS_Shipping_Fulfillment_Result::failed_entry( $is_return, $this->format_booking_error( $e ), $e->errors() );
			}

			$booked = true;

			return $this->apply_side_effects( $order, $shipment, $save_order_note );
		}

		/**
		 * Write a booked shipment onto the order, one step at a time.
		 *
		 * @param WC_Order                    $order           The WooCommerce order.
		 * @param SS_Shipping_Booked_Shipment $shipment        The booked shipment.
		 * @param boolean                     $save_order_note Whether the caller wants the order note saved (the AJAX flow adds it client-side instead).
		 *
		 * @return array A run entry in the shape SS_Shipping_Fulfillment_Result documents.
		 */
		protected function apply_side_effects( WC_Order $order, SS_Shipping_Booked_Shipment $shipment, $save_order_note ): array {
			$order_id     = $order->get_id();
			$is_return    = $shipment->is_return();
			$carrier_name = $this->get_carrier_display_name( $shipment );

			/*
			 * Filter whether a local copy of the shipment's documents is
			 * saved in the uploads folder. Defaults to the "save shipping
			 * labels in uploads" setting.
			 *
			 * @since 9.0.0
			 *
			 * @param boolean                     $save_documents Whether to store the local copy.
			 * @param SS_Shipping_Booked_Shipment $shipment       The booked shipment.
			 * @param WC_Order                    $order          The WooCommerce order.
			 */
			$save_documents = (bool) apply_filters( 'smart_send_fulfillment_save_documents', $this->settings->save_labels_in_uploads(), $shipment, $order );

			if ( $save_documents ) {
				try {
					// Save the label document(s) and link the local copy.
					// Deliberate v9 fix (#139): the computed uploads URL used
					// to be discarded (unconditionally overwritten with the
					// API link); with the setting enabled, the note/response
					// now actually link the uploads copy.
					$this->store_uploads_copies( $shipment );
				} catch ( Exception $e ) {
					return SS_Shipping_Fulfillment_Result::failed_entry( $is_return, $e->getMessage() );
				}
			}

			// save order meta data
			$this->shipment_ids->save_booked( $order, $shipment );

			SS_Shipping_Logger::info(
				$is_return ? 'Return shipping label created' : 'Shipping label created',
				array(
					'order_id'    => $order_id,
					'shipment_id' => $shipment->get_shipment_id(),
					'carrier'     => $carrier_name,
				)
			);

			/*
			 * Filter the order note (HTML) added to the order once a
			 * shipment is booked: the document links, codes and tracking
			 * numbers. Return an empty string to add no note.
			 *
			 * @since 9.0.0 Renamed from smart_send_shipping_label_comment; the
			 *              order argument is now the WC_Order and the shipment
			 *              is passed instead of the is-return flag.
			 *
			 * @param string                      $order_note The order note HTML.
			 * @param SS_Shipping_Booked_Shipment $shipment   The booked shipment.
			 * @param WC_Order                    $order      The WooCommerce order.
			 */
			$order_note = (string) apply_filters(
				'smart_send_fulfillment_order_note',
				$this->get_formatted_order_note_with_label_and_tracking( $shipment ),
				$shipment,
				$order
			);

			$note_added = false;
			if ( $save_order_note && '' !== $order_note ) {
				$order->add_order_note( $order_note, 0, true );
				$note_added = true;

				SS_Shipping_Logger::info( 'Order note with label and tracking added', array( 'order_id' => $order_id ) );
			}

			/*
			 * Filter whether the parcels' tracking numbers are pushed to the
			 * WooCommerce Shipment Tracking plugin. Defaults to true for an
			 * outbound shipment and false for a return shipment.
			 *
			 * @since 9.0.0
			 *
			 * @param boolean                     $push_tracking Whether to push tracking.
			 * @param SS_Shipping_Booked_Shipment $shipment      The booked shipment.
			 * @param WC_Order                    $order         The WooCommerce order.
			 */
			$push_tracking = (bool) apply_filters( 'smart_send_fulfillment_tracking', ! $is_return, $shipment, $order );

			if ( $push_tracking ) {
				foreach ( $shipment->parcels() as $parcel ) {
					$this->save_tracking_in_shipment_tracking(
						$order_id,
						$parcel->get_tracking_code(),
						$parcel->get_tracking_url(),
						$carrier_name,
						null
					);

					SS_Shipping_Logger::info(
						'Tracking number stored',
						array(
							'order_id'      => $order_id,
							'tracking_code' => $parcel->get_tracking_code(),
							'carrier'       => $carrier_name,
						)
					);
				}
			}

			/*
			 * Filter the order status the order is set to once the
			 * shipment is booked, or false to leave the status alone.
			 * Defaults to the "order status after label" setting for an
			 * outbound shipment and false for a return shipment.
			 *
			 * Important to update AFTER saving meta fields and tracking
			 * information (otherwise not included in the email sent via
			 * Shipment Tracking).
			 *
			 * @since 9.0.0
			 *
			 * @param string|false                $order_status The status to set (e.g. 'wc-completed'), or false.
			 * @param SS_Shipping_Booked_Shipment $shipment     The booked shipment.
			 * @param WC_Order                    $order        The WooCommerce order.
			 */
			$order_status = apply_filters( 'smart_send_fulfillment_order_status', $this->default_order_status( $is_return ), $shipment, $order );

			if ( is_string( $order_status ) && '' !== $order_status ) {
				$order->update_status( $order_status );
			} else {
				$order_status = false;
			}

			return SS_Shipping_Fulfillment_Result::fulfilled_entry(
				$shipment,
				$order_note,
				$this->get_shipment_outputs_html( $shipment ),
				array(
					'save_documents' => $save_documents,
					'order_note'     => $note_added,
					'tracking'       => $push_tracking,
					'order_status'   => $order_status,
				)
			);
		}

		/**
		 * The status an order is set to after a label is booked, before
		 * the smart_send_fulfillment_order_status filter: the configured
		 * setting for an outbound label, never for a return label.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return string|false
		 */
		protected function default_order_status( bool $is_return ) {
			if ( $is_return ) {
				return false;
			}

			$order_status = $this->settings->order_status_after_label();

			return null === $order_status ? false : $order_status;
		}

		/**
		 * Save a copy of every label document in the uploads folder and
		 * record it on the document (local_path/local_url). Only documents
		 * whose bytes the API delivered inline are copied - API v1 sends
		 * the label PDF inline next to its link.
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment The booked shipment.
		 *
		 * @throws Exception When a label document cannot be saved.
		 *
		 * @return void
		 */
		protected function store_uploads_copies( SS_Shipping_Booked_Shipment $shipment ) {
			foreach ( $shipment->documents() as $index => $document ) {
				if ( SS_Shipping_Shipment_Document::TYPE_LABEL !== $document->get_type() ) {
					continue;
				}

				$copy = $this->save_label_copy(
					$shipment->get_shipment_id(),
					$document->get_inline_content(),
					$document->get_format(),
					0 === $index ? '' : '-' . $index
				);

				$document->set_local_copy( $copy['file'], $copy['url'] );
			}
		}

		/**
		 * Render a booking failure as the human-readable (HTML) error
		 * string shown to the merchant: the message, each field's
		 * validation errors and the API's Response-ID for support
		 * reference.
		 *
		 * @param SS_Shipping_Booking_Exception $e The failure.
		 *
		 * @return string
		 */
		protected function format_booking_error( SS_Shipping_Booking_Exception $e ): string {
			$delimiter    = '<br>';
			$error_string = $e->getMessage();

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

			if ( null !== $e->response_id() ) {
				$error_string .= $delimiter . 'Response ID: ' . $e->response_id();
			}

			return $error_string;
		}

		/**
		 * Get a formatted string containing a link to every document, every
		 * code, and the tracking code and link of every parcel. This note
		 * is inserted in the order comment.
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment The booked shipment.
		 *
		 * @return string HTML formatted note
		 */
		protected function get_formatted_order_note_with_label_and_tracking( SS_Shipping_Booked_Shipment $shipment ) {
			$lines     = array();
			$is_return = $shipment->is_return();

			foreach ( $shipment->documents() as $document ) {
				$lines[] = sprintf(
					'<label>%1$s: </label>%2$s',
					$this->get_document_label( $document, $is_return ),
					$this->get_document_link( $document, $is_return )
				);
			}

			foreach ( $shipment->codes() as $code ) {
				$lines[] = sprintf(
					'<label>%1$s: </label>%2$s',
					$this->get_code_label( $code ),
					$this->get_code_html( $code )
				);
			}

			foreach ( $shipment->parcels() as $parcel ) {
				$lines[] = sprintf(
					'<label>%1$s: </label><a href="%2$s" target="_blank">%3$s</a>',
					__( 'Tracking number', 'smart-send-logistics' ),
					$parcel->get_tracking_url(),
					$parcel->get_tracking_code()
				);
			}

			return implode( '<br>', $lines );
		}

		/**
		 * The HTML shown on the order screen (meta box) and in the bulk
		 * action notice once a shipment is booked: a download link for
		 * every document and every code (value plus QR image when there is
		 * one), joined by $separator.
		 *
		 * The meta box keeps its historic, shorter label link texts
		 * ("Download return label"); the bulk notice and the order note use
		 * the long ones ("Download return shipping label").
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment         The booked shipment.
		 * @param string                      $separator        Separator between the rendered outputs.
		 * @param boolean                     $meta_box_wording Whether to use the meta box's link texts (default) or the order note's.
		 *
		 * @return string
		 */
		public function get_shipment_outputs_html( SS_Shipping_Booked_Shipment $shipment, $separator = '<br>', $meta_box_wording = true ) {
			$parts     = array();
			$is_return = $shipment->is_return();

			foreach ( $shipment->documents() as $document ) {
				$parts[] = $this->get_document_link( $document, $is_return, $meta_box_wording );
			}

			foreach ( $shipment->codes() as $code ) {
				$parts[] = sprintf(
					'<label>%1$s: </label>%2$s',
					$this->get_code_label( $code ),
					$this->get_code_html( $code )
				);
			}

			return implode( $separator, $parts );
		}

		/**
		 * The translated name of a document type, as used in the order
		 * note label ("Shipping label: ...").
		 *
		 * @param SS_Shipping_Shipment_Document $document  The document.
		 * @param boolean                       $is_return Whether the shipment is a return shipment.
		 *
		 * @return string
		 */
		protected function get_document_label( SS_Shipping_Shipment_Document $document, $is_return ) {
			switch ( $document->get_type() ) {
				case SS_Shipping_Shipment_Document::TYPE_LABEL:
					return $is_return ? __( 'Return shipping label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' );
				case SS_Shipping_Shipment_Document::TYPE_CUSTOMS_DECLARATION:
					return __( 'Customs declaration', 'smart-send-logistics' );
				case SS_Shipping_Shipment_Document::TYPE_COMMERCIAL_INVOICE:
					return __( 'Commercial invoice', 'smart-send-logistics' );
				case SS_Shipping_Shipment_Document::TYPE_PACKING_LIST:
					return __( 'Packing list', 'smart-send-logistics' );
				default:
					return ucfirst( str_replace( '_', ' ', $document->get_type() ) );
			}
		}

		/**
		 * The download link of a document: the uploads copy when one was
		 * saved, the Smart Send URL otherwise. Label documents keep the
		 * historic link texts (the meta box's shorter "Download return
		 * label" or the order note's "Download return shipping label");
		 * the format is appended when it is not PDF.
		 *
		 * @param SS_Shipping_Shipment_Document $document         The document.
		 * @param boolean                       $is_return        Whether the shipment is a return shipment.
		 * @param boolean                       $meta_box_wording Whether to use the meta box's label link texts.
		 *
		 * @return string HTML link
		 */
		protected function get_document_link( SS_Shipping_Shipment_Document $document, $is_return, $meta_box_wording = false ) {
			switch ( $document->get_type() ) {
				case SS_Shipping_Shipment_Document::TYPE_LABEL:
					if ( $meta_box_wording ) {
						$text = $is_return ? __( 'Download return label', 'smart-send-logistics' ) : __( 'Download shipping label', 'smart-send-logistics' );
					} else {
						$text = $is_return ? __( 'Download return shipping label', 'smart-send-logistics' ) : __( 'Download shipping label', 'smart-send-logistics' );
					}
					break;
				case SS_Shipping_Shipment_Document::TYPE_CUSTOMS_DECLARATION:
					$text = __( 'Download customs declaration', 'smart-send-logistics' );
					break;
				case SS_Shipping_Shipment_Document::TYPE_COMMERCIAL_INVOICE:
					$text = __( 'Download commercial invoice', 'smart-send-logistics' );
					break;
				case SS_Shipping_Shipment_Document::TYPE_PACKING_LIST:
					$text = __( 'Download packing list', 'smart-send-logistics' );
					break;
				default:
					$text = __( 'Download document', 'smart-send-logistics' );
			}

			if ( SS_Shipping_Shipment_Document::FORMAT_PDF !== $document->get_format() ) {
				$text = sprintf( '%1$s (%2$s)', $text, strtoupper( $document->get_format() ) );
			}

			return '<a href="' . $document->download_url() . '" target="_blank">' . $text . '</a>';
		}

		/**
		 * The translated name of a code type ("QR code").
		 *
		 * @param SS_Shipping_Shipment_Code $code The code.
		 *
		 * @return string
		 */
		protected function get_code_label( SS_Shipping_Shipment_Code $code ) {
			switch ( $code->get_type() ) {
				case SS_Shipping_Shipment_Code::TYPE_QR_CODE:
					return __( 'QR code', 'smart-send-logistics' );
				case SS_Shipping_Shipment_Code::TYPE_LABEL_CODE:
					return __( 'Label code', 'smart-send-logistics' );
				default:
					return ucfirst( str_replace( '_', ' ', $code->get_type() ) );
			}
		}

		/**
		 * A code rendered for the merchant: the value, the image when the
		 * API provides one, and the instructions when present.
		 *
		 * @param SS_Shipping_Shipment_Code $code The code.
		 *
		 * @return string HTML
		 */
		protected function get_code_html( SS_Shipping_Shipment_Code $code ) {
			$html = '<strong>' . esc_html( $code->get_value() ) . '</strong>';

			if ( null !== $code->get_image_url() ) {
				$html .= '<br><img src="' . esc_url( $code->get_image_url() ) . '" alt="' . esc_attr( $this->get_code_label( $code ) ) . '">';
			}

			if ( null !== $code->get_instructions() ) {
				$html .= '<br>' . esc_html( $code->get_instructions() );
			}

			return $html;
		}

		/**
		 * The carrier name to show to customers, e.g. as the Shipment
		 * Tracking provider ("PostNord" for the carrier code "postnord").
		 *
		 * @param SS_Shipping_Booked_Shipment $shipment The booked shipment.
		 *
		 * @return string
		 */
		protected function get_carrier_display_name( SS_Shipping_Booked_Shipment $shipment ) {
			$catalog = new SS_Shipping_Method_Catalog();

			return $catalog->get_carrier_name( (string) $shipment->get_carrier() );
		}

		/**
		 * Save label file in "uploads" folder
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- pre-existing method signature, kept for backwards compatibility.
		public function save_label_file( $shipment_id, $label_data, $return ) {
			$copy = $this->save_label_copy( $shipment_id, $label_data, SS_Shipping_Shipment_Document::FORMAT_PDF, '' );

			return $copy['url'];
		}

		/**
		 * Save a label document in the "uploads" folder.
		 *
		 * @param string      $shipment_id The Smart Send shipment id (part of the file name).
		 * @param string|null $label_data  The base64-encoded document bytes.
		 * @param string      $format      The document format (file extension).
		 * @param string      $suffix      A file name suffix distinguishing several documents of one shipment.
		 *
		 * @throws Exception When the id or data is empty, or the file cannot be saved.
		 *
		 * @return array The saved file's 'file' (path) and 'url'.
		 */
		protected function save_label_copy( $shipment_id, $label_data, $format, $suffix ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-existing behaviour: exception messages are returned to the admin AJAX response as data, not printed as HTML; escaping is a behaviour change out of scope for the #43 move.

			if ( empty( $shipment_id ) ) {
				throw new Exception( __( 'Shipment id is empty', 'smart-send-logistics' ) );
			}

			if ( empty( $label_data ) ) {
				throw new Exception( __( 'Label data empty', 'smart-send-logistics' ) );
			}

			$label_data_decoded = base64_decode( $label_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the Smart Send API delivers the label PDF base64-encoded; this is transport decoding, not obfuscation.
			$file_ret           = wp_upload_bits(
				$this->get_label_name_from_shipment_id( $shipment_id . $suffix, $format ),
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

			return array(
				'file' => $file_ret['file'],
				'url'  => $file_ret['url'],
			);
		}

		protected function get_label_name_from_shipment_id( $shipment_id, $format = SS_Shipping_Shipment_Document::FORMAT_PDF ) {
			if ( $this->label_prefix ) {
				$shipment_id = $this->label_prefix . $shipment_id;
			}
			return $shipment_id . '.' . $format;
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
