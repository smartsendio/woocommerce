<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Frontend.
 *
 * @package  SS_Shipping_Frontend
 * @category Shipping
 * @author   Smart Send
 */

if (!class_exists('SS_Shipping_Frontend')) :

    class SS_Shipping_Frontend
    {

        /**
         * Init and hook in the integration.
         */
        public function __construct()
        {
            $this->init_hooks();
        }

        protected function init_hooks()
        {
            add_action('woocommerce_after_shipping_rate', array($this, 'display_ss_pickup_points'), 10, 2);
            add_action('woocommerce_checkout_process', array($this, 'validate_agent_selected'));
            add_action('woocommerce_checkout_order_processed', array($this, 'process_ss_pickup_points'), 10, 2);
            add_action('woocommerce_order_details_after_order_table', array($this, 'display_ss_shipping_agent'), 10, 2);
            add_action('woocommerce_email_after_order_table', array($this, 'display_ss_shipping_agent'), 10, 2);
        }

        /**
         * Display the pick-up points next to the Smart Send method
         */
        public function display_ss_pickup_points($method, $index)
        {

            // Only display agents on checkout
            if (!is_checkout()) {
                return;
            }

            // Need posted address
            if (empty($_POST)) {
                return;
            }

            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $chosen_shipping = current($chosen_methods);

            if (defined('WOOCOMMERCE_VERSION') && version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')) {
                $method_id = $method->get_method_id();
                $shipping_id = $method->get_id();
            } else {
                $method_id = $method->method_id;
                $shipping_id = $method->id;
            }

            $meta_data = $method->get_meta_data();

            if ($chosen_shipping &&
                ($method_id == 'smart_send_shipping') &&
                ($chosen_shipping == $shipping_id) &&
                (stripos($meta_data['smart_send_shipping_method'], 'agent') !== false)) {

                if (!empty($_POST['s_country']) && !empty($_POST['s_postcode']) && !empty($_POST['s_address'])) {
                    $country = wc_clean($_POST['s_country']);
                    $postal_code = wc_clean($_POST['s_postcode']);
	                $city = (!empty($_POST['s_city']) ? wc_clean($_POST['s_city']) : null);//not required but preferred
                    $street = wc_clean($_POST['s_address']);

                    $carrier = SS_SHIPPING_WC()->get_shipping_method_carrier($meta_data['smart_send_shipping_method']);

	                $ss_agents = $this->find_closest_agents_by_address($carrier, $country, $postal_code, $city, $street);

                    if (!empty($ss_agents)) {

                        $ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();

                        $ss_agent_options = array();
                        if (!isset($ss_setting['default_select_agent']) || $ss_setting['default_select_agent'] == 'no') {
                            $ss_agent_options[0] = __('- Select Pick-up Point -',
                                    'smart-send-logistics');
                        }

                        foreach ($ss_agents as $key => $agent) {
                            $formatted_address = $this->get_formatted_address($agent);
                            $ss_agent_options[ $agent->agent_no ] = $formatted_address;
                        }

                        woocommerce_form_field( 'ss_shipping_store_pickup', array(
                            'type'          => 'select',
                            'options'       => $ss_agent_options,
                            'input_class'   => array('ss-agent-list'),
                            )
                        );

                    } else {
                        echo '<div class="woocommerce-info ss-agent-info">' . __('Shipping to closest pick-up point',
                                'smart-send-logistics') . '</div>';
                    }
                } else {
                    echo '<div class="woocommerce-info ss-agent-info">' . __('Enter shipping information',
                            'smart-send-logistics') . '</div>';
                }
            }
        }

		/**
		 * Find the closest agents by address
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
			// The request and response (incl. HTTP status code and endpoint)
			// are logged by the client's request logger.
			if ( SS_SHIPPING_WC()->get_api_handle()->findClosestAgentByAddress( $carrier, $country, $postal_code, $city, $street ) ) {

				$ss_agents = SS_SHIPPING_WC()->get_api_handle()->getData();

				$summary = 'Smart Send: found ' . count( $ss_agents ) . ' ' . $carrier . ' pick-up points near the entered address.';
				SS_Shipping_Logger::debug(
					$summary,
					array(
						'carrier'      => $carrier,
						'result_count' => count( $ss_agents ),
					)
				);
				SS_Shipping_Checkout_Debug::add_notice( $summary );

				// Save all of the agents in sessions.
				WC()->session->set( 'ss_shipping_agents', $ss_agents );

				return $ss_agents;
			} else {
				$this->report_agent_lookup_failure( $carrier, SS_SHIPPING_WC()->get_api_handle()->getError() );

				return array();
			}
		}

		/**
		 * Log why a pick-up point lookup failed and surface the reason in
		 * WooCommerce's shipping debug mode.
		 *
		 * The checkout falls back to the "Shipping to closest pick-up point"
		 * text whenever no agents are available. That fallback hides several
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
				// Not an error: the API answered, there are just no pick-up
				// points near the entered address.
				$reason = 'Smart Send: no ' . $carrier . ' pick-up points found near the entered address - falling back to "Shipping to closest pick-up point".';

				SS_Shipping_Logger::debug( $reason );
				SS_Shipping_Checkout_Debug::add_notice( $reason );

				return;
			}

			if ( 0 === strpos( $code, 'transport-' ) ) {
				$reason = 'Smart Send: pick-up point lookup for ' . $carrier . ' failed with a transport error (' . $code . '): ' . $message;
			} elseif ( '' !== $code || '' !== $message ) {
				$reason = 'Smart Send: pick-up point lookup for ' . $carrier . ' failed with an API error (' . $code . '): ' . $message;
			} else {
				$reason = 'Smart Send: pick-up point lookup for ' . $carrier . ' failed for an unknown reason.';
			}

			$reason .= ' Falling back to "Shipping to closest pick-up point".';

			SS_Shipping_Logger::error( $reason );
			SS_Shipping_Checkout_Debug::add_notice( $reason );
		}

        /**
         * Get the formatted address to display on the frontend
         */
        protected function get_formatted_address($agent, $format_id = 0)
        {

            if ($format_id == 0) {
                // Find the setting
                $ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();
                $format_id = $ss_setting['dropdown_display_format'];
            }

            switch ($format_id) {
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
                $agent->agent_no,
                $agent->company,
                $agent->address_line1,
                $agent->postal_code,
                $agent->city,
                $agent->country,
            );

            $formatted_address = str_replace($place_holders, $place_holders_vals, $address_format);

            if (!empty($agent->distance) && $format_id > 0) {
                if ($agent->distance < 1) {
                    $formatted_distance = number_format($agent->distance * 1000, 0, '.', '')
                        . __('m', 'smart-send-logistics');
                } else {
                    $formatted_distance = number_format($agent->distance, 2, '.', '')
                        . __('km', 'smart-send-logistics');
                }
                $formatted_address = $formatted_distance . ': ' . $formatted_address;
            }

            return $formatted_address;
        }

        /**
         * Ensure a store pickup point is selected if the drop down exists
         */
        public function validate_agent_selected()
        {

            if (!isset($_POST)) {
                return;
            }

            // If agent drop down exists and is empty, cannot checkout
            if (isset($_POST['ss_shipping_store_pickup']) && empty($_POST['ss_shipping_store_pickup'])) {
                wc_add_notice(__('A pick-up point must be selected.', 'smart-send-logistics'), 'error');
                return;
            }
        }

        /**
         * Save the posted preferences to the order so can be used when generating label
         */
        public function process_ss_pickup_points($order_id, $posted)
        {

            if (!isset($_POST)) {
                return;
            }

            if (empty($_POST['ss_shipping_store_pickup'])) {
                return;
            }

            $ss_shipping_store_pickup = wc_clean($_POST['ss_shipping_store_pickup']);
            $retrive_data = WC()->session->get('ss_shipping_agents');

            $selected_agent_no = 0;
            if ($retrive_data) {
                foreach ($retrive_data as $agent_key => $agent_value) {
                    // If agent selected for the order, save it
                    if ($agent_value->agent_no == $ss_shipping_store_pickup) {

                        $selected_agent_no = $agent_value->agent_no;
                        $selected_agent = $agent_value;
                        // $retrive_data = WC()->session->delete( 'ss_shipping_agents' );
                        break;
                    }
                }
            }

			// Saving posted agent information.
			if ( ! empty( $selected_agent_no ) ) {
				SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent_no(
					$order_id,
					$selected_agent_no
				);
				SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent( $order_id, $selected_agent );

				SS_Shipping_Logger::info(
					'Pick-up point selected at checkout',
					array(
						'order_id' => $order_id,
						'agent_no' => $selected_agent_no,
					)
				);
			}
        }

        /**
         * Display the Smart Sent Pick-up Point on Thank You order details
         */
        public function display_ss_shipping_agent($order)
        {

            $order_id = $this->getOrderId($order);
            $ordered_agent_no = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent_no($order_id);

            if ($ordered_agent_no) {

                $ordered_agent = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent($order_id);

                $formatted_address = $this->get_formatted_address($ordered_agent, -1);
                // Display in block instead of one line
                $formatted_address = str_replace(',', '<br/>', $formatted_address);

                echo '<h2>' . __('Pick-up Point', 'smart-send-logistics') . '</h2>'
                    . '<address>' . $formatted_address . '</address>';
            }
        }

        protected function getOrderId($order)
        {
            // WC 3.0 code!
            if (defined('WOOCOMMERCE_VERSION') && version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')) {
                return $order->get_id();
            } else {
                return $order->id;
            }
        }
    }

endif;
