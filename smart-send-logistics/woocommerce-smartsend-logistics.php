<?php	
/*
	Plugin Name: Smart Send Logistics
	Plugin URI: http://smartsend.dk/integrationer/woocommerce
	Description: Table rate shipping methods with Post Danmark, GLS and Bring pickup points. Listed in a dropdown sorted by distance from shipping adress.
	Author: Smart Send ApS
	Author URI: http://www.smartsend.dk
	Text Domain: smart-send-logistics
	Domain Path: /lang
	Version: 7.0.16

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

	require_once 'smartsend-api-functions.php';
	require_once 'settings.php';
	require_once 'class.smartsend.primary.php';

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
* 					Language folder for translations
*----------------------------------------------------------------------------------------------------------------------*/	

	add_action('plugins_loaded', 'smartsend_logistics_load_textdomain');
	function smartsend_logistics_load_textdomain() {
		load_plugin_textdomain( 'smart-send-logistics', false, dirname( plugin_basename(__FILE__) ) . '/lang/' );
	}

/*-----------------------------------------------------------------------------------------------------------------------
* 					Run activation functions when the plugin is activated.
*----------------------------------------------------------------------------------------------------------------------*/	
	
	register_activation_hook( __FILE__, 'smartsend_logistics_activate' );
	function smartsend_logistics_activate() {
	
		smartsend_logistics_shipping_method_init();
		
		$carriers = array('PostDanmark','Posten','GLS','Bring','PickupPoints');
		
		foreach($carriers as $carrier) {
		
			switch ($carrier) {
				case 'PostDanmark':
					//Load the Post Danmark class
					$carrier_controller = new Smartsend_Logistics_PostDanmark();
					break;
				case 'Posten':
					//Load the Posten class
					$carrier_controller = new Smartsend_Logistics_Posten();
					break;
				case 'GLS':
					//Load the GLS class
					$carrier_controller = new Smartsend_Logistics_GLS();
					break;
				case 'Bring':
					//Load the Bring class
					$carrier_controller = new Smartsend_Logistics_Bring();
					break;
				case 'PickupPoints':
					//Load the Pickup class
					$carrier_controller = new Smartsend_Logistics_PickupPoints();
					break;
			}
			
			// Get the table rates saved for the carrier
			$carrier_table_rates = $carrier_controller->get_table_rates();
			if( empty($carrier_table_rates) ) {
				// No table rates was saved. Save the default ones.
				$carrier_controller->save_default_table_rates();
			}
			
		}
	}


/*-----------------------------------------------------------------------------------------------------------------------
* 					Functions that deals with orders
*----------------------------------------------------------------------------------------------------------------------*/	

	function smartsend_logistics_register_session(){
        if( !session_id() ) {
            session_start();
        }
    }
    add_action('init','smartsend_logistics_register_session');

