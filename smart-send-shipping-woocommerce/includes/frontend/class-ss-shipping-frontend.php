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

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
 		$chosen_shipping = $chosen_methods[0]; 

 		if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
 			$method_id = $method->get_method_id();
 			$full_method_id = $method->get_id();
 		} else {
 			$method_id = $method->method_id;
 			$full_method_id = $method->id;
 		}

		if( $method_id == 'smart_send_shipping' &&
			$chosen_shipping ==  $full_method_id &&
			(stripos($full_method_id, '_pickuppoint') !== false) ) {

			if ( isset( $_POST['s_country'] ) && isset( $_POST['s_postcode'] ) && isset( $_POST['s_address'] ) ) {
				$country = wc_clean( $_POST['s_country'] );
				$postal_code = wc_clean( $_POST['s_postcode'] );
				$street = wc_clean( $_POST['s_address'] );

				$carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $full_method_id );
				// Is street necessary?  If street changed on frontend this function is not called.
                if ( $this->api_handle->findClosestAgentByAddress( $carrier, $country, $postal_code, $street ) ) {
                    $ss_agents = $this->api_handle->getData();
                    WC()->session->set( 'ss_shipping_agents' , $ss_agents );
                    $ss_setting = SS_SHIPPING_WC()->get_ss_shipping_settings();

                    ?>
                    <select name="ss_shipping_store_pickup">
                        <?php
                            if ( !isset( $ss_setting['default_select_agent'] ) || $ss_setting['default_select_agent'] == 'no' ) {
                                echo '<option value="0">' . __('- Select Pickup Point -', 'smart-send-shipping') . '</option>';
                            }

                            foreach ($ss_agents as $key => $agent) {
                                $formatted_address = $this->get_formatted_address( $agent );
                        ?>
                                <option value="<?php echo $agent->agent_no; ?>"><?php echo $formatted_address ?></option>
                        <?php } ?>

                    </select>
                    <?php
                } else {
                    echo __('Shipping to closest pickup point', 'smart-send-shipping');
                }
			}
		}
	}

	protected function get_formatted_address( $agent, $format_id = 0 ) {
		// $place_holders = '#'.__('Company','smart-send-shipping').', #'.__('Street','smart-send-shipping').', #'.__('City','smart-send-shipping'),
		// #'.__('Zipcode','smart-send-shipping')

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
		// return str_replace( array_keys($place_holders), array_values($place_holders), $address_format );
	}

	public function process_ss_pickup_points( $order_id, $posted ) {
		// save the posted preferences to the order so can be used when generating label
		
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
				if( $agent_value->agent_no == $ss_shipping_store_pickup ) {
					// $selected_agent['selected_agent'] = $agent_value;
					$selected_agent_no = $agent_value->agent_no;
					$selected_agent = $agent_value;
					// $retrive_data = WC()->session->delete( 'ss_shipping_agents' );
					break;
				}
			}
		}
		
		if( ! empty( $selected_agent_no ) ) {
			SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent_no( $order_id, $selected_agent_no );
			SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent( $order_id, $selected_agent );
		}
	}

	public function display_ss_shipping_agent( $order ) {

		$order_id = $order->get_order_number();
		$ordered_agent_no = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent_no( $order_id );

		if ( $ordered_agent_no ) {
			
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
}

endif;
