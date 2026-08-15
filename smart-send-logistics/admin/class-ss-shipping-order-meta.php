<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send order meta repository.
 *
 * The single reader/writer of the Smart-Send-owned order meta (#139):
 * read() materializes the stored delivery configuration into a
 * SS_Shipping_Delivery_Details value object, write() persists a
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
 * SS_Shipping_Shipment_Ids, but their keys stay classified here so the
 * subscription-renewal exclusion has one vocabulary.
 *
 * Non-CRUD logic that historically lived here has moved out: shipping
 * method resolution to SS_Shipping_Method_Resolver, agent-number
 * validation and its admin hooks to SS_Shipping_Pickup_Point_Validator.
 *
 * @package  SS_Shipping_Order_Meta
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Meta' ) ) :

	class SS_Shipping_Order_Meta {

		/**
		 * Meta key holding the outbound shipment id after a label is booked.
		 */
		const META_LABEL_ID = '_ss_shipping_label_id';

		/**
		 * Meta key holding the return shipment id after a return label is booked.
		 */
		const META_RETURN_LABEL_ID = '_ss_shipping_return_label_id';

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
		 * Meta keys recording a booked-label outcome (shipment ids).
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
		 * @return SS_Shipping_Delivery_Details Empty details when the order cannot be loaded.
		 */
		public function read( $order ): SS_Shipping_Delivery_Details {
			$details = new SS_Shipping_Delivery_Details();

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
				$details->set_parcel_plan( SS_Shipping_Parcel_Plan::from_box_rows( $parcel_rows ) );
			}

			return $details;
		}

		/**
		 * Persist a (possibly partial) delivery configuration on an order.
		 *
		 * A null pickup point / parcel plan means "not specified" and
		 * leaves the stored meta alone; an empty parcel plan clears the
		 * stored split (one parcel containing everything). The resolved
		 * shipping method and addons are derived data and are not stored.
		 *
		 * @param integer|WC_Order            $order   Order (or order id).
		 * @param SS_Shipping_Delivery_Details $details The delivery configuration to persist.
		 *
		 * @return void
		 */
		public function write( $order, SS_Shipping_Delivery_Details $details ) {
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
			}

			$parcel_plan = $details->get_parcel_plan();
			if ( null !== $parcel_plan ) {
				$order->update_meta_data( self::META_PARCELS, $parcel_plan->to_box_rows() );
				$changed = true;
			}

			if ( $changed ) {
				$order->save();
			}
		}

		/**
		 * Store the raw pickup point (agent) object delivered by the Smart
		 * Send API on the order, exactly as received - the write path of
		 * the agent-number validation (SS_Shipping_Pickup_Point_Validator),
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
		 * v8 oddity, preserved: the deletion is applied to the order
		 * object but not explicitly saved - whether a subsequent read
		 * still sees the agent differs per storage backend (the HPOS
		 * order cache shares the instance, legacy storage reloads).
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
				SS_Shipping_Logger::error( 'Failed to load WooCommerce order when deleting pickup point meta - skipping', array( 'order_id' => $order_id ) );

				return;
			}

			$order->delete_meta_data( self::META_AGENT );
		}

		/**
		 * Saves the parcels input to post_meta
		 *
		 * TEMPORARY (#139): legacy accessor kept for one refactoring stage;
		 * the builder/label-creator rework replaces it with write().
		 *
		 * @param int $order_id
		 * @param array $parcels
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_parcels( $order_id, $parcels ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( self::META_PARCELS, $parcels );
			$order->save();
		}

		/**
		 * Gets parcels input from post_meta
		 *
		 * TEMPORARY (#139): legacy accessor kept for one refactoring stage;
		 * the builder/label-creator rework replaces it with read().
		 *
		 * @param int $order_id
		 *
		 * @return mixed Parcels if present, false otherwise
		 */
		public function get_ss_shipping_order_parcels( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
			return $order->get_meta( self::META_PARCELS, true );
		}

		/**
		 * Saves the label agent no to post_meta.
		 *
		 * TEMPORARY (#139): legacy accessor kept for one refactoring stage;
		 * the frontend rework replaces it with write().
		 *
		 * @param int $order_id Order ID
		 * @param array $agent_no Agent No.
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_agent_no( $order_id, $agent_no ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( self::META_AGENT_NO, $agent_no );
			$order->save();
		}

		/*
		 * Gets agent no from the post meta array for an order
		 *
		 * TEMPORARY (#139): legacy accessor kept for one refactoring stage;
		 * the frontend/meta-box rework replaces it with read().
		 *
		 * @param int  $order_id  Order ID
		 *
		 * @return Agent No
		 */
		public function get_ss_shipping_order_agent_no( $order_id ) {
			// Fetch agent_no from meta field saved by Smart Send
			$order = wc_get_order( $order_id );

			// wc_get_order() returns false when the order does not exist, e.g. when
			// WooCommerce's email preview fires the order-details hooks with a
			// placeholder order ID. See issue #60.
			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$ss_agent_number = $order->get_meta( self::META_AGENT_NO, true );
			if ( $ss_agent_number ) {
				// Return the agent_no found
				return $ss_agent_number;
			} else {
				// No Smart Send agent_no was found, check if the order has a vConnect agent_no
				$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
				if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
					return $vc_aio_meta['addressId']['value'];
				} else {
					return null;
				}
			}
		}

		/**
		 * Saves the agent object to post_meta.
		 *
		 * TEMPORARY (#139): legacy accessor kept for one refactoring stage;
		 * the frontend rework replaces it with write().
		 *
		 * @param int $order_id Order ID
		 * @param array $agent Agent Object
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_agent( $order_id, $agent ) {
			$this->store_pickup_point_object( $order_id, $agent );
		}

		/*
		 * Gets agent object from the post meta array for an order
		 *
		 * TEMPORARY (#139): legacy accessor kept for one refactoring stage;
		 * the builder/meta-box rework replaces it with read().
		 *
		 * @param int  $order_id  Order ID
		 *
		 * @return Agent Object
		 */
		public function get_ss_shipping_order_agent( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$pickup_point = $this->read_pickup_point( $order );

			return null === $pickup_point ? null : $pickup_point->to_object();
		}

		/**
		 * Build the pickup point value object from the stored agent
		 * object, falling back to the vConnect _vc_aio_options meta.
		 *
		 * @param WC_Order $order The order.
		 *
		 * @return SS_Shipping_Pickup_Point|null
		 */
		protected function read_pickup_point( WC_Order $order ) {
			$ss_agent_info = $order->get_meta( self::META_AGENT, true );
			if ( $ss_agent_info ) {
				return SS_Shipping_Pickup_Point::from_object( (object) $ss_agent_info );
			}

			// No Smart Send agent was found, check if the order has a vConnect agent
			$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
			if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
				return SS_Shipping_Pickup_Point::from_object(
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

endif;
