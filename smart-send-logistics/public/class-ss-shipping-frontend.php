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
		 * Order meta repository (delivery details).
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Checkout delivery-option resolution (which sections checkout
		 * renders, pickup point section statuses and texts).
		 *
		 * @var SS_Shipping_Checkout_Options
		 */
		protected SS_Shipping_Checkout_Options $checkout_options;

		/**
		 * All collaborators are stateless, so fresh defaults are safe for
		 * ad-hoc construction (tests construct the frontend directly).
		 *
		 * @param SS_Shipping_Pickup_Point_Lookup|null    $pickup_point_lookup    Headless pickup point lookup.
		 * @param SS_Shipping_Pickup_Point_Formatter|null $pickup_point_formatter Pickup point display formatter.
		 * @param SS_Shipping_Settings|null               $settings               Typed plugin settings reader.
		 * @param SS_Shipping_Order_Meta|null             $order_meta             Order meta repository.
		 * @param SS_Shipping_Checkout_Options|null       $checkout_options       Checkout delivery-option resolution.
		 */
		public function __construct( ?SS_Shipping_Pickup_Point_Lookup $pickup_point_lookup = null, ?SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter = null, ?SS_Shipping_Settings $settings = null, ?SS_Shipping_Order_Meta $order_meta = null, ?SS_Shipping_Checkout_Options $checkout_options = null ) {
			$this->pickup_point_lookup    = null === $pickup_point_lookup ? new SS_Shipping_Pickup_Point_Lookup() : $pickup_point_lookup;
			$this->pickup_point_formatter = null === $pickup_point_formatter ? new SS_Shipping_Pickup_Point_Formatter() : $pickup_point_formatter;
			$this->settings               = null === $settings ? new SS_Shipping_Settings() : $settings;
			$this->order_meta             = null === $order_meta ? new SS_Shipping_Order_Meta() : $order_meta;
			$this->checkout_options       = null === $checkout_options ? new SS_Shipping_Checkout_Options() : $checkout_options;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
			add_action( 'woocommerce_after_shipping_rate', array( $this, 'display_ss_pickup_points' ), 10, 2 );
			add_action( 'woocommerce_checkout_process', array( $this, 'validate_agent_selected' ) );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_ss_pickup_points' ), 10, 2 );
			add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
			add_action( 'woocommerce_email_after_order_table', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
		}

		/**
		 * Enqueue the checkout stylesheet - owned by this component instead
		 * of a blanket enqueue on the plugin singleton (#140).
		 *
		 * @return void
		 */
		public function enqueue_frontend_styles() {
			wp_enqueue_style( 'ss-shipping-frontend-css', SS_SHIPPING_PLUGIN_DIR_URL . '/public/css/ss-shipping-frontend.css', array(), SS_SHIPPING_VERSION );
		}

		/**
		 * Display the pickup point section next to the chosen Smart Send
		 * agent rate: the selector when the lookup found pickup points, a
		 * status message (enter address / not connected / auth failure /
		 * none found / fallback) otherwise. The statuses and their texts
		 * live on SS_Shipping_Checkout_Options, shared with the Checkout
		 * Block surface.
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $index is part of the woocommerce_after_shipping_rate hook signature.
		public function display_ss_pickup_points( $method, $index ) {

			// Only display pickup points on checkout
			if ( ! is_checkout() ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- pre-existing behaviour: this renders inside WooCommerce's own checkout update_order_review AJAX cycle, which carries no plugin nonce; changing the input handling is out of scope for the #43 move.

			$chosen_methods  = WC()->session->get( 'chosen_shipping_methods' );
			$chosen_shipping = is_array( $chosen_methods ) ? current( $chosen_methods ) : false;

			$method_id   = $method->get_method_id();
			$shipping_id = $method->get_id();

			$meta_data = $method->get_meta_data();

			if ( ! $chosen_shipping ||
				( 'smart_send_shipping' != $method_id ) || // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				( $chosen_shipping != $shipping_id ) || // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				empty( $meta_data['smart_send_shipping_method'] ) ) {
				return;
			}

			$method_code = new SS_Shipping_Method_Code( $meta_data['smart_send_shipping_method'] );

			if ( ! $this->checkout_options->show_pickup_points( $method_code ) ) {
				return;
			}

			list( $country, $postal_code, $city, $street ) = $this->resolve_shipping_address();

			$ss_pickup_points = array();

			if ( empty( $country ) || empty( $postal_code ) || empty( $street ) ) {
				// No lookup can run without an address - render the
				// enter-your-address hint (also on the very first, non-AJAX
				// page load).
				$status = SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE;
			} else {
				try {
					$ss_pickup_points = $this->find_closest_agents_by_address( $method_code->carrier(), $country, $postal_code, $city, $street );

					$status = empty( $ss_pickup_points )
						? SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND
						: SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_FOUND;
				} catch ( Exception $e ) {
					// Logged and session-cached by the lookup itself - only
					// rendering is left to do here.
					$status = $this->checkout_options->pickup_point_status_for_exception( $e );
				}
			}

			if ( SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_FOUND === $status ) {

				$pickup_point_options = array();
				if ( ! $this->settings->default_select_agent() ) {
					$pickup_point_options[0] = __(
						'- Select Pickup Point -',
						'smart-send-logistics'
					);
				}

				foreach ( $ss_pickup_points as $key => $pickup_point ) {
					// The label pipeline (format + the smart_send_pickup_point_option_label
					// filter) is shared with the Checkout Block cart extension (#74).
					$pickup_point_options[ $pickup_point->agent_no ] = $this->pickup_point_formatter->dropdown_label( $pickup_point );
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
				printf(
					'<div class="%1$s ss-agent-info ss-agent-info--%2$s">%3$s</div>',
					$this->checkout_options->is_pickup_point_error_status( $status ) ? 'woocommerce-error' : 'woocommerce-info',
					esc_attr( $status ),
					esc_html( $this->checkout_options->pickup_point_status_message( $status ) )
				);
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		/**
		 * The shipping address the pickup point lookup should run for: the
		 * address fields posted by WooCommerce's update_order_review AJAX
		 * cycle when present, the customer's session-stored shipping address
		 * otherwise (the very first, non-AJAX checkout page load posts
		 * nothing - the same server-side source the Checkout Block surface
		 * reads).
		 *
		 * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null} [country, postal_code, city, street]
		 */
		protected function resolve_shipping_address(): array {
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: values are wc_clean-ed; this renders inside WooCommerce's own checkout update_order_review AJAX cycle, which carries no plugin nonce.
			if ( ! empty( $_POST['s_country'] ) && ! empty( $_POST['s_postcode'] ) && ! empty( $_POST['s_address'] ) ) {
				return array(
					wc_clean( $_POST['s_country'] ),
					wc_clean( $_POST['s_postcode'] ),
					( ! empty( $_POST['s_city'] ) ? wc_clean( $_POST['s_city'] ) : null ), // not required but preferred.
					wc_clean( $_POST['s_address'] ),
				);
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

			$customer = WC()->customer;

			if ( null === $customer ) {
				return array( null, null, null, null );
			}

			$city = $customer->get_shipping_city();

			return array(
				$customer->get_shipping_country(),
				$customer->get_shipping_postcode(),
				empty( $city ) ? null : $city,
				$customer->get_shipping_address(),
			);
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
		 * @throws SS_Shipping_Not_Connected_Exception When no API token is configured.
		 * @throws \Smartsend\Exceptions\HttpClientException When the API call fails.
		 *
		 * @return array The found pickup points (possibly empty).
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

			// If pickup point selected for the order, save it. The
			// session-cache resolution is shared with the Checkout Block
			// persistence (#74).
			$selected_pickup_point = $this->pickup_point_lookup->find_cached_by_agent_no( $ss_shipping_store_pickup );

			if ( null === $selected_pickup_point || ! $selected_pickup_point->get_agent_no() ) {
				// Recovered failure: the shopper's choice is silently
				// dropped from the order (the classic checkout has no
				// fallback resolution), so leave an always-logged trace.
				SS_Shipping_Logger::warning(
					'Posted pickup point could not be resolved from the session cache - selection not saved',
					array(
						'order_id' => $order_id,
						'agent_no' => $ss_shipping_store_pickup,
					)
				);

				return;
			}

			// Saving posted pickup point information.
			$details = new SS_Shipping_Delivery_Details();
			$details->set_pickup_point( $selected_pickup_point );
			$this->order_meta->write( $order_id, $details );

			SS_Shipping_Logger::info(
				'Pickup point selected at checkout',
				array(
					'order_id' => $order_id,
					'agent_no' => $selected_pickup_point->get_agent_no(),
				)
			);
		}

		/**
		 * Display the Smart Sent Pickup Point on Thank You order details
		 */
		public function display_ss_shipping_agent( $order ) {

			$order_id             = $this->get_order_id( $order );
			$ordered_pickup_point = $this->order_meta->read( $order_id )->get_pickup_point();

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
			return $order->get_id();
		}
	}

endif;
