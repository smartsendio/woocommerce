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
		 * The fulfillment service running the label workflow.
		 *
		 * @var SS_Shipping_Fulfillment_Service
		 */
		protected SS_Shipping_Fulfillment_Service $fulfillment_service;

		/**
		 * @param SS_Shipping_Fulfillment_Service $fulfillment_service The fulfillment service.
		 */
		public function __construct( SS_Shipping_Fulfillment_Service $fulfillment_service ) {
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

			// Parcels input, in the frozen id/name/value row shape the meta box JS posts.
			$parcels = ( $split_parcel && isset( $_POST['ss_shipping_parcels'] ) && is_array( $_POST['ss_shipping_parcels'] ) ) ? $_POST['ss_shipping_parcels'] : array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput

			// Translate the posted parcel input into the typed parcel plan and
			// hand it into the flow as partial delivery details (#139); the
			// fulfillment service persists it before booking (save-before-book
			// preserved, the request/response redesign is #116). An unchecked
			// "Split into parcels" box submits an empty plan, clearing a
			// stored split like before.
			$delivery_overrides = new SS_Shipping_Delivery_Details();
			$delivery_overrides->set_parcel_plan( SS_Shipping_Parcel_Plan::from_box_rows( $parcels ) );

			$result = $return
				? $this->fulfillment_service->fulfill_return( $order_id, false, $delivery_overrides )
				: $this->fulfillment_service->fulfill_outbound( $order_id, false, $delivery_overrides );

			wp_send_json( $result->to_legacy_response_array() );
			wp_die();
		}
	}

endif;
