<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Check if WooCommerce is active
 */
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if(!is_network_admin()){
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) || is_plugin_active_for_network('woocommerce/woocommerce.php')) {
	
	function smartsend_logistics_get_woocommerce_version_flexdelivery() {
		/*
		// If get_plugins() isn't available, require it
		if ( ! function_exists( 'get_plugins' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	*/
		// Create the plugins folder and file variables
		$plugin_folder = get_plugins( '/' . 'woocommerce' );
		$plugin_file = 'woocommerce.php';
	
		// If the plugin version number is set, return it 
		if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
			$woocommerce_version = $plugin_folder[$plugin_file]['Version'];
		} else {
			$woocommerce_version = null;
		}
		return $woocommerce_version;
	}
	
/*-----------------------------------------------------------------------------------------------------------------------
* 					Add pickuppoint dropdown on chechout page	
*----------------------------------------------------------------------------------------------------------------------*/		
	/*if ( ! function_exists( 'is_ajax' ) ) {
                   function is_ajax() {
                            return false;
                    }
        }*/
	$woocommerce_version = smartsend_logistics_get_woocommerce_version_flexdelivery();
    $default_hook = (version_compare($woocommerce_version, '2.5.0', '<') ? 0 : 2);
	$x = get_option( 'woocommerce_pickup_display_mode', $default_hook ); //Should be 2 as default if WooCommerce version >= 2.5.0 and 0 else.
	if($x==1) {
		add_action( 'smartsend_logistics_dropdown_hook' , 'Smartsend_Logistics_custom_flexdelivery_field', 10, 3 );
	} elseif($x==2) {
		add_action( 'woocommerce_after_shipping_rate', 'Smartsend_Logistics_custom_flexdelivery_field', 10, 3 );
		//do_action( 'woocommerce_after_shipping_rate', $method, $index ); Line 35: /templates/cart/cart-shipping.php
	} else {
		add_action( 'woocommerce_review_order_after_cart_contents' , 'Smartsend_Logistics_custom_flexdelivery_field', 10, 3 );
		//do_action( 'woocommerce_review_order_after_cart_contents' );  Line 52: /templates/checkout/review-order.php
	}
        
	function Smartsend_Logistics_custom_flexdelivery_field( $method=null, $index=null ) {
	
		$display_selectbox = false;
       
        //If post_data is not set, return false       
		if(!isset($_REQUEST['post_data'])) return false;
               
		parse_str($_REQUEST['post_data'],$request);
		
		$first_shipping_method_element = reset($request['shipping_method']); //First item of array
		
		$shipping_method_id = null;
		if($method) {
			if($method->id == $first_shipping_method_element) {
				$shipping_method_id = $method->id;
			}
		} else {
			$shipping_method_id = $first_shipping_method_element;
		}
		$shipping_carrier = smartsend_logistics_get_shipping_carrier_from_id($shipping_method_id);
		$shipping_method = smartsend_logistics_get_shipping_method_from_id($shipping_method_id);
					
		if(isset($request['ship_to_different_address']) && $request['ship_to_different_address']){
			$address_1 	= $request['shipping_address_1'];
			$address_2 	= $request['shipping_address_2'];
			$city 		= $request['shipping_city'];
			$zip 		= $request['shipping_postcode'];
			$country 	= $request['shipping_country'];
		}else{
			$address_1 	= $request['billing_address_1'];
			$address_2 	= $request['billing_address_2'];
			$city 		= $request['billing_city'];
			$zip 		= $request['billing_postcode'];
			$country 	= $request['billing_country'];
		}
		
		if($shipping_carrier) {
			$display_selectbox = true;
			switch( $shipping_carrier ){
		
				case 'posten': 
					break;
				case 'gls':
					break;
				case 'postdanmark': 
					// Should be if the method is in the setting for flexdelivery!
					$postdanmark = new Smartsend_Logistics_Postdanmark();
					$flexdelivery_methods = $postdanmark->get_option( 'flexdelivery','');
					if( is_array($flexdelivery_methods) && in_array($shipping_method,$flexdelivery_methods) ) {
						$display_selectbox = true;
						$flexdelivery_array = array(
							__('By the front door','smart-send-logistics'),
							__('By the back door','smart-send-logistics'),
							__('One the porch','smart-send-logistics'),
							__('In the greenhouse','smart-send-logistics'),
							__('In the garage/carport','smart-send-logistics'),
							__('In the tool shed','smart-send-logistics'),
							__('In the playhouse','smart-send-logistics'),
							__('Under the roof of the porch','smart-send-logistics'),
							__('Can be left unattended','smart-send-logistics'),
							__('I have Modtagerflex','smart-send-logistics')
						);
					}
					break;
				case 'bring': 
					break;	
			}			
		}
		 
		switch( $shipping_carrier ){
			case 'posten': 
				break;
			case 'gls':
				break;
			case 'postdanmark':
				// Should be if the method is in the setting for flexdelivery!
				$postdanmark = new Smartsend_Logistics_Postdanmark();
				$flexdelivery_methods = $postdanmark->get_option( 'flexdelivery','');
				if( is_array($flexdelivery_methods) && in_array($shipping_method,$flexdelivery_methods) ) {
					$display_selectbox = true;
					$flexdelivery_array = array(
						__('By the front door','smart-send-logistics'),
						__('By the back door','smart-send-logistics'),
						__('One the porch','smart-send-logistics'),
						__('In the greenhouse','smart-send-logistics'),
						__('In the garage/carport','smart-send-logistics'),
						__('In the tool shed','smart-send-logistics'),
						__('In the playhouse','smart-send-logistics'),
						__('Under the roof of the porch','smart-send-logistics'),
						__('Can be left unattended','smart-send-logistics'),
						__('I have Modtagerflex','smart-send-logistics')
					);
				}
				break;
			case 'bring': 
				break;                   
		}
							
        ?>
		<script>
			jQuery(document).ready(function(){
            	var found = false;
				jQuery( ".shipping_method" ).each(function( index ) { 
					var a = jQuery( this ).val();
					if (a.indexOf('smartsend') > -1) { 
						found = true;
					}
				});
				if(!found){
					jQuery('.selectflexdelivery').remove();
				}
            });
		</script>
		<?php if($display_selectbox){ 
		?>
		<script>   
			jQuery(document).ready(function(){
            	var numItems =  jQuery('.selectflexdelivery').length;
                if(numItems > 1){
                	jQuery('.selectflexdelivery').last().remove();
                }
				jQuery('.shipping_method, #ship-to-different-address-checkbox, #billing_country').click(function(){
                	jQuery('.selectflexdelivery').remove();
					jQuery('.pic_error, .pic_script').remove();
				});
			});
		</script>
		
		<!-- script to update checkout if zipcode is changed -->
		<script>   
			jQuery(document).ready(function(){
				var postcode = jQuery('.validate-postcode').find('input');
				
				postcode.change(function() {
					jQuery('.selectflexdelivery').remove();
					jQuery('.pic_error, .pic_script').remove();
					jQuery('body').trigger('update_checkout');
				});
			});
		</script>
		<?php if(!empty($flexdelivery_array) && is_array($flexdelivery_array)):?>
                
			<div id='selectflexdelivery' class="selectflexdelivery">
			<?php if(!empty($flexdelivery_array) && is_array($flexdelivery_array)):?>				
				<select name="flexdelivery" class="pk-drop">
					<option value=""><?php echo __('Select a flexdelivery option','smart-send-logistics'); ?></option>
					<?php foreach($flexdelivery_array as $flexdelivery_method) { ?>
					<option value='<?php echo $flexdelivery_method?>'><?php echo $flexdelivery_method?></option>
					<?php }?>
				</select>
                    
			<?php else:?>
				<?php echo ' : Flexdelivery disabled.'?>
			<?php endif;?>
			</div>
		<?php else:?>
			<div id="selectflexdelivery" class="selectflexdelivery">
			</div>
		<?php endif;?>
	<?php
    	}

	}
        
	#Process the checkout and validate flexdelivery
	add_action('woocommerce_checkout_process', 'Smartsend_Logistics_flexdelivery_checkout_field_process');
	function Smartsend_Logistics_flexdelivery_checkout_field_process() {
		global $woocommerce;
		// Check if set, if its not set add an error. This one is only requite for companies
		if (isset($_POST['flexdelivery']) && $_POST['flexdelivery']=='') {
			wc_add_notice( __('Select a flexdelivery option','smart-send-logistics'), 'error' );
		}
	}
	
	# Update custom order meta field (flexdelivery)
	add_action( 'woocommerce_checkout_update_order_meta', 'Smartsend_Logistics_flexdelivery_field_update_order_meta' );
	function Smartsend_Logistics_flexdelivery_field_update_order_meta( $order_id ) {  
		if ( isset($_POST[ 'flexdelivery' ]) &&  $_POST[ 'flexdelivery' ] != ''){
			$flexdelivery = sanitize_text_field($_POST[ 'flexdelivery' ]);
			update_post_meta( $order_id, 'flexdelivery', $flexdelivery  );
		}
	}          

	# hide custom field data in admin orders section
	add_filter('is_protected_meta', 'Smartsend_Logistics_hide_flexdelivery_meta_filter', 10, 2);
	function Smartsend_Logistics_hide_flexdelivery_meta_filter($protected, $meta_key) {
		return $meta_key == 'flexdelivery' ? true : $protected;
	}

