<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Subscriptions compatibility.
 *
 * Prevents the Smart Send shipping label meta from being copied onto
 * subscription renewal orders - a renewal must get its own label, not
 * inherit the parent order's.
 *
 * The WooCommerce Subscriptions plugin is optional, so the filter name
 * used to hook this in depends on which version (if any) is active. This
 * class owns that guard and registers itself on the correct filter.
 *
 * @package  SS_Shipping_Subscriptions_Compat
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Subscriptions_Compat' ) ) :

	class SS_Shipping_Subscriptions_Compat {

		/**
		 * Register the renewal-meta-query filter on the hook name matching
		 * the active WooCommerce Subscriptions version, if any.
		 *
		 * @return void
		 */
		public function register_hooks() {
			// The WooCommerce Subscriptions plugin is optional.
			$subs_version = class_exists( 'WC_Subscriptions' ) && ! empty( WC_Subscriptions::$version ) ? WC_Subscriptions::$version : null;

			// Prevent data being copied to subscriptions.
			if ( null !== $subs_version && version_compare( $subs_version, '2.0.0', '>=' ) ) {
				add_filter( 'wcs_renewal_order_meta_query', array( $this, 'woocommerce_subscriptions_renewal_order_meta_query' ), 10 );
			} else {
				add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'woocommerce_subscriptions_renewal_order_meta_query' ), 10 );
			}
		}

		/**
		 * Prevents booked-label outcomes being copied to subscription renewals.
		 *
		 * Excludes only the booking-outcome meta keys (the outbound and
		 * return shipment ids) - a renewal must get its own label. The
		 * delivery-configuration meta (pickup point agent object and number,
		 * parcel split) deliberately copies through: a renewal ships the same
		 * way as its parent, it just has not been booked yet. The list is
		 * built from the meta-key classification on SS_Shipping_Order_Meta
		 * so it cannot drift from the vocabulary.
		 *
		 * @param string $order_meta_query SQL fragment selecting the meta rows to copy.
		 *
		 * @return string
		 */
		public function woocommerce_subscriptions_renewal_order_meta_query( $order_meta_query ) {
			$excluded_keys = array();
			foreach ( SS_Shipping_Order_Meta::booking_outcome_meta_keys() as $meta_key ) {
				$excluded_keys[] = "'" . esc_sql( $meta_key ) . "'";
			}

			$order_meta_query .= ' AND `meta_key` NOT IN ( ' . implode( ', ', $excluded_keys ) . ' )';

			return $order_meta_query;
		}
	}

endif;
