<?php

/**
 * Smartsend_Logistics Order class
 *
 * Create order objects that is included in the final Smart Send label API callout.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@smartsend.dk so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the plugin to newer
 * versions in the future. If you wish to customize the plugin for your
 * needs please refer to http://www.smartsend.dk
 *
 * @class		/api/class.order.php
 * @folder		class_order.php
 * @category	Smart Send
 * @package		Smartsend_Logistics
 * @author 		Smart Send ApS
 * @url			http://smartsend.dk/
 * @copyright	Copyright (c) Smart Send ApS (http://www.smartsend.dk)
 * @license		http://smartsend.dk/license
 * @since		Class available since Release 7.1.0
 * @version		Release: 7.1.3
 *
 *	// Overall functions
 *	public function _construct()
 *	public function setOrderObject($order_object)
 *	public function setReturn($return=false)
 *	public function getFinalOrder()
 *
 *	// Logical functions
 *	public function isSmartsendOrVConnect()
 *	public function isPickup()
 *	public function isReturn()
 *	public function isSmartsend()
 *	public function isVconnect()
 *	public function isPickupSmartsend()
 *	public function isPickupVconnect()
 *	public function isSmartDelivery()
 *
 *	// Shipping functions
 *	protected function getShippingCarrierAndMethod()
 *	protected function renameShippingCarrier($shipping_string)
 *	protected function renameShippingMethod($shipping_string)
 *	public function getShippingCarrier($format=0)
 *	public function getShippingMethod()
 *	
 *	// Order set functions
 *	public function setInfo()
 *	public function setReceiver()
 *	public function setSender()
 *	public function setAgent()
 *	public function setService()
 *	public function setParcels()
 *
 *	// Order get functions
 *	public function getPickupId()
 *	public function getPickupData()
 *	public function getSettingsCarrier()
 *	public function getWaybill($string,$country)
 *	protected function getFreetext()
 *
 *
 *	// This class is called by using the code:
 *		$order = new Smartsend_Logistics_Order();
 *		$order->setOrderObject($order_object);
 *		$order->setReturn(true);
 *		try{
 *			$order->setInfo();
 *			$order->setReceiver();
 *			$order->setSender();
 *			$order->setAgent();
 *			$order->setService();
 *			$order->setParcels();
 *	
 *			//All done. Add to request.
 *			$request_array[] = $order->getFinalOrder();
 *		} catch (Exception $e) {
 *			echo 'Caught exception: ',  $e->getMessage(), "\n";
 *		}
 *
*/

