<?php
/**
 * Smart Send availability reporter.
 *
 * @package  SS_Shipping_Availability_Reporter
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Availability_Reporter' ) ) :

	/**
	 * Reports the shipping-class and free-shipping availability outcomes of
	 * SS_Shipping_WC_Method to the two logging surfaces (#92): the log gets
	 * the English message, the checkout shipping debug bar the translated
	 * one.
	 *
	 * Extracted from the two ~130-line switch/if matrices in
	 * SS_Shipping_WC_Method (#140) into data-driven message tables; every
	 * message string is byte-identical to the historic ones so existing
	 * translations keep matching.
	 */
	class SS_Shipping_Availability_Reporter {

		/**
		 * Report the shipping-class availability outcome.
		 *
		 * @param string  $method_title               The shipping method title.
		 * @param string  $display_shipping_class_opt The configured shipping-class condition.
		 * @param boolean $is_available               Whether the method ended up available.
		 * @return void
		 */
		public function report_shipping_class_availability( $method_title, $display_shipping_class_opt, $is_available ) {
			$messages = $this->shipping_class_messages();

			if ( ! isset( $messages[ $display_shipping_class_opt ] ) ) {
				return;
			}

			$entry = $messages[ $display_shipping_class_opt ][ $is_available ? 'available' : 'unavailable' ];

			SS_Shipping_Logger::debug(
				sprintf( $entry['log'], $method_title ),
				array( 'shipping_class_option' => $display_shipping_class_opt )
			);
			SS_Shipping_Checkout_Debug::add_notice( sprintf( $entry['notice'], $method_title ) );
		}

		/**
		 * Report the free-shipping (flat fee) availability outcome.
		 *
		 * @param string     $method_title The shipping method title.
		 * @param string     $requires     The configured free-shipping condition.
		 * @param boolean    $is_available Whether free shipping ended up available.
		 * @param mixed|null $total        The evaluated cart total (only set for amount-based conditions).
		 * @param mixed      $min_amount   The configured minimum order amount.
		 * @return void
		 */
		public function report_free_shipping_availability( $method_title, $requires, $is_available, $total, $min_amount ) {
			$messages = $this->free_shipping_messages();

			$key   = isset( $messages[ $requires ] ) ? $requires : 'default';
			$entry = $messages[ $key ];

			if ( isset( $entry['available'] ) ) {
				$entry = $entry[ $is_available ? 'available' : 'unavailable' ];
			}

			if ( empty( $entry['with_amounts'] ) ) {
				$log_message    = sprintf( $entry['log'], $method_title );
				$notice_message = sprintf( $entry['notice'], $method_title );
			} else {
				$log_message    = sprintf( $entry['log'], $method_title, $total, $min_amount );
				$notice_message = sprintf( $entry['notice'], $method_title, $total, $min_amount );
			}

			SS_Shipping_Logger::debug( $log_message, array( 'requires' => $requires ) );
			SS_Shipping_Checkout_Debug::add_notice( $notice_message );
		}

		/**
		 * The shipping-class message matrix: per configured condition, the
		 * English log template and translated notice template for the
		 * available/unavailable outcomes. All templates take the method
		 * title as their only argument.
		 *
		 * @return array
		 */
		protected function shipping_class_messages() {
			return array(
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_ALL => array(
					'available'   => array(
						'log'    => 'Smart Send "%s": shipping method IS available, because ALL products belong to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method IS available, because ALL products belong to one of the shipping classes', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because ALL products belong to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method is NOT available, because ALL products belong to one of the shipping classes', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_ONE => array(
					'available'   => array(
						'log'    => 'Smart Send "%s": shipping method IS available, because at least ONE product belongs to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method IS available, because at least ONE product belongs to one of the shipping classes', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because at least ONE product belongs to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method is NOT available, because at least ONE product belongs to one of the shipping classes', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_NALL => array(
					'available'   => array(
						'log'    => 'Smart Send "%s": shipping method IS available, because ALL products do NOT belong to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method IS available, because ALL products do NOT belong to one of the shipping classes', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because ALL products do NOT belong to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method is NOT available, because ALL products do NOT belong to one of the shipping classes', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_NONE => array(
					'available'   => array(
						'log'    => 'Smart Send "%s": shipping method IS available, because at least ONE product does NOT belongs to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method IS available, because at least ONE product does NOT belongs to one of the shipping classes', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because at least ONE product does NOT belongs to one of the shipping classes',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": shipping method is NOT available, because at least ONE product does NOT belongs to one of the shipping classes', 'smart-send-logistics' ),
					),
				),
			);
		}

		/**
		 * The free-shipping message matrix: per configured condition, the
		 * English log template and translated notice template. Entries with
		 * 'with_amounts' take (title, total, minimum amount); the others
		 * take the title only. ENABLED/DISABLED/default have one fixed
		 * outcome (preserved v8 behaviour: their message ignores
		 * $is_available).
		 *
		 * @return array
		 */
		protected function free_shipping_messages() {
			return array(
				SS_Shipping_Method_Settings::REQUIRES_MIN_AMOUNT => array(
					'available'   => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed.',
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						'notice'       => __( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed.', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed.',
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						'notice'       => __( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_COUPON => array(
					'available'   => array(
						'log'    => 'Smart Send "%s": free shipping (flat fee) IS available, because a coupon is needed.',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": free shipping (flat fee) IS available, because a coupon is needed.', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": free shipping (flat fee) is NOT available, because a coupon is needed.',
						/* translators: %s: shipping method title. */
						'notice' => __( 'Smart Send "%s": free shipping (flat fee) is NOT available, because a coupon is needed.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_BOTH => array(
					'available'   => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.',
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						'notice'       => __( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.',
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						'notice'       => __( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_EITHER => array(
					'available'   => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.',
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						'notice'       => __( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.', 'smart-send-logistics' ),
					),
					'unavailable' => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.',
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						'notice'       => __( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_ENABLED => array(
					'log'    => 'Smart Send "%s": free shipping (flat fee) IS available, because it is always enabled.',
					/* translators: %s: shipping method title. */
					'notice' => __( 'Smart Send "%s": free shipping (flat fee) IS available, because it is always enabled.', 'smart-send-logistics' ),
				),
				SS_Shipping_Method_Settings::REQUIRES_DISABLED => array(
					'log'    => 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is always disabled.',
					/* translators: %s: shipping method title. */
					'notice' => __( 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is always disabled.', 'smart-send-logistics' ),
				),
				'default'                                  => array(
					'log'    => 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is not available.',
					/* translators: %s: shipping method title. */
					'notice' => __( 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is not available.', 'smart-send-logistics' ),
				),
			);
		}
	}

endif;
