<?php	
/*
Plugin Name: Smart Send Logistics
Plugin URI: http://smartsend.dk/integrationer/woocommerce
Description: Table rate shipping methods with Post Danmark, GLS, SwipBox and Bring pickup points. Listed in a dropdown sorted by distance from shipping adress.
Author: Smart Send ApS
Author URI: http://www.smartsend.dk
Version: 7.0.6

Copyright: (c) 2014 Smart Send ApS (email : kontakt@smartsend.dk)
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

This module and all files are subject to the GNU General Public License v3.0
that is bundled with this package in the file license.txt.
It is also available through the world-wide-web at this URL:
http://www.gnu.org/licenses/gpl-3.0.html
If you did not receive a copy of the license and are unable to
obtain it through the world-wide-web, please send an email
to license@smartsend.dk so we can send you a copy immediately.

DISCLAIMER
Do not edit or add to this file if you wish to upgrade the plugin to newer
versions in the future. If you wish to customize the plugin for your
needs please refer to http://www.smartsend.dk
*/
 
/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

/*-----------------------------------------------------------------------------------------------------------------------
* 					Register CSS script
*----------------------------------------------------------------------------------------------------------------------*/	

	// Register style sheet.
	add_action( 'wp_enqueue_scripts', 'smartsend_logistics_register_plugin_styles' );

	/**
	 * Register style sheet.
	 */
	function smartsend_logistics_register_plugin_styles() {
		wp_register_style( 'smartsend_logistics_style_frontend', plugin_dir_url( __FILE__ ) . 'css/smartsend_logsitics_pickup.css' );
		wp_enqueue_style( 'smartsend_logistics_style_frontend' );
}

/*-----------------------------------------------------------------------------------------------------------------------
* 					Functions that deals with orders
*----------------------------------------------------------------------------------------------------------------------*/	

	function smartsend_logistics_register_session(){
        if( !session_id() )
            session_start();
    }
    add_action('init','smartsend_logistics_register_session');

/*****************************************************
 * Process an order
 * @ order: order object
 */     
        
	function smartsend_logistics_process_order($order) {
		include('api/class.label.php');
		include('api/class.order.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			smartsend_logistics_admin_notice(__("Username and licencekey must be entered in settings"), 'error');
		} else {
			$label = new Smartsend_Logistics_Label();
			try{
				$label->createOrder($order,false);
			}
			//catch exception
			catch(Exception $e) {
				if(is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = "Order #".$order->id.": ".$e->getMessage();
				} else {
					$_SESSION['smartsend_errors'] = array("Order #".$order->id.": ".$e->getMessage());
				}
			}

			if($label->isRequest()) {
				try{
					$label->postRequest(true);
					$label->handleRequest();
				} catch(Exception $e) {
					if(is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = "Order #".$order->id.": ".$e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array("Order #".$order->id.": ".$e->getMessage());
					}
				}
			}
		
		}
	}

	function smartsend_logistics_process_return_order($order) {
	
		include('api/class.label.php');
		include('api/class.order.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			smartsend_logistics_admin_notice(__("Username and licencekey must be entered in settings"), 'error');
		} else {
			$label = new Smartsend_Logistics_Label();
			try{
				$label->createOrder($order,true);
			}
			//catch exception
			catch(Exception $e) {
				if(is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = "Order #".$order->id.": ".$e->getMessage();
				} else {
					$_SESSION['smartsend_errors'] = array("Order #".$order->id.": ".$e->getMessage());
				}
			}

			if($label->isRequest()) {
				try{
					$label->postRequest(true);
					$label->handleRequest();
				} catch(Exception $e) {
					if(is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = "Order #".$order->id.": ".$e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array("Order #".$order->id.": ".$e->getMessage());
					}
				}
			}
		
		}

	}
	
