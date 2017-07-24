<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

if ( ! class_exists( 'Smartsend_Logistics_Posten_Pickup' ) ) {
	class Smartsend_Logistics_Posten_Pickup extends WC_Shipping_Method {
	
		public $PrimaryClass ;
		
		/**
	 	* Constructor.
	 	*/
		public function __construct( $instance_id = 0 ) {
			$this->id						= 'smartsend_posten_pickup';
			$this->instance_id				= absint( $instance_id );
			$this->title					= __( 'Posten','smart-send-logistics') .' - '.__( 'Pickuppoint','smart-send-logistics');
			//$this->method_description		= ''; //Description show in the shipping method settings
			$this->method_title				= $this->title; //Menu point title for the settings
			$this->has_settings				= false; //Remove menu point
		}
		
		/**
		* is_available function.
	 	* @param array $package
	 	* @return bool
	 	*/
		public function is_available( $package ){
			return false;
		}

		/**
		 * calculate_shipping function.
		 *
		 * @access public
		 * @param mixed $package
		 * @return void
		 */
		function calculate_shipping( $package = array() ) {
		}

	}
}