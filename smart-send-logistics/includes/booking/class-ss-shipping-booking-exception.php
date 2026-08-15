<?php
/**
 * WooCommerce Smart Send booking exception.
 *
 * @package  SS_Shipping_Booking_Exception
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Booking_Exception' ) ) :

	/**
	 * Thrown by SS_Shipping_Shipment_Builder when it cannot produce a valid
	 * SS_Shipping_Shipment (e.g. no return method configured).
	 *
	 * Message-only: SS_Shipping_Booking_Service catches this internally and
	 * converts it into a failed SS_Shipping_Booking - it never propagates
	 * to SS_Shipping_Booking_Service's own callers.
	 */
	class SS_Shipping_Booking_Exception extends \Exception {}

endif;
