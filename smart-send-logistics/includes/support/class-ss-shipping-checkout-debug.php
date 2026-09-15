<?php
/**
 * Smart Send checkout debug notices.
 *
 * @package SmartSendLogistics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Surfaces Smart Send diagnostics in WooCommerce's shipping debug mode.
 *
 * When the merchant enables WooCommerce → Settings → Shipping → "Enable
 * debug mode", WooCommerce core prints diagnostic notices at checkout
 * ("Customer matched zone ..."). This class adds Smart Send messages to
 * that same debug bar, using the same gating core applies.
 *
 * This is purely a WooCommerce-core presentation surface: it never writes
 * to the Smart Send log. Call sites that also want a log entry must call
 * SS_Shipping_Logger themselves.
 */
class SS_Shipping_Checkout_Debug {

	/**
	 * Show a message in the checkout shipping debug bar.
	 *
	 * The notice is only added when WooCommerce shipping debug mode is on,
	 * never during checkout submission or AJAX requests, and never twice
	 * for the same message — mirroring WC_Shipping's own debug notices.
	 *
	 * @param string $message Message to show as a debug notice.
	 */
	public static function add_notice( $message ) {
		if ( 'yes' !== get_option( 'woocommerce_shipping_debug_mode', 'no' ) ) {
			return;
		}

		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) || defined( 'WC_DOING_AJAX' ) ) {
			return;
		}

		if ( wc_has_notice( $message ) ) {
			return;
		}

		wc_add_notice( $message );
	}
}
