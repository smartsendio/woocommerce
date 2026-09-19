<?php

namespace Smart_Send\Admin;

use WC_Countries;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Product
 *
 * @package Smart_Send
 * @category Product
 * @author   Smart Send
 */

class Product {


	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// priority is '8' because WC Subscriptions hides fields in the shipping tabs which hide the fields here
		add_action( 'woocommerce_product_options_shipping', array( $this, 'additional_product_shipping_options' ), 8 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_additional_product_shipping_options' ) );
	}

	/**
	 * Render customs fields using the product's editable metadata.
	 */
	public function additional_product_shipping_options() {
		$product = wc_get_product( get_the_ID() );
		if ( ! $product ) {
			$product = new WC_Product();
		}

		$countries_obj = new WC_Countries();
		$options       = $countries_obj->get_countries();
		$options       = array( '' => __( 'Select country', 'smart-send-logistics' ) ) + $options;// A select to the top
		woocommerce_wp_select(
			array(
				'id'          => '_ss_country_of_origin',
				'label'       => __( 'Country of origin', 'smart-send-logistics' ),
				'description' => __( 'ISO3166-alpha2 code of the country where the item was produced', 'smart-send-logistics' ),
				'desc_tip'    => 'true',
				'options'     => $options,
				'value'       => $product->get_meta( '_ss_country_of_origin', true, 'edit' ),
			),
			$product
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_ss_customs_desc',
				'label'       => __( 'Customs description', 'smart-send-logistics' ),
				'description' => '',
				'desc_tip'    => 'false',
				'placeholder' => __( 'Example: T-shirt', 'smart-send-logistics' ),
				'value'       => $product->get_meta( '_ss_customs_desc', true, 'edit' ),
			),
			$product
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_ss_hs_code',
				'label'       => __( 'Harmonized Tariff Schedule', 'smart-send-logistics' ),
				'description' => __(
					'Harmonized Tariff Schedule is a number assigned to every possible commodity that can be imported or exported from any country.',
					'smart-send-logistics'
				),
				'desc_tip'    => 'true',
				'placeholder' => __( 'Example: 12345678', 'smart-send-logistics' ),
				'value'       => $product->get_meta( '_ss_hs_code', true, 'edit' ),
			),
			$product
		);
	}

	/**
	 * Stage submitted fields on the object WooCommerce is about to save.
	 *
	 * WooCommerce verifies the product-save nonce and permissions before
	 * dispatching this hook. Missing fields must not clear existing values.
	 *
	 * @param WC_Product $product Product being saved by the product editor.
	 */
	public function save_additional_product_shipping_options( WC_Product $product ): void {
		$fields = array( '_ss_country_of_origin', '_ss_customs_desc', '_ss_hs_code' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce validates the product-save request before this hook.
		foreach ( $fields as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
				continue;
			}

			$product->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
