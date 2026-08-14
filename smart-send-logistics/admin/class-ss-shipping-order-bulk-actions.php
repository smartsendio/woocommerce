<?php

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
 * Temporarily restricted to a single selected order: selecting more than
 * one order surfaces an error notice and processes nothing. Multi-order
 * bulk processing (and the combined-PDF download it produced) returns
 * with the Phase 7 async processing rebuild (#116).
 *
 * @package  SS_Shipping_Order_Bulk_Actions
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Bulk_Actions' ) ) :

	class SS_Shipping_Order_Bulk_Actions {

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
		 * Admin notices component used to flash one-time notices after
		 * label-generation actions.
		 *
		 * @var SS_Shipping_Admin_Notices
		 */
		protected SS_Shipping_Admin_Notices $admin_notices;

		/**
		 * @param SS_Shipping_Order_Meta          $order_meta          Order meta access component.
		 * @param SS_Shipping_Fulfillment_Service $fulfillment_service The fulfillment service.
		 * @param SS_Shipping_Admin_Notices      $admin_notices       Admin notices component.
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Fulfillment_Service $fulfillment_service, SS_Shipping_Admin_Notices $admin_notices ) {
			$this->order_meta          = $order_meta;
			$this->fulfillment_service = $fulfillment_service;
			$this->admin_notices       = $admin_notices;
		}

		/**
		 * Register bulk order actions.
		 *
		 * The hook callbacks are registered on the SS_Shipping_WC_Order facade
		 * (not this component) so that the callable seen by third-party code
		 * removing or inspecting the filters is unchanged.
		 *
		 * @see https://make.wordpress.org/core/2016/10/04/custom-bulk-actions/
		 * @since WordPress 4.7.0
		 * @return void
		 */
		public function register_bulk_order_actions( SS_Shipping_WC_Order $order_integration ) {
			// The HPOS CustomOrdersTableController exists since WC 6.4; the plugin's WC floor is 4.7.
			$hpos_enabled = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
				&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();

			if ( $hpos_enabled ) {
				// function not available wc_get_page_screen_id( 'shop-order' )
				add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $order_integration, 'add_bulk_order_actions' ) );
				add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $order_integration, 'handle_bulk_order_actions' ), 10, 3 );
			} else {
				// Index page is called 'edit-shop_order' and not just 'shop_order' as stated in the url
				add_filter( 'bulk_actions-edit-shop_order', array( $order_integration, 'add_bulk_order_actions' ) );
				add_filter( 'handle_bulk_actions-edit-shop_order', array( $order_integration, 'handle_bulk_order_actions' ), 10, 3 );
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
					// Temporary restriction: multi-order bulk processing (and the
					// combined-PDF download) returns with the Phase 7 rebuild (#116).
					array_push(
						$array_messages,
						array(
							'message' => __(
								'For now only a single order can be processed at a time. Please select one order - bulk label processing will be back in an upcoming release.',
								'smart-send-logistics'
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

						$ss_shipping_method_id = $this->order_meta->get_smart_send_method_id( $order_id );

						if ( ! empty( $ss_shipping_method_id ) ) {

							$result   = $return
								? $this->fulfillment_service->fulfill_return( $order_id, true )
								: $this->fulfillment_service->fulfill_outbound( $order_id, true );
							$response = $result->to_legacy_response_array();

							foreach ( $response as $key => $value ) {

								if ( isset( $value['success'] ) ) {
									$is_return_label = ! empty( $value['success']->woocommerce['return'] );
									$label_link      = $this->fulfillment_service->get_ss_shipping_label_link( $value['success']->woocommerce['label_url'], $is_return_label );

									if ( $is_return_label ) {
										$message = sprintf(
											/* translators: 1: WooCommerce order number, 2: link to download the return label. */
											__( 'Order #%1$s: Return label created by Smart Send: %2$s', 'smart-send-logistics' ),
											$order->get_order_number(),
											$label_link
										);
									} else {
										$message = sprintf(
											/* translators: 1: WooCommerce order number, 2: link to download the shipping label. */
											__( 'Order #%1$s: Shipping label created by Smart Send: %2$s', 'smart-send-logistics' ),
											$order->get_order_number(),
											$label_link
										);
									}

									array_push(
										$array_messages_success,
										array(
											'message' => $message,
											'type'    => 'success',
										)
									);
								} else {
									array_push(
										$array_messages_error,
										array(
											'message' => sprintf(
												/* translators: 1: WooCommerce order number, 2: error message. */
												__( 'Order #%1$s: %2$s', 'smart-send-logistics' ),
												$order->get_order_number(),
												$value['error']
											),
											'type'    => 'error',
										)
									);
								}
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
			if ( SS_SHIPPING_WC()->get_demo_mode_setting() ) {
				return array(
					'ss_shipping_label_bulk'  => __( 'DEMO MODE: Smart Send - Generate Labels', 'smart-send-logistics' ),
					'ss_shipping_return_bulk' => __( 'DEMO MODE: Smart Send - Generate Return Labels', 'smart-send-logistics' ),
				);
			}

			return array(
				'ss_shipping_label_bulk'  => __( 'Smart Send - Generate Labels', 'smart-send-logistics' ),
				'ss_shipping_return_bulk' => __( 'Smart Send - Generate Return Labels', 'smart-send-logistics' ),
			);
		}
	}

endif;
