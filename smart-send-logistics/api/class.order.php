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

/* This class is called by using the code:

$order = new Smartsend_Logistics_Order();
$order->setOrderObject($order_object);
$order->setReturn(true);
try{
	$order->setInfo();
	$order->setReceiver();
	$order->setSender();
	$order->setAgent();
	$order->setServices();
	$order->setParcels();
	
	//All done. Add to request.
	$request_array[] = $order->getFinalOrder();
} catch (Exception $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n";
}

*/

class Smartsend_Logistics_Order {

	private $_order;
	private $_info;
	private $_receiver;
	private $_sender;
	private $_agent;
	private $_services;
	private $_parcels = array();
	private $_return = false;
	private $_test = false;
	private $_cms = 'woocommerce';
	
	public function _construct() {}
	
	/**
	* 
	* Set the order object
	*/
	public function setOrderObject($order_object) {
		$this->_order = $order_object;
	}
	
	/**
	* 
	* Set wheter or not the label is a return label
	*/
	public function setReturn($return=false) {
		$this->_return = $return;
	}
	
	/**
	* 
	* Construct the order array that is used to create the final JSON request.
	* @return array
	*/
	public function getFinalOrder() {
		return array_merge($this->_info,array(
			'receiver'	=> $this->_receiver,
			'sender'	=> $this->_sender,
			'agent'		=> $this->_agent,
			'service'	=> $this->_service,
			'parcels'	=> $this->_parcels
			));	
	}


/*****************************************************************************************
 * Functions to return true/false for different statements
 ****************************************************************************************/

	/**
	* 
	* Check if order is placed by a SmartSend or a vConnect shipping method
	* @return boolean
	*/
	private function isSmartsendOrVConnect() {

		if($this->isSmartsend() == true) {
			return true;
		} elseif($this->isVconnect() == true) {
			return true;
		} else {
			return false;
		}
	}

	/**
	* 
	* Check if order is a pickup shipping method from SmartSend or vConnect
	* @return boolean
	*/	
	private function isPickup() {

		if($this->isPickupSmartsend() == true) {
			return true;
		} elseif($this->isPickupVconnect() == true) {
			return true;
		} else {
			return false;
		}

	}

	/**
	* 
	* Check if the label is a return label for the order
	* @return boolean
	*/	
	private function isReturn()	{
		return $this->_return;
	}


/*****************************************************************************************
 * Functions to get pickup data, carrier data, carrier settings
 ****************************************************************************************/

	/**
	* 
	* Check if order is placed by a SmartSend shipping method
	* @return boolean
	*/
	private function isSmartsend() {
	
		$method = strtolower($this->getShippingName());
	
		//Check if shipping methode starts with 'smartsend'
		if(substr($method, 0, strlen('smartsend')) === 'smartsend') {
			return true;
		} else {
			return false;
		}
	
	}

	/**
	* 
	* Check if order is placed by a vConnect shipping method
	* @return boolean
	*/
	private function isVconnect() {
	
		$method = strtolower($this->getShippingName());
	
		//Check if shipping methode starts with 'vconnect' or 'vc'
		if(substr($method, 0, strlen('vconnect')) === 'vconnect') {
			return true;
		} elseif(substr($method, 0, strlen('vc')) === 'vc') {
			return true;
		} else {
			return false;
		}
	
	}
	
