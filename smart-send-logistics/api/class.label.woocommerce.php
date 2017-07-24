<?php

/**
 * Smartsend_Logistics Label WooCommerce class
 *
 * The label class is used to handle requests and responses from the Smart Send Logistics API
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
 * @class 		Smartsend_Logistics_Label_Woocommerce
 * @folder		/api/class.label.woocommerce.php
 * @category	Smart Send
 * @package		Smartsend_Logistics
 * @author 		Smart Send ApS
 * @url			http://smartsend.dk/
 * @copyright	Copyright (c) Smart Send ApS (http://www.smartsend.dk)
 * @license		http://smartsend.dk/license
 * @since		Class available since Release 7.1.0
 * @version		Release: 7.1.3.0
*/

class Smartsend_Logistics_Label_Woocommerce extends Smartsend_Logistics_Label {

	protected $apikey;
	protected $smartsend_licensekey;
	protected $smartsend_username;
	protected $cms_system;
	protected $cms_version;
	protected $module_version;
	protected $cms_language;
	protected $settings;

	/*
	 * Set all the variables when class is initiated
	 *
	 * @return void
	 */
	public function __construct() {
		$this->setApiKey();
		$this->setSmartsendLicensekey();
		$this->setSmartsendUsername();
		$this->setCmsVersion();
		$this->setCmsSystem();
		$this->setModuleVersion();
		$this->setCmsLanguage();
		$this->setMessageStringArray();
		$this->setSettings();
	}
	
	/*
	 * Set the strings that are shown (and translated) for succes, error and notification messages
	 *
	 * @return void
	 */
	protected function setMessageStringArray() {
		$this->message_string_array = array(
			2000	=> __('Order','smart-send-logistics'),
			//Notifications
			2101	=> __('Download combined PDF label','smart-send-logistics'),
			2102	=> __('Link to print labels','smart-send-logistics'),
			2103	=> __('Download PDF label','smart-send-logistics'),
			2104	=> __('Link to print label','smart-send-logistics'),
			2105	=> __('Label generated with Smart Send Logistics','smart-send-logistics'),
			2106	=> __('Tracecode','smart-send-logistics'),
			//Errors
			2201	=> __('Unknown API method','smart-send-logistics'),
			2202	=> __('Trying to send empty order array','smart-send-logistics'),
			2203	=> __('Error from server','smart-send-logistics'),
			2204	=> __('No orders returned from server','smart-send-logistics'),
			2205	=> __('No parcels returned from server','smart-send-logistics'),
			2206	=> __('Unknown order was returned from server','smart-send-logistics'),
			2207	=> __('No PDF file or link returned from server','smart-send-logistics'),
			2208	=> __('Failed to insert tracecode. No tracecode available.','smart-send-logistics'),
			2209	=> __('Failed to change order status. Status unchanged.','smart-send-logistics'),
			2210	=> __('Failed to send shipment mail. No mail sent.','smart-send-logistics'),
			2211	=> __('Failed to add order comment','smart-send-logistics'),
			2212	=> __('Please enter a username in the modules settings','smart-send-logistics'),
			2213	=> __('Please enter a license key in the modules settings','smart-send-logistics'),
			);
	}
	
	/*
	 * Set the apikey used for API calls
	 *
	 * @return void
	 */
	protected function setApiKey() {
		$this->apikey = 'N5egWgckXdb4NhV3bTzCAKB26ou73nJm';
	}
	
	/*
	 * Set the licensekey used for API calls
	 *
	 * @return void
	 */
	protected function setSmartsendLicensekey() {
		if(get_option( 'smartsend_logistics_username', '' ) == '' && is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
        	$this->smartsend_licensekey = get_option('vc_aino_license_key', '' );
        } else {
        	$this->smartsend_licensekey = get_option( 'smartsend_logistics_licencekey', '' );
        }
	}
	
	/*
	 * Set the username used for API calls
	 *
	 * @return void
	 */
	protected function setSmartsendUsername() {
		if(get_option( 'smartsend_logistics_username', '' ) == '' && is_plugin_active( 'vc_pdk_allinone/vc_pdk_allinone.php')) {
			$this->smartsend_username = get_option('vc_aino_license_email', '' );
        } else {
        	$this->smartsend_username = get_option( 'smartsend_logistics_username', '' );
        }
	}
	
