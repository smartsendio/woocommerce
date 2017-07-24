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
	
	function smartsend_logistics_get_woocommerce_version_pickuppoint() {
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
    $woocommerce_version = smartsend_logistics_get_woocommerce_version_pickuppoint();
    $default_hook = (version_compare($woocommerce_version, '2.5.0', '<') ? 0 : 2);
	$x = get_option( 'woocommerce_pickup_display_mode', $default_hook ); //Should be 2 as default if WooCommerce version >= 2.5.0 and 0 else.
	if($x==1) {
		add_action( 'smartsend_logistics_dropdown_hook' , 'Smartsend_Logistics_custom_store_pickup_field', 10, 3 );
	} elseif($x==2) {
		add_action( 'woocommerce_after_shipping_rate', 'Smartsend_Logistics_custom_store_pickup_field', 10, 3 );
		//do_action( 'woocommerce_after_shipping_rate', $method, $index ); Line 35: /templates/cart/cart-shipping.php
	} else {
		add_action( 'woocommerce_review_order_after_cart_contents' , 'Smartsend_Logistics_custom_store_pickup_field', 10, 3 );
		//do_action( 'woocommerce_review_order_after_cart_contents' );  Line 52: /templates/checkout/review-order.php
	}
        
	function Smartsend_Logistics_custom_store_pickup_field( $method=null, $index=null ) {
	
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
	
		$pickup_loc = '';
	
		if($shipping_carrier && $shipping_method == 'pickup') {
			$display_selectbox = true;
			switch( $shipping_carrier ){
		
				case 'posten': 
					$shippingTitle = 'Posten';
					$pickup_loc = Smartsend_Logistics_API_Call('posten',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'gls':
					$shippingTitle = 'GLS';
					$pickup_loc = Smartsend_Logistics_API_Call('gls',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'postdanmark': 
					$shippingTitle = 'PostDanmark';
					$pickup_loc = Smartsend_Logistics_API_Call('postdanmark',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'bring': 
					$shippingTitle = 'Bring';
					$pickup_loc = Smartsend_Logistics_API_Call('bring',$address_1,$address_2,$city,$zip,$country);
					break;
							
			}			
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
					jQuery('.selectpickuppoint').remove();
				}
            });
		</script>
		<?php if($display_selectbox){ 
		?>
		<script>   
			jQuery(document).ready(function(){
            	var numItems =  jQuery('.selectpickuppoint').length;
                if(numItems > 1){
                	jQuery('.selectpickuppoint').last().remove();
                }
				jQuery('.shipping_method, #ship-to-different-address-checkbox, #billing_country').click(function(){
                	jQuery('.selectpickuppoint').remove();
					jQuery('.pic_error, .pic_script').remove();
				});
			});
		</script>
		
		<!-- script to update checkout if zipcode is changed -->
		<script>   
			jQuery(document).ready(function(){
				var postcode = jQuery('.validate-postcode').find('input');
				
				postcode.change(function() {
					jQuery('.selectpickuppoint').remove();
					jQuery('.pic_error, .pic_script').remove();
					jQuery('body').trigger('update_checkout');
				});
			});
		</script>
		<?php if(!empty($pickup_loc) && is_array($pickup_loc)):?>
                
			<div id='selectpickuppoint' class="selectpickuppoint">
			<?php if(!empty($pickup_loc) && is_array($pickup_loc)):?>				
				<select name="store_pickup" class="pk-drop">
					<option value=""><?php echo __('Select a pick-up point','smart-send-logistics'); ?></option>
					<?php foreach($pickup_loc as $picIndex => $picValue) { ?>
					<option value='<?php echo $picIndex?>'><?php echo $picValue?></option>
					<?php }?>
				</select>
                    
			<?php else:?>
				<?php //echo ' : Delivered to closest pickup point.'?>
			<?php endif;?>
			</div>
		<?php else:?>
			<div id="selectpickuppoint" class="selectpickuppoint">
				<?php echo __('Shipping to closest pick-up point','smart-send-logistics'); ?>
			</div>
		<?php endif;?>
	<?php
    	}

	}
        
	#Process the checkout and validate pickuppoint
	add_action('woocommerce_checkout_process', 'Smartsend_Logistics_pickuppoint_checkout_field_process');
	function Smartsend_Logistics_pickuppoint_checkout_field_process() {
		global $woocommerce;
		// Check if set, if its not set add an error. This one is only requite for companies
		if (isset($_POST['store_pickup']) && $_POST['store_pickup']=='') {
			wc_add_notice( __('Select a pick-up point','smart-send-logistics'), 'error' );
		}
	}
	
	# Update custom order meta field (store_pickup)
	add_action( 'woocommerce_checkout_update_order_meta', 'Smartsend_Logistics_pickuppoint_field_update_order_meta' );
	function Smartsend_Logistics_pickuppoint_field_update_order_meta( $order_id ) {  
		if ( isset($_POST[ 'store_pickup' ]) &&  $_POST[ 'store_pickup' ] != ''){
			$store_pickup = sanitize_text_field($_POST[ 'store_pickup' ]);
			update_post_meta( $order_id, 'store_pickup', $store_pickup  );
		}
	}          

	# hide custom field data in admin orders section
	add_filter('is_protected_meta', 'Smartsend_Logistics_hide_pickuppoint_meta_filter', 10, 2);
	function Smartsend_Logistics_hide_pickuppoint_meta_filter($protected, $meta_key) {
		return $meta_key == 'store_pickup' ? true : $protected;
	}
	
/*-----------------------------------------------------------------------------------------------------------------------
* 					Show information about selected pickuppoint
*----------------------------------------------------------------------------------------------------------------------*/

	#HTML code showing the pickup information
	function Smartsend_Logistics_display_order_pickuppoint_details($order,$tag=false,$show_id=false,$new_line=true) {
			   
		$store_pickup = get_post_custom($order->get_id());
	
		if(isset($store_pickup['store_pickup'][0]) && $store_pickup['store_pickup'][0] != '') {
			$store_pickup = @unserialize($store_pickup['store_pickup'][0]);
        	$store_pickup = str_replace(array("\\\\\\"), '', $store_pickup);
      
			if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);
	
			if(!empty($store_pickup)){
				if($tag) {
					echo '<'.$tag.'>';
					echo __('Pickuppoint','smart-send-logistics');
					echo '</'.$tag.'>';
				} else {
					echo __('Pickuppoint','smart-send-logistics');
				}
				if($new_line) {
					echo '<br/>';
				} else {
					echo ' ';
				}
				if($show_id) {
					echo 'ID: ' . $store_pickup['id'] .'<br/>';
				}
				echo 	$store_pickup['company'] .'<br/>'.
						$store_pickup['street'] .'<br/>'.
						$store_pickup['zip'] .' '.$store_pickup['city'];
			}
		}
	}
	
	# Show selected pickup location in customer's myaccount section
	add_action( 'woocommerce_order_details_after_order_table', 'Smartsend_Logistics_pickuppoint_field_display_customer_myaccount' );
	function Smartsend_Logistics_pickuppoint_field_display_customer_myaccount($order){
		Smartsend_Logistics_display_order_pickuppoint_details($order,'h4',false,false);
	}
	
	# Show selected pickup location in order emails
	add_action( 'woocommerce_email_after_order_table', 'smartsend_logistics_print_pickuppoint_info', 10, 2 );
	function smartsend_logistics_print_pickuppoint_info( $order, $sent_to_admin ) {
		Smartsend_Logistics_display_order_pickuppoint_details($order,'h3',false,false);
	}
	
	# Show selected pickup location on the order edit page(woocommerce_admin_order_data_after_order_details)
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'Smartsend_Logistics_pickuppoint_field_display_admin_order_meta', 10, 1 );
	function Smartsend_Logistics_pickuppoint_field_display_admin_order_meta($order){
		$line_items_shipping = $order->get_items( 'shipping' );
		$shipMethod = '';
		if(!empty($line_items_shipping)){
			foreach ( $line_items_shipping as $item_id => $item ) {
				$shipMethod=  ! empty( $item['name'] ) ? esc_html( $item['name'] ) : __( 'Shipping','smart-send-logistics');
			}
		}	
		Smartsend_Logistics_display_order_pickuppoint_details($order,'h4',true,false);
	}

}
}