	/**
	* 
	* Check if order is a pickup shipping method from SmartSend
	* @return boolean
	*/	
	private function isPickupSmartsend() {
	
		if($this->isSmartsend() == true) {
			$method = strtolower($this->getShippingName());
	
			//Check if shipping methode ends with 'pickup'
			if(substr($method, -strlen('pickup')) === 'pickup') {
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	
	}

	/**
	* 
	* Check if order is a pickup shipping method from vConnect
	* @return boolean
	*/	
	private function isPickupVconnect() {
	
		if($this->isVconnect() == true) {
			$method = strtolower($this->getShippingName());
	
			//Check if shipping methode ends with 'pickup'
			if(substr($method, -strlen('pickup')) === 'pickup') {
				return true;
			}elseif(substr($method, -strlen('bestway')) === 'bestway') {
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	* 
	* Get pickup id of delivery point
	* @return string
	*/	
	private function getPickupId() {
	
		$pickupdata = $this->getPickupData();
		return (isset($pickupdata['id']) ? $pickupdata['id'] : null);
	
	}

	/**
	* 
	* Get pickup data for delivery point
	* @return array
	*/	
	private function getPickupData() {

		if($this->isPickupSmartsend() == true) {
			return $this->getPickupDataSmartsend();
		} elseif($this->isPickupVconnect() == true) {
			return $this->getPickupDataVconnect();
		} else {
			throw new Exception( "Trying to access pickup data for an order that is not a pickup point order." );
		}

	}
	
		/**
		* 
		* Get pickup data for a vConnect delivery point
		* @return array
		*/	
		private function getPickupDataVconnect() {
		
			$billing_address = $this->getBillingAddress();
	
			$pacsoftServicePoint 		= str_replace(' ', '', $billing_address['address2']); 	//remove spaces
			$pacsoftServicePointArray 	= explode(":",$pacsoftServicePoint); 			//devide into a array by :

			if ( isset($pacsoftServicePointArray) && ( strtolower($pacsoftServicePointArray[0]) == strtolower('ServicePointID') ) ||  strtolower($pacsoftServicePointArray[0]) == strtolower('Pakkeshop') ){
				$pickupData = array(
					'id' 		=> $pacsoftServicePointArray[1]."-".time()."-".rand(9999,10000),
					'agentno'	=> $pacsoftServicePointArray[1],
					'agenttype'	=> ($this->getShippingCarrier() == 'postdanmark' ? 'PDK' : null),
					'company' 	=> $billing_address['company'],
					'name1' 	=> $billing_address['name1'],
					'name2' 	=> $billing_address['name2'],
					'address1'	=> $billing_address['address1'],
					'address2' 	=> null,
					'city'		=> $billing_address['city'],
					'zip'		=> $billing_address['zip'],
					'country'	=> $billing_address['country'],
					'sms' 		=> null,
					'mail' 		=> null,
					);
			
				return $pickupData;
			
			} else {
				return null;
			}
		
		}
	
	/**
	* 
	* Get carrier formatted without 'smartsend'
	* @return string
	*/
	public function formatCarrier($carrier,$format=0) {
		
		if($format != 0 && $format != 1 && $format != 2 && $format != 3 &&  $format != 4 ) {
			//Change this code for each CMS system
			throw new Exception('Unknown format for carrier formatting.');
		}
		
		$carrier_lowcase = strtolower($carrier);
		
		switch ($carrier_lowcase) {
			case 'postdanmark':
				if($format == 0) {
					return 'Postdanmark';
				} elseif($format == 1) {
					return 'postdanmark';
				} elseif($format == 2) {
					return 'PostDanmark';
				} elseif($format == 3) {
					return 'Post Danmark';
				} elseif($format == 4) {
					return 'smartsendpostdanmark';
				}
				break;
			case 'posten':
				if($format == 0) {
					return 'Posten';
				} elseif($format == 1) {
					return 'posten';
				} elseif($format == 2) {
					return 'Posten';
				} elseif($format == 3) {
					return 'Posten';
				} elseif($format == 4) {
					return 'smartsendposten';
				}
				break;
			case 'gls':
				if($format == 0) {
					return 'Gls';
				} elseif($format == 1) {
					return 'gls';
				} elseif($format == 2) {
					return 'GLS';
				} elseif($format == 3) {
					return 'GLS';
				} elseif($format == 4) {
					return 'smartsendgls';
				}
				break;
			case 'bring':
				if($format == 0) {
					return 'Bring';
				} elseif($format == 1) {
					return 'bring';
				} elseif($format == 2) {
					return 'Bring';
				} elseif($format == 3) {
					return 'Bring';
				} elseif($format == 4) {
					return 'smartsendbring';
				}
				break;
			default:
				//Change this code for each CMS system
				throw new Exception('Unable to determine carrier for formatting.');
		}
		
	}
	
	/**
	* 
	* Get data about 0: shipping carrier, 1: shipping method, 2: shipping name/id, 3: Carrier raw
	* Magento example
	*	0: gls, 1: pickup, 2: smartsendpostdanmark_pickup, 3: smartsendpostdanmark
	* @return array
	*/
	private function renameShipping($shipping_id) {
		
		$carrier_raw = null;
	
		// Carrier
			if(substr($shipping_id, 0, strlen('smartsendpickup')) === 'smartsendpickup' || substr($shipping_id, 0, strlen('smartsend_pickup')) === 'smartsend_pickup') {
				$carrier = $this->getPickupCarrier();
				if($carrier == '') {
					throw new Exception("Unable to determine carrier for pickup shipping method");
				}
				$carrier_raw = $this->formatCarrier($carrier,4);
			} elseif(substr($shipping_id, 0, strlen('smartsendbring')) === 'smartsendbring' || substr($shipping_id, 0, strlen('smartsend_bring')) === 'smartsend_bring') {
				$carrier = 'bring';
				$carrier_raw = 'smartsendbring';
			} elseif(substr($shipping_id, 0, strlen('smartsendgls')) === 'smartsendgls' || substr($shipping_id, 0, strlen('smartsend_gls')) === 'smartsend_gls') {
				$carrier = 'gls';
				$carrier_raw = 'smartsendgls';
			} elseif(substr($shipping_id, 0, strlen('smartsendpostdanmark')) === 'smartsendpostdanmark' || substr($shipping_id, 0, strlen('smartsend_postdanmark')) === 'smartsend_postdanmark') {
				$carrier = 'postdanmark';
				$carrier_raw = 'smartsendpostdanmark';
			} elseif(substr($shipping_id, 0, strlen('smartsendposten')) === 'smartsendposten' || substr($shipping_id, 0, strlen('smartsend_posten')) === 'smartsend_posten') {
				$carrier = 'posten';
				$carrier_raw = 'smartsendposten';
			} elseif(substr($shipping_id, 0, strlen('vconnect_postnord')) === 'vconnect_postnord') {
				$carrier = 'postdanmark';
				$carrier_raw = 'smartsendpostdanmark';
			} elseif(substr($shipping_id, 0, strlen('vconnect_postdanmark')) === 'vconnect_postdanmark') {
				$carrier = 'postdanmark';
				$carrier_raw = 'smartsendpostdanmark';
			} elseif(substr($shipping_id, 0, strlen('vconnect_posten')) === 'vconnect_posten') {
				$carrier = 'posten';
				$carrier_raw = 'smartsendposten';
			} elseif(substr($shipping_id, 0, strlen('vconnect_gls')) === 'vconnect_gls') {
				$carrier = 'gls';
				$carrier_raw = 'smartsendgls';
			} elseif(substr($shipping_id, 0, strlen('vconnect_bring')) === 'vconnect_bring') {
				$carrier = 'bring';
				$carrier_raw = 'smartsendbring';
			} else {
				throw new Exception( "Unsupported carrier <" .$shipping_id. ">" );
			}
	
		// Method
			if(substr($shipping_id, -strlen('pickup')) === 'pickup') {
				$method = 'pickup';
			} elseif(substr($shipping_id, -strlen('private')) === 'private') {
				$method = 'private';
			} elseif(substr($shipping_id, -strlen('privatehome')) === 'privatehome') {
				$method = 'privatehome';
			} elseif(substr($shipping_id, -strlen('commercial')) === 'commercial') {
				$method = 'commercial';
			} elseif(substr($shipping_id, -strlen('express')) === 'express') {
				$method = 'express';
			} elseif(substr($shipping_id, -strlen('dpdclassic')) === 'dpdclassic') {
				$method = 'dpdclassic';
			} elseif(substr($shipping_id, -strlen('dpdguarantee')) === 'dpdguarantee') {
				$method = 'dpdguarantee';
			} elseif(substr($shipping_id, -strlen('valuemail')) === 'valuemail') {
				$method = 'valuemail';
			} elseif(substr($shipping_id, -strlen('valuemailfirstclass')) === 'valuemailfirstclass') {
				$method = 'valuemailfirstclass';
			} elseif(substr($shipping_id, -strlen('valuemaileconomy')) === 'valuemaileconomy') {
				$method = 'valuemaileconomy';
			} elseif(substr($shipping_id, -strlen('maximail')) === 'maximail') {
				$method = 'maximail';
			} elseif(substr($shipping_id, -strlen('private_bulksplit')) === 'private_bulksplit') {
				$method = 'private_bulksplit';
			} elseif(substr($shipping_id, -strlen('privatehome_bulksplit')) === 'privatehome_bulksplit') {
				$method = 'privatehome_bulksplit';
			} elseif(substr($shipping_id, -strlen('commercial_bulksplit')) === 'commercial_bulksplit') {
				$method = 'commercial_bulksplit';
			} elseif(substr($shipping_id, -strlen('bestway')) === 'bestway') {
				$method = 'pickup';
			} else {
				throw new Exception('Uanble to determine shipping method.' );
			}
	
		return array($carrier,$method,$shipping_id,$carrier_raw);
	}
	
	/**
	* 
	* Get shipping carrier
	* @return string
	*/
	public function getShippingCarrier() {
		$shipping_info = $this->getShippingInfo();
		
		if(isset($shipping_info[0]) && $shipping_info[0] != '') {
			return $shipping_info[0];
		} else {
			//Change this code for each CMS system
			throw new Exception('Unable to determine shipping carrier.');
		}
	
	}
	
	/**
	* 
	* Get shipping method
	* @return string
	*/
	public function getShippingMethod() {
		$shipping_info = $this->getShippingInfo();
		
		if(isset($shipping_info[1]) && $shipping_info[1] != '') {
			return $shipping_info[1];
		} else {
			//Change this code for each CMS system
			throw new Exception('Unable to determine shipping method.');
		}
	}
	
	/**
	* 
	* Get shipping name/id
	* @return string
	*/
	public function getShippingName() {
		$shipping_info = $this->getShippingInfo();
		
		if(isset($shipping_info[2]) && $shipping_info[2] != '') {
			return $shipping_info[2];
		} else {
			//Change this code for each CMS system
			throw new Exception('Unable to determine shipping id.');
		}
	
	}
	
	/**
	* 
	* Get raw carrier name/id
	* @return string
	*/
	public function getShippingCarrierId() {
		$shipping_info = $this->getShippingInfo();
		
		if(isset($shipping_info[3]) && $shipping_info[3] != '') {
			return $shipping_info[3];
		} else {
			//Change this code for each CMS system
			throw new Exception('Unable to determine carrier id.');
		}
	
	}
	
	/**
	* 
	* Get data about 0: shipping carrier, 1: shipping method, 2: shipping name/id
	* @return array
	*/
	private function getShippingInfo() {
		/* TEST
		return array('postdanmark','pickup','smartsendpostdanmark_pickup'); */
		
		$shipping_id = $this->getShippingId();
		
		$shipping_info = $this->renameShipping($shipping_id);
		
		if($this->isReturn() == true) {
			$carrier = $this->formatCarrier($shipping_info[0],1);
			switch ($carrier) {
				case 'postdanmark':
					$settings = $this->getSettingsPostdanmark();
					$return_shipping_method = $settings['return'];
					break;
				case 'posten':
					$settings = $this->getSettingsPosten();
					$return_shipping_method = $settings['return'];
					break;
				case 'gls':
					$settings = $this->getSettingsGls();
					$return_shipping_method = $settings['return'];
					break;
				case 'bring':
					$settings = $this->getSettingsBring();
					$return_shipping_method = $settings['return'];
					break;
				default:
					//Change this code for each CMS system
					throw new Exception('Unable to determine shipping method for return.');
			}
			
			if($return_shipping_method != '') {
				$shipping_info = $this->renameShipping($return_shipping_method);
			}
		}
		
		return $shipping_info;
		
	}
 	
 	/**
	* 
	* Get unique order reference. Constructed from ordernumber, timestamp and a random number
	* @return string
	*/
 	private function getOrderReference() {
 	
 		return $this->getOrderId()."-".time()."-".rand(9999,10000);
 	
 	}
 	
 	/**
	* 
	* Get the settings from the carrier that would be used if this is a normal label.
	* This is not nessesary the same as the actual carrier if one uses a different carrier
	* for return labels.
	* @return array
	*/
 	private function getSettingsCarrier() {
 	
 		$carrier = $this->formatCarrier($this->getShippingCarrier(),1);
		switch ($carrier) {
			case 'postdanmark':
				$settings = $this->getSettingsPostdanmark();
				break;
			case 'posten':
				$settings = $this->getSettingsPosten();
				break;
			case 'gls':
				$settings = $this->getSettingsGls();
				break;
			case 'bring':
				$settings = $this->getSettingsBring();
				break;
			default:
				$settings = null;
		}
		
		return $settings;
		
	}
	
	/**
	 *
	 * Function to return if waybill id if any
	 * @return string
	 */
	private function getWaybill($string,$country) {

		//Devide string into array
		$array = explode(";", $string);
	
		//Remove empty fields
		$array = array_filter($array);
	
		//Check if there is entries
		if(!empty($array) || !is_array($array)) {
			if(strpos($array[0], ',') !== FALSE) {
		
				$new_array = array();
				foreach($array as $element) {
					//Devide string into array
					$line = explode(",", $element);
					if(isset($line[0])) {
						$new_array[$line[0]] = $line[1];
					}
				}
			
				if(isset($new_array[$country])) {
					return $new_array[$country];
				} elseif(isset($new_array["*"])) {
					return $new_array["*"];
				}
			} else {
				//Only one id is entered
				return $array[0];
			}
		} else {
			return null;
		}

	}
 	

/*****************************************************************************************
 * Functions to set order parameters
 ****************************************************************************************/

	/**
	* 
	* Set the meta data for the order
	*/
	public function setInfo() {
	
		$carrier 	= $this->formatCarrier($this->getShippingCarrier(),1);
		$method 	= $this->getShippingMethod();
		
		$settings 	= $this->getSettingsCarrier();
		$type 		= (isset($settings['format']) ? $settings['format'] : null);

 		$this->_info = array(
 			'orderno'		=> $this->getOrderId(),
 			'type'			=> $type,
   			'reference'		=> $this->getOrderReference(),
   			'carrier'		=> $carrier,
   			'method'		=> $method,
   			'return'		=> $this->isReturn(),
   			'totalprice'	=> $this->getOrderPriceTotal(),
   			'shipprice'		=> $this->getOrderPriceShipping(),
   			'currency'		=> $this->getOrderPriceCurrency(),
   			'test'			=> $this->_test,
 			);
	
	}
	
	/**
	* 
	* Set the receiver information
	*/
	public function setReceiver() {
	
		if($this->isSmartSend() == true) {
			$this->_receiver = $this->getShippingAddress();
		} elseif($this->isVconnect == true) {
			$this->_receiver = $this->getBillingAddress();
		} else {
			//Change this code for each CMS system
			throw new Exception('Unable to set receiver.');
		}
	
	}
	
	/**
	* 
	* Set the sender information
	*/
	public function setSender() {
	
		$carrier 	= $this->formatCarrier($this->getShippingCarrier(),1);
		
		switch ($carrier) {
			case 'postdanmark':
				$settings 	= $this->getSettingsPostdanmark();
				$sender 	= array(
					'senderid' 	=> (isset($settings['quickid']) ? $settings['quickid'] : null),
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
				$settings 	= $this->getSettingsPosten();
				$sender 	= array(
					'senderid' 	=> (isset($settings['quickid']) ? $settings['quickid'] : null),
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
				$sender 	= array(
					'senderid' 	=> null,
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
		}
		
		$this->_sender = $sender;
	
	}
	
	/**
	* 
	* Set the agen information
	*/
	public function setAgent() {
	
		if($this->isPickup() == true) {
			$this->_agent = $this->getPickupData();
		} else {
			$this->_agent = null;
		}
	
	}
	
	/**
	* 
	* Set the services that is used for the order
	*/
	public function setServices() {
	
		$settings = $this->getSettingsCarrier();
		
		$this->_service = array(
			'notemail'			=> ($settings['notemail'] == 1 ? $this->_receiver['mail'] : null),
			'notesms'			=> ($settings['notesms'] == 1 ? $this->_receiver['sms'] : null),
			'prenote'			=> $settings['prenote'],
			'prenote_from'		=> $settings['prenote_from'],
			'prenote_receiver'	=> ($settings['prenote_receiver'] == '' ? $this->_receiver['mail'] : $settings['prenote_receiver']),
			'prenote_message'	=> ($settings['prenote_message'] != '' ? $settings['prenote_message'] : null),
			'waybillid'			=> $this->getWaybill($settings['waybillid'],$this->_receiver['country'])
			);
	
	}
	
	/**
	* 
	* Set the parcels. Each parcel contains items.
	*/
	public function setParcels() {

		//Get all shipments for the order
		$shipments = $this->getShipments();
		
		if(!empty($shipments)) {
			//Go through shipments and check for Track & Trace
			foreach($shipments as $shipment) {
				if($this->isReturn() == true) {
					//Add shipment to order object as a parcel
					$this->addShipment($shipment);
				} else {
					if( !$this->getShipmentTrace($shipment) ) {
						//Add shipment to order object as a parcel
						$this->addShipment($shipment);
					}
				}
			}
			
			if(empty($this->_parcels)) {
				throw new Exception('No parcels without trace code');
			}
		} else {
			if($this->getUnshippedItems() != null) {
				$this->createShipment();
			} else {
				throw new Exception('No unshipped items');
			}
		}
	
		if(empty($this->_parcels)) {
			throw new Exception('No parcels to ship');
		}

	}
		
		
/*****************************************************************************************
 * CMS dependent functions
 ****************************************************************************************/

	/**
	* 
	* Get shipping name/id
	* @return string
	*/
	public function getShippingId() {
	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$line_items_shipping = $this->_order->get_items( 'shipping' );
			
			if(!empty($line_items_shipping)){
				foreach ( $line_items_shipping as $item_id => $item ) {
					if( !empty($item['name']) ) {
						$shipMethod = esc_html( $item['name'] );
					}
					if( !empty($item['method_id']) ) {
						$shipMethod_id = esc_html( $item['method_id'] );
					}
				}
			}
		
			return $shipMethod_id; //return unique id of shipping method
		} /* WooCommerce end */
		
		/* Magento start */
		if($this->_cms == 'magento') {
			$shipMethod_id = $this->_order->getShippingMethod();
			
			return $shipMethod_id; //return unique id of shipping method
		} /* Magento end */
	
	}

	/**
	* 
	* Get carrier name based on the pickup information.
	* Used if the shipping method is 'closest pickup point'
	* @return string
	*/
	private function getPickupCarrier() {
	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$store_pickup = get_post_custom($order->id);
			$store_pickup = @unserialize($store_pickup['store_pickup'][0]);
			if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);
	
			if(!empty($store_pickup) && isset($store_pickup['carrier'])){				
    			return $store_pickup['carrier'];
			} else {
				return null;
			}
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
			$pickupModel = Mage::getModel('logistics/pickup');
			$pickupData = $pickupModel->getCollection()->addFieldToFilter('order_id', $this->_order->getOrderId() )->getFirstItem();        //pickup data 
			if ($pickupData->getData()) {
				$carrier = $pickupData->getCarrier();
			} else {
				$carrier = null;
			}
		} /* Magento end */
	
	}
 
 	/**
	* 
	* Get the order id
	* @return string
	*/
 	private function getOrderId() {
 	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			return $this->_order->id;
		} /* WooCommerce end */
		
		/* Magento start */
		if($this->_cms == 'magento') {
			return $this->_order->getIncrementId();
		} /* Magento end */
 	}
 	
 	/**
	* 
	* Get total price of order including tax
	* @return float
	*/
 	private function getOrderPriceTotal() {
 	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			return $this->_order->get_total();
		} /* WooCommerce end */

		/* Magento start */
		if($this->_cms == 'magento') {
			return $this->_order->getGrandTotal();
		} /* Magento end */
 	}
 	
 	/**
	* 
	* Get shipping costs including tax
	* @return float
	*/
 	private function getOrderPriceShipping() {
 	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			return $this->_order->get_total_shipping();
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
			return $this->_order->getShippingAmount();
		} /* Magento end */
	}
 	
 	/**
	* 
	* Get the currency used for the order
	* @return string
	*/
 	private function getOrderPriceCurrency() {
 	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			return $this->_order->get_order_currency();
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
			return $this->_order->getOrderCurrencyCode();
		} /* Magento end */
 	}
 	
 	/**
	* 
	* Get the shipping address information
	* @return array
	*/
 	private function getShippingAddress() {
 	
 		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			return array(
				'receiverid'=> $this->_order->user_id,
				'company'	=> $this->_order->shipping_company,
				'name1' 	=> $this->_order->shipping_first_name .' '. $this->_order->shipping_last_name,
				'name2'		=> null,
				'address1'	=> $this->_order->shipping_address_1,
				'address2'	=> $this->_order->shipping_address_2,
				'city'		=> $this->_order->shipping_city,
				'zip'		=> $this->_order->shipping_postcode,
				'country'	=> $this->_order->shipping_country,
				'sms'		=> $this->_order->billing_phone, // Billing used
				'mail'		=> $this->_order->billing_email // Billing used
				);
		} /* WooCommerce end */
		
		/* Magento start */
		if($this->_cms == 'magento') {
			return array(
				'receiverid'=> $this->_order->getShippingAddress()->getId(),
				'company'	=> $this->_order->getShippingAddress()->getCompany(),
				'name1' 	=> $this->_order->getShippingAddress()->getFirstname() .' '. $this->_order->getShippingAddress()->getLastname(),
				'name2'		=> null,
				'address1'	=> $this->_order->getShippingAddress()->getStreet(1),
				'address2'	=> $this->_order->getShippingAddress()->getStreet(2),
				'city'		=> $this->_order->getShippingAddress()->getCity(),
				'zip'		=> $this->_order->getShippingAddress()->getPostcode(),
				'country'	=> $this->_order->getShippingAddress()->getCountry_id(),
				'sms'		=> $this->_order->getShippingAddress()->getTelephone(),
				'mail'		=> $this->_order->getShippingAddress()->getEmail()
				);
 		} /* Magento end */
 	}
 	
