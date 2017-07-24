<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once( WP_PLUGIN_DIR . '/woocommerce/includes/abstracts/abstract-wc-order.php' );
require_once( WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-order.php' );

require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.bring.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.gls.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.pickuppoints.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.postdanmark.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.posten.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.swipbox.php' );


class Smartsend_Logistics_Order{

	public $receiver;
	public $sender;
	public $agent;
	public $parcels;
	public $service;
	private $carrier;
	
	/*
	$order->id
	$order->get_total()-$order->get_total_shipping()
	$order->get_order_currency()
	$order->customer_note
	$order->get_items()
	get_product_from_item
	
	
	$order->user_id
	$order->billing_first_name
	$order->billing_last_name
	$order->billing_company
	$order->billing_address_1
	$order->billing_address_2
	$order->billing_city
	$order->billing_postcode
	$order->billing_country
	$order->billing_email
	$order->billing_phone

	$order->shipping_first_name
	$order->shipping_last_name
	$order->shipping_company
	$order->shipping_address_1
	$order->shipping_address_2
	$order->shipping_city
	$order->shipping_postcode
	$order->shipping_country

	//TEST
	get_option( 'smartsend_bring_notemail' )
	
	*/

    public function _construct() {
        
    }
    
    private function getShippingMethod($order) {
    	$line_items_shipping = $order->get_items( 'shipping' );
			
		if(!empty($line_items_shipping)){
			foreach ( $line_items_shipping as $item_id => $item ) {
                $shipMethod_id = $item['method_id'];
                if( !empty($item['method_id']) ) {
			  		$shipMethod_id = esc_html( $item['method_id'] );
			  	}
                if( !empty($item['name']) ) {
			  		$shipMethod = esc_html( $item['name'] );
			  	}
			}
		}
		
		return $shipMethod_id; //return unique id of shipping method
	}
	
	/* Not used anymore
	private function getMethodId($item_id){
		global $wpdb;
		$q= "SELECT `meta_value`
		FROM  `wp_woocommerce_order_itemmeta` 
		WHERE  `order_item_id` =  $item_id"; 
		$results= $wpdb->get_results($q);
		return $results[0];
	} */
    
    private function isSmartSend($order) {
    
    	if(substr($this->getShippingMethod($order), 0, strlen('smartsend_pickup')) === 'smartsend_pickup') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_bring')) === 'smartsend_bring') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_gls')) === 'smartsend_gls') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_swipbox')) === 'smartsend_swipbox') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_postdanmark')) === 'smartsend_postdanmark') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_posten')) === 'smartsend_posten') {
    		return true;
    	} else {
    		return false;
    	}
    
    }
    
    private function isVconnect($order) {
    //Check that this is the name of vConnect in WooCommerce
    	if(substr($this->getShippingMethod($order), 0, strlen('VC_PostDanmark')) === 'VC_PostDanmark') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_GLS')) === 'VC_GLS') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_Bring')) === 'VC_Bring') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_Posten')) === 'VC_Posten') {
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_SwipBox')) === 'VC_SwipBox') {
    		return true;
    	} else {
    		return false;
    	}
    
    }
    
    private function isPickup($order) {
    //Check that this is the name of vConnect in WooCommerce
    	if(substr($this->getShippingMethod($order), -strlen('pickup')) === 'pickup' && $this->isSmartSend($order)) {
    		// It is a Smart Send 'pickup' method.
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_PostDanmark')) === 'VC_PostDanmark') {
    		// It is a vConnect checkout method.
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_GLS')) === 'VC_GLS') {
    		// It is a vConnect checkout method.
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_Bring')) === 'VC_Bring') {
    		// It is a vConnect checkout method.
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_Posten')) === 'VC_Posten') {
    		// It is a vConnect checkout method.
    		return true;
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_SwipBox')) === 'VC_SwipBox') {
    		// It is a vConnect checkout method.
    		return true;
    	} else {
    		return false;
    	}
    
    }
    
    private function hasTracecode($shipment) {
    //NOT implemented
        return false;
    } 
    
    private function getPickup($order) {
    
    	if($this->isSmartSend($order)) {
    		$store_pickup = get_post_custom($order->id);
			$store_pickup = @unserialize($store_pickup['store_pickup'][0]);
			if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);
	
			if(!empty($store_pickup)){
				$pickupData 				= array();
    			$pickupData['id'] 			= (isset($store_pickup['id']) ? $store_pickup['id'] : null);
    			$pickupData['company'] 		= (isset($store_pickup['company']) ? $store_pickup['company'] : null);
    			$pickupData['name'] 		= null;
    			$pickupData['address1']		= (isset($store_pickup['street']) ? $store_pickup['street'] : null);
    			$pickupData['city']			= (isset($store_pickup['city']) ? $store_pickup['city'] : null);
				$pickupData['zip']			= (isset($store_pickup['zip']) ? $store_pickup['zip'] : null);
				$pickupData['country']		= (isset($store_pickup['country']) ? $store_pickup['country'] : null);
				$pickupData['carrier']		= (isset($store_pickup['carrier']) ? $store_pickup['carrier'] : null);
				
    			return $pickupData;
			} else {
				throw new Exception(__("Unable to fetch pickup data") );
			}
        } elseif( $this->isVconnect($order) ) {
        	
        	$pacsoftServicePoint 		= $order->shipping_address_2; 	//get street2
			$pacsoftServicePoint 		= str_replace(' ', '', $pacsoftServicePoint); 	//remove spaces
			$pacsoftServicePointArray 	= explode(":",$pacsoftServicePoint); 			//devide into a array by :
	
			if ( isset($pacsoftServicePointArray) && ( strtolower($pacsoftServicePointArray[0]) == strtolower('ServicePointID') ) ||  strtolower($pacsoftServicePointArray[0]) == strtolower('Pakkeshop') ){
    			$pickupData 				= array();
    			$pickupData['id'] 			= $pacsoftServicePointArray[1];
    			$pickupData['company'] 		= ($order->shipping_company != '' ? $order->shipping_company : $order->shipping_first_name .' '. $order->shipping_last_name );
    			$pickupData['name'] 		= null;
    			$pickupData['address1']		= $order->shipping_address_1;
    			$pickupData['city']			= $order->shipping_city;
				$pickupData['zip']			= $order->shipping_postcode;
				$pickupData['country']		= $order->shipping_country;
				
				// This must be converted to an stdClass!
				return $pickupData;
    			
			} else {
				throw new Exception(__("Missing vConnect agent->id") );
			}		
		} else {
			throw new Exception(__("Not a pickup shipping method") );
		}
    
    }
    
    public function getCarrier($order) {
    
    	if(substr($this->getShippingMethod($order), 0, strlen('smartsendpickup')) === 'smartsendpickup') {
    		//This is the shipping method 'closest'. What is the choosen carrier?
		/*	if (??) {
				return $pickupData->getCarrier();
			} else { */
				throw new Exception(__("Unable to determine carrier for pickup shipping method"));
		/*	} */
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_bring')) === 'smartsend_bring') {
    		return 'bring';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_gls')) === 'smartsend_gls') {
    		return 'gls';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_swipbox')) === 'smartsend_swipbox') {
    		return 'swipbox';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_postdanmark')) === 'smartsend_postdanmark') {
    		return 'postdanmark';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_posten')) === 'smartsend_posten') {
    		return 'posten';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_PostDanmark')) === 'VC_PostDanmark') {
    		return 'postdanmark';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_GLS')) === 'VC_GLS') {
    		return 'gls';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_Bring')) === 'VC_Bring') {
    		return 'bring';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_Posten')) === 'VC_Posten') {
    		return 'posten';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('VC_SwipBox')) === 'VC_SwipBox') {
    		return 'swipbox';
    	} else {
    		throw new Exception(__("Unsupported carrier").": ".$this->getShippingMethod($order) );
    	}
    
    }
    
    public function getSmartSendCarrier($order) {
    
    	if(substr($this->getShippingMethod($order), 0, strlen('smartsendpickup')) === 'smartsendpickup') {
    		//This is the shipping method 'closest'. What is the choosen carrier?
		/*	if (??) {
				return $pickupData->getCarrier();
			} else { */
				throw new Exception(Mage::helper('logistics')->__("Unable to determine carrier for pickup shipping method"));
		/*	} */
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_bring')) === 'smartsend_bring') {
    		return 'smartsendbring';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_gls')) === 'smartsend_gls') {
    		return 'smartsendgls';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_swipbox')) === 'smartsend_swipbox') {
    		return 'smartsendswipbox';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_postdanmark')) === 'smartsend_postdanmark') {
    		return 'smartsendpostdanmark';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('smartsend_posten')) === 'smartsend_posten') {
    		return 'smartsendposten';
    	} elseif(substr($this->getShippingMethod($order), 0, strlen('vconnect_postnord_bestway')) === 'vconnect_postnord_bestway') {
    		return 'smartsendpostdanmark';
    	} else {
    		throw new Exception(__("Unsupported carrier").": ".$this->getShippingMethod($order) );
    	}
    
    }
    
    public function getMethod($order) {
    
    	if(substr($this->getShippingMethod($order), -strlen('pickup')) === 'pickup') {
    		return 'pickup';
    	} elseif(substr($this->getShippingMethod($order), -strlen('private')) === 'private') {
    		return 'private';
    	} elseif(substr($this->getShippingMethod($order), -strlen('privatehome')) === 'privatehome') {
    		return 'privatehome';
    	} elseif(substr($this->getShippingMethod($order), -strlen('commercial')) === 'commercial') {
    		return 'commercial';
    //	} elseif(substr($this->getShippingMethod($order), -strlen('express')) === 'express') {
    //		return 'express';
    	} elseif(substr($this->getShippingMethod($order), -strlen('dpdclassic')) === 'dpdclassic') {
    		return 'dpdclassic';
    	} elseif(substr($this->getShippingMethod($order), -strlen('dpdguarantee')) === 'dpdguarantee') {
    		return 'dpdguarantee';
    	} elseif(substr($this->getShippingMethod($order), -strlen('valuemail')) === 'valuemail') {
    		return 'valuemail';
    	} elseif(substr($this->getShippingMethod($order), -strlen('valuemailfirstclass')) === 'valuemailfirstclass') {
    		return 'valuemailfirstclass';
    	} elseif(substr($this->getShippingMethod($order), -strlen('valuemaileconomy')) === 'valuemaileconomy') {
    		return 'valuemaileconomy';
    	} elseif(substr($this->getShippingMethod($order), -strlen('maximail')) === 'maximail') {
    		return 'maximail';
    	} elseif(substr($this->getShippingMethod($order), -strlen('private_bulksplit')) === 'private_bulksplit') {
    		return 'private_bulksplit';
    	} elseif(substr($this->getShippingMethod($order), -strlen('privatehome_bulksplit')) === 'privatehome_bulksplit') {
    		return 'privatehome_bulksplit';
    	} elseif(substr($this->getShippingMethod($order), -strlen('commercial_bulksplit')) === 'commercial_bulksplit') {
    		return 'commercial_bulksplit';
    	} elseif(substr($this->getShippingMethod($order), -strlen('VC_PostDanmark')) === 'VC_PostDanmark') {
    		return 'pickup';
    	} elseif(substr($this->getShippingMethod($order), -strlen('VC_Posten')) === 'VC_Posten') {
    		return 'pickup';
    	} elseif(substr($this->getShippingMethod($order), -strlen('VC_Bring')) === 'VC_Bring') {
    		return 'pickup';
    	} elseif(substr($this->getShippingMethod($order), -strlen('VC_SwipBox')) === 'VC_SwipBox') {
    		return 'pickup';
    	} elseif(substr($this->getShippingMethod($order), -strlen('VC_GLS')) === 'VC_GLS') {
    		return 'pickup';
    	} else {
    		throw new Exception(__("Unsupported shipping method").": ".$this->getShippingMethod($order) );
    	}
    
    }
    
    public function setOrder($order) {
 		if(!$this->isSmartSend($order) && !$this->isVconnect($order) ) {
 			throw new Exception(__("Unknown carrier").": ".$this->getShippingMethod($order) );
 		}
 		
 		$carrier = $this->getCarrier($order);
    	switch ($carrier) {
    		case 'bring':
				$this->carrier = new Smartsend_Logistics_Bring();
				break;
			case 'gls':
				$this->carrier = new Smartsend_Logistics_GLS();
				break;
			case 'postdanmark':
				$this->carrier = new Smartsend_Logistics_PostDanmark();
				break;
			case 'posten':
				$this->carrier = new Smartsend_Logistics_Posten();
				break;
			case 'swipbox':
				$this->carrier = new Smartsend_Logistics_SwipBox();
				break;
			default:
				throw new Exception(__("Unknown carrier").": ".$this->getShippingMethod($order) );
			break;
		}
 		
		$this->setInfo($order);
		$this->setReceiver($order);
		$this->setSender($order);
		$this->setAgent($order);
		$this->setService($order);
		$this->setParcels($order);
 	}
 	
 	private function setInfo($order) {
 	
 		if($this->getCarrier($order) == 'postdanmark') {
			$type	= $this->carrier->get_option( 'format','pdf');
		} elseif($this->getCarrier($order) == 'posten') {
			$type	= $this->carrier->get_option( 'format','pdf');
		} else {
			$type	= null;
		}

 		$this->info = array(
 			'orderno'		=> $order->id,
 			'type'			=> $type,
   			'reference'		=> $order->id."-".time()."-".rand(9999,10000),
   			'carrier'		=> $this->getCarrier($order),
   			'method'		=> $this->getMethod($order),
   			'return'		=> 0,
   			'totalprice'	=> $order->get_total(),
   			'shipprice'		=> $order->get_total_shipping(),
   			'currency'		=> $order->get_order_currency(),
   			'test'			=> 0,
 			);
	
 	}

 	private function setReceiver($order) {
 	
 		if( $this->isVconnect($order) ) {
 			$this->receiver = array(
				'receiverid'=> $order->user_id,
				'company'	=> $order->billing_company,
				'name1' 	=> $order->billing_first_name .' '. $order->billing_last_name,
				'name2'		=> null,
				'address1'	=> $order->billing_address_1,
				'address2'	=> $order->billing_address_2,
				'city'		=> $order->billing_city,
				'zip'		=> $order->billing_postcode,
				'country'	=> $order->billing_country,
				'sms'		=> $order->billing_phone,
				'mail'		=> $order->billing_email
				);
 		} else {
			$this->receiver = array(
				'receiverid'=> $order->user_id,
				'company'	=> $order->shipping_company,
				'name1' 	=> $order->shipping_first_name .' '. $order->shipping_last_name,
				'name2'		=> null,
				'address1'	=> $order->shipping_address_1,
				'address2'	=> $order->shipping_address_2,
				'city'		=> $order->shipping_city,
				'zip'		=> $order->shipping_postcode,
				'country'	=> $order->shipping_country,
				'sms'		=> $order->billing_phone, // Billing used
				'mail'		=> $order->billing_email // Billing used
				);
		}
 	}
 	
 	private function setSender($order) {
 	
 		$carrier = $this->getCarrier($order);
 	
 		switch ($carrier) {
 			case 'postdanmark':
 				$this->sender = array(
 					'senderid' 	=> $this->carrier->get_option('quickid',1),
 					'company'	=> null,
					'name1'		=> null,
					'name2'		=> null,
					'address1'	=> null,
					'address2'	=> null,
					'zip'		=> null,
					'city'		=> null,
					'country'	=> null,
					'sms'		=> null,
					'mail'		=> null
 					);
 				break;
 			case 'posten':
 				$this->sender = array(
 					'senderid' 	=> $this->carrier->get_option('quickid',1),
 					'company'	=> null,
					'name1'		=> null,
					'name2'		=> null,
					'address1'	=> null,
					'address2'	=> null,
					'zip'		=> null,
					'city'		=> null,
					'country'	=> null,
					'sms'		=> null,
					'mail'		=> null
 					);
 				break;
			case 'bring':
				$this->sender = array(
					'senderid'	=> null,
					'company'	=> null,
					'name1'		=> null,
					'name2'		=> null,
					'address1'	=> null,
					'address2'	=> null,
					'zip'		=> null,
					'city'		=> null,
					'country'	=> null,
					'sms'		=> null,
					'mail'		=> null
					);
				break;
			default:
				$this->sender = null;
		}

 	}
 	
 	private function setAgent($order) {
 	
 		if($this->isPickup($order)) {
 		
 			$pickup_date = $this->getPickup($order);
			$this->agent = array(
				'agentno'		=> (isset($pickup_date['id']) ? $pickup_date['id'] : null),
				'agenttype'		=> null,//'PDK',
				'company'		=> (isset($pickup_date['company']) ? $pickup_date['company'] : null),
				'name1'			=> null,
				'name2'			=> null,
				'address1'		=> (isset($pickup_date['address1']) ? $pickup_date['address1'] : null),
				'address2'		=> null,
				'zip'			=> (isset($pickup_date['zip']) ? $pickup_date['zip'] : null),
				'city'			=> (isset($pickup_date['city']) ? $pickup_date['city'] : null),
				'country'		=> (isset($pickup_date['country']) ? $pickup_date['country'] : null),
				);
 		} else {
 			$this->agent = null;
 		}
 	}
 	
 	private function setService($order) {

 		$carrier = $this->getCarrier($order);
 	
 		switch ($carrier) {
 			case 'postdanmark':
				$this->service = array(
					"notemail"			=> ($this->carrier->get_option( 'notemail','yes') == 'yes' ? $order->billing_email : null),
					"notesms"			=> ($this->carrier->get_option( 'notesms','yes') == 'yes' ? $order->billing_phone : null),
					"prenote"			=> ($this->carrier->get_option( 'prenote','yes') == 'yes' ? true : false),
					"prenote_from"		=> ($this->carrier->get_option( 'prenote_receiver','user') == 'user' ? $order->billing_email : $this->carrier->get_option( 'prenote_receiver','user')),
					"prenote_to"		=> ($this->carrier->get_option( 'prenote_sender','') != '' ? $this->carrier->get_option( 'prenote_sender', '') : null),
					"prenote_message"	=> ($this->carrier->get_option( 'prenote_message','') != '' ? $this->carrier->get_option( 'prenote_message', '') : null),
					);
				break;
			case 'gls':
				$this->service = array(
					"notemail"			=> ($this->carrier->get_option( 'notemail','yes') == 'yes' ? $order->billing_email : null),
					"notesms"			=> ($this->carrier->get_option( 'notesms','yes') == 'yes' ? $order->billing_phone : null),
					"prenote"			=> null,
					"prenote_from"		=> null,
					"prenote_to"		=> null,
					"prenote_message"	=> null,
					);
				break;
			case 'swipbox':
				$this->service = array(
					"notemail"			=> ($this->carrier->get_option( 'notemail','yes') == 'yes' ? $order->billing_email : null),
					"notesms"			=> ($this->carrier->get_option( 'notesms','yes') == 'yes' ? $order->billing_phone : null),
					"prenote"			=> null,
					"prenote_from"		=> null,
					"prenote_to"		=> null,
					"prenote_message"	=> null,
					);
				break;
			case 'bring':
				$this->service = array(
					"notemail"			=> ($this->carrier->get_option( 'notemail','yes') == 'yes' ? $order->billing_email : null),
					"notesms"			=> ($this->carrier->get_option( 'notesms','yes') == 'yes' ? $order->billing_phone : null),
					"prenote"			=> null,
					"prenote_from"		=> null,
					"prenote_to"		=> null,
					"prenote_message"	=> null,
					);
				break;
			case 'posten':
				$this->service = array(
					"notemail"			=> ($this->carrier->get_option( 'notemail','yes') == 'yes' ? $order->billing_email : null),
					"notesms"			=> ($this->carrier->get_option( 'notesms','yes') == 'yes' ? $order->billing_phone : null),
					"prenote"			=> ($this->carrier->get_option( 'prenote','yes') == 'yes' ? true : false),
					"prenote_from"		=> ($this->carrier->get_option( 'prenote_receiver','user') == 'user' ? $order->billing_email : $this->carrier->get_option( 'prenote_receiver','user')),
					"prenote_to"		=> ($this->carrier->get_option( 'prenote_sender','') != '' ? $this->carrier->get_option( 'prenote_sender', '') : null),
					"prenote_message"	=> ($this->carrier->get_option( 'prenote_message','') != '' ? $this->carrier->get_option( 'prenote_message', '') : null),
					);
				break;
			default:
				throw new Exception(__("Unknown carrier when adding services"));
				
		}
 		
 	}
 	
 	/*
	 * This code creates a parcel
	 * Source: http://www.amitbera.com/programmatically-create-shipment-of-a-new-order-in-magento/
	 */
 	private function createShipment($order) {
 	//NOT DONE - STILL MAGENTO
			$qty = array();
			foreach($order->getAllItems() as $eachOrderItem){
 				$Itemqty = 0;
				$Itemqty = $eachOrderItem->getQtyOrdered()
						- $eachOrderItem->getQtyShipped()
						- $eachOrderItem->getQtyRefunded()
						- $eachOrderItem->getQtyCanceled();
				$qty[$eachOrderItem->getId()]=$Itemqty;
 
			}
 
			/* check order shipment is prossiable or not */
 
			$email = false;
			$includeComment = false;
			$comment = "";
 
			if ($order->canShip()) {
				/* @var $shipment Mage_Sales_Model_Order_Shipment */
				/* prepare to create shipment */
				$shipment = $order->prepareShipment($qty);
				if ($shipment) {
					$shipment->register();
					
					//Add a comment. Second parameter is whether or not to email the comment to the user
					$shipment->addComment($comment, $email && $includeComment);
					
					// Set the order status as 'Processing'
					//$shipment->getOrder()->setIsInProcess(true);
					
					try {
						$transactionSave = Mage::getModel('core/resource_transaction')
								->addObject($shipment)
								->addObject($shipment->getOrder())
								->save();
						
						//Email the customer that the order is sent
						$shipment->sendEmail($email, ($includeComment ? $comment : ''));
					} catch (Mage_Core_Exception $e) {
						throw new Exception(Mage::helper('logistics')->__("Error while creating parcel: ".$e));
					}
 				return $shipment;
				}
			}
 	}
 	
 	private function getShipmentWeight($order) {
 		$weight = 0;
		if ( sizeof( $order->get_items() ) > 0 ) {
			foreach( $order->get_items() as $item ) {
				if ( $item['product_id'] > 0 ) {
					$_product = $order->get_product_from_item( $item );
					if ( ! $_product->is_virtual() ) {
						$weight += $_product->get_weight() * $item['qty'];
					}
				}
			}
		}
		if ( $weight > 0 ) {
			return $weight;
		} else {
			return null;
		}
 	}
 	
 	private function addParcel($order) {
 	
 		$parcel = array(
				'shipdate'	=> null,
         		'reference' => $order->id,
         		'weight'	=> $this->getShipmentWeight($order),
         		'height'	=> null,
         		'width'		=> null,
         		'length'	=> null,
         		'size'		=> null,
         		'freetext1'	=> null,
         		'freetext2'	=> null,
         		'freetext3'	=> null,
				'items' 	=> array(),
				);
			$ordered_items = $order->get_items();
			foreach($ordered_items as $item) {
				$parcel['items'][] = $this->setItems($order,$item);
			}
			$this->parcels[] = $parcel;
	}
 	
 	private function setParcels($order) {

 		$this->parcels = array();
 		
 		if ( sizeof( $order->get_items() ) > 0 ) {
			$this->addParcel($order);
		} else {
			//Order has no shipments and cannot be shipped
			throw new Exception(__("No items that could be shipped"));
		}
 	}
 	
 	private function setItems($order,$item) {
 
 		$_product = $order->get_product_from_item( $item );
		if ( ! $_product->is_virtual() ) {
			$weight = $_product->get_weight();
		} else {
			$weight = null;
		}
		
 		return array(
 			'sku'		=> $_product->get_sku(),
            'title'		=> $_product->get_title(),
            'quantity'	=> $item['qty'],
            'unitweight'=> $weight,
            'unitprice'	=> $_product->get_price(),
            'currency'	=> get_woocommerce_currency()
            );
 	}
 	
 	public function getOrder() {
 	
 		$json 				= $this->info;
 		$json['receiver'] 	= $this->receiver;
 		$json['agent'] 		= $this->agent;
 		$json['sender'] 	= $this->sender;
 		$json['service'] 	= $this->service;
 		$json['parcels'] 	= $this->parcels;
 		
 		return $json;
 	}

}
