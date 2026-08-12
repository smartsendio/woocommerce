<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send bulk order actions.
 *
 * Owns the "Generate Labels" / "Generate Return Labels" bulk actions on
 * the Orders screen, including the combined-PDF creation and the admin
 * notices summarising the outcome.
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
		 * Label creation component.
		 *
		 * @var SS_Shipping_Label_Creator
		 */
		protected SS_Shipping_Label_Creator $label_creator;

		/**
		 * Admin notices component used to flash one-time notices after
		 * label-generation actions.
		 *
		 * @var SS_Shipping_Admin_Notices
		 */
		protected SS_Shipping_Admin_Notices $admin_notices;

		/**
		 * @param SS_Shipping_Order_Meta    $order_meta    Order meta access component.
		 * @param SS_Shipping_Label_Creator $label_creator Label creation component.
		 * @param SS_Shipping_Admin_Notices $admin_notices Admin notices component.
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Label_Creator $label_creator, SS_Shipping_Admin_Notices $admin_notices ) {
			$this->order_meta    = $order_meta;
			$this->label_creator = $label_creator;
			$this->admin_notices = $admin_notices;
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
			$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
				&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? 'woocommerce_page_wc-orders' // function not available wc_get_page_screen_id( 'shop-order' )
				: 'edit-shop_order'; // Index page is called 'edit-shop_order' and not just 'shop_order' as stated in the url

			// An actions in the dropdown
			add_filter( "bulk_actions-{$screen}", array( $order_integration, 'add_bulk_order_actions' ) );

			// Handling the form submission
			add_filter( "handle_bulk_actions-{$screen}", array( $order_integration, 'handle_bulk_order_actions' ), 10, 3 );
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
			$array_shipment_ids     = array();

			if ( 'ss_shipping_label_bulk' === $doaction || 'ss_shipping_return_bulk' === $doaction ) {

				// Determine if the request is for a return label
				$return = ( 'ss_shipping_return_bulk' === $doaction );

				// Trigger an admin notice to have the user manually open a print window
				$is_error     = false;
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
				} elseif ( $orders_count > 5 ) {
					array_push(
						$array_messages,
						array(
							'message' => __(
								'For now it is not possible to create labels for more than 5 orders at a time.',
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

							$response = $this->label_creator->create_label_for_single_order_maybe_return( $order_id, $return, true );

							foreach ( $response as $key => $value ) {

								if ( isset( $value['success'] ) ) {
									$is_return_label = ! empty( $value['success']->woocommerce['return'] );
									$label_link      = $this->label_creator->get_ss_shipping_label_link( $value['success']->woocommerce['label_url'], $is_return_label );

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

									array_push(
										$array_shipment_ids,
										array(
											'shipment_id' => $value['success']->shipment_id,
											'order_id'    => $order->get_order_number(),
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

					$array_combo_messages = $this->create_combo_file(
						$array_messages_success,
						$array_messages_error,
						$array_shipment_ids
					);

					$array_messages = array_merge( $array_messages, $array_combo_messages );

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

		/**
		 * Create Combo File
		 */
		protected function create_combo_file( $array_messages_success, $array_messages_error, $array_shipment_ids ) {

			$array_messages = array();
			$combo_name     = $this->get_combo_label_file_name( $array_shipment_ids );
			$combo_path     = $this->label_creator->get_label_path_from_shipment_id( $combo_name );
			$combo_url      = '';

			if ( file_exists( $combo_path ) ) {
				$combo_url = $this->label_creator->get_label_url_from_shipment_id( $combo_name );
			} elseif ( count( $array_shipment_ids ) > 1 ) {
				// If more than one smart send shipment label created, then create combo labels.
				// Create combined label with successful shipments
				$combined_shipments = SS_SHIPPING_WC()->get_api_handle()->combineLabelsForShipments(
					wp_list_pluck(
						$array_shipment_ids,
						'shipment_id'
					)
				);

				if ( SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {

					$response = SS_SHIPPING_WC()->get_api_handle()->getData();
					if ( SS_SHIPPING_WC()->get_setting_save_shipping_labels_in_uploads() ) {
						try {
							// Save the PDF file and save order meta data
							$combo_url = $this->label_creator->save_label_file( $combo_name, $response->pdf->base_64_encoded, null );
						} catch ( Exception $e ) {
							array_push(
								$array_messages,
								array(
									'message' => $e->getMessage(),
									'type'    => 'error',
								)
							);
						}
					}

					// Get the combined label link
					$combo_url = $response->pdf->link;

				} else {
					array_push(
						$array_messages,
						array(
							'message' => sprintf(
								/* translators: %s: error message from the Smart Send API. */
								__( 'Error combining shipping labels: %s', 'smart-send-logistics' ),
								SS_SHIPPING_WC()->get_api_handle()->getErrorString()
							),
							'type'    => 'error',
						)
					);
				}
			}

			if ( ! empty( $combo_url ) ) {
				$order_id_list = wp_list_pluck( $array_shipment_ids, 'order_id' );
				$order_id_list = array_unique( $order_id_list );
				$label_count   = count( $order_id_list );
				$order_ids_str = sprintf(
					/* translators: %s: list of order numbers, each prefixed with #. */
					__( 'Orders: #%s', 'smart-send-logistics' ),
					implode( ', #', $order_id_list )
				);

				array_push(
					$array_messages,
					array(
						'message' => sprintf(
							/* translators: 1: number of orders, 2: URL of the combined PDF file. */
							_n(
								'Shipping labels created by Smart Send for %1$s order: <a href="%2$s" target="_blank">Download combined pdf</a>',
								'Shipping labels created by Smart Send for %1$s orders: <a href="%2$s" target="_blank">Download combined pdf</a>',
								$label_count,
								'smart-send-logistics'
							),
							$label_count,
							$combo_url
						) . '<br/>' . $order_ids_str,
						'type'    => 'success',
					)
				);

				$array_messages = array_merge( $array_messages, $array_messages_error );
			} else {
				$array_messages = array_merge( $array_messages, $array_messages_success, $array_messages_error );
			}

			return $array_messages;
		}

		/**
		 * Create file name from shipment ids, separated by "-" and hash it
		 */
		protected function get_combo_label_file_name( $shipment_ids ) {
			$shipment_id_list = wp_list_pluck( $shipment_ids, 'shipment_id' );
			$shipment_ids_str = implode( '-', $shipment_id_list );
			return hash( 'sha256', $shipment_ids_str );
		}
	}

endif;
