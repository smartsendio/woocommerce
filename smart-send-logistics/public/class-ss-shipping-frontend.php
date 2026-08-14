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

					$carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $meta_data['smart_send_shipping_method'] );

					$ss_pickup_points = $this->find_closest_agents_by_address( $carrier, $country, $postal_code, $city, $street );

					if ( ! empty( $ss_pickup_points ) ) {

						$ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();

						$pickup_point_options = array();
						if ( ! isset( $ss_setting['default_select_agent'] ) || 'no' == $ss_setting['default_select_agent'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
							$pickup_point_options[0] = __(
								'- Select Pickup Point -',
								'smart-send-logistics'
							);
						}

						foreach ( $ss_pickup_points as $key => $pickup_point ) {
							$formatted_address = $this->get_formatted_address( $pickup_point );

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
		 * Find the closest pickup points by address
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
			/*
			 * Filter the search parameters used when looking up the closest
			 * pickup points at checkout, before the API call is made.
			 *
			 * The number of returned pickup points is determined by the API
			 * and cannot be requested here; use the smart_send_pickup_points_found
			 * filter to trim the returned list.
			 *
			 * @since 9.0.0
			 *
			 * @param array $search_params {
			 *     The pickup point search parameters.
			 *
			 *     @type string      $carrier     Unique carrier code (e.g. 'postnord').
			 *     @type string      $country     ISO3166-A2 country code.
			 *     @type string      $postal_code Postal code.
			 *     @type string|null $city        City (optional but preferred).
			 *     @type string      $street      Street address.
			 * }
			 *
			 * @return array The search parameters to use for the lookup.
			 */
			$search_params = apply_filters(
				'smart_send_pickup_point_search_params',
				array(
					'carrier'     => $carrier,
					'country'     => $country,
					'postal_code' => $postal_code,
					'city'        => $city,
					'street'      => $street,
				)
			);

			$carrier = $search_params['carrier'];

			// The request and response (incl. HTTP status code and endpoint)
			// are logged by the client's request logger.
			if ( SS_SHIPPING_WC()->get_api_handle()->pickupPoints()->findClosestByAddress( $carrier, $search_params['country'], $search_params['postal_code'], $search_params['city'], $search_params['street'] ) ) {

				$ss_pickup_points = SS_SHIPPING_WC()->get_api_handle()->getData();

				/*
				 * Filter the pickup points returned by the lookup before they
				 * are cached in the session and rendered in the checkout
				 * drop-down. Return a smaller (or re-ordered) array to limit
				 * or re-rank the choices offered to the customer.
				 *
				 * @since 9.0.0
				 *
				 * @param object[] $ss_pickup_points     The pickup points returned by the API.
				 * @param array    $search_params The (filtered) search parameters used for the lookup.
				 *
				 * @return object[] The pickup points to cache and render.
				 */
				$ss_pickup_points = apply_filters( 'smart_send_pickup_points_found', $ss_pickup_points, $search_params );

				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send: found %1$d %2$s pickup points near the entered address.', count( $ss_pickup_points ), $carrier ),
					array(
						'carrier'      => $carrier,
						'result_count' => count( $ss_pickup_points ),
					)
				);
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: 1: number of pickup points found, 2: carrier code. */
						_n(
							'Smart Send: found %1$d %2$s pickup point near the entered address.',
							'Smart Send: found %1$d %2$s pickup points near the entered address.',
							count( $ss_pickup_points ),
							'smart-send-logistics'
						),
						count( $ss_pickup_points ),
						$carrier
					)
				);

				// Save all of the pickup points in the session.
				WC()->session->set( 'ss_shipping_agents', $ss_pickup_points );

				return $ss_pickup_points;
			} else {
				$this->report_agent_lookup_failure( $carrier, SS_SHIPPING_WC()->get_api_handle()->getError() );

				return array();
			}
		}

		/**
		 * Log why a pickup point lookup failed and surface the reason in
		 * WooCommerce's shipping debug mode.
		 *
		 * The checkout falls back to the "Shipping to closest pickup point"
		 * text whenever no pickup points are available. That fallback hides several
		 * distinct causes, so this classifies the failure (transport error,
		 * API error response, empty result) and reports it: always to the log
		 * (error level for failures, debug level for an empty result) and via
		 * SS_Shipping_Checkout_Debug::add_notice() when WooCommerce shipping
		 * debug mode is on (a no-op during checkout AJAX requests, matching
		 * core - the log entry is then the only trace).
		 *
		 * @param string      $carrier Unique carrier code the lookup ran for.
		 * @param object|null $error   Smartsend\Models\Error describing the failure, if any.
		 */
		protected function report_agent_lookup_failure( $carrier, $error ) {
			$code    = ( is_object( $error ) && isset( $error->code ) && is_scalar( $error->code ) ) ? (string) $error->code : '';
			$message = ( is_object( $error ) && isset( $error->message ) && is_scalar( $error->message ) ) ? (string) $error->message : '';

			if ( 'NoResults' === $code ) {
				// Not an error: the API answered, there are just no pickup
				// points near the entered address.
				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send: no %s pickup points found near the entered address - falling back to "Shipping to closest pickup point".', $carrier ),
					array( 'carrier' => $carrier )
				);
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: %s: carrier code. */
						__( 'Smart Send: no %s pickup points found near the entered address - falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ),
						$carrier
					)
				);

				return;
			}

			if ( 0 === strpos( $code, 'transport-' ) ) {
				$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with a transport error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', $carrier, $code, $message );
				/* translators: 1: carrier code, 2: error code, 3: error message. */
				$notice_message = sprintf( __( 'Smart Send: pickup point lookup for %1$s failed with a transport error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ), $carrier, $code, $message );
			} elseif ( '' !== $code || '' !== $message ) {
				$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with an API error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', $carrier, $code, $message );
				/* translators: 1: carrier code, 2: error code, 3: error message. */
				$notice_message = sprintf( __( 'Smart Send: pickup point lookup for %1$s failed with an API error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ), $carrier, $code, $message );
			} else {
				$log_message = sprintf( 'Smart Send: pickup point lookup for %s failed for an unknown reason. Falling back to "Shipping to closest pickup point".', $carrier );
				/* translators: %s: carrier code. */
				$notice_message = sprintf( __( 'Smart Send: pickup point lookup for %s failed for an unknown reason. Falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ), $carrier );
			}

			SS_Shipping_Logger::error(
				$log_message,
				array(
					'carrier'       => $carrier,
					'error_code'    => $code,
					'error_message' => $message,
				)
			);
			SS_Shipping_Checkout_Debug::add_notice( $notice_message );
		}

		/**
		 * Get the formatted address to display on the frontend
		 */
		protected function get_formatted_address( $pickup_point, $format_id = 0 ) {

			if ( 0 == $format_id ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				// Find the setting
				$ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();
				$format_id  = $ss_setting['dropdown_display_format'];
			}

			switch ( $format_id ) {
				case 1:
					$address_format = '#Company, #Street';
					break;
				case 2:
					$address_format = '#Company, #Street, #Zipcode';
					break;
				case 3:
					$address_format = '#Company, #Street, #City';
					break;
				case 4:
					$address_format = '#Company, #Street, #Zipcode #City';
					break;
				case 5:
					$address_format = '#Company, #Zipcode';
					break;
				case 6:
					$address_format = '#Company, #Zipcode, #City';
					break;
				case 7:
					$address_format = '#Company, #City';
					break;
				default:
					$address_format = '#Company<br>#Street<br>#Country #Zipcode #City';
					break;
			}

			$place_holders = array(
				'#AgentNo',
				'#Company',
				'#Street',
				'#Zipcode',
				'#City',
				'#Country',
			);

			$place_holders_vals = array(
				$pickup_point->agent_no,
				$pickup_point->company,
				$pickup_point->address_line1,
				$pickup_point->postal_code,
				$pickup_point->city,
				$pickup_point->country,
			);

			$formatted_address = str_replace( $place_holders, $place_holders_vals, $address_format );

			if ( ! empty( $pickup_point->distance ) && $format_id > 0 ) {
				if ( $pickup_point->distance < 1 ) {
					/* translators: %s: distance in meters. */
					$formatted_distance = sprintf( __( '%sm', 'smart-send-logistics' ), number_format( $pickup_point->distance * 1000, 0, '.', '' ) );
				} else {
					/* translators: %s: distance in kilometers. */
					$formatted_distance = sprintf( __( '%skm', 'smart-send-logistics' ), number_format( $pickup_point->distance, 2, '.', '' ) );
				}
				$formatted_address = sprintf( '%1$s: %2$s', $formatted_distance, $formatted_address );
			}

			return $formatted_address;
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
			$retrieved_pickup_points = WC()->session->get( 'ss_shipping_agents' );

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
				SS_SHIPPING_WC()->order_meta()->save_ss_shipping_order_agent_no(
					$order_id,
					$selected_pickup_point_no
				);
				SS_SHIPPING_WC()->order_meta()->save_ss_shipping_order_agent( $order_id, $selected_pickup_point );

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

			$order_id                = $this->get_order_id( $order );
			$ordered_pickup_point_no = SS_SHIPPING_WC()->order_meta()->get_ss_shipping_order_agent_no( $order_id );

			if ( $ordered_pickup_point_no ) {

				$ordered_pickup_point = SS_SHIPPING_WC()->order_meta()->get_ss_shipping_order_agent( $order_id );

				$formatted_address = $this->get_formatted_address( $ordered_pickup_point, -1 );
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
