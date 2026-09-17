<?php

namespace Smart_Send\Admin;

use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Fulfillment\Fulfillment_Service;
use Smart_Send\Support\Admin_Notices;
use Smart_Send\Support\Settings;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send bulk order actions.
 *
 * Owns the "Generate Labels" / "Generate Return Labels" bulk actions on
 * the Orders screen and the admin notices summarising the outcome.
 *
 * Limited to a single selected order in 9.0 (#173): selecting more than one
 * order surfaces an error notice and processes nothing. Multi-order bulk
 * printing (and the combined-PDF download it produced) returns as a rebuilt
 * implementation (#115); merchants who need the old flow are pointed to the
 * last 8.x release.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Order_Bulk_Actions {

	/**
	 * WordPress.org page listing the plugin's previous versions, linked
	 * from the more-than-one-order message.
	 */
	const PREVIOUS_VERSIONS_URL = 'https://wordpress.org/plugins/smart-send-logistics/advanced/';

	/**
	 * Shipping method resolver.
	 *
	 * @var Method_Resolver
	 */
	protected Method_Resolver $method_resolver;

	/**
	 * The fulfillment service running the label workflow.
	 *
	 * @var Fulfillment_Service
	 */
	protected Fulfillment_Service $fulfillment_service;

	/**
	 * Admin notices component used to flash one-time notices after
	 * label-generation actions.
	 *
	 * @var Admin_Notices
	 */
	protected Admin_Notices $admin_notices;

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * @param Method_Resolver     $method_resolver     Shipping method resolver.
	 * @param Fulfillment_Service $fulfillment_service The fulfillment service.
	 * @param Admin_Notices      $admin_notices       Admin notices component.
	 * @param Settings|null       $settings            Typed plugin settings reader (stateless; a fresh default is safe).
	 */
	public function __construct( Method_Resolver $method_resolver, Fulfillment_Service $fulfillment_service, Admin_Notices $admin_notices, ?Settings $settings = null ) {
		$this->method_resolver     = $method_resolver;
		$this->fulfillment_service = $fulfillment_service;
		$this->admin_notices       = $admin_notices;
		$this->settings            = null === $settings ? new Settings() : $settings;
	}

	/**
	 * Register this component's hooks.
	 *
	 * @see https://make.wordpress.org/core/2016/10/04/custom-bulk-actions/
	 * @since WordPress 4.7.0
	 * @return void
	 */
	public function register_hooks() {
		$hpos_enabled = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();

		if ( $hpos_enabled ) {
			// function not available wc_get_page_screen_id( 'shop-order' )
			add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_order_actions' ) );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_order_actions' ), 10, 3 );
		} else {
			// Index page is called 'edit-shop_order' and not just 'shop_order' as stated in the url
			add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_order_actions' ) );
			add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_order_actions' ), 10, 3 );
		}
	}

	public function add_bulk_order_actions( $bulk_actions ) {
		return array_merge( $bulk_actions, $this->get_bulk_actions() );
	}

	/**
	 * Processed the selected bulk action.
	 *
	 * Note that this function is not called if no items are selected in the table.
	 *
	 * @param string $sendback
	 * @param string $doaction
	 * @param array $items
	 * @return string|void
	 */
	public function handle_bulk_order_actions( string $sendback, string $doaction, array $items ) {
		if ( ! in_array( $doaction, array_keys( $this->get_bulk_actions() ) ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
			return;
		}

		$array_messages         = array();
		$array_messages_success = array();
		$array_messages_error   = array();

		if ( 'ss_shipping_label_bulk' === $doaction || 'ss_shipping_return_bulk' === $doaction ) {

			// Determine if the request is for a return label
			$return = ( 'ss_shipping_return_bulk' === $doaction );

			$orders_count = count( $items );

			if ( $orders_count < 1 ) {
				array_push(
					$array_messages,
					array(
						'message' => __(
							'No orders selected, please select the orders to create labels for.',
							'smart-send-logistics'
						),
						'type'    => 'error',
					)
				);
			} elseif ( $orders_count > 1 ) {
				// Single-order limit in 9.0 (#173): multi-order bulk printing
				// (and the combined-PDF download) returns with #115.
				array_push(
					$array_messages,
					array(
						'message' => sprintf(
							/* translators: %s: URL of the WordPress.org page listing the plugin's previous versions. */
							__(
								'Bulk printing of multiple orders is not available in version 9.0.0. We are building a much better version, and it is coming soon. Please select a single order, or create the label from the order page. If you need the old bulk printing, you can <a href="%s" target="_blank">downgrade to version 8.x</a>.',
								'smart-send-logistics'
							),
							esc_url( self::PREVIOUS_VERSIONS_URL )
						),
						'type'    => 'error',
					)
				);
			} else {

				// Ensure the selected orders have a Smart Send Shipping method
				foreach ( $items as $order_id ) {
					$order = wc_get_order( $order_id );

					if ( ! $order instanceof WC_Order ) {
						array_push(
							$array_messages_error,
							array(
								'message' => sprintf(
									/* translators: %s: WooCommerce order id. */
									__( 'Order #%s: The order could not be found', 'smart-send-logistics' ),
									$order_id
								),
								'type'    => 'error',
							)
						);
						continue;
					}

					$ss_shipping_method_id = $this->method_resolver->resolve_outbound( $order );

					if ( ! empty( $ss_shipping_method_id ) ) {

						$result = $return
							? $this->fulfillment_service->fulfill_return( $order_id, true )
							: $this->fulfillment_service->fulfill_outbound( $order_id, true );

						// One success notice per booked shipment, linking every
						// document (and showing every code) it produced (#177).
						foreach ( $result->shipments() as $shipment ) {
							$outputs = $this->fulfillment_service->get_shipment_outputs_html( $shipment, ', ', false );

							if ( $shipment->is_return() ) {
								$message = sprintf(
									/* translators: 1: WooCommerce order number, 2: link to download the return label. */
									__( 'Order #%1$s: Return label created by Smart Send: %2$s', 'smart-send-logistics' ),
									$order->get_order_number(),
									$outputs
								);
							} else {
								$message = sprintf(
									/* translators: 1: WooCommerce order number, 2: link to download the shipping label. */
									__( 'Order #%1$s: Shipping label created by Smart Send: %2$s', 'smart-send-logistics' ),
									$order->get_order_number(),
									$outputs
								);
							}

							array_push(
								$array_messages_success,
								array(
									'message' => $message,
									'type'    => 'success',
								)
							);
						}

						// A booked shipment whose local copy could not be
						// saved is fulfilled with a warning (#182).
						foreach ( $result->get_warning_messages() as $warning_message ) {
							array_push(
								$array_messages_error,
								array(
									'message' => sprintf(
										/* translators: 1: WooCommerce order number, 2: warning message. */
										__( 'Order #%1$s: %2$s', 'smart-send-logistics' ),
										$order->get_order_number(),
										$warning_message
									),
									'type'    => 'warning',
								)
							);
						}

						foreach ( $result->get_error_messages() as $error_message ) {
							array_push(
								$array_messages_error,
								array(
									'message' => sprintf(
										/* translators: 1: WooCommerce order number, 2: error message. */
										__( 'Order #%1$s: %2$s', 'smart-send-logistics' ),
										$order->get_order_number(),
										$error_message
									),
									'type'    => 'error',
								)
							);
						}
					} else {
						array_push(
							$array_messages_error,
							array(
								'message' => sprintf(
									/* translators: %s: WooCommerce order number. */
									__( 'Order #%s: The selected order did not include a Send Smart shipping method', 'smart-send-logistics' ),
									$order->get_order_number()
								),
								'type'    => 'error',
							)
						);
					}
				}

				$array_messages = array_merge( $array_messages, $array_messages_success, $array_messages_error );

			}

			if ( ! empty( $array_messages ) ) {
				$this->admin_notices->push( $array_messages );
				$sendback = $this->admin_notices->add_notices_query_arg( $sendback );
			}
		}

		return $sendback;
	}

	/**
	 * Return Smart Send bulk actions
	 */
	protected function get_bulk_actions() {
		return array(
			'ss_shipping_label_bulk'  => __( 'Smart Send - Generate Labels', 'smart-send-logistics' ),
			'ss_shipping_return_bulk' => __( 'Smart Send - Generate Return Labels', 'smart-send-logistics' ),
		);
	}
}
