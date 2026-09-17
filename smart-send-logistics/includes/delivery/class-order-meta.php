<?php

namespace Smart_Send\Delivery;

use Smart_Send\Delivery_Options\Pickup_Point_Validator;
use Smart_Send\Fulfillment\Shipment_IDs;
use Smart_Send\Support\Logger;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send order meta repository.
 *
 * The single reader/writer of the Smart-Send-owned order meta (#139):
 * read() materializes the stored delivery configuration into a
 * Delivery_Details value object, write() persists a
 * (possibly partial) details object back - a null field means "not
 * specified, leave the stored value alone".
 *
 * The meta KEYS and their stored formats are the frozen public contract
 * (documented in readme.txt's Developers section): the pickup point is a
 * plain agent object under _ss_shipping_order_agent plus its number
 * under ss_shipping_order_agent_no, and the parcel plan is the
 * "Split into parcels" rows (id/name/value, one row per unit) under
 * ss_shipping_order_parcels. Booked shipment ids are fulfillment
 * OUTCOMES, not delivery configuration - their accessor is the separate
 * Shipment_IDs, but their keys stay classified here so the
 * subscription-renewal exclusion has one vocabulary.
 *
 * Non-CRUD logic that historically lived here has moved out: shipping
 * method resolution to Method_Resolver, agent-number
 * validation and its admin hooks to Pickup_Point_Validator.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Order_Meta {

	/**
	 * Meta key holding the outbound shipment id after a label is booked.
	 */
	const META_LABEL_ID = '_ss_shipping_label_id';

	/**
	 * Meta key holding the return shipment id after a return label is booked.
	 */
	const META_RETURN_LABEL_ID = '_ss_shipping_return_label_id';

	/**
	 * Meta key holding the append-only list of the labels this plugin
	 * booked for the order - one row per booked shipment, in booking
	 * order:
	 *
	 *   array( array( 'direction' => 'outbound'|'return',
	 *                 'shipment_id' => string,
	 *                 'booked_at' => string (ISO 8601, UTC) ), ... )
	 *
	 * The two frozen id keys above keep holding the LATEST id per
	 * direction (a documented public contract); this key is what the
	 * order screen's "Booked shipments" timeline renders, and it is
	 * capped (see Shipment_IDs::MAX_LABELS) so a
	 * pathological order cannot grow its meta without limit.
	 */
	const META_LABELS = '_ss_shipping_labels';

	/**
	 * Meta key holding the selected pickup point (agent) object.
	 */
	const META_AGENT = '_ss_shipping_order_agent';

	/**
	 * Meta key holding the selected pickup point agent number.
	 */
	const META_AGENT_NO = 'ss_shipping_order_agent_no';

	/**
	 * Meta key holding the parcel split entered in the order meta box.
	 */
	const META_PARCELS = 'ss_shipping_order_parcels';

	/**
	 * Whether a write() is in progress. Programmatic repository writes
	 * carry a complete, typed pickup point and must not be re-validated
	 * by the Custom Fields validator, whose legacy-storage hooks
	 * (update_post_metadata_by_mid / deleted_post_meta) fire for every
	 * post-meta update WooCommerce makes on save() (#182).
	 *
	 * @var bool
	 */
	protected static bool $writing = false;

	/**
	 * Whether the repository is currently persisting delivery details
	 * (see Pickup_Point_Validator, which skips its meta
	 * hooks for such writes).
	 *
	 * @return boolean
	 */
	public static function is_writing(): bool {
		return self::$writing;
	}

	/**
	 * Meta keys recording a booked-label outcome (shipment ids and the
	 * list of booked labels).
	 *
	 * These are per-order booking results and must NOT copy to
	 * subscription renewal orders - a renewal gets its own label.
	 *
	 * @return string[]
	 */
	public static function booking_outcome_meta_keys() {
		return array(
			self::META_LABEL_ID,
			self::META_RETURN_LABEL_ID,
			self::META_LABELS,
		);
	}

	/**
	 * Meta keys describing the delivery configuration (pickup point and
	 * parcel structure).
	 *
	 * These SHOULD copy to subscription renewal orders - a renewal ships
	 * the same way as its parent (same pickup point, same parcel setup);
	 * it just has not been booked yet.
	 *
	 * @return string[]
	 */
	public static function delivery_configuration_meta_keys() {
		return array(
			self::META_AGENT,
			self::META_AGENT_NO,
			self::META_PARCELS,
		);
	}

	/**
	 * Every order meta key Smart Send writes: the union of the booking
	 * outcome and delivery configuration lists.
	 *
	 * A new META_* constant must be classified into exactly one of those
	 * two lists (the integration test pinning the Subscriptions renewal
	 * exclusion enforces this via reflection).
	 *
	 * @return string[]
	 */
	public static function all_meta_keys() {
		return array_merge(
			self::booking_outcome_meta_keys(),
			self::delivery_configuration_meta_keys()
		);
	}

	/**
	 * Read the stored delivery configuration of an order.
	 *
	 * The pickup point falls back to the vConnect plugin's
	 * _vc_aio_options meta when Smart Send stored none (historic
	 * vConnect interoperability); the parcel plan is null when the
	 * order has no stored split.
	 *
	 * @param integer|WC_Order $order Order (or order id).
	 *
	 * @return Delivery_Details Empty details when the order cannot be loaded.
	 */
	public function read( $order ): Delivery_Details {
		$details = new Delivery_Details();

		// wc_get_order() returns false when the order does not exist, e.g. when
		// WooCommerce's email preview fires the order-details hooks with a
		// placeholder order ID. See issue #60.
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return $details;
		}

		$details->set_pickup_point( $this->read_pickup_point( $order ) );

		$parcel_rows = $order->get_meta( self::META_PARCELS, true );
		if ( ! empty( $parcel_rows ) && is_array( $parcel_rows ) ) {
			$details->set_parcel_plan( Parcel_Plan::from_box_rows( $parcel_rows ) );
		}

		return $details;
	}

	/**
	 * Persist a (possibly partial) delivery configuration on an order.
	 *
	 * A null pickup point / parcel plan means "not specified" and
	 * leaves the stored meta alone; an explicitly cleared pickup point
	 * (Delivery_Details::clear_pickup_point()) deletes the
	 * stored pickup point meta; an empty parcel plan clears the stored
	 * split (one parcel containing everything). The resolved shipping
	 * method and addons are derived data and are not stored, and a
	 * parcel spec's weight and dimensions are per booking only - the
	 * frozen box rows carry item allocations alone (#182).
	 *
	 * @param integer|WC_Order            $order   Order (or order id).
	 * @param Delivery_Details $details The delivery configuration to persist.
	 *
	 * @return void
	 */
	public function write( $order, Delivery_Details $details ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$changed = false;

		$pickup_point = $details->get_pickup_point();
		if ( null !== $pickup_point ) {
			$order->update_meta_data( self::META_AGENT, $pickup_point->to_object() );
			$order->update_meta_data( self::META_AGENT_NO, $pickup_point->get_agent_no() );
			$changed = true;
		} elseif ( $details->is_pickup_point_cleared() ) {
			$order->delete_meta_data( self::META_AGENT );
			$order->delete_meta_data( self::META_AGENT_NO );
			$changed = true;
		}

		$parcel_plan = $details->get_parcel_plan();
		if ( null !== $parcel_plan ) {
			$order->update_meta_data( self::META_PARCELS, $parcel_plan->to_box_rows() );
			$changed = true;
		}

		if ( $changed ) {
			self::$writing = true;
			try {
				$order->save();
			} finally {
				self::$writing = false;
			}
		}
	}

	/**
	 * Store the raw pickup point (agent) object delivered by the Smart
	 * Send API on the order, exactly as received - the write path of
	 * the agent-number validation (Pickup_Point_Validator),
	 * which deliberately leaves the agent-number meta to the admin
	 * meta edit that triggered it.
	 *
	 * @param integer|WC_Order $order Order (or order id).
	 * @param object           $agent The agent object from the API.
	 *
	 * @return void
	 */
	public function store_pickup_point_object( $order, $agent ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( self::META_AGENT, $agent );
		$order->save();
	}

	/**
	 * Delete the stored pickup point (agent) object.
	 *
	 * Persist the companion deletion on both storage backends. Leave
	 * the number row for the WordPress/WooCommerce handler that invoked
	 * the cascade; deleting it early would make that handler fail.
	 *
	 * @param integer|WC_Order $order Order (or order id).
	 *
	 * @return void
	 */
	public function delete_pickup_point( $order ) {
		$order_id = $order instanceof WC_Order ? $order->get_id() : $order;
		$order    = $order instanceof WC_Order ? $order : wc_get_order( $order );

		// There are situations where the order has been deleted and cannot be found.
		// We should gracefully handle this situation of failing to load the order.
		if ( ! $order ) {
			Logger::error( 'Failed to load WooCommerce order when deleting pickup point meta - skipping', array( 'order_id' => $order_id ) );

			return;
		}

		$order->delete_meta_data( self::META_AGENT );
		$order->save_meta_data();
	}

	/**
	 * Build the pickup point value object from the stored agent
	 * object, falling back to the vConnect _vc_aio_options meta.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return Pickup_Point|null
	 */
	protected function read_pickup_point( WC_Order $order ) {
		$ss_agent_info = $order->get_meta( self::META_AGENT, true );
		if ( $ss_agent_info ) {
			return Pickup_Point::from_object( (object) $ss_agent_info );
		}

		// No Smart Send agent was found, check if the order has a vConnect agent
		$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
		if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
			return Pickup_Point::from_object(
				(object) array(
					'agent_no'      => isset( $vc_aio_meta['addressId']['value'] ) ? $vc_aio_meta['addressId']['value'] : null,
					'company'       => isset( $vc_aio_meta['name']['value'] ) ? $vc_aio_meta['name']['value'] : null,
					'address_line1' => isset( $vc_aio_meta['addressText']['value'] ) ? $vc_aio_meta['addressText']['value'] : null,
					'address_line2' => null,
					'city'          => isset( $vc_aio_meta['city']['value'] ) ? $vc_aio_meta['city']['value'] : null,
					'postal_code'   => isset( $vc_aio_meta['postcode']['value'] ) ? $vc_aio_meta['postcode']['value'] : null,
					'country'       => isset( $vc_aio_meta['country']['value'] ) ? $vc_aio_meta['country']['value'] : null,
				)
			);
		}

		return null;
	}
}
