<?php

/**
 * Smartsend_Logistics Order WooCommerce class
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
 * @class		Smartsend_Logistics_Order_Woocommerce
 * @folder		/api/class.order.woocommerce.php
 * @category	Smart Send
 * @package		Smartsend_Logistics
 * @author 		Smart Send ApS
 * @url			http://smartsend.dk/
 * @copyright	Copyright (c) Smart Send ApS (http://www.smartsend.dk)
 * @license		http://smartsend.dk/license
 * @since		Class available since Release 7.1.0
 * @version		Release: 7.1.3.2
 *
 *	// Order
 *	public function getShippingId()
 *	public function getOrderId()
 *	public function getOrderReference()
 *	public function getOrderPriceTotal()
 *	public function getOrderPriceShipping()
 *	public function getOrderPriceCurrency()
 *	public function getCustomerComment()
 *	public function getShippingAddress()
 *	public function getBillingAddress()
 *	public function getPickupDataSmartsend()
 *	public function getPickupDataVconnect()
 *	public function getFlexDeliveryNote()
 *	
 *	// Settings
 *	public function getSettingsPostdanmark()
 *	public function getSettingsPosten()
 *	public function getSettingsGls()
 *	public function getSettingsBring()
 *	protected function getSettingIncludeOrderComment
 *
 *	// Shipments
 *	public function getShipments()
 *	public function getShipmentTrace($shipment)
 *	public function getShipmentWeight($shipment)
 *	protected function getUnshippedItems()
 *	public function createShipment($tracking_number)
 *	protected function addShipment($shipment)
 *	protected function addParcelWithUnshippedItems()
 *	protected function addItem($item)
 *	
 */
 
class Smartsend_Logistics_Order_Woocommerce extends Smartsend_Logistics_Order {

	protected $_errors;
		
	public function __construct() {
		$this->_errors = array(
			// Shipping
			2301	=>	__('Unable to determine the shipping method used for return parcels','smart-send-logistics'),
			2302	=>	__('Unable to determine the shipping carrier','smart-send-logistics'),
			2303	=>	__('Unable to determine the shipping method','smart-send-logistics'),
			2304	=>	__('Unable to determine carrier for pickup shipping method','smart-send-logistics'),
			2305	=>	__('Unsupported carrier','smart-send-logistics'),
			2306	=>	__('Unable to determine shipping method for carrier','smart-send-logistics'),
			2307	=>	__('Unable to determine shipping carrier','smart-send-logistics'),
			2308	=>	__('Unknown shipping carrier','smart-send-logistics'),
			2309	=>	__('Unable to determine shipping method','smart-send-logistics'),
			// Order set
			2401	=>	__('All packages have been shipped. No parcels without trace code exists. Remove existing tracecodes to re-generate labels.','smart-send-logistics'),
			2402	=>	__('No items to ship','smart-send-logistics'),
			2403	=>	__('No parcels to ship','smart-send-logistics'),
			// Order get
			2501	=>	__('Trying to access pickup data for an order that is not a pickup point order','smart-send-logistics')
		);
	}

/*****************************************************************************************
 * Order
 ****************************************************************************************/

	/**
	* 
	* Get shipping name/id
	* @return string
	*/
	public function getShippingId() {
	
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
	
		if(strpos($shipMethod_id, 'free_shipping') !== false) {
			$shipMethod_id = get_option( 'smartsend_logistics_wc_shipping_free_shipping','free_shipping');
		}
		
		if(strpos($shipMethod_id, 'smartsend') !== 0) {
			$string_start = strpos($shipMethod_id, 'smartsend');
			$shipMethod_id = substr($shipMethod_id, $string_start);
		} elseif(strpos($shipMethod_id, 'vconnect') !== 0) {
			$string_start = strpos($shipMethod_id, 'vconnect');
			$shipMethod_id = substr($shipMethod_id, $string_start);
		}
		
		//If id is of the instance type like: smartsend_postdanmark_pickup:7
		//Then move the id to after smartsend like: 7_smartsend_postdanmark_pickup
		$shipping_method_splitted = explode(':',$shipMethod_id);
		if(isset($shipping_method_splitted[1]) && $shipping_method_splitted[1] != '') {
			$shipMethod_id = $shipping_method_splitted[1] . '_' . $shipping_method_splitted[0];
		}
		
		return $shipMethod_id; //return unique id of shipping method

	}
 
