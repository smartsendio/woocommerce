<?php
/**
 * WooCommerce Smart Send fulfillment service.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Fulfillment;

use Exception;
use Smart_Send\Booking\Booked_Shipment;
use Smart_Send\Booking\Booking_Service;
use Smart_Send\Booking\Exceptions\Booking_Exception;
use Smart_Send\Booking\Shipment_Code;
use Smart_Send\Booking\Shipment_Document;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Parcel_Plan;
use Smart_Send\Delivery\Parcel_Spec;
use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception;
use Smart_Send\Delivery_Options\Pickup_Point_Lookup;
use Smart_Send\Shipping_Method\Method_Catalog;
use Smart_Send\Shipping_Method\Method_Code;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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
 *     return label when asked for ($with_return), defaulting to the
 *     order's shipping method's auto-generate-return-label setting;
 *   fulfill_return( $order, ... )   - a return label on its own.
 *
 * Each leg runs the same steps:
 *
 *   1. Delivery details = the stored order configuration (repository)
 *      + the method resolved from the order's shipping item, with the
 *      details submitted with the request merged on top (a submitted
 *      field wins over the stored/derived one, a submitted method
 *      wins over the resolver - which is how an order placed with a
 *      non-Smart-Send method gets booked; a pickup point submitted as
 *      a bare agent number is resolved through the shared lookup),
 *      passed through the smart_send_delivery_details filter.
 *   2. Booking_Service::book() - ONE operation for both
 *      directions - returns the Booked_Shipment or throws
 *      Booking_Exception, which is caught here and
 *      recorded on the result as structured data (rendered as HTML
 *      for the merchant by format_booking_error()).
 *   3. Side effects on the order, one step each, each with a filter:
 *      the submitted delivery details persisted through the
 *      repository (persist-after-success, #182: a failed booking
 *      leaves the order meta untouched; only what has storage is
 *      written - pickup point and parcel item rows - the method
 *      override and a spec's weight/dimensions are per booking), the
 *      shipment id in meta (no filter), local copy of the documents
 *      (smart_send_fulfillment_save_documents - a copy that cannot be
 *      saved is a warning on the entry, not a failure), order note
 *      (smart_send_fulfillment_order_note), tracking push
 *      (smart_send_fulfillment_tracking), order status
 *      (smart_send_fulfillment_order_status).
 *
 * Once every leg is done, smart_send_order_fulfilled fires once with
 * the order and the complete Fulfillment_Result.
 *
 * Every step reads the typed Booked_Shipment the booking
 * produced - documents, codes and parcels - never an API response, so
 * the rendering never assumes "one PDF": every document gets a
 * download link and every code is shown.
 *
 * The service is stateless and long-lived: constructed once, no
 * per-order state on properties, safe to call for many orders
 * sequentially. It lives in includes/ (not admin/) because Phase 7's
 * async processing will call it outside an admin-screen context.
 */
class Fulfillment_Service {

	/**
	 * Filename prefix for label documents saved in the uploads folder.
	 *
	 * @var string
	 */
	protected string $label_prefix = 'smart-send-label-';

	/**
	 * Order meta repository.
	 *
	 * @var Order_Meta
	 */
	protected Order_Meta $order_meta;

	/**
	 * Shipping method resolver.
	 *
	 * @var Method_Resolver
	 */
	protected Method_Resolver $method_resolver;

	/**
	 * Booked shipment id accessor.
	 *
	 * @var Shipment_IDs
	 */
	protected Shipment_IDs $shipment_ids;

	/**
	 * The booking service (stateless, order passed per call).
	 *
	 * @var Booking_Service
	 */
	protected Booking_Service $booking_service;

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * Pickup point lookup, resolving a submitted agent number into the
	 * full pickup point.
	 *
	 * @var Pickup_Point_Lookup
	 */
	protected Pickup_Point_Lookup $pickup_point_lookup;

