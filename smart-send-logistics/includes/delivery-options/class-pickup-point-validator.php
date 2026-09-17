<?php

namespace Smart_Send\Delivery_Options;

use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception;
use Smart_Send\Shipping_Method\Method_Code;
use Smart_Send\Support\Logger;
use WC_Admin_Meta_Boxes;
use WC_Order;
use WP_Ajax_Response;
use WP_Error;

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
 * deleted). Extracted from Order_Meta (#139): API validation
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
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Pickup_Point_Validator {

	/**
	 * Order meta repository.
	 *
	 * @var Order_Meta
	 */
	protected Order_Meta $repository;

	/**
	 * Shipping method resolver.
	 *
	 * @var Method_Resolver
	 */
	protected Method_Resolver $method_resolver;

	/**
	 * Pickup point lookup (the shared find-by-agent-number call, #182).
	 *
	 * @var Pickup_Point_Lookup
	 */
	protected Pickup_Point_Lookup $pickup_point_lookup;

	/**
	 * @param Order_Meta               $repository          Order meta repository.
	 * @param Method_Resolver          $method_resolver     Shipping method resolver.
	 * @param Pickup_Point_Lookup|null $pickup_point_lookup Pickup point lookup (stateless; a fresh default is safe).
	 */
	public function __construct( Order_Meta $repository, Method_Resolver $method_resolver, ?Pickup_Point_Lookup $pickup_point_lookup = null ) {
		$this->repository          = $repository;
		$this->method_resolver     = $method_resolver;
		$this->pickup_point_lookup = null === $pickup_point_lookup ? new Pickup_Point_Lookup() : $pickup_point_lookup;
	}

	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Legacy post-table storage: the Custom Fields box updates meta
		// by meta id and deletes fire deleted_post_meta.
		add_filter( 'update_post_metadata_by_mid', array( $this, 'filter_update_agent_meta' ), 10, 4 );
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
		return wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
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

		// A repository write (a complete, typed pickup point - e.g. the
		// one a successful booking was submitted with, #182) is not an
		// admin edit: nothing to validate, and re-validating could
		// refuse the number after the agent object was already replaced.
		if ( Order_Meta::is_writing() || null !== $check ) {
			return $check;
		}

		if ( Order_Meta::META_AGENT_NO === $meta_key ) {
			// WordPress authorizes admin edits before this generic hook.
			// Do not impose a logged-in user on programmatic order writes.
			$meta = get_metadata_by_mid( 'post', $meta_id );
			if ( ! $meta || Order_Meta::META_AGENT_NO !== $meta->meta_key || ! wc_get_order( $meta->post_id ) instanceof WC_Order ) {
				return false;
			}
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

		// The repository deletes both pickup point keys itself when a
		// submitted clearing is persisted (#182); no cascade needed.
		if ( Order_Meta::is_writing() ) {
			return;
		}

		if ( Order_Meta::META_AGENT_NO == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #139 move.
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
		if ( ! $this->hpos_enabled() || ! $order instanceof WC_Order || $order->get_id() !== (int) $order_id ) {
			return;
		}

		// The full order form uses order-specific permissions, unlike
		// WooCommerce's stricter Custom Fields AJAX endpoints.
		if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$posted_meta = isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? wp_unslash( $_POST['meta'] ) : array();

		foreach ( $posted_meta as $meta_id => $posted ) {
			if ( ! isset( $posted['key'] ) || Order_Meta::META_AGENT_NO !== $posted['key'] ) {
				continue;
			}
			if ( Order_Meta::META_AGENT_NO !== $this->get_order_meta_key_by_mid( $order, (int) $meta_id ) || ! isset( $posted['value'] ) || ! is_scalar( $posted['value'] ) ) {
				unset( $_POST['meta'][ $meta_id ] );
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
		if ( Order_Meta::META_AGENT_NO === $new_key ) {
			$new_value = isset( $_POST['metavalue'] ) ? wp_unslash( $_POST['metavalue'] ) : '';

			if ( ! is_scalar( $new_value ) || $this->validate_and_store( $order_id, false, $new_value ) !== true ) {
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
		if ( ! check_ajax_referer( 'add-meta', '_ajax_nonce-add-meta', false ) || ! $this->can_edit_order_metadata() ) {
			return; // Let WooCommerce reject the request before any side effects.
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- nonce-checked above; parsing mirrors WooCommerce's CustomMetaBox::add_meta_ajax(), values are validated against the API, not echoed.
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$meta_id  = null;

		if ( ! empty( $_POST['meta'] ) && is_array( $_POST['meta'] ) ) { // Inline update of an existing row.
			$meta       = wp_unslash( $_POST['meta'] );
			$meta_id    = (int) key( $meta );
			$meta_key   = isset( $meta[ $meta_id ]['key'] ) ? $meta[ $meta_id ]['key'] : '';
			$meta_value = isset( $meta[ $meta_id ]['value'] ) ? $meta[ $meta_id ]['value'] : '';
		} else { // Adding a new row.
			$meta_key = trim( sanitize_text_field( wp_unslash( $_POST['metakeyinput'] ?? '' ) ) );
			if ( '' === $meta_key ) {
				$meta_key = trim( sanitize_text_field( wp_unslash( $_POST['metakeyselect'] ?? '' ) ) );
			}
			$meta_value = sanitize_text_field( wp_unslash( $_POST['metavalue'] ?? '' ) );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		if ( Order_Meta::META_AGENT_NO !== $meta_key || ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return; // WooCommerce reports the missing order.
		}

		// A posted key alone does not prove ownership or identify the row.
		// Stop invalid updates here: WooCommerce can otherwise create a row
		// from an unknown meta id, after the companion object was changed.
		if ( ! is_scalar( $meta_value ) || ( null !== $meta_id && Order_Meta::META_AGENT_NO !== $this->get_order_meta_key_by_mid( $order, $meta_id ) ) ) {
			$validation = __( 'The pickup point field no longer matches this order. Reload the page and try again.', 'smart-send-logistics' );
		} else {
			$validation = $this->validate_and_store( $order_id, true, $meta_value );
		}

		if ( true === $validation ) {
			if ( null === $meta_id ) {
				// WooCommerce 8.2 reads only metakeyinput when adding a row.
				// Give every supported handler the key we just validated.
				$_POST['metakeyinput'] = Order_Meta::META_AGENT_NO;
			}
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
	 * and persists its deletion of the number row.
	 *
	 * @return void
	 */
	public function intercept_hpos_meta_delete() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- ids are cast to int; the nonce is verified below with the same action WooCommerce's handler uses.
		$meta_id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		if ( ! $meta_id || ! $order_id || ! check_ajax_referer( "delete-meta_$meta_id", false, false ) || ! $this->can_edit_order_metadata() ) {
			return; // Let WooCommerce's own handler reject the request.
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( Order_Meta::META_AGENT_NO === $this->get_order_meta_key_by_mid( $order, $meta_id ) ) {
			$this->repository->delete_pickup_point( $order_id );
		}
	}

	/**
	 * Match WooCommerce CustomMetaBox's AJAX authorization before our
	 * priority-zero interceptors call the API or persist companion data.
	 * Generic repository writes remain independent of the current user.
	 *
	 * @return bool
	 */
	protected function can_edit_order_metadata(): bool {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_others_shop_orders' );
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
			$shipping_method_carrier = ( new Method_Code( $ss_shipping_method_id ) )->carrier();

			$order            = wc_get_order( $order_id );
			$shipping_address = $order->get_address( 'shipping' );

			if ( ! empty( $shipping_method_carrier ) && ! empty( $shipping_address['country'] ) ) {

				// Resolve the entered number through the shared lookup
				// (#182); the stored object is the API's agent object
				// exactly as received (to_object() reproduces it).
				try {
					$pickup_point = $this->pickup_point_lookup->find_by_agent_no( $shipping_method_carrier, (string) $shipping_address['country'], (string) $ss_shipping_agent_no );

					Logger::info(
						'Pickup point changed on order',
						array(
							'order_id' => $order_id,
							'agent_no' => $ss_shipping_agent_no,
							'carrier'  => $shipping_method_carrier,
						)
					);

					$this->repository->store_pickup_point_object(
						$order_id,
						$pickup_point->to_object()
					);
					return true;
				} catch ( Pickup_Point_Not_Found_Exception $e ) {

					Logger::warning(
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