 	/**
	* 
	* Get the order id (SQL id)
	* @return string
	*/
 	public function getOrderId() {
 	
		return $this->_order->get_id();
		
 	}
 	
 	/**
	* 
	* Get the order refernce (the one the customer sees)
	* @return string
	*/
 	public function getOrderReference() {
 	
 		return ltrim($this->_order->get_order_number(), '#');
 	
 	}
 	
 	/**
	* 
	* Get total price of order including tax
	* @return float
	*/
 	public function getOrderPriceTotal() {
 	
		return $this->_order->get_total();
		
 	}
 	
 	/**
	* 
	* Get shipping costs including tax
	* @return float
	*/
 	public function getOrderPriceShipping() {
 	
		return $this->_order->get_total_shipping();
		
	}
 	
 	/**
	* 
	* Get the currency used for the order
	* @return string
	*/
 	public function getOrderPriceCurrency() {
 	
		return $this->_order->get_currency();
		
 	}
 	
 	/**
	* 
	* Get the comment that the user provided during checkout
	* @return string
	*/
 	public function getCustomerComment() {
 	
		$comment = $this->_order->customer_message;
		
		if(isset($comment) && $comment != '') {
			return $comment;
		} else {
			return null;
		}
 	}
 	
 	/**
	* 
	* Get the shipping address information
	* @return array
	*/
 	public function getShippingAddress() {
 	
		return array(
			'receiverid'=> ($this->_order->user_id != '' ? $this->_order->user_id : 'guest-'.rand(100000,999999)),
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
			
 	}
 	
 	/**
	* 
	* Get the shipping address information
	* @return array
	*/
 	public function getBillingAddress() {
 	
		return array(
			'receiverid'=> ($this->_order->user_id != '' ? $this->_order->user_id : 'guest-'.rand(100000,999999)),
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
				
 	}
 	
 	/**
	* 
	* Get pickup data for a SmartSend delivery point
	* @return array
	*/	
	public function getPickupDataSmartsend() {
	
		$store_pickup = get_post_custom( $this->_order->get_id() );
		
		if(isset($store_pickup['store_pickup'][0])) {
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
		} else {
			return null;
		}
		
	}
	
	/**
	* 
	* Get pickup data for a vConnect delivery point
	* @return array
	*/	
	public function getPickupDataVconnect() {
		
		$store_pickup = get_post_custom( $this->_order->get_id() );
		
		if(isset($store_pickup['_service_point_id'][0]) && $store_pickup['_service_point_id'][0] != '') {
		
			return array(
				'id' 		=> (isset($store_pickup['_service_point_id'][0]) ? $store_pickup['_service_point_id'][0] : 0)."-".time()."-".rand(9999,10000),
				'agentno'	=> (isset($store_pickup['_service_point_id'][0]) ? $store_pickup['_service_point_id'][0] : null),
				'agenttype'	=> ($this->getShippingCarrier() == 'postdanmark' ? 'PDK' : null),
				'company' 	=> (isset($store_pickup['_service_point_id_name'][0]) ? $store_pickup['_service_point_id_name'][0] : null),
				'name1' 	=> null,
				'name2' 	=> null,
				'address1'	=> (isset($store_pickup['_service_point_id_address'][0]) ? $store_pickup['_service_point_id_address'][0] : null),
				'address2' 	=> null,
				'city'		=> (isset($store_pickup['_service_point_id_city'][0]) ? $store_pickup['_service_point_id_city'][0] : null),
				'zip'		=> (isset($store_pickup['_service_point_id_postcode'][0]) ? $store_pickup['_service_point_id_postcode'][0] : null),
				'country'	=> (isset($store_pickup['_service_point_id_country'][0]) ? $store_pickup['_service_point_id_country'][0] : null),
				'sms' 		=> null,
				'mail' 		=> null,
				);

		} else {
			$billing_address = $this->getShippingAddress();

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
		
	}
	
	/**
	* 
	* Get the Flex delivery comment (where to place the parcel) from Mysql
	* @return string / null
	*/
	public function getFlexDeliveryNote() {
		if( $this->isSmartsend() ) {
			//This is a Smart Send order
			$post_custom = get_post_custom( $this->_order->get_id() );
			if( isset($post_custom['flexdelivery'][0]) && !empty($post_custom['flexdelivery'][0]) ){
				return $post_custom['flexdelivery'][0];
			} else {
				return null;
			}
		} elseif( $this->isVconnect() ) {
			//This is a vConnect order
			$post_custom = get_post_custom( $this->_order->get_id() );
			if( isset($post_custom['_vc_aino_delivery_type'][0]) && !empty($post_custom['_vc_aino_delivery_type'][0]) ){
				return $post_custom['_vc_aino_delivery_type'][0];
			} else {
				return null;
			}
		} else {
			return null;
		}
	}
	
	/**
	* 
	* Get the start time for Smart Delivery from MySQL
	* @return string / null
	*/
	public function getSmartDeliveryTimeStart() {
		return null;
	}
	
	/**
	* 
	* Get the end time for Smart Delivery from MySQL
	* @return string / null
	*/
	public function getSmartDeliveryTimeEnd() {
		return null;
	}
	
/*****************************************************************************************
 * Settings
 ****************************************************************************************/
	
	/**
	* 
	* Get the settings for Post Danmark
	* @return array
	*/
	public function getSettingsPostdanmark() {
		
		$postdanmark = new Smartsend_Logistics_Postdanmark();
		return array(
			'notemail'			=> ($postdanmark->get_option( 'notemail','yes') == 'yes' ? true : null),
			'notesms'			=> ($postdanmark->get_option( 'notesms','yes') == 'yes' ? true : null),
			'prenote'			=> ($postdanmark->get_option( 'prenote','yes') == 'yes' ? true : false),
			'prenote_from'		=> $postdanmark->get_option( 'prenote_sender',''),
			'prenote_receiver'	=> $postdanmark->get_option( 'prenote_receiver',''),
			'prenote_message'	=> $postdanmark->get_option( 'prenote_message',''),
			'flex'				=> null,
			'format'			=> $postdanmark->get_option( 'format','pdf'),
			'quickid'			=> $postdanmark->get_option( 'quickid','1'),
			'waybillid'			=> $postdanmark->get_option( 'waybillid',''),
			'return'			=> $postdanmark->get_option( 'return',''),
			);
			
	}
	
	/**
	* 
	* Get the settings for Posten
	* @return array
	*/
	public function getSettingsPosten() {
	
		$posten = new Smartsend_Logistics_Posten();
		return array(
			'notemail'			=> ($posten->get_option( 'notemail','yes') == 'yes' ? true : null),
			'notesms'			=> ($posten->get_option( 'notesms','yes') == 'yes' ? true : null),
			'prenote'			=> ($posten->get_option( 'prenote','yes') == 'yes' ? true : false),
			'prenote_from'		=> $posten->get_option( 'prenote_sender',''),
			'prenote_receiver'	=> $posten->get_option( 'prenote_receiver',''),
			'prenote_message'	=> $posten->get_option( 'prenote_message',''),
			'flex'				=> null,
			'format'			=> $posten->get_option( 'format','pdf'),
			'quickid'			=> $posten->get_option( 'quickid','1'),
			'waybillid'			=> $posten->get_option( 'waybillid',''),
			'return'			=> $posten->get_option( 'return',''),
			);
			
	}
	
	/**
	* 
	* Get the settings for GLS
	* @return array
	*/
	public function getSettingsGls() {
	
		$gls = new Smartsend_Logistics_Gls();
		return array(
			'notemail'			=> ($gls->get_option( 'notemail','yes') == 'yes' ? true : null),
			'notesms'			=> ($gls->get_option( 'notesms','yes') == 'yes' ? true : null),
			'prenote'			=> null,
			'prenote_from'		=> null,
			'prenote_receiver'	=> null,
			'prenote_message'	=> null,
			'flex'				=> null,
			'format'			=> null,
			'quickid'			=> null,
			'waybillid'			=> null,
			'return'			=> $gls->get_option( 'return',''),
			);
			
	}
	
	/**
	* 
	* Get the settings for Bring
	* @return array
	*/
	public function getSettingsBring() {
	
		$bring = new Smartsend_Logistics_Bring();
		return array(
			'notemail'			=> ($bring->get_option( 'notemail','yes') == 'yes' ? true : null),
			'notesms'			=> ($bring->get_option( 'notesms','yes') == 'yes' ? true : null),
			'prenote'			=> null,
			'prenote_from'		=> null,
			'prenote_receiver'	=> null,
			'prenote_message'	=> null,
			'flex'				=> null,
			'format'			=> null,
			'quickid'			=> null,
			'waybillid'			=> null,
			'return'			=> $bring->get_option( 'return',''),
			);
			
	}
	
	/**
	* 
	* Should the order comment be included as freetext on label
	*
	* @return boolean
	*/
 	protected function getSettingIncludeOrderComment() {
 		return (get_option('smartsend_logistics_includeordercomment','yes') == 'yes' ? true : false);
 	}
	
/*****************************************************************************************
 * Shipments
 ****************************************************************************************/

	/**
	* 
	* Get the shipments for the order if any
	* @return array
	*/
	public function getShipments() {

		return null;
	}

	/**
	* 
	* Get the Track&Trace code for a given shipment
	* @return string
	*/
	public function getShipmentTrace($shipment) {

		return false;
	}
	
	/**
	* 
	* Get the weight (in kg) of a given shipment
	* @return float
	*/
	public function getShipmentWeight($shipment) {
		$weight = 0;
		foreach($shipment as $eachShipmentItem) {
			$itemWeight = (float) $eachShipmentItem['unitweight']; //Always returned in kg
			$itemQty    = (float) $eachShipmentItem['quantity'];
			$rowWeight  = $itemWeight*$itemQty;

			$weight = $weight + $rowWeight;
		}
		
		/* All */
		if($weight > 0) {
			return $weight;
		} else {
			return null;
		}
	}
	
	/**
	* 
	* Get the unshipped items of the order
	* @return array
	*/
	protected function getUnshippedItems() {

		$weight_unit = get_option('woocommerce_weight_unit');

		$ordered_items = $this->_order->get_items();
		foreach($ordered_items as $item) {
			$_product = $this->_order->get_product_from_item( $item );
			if ( ! $_product->is_virtual() ) {
				$weight = $_product->get_weight();

				switch ($weight_unit) {
					case 'g':
						$weight = round($weight/1000,3);
						break;
					case 'lbs':
						$weight = round($weight*0.45359237,3);
						break;
					case 'oz':
						$weight = round($weight*0.0283495231,3);
						break;
				}
			} else {
				$weight = null;
			}
	
			$items[] =  array(
				'sku'		=> ($_product->get_sku() != '' ? $_product->get_sku() : null),
				'title'		=> ($_product->get_title() != '' ? $_product->get_title() : null),
				'quantity'	=> (float) $item['qty'],
				'unitweight'=> ((float) $weight != 0 ? (float) $weight : null),
				'unitprice'	=> (float) $_product->get_price(),
				'currency'	=> get_woocommerce_currency()
				);
		}

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
	public function createShipment($tracking_number=null) {
	}
	
	/**
	* 
	* Add a shipment to the request
	*/
	protected function addShipment($shipment) {
	
		$this->_parcels[] = $shipment;

	}
	
	/*
	 * Add a parcel to the request
	 * The parcel contains all unshipped items
	 */
	protected function addParcelWithUnshippedItems() {
		$unshipped_items = $this->getUnshippedItems();
		
		//create and object, $shipment, with all items
		if ( sizeof( $unshipped_items ) > 0 ) {
			$parcel = array(
				'shipdate'	=> null,
				'reference' => $this->getOrderId(),
				'weight'	=> $this->getShipmentWeight($unshipped_items),
				'height'	=> null,
				'width'		=> null,
				'length'	=> null,
				'size'		=> null,
				'freetext1'	=> $this->getFreetext(),
				'freetext2'	=> null,
				'freetext3'	=> null,
				'items' 	=> $unshipped_items
				);
	
			$this->_parcels[] = $parcel;
		}
	}

	/**
	* 
	* Format an item to be added to a parcel
	* @return array
	*/
	protected function addItem($item) {

		return array(
			'sku'		=> $item->getSku(),
			'title'		=> $item->getName(),
			'quantity'	=> $item->getQty(),
			'unitweight'=> $item->getWeight(),
			'unitprice'	=> $item->getPrice(),
			'currency'	=> Mage::app()->getStore()->getCurrentCurrencyCode()
			);
		  //  $item->getItemId(); //product id
	}

}