	/**
	 * Constructor.
	 *
	 * @param Order_Meta               $order_meta          Order meta repository.
	 * @param Method_Resolver          $method_resolver     Shipping method resolver.
	 * @param Shipment_IDs             $shipment_ids        Booked shipment id accessor.
	 * @param Booking_Service          $booking_service     The booking service.
	 * @param Settings|null            $settings            Typed plugin settings reader (stateless; a fresh default is safe).
	 * @param Pickup_Point_Lookup|null $pickup_point_lookup Pickup point lookup (stateless; a fresh default is safe).
	 */
	public function __construct( Order_Meta $order_meta, Method_Resolver $method_resolver, Shipment_IDs $shipment_ids, Booking_Service $booking_service, ?Settings $settings = null, ?Pickup_Point_Lookup $pickup_point_lookup = null ) {
		$this->order_meta          = $order_meta;
		$this->method_resolver     = $method_resolver;
		$this->shipment_ids        = $shipment_ids;
		$this->booking_service     = $booking_service;
		$this->settings            = null === $settings ? new Settings() : $settings;
		$this->pickup_point_lookup = null === $pickup_point_lookup ? new Pickup_Point_Lookup( $this->settings ) : $pickup_point_lookup;
	}

	/**
	 * Fulfill an outbound (normal) shipping label for the order, and -
	 * when a return label is wanted and the outbound booking succeeded
	 * - the return label too.
	 *
	 * @param int|WC_Order                      $order              Order id or order object.
	 * @param boolean                           $save_order_note    Whether to save an order note with information about the label.
	 * @param Delivery_Details|null $delivery_overrides Partial delivery details submitted with the request (e.g. from the order meta box): a submitted field wins over the stored/derived value, and what has storage (pickup point, parcel item rows) is persisted only after the booking succeeded.
	 * @param boolean|null                      $with_return        Whether to also create the return label: null follows the order's shipping method's auto-generate-return-label setting (what bulk passes), true/false override it. The return leg reads the freshly persisted details and the order's return method; the outbound overrides are per outbound booking.
	 * @param Delivery_Details|null $return_overrides   Partial delivery details for the return leg of the same run (#182): a submitted return method wins over the configured one, which is how an order without a Smart Send method books outbound and return in one run. Null keeps the configured return method.
	 *
	 * @return Fulfillment_Result
	 */
	public function fulfill_outbound( $order, $save_order_note = true, ?Delivery_Details $delivery_overrides = null, ?bool $with_return = null, ?Delivery_Details $return_overrides = null ): Fulfillment_Result {
		$order = $this->resolve_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return new Fulfillment_Result( array( $order ) );
		}

		$entries   = array();
		$entries[] = $this->fulfill_leg( $order, false, $save_order_note, $outbound_booked, $delivery_overrides );

		if ( null === $with_return ) {
			$with_return = $this->method_resolver->is_auto_return_enabled( $order );
		}

		// We're only creating the return label if the outbound BOOKING succeeded.
		// Deliberate behaviour change from the historic flow, which gated on the
		// whole outbound entry: a successful API booking whose local PDF save
		// failed used to silently skip the configured auto-return label; now the
		// return label is still attempted and both outcomes are reported.
		if ( $outbound_booked && $with_return ) {
			$entries[] = $this->fulfill_leg( $order, true, $save_order_note, $return_booked, $return_overrides );
		}