/*****************************************************
 * Process an order
 * @ order: order object
 */     
        
	function smartsend_logistics_process_order($order) {
		include('api/class.label.php');
		include('api/class.order.php');
		include('api/class.order.woocommerce.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			smartsend_logistics_admin_notice(__("Username and licencekey must be entered in settings",'smart-send-logistics'), 'error');
		} else {
			$label = new Smartsend_Logistics_Label();
			try{
				$label->createOrder($order,false);
			}
			//catch exception
			catch(Exception $e) {
				if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
		include('api/class.order.woocommerce.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			smartsend_logistics_admin_notice(__("Username and licencekey must be entered in settings",'smart-send-logistics'), 'error');
		} else {
			$label = new Smartsend_Logistics_Label();
			try{
				$label->createOrder($order,true);
			}
			//catch exception
			catch(Exception $e) {
				if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
						$_SESSION['smartsend_errors'][] = "Order #".$order->id.": ".$e->getMessage();
					} else {
						$_SESSION['smartsend_errors'] = array("Order #".$order->id.": ".$e->getMessage());
					}
				}
			}
		
		}

	}
	
	function smartsend_logistics_process_normal_return_order($order) {
	
		include('api/class.label.php');
		include('api/class.order.php');
		include('api/class.order.woocommerce.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			smartsend_logistics_admin_notice(__("Username and licencekey must be entered in settings",'smart-send-logistics'), 'error');
		} else {
			$label = new Smartsend_Logistics_Label();
			try{
				$label->createOrder($order,false);
				$label->createOrder($order,true);
			}
			//catch exception
			catch(Exception $e) {
				if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = "Order #".$order->id.": ".$e->getMessage();
				} else {
					$_SESSION['smartsend_errors'] = array("Order #".$order->id.": ".$e->getMessage());
				}
			}

			if($label->isRequest()) {
				try{
					$label->postRequest(false);
					$label->handleRequest();
				} catch(Exception $e) {
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
		include('api/class.order.woocommerce.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = __("Username and licencekey must be entered in settings",'smart-send-logistics');
				} else {
					$_SESSION['smartsend_errors'] = array(__("Username and licencekey must be entered in settings",'smart-send-logistics'));
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
		include('api/class.order.woocommerce.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = __("Username and licencekey must be entered in settings",'smart-send-logistics');
				} else {
					$_SESSION['smartsend_errors'] = array(__("Username and licencekey must be entered in settings",'smart-send-logistics'));
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
	
	function smartsend_logistics_process_normal_return_orders($order_ids) {
	
		include('api/class.label.php');
		include('api/class.order.php');
		include('api/class.order.woocommerce.php');
	
		if((get_option( 'smartsend_logistics_username', '' ) == '' || get_option( 'smartsend_logistics_licencekey', '' ) == '') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
					$_SESSION['smartsend_errors'][] = __("Username and licencekey must be entered in settings",'smart-send-logistics');
				} else {
					$_SESSION['smartsend_errors'] = array(__("Username and licencekey must be entered in settings",'smart-send-logistics'));
				}
		} else {
			$label = new Smartsend_Logistics_Label();
			
			foreach($order_ids as $order_id) {
				$order = new WC_Order( $order_id );
				try{
					$label->createOrder($order,false);
					$label->createOrder($order,true);
				}
				//catch exception
				catch(Exception $e) {
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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
					if(isset($_SESSION['smartsend_errors']) && is_array($_SESSION['smartsend_errors'])) {
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


/*-----------------------------------------------------------------------------------------------------------------------
* 					Add order actions to the order action dropdown in the order action meta box
*					Details: http://neversettle.it/add-custom-order-action-woocommerce/
*----------------------------------------------------------------------------------------------------------------------*/	

/*****************************************************
 * Step 1: add our own item to the order actions meta box
 */
	add_action( 'woocommerce_order_actions', 'smartsend_logistics_add_order_meta_box_actions' );
	function smartsend_logistics_add_order_meta_box_actions( $actions ) {
		$actions['smartsend_logistics_single_order_action_label'] 				= __( 'Generate label','smart-send-logistics');
		$actions['smartsend_logistics_single_order_action_return_label'] 		= __( 'Generate return label','smart-send-logistics');
		$actions['smartsend_logistics_single_order_action_normal_return_label'] = __( 'Generate normal and return label','smart-send-logistics');
		return $actions;
	}

/*****************************************************
 * Step 2: process the custom order meta box order actions
 */
	add_action( 'woocommerce_order_action_smartsend_logistics_single_order_action_label', 'smartsend_logistics_meta_box_process_order' );
	add_action( 'woocommerce_order_action_smartsend_logistics_single_order_action_return_label', 'smartsend_logistics_meta_box_process_return_order' );
	add_action( 'woocommerce_order_action_smartsend_logistics_single_order_action_normal_return_label', 'smartsend_logistics_meta_box_process_normal_return_order' );

/*****************************************************
 * Step 3: Process the order if actions match
 */
 	/*
	 * Create a normal shipping label for the order inputted
	 *
 	 * @param object $order
	 * @return void
	 */
 	function smartsend_logistics_meta_box_process_order( $order ) {
		smartsend_logistics_process_order( $order );
	}
	
	/*
	 * Create a return shipping label for the order inputted
	 *
 	 * @param object $order
	 * @return void
	 */
	function smartsend_logistics_meta_box_process_return_order( $order ) {
		smartsend_logistics_process_return_order( $order );
	}
	
	/*
	 * Create a normal shipping label and a then a return label for the same order
	 *
 	 * @param object $order
	 * @return void
	 */
	function smartsend_logistics_meta_box_process_normal_return_order( $order ) {
		smartsend_logistics_process_normal_return_order( $order );
	}

/*-----------------------------------------------------------------------------------------------------------------------
* 					Add order actions to custom Smart Send meta box on order info page
*----------------------------------------------------------------------------------------------------------------------*/	

/*****************************************************
 * Step 1: add custom meta box
 */
	add_action( 'add_meta_boxes', 'Smartsend_Logistics_add_meta_boxes' );
	function Smartsend_Logistics_add_meta_boxes(){
		add_meta_box(
			'woocommerce-order-shipping-my-custom',
			__( 'Smart Send Logistics' ),
			'Smartsend_Logistics_order_shipping_custom_metabox',
			'shop_order',
			'side',
			'default'
		);
	}

/*****************************************************
 * Step 2: add content to the meta box
 */
	function Smartsend_Logistics_order_shipping_custom_metabox( $post ){

		$order = wc_get_order( $post->ID );

		$line_items_shipping = $order->get_items( 'shipping' );
		$shipMethod = '';
		if(!empty($line_items_shipping)){
			foreach ( $line_items_shipping as $item_id => $item ) {
				$shipMethod_id = ! empty( $item['method_id'] ) ? esc_html( $item['method_id'] ) : __( 'Shipping','smart-send-logistics');
				$shipMethod=  ! empty( $item['name'] ) ? esc_html( $item['name'] ) : __( 'Shipping','smart-send-logistics');
			}
		}
	
		$store_pickup = get_post_custom($order->id);
		
		echo '<p><h3>Shipping Method</h3>'.$shipMethod;
		//echo ' ('.$shipMethod_id.')';
		echo '</p>';
				   
		Smartsend_Logistics_display_store_order_details($order,true,false,'h3');
		
		echo '<br/>';
		echo '<a href="post.php?post='.$post->ID.'&action=edit&type=create_label" class="button button-primary">'.__( 'Generate label','smart-send-logistics').'</a><br/><br/>';
		echo '<a href="post.php?post='.$post->ID.'&action=edit&type=create_label_return" class="button">'.__( 'Generate return label','smart-send-logistics').'</a><br/><br/>';
		echo '<a href="post.php?post='.$post->ID.'&action=edit&type=create_label_normal_return" class="button">'.__( 'Generate normal and return label','smart-send-logistics').'</a>'; 
    }
	
/*****************************************************
 * Step 3: Process the order if actions match the ones from the meta box buttons
 */
	add_action( 'admin_notices', 'smartsend_logistics_admin_notice_process' );
	function smartsend_logistics_admin_notice_process() {
		if(isset($_GET['type']) && ($_GET['type'] == 'create_label' || $_GET['type'] == 'create_label_return' || $_GET['type'] == 'create_label_normal_return')){
		
			if($_GET['type']=='create_label') {
				$order = new WC_Order( $_GET['post'] );
				smartsend_logistics_process_order($order);
			}
		
			if($_GET['type']=='create_label_return') {
				$order = new WC_Order( $_GET['post'] );
				smartsend_logistics_process_return_order($order);
			}
			
			if($_GET['type']=='create_label_normal_return') {
				$order = new WC_Order( $_GET['post'] );
				smartsend_logistics_process_normal_return_order($order);
			}
		}
		
	}

/*-----------------------------------------------------------------------------------------------------------------------
* 					Add actions to the order list (bulk print)	
*					Description: https://www.skyverge.com/blog/add-custom-bulk-action/
*					Description: http://wordpress.stackexchange.com/questions/29822/custom-bulk-action
*----------------------------------------------------------------------------------------------------------------------*/		

	/*
	//future way (WooCommerce >= 2.4.5) to add the button.  
	add_filter('bulk_actions-edit-shop_order','smartsend_logistics_add_bulk_actions' );
	function smartsend_logistics_add_bulk_actions($actions) {
		$actions['smartsendtest'] = 'test';
		return $actions;
	} */
	
/*****************************************************
 * Step 1: Add the custom bulk action to the select menus of the order grid
 */
	add_action('admin_footer-edit.php','smartsend_logistics_custom_bulk_admin_footer');
	function smartsend_logistics_custom_bulk_admin_footer() {
		global $post_type;
	
		if($post_type == 'shop_order') {
			?>
				<script type="text/javascript">
					jQuery(document).ready(function() {
						jQuery('<option>').val('smartsend_label').text('<?php _e('Generate label','smart-send-logistics')?>').appendTo("select[name='action']");
						jQuery('<option>').val('smartsend_label').text('<?php _e('Generate label','smart-send-logistics')?>').appendTo("select[name='action2']");
						jQuery('<option>').val('smartsend_return_label').text('<?php _e('Generate return label','smart-send-logistics')?>').appendTo("select[name='action']");
						jQuery('<option>').val('smartsend_return_label').text('<?php _e('Generate return label','smart-send-logistics')?>').appendTo("select[name='action2']");
						jQuery('<option>').val('smartsend_normal_return_label').text('<?php _e('Generate normal and return label','smart-send-logistics')?>').appendTo("select[name='action']");
						jQuery('<option>').val('smartsend_normal_return_label').text('<?php _e('Generate normal and return label','smart-send-logistics')?>').appendTo("select[name='action2']");
					});
				</script>
			<?php
		}
	}
	
/*****************************************************
 * Step 2: Handle the custom bulk actions
 */
	add_action('load-edit.php','smartsend_logistics_custom_bulk_action');
	function smartsend_logistics_custom_bulk_action() {
		global $typenow;
		$post_type = $typenow;

		if($post_type == 'shop_order') {
		
			// get the action
			$wp_list_table = _get_list_table('WP_Posts_List_Table');  // depending on your resource type this could be WP_Users_List_Table, WP_Comments_List_Table, etc
			$action = $wp_list_table->current_action();
                       
			$allowed_actions = array("smartsend_label","smartsend_return_label", "smartsend_normal_return_label");
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
					//	wp_die( __('You are not allowed to export this post.','smart-send-logistics') );
				
				/*	$smartsend = 0;
					$json = new smartsend_label;
					foreach( $post_ids as $post_id ) {
					
						//if ( !$this->process_order_list_actions($post_id) )
						if ( !process_order_list_actions($post_id) )
							wp_die( __('Error exporting post.','smart-send-logistics') );
	
						$smartsend++;
					} */
					smartsend_logistics_process_orders($post_ids);
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
				
				case 'smartsend_return_label':
				
					smartsend_logistics_process_return_orders($post_ids);
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
					
				case 'smartsend_normal_return_label':
				
					smartsend_logistics_process_normal_return_orders($post_ids);
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
			
				default: return;
			}
		
			$sendback = remove_query_arg( array_merge(array('export', 'message', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',  'post', 'bulk_edit', 'post_view'),$allowed_actions), $sendback );
		
			//wc_add_notice('test','error');
			wp_redirect($sendback);
			exit();
		}
	}


/*-----------------------------------------------------------------------------------------------------------------------
* 					Display messages (succes, notification and errors)
*----------------------------------------------------------------------------------------------------------------------*/	

/*****************************************************
 * Notification hook at top of the edit page
 * The succeses are taken from the $_SESSION called smartsend_succeses
 * The notification are taken from the $_SESSION called smartsend_notification
 * The errors are taken from the $_SESSION called smartsend_errors
 */
	add_action( 'admin_notices', 'smartsend_logistics_admin_notice_messages' ); 
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

/*****************************************************
 * Print a message (succes, notification and errors) div class
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

/*-----------------------------------------------------------------------------------------------------------------------
* 					Add carriers
*----------------------------------------------------------------------------------------------------------------------*/

 	add_filter( 'woocommerce_shipping_methods', 'smartsend_logistics_add_shipping_methods' );
 	function smartsend_logistics_add_shipping_methods( $methods ) {
 		// Post Danmark
 		$methods[] = 'Smartsend_Logistics_PostDanmark';
 		// Posten
 		$methods[] = 'Smartsend_Logistics_Posten';
 		// GLS
 		$methods[] = 'Smartsend_Logistics_GLS';
 		// Bring
   		$methods[] = 'Smartsend_Logistics_Bring';
   		// Pickup points
   		$methods[] = 'Smartsend_Logistics_PickupPoints';
   		
   		return $methods;
 	}

 	add_action( 'woocommerce_shipping_init', 'smartsend_logistics_shipping_method_init' );
 	function smartsend_logistics_shipping_method_init(){
 		// Post Danmark
 		require_once 'class.smartsend.postdanmark.php';
 		// Posten
 		require_once 'class.smartsend.posten.php';
 		// GLS
 		require_once 'class.smartsend.gls.php';
 		// Bring
   		require_once 'class.smartsend.bring.php';
   		// Pickup points
   		require_once 'class.smartsend.pickuppoints.php';
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
       
        //If post_data is not set, return false       
		if(!isset($_REQUEST['post_data'])) return false;
               
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
				case 'smartsendpoints':
				// Shipping method is smartsend_pickuppoints_pickup which is renamed to smartsendpoints because '_pickup' is removed
					if ($pos !== false) {
						$display_selectbox = true;
					}
					$shippingTitle = 'Closest';
					
					$carriers = array();
					if(get_option( 'woocommerce_smartsend_pickuppoints_active_pickup_PostDanmark', 1 ) == 1)
						$carriers[] = 'postdanmark';
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
					var a = jQuery( this ).val();
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
		
		<!-- script to update checkout if zipcode is changed -->
		<script>   
			jQuery(document).ready(function(){
				var postcode = jQuery('.validate-postcode').find('input');
				
				postcode.change(function() {
					jQuery('.selectstore').remove();
					jQuery('.pic_error, .pic_script').remove();
					jQuery('body').trigger('update_checkout');
				});
			});
		</script>
		<?php if(!empty($pickup_loc) && is_array($pickup_loc)):?>
                
			<div id='selectpickup' class="selectstore"> <?php echo __('Select a pickup location','smart-send-logistics'); echo ' ('.$shippingTitle.')'; ?>
			<?php if(!empty($pickup_loc) && is_array($pickup_loc)):?>				
				<select name="store_pickup" class="pk-drop">
					<option value=""><?php echo __('Select a pickup location','smart-send-logistics'); ?></option>
					<?php foreach($pickup_loc as $picIndex => $picValue) { ?>
					<option value='<?php echo $picIndex?>'><?php echo $picValue?></option>
					<?php }?>
				</select>
                    
			<?php else:?>
				<?php //echo ' : Delivered to closest pickup point.'?>
			<?php endif;?>
			</div>
		<?php else:?>
			<div id="selectpickup" class="selectstore">
				<?php echo __('Delivered to closest pickup point','smart-send-logistics'); ?>
			</div>
		<?php endif;?>
	<?php
    	}

	}
        
	#Process the checkout and validate store location
	add_action('woocommerce_checkout_process', 'Smartsend_Logistics_pickup_checkout_field_process');
	function Smartsend_Logistics_pickup_checkout_field_process() {
		global $woocommerce;
		// Check if set, if its not set add an error. This one is only requite for companies
		if (isset($_POST['store_pickup']) && $_POST['store_pickup']=='') {
			wc_add_notice( __('Select pickup location','smart-send-logistics'), 'error' );
		}
	}
			
}

	 
?>
