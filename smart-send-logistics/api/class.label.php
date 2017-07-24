<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once( WP_PLUGIN_DIR . '/woocommerce/includes/abstracts/abstract-wc-order.php' );
require_once( WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-order.php' );

class Smartsend_Logistics_Label{

	protected $request=array();
	protected $response;
	
	public function _construct() {
        
    }

 	/*
 	 * Function: is there a requerst?
 	 * both for single and mass generation
 	 */
 	public function isRequest() {
 		if(empty($this->request)) {
 			return false;
 		} else {
 			return true;
 		}
 	}
 
 
 	/*
 	 * Function: Get JSON request
 	 * both for single and mass generation
 	 */
 	private function getJsonRequest() {
 		if(empty($this->request)) {
 			throw new Exception(__("Trying to send empty order array"));
 		} else {
 			return json_encode($this->request);
 		}
 	}
 
 	/*
 	 * Function: Create an order request
 	 * both for single and mass generation
 	 */
 	public function createOrder($order,$return=false) {
		
		$smartsendorder = new Smartsend_Logistics_Order();
		$smartsendorder->setOrderObject($order);
		$smartsendorder->setReturn($return);

		$smartsendorder->setInfo();
		$smartsendorder->setReceiver();
		$smartsendorder->setSender();
		$smartsendorder->setAgent();
		$smartsendorder->setServices();
		$smartsendorder->setParcels();

		//All done. Add to request.
		$this->request[] = $smartsendorder->getFinalOrder();
 	}
 	
 	/*
 	 * Function: POST final cURL request
 	 * both for single and mass generation
 	 */
 	public function postRequest($single=false) {
 	
 		$ch = curl_init();               //intitiate curl

        /* Script URL */
        if($single == true) {
        	$url = 'https://smartsend-prod.apigee.net/v7/booking/order';
        } elseif($single == false) {
        	$url = 'https://smartsend-prod.apigee.net/v7/booking/orders';
        } else {
        	throw new Exception('Unknown post method: '.$single);
        }
        
        if(get_option( 'smartsend_logistics_username', '' ) == '' && is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
        	$settings = get_option('woocommerce_vc_pdk_allinone_settings');
			$username = $settings['license_email'];
			$licensekey = $settings['license_key'];
        } else {
        	$username = get_option( 'smartsend_logistics_username', '' );
        	$licensekey = get_option( 'smartsend_logistics_licencekey', '' );
        }
        
        $rel_dir = str_replace("/api","",__DIR__);
		$plugin_info = get_plugin_data($rel_dir . '/woocommerce-smartsend-logistics.php', $markup = true, $translate = true );

        curl_setopt($ch, CURLOPT_URL, $url);       //curl url
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->getJsonRequest());
        //curl_setopt($ch, CURLOPT_HTTPGET, true);   //curl request method
        //curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        	'apikey:N5egWgckXdb4NhV3bTzCAKB26ou73nJm',
        	'smartsendmail:'.$username,
        	'smartsendlicence:'.$licensekey,
        	'cmssystem:WooCommerce',
        	'cmsversion:'.$this->wpbo_get_woo_version_number(),
        	'appversion:'.$plugin_info["Version"],
        	'Content-Type:application/json; charset=UTF-8'
        	));    //curl request header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $this->response = new StdClass();                       //creating new class
        $this->response->body = curl_exec($ch);             //executing the curl
        $this->response->code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->response->meta = curl_getinfo($ch);
        $curl_error = ($this->response->code > 0 ? null : curl_error($ch) . ' (' . curl_errno($ch) . ')');      //getting error from curl if any

        curl_close($ch);                          //closing the curl

        if ($curl_error) {
            throw new Exception(__('An error occurred while sending order').': ' . $curl_error);
        }
        
        if(!($this->response->code >= '200') || !($this->response->code <= '210')) {
        	throw new Exception(__('Response').': ('.$this->response->code.') '.$this->response->body);
        }
 	
 	}
 	
 	private function wpbo_get_woo_version_number() {
			// If get_plugins() isn't available, require it
		if ( ! function_exists( 'get_plugins' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	
			// Create the plugins folder and file variables
		$plugin_folder = get_plugins( '/' . 'woocommerce' );
		$plugin_file = 'woocommerce.php';
	
		// If the plugin version number is set, return it 
		if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
			return $plugin_folder[$plugin_file]['Version'];

		} else {
		// Otherwise return null
			return NULL;
		}
	}
	
	/*
	 * Add Track and Trace number to parcels
	 * @string shipment_reference: unique if of shipment
	 * @string tracecode
	 */ 
 	private function addTraceToShipment($shipment_reference,$tracecode) {
 	// NOT DONE! STILL MAGENTO!
 	
 	
	/*	$shipment_collection = Mage::getResourceModel('sales/order_shipment_collection');
		$shipment_collection->addAttributeToFilter('order_id', $order_id);
		
		foreach($shipment_collection as $sc) {
			$shipment = Mage::getModel('sales/order_shipment');
			$shipment->load($sc->getId());
			if($shipment->getId() != '') { 
				$track = Mage::getModel('sales/order_shipment_track')
						 ->setShipment($shipment)
						 ->setData('title', 'ShippingMethodName')
						 ->setData('number', $track_no)
						 ->setData('carrier_code', 'ShippingCarrierCode')
						 ->setData('order_id', $shipment->getData('order_id'))
						 ->save();
			}
		} */
		
		$shipment = Mage::getModel('sales/order_shipment');
		$shipment->load($shipment_reference);
		if($shipment->getId() != '') {
			$order = Mage::getModel('sales/order')->load($shipment->getData('order_id'));
			$smartsendorder = Mage::getModel('logistics/order');
		
			$track = Mage::getModel('sales/order_shipment_track')
				->setShipment($shipment)
				->setData('title', $smartsendorder->getMethod($order))
				->setData('number', $tracecode)
				->setData('carrier_code', $smartsendorder->getSmartSendCarrier($order))
				->setData('order_id', $shipment->getData('order_id'))
				->save();
		} else {
			throw new Exception($this->__('Failed to insert tracecode'));
		}
		
 	}
 	
 	/*
 	 * Function: go through parcels and add trace code
 	 */
 	private function verifyParcels($json) {
 		if(isset($json->parcels) && is_array($json->parcels)) {
 			$trace_codes = array();
			foreach($json->parcels as $parcel) {
				if(isset($parcel->reference) && $parcel->reference != '' && isset($parcel->tracecode) && $parcel->tracecode != '') {
					$trace_codes[] = $parcel->tracecode;
				}	
			}
			
			if(!empty($trace_codes)) {
				$order = new WC_Order( $json->orderno );
				
				$smartsendorder = new Smartsend_Logistics_Order();
				$smartsendorder->setOrderObject($order);
				
				$tracking_code_combined = '';
				foreach($trace_codes as $trace_code) {
					//Add a note with a Track&Trace link
					$order->add_order_note('TraceCode: <a href="https://smartsend-prod.apigee.net/trace/'.$smartsendorder->getShippingCarrier().'/'.$trace_code.'" target="_blank">'.$trace_code.'</a>');
					$tracking_code_combined .= ($tracking_link != '' ? ',' : '').$trace_code;
				}
				
				if($tracking_code_combined != ',' && $tracking_code_combined != '') {
					//Add trace link to WooTheme extension 'Shipment Tracking'
					update_post_meta( $order->id, '_tracking_provider', $smartsendorder->formatCarrier($smartsendorder->getShippingCarrier(),1) );
					update_post_meta( $order->id, '_custom_tracking_provider', $smartsendorder->formatCarrier($smartsendorder->getShippingCarrier(),1) );
					update_post_meta( $order->id, '_tracking_number', $tracking_code_combined );
					//update_post_meta( $order->id, '_custom_tracking_link', null );
					//update_post_meta( $order->id, '_date_shipped', null );
				}
			}
		}
	}	
	
 	/*
 	 * Function: Handle cURL response
 	 * both for single and mass generation
 	 */
 	public function handleRequest() {
 		if(strpos($this->response->meta['content_type'],'json') !== false) {
 			$_errors = array();
 			$_notification = array();
 			$_succeses = array();
 		
 			$json = json_decode($this->response->body);
 		/*	$this->_getSession()->addNotice($this->getJsonRequest());
 			$this->_getSession()->addNotice($this->response->body); */
 			
 			//Show a notice if info is given
 			if(isset($json->info)) {
 				if(is_array($json->info)) {
 					foreach($json->info as $info) {
 						$_notification[] = $info;
 					}
 				} else {
 					$_notification[] = $json->info;
 				}
 			}
 			
 			if(isset($json->combine_pdf) && get_option('smartsend_logistics_combinepdf','yes') == 'yes') {
 				$_succeses[] = '<a href="'. $json->combine_pdf .'" target="_blank">Combined PDF labels</a>';
 			}
 			
 			if(isset($json->combine_link) && get_option('smartsend_logistics_combinepdf','yes') == 'yes') {
 				$_succeses[] = '<a href="'. $json->combine_link .'" target="_blank">Combined label links</a>';
 			}
 			
			if(isset($json->orders) && is_array($json->orders)) {
				// An array of orders was returned
				foreach($json->orders as $json_order) {
					if(isset($json_order->pdflink) && !(isset($json->combine_pdf) && get_option('smartsend_logistics_combinepdf','yes') == "yes")) {
						$_succeses[] = 'Order #'.$json_order->orderno.': <a href="'. $json_order->pdflink .'" target="_blank">PDF label</a>';
						// Go through parcels and add trace to shipments
						$this->verifyParcels($json_order);	
					} elseif(isset($json_order->link) && !(isset($json->combine_link) && get_option('smartsend_logistics_combinepdf','yes') == "yes")) {
						$_succeses[] = 'Order #'.$json_order->orderno.': <a href="'. $json_order->link .'" target="_blank">Label link</a>';
						// Go through parcels and add trace to shipments
						$this->verifyParcels($json_order);	
					} elseif( (isset($json_order->pdflink) || isset($json_order->link) ) && get_option('smartsend_logistics_combinepdf','yes') == "yes") {
						$_succeses[] = 'Order #'.$json_order->orderno.': '. $json_order->message; 
					} else {
						if(isset($json_order->status) && $json_order->status != '') {
							$_errors[] = 'Order #'.$json_order->orderno.': '. $json_order->message; 
						} else {
							$_errors[] = __('Unknown status').': '. $json_order->message;
						}
					}
				}
			
			} else {
				// An array of orders was not returned. Check if just a single order was returned
			
				if(isset($json->pdflink) && !(isset($json->combine_pdf) && get_option('smartsend_logistics_combinepdf','yes') == 1)) {
					$_succeses[] = 'Order #'.$json->orderno.': <a href="'. $json->pdflink .'" target="_blank">PDF label</a>';
					// Go through parcels and add trace to shipments
					$this->verifyParcels($json);	
				} elseif(isset($json->link) && !(isset($json->combine_link) && get_option('smartsend_logistics_combinepdf','yes') == 1)) {
					$_succeses[] = 'Order #'.$json->orderno.': <a href="'. $json->link .'" target="_blank">Label link</a>';
					// Go through parcels and add trace to shipments
					$this->verifyParcels($json);	
				} else {
					if(isset($json->status) && $json->status != '') {
						$_errors[] = 'Order #'.$json->orderno.': '. $json->message;
					} else {
						$_errors[] = __('Unknown status').': '. $json->message;
					}
				}
			}
 			

 			$_SESSION['smartsend_errors'] 			= array_merge((is_array($_SESSION['smartsend_errors']) ? $_SESSION['smartsend_errors'] : array()),$_errors);
 			$_SESSION['smartsend_notification'] 	= array_merge((is_array($_SESSION['smartsend_notification']) ? $_SESSION['smartsend_notification'] : array()),$_notification);
 			$_SESSION['smartsend_succeses'] 		= array_merge((is_array($_SESSION['smartsend_succeses']) ? $_SESSION['smartsend_succeses'] : array()),$_succeses);
 			
 			global $smartsend_errors;
 			$smartsend_errors = $GLOBALS['smartsend_errors'];
 		} else {
 			throw new Exception(__('Unknown content type').': '.$this->response->meta['content_type']);
 		}
 		
 	}
 	
}