<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Frontend.
 *
 * @package  SS_Shipping_Frontend
 * @category Shipping
 * @author   Shadi Manna
 */

if ( ! class_exists( 'SS_Shipping_Frontend' ) ) :

class SS_Shipping_Frontend {
	
	/**
	 * Init and hook in the integration.
	 */
	public function __construct( ) {
		$this->init_hooks();
	}

	/**
	 * Init hooks
	 */
	public function init_hooks() {
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'display_ss_pickup_points' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_agent_selected' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_ss_pickup_points' ), 10, 2 );
		add_filter( 'woocommerce_order_details_after_order_table', array( $this, 'display_ss_shipping_agent') ) ;
	}

	/**
	 * Display the pick-up points next to the Smart Send method
	 */
	public function display_ss_pickup_points($method, $index) {

		// Only display agents on checkout
		if( ! is_checkout() ) {
			return;
		}

		// Need posted address
		if( empty( $_POST ) ) {
			return;
		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
 		$chosen_shipping = current($chosen_methods);

 		if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
 			$method_id = $method->get_method_id();
 			$shipping_id = $method->get_id();
 		} else {
 			$method_id = $method->method_id;
 			$shipping_id = $method->id;
 		}
 		
 		$meta_data = $method->get_meta_data();

		if ( $chosen_shipping &&
            ( $method_id == 'smart_send_shipping' ) &&
			( $chosen_shipping == $shipping_id ) &&
			( stripos($meta_data['smartsend_method'], 'agent') !== false) ) {

			if ( !empty( $_POST['s_country'] ) && !empty( $_POST['s_postcode'] ) && !empty( $_POST['s_address'] ) ) {
				$country = wc_clean( $_POST['s_country'] );
				$postal_code = wc_clean( $_POST['s_postcode'] );
				$street = wc_clean( $_POST['s_address'] );

				$carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $meta_data['smartsend_method'] );

				SS_SHIPPING_WC()->log_msg( 'Called "findClosestAgentByAddress" with carrier = "' . $carrier .'", country = "'. $country . '", postcode = "' . $postal_code . '", street = "' . $street . '"' );
				
                if ( SS_SHIPPING_WC()->get_api_handle()->findClosestAgentByAddress( $carrier, $country, $postal_code, $street ) ) {

                    $ss_agents = SS_SHIPPING_WC()->get_api_handle()->getData();

                    SS_SHIPPING_WC()->log_msg( 'Response from "findClosestAgentByAddress": ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody() );
                    // Save all of the agents in sessions
                    WC()->session->set( 'ss_shipping_agents' , $ss_agents );
                    $ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();

                    ?>
                    <select name="ss_shipping_store_pickup" class="ss-agent-list">
                        <?php
                        	// Select the closest pick-up point by default or have the customer select one
                            if ( !isset( $ss_setting['default_select_agent'] ) || $ss_setting['default_select_agent'] == 'no' ) {
                                echo '<option value="0">' . __('- Select Pick-up Point -', 'smart-send-shipping') . '</option>';
                            }

                            foreach ($ss_agents as $key => $agent) {
                                $formatted_address = $this->get_formatted_address( $agent );
                        ?>
                                <option value="<?php echo $agent->agent_no; ?>"><?php echo $formatted_address ?></option>
                        <?php } ?>

                    </select>
                    <?php
                } else {
                	
                	SS_SHIPPING_WC()->log_msg( 'Response from "findClosestAgentByAddress": '.SS_SHIPPING_WC()->get_api_handle()->getErrorString() );

                    echo '<div class="woocommerce-info ss-agent-info">' . __('Shipping to closest pick-up point', 'smart-send-shipping') . '</div>';
                }
			} else {
                echo '<div class="woocommerce-info ss-agent-info">' . __('Enter shipping information', 'smart-send-shipping') . '</div>';
            }
		}
	}

	/**
	 * Get the formatted address to display on the frontend
	 */
	protected function get_formatted_address( $agent, $format_id = 0 ) {
		if ( empty($format_id) ) {
			$ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();
			$format_id = $ss_setting['dropdown_display_format'];
		}

		$agents_address_format = SS_SHIPPING_WC()->get_agents_address_format();
		$address_format = $agents_address_format[ $format_id ];

		$place_holders = array( 
								__('#Company', 'smart-send-shipping'),
								__('#Street','smart-send-shipping'),
								__('#Zipcode','smart-send-shipping'),
								__('#City','smart-send-shipping')
							);

		$place_holders_vals = array(
								$agent->company,
								$agent->address_line1,
								$agent->postal_code,
								$agent->city
							);

		$formatted_address = str_replace( $place_holders, $place_holders_vals, $address_format );

		if( !empty( $agent->distance ) ) {
		    if($agent->distance < 1) {
                $formatted_distance = number_format($agent->distance*1000, 0, '.', '')
                    . __('m: ', 'smart-send-shipping');
            } else {
                $formatted_distance = number_format($agent->distance, 2, '.', '')
                    . __('km: ', 'smart-send-shipping');
            }
            $formatted_address = $formatted_distance . $formatted_address;
		}

		return $formatted_address;
	}

	/**
	* Ensure a store pickup point is selected if the drop down exists
	*/
	public function validate_agent_selected() {
		
		if ( ! isset( $_POST ) ) {
			return;
		}
		
		// If agent drop down exists and is empty, cannot checkout
		if( isset( $_POST[ 'ss_shipping_store_pickup' ] ) && empty( $_POST[ 'ss_shipping_store_pickup' ] ) ) {
			wc_add_notice( __( 'A pick-up point must be selected.', 'smart-send-shipping' ), 'error' );
			return;
		}
	}

	/**
	* Save the posted preferences to the order so can be used when generating label	
	*/
	public function process_ss_pickup_points( $order_id, $posted ) {
		
		if ( ! isset( $_POST ) ) {
			return;
		}
		
		if( empty( $_POST[ 'ss_shipping_store_pickup' ] ) ) {
			return;
		}

		$ss_shipping_store_pickup = wc_clean( $_POST[ 'ss_shipping_store_pickup' ] );
		$retrive_data = WC()->session->get( 'ss_shipping_agents' );

		$selected_agent_no = 0;
		if ( $retrive_data ) {
			foreach ($retrive_data as $agent_key => $agent_value) {
				// If agent selected for the order, save it
				if( $agent_value->agent_no == $ss_shipping_store_pickup ) {
					
					$selected_agent_no = $agent_value->agent_no;
					$selected_agent = $agent_value;
					// $retrive_data = WC()->session->delete( 'ss_shipping_agents' );
					break;
				}
			}
		}
		
		// Saving posted agent information
		if( ! empty( $selected_agent_no ) ) {
			SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent_no( $order_id, $selected_agent_no );
			SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent( $order_id, $selected_agent );
		}
	}

	/**
	* Display the Smart Sent Pick-up Point on Thank You order details
	*/
	public function display_ss_shipping_agent( $order ) {

		$order_id = $order->get_order_number();
		$ordered_agent_no = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent_no( $order_id );

		if ( $ordered_agent_no ) {
			
			$ordered_agent = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent( $order_id );

			$formatted_address = $this->get_formatted_address($ordered_agent);
			// Display in block instead of one line
			$formatted_address = str_replace(',', '<br/>', $formatted_address);
			?>

			<h2><?php _e( 'Pick-up Point', 'smart-send-shipping' ); ?></h2>
			<address>
				<?php echo $formatted_address; ?>
			</address>
			
			<?php		
		}
	}
}

endif;
