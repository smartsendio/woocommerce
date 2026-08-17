<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send pickup point validator.
 *
 * Validates a pickup point (agent) number entered by hand in the order
 * screen's Custom Fields box against the Smart Send API before it is
 * stored, and keeps the companion stored agent object in sync (storing
 * the API's agent data on success, deleting it when the number is
 * deleted). Extracted from SS_Shipping_Order_Meta (#139): API validation
 * and WC_Admin_Meta_Boxes error presentation are not meta CRUD.
 *
 * Deliberate v9 fix (#139): validation used to hang exclusively off the
 * update_post_metadata_by_mid filter and the deleted_post_meta action,
 * which only exist for post-table storage and never fire on HPOS stores,
 * so editing ss_shipping_order_agent_no on an HPOS order skipped
 * validation entirely. This class keeps the legacy hooks and adds the
 * HPOS admin edit seams: the order edit form save
 * (woocommerce_process_shop_order_meta, before WooCommerce's
 * CustomMetaBox applies $_POST['meta']) and the Custom Fields box's own
 * AJAX endpoints (woocommerce_order_add_meta / woocommerce_order_delete_meta,
 * hooked at priority 0 ahead of WooCommerce's handlers).
 *
 * @package  SS_Shipping_Pickup_Point_Validator
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Pickup_Point_Validator' ) ) :

	class SS_Shipping_Pickup_Point_Validator {

		/**
		 * Order meta repository.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $repository;

		/**
		 * Shipping method resolver.
		 *
		 * @var SS_Shipping_Method_Resolver
		 */
		protected SS_Shipping_Method_Resolver $method_resolver;

		/**
		 * @param SS_Shipping_Order_Meta      $repository      Order meta repository.
		 * @param SS_Shipping_Method_Resolver $method_resolver Shipping method resolver.
		 */
		public function __construct( SS_Shipping_Order_Meta $repository, SS_Shipping_Method_Resolver $method_resolver ) {
			$this->repository      = $repository;
			$this->method_resolver = $method_resolver;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			// Legacy post-table storage: the Custom Fields box updates meta
			// by meta id and deletes fire deleted_post_meta.
			add_filter( 'update_post_metadata_by_mid', array( $this, 'filter_update_agent_meta' ), 10, 4 );//For WordPress 5.0.0+
			add_action( 'deleted_post_meta', array( $this, 'action_deleted_agent_meta' ), 10, 4 );

			// HPOS storage: no WP meta hooks fire, so intercept the admin
			// edit seams themselves (see the class docblock).
			add_action( 'woocommerce_process_shop_order_meta', array( $this, 'validate_hpos_form_meta_changes' ), 10, 2 );
			add_action( 'wp_ajax_woocommerce_order_add_meta', array( $this, 'intercept_hpos_inline_meta_update' ), 0 );
			add_action( 'wp_ajax_woocommerce_order_delete_meta', array( $this, 'intercept_hpos_meta_delete' ), 0 );
		}

		/**
		 * Whether orders are stored in the HPOS custom orders table.
		 *
		 * @return boolean
		 */
		protected function hpos_enabled() {
			// The HPOS CustomOrdersTableController exists since WC 6.4; the plugin's WC floor is 5.0.
			return class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
				&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
		}

		/**
		 * Agent meta data updated (legacy post-table storage).
		 *
		 * @since 5.0.0
		 *
		 * @param null|bool   $check      Whether to allow updating metadata for the given type.
		 * @param int         $meta_id    Meta ID.
		 * @param mixed       $meta_value Meta value. Must be serializable if non-scalar.
		 * @param string|bool $meta_key   Meta key, if provided.
		 * @return bool                   Returning a non-null value will effectively short-circuit the function.
		 */
		public function filter_update_agent_meta( $check, $meta_id, $meta_value, $meta_key ) {

			if ( SS_Shipping_Order_Meta::META_AGENT_NO == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #139 move.
				$meta      = get_metadata_by_mid( 'post', $meta_id );
				$object_id = $meta->post_id;
				if ( $this->validate_and_store( $object_id, true, $meta_value ) !== true ) {
					// the agent was not found so do NOT save the new agent_no
					$check = false;
				}
			}

			return $check;
		}

		/**
		 * Agent meta deleted (legacy post-table storage).
		 * Fires immediately after deleting metadata of a specific type.
		 *
		 * @since WP 2.9.0
		 *
		 * @param array  $meta_ids    An array of deleted metadata entry IDs.
		 * @param int    $object_id   Object ID.
		 * @param string $meta_key    Meta key.
		 * @param mixed  $_meta_value Meta value.
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_meta_value is part of the deleted_post_meta hook signature.
		public function action_deleted_agent_meta( $meta_ids, $object_id, $meta_key, $_meta_value ) {

			if ( SS_Shipping_Order_Meta::META_AGENT_NO == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #139 move.
				$this->repository->delete_pickup_point( $object_id );
			}
		}

		/**
		 * Validate agent-number changes posted from the HPOS order edit
		 * form's Custom Fields box, before WooCommerce's CustomMetaBox
		 * applies $_POST['meta'] to the order.
		 *
		 * A rejected number is reverted in the request payload (so no
		 * change is applied) and reported via WC_Admin_Meta_Boxes.
		 *
		 * @param int      $order_id The order id.
		 * @param WC_Order $order    The order being saved.
		 *
		 * @return void
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- runs inside the HPOS order edit form save, admin-referer-checked by WooCommerce before woocommerce_process_shop_order_meta fires; values are compared/validated, not echoed.
		public function validate_hpos_form_meta_changes( $order_id, $order ) {
			if ( ! $this->hpos_enabled() || ! $order instanceof WC_Order ) {
				return;
			}

			$posted_meta = isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? wp_unslash( $_POST['meta'] ) : array();

			foreach ( $posted_meta as $meta_id => $posted ) {
				if ( ! isset( $posted['key'] ) || SS_Shipping_Order_Meta::META_AGENT_NO !== $posted['key'] ) {
					continue;
				}

				$stored = $this->get_order_meta_value_by_mid( $order, (int) $meta_id );

				if ( null === $stored || (string) $stored === (string) $posted['value'] ) {
					continue; // Unknown meta id or unchanged value - nothing to validate.
				}

				if ( $this->validate_and_store( $order_id, false, $posted['value'] ) !== true ) {
					// Revert the posted value so CustomMetaBox sees no change;
					// validate_and_store() already queued the admin error.
					$_POST['meta'][ $meta_id ]['value'] = $stored;
				}
			}

			// The "add new custom field" inputs of the same form.
			$new_key = isset( $_POST['metakeyinput'] ) ? wp_unslash( $_POST['metakeyinput'] ) : '';
			if ( SS_Shipping_Order_Meta::META_AGENT_NO === $new_key ) {
				$new_value = isset( $_POST['metavalue'] ) ? wp_unslash( $_POST['metavalue'] ) : '';

				if ( $this->validate_and_store( $order_id, false, $new_value ) !== true ) {
					unset( $_POST['metakeyinput'], $_POST['metavalue'] );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		/**
		 * Validate an agent-number change made through the HPOS Custom
		 * Fields box's inline AJAX update (WooCommerce's reimplementation
		 * of WP core's add-meta AJAX). Runs at priority 0, before
		 * WooCommerce's own handler; on a rejected number the error is
		 * sent back and the request ends, so the change never applies.
		 *
		 * @return void
		 */
		public function intercept_hpos_inline_meta_update() {
			if ( ! check_ajax_referer( 'add-meta', '_ajax_nonce-add-meta', false ) ) {
				return; // Let WooCommerce's own handler reject the nonce.
			}

			// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- nonce-checked above; parsing mirrors WooCommerce's CustomMetaBox::add_meta_ajax(), values are validated against the API, not echoed.
			$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;

			if ( ! empty( $_POST['meta'] ) && is_array( $_POST['meta'] ) ) { // Inline update of an existing row.
				$meta       = wp_unslash( $_POST['meta'] );
				$meta_id    = (int) key( $meta );
				$meta_key   = isset( $meta[ $meta_id ]['key'] ) ? $meta[ $meta_id ]['key'] : '';
				$meta_value = isset( $meta[ $meta_id ]['value'] ) ? $meta[ $meta_id ]['value'] : '';
			} else { // Adding a new row.
				$meta_key   = trim( sanitize_text_field( wp_unslash( $_POST['metakeyinput'] ?? '' ) ) );
				$meta_value = sanitize_text_field( wp_unslash( $_POST['metavalue'] ?? '' ) );
			}
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput

			if ( SS_Shipping_Order_Meta::META_AGENT_NO !== $meta_key || ! $order_id ) {
				return;
			}

			$validation = $this->validate_and_store( $order_id, true, $meta_value );

			if ( true === $validation ) {
				return; // Valid - let WooCommerce's handler apply the change.
			}

			$response = new WP_Ajax_Response(
				array(
					'what' => 'meta',
					'id'   => new WP_Error( 'ss_shipping_invalid_agent_no', is_string( $validation ) ? $validation : __( 'The agent number entered was not found.', 'smart-send-logistics' ) ),
				)
			);
			$response->send();
		}

		/**
		 * Mirror the legacy deleted_post_meta cascade on HPOS: when the
		 * agent-number row is deleted through the Custom Fields box's
		 * delete AJAX, delete the companion stored agent object too. Runs
		 * at priority 0; WooCommerce's own handler then deletes the row
		 * and saves the (cached, shared) order instance.
		 *
		 * @return void
		 */
		public function intercept_hpos_meta_delete() {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- ids are cast to int; the nonce is verified below with the same action WooCommerce's handler uses.
			$meta_id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput

			if ( ! $meta_id || ! $order_id || ! check_ajax_referer( "delete-meta_$meta_id", false, false ) ) {
				return; // Let WooCommerce's own handler reject the request.
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( SS_Shipping_Order_Meta::META_AGENT_NO === $this->get_order_meta_key_by_mid( $order, $meta_id ) ) {
				$this->repository->delete_pickup_point( $order_id );
			}
		}

		/**
		 * Call the API if needed and save the shipping agent address.
		 *
		 * @param int     $order_id             The order id.
		 * @param boolean $doing_ajax           Whether the caller reports errors itself (true: the error string is returned; false: it is queued on WC_Admin_Meta_Boxes).
		 * @param string  $ss_shipping_agent_no The entered agent number.
		 *
		 * @return bool|string Returns true for success and false or a string when failing.
		 */
		public function validate_and_store( $order_id, $doing_ajax, $ss_shipping_agent_no ) {

			$ss_shipping_method_id = $this->method_resolver->resolve_outbound( $order_id );

			if ( ! empty( $ss_shipping_method_id ) ) {
				$shipping_method_carrier = ( new SS_Shipping_Method_Code( $ss_shipping_method_id ) )->carrier();

				$order            = wc_get_order( $order_id );
				$shipping_address = $order->get_address( 'shipping' );

				if ( ! empty( $shipping_method_carrier ) && ! empty( $shipping_address['country'] ) ) {

					// API call to get agent info by agent no.
					$response = SS_SHIPPING_WC()->get_api_handle()->pickupPoints()->findByAgentNo( $shipping_method_carrier, $shipping_address['country'], $ss_shipping_agent_no );

					if ( $response->isSuccessful() ) {

						SS_Shipping_Logger::info(
							'Pickup point changed on order',
							array(
								'order_id' => $order_id,
								'agent_no' => $ss_shipping_agent_no,
								'carrier'  => $shipping_method_carrier,
							)
						);

						$this->repository->store_pickup_point_object(
							$order_id,
							$response->data()
						);
						return true;
					} else {

						SS_Shipping_Logger::warning(
							'Pickup point not found - agent number rejected',
							array(
								'order_id' => $order_id,
								'agent_no' => $ss_shipping_agent_no,
								'carrier'  => $shipping_method_carrier,
							)
						);

						$error_msg = sprintf(
							/* translators: %s: the pickup point agent number that was entered. */
							__(
								'The agent number entered, %s, was not found.',
								'smart-send-logistics'
							),
							$ss_shipping_agent_no
						);

						if ( $doing_ajax ) {
							return $error_msg;
						} else {
							WC_Admin_Meta_Boxes::add_error( $error_msg );
							return false;
						}
					}
				}
			}

			return false;
		}

		/**
		 * Find an order meta row's value by meta id.
		 *
		 * @param WC_Order $order   The order.
		 * @param int      $meta_id The meta id.
		 *
		 * @return mixed|null The value, or null when the order has no meta row with that id.
		 */
		protected function get_order_meta_value_by_mid( WC_Order $order, $meta_id ) {
			foreach ( $order->get_meta_data() as $meta ) {
				if ( (int) $meta->id === (int) $meta_id ) {
					return $meta->value;
				}
			}

			return null;
		}

		/**
		 * Find an order meta row's key by meta id.
		 *
		 * @param WC_Order $order   The order.
		 * @param int      $meta_id The meta id.
		 *
		 * @return string|null The key, or null when the order has no meta row with that id.
		 */
		protected function get_order_meta_key_by_mid( WC_Order $order, $meta_id ) {
			foreach ( $order->get_meta_data() as $meta ) {
				if ( (int) $meta->id === (int) $meta_id ) {
					return $meta->key;
				}
			}

			return null;
		}
	}

endif;
