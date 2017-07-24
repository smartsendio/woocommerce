<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if ( ! class_exists( 'Smartsend_Logistics_Posten' ) ) {
	class Smartsend_Logistics_Posten extends WC_Shipping_Method {
	
		public $PrimaryClass ;
		
		public function __construct() {
			$this->id                 	= 'smartsend_posten'; 
			$this->method_title       	= __( 'Posten' );  
			$this->method_description 	= __( 'Posten Hente Selv' ); 				
			$this->table_rate_option    = 'Posten_table_rate';
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
				'enabled' 			=> array(
					'title' 			=> __( 'Enable/Disable', 'woocommerce' ),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable this shipping method', 'woocommerce' ),
					'default' 			=> 'no'
				),
				'title' 			=> array(
					'title' 			=> __( 'Carrier Title', 'woocommerce' ),
					'type' 				=> 'text',
					'description' 		=> __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'			=> __( 'Posten', 'woocommerce' ),
					'desc_tip'			=> true,
				),
				'domestic_shipping_table' => array(
					'type'      		=> 'shipping_table'
				),
				'cheap_expensive' 	=> array(
					'title'    			=> __( 'Cheapest or most expensive?', 'woocommerce' ),
					'description'     	=> __( 'This controls Cheapest or most expensive on the frontend.', 'woocommerce' ),
					'default'  			=> 'cheapest',
					'type'     			=> 'select',
					'options'  			=> array(
						'cheapest'       	=> __( 'Cheapest', 'woocommerce' ),
						'expensive' 		=> __( 'Most Expensive', 'woocommerce' ),
					)
				),
				'tax_status' 		=> array(
					'title'     		=> __( 'Tax Status', 'woocommerce'  ),
					'type'      		=> 'select',
					'default'   		=> 'taxable',
					'options'   		=> array(
						'taxable' 			=> __( 'Taxable', 'woocommerce'  ),
						'none'    			=> __( 'None', 'woocommerce'  ),
					),
				),
				'pickup_style' 		=> array(
					'title'     		=> __( 'Dropdown format', 'woocommerce' ),
					'type'      		=> 'select',
					'default'   		=> '4',
					'options'   		=> array(
						'1' 				=> __( '#NAME, #STREET', 'woocommerce' ),
						'2'    				=> __( '#NAME, #STREET, #ZIP', 'woocommerce' ),
						'3'    				=> __( '#NAME, #STREET, #CITY', 'woocommerce' ),
						'4'    				=> __( '#NAME, #STREET, #ZIP #CITY', 'woocommerce' ),
					),
				),
				'format' 	=> array(
					'title'    			=> __( 'Format', 'woocommerce' ),
					'description'     	=> __( 'Create a Pacsoft link or a pdf file', 'woocommerce' ),
					'default'  			=> 'pdf',
					'type'     			=> 'select',
					'options'  			=> array(
						'pdf'      	=> __( 'PDF file', 'woocommerce' ),
						'link'      	=> __( 'Pacosft Online link', 'woocommerce' ),
					)
				),
				'quickid' 			=> array(
					'title' 			=> __( 'Pacsoft QuickID', 'woocommerce' ),
					'type' 				=> 'text',
					'default'			=> __( '1', 'woocommerce' ),
					'desc_tip'			=> true,
				),
				'waybillid' 			=> array(
					'title' 			=> __( 'Waybill ID', 'woocommerce' ),
					'description'     	=> __( 'Either just an id or a semicolon separated list of "country,id" (* is all countries). Eg: SE,123;NO,321;*,44', 'woocommerce' ),
					'type' 				=> 'text',
					'default'			=> __( '', 'woocommerce' ),
					'desc_tip'			=> true,
				),
				'notemail' 	=> array(
					'title'    			=> __( 'Email notification', 'woocommerce' ),
					'description'     	=> __( 'Send an email with info about delivery', 'woocommerce' ),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable', 'woocommerce' ),
					'default' 			=> 'yes'
				),
				'notesms' 	=> array(
					'title'    			=> __( 'SMS notification', 'woocommerce' ),
					'description'     	=> __( 'Send an SMS with info about delivery', 'woocommerce' ),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable', 'woocommerce' ),
					'default' 			=> 'yes'
				),
				'prenote' 	=> array(
					'title'    			=> __( 'Pre notification', 'woocommerce' ),
					'description'     	=> __( 'Send an email with info about delivery as soon as the label is created', 'woocommerce' ),
					'type' 				=> 'checkbox',
					'label' 			=> __( 'Enable', 'woocommerce' ),
					'default' 			=> 'no'
				),
				'prenote_receiver' 	=> array(
					'title'    			=> __( 'Pre notification receiver', 'woocommerce' ),
					'description'     	=> __( 'Receivers email address. Leave blank if receiver should be the user.' ),
					'type' 				=> 'text',
					'default'			=> __( '', 'woocommerce' ),
					'desc_tip'			=> true,
				),
				'prenote_sender' 	=> array(
					'title'    			=> __( 'Pre notification sender', 'woocommerce' ),
					'description'     	=> __( 'Senders email address.' ),
					'type' 				=> 'text',
					'default'			=> __( '', 'woocommerce' ),
					'desc_tip'			=> true,
				),
				'prenote_message' 	=> array(
					'title'    			=> __( 'Pre notification message', 'woocommerce' ),
					'description'     	=> __( 'Email message' ),
					'type' 				=> 'text',
					'default'			=> __( '', 'woocommerce' ),
					'desc_tip'			=> true,
				),
				'return' 	=> array(
					'title'    			=> __( 'Return shipping method', 'woocommerce' ),
					'description'     	=> __( 'Method used for return labels', 'woocommerce' ),
					'default'  			=> 'postdanmark',
					'type'     			=> 'select',
					'options'  			=> array(
						'smartsendpostdanmark_private'	=> __( 'Post Danmark', 'woocommerce' ),
						'smartsendposten_private'      	=> __( 'Posten', 'woocommerce' ),
						'smartsendgls_private'      	=> __( 'GLS', 'woocommerce' ),
						'smartsendbring_private'      	=> __( 'Bring', 'woocommerce' ),
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
				foreach($methods as $method){
					$this->table_rates[] = Array (
						'methods'		=> $method,
						'minO' 			=> '1',
						'maxO' 			=> '100000',
						'minwO' 		=> '0',
						'maxwO' 		=> '100000',
						'shippingO' 	=> 7.00,
						'country' 		=> 'DK',
						'method_name' 	=> $method
						);
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
				'private'				=> 'private',
				'privatehome'			=> 'privatehome',
				'commercial'			=> 'commercial',
                'valuemail'				=> 'valuemail',
				'valuemailfirstclass'	=> 'valuemailfirstclass',
				'valuemaileconomy'		=> 'valuemaileconomy',
				'maximail'				=> 'maximail'
				);
			if(function_exists('is_plugin_active') && !is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
				$shipping_methods = array_merge(array('pickup' => 'pickup'),$shipping_methods);
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