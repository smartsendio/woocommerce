<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if ( ! class_exists( 'Smartsend_Logistics_Postdanmark' ) ) {
	class Smartsend_Logistics_Postdanmark extends WC_Shipping_Method {
	
		public $PrimaryClass ;
		
		/**
	 	* Constructor.
	 	*/
		public function __construct() {
			$this->id                 	= 'smartsend_postdanmark'; 
			$this->method_title       	= __( 'Post Danmark','smart-send-logistics');  
			
			$this->method_description 	= __( 'This shipping method may only be used if a valid Smart Send license is purchased. Please see <a href="http://www.smartsend.dk" target="_blank">Smart Send</a> for further information.','smart-send-logistics');
			$this->table_rate_option    = 'PostDanmark_table_rate';
			$this->PrimaryClass 		= new Smartsend_Logistics_PrimaryClass();
			
			$this->init();
		}

		/**
		 * init function.
		 */
		public function init() {
		
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->enabled					= $this->get_option( 'enabled' );
	  		$this->title 					= $this->get_option( 'title' );
			$this->cheap_expensive 			= $this->get_option( 'cheap_expensive' );
			$this->tax_status   			= 'taxable';
			$this->format   				= $this->get_option( 'format' );
			$this->quickid   				= $this->get_option( 'quickid' );
			$this->waybillid   				= $this->get_option( 'waybillid' );
			$this->notemail    				= $this->get_option( 'notemail' );
			$this->notesms    				= $this->get_option( 'notesms' );
			$this->prenote    				= $this->get_option( 'prenote' );
			$this->prenote_receiver    		= $this->get_option( 'prenote_receiver' );
			$this->prenote_sender    		= $this->get_option( 'prenote_sender' );
			$this->prenote_message    		= $this->get_option( 'prenote_message' );
			$this->return  					= $this->get_option( 'return' );
		
			// Actions
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_table_rates' ) );
	
			// Load Table rates
			$this->load_table_rates();
		}
		
		/**
		* is_available function.
	 	* @param array $package
	 	* @return bool
	 	*/
		public function is_available( $package ){
			$option = $this->enabled;
			if($option == "yes") {
				$is_available = TRUE;
			} else {
				$is_available = FALSE;
			}
			return apply_filters( 'smartsend_logistics_' . $this->id . '_is_available', $is_available, $package );
		}
	
		/**
	 	* Initialise Gateway Settings Form Fields.
	 	*/
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' 			=> array(
					'title' 			=> __( 'Enabled','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable the shipping methods from the table','smart-send-logistics'),
					'default' 			=> 'no'
				),
				'title' 			=> array(
					'title' 			=> __( 'Carrier title','smart-send-logistics'),
					'type' 				=> 'text',
					'description' 		=> __( 'Carrier title shown in frontend at customer checkout','smart-send-logistics'),
					'default'			=> __( 'Post Danmark','smart-send-logistics'),
					'desc_tip'			=> true,
				),
				'domestic_shipping_table' => array(
					'type'      	=> 'shipping_table'
				),
				'cheap_expensive' 	=> array(
					'title'    			=> __( 'Handle multiple rates for same shipping method','smart-send-logistics'),
					'description'     	=> __( 'If multiple rates are valid for the same method, use either the cheapest or the most expensive rate','smart-send-logistics'),
					'default'  			=> 'cheapest',
					'type'     			=> 'select',
					'class'         	=> 'wc-enhanced-select',
					'options'  			=> array(
						'cheapest'      	=> __( 'Cheapest','smart-send-logistics'),
						'expensive' 		=> __( 'Most expensive','smart-send-logistics'),
					)
				),
				'exclude_tax' 		=> array(
					'title' 			=> __( 'Exclude from TAX', 'smart-send-logistics' ),
					'description'		=> __('Excluded private shipping methods to Denmark from TAX','smart-send-logistics'),
					'type'      		=> 'checkbox',
					'default'   		=> 'yes',
					'desc_tip'			=> true,
				),
				'format' 	=> array(
					'title'    			=> __( 'Label format','smart-send-logistics'),
					'default'  			=> 'pdf',
					'type'     			=> 'select',
					'class'         	=> 'wc-enhanced-select',
					'options'  			=> array(
						'pdf'      			=> __( 'PDF file','smart-send-logistics'),
						'link'      		=> __( 'Pacsoft link','smart-send-logistics'),
					)
				),
				'quickid' 			=> array(
					'title' 			=> __( 'Pacsoft QuickID','smart-send-logistics'),
					'description'		=> __('ID of the Pacsoft sender appearing on the label','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '1',
					'desc_tip'			=> true,
				),
				'waybillid' 			=> array(
					'title' 			=> __( 'Waybill ID','smart-send-logistics'),
					'description'     	=> __( "Either just an id or a semicolon separated list of 'country,id' (* is all countries). Eg: SE,123;NO,321;*,44",'smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'notemail' 	=> array(
					'title'    			=> __( 'Email notification','smart-send-logistics'),
					'description'     	=> __( 'Send an email to the customer with info about delivery','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable','smart-send-logistics'),
					'default' 			=> 'yes'
				),
				'notesms' 	=> array(
					'title'    			=> __( 'SMS notification','smart-send-logistics'),
					'description'     	=> __( 'Send a SMS to the customer with info about delivery','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable','smart-send-logistics'),
					'default' 			=> 'yes'
				),
				'prenote' 	=> array(
					'title'    			=> __( 'Pre-notification','smart-send-logistics'),
					'description'     	=> __( 'Send and email with info about delivery at soon as a label is created','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable','smart-send-logistics'),
					'default' 			=> 'no'
				),
				'prenote_receiver' 	=> array(
					'title'    			=> __( 'Pre-notification receiver','smart-send-logistics'),
					'description'     	=> __( 'Leave blank if receiver should be the user','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'prenote_sender' 	=> array(
					'title'    			=> __( 'Pre notification sender','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'prenote_message' 	=> array(
					'title'    			=> __( 'Pre-notification message','smart-send-logistics'),
					'type' 				=> 'textarea',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'flexdelivery' 	=> array(
					'title'    			=> __( 'Flex delivery','smart-send-logistics'),
					'description'     	=> __( 'Enable flex delivery for the selected shipping methods','smart-send-logistics'),
					'default'  			=> $this->get_default_shipping_methods_flexdelivery(),
					'type'     			=> 'multiselect',
					'class'         	=> 'wc-enhanced-select',
					'options'  			=> $this->get_methods(),
				),
				'return' 	=> array(
					'title'    			=> __( 'Return method','smart-send-logistics'),
					'description'     	=> __( 'Determines what carrier handles return packages','smart-send-logistics'),
					'default'  			=> 'postdanmark',
					'type'     			=> 'select',
					'class'         	=> 'wc-enhanced-select',
					'options'  			=> array(
						'smartsendpostdanmark_private'	=> __( 'Post Danmark','smart-send-logistics'),
						'smartsendposten_private'      	=> __( 'Posten','smart-send-logistics'),
						'smartsendgls_private'      	=> __( 'GLS','smart-send-logistics'),
						'smartsendbring_private'      	=> __( 'Bring','smart-send-logistics'),
					)
				)
			);
			
		} // End init_form_fields()

		/**
		 * calculate_shipping function.
		 *
		 * @access public
		 * @param mixed $package
		 * @return void
		 */
		function calculate_shipping( $package = array() ) {
			$this->PrimaryClass->calculate_shipping($package = array(),$this);
		}

		/**
		 * validate_additional_costs_field function.
		 *
		 * @access public
		 * @param mixed   $key
		 * @return void
		 */
		function validate_shipping_table_field( $key ) {
			return false;
		}			
		
		function generate_shipping_table_html() {
			return $this->PrimaryClass->generate_shipping_table_html($this);
		}

		/**
		 * process_table_rates function.
		 *
		 * @access public
		 * @return void
		 */
		function process_table_rates() {
			$this->PrimaryClass->process_table_rates($this);
		}

		/**
		 * save_default_costs function.
		 *
		 * @access public
		 * @param mixed   $values
		 * @return void
		 */
		function save_default_costs( $fields ) {
			return $this->PrimaryClass->save_default_costs($fields);
		}

		/**
		 * load_table_rates function.
		 *
		 * @access public
		 * @return void
		 */
		function load_table_rates() {
			$this->table_rates = $this->get_table_rates();
		}
		
		/**
		 * get_table_rates function.
		 *
		 * @access public
		 * @return void
		 */
		function get_table_rates() {
			return array_filter( (array) get_option( $this->table_rate_option ) );
		}
		
		/**
		 * get_default_table_rates function.
		 *
		 * @access public
		 * @return void
		 */
		function get_default_table_rates() {
		
			return array(
				array(
					'class'			=> 'all',
					'methods'		=> 'Pickup',
					'minO' 			=> '0',
					'maxO' 			=> '500',
					'minwO' 		=> '0',
					'maxwO' 		=> '100000',
					'shippingO' 	=> 40.00,
					'country' 		=> 'DK',
					'method_name' 	=> __('Pickuppoint','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'Pickup',
					'minO' 			=> '500',
					'maxO' 			=> '100000',
					'minwO' 		=> '0',
					'maxwO' 		=> '100000',
					'shippingO' 	=> 0,
					'country' 		=> 'DK',
					'method_name' 	=> __('Pickuppoint','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'privatehome',
					'minO' 			=> '0',
					'maxO' 			=> '500',
					'minwO' 		=> '0',
					'maxwO' 		=> '100000',
					'shippingO' 	=> 50.00,
					'country' 		=> 'DK',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'privatehome',
					'minO' 			=> '500',
					'maxO' 			=> '100000',
					'minwO' 		=> '0',
					'maxwO' 		=> '100000',
					'shippingO' 	=> 10.00,
					'country' 		=> 'DK',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'private',
					'minO' 			=> '0',
					'maxO' 			=> '500',
					'minwO' 		=> '0',
					'maxwO' 		=> '100000',
					'shippingO' 	=> 90.00,
					'country' 		=> 'SE,NO,FI',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'private',
					'minO' 			=> '500',
					'maxO' 			=> '100000',
					'minwO' 		=> '0',
					'maxwO' 		=> '100000',
					'shippingO' 	=> 50.00,
					'country' 		=> 'SE,NO,FI',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'private',
					'minO' 			=> '0',
					'maxO' 			=> '100000',
					'minwO' 		=> '0',
					'maxwO' 		=> '3',
					'shippingO' 	=> 300.00,
					'country' 		=> 'FO,GL',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'private',
					'minO' 			=> '0',
					'maxO' 			=> '100000',
					'minwO' 		=> '3',
					'maxwO' 		=> '10',
					'shippingO' 	=> 400.00,
					'country' 		=> 'FO,GL',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					),
				array(
					'class'			=> 'all',
					'methods'		=> 'private',
					'minO' 			=> '0',
					'maxO' 			=> '100000',
					'minwO' 		=> '10',
					'maxwO' 		=> '20',
					'shippingO' 	=> 500.00,
					'country' 		=> 'FO,GL',
					'method_name' 	=> __('Private to home','smart-send-logistics'),
					)
				);	
		}
		
		/**
		 * save_default_table_rates function.
		 *
		 * @access public
		 * @return void
		 */
		function save_default_table_rates() {
			$table_rates = $this->get_default_table_rates();
			update_option( $this->table_rate_option, $table_rates );
		}				
							
		/**
		 * get_methods function.
		 *
		 * @access public
		 * @return array
		 */
		function get_methods(){
			$shipping_methods = array(
				'pickup'				=> __( 'Pickuppoint', 'smart-send-logistics'),
				'private'				=> __( 'Private', 'smart-send-logistics'),
				'privatehome'			=> __( 'Private to home', 'smart-send-logistics'),
				'commercial'			=> __( 'Commercial', 'smart-send-logistics'),
				'dpdclassic'			=> __( 'DPD Classic', 'smart-send-logistics'),
                'dpdguarantee'			=> __( 'DPD Guarantee', 'smart-send-logistics'),
				'valuemail'				=> __( 'Value mail', 'smart-send-logistics'),
				'privatesamsending' 	=> __( 'Private collective', 'smart-send-logistics'),
            	'privatepriority'		=> __( 'Private priority', 'smart-send-logistics'),
            	'privateeconomy'		=> __( 'Private economy', 'smart-send-logistics'),
            	'lastmile'				=> __( 'Service Logistics', 'smart-send-logistics'),
            	'businesspriority'		=> __( 'Commercial priority', 'smart-send-logistics'),
				);
			if(function_exists('is_plugin_active') && is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
				unset($shipping_methods['pickup']);
			}
			
			return $shipping_methods;
		}
		
		/**
		 * get_default_shipping_methods_flexdelivery function.
		 *
		 * @access public
		 * @return array
		 */
		function get_default_shipping_methods_flexdelivery(){
			$shipping_methods = array('lastmile');
			
			return $shipping_methods;
		}
		
		/**
		 * get_shipping_methods_excluded_from_tax function.
		 *
		 * @access public
		 * @return array
		 */
		function get_shipping_methods_excluded_from_tax() {
			return array(
				'pickup',
				'private',
				'privatehome',
				'privatepriority',
				'valuemail');
		}

	}
}