		return $this->finish( $order, new Fulfillment_Result( $entries ) );
	}

	/**
	 * Fulfill a return shipping label for the order.
	 *
	 * @param int|WC_Order                      $order              Order id or order object.
	 * @param boolean                           $save_order_note    Whether to save an order note with information about the label.
	 * @param Delivery_Details|null $delivery_overrides Partial delivery details submitted with the request: a submitted return method wins over the configured one, a submitted pickup point or parcel plan over the stored one; what has storage is persisted only after the booking succeeded.
	 *
	 * @return Fulfillment_Result
	 */
	public function fulfill_return( $order, $save_order_note = true, ?Delivery_Details $delivery_overrides = null ): Fulfillment_Result {
		$order = $this->resolve_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return new Fulfillment_Result( array( $order ) );
		}

		$entries = array( $this->fulfill_leg( $order, true, $save_order_note, $return_booked, $delivery_overrides ) );

		return $this->finish( $order, new Fulfillment_Result( $entries ) );
	}

	/**
	 * The delivery details a label for the order ships with, before
	 * the smart_send_delivery_details filter: the stored order
	 * configuration (pickup point, parcel plan) with the shipping
	 * method resolved from the order's shipping item, and the details
	 * submitted with the request merged on top. An outbound label uses
	 * the order's Smart Send shipping method and keeps the stored
	 * pickup point; a return label uses the configured return method
	 * and drops the pickup point - unless the return method could not
	 * be resolved to the dedicated smart_send_return_method meta
	 * (free-shipping/vConnect orders, see
	 * Method_Resolver::return_uses_stored_pickup_point()),
	 * in which case the stored selection applies like on an outbound
	 * label.
	 *
	 * Submitted overrides win field by field (#182): a submitted
	 * shipping method replaces the resolved one (for a return leg, the
	 * configured return method is then not even resolved - so a
	 * missing return method is no failure when one is submitted), a
	 * submitted pickup point replaces (or, when cleared, removes) the
	 * stored one - and a submitted outbound method that is not a pickup
	 * point method drops the stored point for that booking -, a
	 * submitted parcel plan replaces the stored split,
	 * submitted addons replace the stored ones when non-empty. A pickup
	 * point submitted as a bare agent number that equals the stored
	 * one resolves to the stored point; any other bare agent number is
	 * resolved through Pickup_Point_Lookup::find_by_agent_no()
	 * (carrier from the submitted-or-resolved method, country from the
	 * order's shipping address).
	 *
	 * Public so Phase 7 can queue "order id + details" (#116).
	 *
	 * @param WC_Order                          $order     The WooCommerce order.
	 * @param boolean                           $is_return Whether the label is a return label.
	 * @param Delivery_Details|null $overrides Partial delivery details submitted with the request, or null.
	 *
	 * @throws Booking_Exception                When no return method is configured and none was submitted (from the resolver).
	 * @throws Pickup_Point_Not_Found_Exception When a submitted agent number cannot be resolved into a pickup point.
	 *
	 * @return Delivery_Details
	 */
	public function resolve_delivery_details( WC_Order $order, bool $is_return, ?Delivery_Details $overrides = null ): Delivery_Details {
		$details = $this->order_meta->read( $order->get_id() );
		$stored  = $details->get_pickup_point();

		$submitted_method = null === $overrides ? null : $overrides->get_shipping_method();

		if ( $is_return ) {
			$details->set_shipping_method( null !== $submitted_method ? $submitted_method : $this->method_resolver->resolve_return( $order ) );

			if ( ! $this->method_resolver->return_uses_stored_pickup_point( $order ) ) {
				$details->set_pickup_point( null );
			}
		} else {
			$details->set_shipping_method( null !== $submitted_method ? $submitted_method : $this->method_resolver->resolve_outbound( $order ) );
		}

		if ( null === $overrides ) {
			return $details;
		}

		if ( $overrides->is_pickup_point_cleared() ) {
			$details->set_pickup_point( null );
		} elseif ( null !== $overrides->get_pickup_point() ) {
			$details->set_pickup_point( $this->resolve_submitted_pickup_point( $order, $overrides->get_pickup_point(), $stored, (string) $details->get_shipping_method() ) );
		} elseif ( ! $is_return && null !== $submitted_method && false === stripos( ( new Method_Code( $submitted_method ) )->type(), 'agent' ) ) {
			// A submitted method that is not a pickup point method books
			// without the stored pickup point (#182: the meta box hides
			// it); per booking only - nothing is persisted for it.
			$details->set_pickup_point( null );
		}

		if ( null !== $overrides->get_parcel_plan() ) {
			$details->set_parcel_plan( $overrides->get_parcel_plan() );
		}

		if ( array() !== $overrides->get_addons() ) {
			$details->set_addons( $overrides->get_addons() );
		}

		return $details;
	}

	/**
	 * Resolve a submitted pickup point: a full point is used as
	 * submitted; a bare agent number (see
	 * Pickup_Point::is_agent_no_only()) resolves to the
	 * stored point when the numbers match, and through the API lookup
	 * otherwise.
	 *
	 * @param WC_Order                      $order     The WooCommerce order.
	 * @param Pickup_Point      $submitted The submitted pickup point.
	 * @param Pickup_Point|null $stored    The pickup point stored on the order, if any.
	 * @param string                        $method    The Smart Send method the leg books with (the carrier for the lookup).
	 *
	 * @throws Pickup_Point_Not_Found_Exception When the agent number cannot be resolved.
	 *
	 * @return Pickup_Point
	 */
	protected function resolve_submitted_pickup_point( WC_Order $order, Pickup_Point $submitted, ?Pickup_Point $stored, string $method ): Pickup_Point {
		if ( ! $submitted->is_agent_no_only() ) {
			return $submitted;
		}

		$agent_no = (string) $submitted->get_agent_no();

		if ( null !== $stored && (string) $stored->get_agent_no() === $agent_no ) {
			return $stored;
		}

		$carrier = '' === $method ? '' : ( new Method_Code( $method ) )->carrier();

		if ( '' === $carrier ) {
			// Without a method there is no carrier to look the number up
			// for; the builder rejects the booking for the missing method.
			return $submitted;
		}

		try {
			$pickup_point = $this->pickup_point_lookup->find_by_agent_no( $carrier, (string) $order->get_shipping_country(), $agent_no );
		} catch ( Pickup_Point_Not_Found_Exception $e ) {
			Logger::warning(
				'Pickup point not found - agent number rejected',
				array(
					'order_id' => $order->get_id(),
					'agent_no' => $agent_no,
					'carrier'  => $carrier,
				)
			);

			throw $e;
		}

		Logger::info(
			'Pickup point changed on order',
			array(
				'order_id' => $order->get_id(),
				'agent_no' => $agent_no,
				'carrier'  => $carrier,
			)
		);

		return $pickup_point;
	}

	/**
	 * The part of the submitted details that gets persisted once the
	 * booking succeeded: the submitted pickup point as resolved (the
	 * full point, never a bare agent number) or its clearing, and the
	 * submitted parcel plan (the repository stores its item rows only).
	 * The shipping method and addons are not stored by the repository,
	 * so they are per booking by construction.
	 *
	 * @param Delivery_Details $overrides The submitted details.
	 * @param Delivery_Details $merged    The merged details the leg booked with.
	 *
	 * @return Delivery_Details
	 */
	protected function persistable_overrides( Delivery_Details $overrides, Delivery_Details $merged ): Delivery_Details {
		$persist = new Delivery_Details();

		if ( $overrides->is_pickup_point_cleared() ) {
			$persist->clear_pickup_point();
		} elseif ( null !== $overrides->get_pickup_point() ) {
			$persist->set_pickup_point( $merged->get_pickup_point() );
		}

		$persist->set_parcel_plan( $overrides->get_parcel_plan() );

		return $persist;
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

		return Fulfillment_Result::failed_entry(
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
	 * @param Fulfillment_Result $result The completed run.
	 *
	 * @return Fulfillment_Result The same result, for chaining.
	 */
	protected function finish( WC_Order $order, Fulfillment_Result $result ): Fulfillment_Result {
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
			 * @param Fulfillment_Result $result The whole fulfillment run.
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
	 * On a failed booking nothing is written - not even the submitted
	 * delivery details - and a failed entry is returned; $booked
	 * reports whether the API booked the shipment (the auto-return
	 * gate), independent of the side effects.
	 *
	 * @param WC_Order                          $order           The WooCommerce order.
	 * @param boolean                           $is_return       Whether the label is a return label.
	 * @param boolean                           $save_order_note Whether to save an order note with information about the label.
	 * @param boolean                           $booked          Set to whether the booking itself succeeded.
	 * @param Delivery_Details|null $overrides       Partial delivery details submitted with the request, or null.
	 *
	 * @return array A run entry in the shape Fulfillment_Result documents.
	 */
	protected function fulfill_leg( WC_Order $order, bool $is_return, $save_order_note, &$booked = null, ?Delivery_Details $overrides = null ): array {
		$booked = false;

		try {
			try {
				$details = $this->resolve_delivery_details( $order, $is_return, $overrides );
			} catch ( Pickup_Point_Not_Found_Exception $e ) {
				// A submitted agent number the API does not know: a
				// structured field error on the leg, in the v1 field
				// vocabulary the booking validation errors use.
				return Fulfillment_Result::failed_entry(
					$is_return,
					$e->getMessage(),
					'',
					array( 'agent_no' => array( $e->getMessage() ) )
				);
			}

			$persist = null === $overrides ? null : $this->persistable_overrides( $overrides, $details );

			/*
			 * Filter the delivery details a shipping label is booked
			 * with, after the stored configuration and the resolved
			 * method have been merged and before booking is called.
			 * One typed extension point for everything Smart Send knows
			 * about how the order ships: override the shipping method,
			 * clear or replace the pickup point (Pickup_Point),
			 * or declare a parcel plan (Parcel_Plan of
			 * Parcel_Spec rows - specs may carry dimensions
			 * and an explicit weight with no item allocations at all).
			 *
			 * Replaces the removed smart_send_shipping_label_args,
			 * smart_send_order_parcels, smart_send_order_pickup_point and
			 * smart_send_parcel_weight filters (v9).
			 *
			 * @since 9.0.0
			 *
			 * @param Delivery_Details $details   The merged delivery details.
			 * @param WC_Order                     $order     The WooCommerce order.
			 * @param boolean                      $is_return Whether the label is a return label.
			 *
			 * @return Delivery_Details The delivery details to book with.
			 */
			$details = apply_filters( 'smart_send_delivery_details', $details, $order, $is_return );

			$shipment = $this->booking_service->book( $order, $details, $is_return );
		} catch ( Booking_Exception $e ) {
			// The booking failed. Record the error as data, so it can be shown to the user.
			return Fulfillment_Result::failed_entry( $is_return, $e->getMessage(), $this->format_booking_error( $e ), $e->errors(), $e->response_id() );
		}

		$booked = true;

		return $this->apply_side_effects( $order, $shipment, $save_order_note, $persist );
	}

	/**
	 * Write a booked shipment onto the order, one step at a time. Step
	 * 0 persists the submitted delivery details (#182): the merchant's
	 * configuration is stored only once the booking it was submitted
	 * for succeeded, before the shipment id.
	 *
	 * @param WC_Order                          $order           The WooCommerce order.
	 * @param Booked_Shipment       $shipment        The booked shipment.
	 * @param boolean                           $save_order_note Whether the caller wants the order note saved (the REST response then carries the note the meta box prepends client-side).
	 * @param Delivery_Details|null $persist         The submitted delivery details to persist (see persistable_overrides()), or null when the request carried none.
	 *
	 * @return array A run entry in the shape Fulfillment_Result documents.
	 */
	protected function apply_side_effects( WC_Order $order, Booked_Shipment $shipment, $save_order_note, ?Delivery_Details $persist = null ): array {
		$order_id     = $order->get_id();
		$is_return    = $shipment->is_return();
		$carrier_name = $this->get_carrier_display_name( $shipment );
		$warnings     = array();

		if ( null !== $persist ) {
			$this->order_meta->write( $order, $persist );
		}

		/*
		 * Filter whether a local copy of the shipment's documents is
		 * saved in the uploads folder. Defaults to the "save shipping
		 * labels in uploads" setting.
		 *
		 * @since 9.0.0
		 *
		 * @param boolean                     $save_documents Whether to store the local copy.
		 * @param Booked_Shipment $shipment       The booked shipment.
		 * @param WC_Order                    $order          The WooCommerce order.
		 */
		$save_documents = (bool) apply_filters( 'smart_send_fulfillment_save_documents', $this->settings->save_labels_in_uploads(), $shipment, $order );

		$save_documents_step = $save_documents;

		if ( $save_documents ) {
			try {
				// Save the label document(s) and link the local copy.
				// Deliberate v9 fix (#139): the computed uploads URL used
				// to be discarded (unconditionally overwritten with the
				// API link); with the setting enabled, the note/response
				// now actually link the uploads copy.
				$this->store_uploads_copies( $shipment );
			} catch ( Exception $e ) {
				// Deliberate behaviour change (#182): the shipment IS
				// booked at Smart Send, so a copy that cannot be saved
				// no longer turns the leg into a failure. The documents
				// keep their Smart Send URL (download_url() falls back
				// to it) and the merchant is warned.
				$save_documents_step = 'failed';
				$warnings[]          = sprintf(
					/* translators: %s: the reason the local copy could not be saved. */
					__( 'Label booked; the local copy could not be saved: %s', 'smart-send-logistics' ),
					$e->getMessage()
				);

				Logger::warning(
					'Label booked but the uploads copy could not be saved',
					array(
						'order_id'    => $order_id,
						'shipment_id' => $shipment->get_shipment_id(),
						'reason'      => $e->getMessage(),
					)
				);
			}
		}

		// save order meta data
		$this->shipment_ids->save_booked( $order, $shipment );

		Logger::info(
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
		 * @param Booked_Shipment $shipment   The booked shipment.
		 * @param WC_Order                    $order      The WooCommerce order.
		 */
		$order_note = (string) apply_filters(
			'smart_send_fulfillment_order_note',
			$this->get_formatted_order_note_with_label_and_tracking( $shipment ),
			$shipment,
			$order
		);

		$note_added = false;
		$note_id    = null;
		if ( $save_order_note && '' !== $order_note ) {
			$added      = $order->add_order_note( $order_note, 0, true );
			$note_added = true;
			$note_id    = $added > 0 ? (int) $added : null;

			Logger::info( 'Order note with label and tracking added', array( 'order_id' => $order_id ) );
		}

		/*
		 * Filter whether the parcels' tracking numbers are pushed to the
		 * WooCommerce Shipment Tracking plugin. Defaults to true for an
		 * outbound shipment and false for a return shipment.
		 *
		 * @since 9.0.0
		 *
		 * @param boolean                     $push_tracking Whether to push tracking.
		 * @param Booked_Shipment $shipment      The booked shipment.
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

				Logger::info(
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
		 * @param Booked_Shipment $shipment     The booked shipment.
		 * @param WC_Order                    $order        The WooCommerce order.
		 */
		$order_status = apply_filters( 'smart_send_fulfillment_order_status', $this->default_order_status( $is_return ), $shipment, $order );

		if ( is_string( $order_status ) && '' !== $order_status ) {
			$order->update_status( $order_status );
		} else {
			$order_status = false;
		}

		return Fulfillment_Result::fulfilled_entry(
			$shipment,
			$order_note,
			array(
				'save_documents' => $save_documents_step,
				'order_note'     => $note_added,
				'tracking'       => $push_tracking,
				'order_status'   => $order_status,
			),
			$note_id,
			$warnings
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
	 * @param Booked_Shipment $shipment The booked shipment.
	 *
	 * @throws Exception When a label document cannot be saved.
	 *
	 * @return void
	 */
	protected function store_uploads_copies( Booked_Shipment $shipment ) {
		foreach ( $shipment->documents() as $index => $document ) {
			if ( Shipment_Document::TYPE_LABEL !== $document->get_type() ) {
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
	 * @param Booking_Exception $e The failure.
	 *
	 * @return string
	 */
	protected function format_booking_error( Booking_Exception $e ): string {
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
	 * @param Booked_Shipment $shipment The booked shipment.
	 *
	 * @return string HTML formatted note
	 */
	protected function get_formatted_order_note_with_label_and_tracking( Booked_Shipment $shipment ) {
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
	 * @param Booked_Shipment $shipment         The booked shipment.
	 * @param string                      $separator        Separator between the rendered outputs.
	 * @param boolean                     $meta_box_wording Whether to use the meta box's link texts (default) or the order note's.
	 *
	 * @return string
	 */
	public function get_shipment_outputs_html( Booked_Shipment $shipment, $separator = '<br>', $meta_box_wording = true ) {
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
	 * @param Shipment_Document $document  The document.
	 * @param boolean                       $is_return Whether the shipment is a return shipment.
	 *
	 * @return string
	 */
	protected function get_document_label( Shipment_Document $document, $is_return ) {
		switch ( $document->get_type() ) {
			case Shipment_Document::TYPE_LABEL:
				return $is_return ? __( 'Return shipping label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' );
			case Shipment_Document::TYPE_CUSTOMS_DECLARATION:
				return __( 'Customs declaration', 'smart-send-logistics' );
			case Shipment_Document::TYPE_COMMERCIAL_INVOICE:
				return __( 'Commercial invoice', 'smart-send-logistics' );
			case Shipment_Document::TYPE_PACKING_LIST:
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
	 * @param Shipment_Document $document         The document.
	 * @param boolean                       $is_return        Whether the shipment is a return shipment.
	 * @param boolean                       $meta_box_wording Whether to use the meta box's label link texts.
	 *
	 * @return string HTML link
	 */
	protected function get_document_link( Shipment_Document $document, $is_return, $meta_box_wording = false ) {
		switch ( $document->get_type() ) {
			case Shipment_Document::TYPE_LABEL:
				if ( $meta_box_wording ) {
					$text = $is_return ? __( 'Download return label', 'smart-send-logistics' ) : __( 'Download shipping label', 'smart-send-logistics' );
				} else {
					$text = $is_return ? __( 'Download return shipping label', 'smart-send-logistics' ) : __( 'Download shipping label', 'smart-send-logistics' );
				}
				break;
			case Shipment_Document::TYPE_CUSTOMS_DECLARATION:
				$text = __( 'Download customs declaration', 'smart-send-logistics' );
				break;
			case Shipment_Document::TYPE_COMMERCIAL_INVOICE:
				$text = __( 'Download commercial invoice', 'smart-send-logistics' );
				break;
			case Shipment_Document::TYPE_PACKING_LIST:
				$text = __( 'Download packing list', 'smart-send-logistics' );
				break;
			default:
				$text = __( 'Download document', 'smart-send-logistics' );
		}

		if ( Shipment_Document::FORMAT_PDF !== $document->get_format() ) {
			$text = sprintf( '%1$s (%2$s)', $text, strtoupper( $document->get_format() ) );
		}

		return '<a href="' . $document->download_url() . '" target="_blank">' . $text . '</a>';
	}

	/**
	 * The translated name of a code type ("QR code").
	 *
	 * @param Shipment_Code $code The code.
	 *
	 * @return string
	 */
	protected function get_code_label( Shipment_Code $code ) {
		switch ( $code->get_type() ) {
			case Shipment_Code::TYPE_QR_CODE:
				return __( 'QR code', 'smart-send-logistics' );
			case Shipment_Code::TYPE_LABEL_CODE:
				return __( 'Label code', 'smart-send-logistics' );
			default:
				return ucfirst( str_replace( '_', ' ', $code->get_type() ) );
		}
	}

	/**
	 * A code rendered for the merchant: the value, the image when the
	 * API provides one, and the instructions when present.
	 *
	 * @param Shipment_Code $code The code.
	 *
	 * @return string HTML
	 */
	protected function get_code_html( Shipment_Code $code ) {
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
	 * @param Booked_Shipment $shipment The booked shipment.
	 *
	 * @return string
	 */
	protected function get_carrier_display_name( Booked_Shipment $shipment ) {
		$catalog = new Method_Catalog();

		return $catalog->get_carrier_name( (string) $shipment->get_carrier() );
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
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-existing behaviour: exception messages are recorded on the result as data, not printed as HTML; escaping is a behaviour change out of scope for the #43 move.

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

	protected function get_label_name_from_shipment_id( $shipment_id, $format = Shipment_Document::FORMAT_PDF ) {
		if ( $this->label_prefix ) {
			$shipment_id = $this->label_prefix . $shipment_id;
		}
		return $shipment_id . '.' . $format;
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
