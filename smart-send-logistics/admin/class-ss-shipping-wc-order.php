<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Order.
 *
 * The admin order integration facade. Wires the hooks and delegates the
 * actual work to single-responsibility components:
 *
 * - SS_Shipping_Order_Meta          order meta access (method id, agent, parcels, shipment ids)
 * - SS_Shipping_Order_Meta_Box      the order screen meta box
 * - SS_Shipping_Fulfillment_Service the label fulfillment workflow (book + post-booking steps)
 * - SS_Shipping_Label_Creator       AJAX label-generation controller
 * - SS_Shipping_Order_Bulk_Actions  bulk label generation on the Orders screen
 *
 * The public surface of this class is kept stable: merchant code snippets
 * and the test suite reach everything through
 * SS_SHIPPING_WC()->get_ss_shipping_wc_order().
 *
 * @package  SS_Shipping_WC_Order
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_WC_Order' ) ) :

	class SS_Shipping_WC_Order {

		/**
		 * Admin notices component used to flash one-time notices after
		 * label-generation actions.
		 *
		 * @var SS_Shipping_Admin_Notices
		 */
		protected SS_Shipping_Admin_Notices $admin_notices;

		/**
		 * Order meta access component.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Order screen meta box component.
		 *
		 * @var SS_Shipping_Order_Meta_Box
		 */
		protected SS_Shipping_Order_Meta_Box $meta_box;

		/**
		 * The fulfillment service running the label workflow.
		 *
		 * @var SS_Shipping_Fulfillment_Service
		 */
		protected SS_Shipping_Fulfillment_Service $fulfillment_service;

		/**
		 * AJAX label-generation controller.
		 *
		 * @var SS_Shipping_Label_Creator
		 */
		protected SS_Shipping_Label_Creator $label_creator;

		/**
		 * Bulk actions component.
		 *
		 * @var SS_Shipping_Order_Bulk_Actions
		 */
		protected SS_Shipping_Order_Bulk_Actions $bulk_actions;

		/**
		 * Init and hook in the integration.
		 */
		public function __construct() {
			$this->admin_notices       = new SS_Shipping_Admin_Notices();
			$this->order_meta          = new SS_Shipping_Order_Meta();
			$this->meta_box            = new SS_Shipping_Order_Meta_Box( $this->order_meta );
			$this->fulfillment_service = new SS_Shipping_Fulfillment_Service(
				$this->order_meta,
				new SS_Shipping_Booking_Service( $this )
			);
			$this->label_creator       = new SS_Shipping_Label_Creator( $this->order_meta, $this->fulfillment_service );
			$this->bulk_actions        = new SS_Shipping_Order_Bulk_Actions( $this->order_meta, $this->fulfillment_service, $this->admin_notices );

			$this->define_constants();
			$this->init_hooks();
		}

		/**
		 * Get the admin notices component.
		 *
		 * @return SS_Shipping_Admin_Notices
		 */
		public function get_admin_notices() {
			return $this->admin_notices;
		}

		/**
		 * Get the fulfillment service.
		 *
		 * Programmatic callers reach the label fulfillment workflow through
		 * SS_SHIPPING_WC()->get_ss_shipping_wc_order()->fulfillment().
		 *
		 * @return SS_Shipping_Fulfillment_Service
		 */
		public function fulfillment(): SS_Shipping_Fulfillment_Service {
			return $this->fulfillment_service;
		}

		/**
		 * Define constants
		 */
		protected function define_constants() {
			SS_SHIPPING_WC()->define(
				'SS_SHIPPING_BUTTON_LABEL_GEN',
				SS_SHIPPING_WC()->get_demo_mode_setting()
					? __( 'DEMO MODE: Generate label', 'smart-send-logistics' )
					: __( 'Generate label', 'smart-send-logistics' )
			);
			SS_SHIPPING_WC()->define(
				'SS_SHIPPING_BUTTON_RETURN_LABEL_GEN',
				SS_SHIPPING_WC()->get_demo_mode_setting()
					? __( 'DEMO MODE: Generate return label', 'smart-send-logistics' )
					: __( 'Generate return label', 'smart-send-logistics' )
			);
		}

		protected function init_hooks() {
			// Order page metabox actions
			add_action( 'add_meta_boxes', array( $this, 'add_smart_send_order_meta_box' ), 20 );
			add_action( 'wp_ajax_ss_shipping_generate_label', array( $this, 'generate_label' ) );

			// Meta field for storing the selected agent_no
			add_filter( 'update_post_metadata_by_mid', array( $this, 'filter_update_agent_meta' ), 10, 4 );//For WordPress 5.0.0+
			add_action( 'deleted_post_meta', array( $this, 'action_deleted_agent_meta' ), 10, 4 );

			// The WooCommerce Subscriptions plugin is optional.
			$subs_version = class_exists( 'WC_Subscriptions' ) && ! empty( WC_Subscriptions::$version ) ? WC_Subscriptions::$version : null;
			// Prevent data being copied to subscriptions.
			if ( null !== $subs_version && version_compare( $subs_version, '2.0.0', '>=' ) ) {
				add_filter( 'wcs_renewal_order_meta_query', array( $this, 'woocommerce_subscriptions_renewal_order_meta_query' ), 10 );
			} else {
				add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'woocommerce_subscriptions_renewal_order_meta_query' ), 10 );
			}

			// Add bulk actions to the Orders screen table bulk action drop-downs.
			$this->bulk_actions->register_bulk_order_actions( $this );

			// Display pending admin notices pushed by bulk actions.
			$this->admin_notices->init_hooks();
		}

		/**
		 * Add an action to the Orders screen bulk action drop-down.
		 *
		 * @see SS_Shipping_Order_Bulk_Actions::add_bulk_order_actions()
		 */
		public function add_bulk_order_actions( $bulk_actions ) {
			return $this->bulk_actions->add_bulk_order_actions( $bulk_actions );
		}

		/**
		 * Process the selected bulk action.
		 *
		 * @see SS_Shipping_Order_Bulk_Actions::handle_bulk_order_actions()
		 */
		public function handle_bulk_order_actions( string $sendback, string $doaction, array $items ) {
			return $this->bulk_actions->handle_bulk_order_actions( $sendback, $doaction, $items );
		}

		/**
		 * Add the meta box for shipment info on the order page.
		 *
		 * @see SS_Shipping_Order_Meta_Box::add_smart_send_order_meta_box()
		 */
		public function add_smart_send_order_meta_box() {
			$this->meta_box->add_smart_send_order_meta_box();
		}

		/**
		 * Render the content of the order meta box.
		 *
		 * @see SS_Shipping_Order_Meta_Box::render_smart_send_order_meta_box()
		 *
		 * @param WP_Post|WC_Order $post_or_order_object
		 * @return void
		 */
		public function render_smart_send_order_meta_box( $post_or_order_object ) {
			$this->meta_box->render_smart_send_order_meta_box( $post_or_order_object );
		}

		/**
		 * Return ordered Smart Send shipping method, OR Free Shipping linked to Smart Send shipping method, otherwise empty string.
		 *
		 * @see SS_Shipping_Order_Meta::get_smart_send_method_id()
		 *
		 * @param integer $order_id     Post object or post ID of the order.
		 * @param boolean $return       Whether or not the label is return (true) or normal (false)
		 * @return string               Unique Smart Send name of shipping method. Example 'postnord_agent'
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_smart_send_method_id( $order_id, $return = false ) {
			return $this->order_meta->get_smart_send_method_id( $order_id, $return );
		}

		/**
		 * Agent meta data updated.
		 *
		 * @see SS_Shipping_Order_Meta::filter_update_agent_meta()
		 */
		public function filter_update_agent_meta( $check, $meta_id, $meta_value, $meta_key ) {
			return $this->order_meta->filter_update_agent_meta( $check, $meta_id, $meta_value, $meta_key );
		}

		/**
		 * Agent meta deleted.
		 *
		 * @see SS_Shipping_Order_Meta::action_deleted_agent_meta()
		 */
		public function action_deleted_agent_meta( $meta_ids, $object_id, $meta_key, $_meta_value ) {
			$this->order_meta->action_deleted_agent_meta( $meta_ids, $object_id, $meta_key, $_meta_value );
		}

		/**
		 * Call the API if needed and save the shipping agent address.
		 *
		 * Kept protected with its historic signature; delegates to the meta
		 * component.
		 *
		 * @see SS_Shipping_Order_Meta::save_shipping_agent()
		 *
		 * @return bool|string Returns true for success and false or a string when failing
		 */
		protected function save_shipping_agent( $post_id, $doing_ajax, $ss_shipping_agent_no ) {
			return $this->order_meta->save_shipping_agent( $post_id, $doing_ajax, $ss_shipping_agent_no );
		}

		/**
		 * Save Agent No. and Generate Label (AJAX endpoint).
		 *
		 * @see SS_Shipping_Label_Creator::generate_label()
		 */
		public function generate_label() {
			$this->label_creator->generate_label();
		}

		/**
		 * Create label for a single WooCommerce order and maybe auto generate return label.
		 *
		 * Kept protected with its historic signature and legacy response
		 * shape; delegates to the fulfillment service.
		 *
		 * @see SS_Shipping_Fulfillment_Service::fulfill_outbound()
		 * @see SS_Shipping_Fulfillment_Service::fulfill_return()
		 *
		 * @return array
		 */
		protected function create_label_for_single_order_maybe_return(
			$order_id,
			$return = false, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing method signature, kept for backwards compatibility.
			$setting_save_order_note = true
		) {
			$result = $return
				? $this->fulfillment_service->fulfill_return( $order_id, $setting_save_order_note )
				: $this->fulfillment_service->fulfill_outbound( $order_id, $setting_save_order_note );

			return $result->to_legacy_response_array();
		}

		/**
		 * Saves the parcels input to post_meta.
		 *
		 * @see SS_Shipping_Order_Meta::save_ss_shipping_order_parcels()
		 */
		public function save_ss_shipping_order_parcels( $order_id, $parcels ) {
			$this->order_meta->save_ss_shipping_order_parcels( $order_id, $parcels );
		}

		/**
		 * Gets parcels input from post_meta.
		 *
		 * @see SS_Shipping_Order_Meta::get_ss_shipping_order_parcels()
		 */
		public function get_ss_shipping_order_parcels( $order_id ) {
			return $this->order_meta->get_ss_shipping_order_parcels( $order_id );
		}

		/**
		 * Saves the label agent no to post_meta.
		 *
		 * @see SS_Shipping_Order_Meta::save_ss_shipping_order_agent_no()
		 */
		public function save_ss_shipping_order_agent_no( $order_id, $agent_no ) {
			$this->order_meta->save_ss_shipping_order_agent_no( $order_id, $agent_no );
		}

		/**
		 * Gets agent no from the post meta array for an order.
		 *
		 * @see SS_Shipping_Order_Meta::get_ss_shipping_order_agent_no()
		 */
		public function get_ss_shipping_order_agent_no( $order_id ) {
			return $this->order_meta->get_ss_shipping_order_agent_no( $order_id );
		}

		/**
		 * Saves the agent object to post_meta.
		 *
		 * @see SS_Shipping_Order_Meta::save_ss_shipping_order_agent()
		 */
		public function save_ss_shipping_order_agent( $order_id, $agent ) {
			$this->order_meta->save_ss_shipping_order_agent( $order_id, $agent );
		}

		/**
		 * Delete shipping agent object.
		 *
		 * @see SS_Shipping_Order_Meta::delete_ss_shipping_order_agent()
		 */
		public function delete_ss_shipping_order_agent( $order_id ) {
			$this->order_meta->delete_ss_shipping_order_agent( $order_id );
		}

		/**
		 * Gets agent object from the post meta array for an order.
		 *
		 * @see SS_Shipping_Order_Meta::get_ss_shipping_order_agent()
		 */
		public function get_ss_shipping_order_agent( $order_id ) {
			return $this->order_meta->get_ss_shipping_order_agent( $order_id );
		}

		/**
		 * Saves the Shipment ID to post_meta.
		 *
		 * @see SS_Shipping_Order_Meta::save_ss_shipment_id_in_order_meta()
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function save_ss_shipment_id_in_order_meta( $order_id, $shipment_id, $return ) {
			$this->order_meta->save_ss_shipment_id_in_order_meta( $order_id, $shipment_id, $return );
		}

		/**
		 * Gets label URL from the order meta.
		 *
		 * @see SS_Shipping_Fulfillment_Service::get_label_url_from_order_id()
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_label_url_from_order_id( $order_id, $return ): string {
			return $this->fulfillment_service->get_label_url_from_order_id( $order_id, $return );
		}

		/**
		 * Get formatted label link.
		 *
		 * @see SS_Shipping_Fulfillment_Service::get_ss_shipping_label_link()
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_ss_shipping_label_link( $url, $return ) {
			return $this->fulfillment_service->get_ss_shipping_label_link( $url, $return );
		}

		/**
		 * Save tracking number in Shipment Tracking.
		 *
		 * @see SS_Shipping_Fulfillment_Service::save_tracking_in_shipment_tracking()
		 */
		public function save_tracking_in_shipment_tracking(
			$order_id,
			$tracking_number,
			$tracking_url,
			$provider = 'Smart Send',
			$date_shipped = null
		) {
			$this->fulfillment_service->save_tracking_in_shipment_tracking( $order_id, $tracking_number, $tracking_url, $provider, $date_shipped );
		}

		/**
		 * Prevents data being copied to subscription renewals
		 */
		public function woocommerce_subscriptions_renewal_order_meta_query( $order_meta_query ) {
			$order_meta_query .= " AND `meta_key` NOT IN ( '_ss_shipping_label' )";

			return $order_meta_query;
		}
	}

endif;
