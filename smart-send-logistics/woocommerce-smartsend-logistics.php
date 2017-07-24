<?php	
/*
	Plugin Name: Smart Send Logistics
	Plugin URI: http://smartsend.dk/integrationer/woocommerce
	Description: Table rate shipping methods with flexible conditions determining the rate and even let the customer chose a pick-up point during checkout. Integrates the shipping methods directly with carrier systems and create PDF labels directly from the backend.
	Author: Smart Send ApS
	Author URI: http://www.smartsend.dk
	Text Domain: smart-send-logistics
	Domain Path: /lang
	Version: 7.1.9

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
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if(!is_network_admin()){
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) || is_plugin_active_for_network('woocommerce/woocommerce.php')) {

	require_once 'smartsend-api-functions.php';
	require_once 'settings.php';
	require_once 'class.smartsend.primary.php';
	require_once 'smartsend-pickuppoint.php';
	require_once 'smartsend-flexdelivery.php';
	
/*-----------------------------------------------------------------------------------------------------------------------
* 					Plugin update hook
*----------------------------------------------------------------------------------------------------------------------*/	

	$smartsend_logistics_file   = basename( __FILE__ );
	$smartsend_logistics_folder = basename( dirname( __FILE__ ) );
	$smartsend_logistics_hook = "in_plugin_update_message-{$smartsend_logistics_folder}/{$smartsend_logistics_file}";
	add_action( $smartsend_logistics_hook, 'smartsend_logistics_update_message_wpse_87051', 10, 2 ); 
	function smartsend_logistics_update_message_wpse_87051( $plugin_data, $r )
	{
		if(isset($plugin_data['new_version']) && isset($plugin_data['Version']) && smartsend_logistics_major_version_compare($plugin_data['new_version'], $plugin_data['Version'])) {
			echo '<div class="woothemes-updater-plugin-upgrade-notice">'.__('This is a major update. Please go through settings once the module is updated and verify that these are as expected.','smart-send-logistics').'</div>';
		}
	}	

	function  smartsend_logistics_major_version_compare($new_version, $old_version,$delimiter_position=2) {
		$new = explode(".",$new_version);
		$old = explode(".",$old_version);
	
		return version_compare(implode(".",array_slice($new, 0, $delimiter_position)), implode(".",array_slice($old, 0, $delimiter_position)));
	}

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
* 					Change the meta data from plugin list
*----------------------------------------------------------------------------------------------------------------------*/	
	
	add_filter( 'plugin_row_meta', 'smartsend_logistics_plugin_row_meta', 10, 2 );
	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param	mixed $links Plugin Row Meta
	 * @param	mixed $file  Plugin Base file
	 * @return	array
	 */
	function smartsend_logistics_plugin_row_meta( $links, $file ) {
		if ( strpos( $file, 'woocommerce-smartsend-logistics.php' ) !== false ) {
			$row_meta = array(
				'installation'	=> '<a href="' . esc_url( apply_filters( 'smartsend_logistics_installation_url', 'http://smartsend.dk/woocommerce/installation/' ) ) . '" title="' . esc_attr( __( 'Installation guide','smart-send-logistics' ) ) . '" target="_blank">' . __( 'Installation guide','smart-send-logistics' ) . '</a>',
				'configuration'	=> '<a href="' . esc_url( apply_filters( 'smartsend_logistics_configuration_url', 'http://smartsend.dk/woocommerce/configuration/' ) ) . '" title="' . esc_attr( __( 'Configuration guide','smart-send-logistics' ) ) . '" target="_blank">' . __( 'Configuration guide','smart-send-logistics' ) . '</a>',
				'support'		=> '<a href="' . esc_url( apply_filters( 'smartsend_logistics_support_url', 'http://smartsend.dk/support/' ) ) . '" title="' . esc_attr( __( 'Support','smart-send-logistics' ) ) . '" target="_blank">' . __( 'Support','smart-send-logistics' ) . '</a>',
			);
			
			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}

/*-----------------------------------------------------------------------------------------------------------------------
* 					Run activation functions when the plugin is activated.
*----------------------------------------------------------------------------------------------------------------------*/	
	
	register_activation_hook( __FILE__, 'smartsend_logistics_activate' );
	function smartsend_logistics_activate() {
	
		smartsend_logistics_shipping_method_init();
		
		$carriers = array('PostDanmark','Posten','GLS','Bring');
		
		foreach($carriers as $carrier) {
		
			switch ($carrier) {
				case 'PostDanmark':
					//Load the Post Danmark class
					$carrier_controller = new Smartsend_Logistics_Postdanmark();
					break;
				case 'Posten':
					//Load the Posten class
					$carrier_controller = new Smartsend_Logistics_Posten();
					break;
				case 'GLS':
					//Load the GLS class
					$carrier_controller = new Smartsend_Logistics_Gls();
					break;
				case 'Bring':
					//Load the Bring class
					$carrier_controller = new Smartsend_Logistics_Bring();
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
* 					Miscellaneous functions
*----------------------------------------------------------------------------------------------------------------------*/	

	function smartsend_logistics_get_woocommerce_version() {
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
* 					Functions that deals with orders
*----------------------------------------------------------------------------------------------------------------------*/	

	/*
	 * Function to get carrier and shipping method
	 *
	 * @param string $shipping_method_id is the id of the shipping method
	 *
	 * @return array containing field 'carrier' and 'shipping_method'
	 *
	 */
	function smartsend_logistics_get_shipping_method_and_carrier_from_id($shipping_method_id) {
		$return_shipping_method_array = false;
	
		if(strpos($shipping_method_id, 'free_shipping') !== false) {
			$shipping_method_id = get_option( 'smartsend_logistics_wc_shipping_free_shipping','free_shipping');
		}
	
		if (strpos($shipping_method_id, 'smartsend') !== false) {
		
			$shipping_method_array = explode("_", $shipping_method_id);
		
			if(is_array($shipping_method_array)) {
				foreach($shipping_method_array as $key => $value) {
					$isSmartsend = ($value == 'smartsend' ? true : false);
					$hasFields = (isset($shipping_method_array[$key+1]) && isset($shipping_method_array[$key+2]) ? true : false);
					if($isSmartsend && $hasFields) {
						$return_shipping_method_array = array(
							'carrier'			=> $shipping_method_array[$key+1],
							'shipping_method'	=> $shipping_method_array[$key+2]
							);
					}
				}
			}
		
		}
	
		return $return_shipping_method_array;
	}
	
	/*
	 * Function to get shipping method
	 *
	 * @param string $shipping_method_id is the id of the shipping method
	 *
	 * @return string containing shipping method
	 *
	 */
	function smartsend_logistics_get_shipping_method_from_id($shipping_method_id) {
		
		$shipping_method_array = smartsend_logistics_get_shipping_method_and_carrier_from_id($shipping_method_id);
		
		if(isset($shipping_method_array['shipping_method']) && $shipping_method_array['shipping_method'] != '') {
			return $shipping_method_array['shipping_method'];
		} else {
			return $shipping_method_id;
		}
	}
	
	/*
	 * Function to get carrier
	 *
	 * @param string $shipping_method_id is the id of the shipping method
	 *
	 * @return string containing carrier
	 *
	 */
	function smartsend_logistics_get_shipping_carrier_from_id($shipping_method_id) {
		
		$shipping_method_array = smartsend_logistics_get_shipping_method_and_carrier_from_id($shipping_method_id);
		
		if(isset($shipping_method_array['carrier']) && $shipping_method_array['carrier'] != '') {
			return $shipping_method_array['carrier'];
		} else {
			return $shipping_method_id;
		}
	}

	/*
	 * Function to generate a label
	 *
	 * @param array|string $order_ids is an array of the id of the orders to include in the API call or just a string of a single order id
	 * @param boolean $return indicated if the label is a normal (false) or return (true) label
	 *
	 * @return void
	 *
	 */
	 function smartsend_logistics_create_label_action($order_ids,$return=false) {
 	
 		require_once 'api/class.label.php';
		require_once 'api/class.label.woocommerce.php';
		require_once 'api/class.order.php';
		require_once 'api/class.order.woocommerce.php';
		
 		$label = new Smartsend_Logistics_Label_Woocommerce();
 	
 		if(is_array($order_ids) && !empty($order_ids)) {
 			$label->setRequestType('bulk');
			foreach($order_ids as $order_id) {
				$order = new WC_Order( $order_id );
				try{
					if((string)$return == 'both') {
						$label->addOrderToRequest($order,false);
						$label->addOrderToRequest($order,true);
					} else {
						$label->addOrderToRequest($order,$return);
					}
				}
				//catch exception
				catch(Exception $e) {
					$label->addErrorMessage( __('Order','smart-send-logistics') . ' ' . $order->get_id() . ': ' . $e->getMessage() );
				}
			}
		} elseif( method_exists($order_ids,'get_id' ) && $order_ids->get_id() != '') {
			$order = $order_ids;
			try{
				if((string)$return == 'both') {
					$label->setRequestType('bulk');
					$label->addOrderToRequest($order,false);
					$label->addOrderToRequest($order,true);
				} else {
					$label->setRequestType('single');
					$label->addOrderToRequest($order,$return);
				}
			}
			//catch exception
			catch(Exception $e) {
				$label->addErrorMessage( __('Order','smart-send-logistics') . ' ' . $order->get_id() . ': ' . $e->getMessage() );
			}
		} else {
			$label->addErrorMessage( __('No orders selected','smart-send-logistics')); 
		}
	
		if( $label->hasRequestOrders() ) {
			try{
				$label->sendRequest();
				$label->handleApiReponse();
			} catch(Exception $e) {
				$label->addErrorMessage( $e->getMessage() );
			}
		}
	
		$label->showResult();
 	
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
                                
  		// this is based on wp-admin/post.php
                $sendback = remove_query_arg( array('message'), wp_get_referer() );
                if ( ! $sendback )
                 $sendback = admin_url( "post.php?post=".$_POST['post_ID']."&action=edit" );

                $sendback = add_query_arg( array('smartsend_type' => 'create_label'), $sendback );
                //$sendback = remove_query_arg( array('export', 'message', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',   'bulk_edit', 'post_view'), $sendback );
                //wc_add_notice('test','error');
                wp_redirect($sendback);
                exit();
	}
	
	/*
	 * Create a return shipping label for the order inputted
	 *
 	 * @param object $order
	 * @return void
	 */
	function smartsend_logistics_meta_box_process_return_order( $order ) {
            
                             
  		// this is based on wp-admin/post.php
                $sendback = remove_query_arg( array('message'), wp_get_referer() );
                if ( ! $sendback )
                $sendback = admin_url( "post.php?post=".$_POST['post_ID']."&action=edit" );
                                
                $sendback = add_query_arg( array('smartsend_type' => 'create_label_return'), $sendback );
                //$sendback = remove_query_arg( array('export', 'message', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',   'bulk_edit', 'post_view'), $sendback );
                //wc_add_notice('test','error');
                wp_redirect($sendback);
                exit();
	}
	
	/*
	 * Create a normal shipping label and a then a return label for the same order
	 *
 	 * @param object $order
	 * @return void
	 */
	function smartsend_logistics_meta_box_process_normal_return_order( $order ) {
            
                // this is based on wp-admin/post.php
                $sendback = remove_query_arg( array('message'), wp_get_referer() );
                if ( ! $sendback )
                $sendback = admin_url( "post.php?post=".$_POST['post_ID']."&action=edit" );
                 
                $sendback = add_query_arg( array('smartsend_type' => 'create_label_normal_return'), $sendback );
                //$sendback = remove_query_arg( array('export', 'message', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',   'bulk_edit', 'post_view'), $sendback );
                //wc_add_notice('test','error');
                wp_redirect($sendback);
                exit();
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
			__( 'Smart Send Logistics','smart-send-logistics' ),
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
		if(!empty($line_items_shipping)){
			foreach ( $line_items_shipping as $item_id => $item ) {
				$shipMethod_id = ! empty( $item['method_id'] ) ? esc_html( $item['method_id'] ) : null;;
				$shipMethod =  ! empty( $item['name'] ) ? esc_html( $item['name'] ) : null;;
			}
		}
	
		$store_pickup = get_post_custom($order->get_id());
		if(isset($shipMethod) && $shipMethod != '') {
			echo '<p><h3>'.__('Shipping method','smart-send-logistics').'</h3>'.$shipMethod;
			//echo ' ('.$shipMethod_id.')';
			echo '</p>';
		}
				   
		Smartsend_Logistics_display_order_pickuppoint_details($order,'h3',true,false);
		Smartsend_Logistics_display_order_flexdelivery_details($order,'h3',false);
		
		echo '<br/><hr>';
		echo '<a href="post.php?post='.$post->ID.'&action=edit&smartsend_type=create_label" class="button button-primary">'.__( 'Generate label','smart-send-logistics').'</a><br/><br/>';
		echo '<a href="post.php?post='.$post->ID.'&action=edit&smartsend_type=create_label_return" class="button">'.__( 'Generate return label','smart-send-logistics').'</a><br/><br/>';
		echo '<a href="post.php?post='.$post->ID.'&action=edit&smartsend_type=create_label_normal_return" class="button">'.__( 'Generate normal and return label','smart-send-logistics').'</a>'; 
    }
	
/*****************************************************
 * Step 3: Process the order if actions match the ones from the meta box buttons
 */
	add_action( 'admin_notices', 'smartsend_logistics_admin_notice_process' );
	function smartsend_logistics_admin_notice_process() {
		if(isset($_GET['smartsend_type']) && ($_GET['smartsend_type'] == 'create_label' || $_GET['smartsend_type'] == 'create_label_return' || $_GET['smartsend_type'] == 'create_label_normal_return')){
                        $order='';
                        if(isset($_GET['post']) && $_GET['post']!='' && isset($_GET['action']) && $_GET['action']=='edit'){
                            $order = new WC_Order( $_GET['post'] );
                        }else if(isset($_GET['ids']) && $_GET['ids']!='' && isset($_GET['post_type']) && $_GET['post_type']=='shop_order'){
                            $order=  explode(',', $_GET['ids']);
                        }
                       
			if($_GET['smartsend_type']=='create_label') {
				smartsend_logistics_create_label_action($order,$return=false);
			}
			if($_GET['smartsend_type']=='create_label_return') {
				smartsend_logistics_create_label_action($order,$return=true);
			}
			if($_GET['smartsend_type']=='create_label_normal_return') {
				smartsend_logistics_create_label_action($order,$return='both');
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
						jQuery('<option>').val('create_label').text('<?php _e('Generate label','smart-send-logistics')?>').appendTo("select[name='action']");
						jQuery('<option>').val('create_label').text('<?php _e('Generate label','smart-send-logistics')?>').appendTo("select[name='action2']");
						jQuery('<option>').val('create_label_return').text('<?php _e('Generate return label','smart-send-logistics')?>').appendTo("select[name='action']");
						jQuery('<option>').val('create_label_return').text('<?php _e('Generate return label','smart-send-logistics')?>').appendTo("select[name='action2']");
						jQuery('<option>').val('create_label_normal_return').text('<?php _e('Generate normal and return label','smart-send-logistics')?>').appendTo("select[name='action']");
						jQuery('<option>').val('create_label_normal_return').text('<?php _e('Generate normal and return label','smart-send-logistics')?>').appendTo("select[name='action2']");
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
                       
			$allowed_actions = array("create_label","create_label_return", "create_label_normal_return");
			if(!in_array($action, $allowed_actions)) return;

			// security check
			check_admin_referer('bulk-posts');

			// make sure ids are submitted.  depending on the resource type, this may be 'media' or 'ids'
			if(isset($_REQUEST['post'])) {
				$post_ids = array_map('intval', $_REQUEST['post']);
			}
		
			if(empty($post_ids)) return;
		
			// this is based on wp-admin/edit.php
			//$sendback = remove_query_arg( array('exported', 'untrashed', 'deleted', 'ids'), wp_get_referer() );
			//if ( ! $sendback )
				$sendback = admin_url( "edit.php?post_type=$post_type" );
		
			$pagenum = $wp_list_table->get_pagenum();
			$sendback = add_query_arg( 'paged', $pagenum, $sendback );
		
			switch($action) {
				case 'create_label':
				
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
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
				
				case 'create_label_return':
				
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
					
				case 'create_label_normal_return':
				
					
					$sendback = add_query_arg( array('ids' => join(',', $post_ids)), $sendback );
				
					break;
			
				default: return;
			}
			$sendback = add_query_arg( array('smartsend_type' => $action), $sendback );

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
 		// Enbale the carriers
 		
			// Post Danmark
			$methods[] = 'Smartsend_Logistics_Postdanmark';
			// Posten
			$methods[] = 'Smartsend_Logistics_Posten';
			// GLS
			$methods[] = 'Smartsend_Logistics_Gls';
			// Bring
			$methods[] = 'Smartsend_Logistics_Bring';
   		
   		
   		//Enable the shippign methods for each carrier
   		if(get_option( 'smartsend_logistics_add_all_shipping_methods', '' ) == 'yes') {
   			smartsend_logistics_shipping_method_init();
   			
   			//Post Danmark
   			$Postdanmark = new Smartsend_Logistics_Postdanmark();
   			foreach($Postdanmark->get_methods() as $method_code => $method_name) {
   				require_once 'carriers/class.smartsend.postdanmark.'.$method_code.'.php';
   				$methods[] = 'Smartsend_Logistics_Postdanmark_'.ucfirst($method_code);
   			}
   			
   			//Posten
   			$Posten = new Smartsend_Logistics_Posten();
   			foreach($Posten->get_methods() as $method_code => $method_name) {
   				require_once 'carriers/class.smartsend.posten.'.$method_code.'.php';
   				$methods[] = 'Smartsend_Logistics_Posten_'.ucfirst($method_code);
   			}
   			
   			//GLS
   			$Gls = new Smartsend_Logistics_Gls();
   			foreach($Gls->get_methods() as $method_code => $method_name) {
   				require_once 'carriers/class.smartsend.gls.'.$method_code.'.php';
   				$methods[] = 'Smartsend_Logistics_Gls_'.ucfirst($method_code);
   			}
   			
   			//Bring
   			$Gls = new Smartsend_Logistics_Bring();
   			foreach($Gls->get_methods() as $method_code => $method_name) {
   				require_once 'carriers/class.smartsend.bring.'.$method_code.'.php';
   				$methods[] = 'Smartsend_Logistics_Bring_'.ucfirst($method_code);
   			}
   		}
   		
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
 	}
        
	add_filter( 'removable_query_args', 'smartsend_logistics_removable_query_args' );
	function smartsend_logistics_removable_query_args($removable_query_args){
                return array_merge($removable_query_args,array('smartsend_type'));    
 	}	
}

}
	 
?>
