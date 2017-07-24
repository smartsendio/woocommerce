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
    
    /**
	 * Check user credentials if:
	 * Username is udpated
	 * Licensekey is updated
	 * It is more than a week since the credentials was verified last
	*/
		#The username is updated
        add_filter( 'pre_update_option_smartsend_logistics_username', 'Smartsend_Logistics_update_setting_username', 10, 2 );
    	function Smartsend_Logistics_update_setting_username( $new_username, $old_username ) {
    	
    		$validation_date 	= (int) get_option('smartsend_logistics_validation_date');
    		$licencekey			= get_option('smartsend_logistics_licencekey');
    	
			if( $new_username != $old_username && $validation_date + 5 < time() ) {
        			
				if( Smartsend_User_Validation($new_username,$licencekey) ) {
					$validation_date = time();
				} else {
					$validation_date = 0;
				}
				update_option( 'smartsend_logistics_validation_date', $validation_date );
				
			} elseif( $validation_date + 60*60*24*7 < time() ) {
			
				if( Smartsend_User_Validation($new_username,$licencekey) ) {
					$validation_date = time();
				} else {
					$validation_date = 0;
				}
				update_option( 'smartsend_logistics_validation_date', $validation_date );
				
			}
			
			return $new_username;
		}
		
		#The licensekey is updated
        add_filter( 'pre_update_option_smartsend_logistics_licencekey', 'Smartsend_Logistics_update_setting_licencekey', 10, 2 );
    	function Smartsend_Logistics_update_setting_licencekey( $new_licencekey, $old_licencekey ) {
    	
    		$validation_date 	= (int) get_option('smartsend_logistics_validation_date');
    		$username			= get_option('smartsend_logistics_username');
    	
			if( $new_licencekey != $old_licencekey && $validation_date + 5 < time() ) {
        			
				if( Smartsend_User_Validation($username,$new_licencekey) ) {
					$validation_date = time();
				} else {
					$validation_date = 0;
				}
				update_option( 'smartsend_logistics_validation_date', $validation_date );
				
			} elseif( $validation_date + 60*60*24*7 < time() ) {
			
				if( Smartsend_User_Validation($username,$new_licencekey) ) {
					$validation_date = time();
				} else {
					$validation_date = 0;
				}
				update_option( 'smartsend_logistics_validation_date', $validation_date );
				
			}
			
			return $new_licencekey;
		}
		
		#Return a string whether or not the credentials have been verified
		add_filter( 'pre_option_smartsend_logistics_validation', 'Smartsend_Logistics_setting_validation');
		function Smartsend_Logistics_setting_validation( $validation ) {
			$validation_date = (int) get_option( 'smartsend_logistics_validation_date');
			
			if ($validation_date + 60*60*24*7 > time() ) {
				return __('Valid user information','smart-send-logistics');
			} elseif( $validation_date == 0 ){
				return __('Please enter valid user information','smart-send-logistics');
			} else {
				return __('Save settings to validate','smart-send-logistics');
			}
		}
        
	/**
	 * wc_shipment_tracking_add_custom_provider
	 *
	 * Adds custom provider to shipment tracking
	 * Change the country name, the provider name, and the URL (it must include the %1$s)
	 * Add one provider per line
	*/
	add_action( 'wc_shipment_tracking_get_providers' , 'smartsend_logistics_wc_shipment_tracking_add_custom_provider' );
	function smartsend_logistics_wc_shipment_tracking_add_custom_provider( $providers ) {

		//Denmark
		$providers['Denmark']['PostDanmark'] 	= 'https://pakkeboksen.dk/track-trace.html?searchId=%1$s';
		$providers['Denmark']['GLS'] 			= 'http://www.gls-group.eu/276-I-PORTAL-WEB/content/GLS/DK01/DA/5004.htm?txtAction=71000&txtRefNo=%1$s';
		$providers['Denmark']['Bring'] 			= 'http://sporing.bring.no/sporing.html?q=%1$s';
		
		//Sweden
		$providers['Sweden']['Posten'] 			= 'http://www.postnord.se/en/tools/track/track-and-trace#dynamicloading=true&shipmentid=%1$s';
		
		//Norway
		$providers['Norway']['Bring'] 			= 'http://sporing.bring.no/sporing.html?q=%1$s';
		
		//Sort by keys
		ksort($providers);
		
		return $providers;
	}
	
	#Add a New WooCommerce Settings Tab
	add_filter( 'woocommerce_settings_tabs_array', 'Smartsend_Logistics_add_settings_tab', 50 );
	function Smartsend_Logistics_add_settings_tab( $settings_tabs ) {
        $settings_tabs['settings_tab_smartsend_logistics'] = __( 'Smart Send', 'smart-send-logistics' );
        return $settings_tabs;
    }
    
    #Add Settings to the custom WooCommerce settings tab
    add_action( 'woocommerce_settings_tabs_settings_tab_smartsend_logistics', 'Smartsend_Logistics_add_settings_for_settings_tab' );
	function Smartsend_Logistics_add_settings_for_settings_tab() {
    	woocommerce_admin_fields( Smartsend_Logistics_get_setting() );
	}
	
	#Return the Smart Send Logistics Settings
	function Smartsend_Logistics_get_setting() {
		
		$WC_Shipping_Free_Shipping = new WC_Shipping_Free_Shipping();
		
		switch (get_option('smartsend_logistics_validation')) {
			case __('Valid user information','smart-send-logistics'):
				$validate_color = 'green';
				break;
			case __('Save settings to validate','smart-send-logistics'):
				$validate_color = 'orange';
				break;
			default:
				$validate_color = 'red';
		}
		
		$settings = array(
			'title'	=> array(
				'name' 		=> __( 'Smart Send Logistics settings', 'smart-send-logistics' ),
				'type' 		=> 'title',
				'desc' 		=> __("If you don't have a Smart Send subscription please create one at our website", 'smart-send-logistics').': <a href="http://www.smartsend.dk/signup" target="_blank">Smart Send</a>',
				'id' 		=> 'smartsend_logistics_settings'
			),
			'username' => array(
				'title'   	=> __( 'Username','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_username',
				'default' 	=> '', //Choose Store Location
				'type'    	=> 'text',
				'desc_tip'        =>  false,
			),
			'licensekey' => array(
				'title'   	=> __( 'License key','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_licencekey',
				'default' 	=> '',
				'type'    	=> 'text',
				'desc_tip'        =>  false,
			),
            'validation' => array(
				'title'		=> __( 'Validation','smart-send-logistics'),
				'id'		=> 'smartsend_logistics_validation',
				'default'	=> '0',
				'type'		=> 'text',
				'desc_tip'	=>  false,
                'css'		=> 'box-shadow:none;width:255px; color: '.$validate_color.'; background: none repeat scroll 0 0 rgba(0, 0, 0, 0) !important; border: none;'
			),
			'combine_pdf_files' => array(
				'title'    	=> __( 'Merge labels from multiple orders','smart-send-logistics'),
				'desc'     	=> __( 'Generate PDF file containing all labels or create a single PDF file for each order','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_combinepdf',
				'default' 	=> 'yes',
				'type'    	=> 'radio',
				'options' 	=> array(
					'yes'     	=> __( 'Merged PDF file','smart-send-logistics'),
					'no'      	=> __( 'Separate PDF files','smart-send-logistics'),
				),
				'autoload'        => false,
				'desc_tip'        =>  true,
			),
			'dropdown_display_mode' => array(
				'title'   	=> __( 'Pickup dropdown display place','smart-send-logistics'),
				'desc'    	=> __( 'This controls the display postion of pick-up point dropdown on checkout page.','smart-send-logistics'),
				'id'      	=> 'woocommerce_pickup_display_mode',
				'default' 	=> '2',
				'type'    	=> 'radio',
				'options' 	=> array(
					'0'     	=> __( 'Above the "Your Order" section on Checkout page','smart-send-logistics'),
					'1'      	=> __( "Add to specific location on Checkout page by using custom hook in your theme: do_action('smartsend_logistics_dropdown_hook')",'smart-send-logistics'),
					'2'      	=> __( 'Below the shipping method','smart-send-logistics'),
				),
				'autoload'        => false,
				'desc_tip'        =>  true,
			),
			'dropdown_display_format' => array(
				'title'    	=> __( 'Dropdown format','smart-send-logistics'),
				'desc'		=> __('How the pickup points are listed during checkout','smart-send-logistics'),
				'id'       	=> 'woocommerce_pickup_display_format',
				'default'  	=> '4',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'desc_tip' 	=> true,
				'options'   => array(
					'1' 		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics'),
					'2'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics').', #'.__('Zipcode','smart-send-logistics'),
					'3'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics').', #'.__('City','smart-send-logistics'),
					'4'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics').', #'.__('Zipcode','smart-send-logistics').' #'.__('City','smart-send-logistics'),
				)
			),
			'order_status' => array(
				'title'    	=> __( 'Set order status after label print','smart-send-logistics'),
				'id'       	=> 'smartsend_logistics_order_status',
				'default'  	=> '0',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'options'   => array_merge(array('0'=>__("Don't change order status",'smart-send-logistics')),wc_get_order_statuses())
			),
			'shipping_method_display_format' => array(
				'title'    	=> __( 'Shipping method display format','smart-send-logistics'),
				'desc'		=> __('How the shipping methods are shown during checkout','smart-send-logistics'),
				'id'       	=> 'woocommerce_carrier_display_format',
				'default'  	=> '0',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'desc_tip' 	=> true,
				'options'  	=> array(
					'0'    		=> '#'.__( 'Carrier','smart-send-logistics' ) . ' - #' . __( 'Method','smart-send-logistics' ),
					'1'      	=> '#'.__( 'Carrier','smart-send-logistics' ) . ' (#' . __( 'Method','smart-send-logistics' ) . ')',
					'2' 		=> '#'.__( 'Carrier','smart-send-logistics' ) . ' - (#' . __( 'Method','smart-send-logistics' ) . ')',
					'3'      	=> '#'.__( 'Carrier','smart-send-logistics' ) . ' #' . __( 'Method','smart-send-logistics' ),
					'4' 		=> '#'.__( 'Carrier','smart-send-logistics' ) . '-(#' . __( 'Method','smart-send-logistics' ) . ')',
					'5' 		=> '#'.__( 'Carrier','smart-send-logistics' ),
					'6' 		=> '#'.__( 'Method','smart-send-logistics' ),
					)
			),
			'shipping_method_for_free_shipping' => array(
				'title'    	=> __( 'Shipping method used for WooCommerce method Free Shipping','smart-send-logistics'),
				'id'       	=> 'smartsend_logistics_wc_shipping_free_shipping',
				'default'  	=> '0',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'desc_tip' 	=> false,
				'options'  	=> Smartsend_Logistics_get_all_shipping_methods()
			),
			'include_order_comment' => array(
				'title'    	=> __( 'Include order comment on label','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_includeordercomment',
				'default' 	=> 'yes',
				'type'    	=> 'checkbox',
				'autoload'        => false,
				'desc_tip'        =>  false,
			),
			'add_all_shipping_methods' => array(
				'title'    	=> __( 'Enable function to change shipping method in admin ','smart-send-logistics'),
				'desc'		=> __('This will include a long list of shipping methods in the shipping table','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_add_all_shipping_methods',
				'default' 	=> 'no',
				'type'    	=> 'checkbox',
				'autoload'        => false,
				'desc_tip'        =>  false,
			),
			'section_end' => array(
			'type' => 'sectionend',
			'id' => 'Smartsend_Logistics_section_end'
			)
		);
		
		$woocommerce_version = smartsend_logistics_get_woocommerce_version();
		if( version_compare($woocommerce_version, '2.5.0', '<') ) {
			$settings['dropdown_display_mode']['default'] = '0';
			unset($settings['dropdown_display_mode']['options'][2]);
		}
		
		return apply_filters( 'wc_settings_tab_smartsend_logistics_settings', $settings );
	}
	
	# Save settings
	add_action( 'woocommerce_update_options_settings_tab_smartsend_logistics', 'Smartsend_Logistics_update_settings' );
	function Smartsend_Logistics_update_settings() {
		woocommerce_update_options( Smartsend_Logistics_get_setting() );
	}
	
	function Smartsend_Logistics_get_all_shipping_methods() {
	
		//Load carrier classes
		smartsend_logistics_shipping_method_init();
	
		//Array that will contain all the shipping methods
		$shipping_methods = array();
	
		//Load the Post Danmark class
		$carrier_controller = new Smartsend_Logistics_Postdanmark();
		foreach($carrier_controller->get_methods() as $name => $description) {
			$shipping_methods['smartsend_postdanmark_'.$name] = 'Post Danmark '.$description; 
		}

		//Load the Posten class
		$carrier_controller = new Smartsend_Logistics_Posten();
		foreach($carrier_controller->get_methods() as $name => $description) {
			$shipping_methods['smartsend_posten_'.$name] = 'Posten '.$description; 
		}

		//Load the GLS class
		$carrier_controller = new Smartsend_Logistics_Gls();
		foreach($carrier_controller->get_methods() as $name => $description) {
			$shipping_methods['smartsend_gls_'.$name] = 'GLS '.$description; 
		}

		//Load the Bring class
		$carrier_controller = new Smartsend_Logistics_Bring();
		foreach($carrier_controller->get_methods() as $name => $description) {
			$shipping_methods['smartsend_bring_'.$name] = 'Bring '.$description; 
		}
	
		return $shipping_methods;
	}
            
}
}