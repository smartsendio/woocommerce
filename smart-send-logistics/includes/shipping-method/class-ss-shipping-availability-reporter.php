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
	 * the full English step trace; the checkout shipping debug bar gets a
	 * single translated verdict line, and only when the shipping-class
	 * condition made the method unavailable (an available method's one-line
	 * summary - including the free-shipping outcome - is emitted by
	 * calculate_shipping() instead).
	 *
	 * Extracted from the two ~130-line switch/if matrices in
	 * SS_Shipping_WC_Method (#140) into data-driven message tables; the log
	 * message strings are byte-identical to the historic ones so they stay
	 * greppable across versions.
	 */
	class SS_Shipping_Availability_Reporter {

		/**
		 * Report the shipping-class availability outcome: always to the log;
		 * to the debug bar only when the condition made the method
		 * unavailable (calculate_shipping() never runs then, so this is the
		 * method's one debug bar line).
		 *
		 * @param string  $method_title               The shipping method title.
		 * @param string  $display_shipping_class_opt The configured shipping-class condition.
		 * @param boolean $is_available               Whether the method ended up available.
		 * @param string  $rate_id                    The shipping rate id (e.g. 'smart_send_shipping:2').
		 * @return void
		 */
		public function report_shipping_class_availability( $method_title, $display_shipping_class_opt, $is_available, $rate_id = '' ) {
			$messages = $this->shipping_class_messages();

			if ( ! isset( $messages[ $display_shipping_class_opt ] ) ) {
				return;
			}

			$entry = $messages[ $display_shipping_class_opt ][ $is_available ? 'available' : 'unavailable' ];

			SS_Shipping_Logger::debug(
				sprintf( $entry['log'], $method_title ),
				array( 'shipping_class_option' => $display_shipping_class_opt )
			);

			if ( ! $is_available ) {
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: 1: shipping method title, 2: shipping rate id, 3: the shipping-class reason the method is unavailable. */
						__( 'Smart Send: Evaluated method "%1$s" (%2$s) as not available: %3$s', 'smart-send-logistics' ),
						$method_title,
						$rate_id,
						$entry['reason']
					)
				);
			}
		}

		/**
		 * Report the free-shipping (flat fee) availability outcome to the
		 * log. The debug bar carries no separate free-shipping line - the
		 * outcome is part of the one-line evaluation summary
		 * calculate_shipping() emits ("Flat fee cost X applied").
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
				$log_message = sprintf( $entry['log'], $method_title );
			} else {
				$log_message = sprintf( $entry['log'], $method_title, $total, $min_amount );
			}

			SS_Shipping_Logger::debug( $log_message, array( 'requires' => $requires ) );
		}

		/**
		 * The shipping-class message matrix: per configured condition, the
		 * English log template (taking the method title) for the
		 * available/unavailable outcomes, plus - on the unavailable side -
		 * the translated reason clause for the debug bar verdict line.
		 *
		 * @return array
		 */
		protected function shipping_class_messages() {
			return array(
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_ALL => array(
					'available'   => array(
						'log' => 'Smart Send "%s": shipping method IS available, because ALL products belong to one of the shipping classes',
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because ALL products belong to one of the shipping classes',
						'reason' => __( 'not ALL products belong to one of the selected shipping classes.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_ONE => array(
					'available'   => array(
						'log' => 'Smart Send "%s": shipping method IS available, because at least ONE product belongs to one of the shipping classes',
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because at least ONE product belongs to one of the shipping classes',
						'reason' => __( 'no product belongs to one of the selected shipping classes.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_NALL => array(
					'available'   => array(
						'log' => 'Smart Send "%s": shipping method IS available, because ALL products do NOT belong to one of the shipping classes',
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because ALL products do NOT belong to one of the shipping classes',
						'reason' => __( 'at least one product belongs to one of the selected shipping classes.', 'smart-send-logistics' ),
					),
				),
				SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_NONE => array(
					'available'   => array(
						'log' => 'Smart Send "%s": shipping method IS available, because at least ONE product does NOT belongs to one of the shipping classes',
					),
					'unavailable' => array(
						'log'    => 'Smart Send "%s": shipping method is NOT available, because at least ONE product does NOT belongs to one of the shipping classes',
						'reason' => __( 'ALL products belong to one of the selected shipping classes.', 'smart-send-logistics' ),
					),
				),
			);
		}

		/**
		 * The free-shipping message matrix: per configured condition, the
		 * English log template. Entries with 'with_amounts' take (title,
		 * total, minimum amount); the others take the title only.
		 * ENABLED/DISABLED/default have one fixed outcome (preserved v8
		 * behaviour: their message ignores $is_available).
		 *
		 * @return array
		 */
		protected function free_shipping_messages() {
			return array(
				SS_Shipping_Method_Settings::REQUIRES_MIN_AMOUNT => array(
					'available'   => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed.',
					),
					'unavailable' => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed.',
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_COUPON => array(
					'available'   => array(
						'log' => 'Smart Send "%s": free shipping (flat fee) IS available, because a coupon is needed.',
					),
					'unavailable' => array(
						'log' => 'Smart Send "%s": free shipping (flat fee) is NOT available, because a coupon is needed.',
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_BOTH => array(
					'available'   => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.',
					),
					'unavailable' => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.',
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_EITHER => array(
					'available'   => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.',
					),
					'unavailable' => array(
						'with_amounts' => true,
						'log'          => 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.',
					),
				),
				SS_Shipping_Method_Settings::REQUIRES_ENABLED => array(
					'log' => 'Smart Send "%s": free shipping (flat fee) IS available, because it is always enabled.',
				),
				SS_Shipping_Method_Settings::REQUIRES_DISABLED => array(
					'log' => 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is always disabled.',
				),
				'default'                                  => array(
					'log' => 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is not available.',
				),
			);
		}
	}

endif;