/*-----------------------------------------------------------------------------------------------------------------------
* 					Show information about selected flexdelivery option
*----------------------------------------------------------------------------------------------------------------------*/

	#HTML code showing the flexdelivery information
	function Smartsend_Logistics_display_order_flexdelivery_details($order,$tag=false,$new_line=false) {
			   
		$post_custom = get_post_custom($order->get_id());
		if( isset($post_custom['flexdelivery'][0]) && !empty($post_custom['flexdelivery'][0]) ){
			if($tag) {
				echo '<'.$tag.'>';
				echo __('Flexdelivery note','smart-send-logistics');
				echo '</'.$tag.'>';
			} else {
				echo __('Flexdelivery note','smart-send-logistics');
			}
			if($new_line) {
				echo '<br/>';
			} else {
				echo ' ';
			}
			echo $post_custom['flexdelivery'][0] .'<br/>';
		}
	}
	
	# Show selected pickup location in customer's myaccount section
	add_action( 'woocommerce_order_details_after_order_table', 'Smartsend_Logistics_flexdelivery_field_display_customer_myaccount' );
	function Smartsend_Logistics_flexdelivery_field_display_customer_myaccount($order){
		Smartsend_Logistics_display_order_flexdelivery_details($order,'h4',false);
	}
	
	# Show selected flexdelivery note in order emails
	add_action( 'woocommerce_email_after_order_table', 'smartsend_logistics_print_flexdelivery_info', 10, 2 );
	function smartsend_logistics_print_flexdelivery_info( $order, $sent_to_admin ) {
		Smartsend_Logistics_display_order_flexdelivery_details($order,'h3',false);
	}
	
	# Show selected flexdelivery note on the order edit page (woocommerce_admin_order_data_after_order_details)
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'Smartsend_Logistics_flexdelivery_field_display_admin_order_meta', 10, 1 );
	function Smartsend_Logistics_flexdelivery_field_display_admin_order_meta($order){
		Smartsend_Logistics_display_order_flexdelivery_details($order,'h4',false);
	}

}
}