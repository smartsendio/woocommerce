<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send label creation AJAX controller.
 *
 * Owns the wp_ajax_ss_shipping_generate_label endpoint: nonce check,
 * request parsing and the pre-booking parcel-meta save, then delegates
 * the actual fulfillment workflow to SS_Shipping_Fulfillment_Service and
 * sends the legacy-shaped JSON response that
 * admin/js/ss-shipping-label.js parses. The class name is kept for the
 * facade's frozen delegations; it is a thin controller since the
 * fulfillment-service extraction.
 *
 * @package  SS_Shipping_Label_Creator
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Label_Creator' ) ) :

	class SS_Shipping_Label_Creator {

		/**
		 * Order meta access component.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * The fulfillment service running the label workflow.
		 *
		 * @var SS_Shipping_Fulfillment_Service
		 */
		protected SS_Shipping_Fulfillment_Service $fulfillment_service;

		/**
		 * @param SS_Shipping_Order_Meta          $order_meta          Order meta access component.
		 * @param SS_Shipping_Fulfillment_Service $fulfillment_service The fulfillment service.
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Fulfillment_Service $fulfillment_service ) {
			$this->order_meta          = $order_meta;
			$this->fulfillment_service = $fulfillment_service;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			add_action( 'wp_ajax_ss_shipping_generate_label', array( $this, 'generate_label' ) );
		}

		/**
		 * Save Agent No. and Generate Label
		 */
		public function generate_label() {
			check_ajax_referer(
				'create-ss-shipping-label',
				'ss_shipping_label_nonce'
			); //This function dies if the referer is not correct
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: nonce-checked above, order id is wc_clean-ed and the flags are boolval-ed; changing the parcels input handling is out of scope for the #43 move.
			$order_id     = wc_clean( $_POST['order_id'] );
			$return       = boolval( $_POST['return_label'] );
			$split_parcel = boolval( $_POST['ss_shipping_split_parcel'] );

			// Save parcels input if set:
			$parcels = ( $split_parcel ) ? $_POST['ss_shipping_parcels'] : array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput
			// Pre-booking parcel-meta save: kept save-before-book as-is, the request/response redesign (#116) fixes it later.
			$this->order_meta->save_ss_shipping_order_parcels( $order_id, $parcels );

			$result = $return
				? $this->fulfillment_service->fulfill_return( $order_id, false )
				: $this->fulfillment_service->fulfill_outbound( $order_id, false );

			wp_send_json( $result->to_legacy_response_array() );
			wp_die();
		}
	}

endif;
