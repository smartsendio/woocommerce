<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Product
 *
 * @package  SS_Shipping_WC_Product
 * @category Product
 * @author   Shadi Manna
 */

if ( ! class_exists( 'SS_Shipping_WC_Product' ) ) :

class SS_Shipping_WC_Product {

	/**
	 * Init and hook in the integration.
	 */
	public function __construct( ) {
		// priority is '8' because WC Subscriptions hides fields in the shipping tabs which hide the fields here
		add_action( 'woocommerce_product_options_shipping', array($this,'additional_product_shipping_options'), 8 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_additional_product_shipping_options' ) );
	}

	/**
	 * Add the meta box for shipment info on the order page
	 */
	public function additional_product_shipping_options() {
	    global $thepostid, $post;

		$thepostid = empty( $thepostid ) ? $post->ID : $thepostid;

		woocommerce_wp_text_input( 
			array(
				'id' => '_ss_hs_code',
				'label' => __('Harmonized Tariff Schedule (Smart Send)', 'smart-send-shipping'),
				'description' => __('Harmonized Tariff Schedule is a number assigned to every possible commodity that can be imported or exported from any country.', 'smart-send-shipping'),
				'desc_tip' => 'true',
				'placeholder' => 'HsCode'
			) 
		);

		woocommerce_wp_text_input( 
			array(
				'id' => '_ss_custom_desc',
				'label' => __('Custom Description', 'smart-send-shipping'),
				'description' => '',
				'desc_tip' => 'false',
				'placeholder' => ''
			) 
		);
	}

	public function save_additional_product_shipping_options( $post_id ) {
	    //HS code value
		if ( isset( $_POST['_ss_hs_code'] ) ) {
			update_post_meta( $post_id, '_ss_hs_code', wc_clean( $_POST['_ss_hs_code'] ) );
		}

		//Custom description value
		if ( isset( $_POST['_ss_custom_desc'] ) ) {
			update_post_meta( $post_id, '_ss_custom_desc', wc_clean( $_POST['_ss_custom_desc'] ) );
		}
	}
}

endif;
