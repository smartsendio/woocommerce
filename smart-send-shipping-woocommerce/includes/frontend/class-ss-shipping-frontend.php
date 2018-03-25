<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Order.
 *
 * @package  SS_SHIPPING_WC_Order
 * @category Shipping
 * @author   Shadi Manna
 */

// require_once '../../lib/Smartsend/Api.php';

if ( ! class_exists( 'SS_Shipping_Frontend' ) ) :

class SS_Shipping_Frontend {
	
	// protected $ss_agents = array();

	protected $api_handle = null;

	/**
	 * Init and hook in the integration.
	 */
	public function __construct( ) {
		// $this->define_constants();

		// Initiate an API handle with the login credentials.
		$this->api_handle = new \Smartsend\Api('API_KEY');

		$this->init_hooks();
	}

	public function init_hooks() {
		// add_action( 'wp_enqueue_scripts', array( $this, 'load_styles_scripts' ) );
		// add_action( 'woocommerce_review_order_after_shipping', array( $this, 'add_preferred_fields' ) );
		// add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_cart_fees' ) );
		
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'display_ss_pickup_points' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_ss_pickup_points' ), 10, 2 );
		// add_filter( 'woocommerce_get_order_item_totals', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
		add_filter( 'woocommerce_order_details_after_order_table', array( $this, 'display_ss_shipping_agent') ) ;

	}

