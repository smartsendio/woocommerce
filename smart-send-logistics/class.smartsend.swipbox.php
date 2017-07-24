<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if ( ! class_exists( 'Smartsend_Logistics_SwipBox' ) ) {
	class Smartsend_Logistics_SwipBox extends WC_Shipping_Method {
		/**
		 * Constructor for your shipping class
		 *
		 * @access public
		 * @return void
		 */
		public $PrimaryClass ;
		
		public function __construct() {
			$this->id                 	= 'smartsend_swipbox'; // Id for your shipping method. Should be uunique.
			$this->method_title       	= __( 'SwipBox' );  
			$this->method_description 	= __( 'SwipBox pakkestationer.' ); 				
			$this->table_rate_option    = 'SwipBox_table_rate';
			$this->PrimaryClass 		= new Smartsend_Logistics_PrimaryClass();
			$this->init();
		}

		/**
		 * Init your settings
		 *
		 * @access public
		 * @return void
		 */
		function init() {

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
		
		
			$this->shipping_description		= $this->get_option( 'shipping_description' );
			$this->enabled					= $this->get_option( 'enabled' );
			$this->title 					= $this->get_option( 'title' );
			//$this->cost_per_order = $this->get_option( 'cost_per_order' );
			$this->min_amount 				= $this->get_option( 'min_amount', 0 );
			$this->availability 			= 'specific';//$this->get_option( 'availability' );
			$this->countries 				= $this->getCountries();
			$this->requires					= $this->get_option( 'requires' );
			$this->apply_when 				= $this->get_option( 'apply_when' );
			$this->greatMax 				= $this->get_option( 'greatMax' );
			$this->type         			= $this->get_option( 'type' );
			$this->tax_status   			= $this->get_option( 'tax_status' );
			$this->min_order    			= $this->get_option( 'min_order' );
			$this->max_order    			= $this->get_option( 'max_order' );
			$this->shipping_rate			= $this->get_option( 'shipping_rate' );
		
			// Actions
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_table_rates' ) );
	
			// Load Table rates
			$this->get_table_rates();
		}
		
		
		/**
		 * Initialise Gateway Settings Form Fields
		 */
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
					'default'			=> __( 'SwipBox', 'woocommerce' ),
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
						'cheapest'      	=> __( 'Cheapest', 'woocommerce' ),
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
					'title'     			=> __( 'Dropdown format', 'woocommerce' ),
					'type'      			=> 'select',
					'default'   			=> '4',
					'options'   			=> array(
						'1' 					=> __( '#NAME, #STREET', 'woocommerce' ),
						'2'    					=> __( '#NAME, #STREET, #ZIP', 'woocommerce' ),
						'3'    					=> __( '#NAME, #STREET, #CITY', 'woocommerce' ),
						'4'    					=> __( '#NAME, #STREET, #ZIP #CITY', 'woocommerce' ),
					),
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
				'return' 	=> array(
					'title'    			=> __( 'Return shipping method', 'woocommerce' ),
					'description'     	=> __( 'Method used for return labels', 'woocommerce' ),
					'default'  			=> 'postdanmark',
					'type'     			=> 'select',
					'options'  			=> array(
						'postdanmark'      	=> __( 'Post Danmark', 'woocommerce' ),
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
			return array(
				'pickup'	=> 'pickup'
				);
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