require_once( WP_PLUGIN_DIR . '/woocommerce/includes/abstracts/abstract-wc-order.php' );
require_once( WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-order.php' );

require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.bring.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.gls.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.postdanmark.php' );
require_once( WP_PLUGIN_DIR . '/smart-send-logistics/class.smartsend.posten.php' );

class Smartsend_Logistics_Order {

	protected $_order;
	protected $_info;
	protected $_receiver;
	protected $_sender;
	protected $_agent;
	protected $_service;
	protected $_parcels = array();
	protected $_return = false;
	
	protected $_test = false;

/*****************************************************************************************
 * Overall functions
 ****************************************************************************************/
	
	/**
	* 
	* Set the order object
	* @param object $order_object order object
	*/
	public function setOrderObject($order_object) {
		$this->_order = $order_object;
	}
	
	/**
	* 
	* Set wheter or not the label is a return label
	* @param boolean $return wheter or not the label is a return label
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
 * Logical functions: Functions to return true/false for different statements
 ****************************************************************************************/

	/**
	* 
	* Check if order is placed by a SmartSend or a vConnect shipping method
	* @return boolean
	*/
	public function isSmartsendOrVConnect() {

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
	public function isPickup() {

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
	public function isReturn()	{
		return $this->_return;
	}

	/**
	* 
	* Check if order is placed by a SmartSend shipping method
	* @return boolean
	*/
	public function isSmartsend() {
		
		try {
			$method = strtolower($this->getShippingId());
	
			//Check if shipping methode contains 'smartsend'
			if(strpos($method, 'smartsend') !== false) {
				return true;
			} else {
				return false;
			}
		} catch(Exception $e) {
			return false;
		}
	
	}

	/**
	* 
	* Check if order is placed by a vConnect shipping method
	* @return boolean
	*/
	public function isVconnect() {
	
		try{
			$method = strtolower($this->getShippingId());
	
			//Check if shipping methode contains 'vconnect' or 'vc'
			if(strpos($method, 'vconnect') !== false) {
				return true;
			} elseif(strpos($method, 'vc') !== false) {
				return true;
			} else {
				return false;
			}
		} catch(Exception $e) {
			return false;
		}
	
	}
	
	/**
	* 
	* Check if order is a pickup shipping method from SmartSend
	* @return boolean
	*/	
	public function isPickupSmartsend() {
		try{
			if($this->isSmartsend() == true) {
				$method = $this->getShippingMethod();
	
				//Check if shipping methode ends with 'pickup'
				if(substr($method, -strlen('pickup')) === 'pickup') {
					return true;
				} else {
					return false;
				}
			} else {
				return false;
			}
		} catch(Exception $e) {
			return false;
		}
	
	}

	/**
	* 
	* Check if order is a pickup shipping method from vConnect
	* @return boolean
	*/	
	public function isPickupVconnect() {
	
		try{
			if($this->isVconnect() == true) {
				$method = $this->getShippingMethod();
	
				//Check if shipping methode ends with 'pickup'
				if(substr($method, -strlen('pickup')) === 'pickup') {
					return true;
				} else {
					return false;
				}
			} else {
				return false;
			}
		
		} catch(Exception $e) {
			return false;
		}
	}
	
	/**
	* 
	* Check if Smart Delivery is active
	* @return boolean
	*/	
	public function isSmartDelivery() {
	
		false;
		
	}
	
/*****************************************************************************************
 * Shipping functions
 ****************************************************************************************/
	
	/**
	* 
	* Get shipping carrier and method
	*	0: @string shipping carrier. Example: 'postdanmark'
	*	1: @string shipping method. Example: 'private'
	* @return array
	*/
	protected function getShippingCarrierAndMethod() {
		
		// Retreive unique shipping id. Example: 'postdanmark_private'
		$shipping_string = $this->getShippingId();
		
		$carrier = $this->renameShippingCarrier($shipping_string);
		$method = $this->renameShippingMethod($shipping_string);
		
		if($this->isReturn() == true) {
			switch ($carrier) {
				case 'postdanmark':
					$settings = $this->getSettingsPostdanmark();
					$shipping_string = $settings['return'];
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
					throw new Exception($this->_errors[2301]);
			}
			
			// Check if the carrier must be changed
			try{
				$carrier = $this->renameShippingCarrier($shipping_string);
			} catch(Exception $e) {
				// Do nothing if no return shipping carrier is found
			}
			
			// Check if the method must be changed
			try{
				$new_method = $this->renameShippingMethod($shipping_string);
				if(isset($new_method) && $new_method != '' && $new_method != 'auto') {
					$method = $new_method;
				}
			} catch(Exception $e) {
				// Do nothing if no return shipping method is found
			}
		}
		
		if(!isset($carrier) || $carrier == '') {
			throw new Exception($this->_errors[2302]);
		} elseif(!isset($method) || $method == '') {
			throw new Exception($this->_errors[2303]);
		} else {
			return array($carrier,$method);
		}
		
	}
	
	/**
	* 
	* Find the shipping carrier from a string and return as lower case single word
	* @param string $carrier to be determined. Example: 'smartsendpostdanmark_private' would give 'postdanmark'
	* @return array
	*/
	protected function renameShippingCarrier($shipping_string) {
		
		$shipping_string = strtolower($shipping_string);
	
	// Smart Send shipping methods
		if(strpos($shipping_string,'smartsendbring') !== false || strpos($shipping_string,'smartsend_bring') !== false) {
			$carrier = 'bring';
		} elseif(strpos($shipping_string,'smartsendgls') !== false || strpos($shipping_string,'smartsend_gls') !== false) {
			$carrier = 'gls';
		} elseif(strpos($shipping_string,'smartsendpostdanmark') !== false || strpos($shipping_string,'smartsend_postdanmark') !== false) {
			$carrier = 'postdanmark';
		} elseif(strpos($shipping_string,'smartsendposten') !== false || strpos($shipping_string,'smartsend_posten') !== false) {
			$carrier = 'posten';
			
	// vConnect All-in-1 module shipping methods
		} elseif(strpos($shipping_string,'vconnect_postnord_dk') !== false) {
			$carrier = 'postdanmark';
		} elseif(strpos($shipping_string,'vconnect_postnord_se') !== false) {
			$carrier = 'posten';
		} elseif(strpos($shipping_string,'vconnect_postnord_no') !== false) {
			$carrier = 'postnordnorway';
		} elseif(strpos($shipping_string,'vconnect_postnord_fi') !== false) {
			$carrier = 'postnordfinland';
			
	// Old vConnect shipping methods
		} elseif(strpos($shipping_string,'vconnect_postdanmark') !== false || strpos($shipping_string,'vc_postdanmark') !== false || strpos($shipping_string,'vc_allinone_vconnectpostdanmark') !== false) {
			$carrier = 'postdanmark';
		} elseif(strpos($shipping_string,'vconnect_posten') !== false || strpos($shipping_string,'vc_posten') !== false || strpos($shipping_string,'vc_allinone_vconnectposten') !== false) {
			$carrier = 'posten';
		} elseif(strpos($shipping_string,'vconnect_postnord') !== false || strpos($shipping_string,'vc_postnord') !== false || strpos($shipping_string,'vc_allinone_vconnectpostnord') !== false) {
			$carrier = 'postdanmark';
		} elseif(strpos($shipping_string,'vconnect_gls') !== false || strpos($shipping_string,'vc_gls') !== false) {
			$carrier = 'gls';
		} elseif(strpos($shipping_string,'vconnect_bring') !== false || strpos($shipping_string,'vc_bring') === false) {
			$carrier = 'bring';
		} elseif(strpos($shipping_string,'vconnect_pdkalpha') !== false) {
			$carrier = 'postdanmark';
			
	// If the shipping method is unknown throw an error
		} else {
			throw new Exception( $this->_errors[2305] .': '. $shipping_string );
		}
	
		return $carrier;
	}
	
	/**
	* 
	* Find the shipping method from a string and return as lower case single word
	* @param string $carrier to be determined. Example: 'smartsendpostdanmark_private' would give 'private'
	* @return array
	*/
	protected function renameShippingMethod($shipping_string) {
		
		$shipping_string = strtolower($shipping_string);

		if(substr($shipping_string, -strlen('pickup')) === 'pickup') {
			$method = 'pickup';
		} elseif(substr($shipping_string, -strlen('private')) === 'private') {
			$method = 'private';
		} elseif(substr($shipping_string, -strlen('privatehome')) === 'privatehome') {
			$method = 'privatehome';
		} elseif(substr($shipping_string, -strlen('commercial')) === 'commercial') {
			$method = 'commercial';
		} elseif(substr($shipping_string, -strlen('express')) === 'express') {
			$method = 'express';
		} elseif(substr($shipping_string, -strlen('privatesamsending')) === 'privatesamsending') {
			$method = 'privatesamsending';
		} elseif(substr($shipping_string, -strlen('privatepriority')) === 'privatepriority') {
			$method = 'privatepriority';
		} elseif(substr($shipping_string, -strlen('privateeconomy')) === 'privateeconomy') {
			$method = 'privateeconomy';
		} elseif(substr($shipping_string, -strlen('lastmile')) === 'lastmile') {
			$method = 'lastmile';
		} elseif(substr($shipping_string, -strlen('businesspriority')) === 'businesspriority') {
			$method = 'businesspriority';
		} elseif(substr($shipping_string, -strlen('dpdclassic')) === 'dpdclassic') {
			$method = 'dpdclassic';
		} elseif(substr($shipping_string, -strlen('dpdguarantee')) === 'dpdguarantee') {
			$method = 'dpdguarantee';
		} elseif(substr($shipping_string, -strlen('valuemail')) === 'valuemail') {
			$method = 'valuemail';
		} elseif(substr($shipping_string, -strlen('valuemailfirstclass')) === 'valuemailfirstclass') {
			$method = 'valuemailfirstclass';
		} elseif(substr($shipping_string, -strlen('valuemaileconomy')) === 'valuemaileconomy') {
			$method = 'valuemaileconomy';
		} elseif(substr($shipping_string, -strlen('maximail')) === 'maximail') {
			$method = 'maximail';
		} elseif(substr($shipping_string, -strlen('miniparcel')) === 'miniparcel') {
			$method = 'miniparcel';
		} elseif(substr($shipping_string, -strlen('private_bulksplit')) === 'private_bulksplit') {
			$method = 'private_bulksplit';
		} elseif(substr($shipping_string, -strlen('privatehome_bulksplit')) === 'privatehome_bulksplit') {
			$method = 'privatehome_bulksplit';
		} elseif(substr($shipping_string, -strlen('commercial_bulksplit')) === 'commercial_bulksplit') {
			$method = 'commercial_bulksplit';
		} elseif(substr($shipping_string, -strlen('bestway')) === 'bestway') {
			$method = 'pickup';
		} elseif(substr($shipping_string, -strlen('postdanmark')) === 'postdanmark') {
			$method = 'pickup';
		} elseif(substr($shipping_string, -strlen('posten')) === 'posten') {
			$method = 'pickup';
		} elseif(substr($shipping_string, -strlen('postnord')) === 'postnord') {
			$method = 'pickup';
		} elseif(substr($shipping_string, -strlen('bring')) === 'bring') {
			$method = 'pickup';
		} elseif(substr($shipping_string, -strlen('gls')) === 'gls') {
			$method = 'pickup';
	// Support for vConnect shipping method 'pdkalpha'
		} elseif(strpos($shipping_string,'vconnect_pdkalpha') !== false) {
			$method = 'lastmile';
		} else {
	// If the shipping method is unknown throw an error
			throw new Exception( $this->_errors[2306] .': '. $shipping_string );
		}
	
		return $method;
	}
	
	/**
	* 
	* Get shipping carrier
	* Format 0: lowercase single word (default). Example: 'postdanmark'
	* Format 1: Capilized user friendly output. Example: 'Post Danmark'
	* @param int $format defines the format of the shipping carrier
	* @return string
	*/
	public function getShippingCarrier($format=0) {
		$shipping_info = $this->getShippingCarrierAndMethod();
		
		if(isset($shipping_info[0]) && $shipping_info[0] != '') {
			$carrier_lowcase = strtolower($shipping_info[0]);
		} else {
			throw new Exception( $this->_errors[2307] );
		}
		
		switch ($carrier_lowcase) {
			case 'postdanmark':
				if($format == 0) {
					return 'postdanmark';
				} else {
					return 'Post Danmark';
				}
				break;
			case 'posten':
				if($format == 0) {
					return 'posten';
				} else {
					return 'Posten';
				}
				break;
			case 'gls':
				if($format == 0) {
					return 'gls';
				} else {
					return 'GLS';
				}
				break;
			case 'bring':
				if($format == 0) {
					return 'bring';
				} else {
					return 'Bring';
				}
				break;
			default:
				throw new Exception( $this->_errors[2308] );
		}
	
	}
	
	/**
	* 
	* Get shipping method
	* Example: 'pickup'
	* @return string
	*/
	public function getShippingMethod() {
		$shipping_info = $this->getShippingCarrierAndMethod();
		
		if(isset($shipping_info[1]) && $shipping_info[1] != '') {
			return $shipping_info[1];
		} else {
			//Change this code for each CMS system
			throw new Exception( $this->_errors[2309] );
		}
	}
 	

/*****************************************************************************************
 * Order set functions: Functions to set order parameters
 ****************************************************************************************/

	/**
	* 
	* Set the meta data for the order
	*/
	public function setInfo() {
	
		$carrier 	= $this->getShippingCarrier();
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
	
		if($this->isPickupVconnect() == true) {
			$shipping_address = $this->getShippingAddress();
			$service_point = str_replace(' ', '', $shipping_address['address2']); 	//remove spaces
			$service_point = strtolower($service_point); //Make lowercase
			if( strpos($service_point, strtolower('ServicePointID')) !== false || strpos($service_point, strtolower('Pakkeshop')) !== false ) {
				$this->_receiver = $this->getBillingAddress();
			} else {
				$this->_receiver = $this->getShippingAddress();
			}
		} else {
			$this->_receiver = $this->getShippingAddress();
		}
	
	}
	
	/**
	* 
	* Set the sender information
	*/
	public function setSender() {
	
		$carrier 	= $this->getShippingCarrier();
		
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
	public function setService() {
	
		$settings = $this->getSettingsCarrier();
		
		$this->_service = array(
			'notemail'				=> ($settings['notemail'] == 1 ? $this->_receiver['mail'] : null),
			'notesms'				=> ($settings['notesms'] == 1 ? $this->_receiver['sms'] : null),
			'prenote'				=> $settings['prenote'],
			'prenote_from'			=> $settings['prenote_from'],
			'prenote_receiver'		=> ($settings['prenote_receiver'] == '' ? $this->_receiver['mail'] : $settings['prenote_receiver']),
			'prenote_message'		=> ($settings['prenote_message'] != '' ? $settings['prenote_message'] : null),
			'flex'					=> ($this->getFlexDeliveryNote() ? true : null),
			'waybillid'				=> $this->getWaybill($settings['waybillid'],$this->_receiver['country']),
			'smartdelivery'			=> $this->isSmartDelivery(),
			'smartdelivery_start'	=> $this->getSmartDeliveryTimeStart(),
			'smartdelivery_end'		=> $this->getSmartDeliveryTimeEnd(),
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
				throw new Exception( $this->_errors[2401] );
			}
		} else {
			if($this->getUnshippedItems() != null) {
				$this->addParcelWithUnshippedItems();
			} else {
				throw new Exception( $this->_errors[2402] );
			}
		}
	
		if(empty($this->_parcels)) {
			throw new Exception( $this->_errors[2401] );
		}

	}
	
/*****************************************************************************************
 * Order get functions: Functions to get order parameters
 ****************************************************************************************/
 	
 	/**
	* 
	* Get pickup id of delivery point
	* @return string
	*/	
	public function getPickupId() {
	
		$pickupdata = $this->getPickupData();
		return (isset($pickupdata['id']) ? $pickupdata['id'] : null);
	
	}

	/**
	* 
	* Get pickup data for delivery point
	* @return array
	*/	
	public function getPickupData() {

		if($this->isPickupSmartsend() == true) {
			return $this->getPickupDataSmartsend();
		} elseif($this->isPickupVconnect() == true) {
			return $this->getPickupDataVconnect();
		} else {
			throw new Exception( $this->_errors[2501] );
		}

	}
 	
 	/**
	* 
	* Get the settings from the carrier that would be used if this is a normal label.
	* This is not nessesary the same as the actual carrier if one uses a different carrier
	* for return labels.
	* @return array
	*/
 	public function getSettingsCarrier() {
 	
 		$carrier = $this->getShippingCarrier();
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
	public function getWaybill($string,$country) {

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
	
	/**
	 *
	 * Function to return freetext that is displayed on the label
	 * @return string
	 */
	protected function getFreetext() {
		// If there is a flexdelivery note, return this
		if( $this->getFlexDeliveryNote() ) {
			return $this->getFlexDeliveryNote();
		}
		// If the setting is to include the order comment, include a trimmed comment
		elseif( $this->getSettingIncludeOrderComment() ) {
			return $this->getCustomerComment();
		}
		// Otherwise return an empty string
		else {
			return null;
		}
	}

}