<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if ( ! class_exists( 'Smartsend_Logistics_Bring' ) ) {
	class Smartsend_Logistics_Bring extends WC_Shipping_Method {
	
		public $PrimaryClass;
		
		public function __construct() {
			$this->id                 	= 'smartsend_bring'; 
			$this->method_title       	= __( 'Bring','smart-send-logistics');  
			$this->method_description 	= __( 'Bring','smart-send-logistics'); 				
			$this->table_rate_option  	= 'Bring_table_rate';
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
			$this->get_table_rates();
		}
	
		function init_form_fields() {
			$this->form_fields = array(
				'enabled' 		=> array(
					'title' 		=> __( 'Enable/Disable','smart-send-logistics'),
					'type' 			=> 'checkbox',
					'label' 		=> __( 'Enable this shipping method','smart-send-logistics'),
					'default' 		=> 'no'
				),
				'title' 		=> array(
					'title' 		=> __( 'Carrier title','smart-send-logistics'),
					'type' 			=> 'text',
					'description' 	=> __( 'This controls the title which the user sees during checkout','smart-send-logistics'),
					'default'		=> __( 'Bring','smart-send-logistics'),
					'desc_tip'		=> true,
				),
				'domestic_shipping_table' => array(
					'type'     		=> 'shipping_table'
				),
				'cheap_expensive' 	=> array(
					'title'    			=> __( 'Cheapest or most expensive?','smart-send-logistics'),
					'description'     	=> __( 'This controls cheapest or most expensive on the frontend','smart-send-logistics'),
					'default' 			=> 'cheapest',
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
					'options'  			=> array(
						'taxable' 			=> __( 'Taxable','smart-send-logistics'),
						'none'    			=> __( 'None','smart-send-logistics'),
					),
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
		 * get_table_rates function.
		 *
		 * @access public
		 * @return void
		 */
		function get_table_rates() {
			$this->table_rates = array_filter( (array) get_option( $this->table_rate_option ) );
			if(empty($this->table_rates)){
				$methods = $this->get_methods();
				foreach($methods as $method => $method_name){
					if(in_array($method,array('pickup','private'))) {
						$this->table_rates[] = Array (
							'methods'		=> $method,
							'minO' 			=> '0',
							'maxO' 			=> '100000',
							'minwO' 		=> '0',
							'maxwO' 		=> '100000',
							'shippingO' 	=> 49.00,
							'country' 		=> 'DK',
							'method_name' 	=> $method_name
							);
					}
				}
			}
		}
												
		/**
		 * get_methods function.
		 *
		 * @access public
		 * @return void
		 */
		function get_methods(){
			$shipping_methods = array(
				'private'		=> 'Private',
				'privatehome'	=> 'Private to home',
				'commercial'	=> 'Commercial'
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