/*****************************************************
 * Process an array of order
 * @ order_ids: list of order
 */
	function smartsend_logistics_process_orders($order_ids) {
		include('api/class.label.php');
		include('api/class.order.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			if(is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = __("Username and licencekey must be entered in settings");
				} else {
					$_SESSION['smartsend_errors'] = array(__("Username and licencekey must be entered in settings"));
				}
		} else {
			$label = new Smartsend_Logistics_Label();
			
			foreach($order_ids as $order_id) {
				$order = new WC_Order( $order_id );
				try{
					$label->createOrder($order,false);
				}
				//catch exception
				catch(Exception $e) {
					if(is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = "Order #".$order_id.": ".$e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array("Order #".$order_id.": ".$e->getMessage());
					}
				}
			}

			if($label->isRequest()) {
				try{
					$label->postRequest(false);
					$label->handleRequest();
				} catch(Exception $e) {
					if(is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = $e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array($e->getMessage());
					}
				}
				
			} else {		
				//smartsend_logistics_admin_notice('crap!', 'error');
			}

		}
	}

	function smartsend_logistics_process_return_orders($order_ids) {
	
		include('api/class.label.php');
		include('api/class.order.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			if(is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = __("Username and licencekey must be entered in settings");
				} else {
					$_SESSION['smartsend_errors'] = array(__("Username and licencekey must be entered in settings"));
				}
		} else {
			$label = new Smartsend_Logistics_Label();
			
			foreach($order_ids as $order_id) {
				$order = new WC_Order( $order_id );
				try{
					$label->createOrder($order,true);
				}
				//catch exception
				catch(Exception $e) {
					if(is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = "Order #".$order_id.": ".$e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array("Order #".$order_id.": ".$e->getMessage());
					}
				}
			}

			if($label->isRequest()) {
				try{
					$label->postRequest(false);
					$label->handleRequest();
				} catch(Exception $e) {
					if(is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = $e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array($e->getMessage());
					}
				}
				
			} else {		
				//smartsend_logistics_admin_notice('crap!', 'error');
			}

		}

	}

/****************************************************/


/*-----------------------------------------------------------------------------------------------------------------------
* 					Add actions to the order info page (single print)	
*----------------------------------------------------------------------------------------------------------------------*/	

/*****************************************************
 * Add order action, 'create label' to singleprint action list
 */

	// add our own item to the order actions meta box
	add_action( 'woocommerce_order_actions', 'smartsend_logistics_add_order_meta_box_action_create_label' );

	// define the item in the meta box by adding an item to the $actions array
	function smartsend_logistics_add_order_meta_box_action_create_label( $actions ) {
		$actions['smartsend_logistics_single_order_action_label'] = __( 'Generate label', 'text_domain' );
		return $actions;
	}

	// process the custom order meta box order action
	add_action( 'woocommerce_order_action_smartsend_logistics_single_order_action_label', 'smartsend_logistics_process_order' );

/****************************************************/

/*****************************************************
 * Add order action, 'create return label' to singleprint action list
 */

	// add our own item to the order actions meta box
	add_action( 'woocommerce_order_actions', 'smartsend_logistics_add_order_meta_box_action_create_return_label' );

	// define the item in the meta box by adding an item to the $actions array
	function smartsend_logistics_add_order_meta_box_action_create_return_label( $actions ) {
		$actions['smartsend_logistics_single_order_action_return_label'] = __( 'Generate return label', 'text_domain' );
		return $actions;
	}

	// process the custom order meta box order action
	add_action( 'woocommerce_order_action_smartsend_logistics_single_order_action_return_label', 'smartsend_logistics_process_return_order' );

/****************************************************/


/*****************************************************
 * Process the order if actions match criteria
 */

	function smartsend_logistics_admin_notice_process() {
		if(isset($_GET['type']) && ($_GET['type'] == 'create_label' || $_GET['type'] == 'create_label_return')){
		
			if($_GET['type']=='create_label') {
				$order = new WC_Order( $_GET['post'] );
				smartsend_logistics_process_order($order);
			}
		
			if($_GET['type']=='create_label_return') {
				$order = new WC_Order( $_GET['post'] );
				smartsend_logistics_process_return_order($order);
			}
		}
		
	}
	add_action( 'admin_notices', 'smartsend_logistics_admin_notice_process' ); 

/*****************************************************/