 	/**
	* 
	* Get the shipping address information
	* @return array
	*/
 	private function getBillingAddress() {
 	
 		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			return array(
				'receiverid'=> $this->_order->user_id,
				'company'	=> $this->_order->billing_company,
				'name1' 	=> $this->_order->billing_first_name .' '. $this->_order->billing_last_name,
				'name2'		=> null,
				'address1'	=> $this->_order->billing_address_1,
				'address2'	=> $this->_order->billing_address_2,
				'city'		=> $this->_order->billing_city,
				'zip'		=> $this->_order->billing_postcode,
				'country'	=> $this->_order->billing_country,
				'sms'		=> $this->_order->billing_phone, // Billing used
				'mail'		=> $this->_order->billing_email // Billing used
				);
		} /* WooCommerce end */
 	
		/* Magento start */
		if($this->_cms == 'magento') {
			return array(
				'receiverid'=> $this->_order->getBillingAddress()->getId(),
				'company'	=> $this->_order->getBillingAddress()->getCompany(),
				'name1' 	=> $this->_order->getBillingAddress()->getFirstname() .' '. $this->_order->getBillingAddress()->getLastname(),
				'name2'		=> null,
				'address1'	=> $this->_order->getBillingAddress()->getStreet(1),
				'address2'	=> $this->_order->getBillingAddress()->getStreet(2),
				'city'		=> $this->_order->getBillingAddress()->getCity(),
				'zip'		=> $this->_order->getBillingAddress()->getPostcode(),
				'country'	=> $this->_order->getBillingAddress()->getCountry_id(),
				'sms'		=> $this->_order->getBillingAddress()->getTelephone(),
				'mail'		=> $this->_order->getBillingAddress()->getEmail()
				);
		} /* Magento end */
 	}
 	
 	/**
	* 
	* Get pickup data for a SmartSend delivery point
	* @return array
	*/	
	private function getPickupDataSmartsend() {
	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$store_pickup = get_post_custom($order->id);
			$store_pickup = @unserialize($store_pickup['store_pickup'][0]);
			if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);
	
			if(!empty($store_pickup)){
			
				return array(
					'id' 		=> (isset($store_pickup['id']) ? $store_pickup['id'] : 0)."-".time()."-".rand(9999,10000),
					'agentno'	=> (isset($store_pickup['id']) ? $store_pickup['id'] : null),
					'agenttype'	=> ($this->getShippingCarrier() == 'postdanmark' ? 'PDK' : null),
					'company' 	=> (isset($store_pickup['company']) ? $store_pickup['company'] : null),
					'name1' 	=> null,
					'name2' 	=> null,
					'address1'	=> (isset($store_pickup['street']) ? $store_pickup['street'] : null),
					'address2' 	=> null,
					'city'		=> (isset($store_pickup['city']) ? $store_pickup['city'] : null),
					'zip'		=> (isset($store_pickup['zip']) ? $store_pickup['zip'] : null),
					'country'	=> (isset($store_pickup['country']) ? $store_pickup['country'] : null),
					'sms' 		=> null,
					'mail' 		=> null,
					);

			} else {
				return null;
			}
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
	
			$carrier = $this->formatCarrier($this->getShippingCarrier(),1);
			switch ($carrier) {
				case 'postdanmark':
					$pickupModel = Mage::getModel('logistics/postdanmark');
					break;
				case 'posten':
					$pickupModel = Mage::getModel('logistics/posten');
					break;
				case 'gls':
					$pickupModel = Mage::getModel('logistics/gls');
					break;
				case 'bring':
					$pickupModel = Mage::getModel('logistics/bring');
					break;
				default:
					//Change this code for each CMS system
					throw new Exception('Unable to get pickup data for Smart Send shipping method.');
			}
	
			$order_id = $this->_order->getId();	//order id
			$pickupData = $pickupModel->getCollection()->addFieldToFilter('order_id', $order_id)->getFirstItem();        //pickup data 

			if ($pickupData->getData()) {
			
				return array(
					'id' 		=> $pickupData->getPickUpId()."-".time()."-".rand(9999,10000),
					'agentno'	=> $pickupData->getPickUpId(),
					'agenttype'	=> ($this->getShippingCarrier() == 'postdanmark' ? 'PDK' : null),
					'company' 	=> $pickupData->getCompany(),
					'name1' 	=> null,
					'name2' 	=> null,
					'address1'	=> $pickupData->getStreet(),
					'address2' 	=> null,
					'city'		=> $pickupData->getCity(),
					'zip'		=> $pickupData->getZip(),
					'country'	=> $pickupData->getCountry(),
					'sms' 		=> null,
					'mail' 		=> null,
					);

			} else {
				return null;
			}
		} /* Magento end */
	}
	
	/**
	* 
	* Get the settings for Post Danmark
	* @return array
	*/
	private function getSettingsPostdanmark() {
		
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$postdanmark = new Smartsend_Logistics_PostDanmark();
			return array(
				'notemail'			=> ($postdanmark->get_option( 'notemail','yes') == 'yes' ? true : null),
				'notesms'			=> ($postdanmark->get_option( 'notesms','yes') == 'yes' ? true : null),
				'prenote'			=> ($postdanmark->get_option( 'prenote','yes') == 'yes' ? true : false),
				'prenote_from'		=> $postdanmark->get_option( 'prenote_sender',''),
				'prenote_receiver'	=> $postdanmark->get_option( 'prenote_receiver','user'),
				'prenote_message'	=> $postdanmark->get_option( 'prenote_message',''),
				'format'			=> $postdanmark->get_option( 'format','pdf'),
				'quickid'			=> $postdanmark->get_option( 'quickid','1'),
				'waybillid'			=> $postdanmark->get_option( 'waybillid',''),
				'return'			=> $postdanmark->get_option( 'return',''),
				);
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
			return array(
				'notemail'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/notemail'),
				'notesms'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/notesms'),
				'prenote'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/prenote'),
				'prenote_from'		=> Mage::getStoreConfig('carriers/smartsendpostdanmark/prenote_sender'),
				'prenote_receiver'	=> Mage::getStoreConfig('carriers/smartsendpostdanmark/prenote_receiver'),
				'prenote_message'	=> Mage::getStoreConfig('carriers/smartsendpostdanmark/prenote_message'),
				'format'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/format'),
				'quickid'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/quickid'),
				'waybillid'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/waybillid'),
				'return'			=> Mage::getStoreConfig('carriers/smartsendpostdanmark/return'),
				);
		} /* Magento end */
	}
	
	/**
	* 
	* Get the settings for Posten
	* @return array
	*/
	private function getSettingsPosten() {
	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$posten = new Smartsend_Logistics_Posten();
			return array(
				'notemail'			=> ($posten->get_option( 'notemail','yes') == 'yes' ? true : null),
				'notesms'			=> ($posten->get_option( 'notesms','yes') == 'yes' ? true : null),
				'prenote'			=> ($posten->get_option( 'prenote','yes') == 'yes' ? true : false),
				'prenote_from'		=> $posten->get_option( 'prenote_sender',''),
				'prenote_receiver'	=> $posten->get_option( 'prenote_receiver','user'),
				'prenote_message'	=> $posten->get_option( 'prenote_message',''),
				'format'			=> $posten->get_option( 'format','pdf'),
				'quickid'			=> $posten->get_option( 'quickid','1'),
				'waybillid'			=> $posten->get_option( 'waybillid',''),
				'return'			=> $posten->get_option( 'return',''),
				);
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
			return array(
				'notemail'			=> Mage::getStoreConfig('carriers/smartsendposten/notemail'),
				'notesms'			=> Mage::getStoreConfig('carriers/smartsendposten/notesms'),
				'prenote'			=> Mage::getStoreConfig('carriers/smartsendposten/prenote'),
				'prenote_from'		=> Mage::getStoreConfig('carriers/smartsendposten/prenote_sender'),
				'prenote_receiver'	=> Mage::getStoreConfig('carriers/smartsendposten/prenote_receiver'),
				'prenote_message'	=> Mage::getStoreConfig('carriers/smartsendposten/prenote_message'),
				'format'			=> Mage::getStoreConfig('carriers/smartsendposten/format'),
				'quickid'			=> Mage::getStoreConfig('carriers/smartsendposten/quickid'),
				'waybillid'			=> Mage::getStoreConfig('carriers/smartsendposten/waybillid'),
				'return'			=> Mage::getStoreConfig('carriers/smartsendposten/return'),
				);
		} /* Magento end */
	}
	
	/**
	* 
	* Get the settings for GLS
	* @return array
	*/
	private function getSettingsGls() {
	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$gls = new Smartsend_Logistics_GLS();
			return array(
				'notemail'			=> ($gls->get_option( 'notemail','yes') == 'yes' ? true : null),
				'notesms'			=> ($gls->get_option( 'notesms','yes') == 'yes' ? true : null),
				'prenote'			=> null,
				'prenote_from'		=> null,
				'prenote_receiver'	=> null,
				'prenote_message'	=> null,
				'format'			=> null,
				'quickid'			=> null,
				'waybillid'			=> null,
				'return'			=> $gls->get_option( 'return',''),
				);
		} /* WooCommerce end */
			
		/* Magento start */
		if($this->_cms == 'magento') {
			return array(
				'notemail'			=> Mage::getStoreConfig('carriers/smartsendgls/notemail'),
				'notesms'			=> Mage::getStoreConfig('carriers/smartsendgls/notesms'),
				'prenote'			=> null,
				'prenote_from'		=> null,
				'prenote_receiver'	=> null,
				'prenote_message'	=> null,
				'format'			=> null,
				'quickid'			=> null,
				'waybillid'			=> null,
				'return'			=> Mage::getStoreConfig('carriers/smartsendgls/return'),
				);
		} /* Magento end */
	}
	
	/**
	* 
	* Get the settings for Bring
	* @return array
	*/
	private function getSettingsBring() {
	
		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$bring = new Smartsend_Logistics_Bring();
			return array(
				'notemail'			=> ($bring->get_option( 'notemail','yes') == 'yes' ? true : null),
				'notesms'			=> ($bring->get_option( 'notesms','yes') == 'yes' ? true : null),
				'prenote'			=> null,
				'prenote_from'		=> null,
				'prenote_receiver'	=> null,
				'prenote_message'	=> null,
				'format'			=> null,
				'quickid'			=> null,
				'waybillid'			=> null,
				'return'			=> $bring->get_option( 'return',''),
				);
		} /* WooCommerce end */
			
		/* Magento start */
		if($this->_cms == 'magento') {
			return array(
				'notemail'			=> Mage::getStoreConfig('carriers/smartsendbring/notemail'),
				'notesms'			=> Mage::getStoreConfig('carriers/smartsendbring/notesms'),
				'prenote'			=> null,
				'prenote_from'		=> null,
				'prenote_receiver'	=> null,
				'prenote_message'	=> null,
				'format'			=> null,
				'quickid'			=> null,
				'waybillid'			=> null,
				'return'			=> Mage::getStoreConfig('carriers/smartsendbring/return'),
				);
		} /* Magento end */
	}
	
	/* CMS FUNCTIONS ABOUT PARCELS AND SHIPMENTS */

		/**
		* 
		* Get the Track&Trace code for a given shipment
		* @return string
		*/
		private function getShipmentTrace($shipment) {

			/* WooCommerce start */
			if($this->_cms == 'woocommerce') {
				return false;
			} /* WooCommerce end */
	
			/* Magento start */
			if($this->_cms == 'magento') {
				$tracknums = array();
				foreach($shipment->getAllTracks() as $tracknum) {
					$tracknums[]=$tracknum->getNumber();
				}
	
				if(empty($tracknums)) {
					return false;
				} else {
					return true;
				}
			} /* Magento end */
		}
		
		/**
		* 
		* Get the weight (in kg) of a given shipment
		* @return float
		*/
		private function getShipmentWeight($shipment) {
		
			/* WooCommerce start */
			if($this->_cms == 'woocommerce') {
				$weight = 0;
				foreach($shipment as $eachShipmentItem) {
					$itemWeight = $eachShipmentItem['unitweight'];
					$itemQty    = $eachShipmentItem['quantity'];
					$rowWeight  = $itemWeight*$itemQty;
		
					$weight = $weight + $rowWeight;
				}
			} /* WooCommerce end */
		
			/* Magento start */
			if($this->_cms == 'magento') {
				$weight = 0;
				foreach($shipment->getAllItems() as $eachShipmentItem) {
					$itemWeight = $eachShipmentItem->getWeight();
					$itemQty    = $eachShipmentItem->getQty();
					$rowWeight  = $itemWeight*$itemQty;
		
					$weight = $weight + $rowWeight;
				}
			} /* Magento end */
			
			/* All */
			if($weight > 0) {
				return $weight;
			} else {
				return null;
			}
		}

		/**
		* 
		* Get the shipments for the order if any
		* @return array
		*/
		private function getShipments() {

			/* WooCommerce start */
			if($this->_cms == 'woocommerce') {
				return null;
			} /* WooCommerce end */

			/* Magento start */
			if($this->_cms == 'magento') {
				if( $this->_order->hasShipments() ) {
					return $this->_order->getShipmentsCollection();
				} else {
					return null;
				}
			} /* Magento end */
		}
		
		/**
		* 
		* Add a shipment to the request
		*/
		private function addShipment($shipment) {
		
			/* WooCommerce start */
			if($this->_cms == 'woocommerce') {
				$this->_parcels[] = $shipment;
			} /* WooCommerce end */

			/* Magento start */
			if($this->_cms == 'magento') {
				$parcel = array(
					'shipdate'	=> null,
					'reference' => $shipment->getId(),
					'weight'	=> $this->getShipmentWeight($shipment),
					'height'	=> null,
					'width'		=> null,
					'length'	=> null,
					'size'		=> null,
					'freetext1'	=> null,
					'freetext2'	=> null,
					'freetext3'	=> null,
					'items' 	=> array(),
					);
		
				$ordered_items = $shipment->getAllItems();	
				foreach($ordered_items as $item) {
					$parcel['items'][] = $this->addItem($item);
				}
			
				$this->_parcels[] = $parcel;
			} /* Magento end */
		}

		/**
		* 
		* Format an item to be added to a parcel
		* @return array
		*/
		private function addItem($item) {

			/* Magento start */
			if($this->_cms == 'magento') {
				return array(
					'sku'		=> $item->getSku(),
					'title'		=> $item->getName(),
					'quantity'	=> $item->getQty(),
					'unitweight'=> $item->getWeight(),
					'unitprice'	=> $item->getPrice(),
					'currency'	=> Mage::app()->getStore()->getCurrentCurrencyCode()
					);
				  //  $item->getItemId(); //product id
			} /* Magento end */
		}

		/**
		* 
		* Get the unshipped items of the order
		* @return array
		*/
		private function getUnshippedItems() {

		/* WooCommerce start */
		if($this->_cms == 'woocommerce') {
			$ordered_items = $this->_order->get_items();
			foreach($ordered_items as $item) {
				$_product = $this->_order->get_product_from_item( $item );
				if ( ! $_product->is_virtual() ) {
					$weight = $_product->get_weight();
				} else {
					$weight = null;
				}
		
				$items[] =  array(
					'sku'		=> ($_product->get_sku() != '' ? $_product->get_sku() : null),
					'title'		=> ($_product->get_title() != '' ? $_product->get_title() : null),
					'quantity'	=> $item['qty'],
					'unitweight'=> ($weight != '' ? $weight : null),
					'unitprice'	=> $_product->get_price(),
					'currency'	=> get_woocommerce_currency()
					);
			}
		} /* WooCommerce end */
	
		/* Magento start */
		if($this->_cms == 'magento') {
			$items = array();
			foreach($this->_order->getAllItems() as $eachOrderItem){
				$Itemqty = 0;
				$Itemqty = $eachOrderItem->getQtyOrdered()
						- $eachOrderItem->getQtyShipped()
						- $eachOrderItem->getQtyRefunded()
						- $eachOrderItem->getQtyCanceled();
				if($Itemqty > 0) {
					$items[$eachOrderItem->getId()] = $Itemqty;
				}
			}
		} /* Magento end */
	
		/* All */
			if(!empty($items)) {
				return $items;
			} else {
				return null;
			}
	
		}

		/**
		* 
		* Create a parcel containing all unshipped items.
		* Add the parcel to the request.
		*/
		private function createShipment() {

		/* WooCommerce start */
			if($this->_cms == 'woocommerce') {
				//create and object, $shipment, with all items
				if ( sizeof( $this->_order->get_items() ) > 0 ) {
					$shipment = array(
						'shipdate'	=> null,
						'reference' => $this->_order->id,
						'weight'	=> $this->getShipmentWeight($this->getUnshippedItems()),
						'height'	=> null,
						'width'		=> null,
						'length'	=> null,
						'size'		=> null,
						'freetext1'	=> null,
						'freetext2'	=> null,
						'freetext3'	=> null,
						'items' 	=> $this->getUnshippedItems()
						);
				} else {
					//Order has no shipments and cannot be shipped
					throw new Exception(__("No items that could be shipped"));
				}
			} /* WooCommerce end */

		/* Magento start */
		if($this->_cms == 'magento') {
			$order = $this->_order;
			$qty = $this->getUnshippedItems();
 
			/* check order shipment is prossiable or not */
 
			$email = false;
			$includeComment = false;
			$comment = "";
 
			if ($order->canShip()) {
				// @var $shipment Mage_Sales_Model_Order_Shipment
				// prepare to create shipment
				$shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($qty);
				if ($shipment) {
					$shipment->register();
					
					//Add a comment. Second parameter is whether or not to email the comment to the user
					$shipment->addComment($comment, $email && $includeComment);
					
					// Set the order status as 'Processing'
					$order->setIsInProcess($email);
					$order->addStatusHistoryComment('Label generated by Smart Send Logistics.', false);
					
					try {
						$transactionSave = Mage::getModel('core/resource_transaction')
								->addObject($shipment)
								->addObject($order)
								->save();
						
						//Email the customer that the order is sent
						$shipment->sendEmail($email, ($includeComment ? $comment : ''));
						
						//Set order status as complete
						//$order->setData('state', Mage_Sales_Model_Order::STATE_COMPLETE);
    					//$order->setData('status', Mage_Sales_Model_Order::STATE_COMPLETE);
 						//$order->save();
 						
						//var_dump($qty); exit();
					} catch (Mage_Core_Exception $e) {
						throw new Exception(Mage::helper('logistics')->__("Error while creating parcel: ".$e));
					}
				}
			}

		} /* Magento end */

		/* All */
			if ($shipment) {
				//Lastly add the shipment to the order array.
				$this->addShipment($shipment);
			}
	
		}

}