<?php
/**
 * WooCommerce Smart Send booked shipment id accessor.
 *
 * @package  SS_Shipping_Shipment_Ids
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment_Ids' ) ) :

	/**
	 * Reads and writes the booked shipment ids of an order (#139).
	 *
	 * Shipment ids are the OUTCOME of fulfillment, not delivery
	 * configuration, so they deliberately live outside
	 * SS_Shipping_Delivery_Details and its repository - a small distinct
	 * accessor in the same file family. The meta keys (and their
	 * booking-outcome classification, which keeps them off subscription
	 * renewal orders) stay defined on SS_Shipping_Order_Meta.
	 */
	class SS_Shipping_Shipment_Ids {

		/**
		 * Save a booked shipment id on the order.
		 *
		 * @param integer|WC_Order $order       Order (or order id).
		 * @param string           $shipment_id The Smart Send shipment id.
		 * @param boolean          $is_return   Whether the label is a return label.
		 *
		 * @return void
		 */
		public function save( $order, $shipment_id, $is_return ) {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$order->update_meta_data(
				$is_return ? SS_Shipping_Order_Meta::META_RETURN_LABEL_ID : SS_Shipping_Order_Meta::META_LABEL_ID,
				$shipment_id
			);
			$order->save();
		}

		/**
		 * Get the booked shipment id of the order, if any.
		 *
		 * @param integer|WC_Order $order     Order (or order id).
		 * @param boolean          $is_return Whether to read the return label's shipment id.
		 *
		 * @return string The shipment id, or '' when none is stored (or the order cannot be loaded).
		 */
		public function get( $order, $is_return ) {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
			if ( ! $order instanceof WC_Order ) {
				return '';
			}

			return (string) $order->get_meta(
				$is_return ? SS_Shipping_Order_Meta::META_RETURN_LABEL_ID : SS_Shipping_Order_Meta::META_LABEL_ID,
				true
			);
		}
	}

endif;
