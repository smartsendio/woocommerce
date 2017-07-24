<?php

/**
 * Order class
 *
 * Create order objects that is included in the final Smart Send label API callout.
 * These are the CMS dependent functions that is used by the order class.
 *
 * @class 		Smartsend_Logistics_Order_Woocommerce
 * @version		7.0.2
 * @author 		Smart Send
 *

 	// Order
	public function getShippingId()
	public function getPickupCarrier()
	public function getOrderId()
	public function getOrderReference()
	public function getOrderPriceTotal()
	public function getOrderPriceShipping()
	public function getOrderPriceCurrency()
	public function getCustomerComment()
	public function getShippingAddress()
	public function getBillingAddress()
	public function getPickupDataSmartsend()
	
	// Settings
	public function getSettingsPostdanmark()
	public function getSettingsPosten()
	public function getSettingsGls()
	public function getSettingsBring()
 
 	// Shipments
 	public function getShipments()
 	public function getShipmentTrace($shipment)
	public function getShipmentWeight($shipment)
	protected function getUnshippedItems()
 	protected function createShipment()
	protected function addShipment($shipment)
 	protected function addItem($item)
	
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
			2401	=>	__('No parcels without trace code','smart-send-logistics'),
			2402	=>	__('No unshipped items','smart-send-logistics'),
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
	
		if($shipMethod_id == 'free_shipping') {
			$shipMethod_id = get_option( 'smartsend_wc_shipping_free_shipping','free_shipping');
		}
	
		return $shipMethod_id; //return unique id of shipping method

	}

	/**
	* 
	* Get carrier name based on the pickup information.
	* Used if the shipping method is 'closest pickup point'
	* @return string
	*/
	public function getPickupCarrier() {
	
		$store_pickup = get_post_custom($order->id);
		$store_pickup = @unserialize($store_pickup['store_pickup'][0]);
		if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);

		if(!empty($store_pickup) && isset($store_pickup['carrier'])){				
			return $store_pickup['carrier'];
		} else {
			return null;
		}
	
	}
 
 	/**
	* 
	* Get the order id (SQL id)
	* @return string
	*/
 	public function getOrderId() {
 	
		return $this->_order->id;
		
 	}
 	
 	/**
	* 
	* Get the order refernce (the one the customer sees)
	* @return string
	*/
 	public function getOrderReference() {
 	
 		return $this->_order->get_order_number();
 	
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
 	
		return $this->_order->get_order_currency();
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
	
		$store_pickup = get_post_custom($this->_order->id);
		
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
	
/*****************************************************************************************
 * Settings
 ****************************************************************************************/
	
	/**
	* 
	* Get the settings for Post Danmark
	* @return array
	*/
	public function getSettingsPostdanmark() {
		
		$postdanmark = new Smartsend_Logistics_PostDanmark();
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
	
		$gls = new Smartsend_Logistics_GLS();
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
			'format'			=> null,
			'flex'				=> null,
			'quickid'			=> null,
			'waybillid'			=> null,
			'return'			=> $bring->get_option( 'return',''),
			);

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
			$itemWeight = $eachShipmentItem['unitweight'];
			$itemQty    = $eachShipmentItem['quantity'];
			$rowWeight  = $itemWeight*$itemQty;

			$weight = $weight + $rowWeight;
		}
		
		/* All */
		if($weight > 0) {
		
			if( get_option('woocommerce_weight_unit') == 'g') {
				return round($weight/1000,3);
			} else {
				return $weight;
			}
			
		} else {
			return null;
		}
	}

	/**
	* 
	* Get the unshipped items of the order
	* @return array
	*/
	public function getUnshippedItems() {

		$ordered_items = $this->_order->get_items();
		foreach($ordered_items as $item) {
			$_product = $this->_order->get_product_from_item( $item );
			if ( ! $_product->is_virtual() ) {
				$weight = $_product->get_weight();
				if( get_option('woocommerce_weight_unit') == 'g') {
					$weight = round($weight/1000,3);
				}
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
	public function createShipment() {

		//create and object, $shipment, with all items
		if ( sizeof( $this->_order->get_items() ) > 0 ) {
			$shipment = array(
				'shipdate'	=> null,
				'reference' => $this->getOrderId(),
				'weight'	=> $this->getShipmentWeight($this->getUnshippedItems()),
				'height'	=> null,
				'width'		=> null,
				'length'	=> null,
				'size'		=> null,
				'freetext1'	=> $this->getCustomerComment(),
				'freetext2'	=> null,
				'freetext3'	=> null,
				'items' 	=> $this->getUnshippedItems()
				);
		} else {
			//Order has no shipments and cannot be shipped
			throw new Exception("No items that could be shipped");
		}

		/* All */
		if ($shipment) {
			//Lastly add the shipment to the order array.
			$this->addShipment($shipment);
		}

	}
	
	/**
	* 
	* Add a shipment to the request
	*/
	public function addShipment($shipment) {
	
		$this->_parcels[] = $shipment;

	}

	/**
	* 
	* Format an item to be added to a parcel
	* @return array
	*/
	public function addItem($item) {

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