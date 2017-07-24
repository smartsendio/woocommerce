<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if ( ! class_exists( 'Smartsend_Logistics_PostDanmark' ) ) {
	class Smartsend_Logistics_PostDanmark extends WC_Shipping_Method {
	
		public $PrimaryClass ;
		
		public function __construct() {
			$this->id                 	= 'smartsend_postdanmark'; 
			$this->method_title       	= __( 'PostDanmark','smart-send-logistics');  
			$this->method_description 	= __( 'PostDanmark','smart-send-logistics'); 				
			$this->table_rate_option    = 'PostDanmark_table_rate';
			$this->PrimaryClass 		= new Smartsend_Logistics_PrimaryClass();
			$this->init();
		}

		
		function init() {
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
		
			$this->shipping_description		= $this->get_option( 'shipping_description' );
			$this->enabled					= $this->get_option( 'enabled' );
			$this->title 					= $this->get_option( 'title' );
			$this->availability 			= 'specific';
			$this->countries 				= $this->getCountries();
			$this->requires					= $this->get_option( 'requires' );
			$this->apply_when 				= $this->get_option( 'apply_when' );
			$this->greatMax 				= $this->get_option( 'greatMax' );
			$this->type       				= $this->get_option( 'type' );
			$this->tax_status   			= $this->get_option( 'tax_status' );
			$this->min_order    			= $this->get_option( 'min_order' );
			$this->max_order    			= $this->get_option( 'max_order' );
			$this->shipping_rate  			= $this->get_option( 'shipping_rate' );
		
			// Actions
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_table_rates' ) );
	
			// Load Table rates
			$this->load_table_rates();
		}
	
		function init_form_fields() {
			$this->form_fields = array(
				'enabled' 			=> array(
					'title' 			=> __( 'Enable/Disable','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable this shipping method','smart-send-logistics'),
					'default' 			=> 'no'
				),
				'title' 			=> array(
					'title' 			=> __( 'Carrier title','smart-send-logistics'),
					'type' 				=> 'text',
					'description' 		=> __( 'This controls the title which the user sees during checkout','smart-send-logistics'),
					'default'			=> __( 'PostDanmark','smart-send-logistics'),
					'desc_tip'			=> true,
				),
				'domestic_shipping_table' => array(
					'type'      	=> 'shipping_table'
				),
				'cheap_expensive' 	=> array(
					'title'    			=> __( 'Cheapest or most expensive','smart-send-logistics'),
					'description'     	=> __( 'This controls cheapest or most expensive on the frontend','smart-send-logistics'),
					'default'  			=> 'cheapest',
					'type'     			=> 'select',
					'options'  			=> array(
						'cheapest'      	=> __( 'Cheapest','smart-send-logistics'),
						'expensive' 		=> __( 'Most expensive','smart-send-logistics'),
					)
				),
				'tax_status' 		=> array(
					'title'     		=> __( 'Tax status','smart-send-logistics'),
					'type'      		=> 'select',
					'default'   		=> 'taxable',
					'options'   		=> array(
						'taxable' 			=> __( 'Taxable','smart-send-logistics'),
						'none'    			=> __( 'None','smart-send-logistics'),
					),
				),
				'format' 	=> array(
					'title'    			=> __( 'Format','smart-send-logistics'),
					'description'     	=> __( 'Create a Pacsoft link or a pdf file','smart-send-logistics'),
					'default'  			=> 'pdf',
					'type'     			=> 'select',
					'options'  			=> array(
						'pdf'      			=> __( 'PDF file','smart-send-logistics'),
						'link'      		=> __( 'Pacosft Online link','smart-send-logistics'),
					)
				),
				'quickid' 			=> array(
					'title' 			=> __( 'Pacsoft QuickID','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '1',
					'desc_tip'			=> true,
				),
				'waybillid' 			=> array(
					'title' 			=> __( 'Waybill ID','smart-send-logistics'),
					'description'     	=> __( 'Either just an id or a semicolon separated list of "country,id" (* is all countries). Eg: SE,123;NO,321;*,44','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'notemail' 	=> array(
					'title'    			=> __( 'Email notification','smart-send-logistics'),
					'description'     	=> __( 'Send an email with info about delivery','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable','smart-send-logistics'),
					'default' 			=> 'yes'
				),
				'notesms' 	=> array(
					'title'    			=> __( 'SMS notification','smart-send-logistics'),
					'description'     	=> __( 'Send an SMS with info about delivery','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable','smart-send-logistics'),
					'default' 			=> 'yes'
				),
				'prenote' 	=> array(
					'title'    			=> __( 'Pre notification','smart-send-logistics'),
					'description'     	=> __( 'Send an email with info about delivery as soon as the label is created','smart-send-logistics'),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable','smart-send-logistics'),
					'default' 			=> 'no'
				),
				'prenote_receiver' 	=> array(
					'title'    			=> __( 'Pre notification receiver','smart-send-logistics'),
					'description'     	=> __( 'Receivers email address. Leave blank if receiver should be the user.','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'prenote_sender' 	=> array(
					'title'    			=> __( 'Pre notification sender','smart-send-logistics'),
					'description'     	=> __( 'Senders email address','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'prenote_message' 	=> array(
					'title'    			=> __( 'Pre notification message','smart-send-logistics'),
					'description'     	=> __( 'Email message','smart-send-logistics'),
					'type' 				=> 'text',
					'default'			=> '',
					'desc_tip'			=> true,
				),
				'return' 	=> array(
					'title'    			=> __( 'Return shipping method','smart-send-logistics'),
					'description'     	=> __( 'Method used for return labels','smart-send-logistics'),
					'default'  			=> 'postdanmark',
					'type'     			=> 'select',
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
					'method_name' 	=> __('Delivered to door','smart-send-logistics'),
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
		 * @return void
		 */
		function get_methods(){
			$shipping_methods = array(
				'private'				=> 'Private',
				'privatehome'			=> 'Private to home',
				'commercial'			=> 'Commercial',
				'dpdclassic'			=> 'DPD classic',
                'dpdguarantee'			=> 'DPD guarantee',
				'valuemail'				=> 'Valuemail',
				'privatesamsending' 	=> 'Private samsending',
            	'privatepriority'		=> 'Private priority',
            	'privateeconomy'		=> 'Private economy',
            	'lastmile'				=> 'Last mile'
				);
			if(function_exists('is_plugin_active') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
				$shipping_methods = array_merge(array('pickup' => 'Pickup'),$shipping_methods);
			}
			
			return $shipping_methods;
		}
                
		function getCountries(){
			$datas = array_filter( (array) get_option( $this->table_rate_option ) );

			$countries = array();
			if($datas){
				foreach($datas as $data){
						$countriesArray = explode(',',$data['country']);
						if(is_array($countriesArray)){
							foreach($countriesArray as $c){
								$countries[] = trim(strtoupper($c)); 
							}
						}else{
							$countries[] =trim(strtoupper($data['country'])); 
						}
				}
			}

			return $countries;
		}

	}
}