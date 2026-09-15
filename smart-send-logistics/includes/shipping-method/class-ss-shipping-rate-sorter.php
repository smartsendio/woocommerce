<?php
/**
 * Smart Send rate sorter.
 *
 * @package  SS_Shipping_Rate_Sorter
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Rate_Sorter' ) ) :

	/**
	 * Sorts the checkout shipping rates by cost when the "Sort shipping
	 * methods" setting is enabled, and reports which Smart Send rates ended
	 * up offered for the package - moved off the plugin singleton into the
	 * shipping-method domain (#140).
	 */
	class SS_Shipping_Rate_Sorter {

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * The settings reader is stateless, so a fresh default is safe for
		 * ad-hoc construction.
		 *
		 * @param SS_Shipping_Settings|null $settings Typed plugin settings reader.
		 */
		public function __construct( ?SS_Shipping_Settings $settings = null ) {
			$this->settings = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			add_filter( 'woocommerce_package_rates', array( $this, 'sort_shipping_methods' ) );
		}

		/**
		 * Sort the shipping methods according to setting.
		 *
		 * @param array $available_shipping_methods WC_Shipping_Rate objects keyed by rate id.
		 *
		 * @return array
		 */
		public function sort_shipping_methods( $available_shipping_methods ) {
			// If there are no rates don't do anything.
			if ( ! $available_shipping_methods ) {
				return $available_shipping_methods;
			}

			if ( $this->settings->sort_methods_by_cost() ) {
				// get an array of prices
				$prices = array();
				foreach ( $available_shipping_methods as $shipping_method ) {
					// The price is the cost + taxes.
					// Note that WC_Shipping_Rate::get_cost() can be a string, so we need to cast it to float. @see https://wordpress.org/support/topic/add-to-cart-not-working-after-update-from-9-8-5-9-9-3/
					$prices[] = floatval( $shipping_method->cost ) + array_sum( $shipping_method->taxes );
				}

				// Use the prices to sort the rates.
				array_multisort( $prices, $available_shipping_methods );
			}

			$this->report_smart_send_rates_for_package( $available_shipping_methods );

			// Return the rates.
			return $available_shipping_methods;
		}

		/**
		 * Log which Smart Send rates ended up offered for the package.
		 *
		 * Runs on woocommerce_package_rates after every shipping method has
		 * calculated its rates, so this is the final set the shopper is
		 * offered. Log-only developer trace: the checkout shipping debug bar
		 * already carries one evaluation summary line per method (from
		 * calculate_shipping()), so a second offered-rates line would be
		 * noise there.
		 *
		 * @param array $available_shipping_methods WC_Shipping_Rate objects keyed by rate id.
		 */
		protected function report_smart_send_rates_for_package( $available_shipping_methods ) {
			$offered = array();

			foreach ( $available_shipping_methods as $shipping_rate ) {
				if ( $shipping_rate instanceof WC_Shipping_Rate && SS_SHIPPING_METHOD_ID === $shipping_rate->get_method_id() ) {
					$offered[] = sprintf( '"%1$s" (%2$s, cost %3$s)', $shipping_rate->get_label(), $shipping_rate->get_id(), $shipping_rate->get_cost() );
				}
			}

			if ( empty( $offered ) ) {
				$log_message = 'Smart Send: no Smart Send rates offered for this package.';
			} else {
				$log_message = sprintf( 'Smart Send: rates offered for this package: %s.', implode( ', ', $offered ) );
			}

			SS_Shipping_Logger::debug( $log_message, array( 'offered_rates' => $offered ) );
		}
	}

endif;