	/*
	 * Set the version number of the CMS system
	 *
	 * @return void
	 */
	protected function setCmsVersion() {

		// If get_plugins() isn't available, require it
		if ( ! function_exists( 'get_plugins' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	
			// Create the plugins folder and file variables
		$plugin_folder = get_plugins( '/' . 'woocommerce' );
		$plugin_file = 'woocommerce.php';
	
		// If the plugin version number is set, return it 
		if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
			$this->cms_version = $plugin_folder[$plugin_file]['Version'];
		}
	}
	
	/*
	 * Set the language used by the CMS system
	 *
	 * @return void
	 */
	protected function setCmsLanguage() {
		$this->cms_language = get_locale();
	}
	
	/*
	 * Set the CMS system
	 *
	 * @return void
	 */
	protected function setCmsSystem() {
		$this->cms_system = 'WooCommerce';
	}
	
	/*
	 * Set the version number of this module
	 *
	 * @return void
	 */
	protected function setModuleVersion() {
		$rel_dir = str_replace("/api","",__DIR__);
		$plugin_info = get_plugin_data($rel_dir . '/woocommerce-smartsend-logistics.php', $markup = true, $translate = true );
		$this->module_version = $plugin_info["Version"];
	}
	
	/*
	 * Set the general settings from the module
	 *
	 * @return void
	 */
	protected function setSettings() {
		$this->settings = array(
			'combine_pdf_labels'	=> (get_option('smartsend_logistics_combinepdf','yes') == 'yes' ? true : false),
			'change_order_status'	=> (get_option('smartsend_logistics_order_status','0') != '0' ? get_option('smartsend_logistics_order_status','0') : false),
			'send_shipment_mail'	=> false
			);
	}
	
	/*
	 * Load an order model class
	 *
	 * @return object
	 */
	protected function loadOrderModel($order_id) {
		$order_object = new WC_Order( $order_id );
		return $order_object;
	}
	
	/*
	 * Load a Smart Send order model class
	 *
	 * @return object
	 */
	protected function loadSmartsendOrderModel() {
		$smartsend_woocommerce_order_object = new Smartsend_Logistics_Order_Woocommerce();
		return $smartsend_woocommerce_order_object;
	}
	
	/*
	 * Send an email with the shipping informations
	 *
	 * @param string $order_number is the id of the order
	 * @param array $parcels_succes_array is an array of parcel id numbers
	 * @param string $customer_email_comments is a string that can be sendt with the email
	 *
	 * @return void
	 */
	protected function sendShipmentEmail($order_number,$parcels_succes_array,$customer_email_comments=null) {
	}
	
	/*
	 * Set the order status
	 *
	 * @param string $order_number is the id of the order
	 * @param string $order_status is the status that the order should be updated to
	 *
	 * @return void
	 */
	protected function setOrderStatus($order_number,$order_status) {
		$order = new WC_Order($order_number);
		
		if( $order->get_id() != '' && $order_status != '0' ) {
			$order->update_status( $order_status ); // update_status($status,$norder_note); order note is optional, if you want to  add a note to order
		}
	}
	
	/*
	 * Add a trace code to the parcel
	 * If order has no parcels, create a parcel with alll unshipped items
	 *
	 * @param string $order_number is the id of the order
	 * @param string $shipment_number is the id of the shipment
	 * @param string $tracking_number is the tracking number to add
	 * @param string $tracelink is the linked used to crack the parcel
	 *
	 * @return void
	 */
	protected function addTracecodeToParcel($order_number,$shipment_number,$tracking_number,$tracelink) {
		$order = new WC_Order( $order_number );
		
		$smartsendorder = new Smartsend_Logistics_Order_Woocommerce();
		$smartsendorder->setOrderObject($order);
		
		$provider = $smartsendorder->getShippingCarrier($format=0);
			
		//Add trace link to WooTheme extension 'Shipment Tracking'
		if( function_exists('wc_st_add_tracking_number') ) {
			wc_st_add_tracking_number( $order->get_id(), $tracking_number, $provider, $date_shipped = null, $custom_url = false );
		}
		
	}
	
	/*
	 * Add a comment to the order
	 *
	 * @param string $order_number is the id of the order
	 * @param string $order_comment is the comment to add
	 *
	 * @return void
	 */
	protected function addCommentToOrder($order_number,$order_comment) {
		$order = new WC_Order( $order_number );
		if($order->get_id() != '') {
			//Add order history comment
			$order->add_order_note($order_comment);
		} else {
			throw new Exception( $this->getMessageString(2211) );
		}
	}
	
	/*
	 * Show the messages that has been added during the API call
	 *
	 * @return void
	 */
	public function showResult() {
	
		foreach($this->getMessages() as $message) {
		
			switch ($message['type']) {
				case 'error':
					//Add an error
					smartsend_logistics_admin_notice($message['text'], 'error');
					break;
				case 'warning':
					//Add a warning
					smartsend_logistics_admin_notice($message['text'], 'info');
					break;
				case 'success':
					//Add a success
					smartsend_logistics_admin_notice($message['text'], 'succes');
					break;
				default:
					//Add an information
					smartsend_logistics_admin_notice($message['text'], 'info');
			}

		}
	}
	
	/**
 	 * The metchods adds an order to the API request
 	 * both for single and mass generation
 	 *
 	 * @param object $order
 	 * @param bool $return indicates if the order is a return order (true) or a normal order (false)
 	 *
 	 * @return void
 	 */
 	public function addOrderToRequest($order,$return=false) {
		
		$smartsendorder = new Smartsend_Logistics_Order_Woocommerce();
		$smartsendorder->setOrderObject($order);
		$smartsendorder->setReturn($return);

		$smartsendorder->setInfo();
		$smartsendorder->setReceiver();
		$smartsendorder->setSender();
		$smartsendorder->setAgent();
		$smartsendorder->setService();
		$smartsendorder->setParcels();

		//All done. Add to request.
		switch ($this->getRequestType()) {
			case 'bulk':
				//Label was created from order list
				$this->request[] = $smartsendorder->getFinalOrder();
				break;
			case 'single':
				//Label was created from order info page
				$this->request = $smartsendorder->getFinalOrder();
				break;
			default:
				throw new Exception( $this->getMessageString(2201) );
		}
 	}
	
}