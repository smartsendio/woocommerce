<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Frontend.
 *
 * @package  SS_Shipping_Frontend
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Frontend' ) ) :

	class SS_Shipping_Frontend {

		/**
		 * Headless pickup point lookup (API + session cache).
		 *
		 * @var SS_Shipping_Pickup_Point_Lookup
		 */
		protected SS_Shipping_Pickup_Point_Lookup $pickup_point_lookup;

		/**
		 * Pickup point display formatter.
		 *
		 * @var SS_Shipping_Pickup_Point_Formatter
		 */
		protected SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter;

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * All collaborators are stateless, so fresh defaults are safe for
		 * ad-hoc construction (tests construct the frontend directly).
		 *
		 * @param SS_Shipping_Pickup_Point_Lookup|null    $pickup_point_lookup    Headless pickup point lookup.
		 * @param SS_Shipping_Pickup_Point_Formatter|null $pickup_point_formatter Pickup point display formatter.
		 * @param SS_Shipping_Settings|null               $settings               Typed plugin settings reader.
		 */
		public function __construct( ?SS_Shipping_Pickup_Point_Lookup $pickup_point_lookup = null, ?SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter = null, ?SS_Shipping_Settings $settings = null ) {
			$this->pickup_point_lookup    = null === $pickup_point_lookup ? new SS_Shipping_Pickup_Point_Lookup() : $pickup_point_lookup;
			$this->pickup_point_formatter = null === $pickup_point_formatter ? new SS_Shipping_Pickup_Point_Formatter() : $pickup_point_formatter;
			$this->settings               = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			add_action( 'woocommerce_after_shipping_rate', array( $this, 'display_ss_pickup_points' ), 10, 2 );
			add_action( 'woocommerce_checkout_process', array( $this, 'validate_agent_selected' ) );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_ss_pickup_points' ), 10, 2 );
			add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
			add_action( 'woocommerce_email_after_order_table', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
		}

		/**
		 * Display the pickup points next to the Smart Send method
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $index is part of the woocommerce_after_shipping_rate hook signature.
		public function display_ss_pickup_points( $method, $index ) {

			// Only display pickup points on checkout
			if ( ! is_checkout() ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- pre-existing behaviour: this renders inside WooCommerce's own checkout update_order_review AJAX cycle, which carries no plugin nonce; changing the input handling is out of scope for the #43 move.

			// Need posted address
			if ( empty( $_POST ) ) {
				return;
			}

			$chosen_methods  = WC()->session->get( 'chosen_shipping_methods' );
			$chosen_shipping = current( $chosen_methods );

			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
				$method_id   = $method->get_method_id();
				$shipping_id = $method->get_id();
			} else {
				$method_id   = $method->method_id;
				$shipping_id = $method->id;
			}

			$meta_data = $method->get_meta_data();

			if ( $chosen_shipping &&
				( 'smart_send_shipping' == $method_id ) && // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				( $chosen_shipping == $shipping_id ) && // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				( stripos( $meta_data['smart_send_shipping_method'], 'agent' ) !== false ) ) {

				// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: values are wc_clean-ed; changing the input handling is out of scope for the #43 move.
				if ( ! empty( $_POST['s_country'] ) && ! empty( $_POST['s_postcode'] ) && ! empty( $_POST['s_address'] ) ) {
					$country     = wc_clean( $_POST['s_country'] );
					$postal_code = wc_clean( $_POST['s_postcode'] );
					$city        = ( ! empty( $_POST['s_city'] ) ? wc_clean( $_POST['s_city'] ) : null );//not required but preferred
					$street      = wc_clean( $_POST['s_address'] );
					// phpcs:enable WordPress.Security.ValidatedSanitizedInput

					$carrier = ( new SS_Shipping_Method_Code( $meta_data['smart_send_shipping_method'] ) )->carrier();

					$ss_pickup_points = $this->find_closest_agents_by_address( $carrier, $country, $postal_code, $city, $street );

					if ( ! empty( $ss_pickup_points ) ) {

						$pickup_point_options = array();
						if ( ! $this->settings->default_select_agent() ) {
							$pickup_point_options[0] = __(
								'- Select Pickup Point -',
								'smart-send-logistics'
							);
						}

						foreach ( $ss_pickup_points as $key => $pickup_point ) {
							$formatted_address = $this->pickup_point_formatter->format( $pickup_point );

							/*
							 * Filter the label shown for a pickup point in the
							 * checkout drop-down.
							 *
							 * @since 9.0.0
							 *
							 * @param string $formatted_address The label formatted per the "Dropdown display format" setting.
							 * @param object $pickup_point      The pickup point (agent_no, company, address_line1, postal_code, city, country, distance, ...).
							 *
							 * @return string The option label to render.
							 */
							$pickup_point_options[ $pickup_point->agent_no ] = apply_filters( 'smart_send_pickup_point_option_label', $formatted_address, $pickup_point );
						}

						/*
						 * Filter which pickup point is pre-selected in the
						 * checkout drop-down. Return the agent_no of one of the
						 * found pickup points to pre-select it; return an empty
						 * string to keep the default behaviour (the first option,
						 * which is the "- Select Pickup Point -" placeholder
						 * unless the "Select Default" setting is enabled).
						 *
						 * @since 9.0.0
						 *
						 * @param string   $default_pickup_point_no The pre-selected agent_no ('' selects the first option).
						 * @param object[] $ss_pickup_points        The pickup points shown in the drop-down.
						 *
						 * @return string The agent_no to pre-select, or '' for the first option.
						 */
						$default_pickup_point_no = apply_filters( 'smart_send_default_selected_pickup_point', '', $ss_pickup_points );

						woocommerce_form_field(
							'ss_shipping_store_pickup',
							array(
								'type'        => 'select',
								'options'     => $pickup_point_options,
								'input_class' => array( 'ss-agent-list' ),
								'default'     => $default_pickup_point_no,
							)
						);

					} else {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing static translated string in static markup; escaping is a behaviour change out of scope for the #43 move.
						echo '<div class="woocommerce-info ss-agent-info">' . __(
							'Shipping to closest pickup point',
							'smart-send-logistics'
						) . '</div>';
					}
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing static translated string in static markup; escaping is a behaviour change out of scope for the #43 move.
					echo '<div class="woocommerce-info ss-agent-info">' . __(
						'Enter shipping information',
						'smart-send-logistics'
					) . '</div>';
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		/**
		 * Find the closest pickup points by address - delegates to the
		 * headless SS_Shipping_Pickup_Point_Lookup (#139), which owns the
		 * API call, the lookup filters, the failure reporting and the
		 * session cache.
		 *
		 * @param $carrier string | unique carrier code
		 * @param $country string | ISO3166-A2 Country code
		 * @param $postal_code string
		 * @param $city string
		 * @param $street string
		 *
		 * @return array
		 */
		public function find_closest_agents_by_address( $carrier, $country, $postal_code, $city, $street ) {
			return $this->pickup_point_lookup->find_closest_by_address( $carrier, $country, $postal_code, $city, $street );
		}

		/**
		 * Ensure a store pickup point is selected if the drop down exists
		 */
		public function validate_agent_selected() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- pre-existing behaviour: runs inside WooCommerce's checkout submission, which is nonce-verified by WooCommerce itself.

			if ( ! isset( $_POST ) ) {
				return;
			}

			// If pickup point drop down exists and is empty, cannot checkout
			if ( isset( $_POST['ss_shipping_store_pickup'] ) && empty( $_POST['ss_shipping_store_pickup'] ) ) {
				wc_add_notice( __( 'A pickup point must be selected.', 'smart-send-logistics' ), 'error' );
				return;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		/**
		 * Save the posted preferences to the order so can be used when generating label
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $posted is part of the woocommerce_checkout_order_processed hook signature.
		public function process_ss_pickup_points( $order_id, $posted ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: runs inside WooCommerce's nonce-verified checkout submission and the value is wc_clean-ed; changing the input handling is out of scope for the #43 move.

			if ( ! isset( $_POST ) ) {
				return;
			}

			if ( empty( $_POST['ss_shipping_store_pickup'] ) ) {
				return;
			}

			$ss_shipping_store_pickup = wc_clean( $_POST['ss_shipping_store_pickup'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
			$retrieved_pickup_points = $this->pickup_point_lookup->get_session_pickup_points();

			$selected_pickup_point_no = 0;
			if ( $retrieved_pickup_points ) {
				foreach ( $retrieved_pickup_points as $pickup_point_key => $pickup_point_value ) {
					// If pickup point selected for the order, save it
					if ( $pickup_point_value->agent_no == $ss_shipping_store_pickup ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.

						$selected_pickup_point_no = $pickup_point_value->agent_no;
						$selected_pickup_point    = $pickup_point_value;
						break;
					}
				}
			}

			// Saving posted pickup point information.
			if ( ! empty( $selected_pickup_point_no ) ) {
				$details = new SS_Shipping_Delivery_Details();
				$details->set_pickup_point( SS_Shipping_Pickup_Point::from_object( $selected_pickup_point ) );
				SS_SHIPPING_WC()->order_meta()->write( $order_id, $details );

				SS_Shipping_Logger::info(
					'Pickup point selected at checkout',
					array(
						'order_id' => $order_id,
						'agent_no' => $selected_pickup_point_no,
					)
				);
			}
		}

		/**
		 * Display the Smart Sent Pickup Point on Thank You order details
		 */
		public function display_ss_shipping_agent( $order ) {

			$order_id             = $this->get_order_id( $order );
			$ordered_pickup_point = SS_SHIPPING_WC()->order_meta()->read( $order_id )->get_pickup_point();

			if ( null !== $ordered_pickup_point && $ordered_pickup_point->get_agent_no() ) {

				$formatted_address = $this->pickup_point_formatter->format( $ordered_pickup_point, -1 );
				// Display in block instead of one line
				$formatted_address = str_replace( ',', '<br/>', $formatted_address );

				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour: the formatted pickup point address deliberately carries <br/> markup; escaping is a behaviour change out of scope for the #43 move.
				echo '<h2>' . __( 'Pickup Point', 'smart-send-logistics' ) . '</h2>'
					. '<address>' . $formatted_address . '</address>';
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		protected function get_order_id( $order ) {
			// WC 3.0 code!
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
				return $order->get_id();
			} else {
				return $order->id;
			}
		}
	}

endif;