/*-----------------------------------------------------------------------------------------------------------------------
* 					Add actions to the order list (bulk print)	
*----------------------------------------------------------------------------------------------------------------------*/		

	//future way to add the button.  
	//add_action('bulk_actions-edit-shop_order','smartsend_logistics_add_order_meta_box_actions' );
	
	/**
	 * Step 1: add the custom Bulk Action to the select menus
	 */
	add_action('admin_footer-edit.php','smartsend_logistics_custom_bulk_admin_footer');
	function smartsend_logistics_custom_bulk_admin_footer() {
		global $post_type;
	
		if($post_type == 'shop_order') {
			?>
				<script type="text/javascript">
					jQuery(document).ready(function() {
						jQuery('<option>').val('smartsend_label').text('<?php _e('Generate label')?>').appendTo("select[name='action']");
						jQuery('<option>').val('smartsend_label').text('<?php _e('Generate label')?>').appendTo("select[name='action2']");
						jQuery('<option>').val('smartsend_return_label').text('<?php _e('Generate return label')?>').appendTo("select[name='action']");
						jQuery('<option>').val('smartsend_return_label').text('<?php _e('Generate return label')?>').appendTo("select[name='action2']");
					});
				</script>
			<?php
		}
	}
	
	/**
	 * Step 2: handle the custom Bulk Action
	 * 
	 * Based on the post http://wordpress.stackexchange.com/questions/29822/custom-bulk-action
	 */
	add_action('load-edit.php','smartsend_logistics_custom_bulk_action');
	function smartsend_logistics_custom_bulk_action() {
		global $typenow;
		$post_type = $typenow;

		if($post_type == 'shop_order') {
		
			// get the action
			$wp_list_table = _get_list_table('WP_Posts_List_Table');  // depending on your resource type this could be WP_Users_List_Table, WP_Comments_List_Table, etc
			$action = $wp_list_table->current_action();
                       
			$allowed_actions = array("smartsend_label","smartsend_return_label");
			if(!in_array($action, $allowed_actions)) return;

			// security check
			check_admin_referer('bulk-posts');

			// make sure ids are submitted.  depending on the resource type, this may be 'media' or 'ids'
			if(isset($_REQUEST['post'])) {
				$post_ids = array_map('intval', $_REQUEST['post']);
			}
		
			if(empty($post_ids)) return;
		
			// this is based on wp-admin/edit.php
			$sendback = remove_query_arg( array('exported', 'untrashed', 'deleted', 'ids'), wp_get_referer() );
			if ( ! $sendback )
				$sendback = admin_url( "edit.php?post_type=$post_type" );
		
			$pagenum = $wp_list_table->get_pagenum();
			$sendback = add_query_arg( 'paged', $pagenum, $sendback );
		
			switch($action) {
				case 'smartsend_label':
				
					// if we set up user permissions/capabilities, the code might look like:
					//if ( !current_user_can($post_type_object->cap->export_post, $post_id) )
					//	wp_die( __('You are not allowed to export this post.') );
				
				/*	$smartsend = 0;
					$json = new smartsend_label;
					foreach( $post_ids as $post_id ) {
					
						//if ( !$this->process_order_list_actions($post_id) )
						if ( !process_order_list_actions($post_id) )
							wp_die( __('Error exporting post.') );
	
						$smartsend++;
					} */
					smartsend_logistics_process_orders($post_ids);
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
				
				case 'smartsend_return_label':
				
					smartsend_logistics_process_return_orders($post_ids);
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
			
				default: return;
			}
		
			$sendback = remove_query_arg( array('export', 'message', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',  'post', 'bulk_edit', 'post_view'), $sendback );
		
			//wc_add_notice('test','error');
			wp_redirect($sendback);
			exit();
		}
	}


/*-----------------------------------------------------------------------------------------------------------------------
* 					Display messages
*----------------------------------------------------------------------------------------------------------------------*/	

/*****************************************************
 * Process the order if actions match criteria
 */

	function smartsend_logistics_admin_notice_messages() {
	
		if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
			foreach($_SESSION['smartsend_errors'] as $error) {
				smartsend_logistics_admin_notice($error, 'error');
			}
			unset($_SESSION['smartsend_errors']);
		}
		
		if(isset($_SESSION['smartsend_notification']) && is_array($_SESSION['smartsend_notification'])) {
			foreach($_SESSION['smartsend_notification'] as $notification) {
				smartsend_logistics_admin_notice($notification, 'info');
			}
			unset($_SESSION['smartsend_notification']);
		}
		
		if(isset($_SESSION['smartsend_succeses']) && is_array($_SESSION['smartsend_succeses'])) {
			foreach($_SESSION['smartsend_succeses'] as $succes) {
				smartsend_logistics_admin_notice($succes, 'succes');
			}
			unset($_SESSION['smartsend_succeses']);
		}
		
	}
	add_action( 'admin_notices', 'smartsend_logistics_admin_notice_messages' ); 

/*****************************************************/

/*****************************************************
 * Notification hook at top of the edit page
 */

function smartsend_logistics_admin_notice($message, $type='info') {
    
    switch ($type) {
    	case 'info':
    		$class = 'update-nag';
    		break;
    	case 'error':
    		$class = 'error';
    		break;
    	case 'succes':
    		$class = 'updated';
    		break;
    	default:
    		$class = 'updated';
    		break;
    }
    
    echo "<div class=\"$class\">
    		<p>$message</p>
    	</div>"; 
}

/*****************************************************/


/*-----------------------------------------------------------------------------------------------------------------------
* 					Hooks and function for carriers
*----------------------------------------------------------------------------------------------------------------------*/		

            
/************ Start SwipBox method ***************************************/
	function Smartsend_Logistics_SwipBox() {
		include('class.smartsend.swipbox.php');
	}
	add_action( 'woocommerce_shipping_init', 'Smartsend_Logistics_SwipBox' );
 
	function Smartsend_Logistics_add_SwipBox( $methods ) {
		$methods[] = 'Smartsend_Logistics_SwipBox';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'Smartsend_Logistics_add_SwipBox' );
/************ end SwipBox method ******************************************/


/************ Start GLS method ******************************************/
	function Smartsend_Logistics_GLS() {
		include('class.smartsend.gls.php');
	}

	add_action( 'woocommerce_shipping_init', 'Smartsend_Logistics_GLS' );
	
	function Smartsend_Logistics_add_GLS( $methods ) {
		$methods[] = 'Smartsend_Logistics_GLS';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'Smartsend_Logistics_add_GLS' );	
/************ end gls method ********************************************/	

	
/************ Start PostDanmark method **********************************/
	function Smartsend_Logistics_PostDanmark() {
		include('class.smartsend.postdanmark.php');
	}

	add_action( 'woocommerce_shipping_init', 'Smartsend_Logistics_PostDanmark' );
	
	function Smartsend_Logistics_add_PostDanmark( $methods ) {
		$methods[] = 'Smartsend_Logistics_PostDanmark';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'Smartsend_Logistics_add_PostDanmark' );
/************* end PostDanmark method **********************************/


/************* Start Bring method **************************************/
	function Smartsend_Logistics_Bring() {
				include('class.smartsend.bring.php');
	}

	add_action( 'woocommerce_shipping_init', 'Smartsend_Logistics_Bring' );
	
	function Smartsend_Logistics_add_Bring( $methods ) {
		$methods[] = 'Smartsend_Logistics_Bring';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'Smartsend_Logistics_add_Bring' );	
/************* end Bring method *****************************************/

/************* Start Posten method **************************************/
	function Smartsend_Logistics_Posten() {
		include('class.smartsend.posten.php');
	}

	add_action( 'woocommerce_shipping_init', 'Smartsend_Logistics_Posten' );
	
	function Smartsend_Logistics_add_Posten( $methods ) {
		$methods[] = 'Smartsend_Logistics_Posten';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'Smartsend_Logistics_add_Posten' );
/************* end Posten method ****************************************/
			
			
/************* Start All pickup Points method ***************************/
	function Smartsend_Logistics_PickupPoints() {
		include('class.smartsend.pickuppoints.php');
	}

	add_action( 'woocommerce_shipping_init', 'Smartsend_Logistics_PickupPoints' );
	
	function Smartsend_Logistics_add_PickupPoints( $methods ) {
		$methods[] = 'Smartsend_Logistics_PickupPoints';
		return $methods;
	}
 
	add_filter( 'woocommerce_shipping_methods', 'Smartsend_Logistics_add_PickupPoints' );
/************* end Pickup Points method *********************************/
	
	function show_description($shipping_description) {
		echo "<p class='description_testship'>$shipping_description</p>";
	}
	
/*-----------------------------------------------------------------------------------------------------------------------
* 					Add Store Pick up loaction on chechout page	
*----------------------------------------------------------------------------------------------------------------------*/		
	/*if ( ! function_exists( 'is_ajax' ) ) {
                   function is_ajax() {
                            return false;
                    }
        }*/
	$x = get_option( 'woocommerce_pickup_display_mode1', 0 );
	if($x==1) {
		add_filter( 'smartsend_logistics_dropdown_hook' , 'Smartsend_Logistics_custom_store_pickup_field');
	} else {
		add_filter( 'woocommerce_review_order_after_cart_contents' , 'Smartsend_Logistics_custom_store_pickup_field');
	}

        
	function Smartsend_Logistics_custom_store_pickup_field( $fields ) {
               
		//if(!isset($_REQUEST['post_data'])) return false;
               
		parse_str($_REQUEST['post_data'],$request);
		$shipping_method = $request['shipping_method'][0];
					
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
		$display_selectbox = false;
		if(!empty($shipping_method)){
			$chkpickup = $shipping_method; 
			$shippingTitle = $shipping_method;
			$pos = strpos($chkpickup, 'pickup');
			$shipping_method = str_replace("_pickup", '',$shipping_method);
			 
			switch( $shipping_method ){
			
				case 'smartsend_swipbox': 
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'SwipBox';
					$pickup_loc = Smartsend_Logistics_API_Call('swipbox',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'smartsend_posten': 
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'Posten';
					$pickup_loc = Smartsend_Logistics_API_Call('posten',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'smartsend_gls':
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'GLS';
					$pickup_loc = Smartsend_Logistics_API_Call('gls',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'smartsend_postdanmark': 
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'PostDanmark';
					$pickup_loc = Smartsend_Logistics_API_Call('postdanmark',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'smartsend_bring': 
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'Bring';
					$pickup_loc = Smartsend_Logistics_API_Call('bring',$address_1,$address_2,$city,$zip,$country);
					break;
				case 'smartsend_pickuppoints':
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'Closest';
					
					$carriers = array();
					if(get_option( 'woocommerce_smartsend_pickuppoints_active_pickup_PostDanmark', 1 ) == 1)
						$carriers[] = 'postdanmark';
					if(get_option( 'woocommerce_smartsend_pickuppoints_active_pickup_SwipBox', 1 ) == 1)
						$carriers[] = 'swipbox';
					if(get_option( 'woocommerce_smartsend_pickuppoints_active_pickup_Bring', 1 ) == 1)
						$carriers[] = 'bring';
					if(get_option( 'woocommerce_smartsend_pickuppoints_active_pickup_GLS', 1 ) == 1)
						$carriers[] = 'gls';
					
					$pickup_loc = Smartsend_Logistics_API_Call(implode(",",$carriers),$address_1,$address_2,$city,$zip,$country);
					break;	
                                
			} 
							
		}
               ?>
               <script>
                   jQuery(document).ready(function(){
                        var found = false;
                            jQuery( ".shipping_method" ).each(function( index ) { 
                            
                            var a = jQuery( this ).val() ;
                            if (a.indexOf('smartsend') > -1) { 
                                found = true;
                            }
                          });
                          if(!found){
                              jQuery('.selectstore').remove();
                          }
                   });
               </script>
		<?php if($display_selectbox){ 
		?>
		<script>
                    
			   jQuery(document).ready(function(){
                                    var numItems =  jQuery('.selectstore').length;
                                    if(numItems > 1){
                                        jQuery('.selectstore').last().remove();
                                    }
				   jQuery('.shipping_method, #ship-to-different-address-checkbox, #billing_country').click(function(){
                                          jQuery('.selectstore').remove();
					   jQuery('.pic_error, .pic_script').remove();
				   });
			   });
		</script>
		<?php if(!empty($pickup_loc) && is_array($pickup_loc)):?>
                
		<div id='selectpickup' class="selectstore"> <?php echo __(get_option('woocommerce_pickup_display_dropdown_legend', 'Choose Store Location'),'woocommerce'); echo ' ('.$shippingTitle.')'; ?>
		<?php if(!empty($pickup_loc) && is_array($pickup_loc)):?>				
		<select name="store_pickup" class="pk-drop">
			<option value=""><?php echo __(get_option('woocommerce_pickup_display_dropdown_text', 'Select pickup location'),'woocommerce'); ?></option>
			<?php foreach($pickup_loc as $picIndex => $picValue) { ?>
				<option value='<?php echo $picIndex?>'><?php echo $picValue?></option>
			<?php }?>
		</select>
                    
		<?php else:?>
			<?php //echo ' : Delivered to closest pickup point.'?>
		<?php endif;?>
		</div>
		<?php else:?>
			<?php echo '<div id="selectpickup" class="selectstore">'; echo __(get_option('woocommerce_pickup_display_dropdown_nopoints', 'Delivered to closest pickup point'),'woocommerce'); echo '</div>'; ?>
		<?php endif;?>
	<?php
                }

	}
        
        //add_action('woocommerce_review_order_after_shipping','remove_pickup_dropdown_not_needed');
       // function remove_pickup_dropdown_not_needed(){
       // }
	add_action('woocommerce_checkout_process', 'smartsend_pickup_select_process');
	function smartsend_pickup_select_process() {
		if (empty($_POST['store_pickup']) && isset($_POST['store_pickup'])) {
         ?>
		<div class="pic_script">
			<script>
				jQuery(document).ready(function(){
					jQuery('#place_order').click(function(){
						jQuery('.pic_error, .pic_script').remove();
					});
				});
			</script>
		</div>
	<?php
			echo '<p style="color:red" class="pic_error">';
			echo __(get_option('woocommerce_pickup_display_dropdown_error', 'Please select a pickup loaction'),'woocommerce');
			die;
		}
			   
	}		
			
	 include('smartsend-api-functions.php');
	 include('settings.php');
}

	 include('class.smartsend.primary.php');
?>