	public function display_ss_pickup_points($method, $index) {

		// Only display agents on checkout
		if( ! is_checkout() ) {
			return;
		}

		// Need posted address
		if( empty( $_POST ) ) {
			return;
		}

		// error_log(print_r($_POST,true));
		// error_log(print_r(WC()->customer,true));

		// error_log('display_ss_pickup_points');
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		// error_log(print_r($chosen_methods,true));
 		$chosen_shipping = $chosen_methods[0]; 
		// error_log($index);
 		$method_id = $method->get_method_id();
 		$full_method_id = $method->get_id();

		// error_log(print_r($method,true));
		if( $method_id == 'smart_send_shipping' &&
			$chosen_shipping ==  $full_method_id &&
			(stripos($full_method_id, '_pickuppoint') !== false) ) {
			
			error_log('found selected shipping method');

			if ( isset( $_POST['s_country'] ) && isset( $_POST['s_postcode'] ) && isset( $_POST['s_address'] ) ) {
				$country = wc_clean( $_POST['s_country'] );
				$postal_code = wc_clean( $_POST['s_postcode'] );
				$street = wc_clean( $_POST['s_address'] );
				// error_log($country);
				// error_log($postal_code);
				// error_log($street);

				$carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $full_method_id );
				// error_log($carrier);
				// error_log($method->get_id());
				// Is street necessary?  If street changed on frontend this function is not called.
				try {
					
					$ss_agents = $this->api_handle->findClosestAgentByAddress( $carrier, $country, $postal_code, $street );
					// $ss_agents = $this->api_handle->findClosestAgentByAddress($carrier='gls', $country='dk', $postal_code='2100', $street='classensgade');
					// $ss_agents = $this->api_handle->findClosestAgentByAddress($carrier='gls', $country='2100', $postal_code='dk', $street='classensgade');
					// error_log(print_r($ss_agents,true));
					WC()->session->set( 'ss_shipping_agents' , $ss_agents );

					if ( $ss_agents ) {
						
						$ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();
						$default_select_agent = $ss_setting['default_select_agent'];
						
						?>
						<select name="ss_shipping_store_pickup">
							<?php 

								if ( isset( $ss_setting['default_select_agent'] ) && $ss_setting['default_select_agent'] == 'no' ) {
									echo '<option value="0">' . __('- Select Pickup Point -', 'smart-send-shipping') . '</option>';		
								}

								foreach ($ss_agents as $key => $agent) { 
									$formatted_address = $this->get_formatted_address( $agent );
								// $formatted_address_val = $this->get_formatted_address( $agent, 4 );
								// error_log($formatted_address);
								// echo $agent->agent_no . ':' . $formatted_address_val;
							?>
									<option value="<?php echo $agent->agent_no; ?>"><?php echo $formatted_address ?></option>
							<?php } ?>
								
						</select>
						<?php
					} else {
						$debug = $this->api_handle->getDebug();
						error_log(print_r($debug,true));
						echo __('Shipping to closest pickup point', 'smart-send-shipping');
					}

				} catch (Exception $e) {
					// throw $e;
					$debug = $this->api_handle->getDebug();
						error_log(print_r($debug,true));
					echo __('Shipping to closest pickup point', 'smart-send-shipping');
				}
			}
		}
	}

	protected function get_formatted_address( $agent, $format_id = 0 ) {
		// error_log(print_r($agent,true));
		// $place_holders = '#'.__('Company','smart-send-shipping').', #'.__('Street','smart-send-shipping').', #'.__('City','smart-send-shipping'),
		// #'.__('Zipcode','smart-send-shipping')

		if ( empty($format_id) ) {
			$ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();
			$format_id = $ss_setting['dropdown_display_format'];
		}

		$agents_address_format = SS_SHIPPING_WC()->get_agents_address_format();
		// error_log(print_r($agents_address_format,true));
		// error_log(print_r($ss_setting,true));
		$address_format = $agents_address_format[ $format_id ];
		// error_log(print_r($address_format,true));

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
		// return str_replace( array_keys($place_holders), array_values($place_holders), $address_format );
	}

	public function process_ss_pickup_points( $order_id, $posted ) {
		// save the posted preferences to the order so can be used when generating label
		
		if ( ! isset( $_POST ) ) {
			return;
		}
		// error_log(print_r($_POST, true));
		
		if( empty( $_POST[ 'ss_shipping_store_pickup' ] ) ) {
			return;
		}

		$ss_shipping_store_pickup = wc_clean( $_POST[ 'ss_shipping_store_pickup' ] );
		// error_log($ss_shipping_store_pickup);
		$retrive_data = WC()->session->get( 'ss_shipping_agents' );

		$selected_agent_no = 0;
		if ( $retrive_data ) {
			foreach ($retrive_data as $agent_key => $agent_value) {
				// error_log($agent_value->agent_no);
				if( $agent_value->agent_no == $ss_shipping_store_pickup ) {
					// $selected_agent['selected_agent'] = $agent_value;
					$selected_agent_no = $agent_value->agent_no;
					$selected_agent = $agent_value;
					// $retrive_data = WC()->session->delete( 'ss_shipping_agents' );
					break;
				}
			}
		}
		// error_log('retrive_data');
		// error_log(print_r($retrive_data,true));
		// $ss_shipping_store_address = $this->ss_agents[ $ss_shipping_store_pickup ];
		// $ss_shipping_agent = explode(':', $ss_shipping_store_pickup);

		// $formatted_address = $this->get_formatted_address( $ss_shipping_store_address, 4 );

		// if ( ! empty( $ss_shipping_agent[0] )) {
		// 	// update_post_meta( $order_id, 'ss_shipping_agent_id', $ss_shipping_agent[0] );
		// 	$ss_shipping_order_options['ss_shipping_agent_id'] = $ss_shipping_agent[0];
		// }

		// if ( ! empty( $ss_shipping_agent[1] )) {
		// 	$ss_shipping_order_options['ss_shipping_agent_address'] = $ss_shipping_agent[1];
		// 	// update_post_meta( $order_id, '_ss_shipping_agent_address', $ss_shipping_agent[1] );
		// }
		// error_log(print_r($selected_agent,true));
		if( ! empty( $selected_agent_no ) ) {
			SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent_no( $order_id, $selected_agent_no );
			SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent( $order_id, $selected_agent );
		}
	}
	/*
	private function get_shipping_carrier( $ship_method ) {
		
		if( empty( $ship_method ) ) {
			return $ship_method;
		}

		// Assumes format 'name:instance_carrier_method'
		$new_ship_method = explode(':', $ship_method );
		
		if ( isset($new_ship_method[1] ) ) {
			// Assumes format 'instance_carrier_method'
			$ship_carrier = explode('_', $new_ship_method[1] );
	
			// if has 3 parts
			if ( sizeof($ship_carrier) == 3 ) {
				return $ship_carrier[1]; // second value is the carrier
			}
		}

		return $ship_method;
	}*/

	public function display_ss_shipping_agent( $order ) {

		// Might need to change for WC 3.0
		$order_id = $order->get_order_number(); // WHAT HAPPENS WHEN SEQUENCIAL ORDER ID PLUGIN IS INSTALLED?

		// global $order;
		$ordered_agent_no = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent_no( $order_id );
		$ordered_agent = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent( $order_id );

		$formatted_address = $this->get_formatted_address($ordered_agent);
		// Display in block instead of one line
		$formatted_address = str_replace(',', '<br/>', $formatted_address);
		?>

		<h2><?php _e( 'Pickup Point', 'woocommerce' ); ?></h2>
		<address>
			<?php echo $formatted_address; ?>
		</address>
		
		<?php		
	}
}

endif;
