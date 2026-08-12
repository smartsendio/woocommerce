<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * WooCommerce Smart Send Shipping Order.
 *
 * @package  SS_Shipping_WC_Order
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_WC_Order' ) ) :

	class SS_Shipping_WC_Order {

		protected string $label_prefix = 'smart-send-label-';

		/**
		 * Admin notices component used to flash one-time notices after
		 * label-generation actions.
		 *
		 * @var SS_Shipping_Admin_Notices
		 */
		protected SS_Shipping_Admin_Notices $admin_notices;

		/**
		 * Init and hook in the integration.
		 */
		public function __construct() {
			$this->admin_notices = new SS_Shipping_Admin_Notices();
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
			$this->register_bulk_order_actions();

			// Display pending admin notices pushed by bulk actions.
			$this->admin_notices->init_hooks();
		}

		/**
		 * Register bulk order actions.
		 *
		 * @see https://make.wordpress.org/core/2016/10/04/custom-bulk-actions/
		 * @since WordPress 4.7.0
		 * @return void
		 */
		private function register_bulk_order_actions() {
			// The HPOS CustomOrdersTableController exists since WC 6.4; the plugin's WC floor is 4.7.
			$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
				&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? 'woocommerce_page_wc-orders' // function not available wc_get_page_screen_id( 'shop-order' )
				: 'edit-shop_order'; // Index page is called 'edit-shop_order' and not just 'shop_order' as stated in the url

			// An actions in the dropdown
			add_filter( "bulk_actions-{$screen}", array( $this, 'add_bulk_order_actions' ) );

			// Handling the form submission
			add_filter( "handle_bulk_actions-{$screen}", array( $this, 'handle_bulk_order_actions' ), 10, 3 );
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

						$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id );

						if ( ! empty( $ss_shipping_method_id ) ) {

							$response = $this->create_label_for_single_order_maybe_return( $order_id, $return, true );

							foreach ( $response as $key => $value ) {

								if ( isset( $value['success'] ) ) {
									$is_return_label = ! empty( $value['success']->woocommerce['return'] );
									$label_link      = $this->get_ss_shipping_label_link( $value['success']->woocommerce['label_url'], $is_return_label );

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
		 * Add the meta box for shipment info on the order page
		 */
		public function add_smart_send_order_meta_box() {
			// @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#audit-for-order-administration-screen-functions
			// The HPOS CustomOrdersTableController exists since WC 6.4; the plugin's WC floor is 4.7.
			$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

			add_meta_box(
				'woocommerce-ss-shipping-label',
				__( 'Smart Send Shipping', 'smart-send-logistics' ),
				array( $this, 'render_smart_send_order_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}

		/**
		 * Render the content of the order meta box.
		 *
		 * @param WP_Post|WC_Order $post_or_order_object
		 * @return void
		 */
		public function render_smart_send_order_meta_box( $post_or_order_object ) {
			/** @var WC_Order $order */
			$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

			// ... rest of the code. $post_or_order_object should not be used directly below this point´

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$order_id = $order->get_id();

			$shipping_ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

			$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id );

			// Only display Smart Shipping (SS) meta box is SS selected as shipping method OR free shipping is set to SS method.
			if ( ! $ss_shipping_method_id ) {
				SS_Shipping_Logger::debug( 'No Smart Send shipping method on order - skipping meta box content', array( 'order_id' => $order_id ) );

				echo '<p>' . esc_html__( 'Order placed with a shipping method that is not from the Smart Send plugin', 'smart-send-logistics' ) . '</p>';

				return;
			}

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour: the meta box HTML is built from translated strings and internally generated markup exactly as before the #43 move; escaping it is a behaviour change out of scope here.

			$ss_shipping_method_name = SS_SHIPPING_WC()->get_shipping_method_name_from_all_shipping_method_instances( $ss_shipping_method_id );

			// Get order agent object
			$ss_shipping_order_agent    = $this->get_ss_shipping_order_agent( $order_id );
			$ss_shipping_order_agent_no = $this->get_ss_shipping_order_agent_no( $order_id );

			echo '<div id="ss-shipping-label-form">';

			woocommerce_wp_hidden_input(
				array(
					'id'    => 'ss_shipping_label_nonce',
					'value' => wp_create_nonce( 'create-ss-shipping-label' ),
				)
			);

			$shipping_method_carrier = ucfirst( SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id ) );
			$shipping_method_type    = ucfirst( SS_SHIPPING_WC()->get_shipping_method_type( $ss_shipping_method_id ) );

			echo '<h3>' . __( 'Shipping Method', 'smart-send-logistics' ) . '</h3>';
			echo '<p>' . $ss_shipping_method_name . '</p>';

			// If debug is enabled then show the shipping method id and instance id.
			if ( isset( $shipping_ss_settings['ss_debug'] ) && 'yes' === $shipping_ss_settings['ss_debug'] ) {
				foreach ( $order->get_shipping_methods() as $method ) {
					echo '<pre>' . sprintf(
						/* translators: %s: shipping method id and instance id. */
						esc_html__( 'Debug id: %s', 'smart-send-logistics' ),
						esc_html( $method->get_method_id() . ':' . $method->get_instance_id() )
					) . '</pre>';
				}
			}

			echo '<p>' . sprintf(
				/* translators: %0.2f: total order weight in kg. */
				esc_html__( 'Weight: %0.2f kg', 'smart-send-logistics' ),
				floatval( $this->getOrderWeight( $order ) )
			) . '</p>';

			// Display Agent No. field if pickup-point shipping method selected.
			if ( false !== stripos( $shipping_method_type, 'agent' ) ) {
				echo '<h3>' . esc_html__( 'Pick-up Point', 'smart-send-logistics' ) . '</h3>';
				echo '<strong>' . sprintf(
					/* translators: %s: pick-up point agent number. */
					esc_html__( 'Agent No.: %s', 'smart-send-logistics' ),
					esc_html( (string) $ss_shipping_order_agent_no )
				) . '</strong>';
				echo wp_kses_post( $this->get_formatted_address( $ss_shipping_order_agent ) );
			}

			echo '<hr>';

			$parcels        = $this->get_ss_shipping_order_parcels( $order_id );
			$checked_attrib = '';
			$items_class    = 'hidden';
			$items          = '';
			if ( ! empty( $parcels ) ) {
				$checked_attrib = 'checked';
				$items_class    = '';

				foreach ( $parcels as $parcel ) {
					$dropdown = '<select data-id="' . $parcel['id'] . '" data-name="' . $parcel['name'] . '" name="ss_shipping_box_no[]"  autocomplete="off">';

					for ( $i = 1; $i <= 9; $i++ ) {
						$selected  = ( intval( $parcel['value'] ) == $i ) ? 'selected' : ''; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
						$dropdown .= '<option value="' . $i . '" ' . $selected . '>' . $i . '</option>';
					}
					$dropdown .= '</select>';

					$items .= '<tr><td width="80%">' . $parcel['name'] . '</td><td width="20%">' . $dropdown . '</td></tr>';
				}
			}

			echo '<input type="checkbox" id="ss-shipping-split-parcels" name="ss_shipping_split_parcels" autocomplete="off" value="1" ' . $checked_attrib . '> <strong>' . __(
				'Split into parcels',
				'smart-send-logistics'
			) . '</strong><br/>';

			echo '<div id="ss-shipping-order-items" class="' . $items_class . '"><table width="100%">';

			if ( ! empty( $parcels ) ) {
				echo $items;
			} else {
				foreach ( $order->get_items() as $item_id => $item ) {

					$product_id   = $item['product_id'];
					$product_name = $item['name'];
					// If variable product, add attribute to name
					if ( ! empty( $item['variation_id'] ) ) {
						$product_id = $item['variation_id'];

						$product_attribute = wc_get_product_variation_attributes( $item['variation_id'] );
						$product_name     .= ': ' . current( $product_attribute );

					}

					$item_qty = intval( $item['qty'] );
					for ( $ii = 1; $ii <= $item_qty; $ii++ ) {

						$dropdown = '<select data-id="' . $product_id . '" data-name="' . $product_name . '" name="ss_shipping_box_no[]"  autocomplete="off">';

						for ( $i = 1; $i <= 9; $i++ ) {
							$dropdown .= '<option value="' . $i . '">' . $i . '</option>';
						}

						$dropdown .= '</select>';

						echo '<tr><td width="80%">' . $product_name . '</td><td width="20%">' . $dropdown . '</td></tr>';
					}
				}
			}

			echo '</table></div>';

			echo '<hr>';
			echo '</p>';

			echo '<button id="ss-shipping-label-button" class="button button-primary button-save-form">' . SS_SHIPPING_BUTTON_LABEL_GEN . '</button><br><br>';
			echo '<button id="ss-shipping-return-label-button" class="button button-save-form">' . SS_SHIPPING_BUTTON_RETURN_LABEL_GEN . '</button>';

			// Load JS for AJAX calls
			$ss_label_data = array(
				'read_more'             => __( 'Read more', 'smart-send-logistics' ),
				'unique_error_id'       => __( 'Unique error id: ', 'smart-send-logistics' ),
				'download_label'        => __( 'Download shipping label', 'smart-send-logistics' ),
				'download_return_label' => __( 'Download return label', 'smart-send-logistics' ),
				'unexpected_error'      => __( 'Unexpected error', 'smart-send-logistics' ),
			);
			wp_enqueue_script(
				'ss-shipping-label-js',
				SS_SHIPPING_PLUGIN_DIR_URL . '/admin/js/ss-shipping-label.js',
				array(),
				SS_SHIPPING_VERSION,
				false
			);
			wp_localize_script( 'ss-shipping-label-js', 'ss_label_data', $ss_label_data );

			echo '</div>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Return HTML formatted agent address
		 *
		 * @param object $ss_shipping_order_agent
		 * @return string
		 */
		protected function get_formatted_address( $ss_shipping_order_agent ) {

			if ( empty( $ss_shipping_order_agent ) ) {
				return '';
			}

			return '<p class="ss_agent_address">' . $ss_shipping_order_agent->company . '</br>' . $ss_shipping_order_agent->address_line1 . '</br>' . $ss_shipping_order_agent->postal_code . ' ' . $ss_shipping_order_agent->city . '</p>';
		}

		/**
		 * Return ordered Smart Send shipping method, OR Free Shipping linked to Smart Send shipping method, otherwise empty string
		 *
		 * @param integer $order_id     Post object or post ID of the order.
		 * @param boolean $return       Whether or not the label is return (true) or normal (false)
		 * @return string               Unique Smart Send name of shipping method. Example 'postnord_agent'
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_smart_send_method_id( $order_id, $return = false ) {
			$order = wc_get_order( $order_id );//Accepts Post object or post ID of the order.

			if ( ! $order ) {
				return '';
			}

			// Get shipping id to make sure its either Smart Send, Free Shipping or vConnect
			$order_shipping_methods = $order->get_shipping_methods();
			if ( ! empty( $order_shipping_methods ) ) {

				foreach ( $order_shipping_methods as $item_id => $item ) {
					// Array access on 'WC_Order_Item_Shipping' works because it implements backwards compatibility
					$shipping_method_id = ! empty( $item['method_id'] ) ? esc_html( $item['method_id'] ) : null;

					// If Smart Send found, return id
					if ( stripos( $shipping_method_id, 'smart_send_shipping' ) !== false ) {
						if ( $return ) {
							return array(
								'smart_send_return_method' => $item['smart_send_return_method'],
								'smart_send_auto_generate_return_label' => $item['smart_send_auto_generate_return_label'],
							);
						} else {
							return $item['smart_send_shipping_method'];
						}
					} elseif ( stripos( $shipping_method_id, 'free_shipping' ) !== false ) {
							// If free shipping, then filter the shipping method to the correct Smart Send method

							$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

						if ( ! empty( $ss_settings['shipping_method_for_free_shipping'] ) ) {
							return $ss_settings['shipping_method_for_free_shipping'];
						}
					} elseif ( stripos( $shipping_method_id, 'vconnect_postnord' ) !== false ) {
						// If vConnect, then filter the shipping method to the correct Smart Send method
						if ( $return ) {
							return 'postnord_returndropoff';
						} elseif ( stripos( $shipping_method_id, '_pickup' ) !== false ) {
								return 'postnord_agent';
						} elseif ( stripos( $shipping_method_id, '_dpd' ) !== false ) {
							return 'postnord_homedelivery';
						} elseif ( stripos( $shipping_method_id, '_commercial' ) !== false ) {
							return 'postnord_commercial';
						} elseif ( stripos( $shipping_method_id, '_privatehome' ) !== false ) {
							$order                = wc_get_order( $order_id );
							$vc_aio_options       = $order->get_meta( '_vc_aio_options', true );
							$flex_delivery        = false;
							$flex_delivery_option = false;
							$day_delivery         = false;
							if ( is_array( $vc_aio_options ) ) {
								foreach ( $vc_aio_options as $option ) {
									// Check if shipping method has flexDelivery enabled (the parcel can be left somewhere)
									// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose array_search; tightening is a behaviour change out of scope for the #43 move.
									if ( array_search(
										'flexDelivery',
										array_column( $option, 'value' )
									) !== false ) {
										$flex_delivery = true;
									}
									// Check if shipping method has dayDelivery enabled (customer will receive an SMS with possibility to choose)
									if ( array_search( 'dayDelivery', array_column( $option, 'value' ) ) !== false ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose array_search; tightening is a behaviour change out of scope for the #43 move.
										$day_delivery = true;
									}
									// A flexDelivery option is chosen
									if ( ! empty( $option['typeId']['value'] ) && 'flexDelivery' == $option['typeId']['value'] // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
										&& ! empty( $option['addressText']['value'] ) ) {
										$flex_delivery_option = true;
									}
								}
							}
							if ( ! $flex_delivery && ! $day_delivery && ! $flex_delivery_option ) {
								return 'postnord_homedelivery';
							} elseif ( ! $flex_delivery && ! $flex_delivery_option && $day_delivery ) {
								return 'postnord_flexhome';
							} elseif ( $flex_delivery && ! $flex_delivery_option && ! $day_delivery ) {
								return 'postnord_doorstep';
								// The chosen flexdelivy option must be used to tell PostNord where the parcel should be left
							} elseif ( $flex_delivery && $flex_delivery_option && ! $day_delivery ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedElseif -- pre-existing intentionally empty branch documenting an unhandled vConnect case.
								// The chosen flexdelivy option must be used to tell PostNord where the parcel should be left
							}
						}
					}
				}
			}

			return '';
		}

		/**
		 * Agent meta data updated
		 *
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

			if ( 'ss_shipping_order_agent_no' == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				$meta      = get_metadata_by_mid( 'post', $meta_id );
				$object_id = $meta->post_id;
				if ( $this->save_shipping_agent( $object_id, true, $meta_value ) !== true ) {
					// the agent was not found so do NOT save the new agent_no
					$check = false;
				}
			}

			return $check;
		}

		/**
		 * Agent meta deleted
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

			if ( 'ss_shipping_order_agent_no' == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				$this->delete_ss_shipping_order_agent( $object_id );
			}
		}

		/**
		 * Call the API if needed and save the shipping agent address
		 *
		 * @param $post_id
		 * @param $doing_ajax
		 * @param $ss_shipping_agent_no
		 *
		 * @return bool|string         Returns true for success and false or a string when failing
		 */
		protected function save_shipping_agent( $post_id, $doing_ajax, $ss_shipping_agent_no ) {

			$ss_shipping_method_id = $this->get_smart_send_method_id( $post_id );

			if ( ! empty( $ss_shipping_method_id ) ) {
				$shipping_method_carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id );

				$order            = wc_get_order( $post_id );
				$shipping_address = $order->get_address( 'shipping' );

				if ( ! empty( $shipping_method_carrier ) && ! empty( $shipping_address['country'] ) ) {

					// API call to get agent info by agent no.
					if ( SS_SHIPPING_WC()->get_api_handle()->getAgentByAgentNo( $shipping_method_carrier, $shipping_address['country'], $ss_shipping_agent_no ) ) {

						SS_Shipping_Logger::info(
							'Pick-up point changed on order',
							array(
								'order_id' => $post_id,
								'agent_no' => $ss_shipping_agent_no,
								'carrier'  => $shipping_method_carrier,
							)
						);

						$this->save_ss_shipping_order_agent(
							$post_id,
							SS_SHIPPING_WC()->get_api_handle()->getData()
						);
						return true;
					} else {

						SS_Shipping_Logger::warning(
							'Pick-up point not found - agent number rejected',
							array(
								'order_id' => $post_id,
								'agent_no' => $ss_shipping_agent_no,
								'carrier'  => $shipping_method_carrier,
							)
						);

						$error_msg = sprintf(
							/* translators: %s: the pick-up point agent number that was entered. */
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
			$this->save_ss_shipping_order_parcels( $order_id, $parcels );

			$response = $this->create_label_for_single_order_maybe_return( $order_id, $return, false );

			wp_send_json( $response );
			wp_die();
		}

		/**
		 * Create label for a single WooCommerce order and maybe auto generate return label
		 *
		 * @param int $order_id Order ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 * @param boolean $setting_save_order_note Whether or not to save an order note with information about label
		 *
		 * @return array
		 */
		protected function create_label_for_single_order_maybe_return(
			$order_id,
			$return = false, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing method signature, kept for backwards compatibility.
			$setting_save_order_note = true
		) {

			$reponse_arr = array();

			$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id, true );

			// If creating normal label and auto generate return flag is enabled, create both
			if ( ! $return &&
				isset( $ss_shipping_method_id['smart_send_auto_generate_return_label'] ) &&
				'yes' == $ss_shipping_method_id['smart_send_auto_generate_return_label'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.

				// Create the normal label
				$response = $this->create_label_for_single_order( $order_id, false, $setting_save_order_note );
				array_push( $reponse_arr, $response );

				// We're only creating the return label if the normal label creation is successful.
				if ( isset( $response['success']->woocommerce ) ) {
					// Create the return label
					$response = $this->create_label_for_single_order( $order_id, true, $setting_save_order_note );
					array_push( $reponse_arr, $response );
				}
			} else {
				$response = $this->create_label_for_single_order( $order_id, $return, $setting_save_order_note );
				array_push( $reponse_arr, $response );
			}

			return $reponse_arr;
		}

		/**
		 * Create label for a single WooCommerce order
		 *
		 * @param int $order_id Order ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 * @param boolean $setting_save_order_note Whether or not to save an order note with information about label
		 *
		 * @return array
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing method signature, kept for backwards compatibility.
		protected function create_label_for_single_order( $order_id, $return = false, $setting_save_order_note = true ) {
			// Load WC Order
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return array(
					'error' => sprintf(
						/* translators: %s: WooCommerce order id. */
						__( 'Order #%s: The order could not be found', 'smart-send-logistics' ),
						$order_id
					),
				);
			}

			$ss_order_api = new SS_Shipping_Shipment( $order, $this );

			if ( $ss_order_api->make_single_shipment_api_call( $return ) ) {

				//The request was successful, lets update WooCommerce
				$response = $ss_order_api->get_shipping_data();

				if ( SS_SHIPPING_WC()->get_setting_save_shipping_labels_in_uploads() ) {
					try {
						// Save the PDF file
						$label_url = $this->save_label_file(
							$response->shipment_id,
							$response->pdf->base_64_encoded,
							$return
						);
					} catch ( Exception $e ) {
						return array( 'error' => $e->getMessage() );
					}
				}

				// Get the label link
				$label_url = $response->pdf->link;

				// save order meta data
				$this->save_ss_shipment_id_in_order_meta( $order_id, $response->shipment_id, $return );

				SS_Shipping_Logger::info(
					$return ? 'Return shipping label created' : 'Shipping label created',
					array(
						'order_id'    => $order_id,
						'shipment_id' => $response->shipment_id,
						'carrier'     => isset( $response->carrier_name ) ? $response->carrier_name : null,
					)
				);

				// Get formatted order comment
				$response->woocommerce['label_url']  = $label_url;
				$response->woocommerce['order_note'] = $this->get_formatted_order_note_with_label_and_tracking(
					$order_id,
					$response,
					$return
				);
				$response->woocommerce['return']     = $return;

				// Save order note
				if ( $setting_save_order_note ) {
					/*
					 * Filter the order comment that is saved. The order comment can be seen in the WooCommerce backend
					 *
					 * @param string order note containing tracking link and link to pdf label
					 * @param WC_Order object
					 * @param boolean $return Whether or not the label is return (true) or normal (false)
					 */
					$order_note = apply_filters(
						'smart_send_shipping_label_comment',
						$response->woocommerce['order_note'],
						$order,
						$return
					);
					$order->add_order_note( $order_note, 0, true );

					SS_Shipping_Logger::info( 'Order note with label and tracking added', array( 'order_id' => $order_id ) );
				}

				// Add tracking info to "WooCommerce Shipment Tracking" plugin.
				foreach ( $response->parcels as $parcel ) {
					// Only add tracking info to "WooCommerce Shipment Tracking" plugin for non-return parcels.
					if ( ! $return ) {
						$this->save_tracking_in_shipment_tracking(
							$order_id,
							$parcel->tracking_code,
							$parcel->tracking_link,
							$response->carrier_name,
							null
						);

						SS_Shipping_Logger::info(
							'Tracking number stored',
							array(
								'order_id'      => $order_id,
								'tracking_code' => $parcel->tracking_code,
								'carrier'       => $response->carrier_name,
							)
						);
					}
				}

				// Set order status after label generation
				// Important to update AFTER saving meta fields and tracking information (otherwise not included in email via Shipment Tracking)
				if ( ! $return ) {
					$this->set_order_status_after_label_generated( $order );
				}

				// Action when a shipping label has been created
				do_action( 'smart_send_shipping_label_created', $order_id, $response );

				// return the success data
				return array(
					'success'  => $response,
					'shipment' => $ss_order_api->get_shipment(),
				);
			} else {
				// Something failed. Let's return them, so the error can be shown to the user
				return array( 'error' => $ss_order_api->get_error_msg() );
			}
		}

		/**
		 * If set to change order after order generated, update order status
		 */
		protected function set_order_status_after_label_generated( $order ) {

			$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

			if ( ! empty( $ss_settings['order_status'] ) ) {
				$order->update_status( $ss_settings['order_status'] );
			}
		}

		/**
		 * Get tracking details from returned shipment details
		 */
		protected function get_tracking_details( $shipment ) {
			$tracking_array = array();
			foreach ( $shipment->parcels as $parcel ) {
				$tracking_array[ $parcel->parcel_internal_id ] = array(
					'carrier_code'  => $shipment->carrier_code,
					'carrier_name'  => $shipment->carrier_name,
					'tracking_code' => $parcel->tracking_code,
					/*
					 * Filter the tracking link
					 *
					 * @param string | tracking link
					 * @param string | carrier code
					 */
					'tracking_link' => apply_filters(
						'smart_send_tracking_url',
						$parcel->tracking_link,
						$shipment->carrier_code
					),
				);
			}
			return $tracking_array;
		}

		/**
		 * Get a formatted string containing link to PDF label, tracking code and tracking link.
		 * This note is inserted in the order comment.
		 *
		 * @param int $order_id Order ID
		 * @param mixed $api_shipment_response response for API call
		 * @param boolean $return true for return labels and false for normal labels (default)
		 *
		 * @return string HTML formatted note
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- pre-existing method signature, kept for backwards compatibility.
		protected function get_formatted_order_note_with_label_and_tracking( $order_id, $api_shipment_response, $return ) {

			$tracking_note = sprintf(
				'<label>%1$s: </label>%2$s',
				$return ? __( 'Return shipping label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' ),
				$this->get_ss_shipping_label_link( $api_shipment_response->woocommerce['label_url'], $return )
			);

			foreach ( $api_shipment_response->parcels as $parcel ) {
				$tracking_note .= sprintf(
					'<br><label>%1$s: </label><a href="%2$s" target="_blank">%3$s</a>',
					__( 'Tracking number', 'smart-send-logistics' ),
					$parcel->tracking_link,
					$parcel->tracking_code
				);
			}

			return $tracking_note;
		}

		/**
		 * Save label file in "uploads" folder
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- pre-existing method signature, kept for backwards compatibility.
		protected function save_label_file( $shipment_id, $label_data, $return ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-existing behaviour: exception messages are returned to the admin AJAX response as data, not printed as HTML; escaping is a behaviour change out of scope for the #43 move.

			if ( empty( $shipment_id ) ) {
				throw new Exception( __( 'Shipment id is empty', 'smart-send-logistics' ) );
			}

			if ( empty( $label_data ) ) {
				throw new Exception( __( 'Label data empty', 'smart-send-logistics' ) );
			}

			$label_data_decoded = base64_decode( $label_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the Smart Send API delivers the label PDF base64-encoded; this is transport decoding, not obfuscation.
			$file_ret           = wp_upload_bits(
				$this->get_label_name_from_shipment_id( $shipment_id ),
				null,
				$label_data_decoded,
				null
			);

			if ( empty( $file_ret['url'] ) ) {
				throw new Exception(
					__(
						'Label file cannot be saved',
						'smart-send-logistics'
					)
				); //This exception is not caught
			}
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

			return $file_ret['url'];
		}

		protected function get_label_url_from_shipment_id( $shipment_id ) {
			$upload_path = wp_upload_dir();
			return $upload_path['url'] . '/' . $this->get_label_name_from_shipment_id( $shipment_id );
		}

		protected function get_label_path_from_shipment_id( $shipment_id ) {
			$upload_path = wp_upload_dir();
			return $upload_path['path'] . '/' . $this->get_label_name_from_shipment_id( $shipment_id );
		}

		protected function get_label_name_from_shipment_id( $shipment_id ) {
			if ( $this->label_prefix ) {
				$shipment_id = $this->label_prefix . $shipment_id;
			}
			return $shipment_id . '.pdf';
		}


		/**
		 * Saves the parcels input to post_meta
		 *
		 * @param int $order_id
		 * @param array $parcels
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_parcels( $order_id, $parcels ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( 'ss_shipping_order_parcels', $parcels );
			$order->save();
		}

		/**
		 * Gets parcels input from post_meta
		 *
		 * @param int $order_id
		 * @param array $parcels
		 *
		 * @return mixed Parcels if present, false otherwise
		 */
		public function get_ss_shipping_order_parcels( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
			return $order->get_meta( 'ss_shipping_order_parcels', true );
		}


		/**
		 * Saves the label agent no to post_meta.
		 *
		 * @param int $order_id Order ID
		 * @param array $agent_no Agent No.
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_agent_no( $order_id, $agent_no ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( 'ss_shipping_order_agent_no', $agent_no );
			$order->save();
		}

		/*
		 * Gets agent no from the post meta array for an order
		 *
		 * @param int  $order_id  Order ID
		 *
		 * @return Agent No
		 */
		public function get_ss_shipping_order_agent_no( $order_id ) {
			// Fetch agent_no from meta field saved by Smart Send
			$order = wc_get_order( $order_id );

			// wc_get_order() returns false when the order does not exist, e.g. when
			// WooCommerce's email preview fires the order-details hooks with a
			// placeholder order ID. See issue #60.
			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$ss_agent_number = $order->get_meta( 'ss_shipping_order_agent_no', true );
			if ( $ss_agent_number ) {
				// Return the agent_no found
				return $ss_agent_number;
			} else {
				// No Smart Send agent_no was found, check if the order has a vConnect agent_no
				$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
				if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
					return $vc_aio_meta['addressId']['value'];
				} else {
					return null;
				}
			}
		}

		/**
		 * Saves the agent object to post_meta.
		 *
		 * @param int $order_id Order ID
		 * @param array $agent Agent Object
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_agent( $order_id, $agent ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( '_ss_shipping_order_agent', $agent );
			$order->save();
		}

		/**
		 * Delete shippng agent object
		 *
		 * @param $order_id
		 */
		public function delete_ss_shipping_order_agent( $order_id ) {
			$order = wc_get_order( $order_id );

			// There are situations where the order has been deleted and cannot be found.
			// We should gracefully handle this situation of failing to load the order.
			if ( ! $order ) {
				SS_Shipping_Logger::error( 'Failed to load WooCommerce order when deleting pick-up point meta - skipping', array( 'order_id' => $order_id ) );

				return;
			}

			$order->delete_meta_data( '_ss_shipping_order_agent' );
		}

		/*
		 * Gets agent object from the post meta array for an order
		 *
		 * @param int  $order_id  Order ID
		 *
		 * @return Agent Object
		 */
		public function get_ss_shipping_order_agent( $order_id ) {
			// Fetch agent info from meta field saved by Smart Send
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$ss_agent_info = $order->get_meta( '_ss_shipping_order_agent', true );
			if ( $ss_agent_info ) {
				// Return the agent_no found
				return $ss_agent_info;
			} else {
				// No Smart Send agent_no was found, check if the order has a vConnect agent_no
				$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
				if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
					return (object) array(
						'agent_no'      => isset( $vc_aio_meta['addressId']['value'] ) ? $vc_aio_meta['addressId']['value'] : null,
						'company'       => isset( $vc_aio_meta['name']['value'] ) ? $vc_aio_meta['name']['value'] : null,
						'address_line1' => isset( $vc_aio_meta['addressText']['value'] ) ? $vc_aio_meta['addressText']['value'] : null,
						'address_line2' => null,
						'city'          => isset( $vc_aio_meta['city']['value'] ) ? $vc_aio_meta['city']['value'] : null,
						'postal_code'   => isset( $vc_aio_meta['postcode']['value'] ) ? $vc_aio_meta['postcode']['value'] : null,
						'country'       => isset( $vc_aio_meta['country']['value'] ) ? $vc_aio_meta['country']['value'] : null,
					);
				} else {
					return null;
				}
			}
		}

		/**
		 * Saves the Shipment ID to post_meta.
		 *
		 * @param int $order_id Order ID
		 * @param string $shipment_id Shipment ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 *
		 * @return void
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function save_ss_shipment_id_in_order_meta( $order_id, $shipment_id, $return ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			if ( $return ) {
				$order->update_meta_data( '_ss_shipping_return_label_id', $shipment_id );
			} else {
				$order->update_meta_data( '_ss_shipping_label_id', $shipment_id );
			}
			$order->save();
		}

		/*
		 * Gets label URL post meta array for an order
		 *
		 * @param int  $order_id  Order ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 *
		 * @return string URL label link
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_label_url_from_order_id( $order_id, $return ): string {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return '';
			}
			if ( $return ) {
				$shipment_id = $order->get_meta( '_ss_shipping_return_label_id', true );
			} else {
				$shipment_id = $order->get_meta( '_ss_shipping_label_id', true );
			}
			return $this->get_label_url_from_shipment_id( $shipment_id );
		}

		/**
		 * Get formatted label link
		 *
		 * @param string $url label url
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 *
		 * @return string html label link
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_ss_shipping_label_link( $url, $return ) {
			if ( $return ) {
				$message = __( 'Download return shipping label', 'smart-send-logistics' );
			} else {
				$message = __( 'Download shipping label', 'smart-send-logistics' );
			}
			return '<a href="' . $url . '" target="_blank">' . $message . '</a>';
		}


		/**
		 * Save tracking number in Shipment Tracking
		 *
		 * @param int $order_id Order ID
		 * @param string $tracking_number Unique tracking code for parcel
		 * @param string $tracking_url Url for tracking parcel delivery
		 * @param string $provider Carrier provider
		 * @param string $date_shipped Shipping data in format YYYY-mm-dd
		 *
		 * @return void
		 */
		public function save_tracking_in_shipment_tracking(
			$order_id,
			$tracking_number,
			$tracking_url,
			$provider = 'Smart Send',
			$date_shipped = null
		) {

			// The WooCommerce Shipment Tracking plugin is optional.
			if ( function_exists( 'wc_st_add_tracking_number' ) ) {
				wc_st_add_tracking_number( $order_id, $tracking_number, $provider, $date_shipped, $tracking_url );
			}
		}

		/**
		 * Prevents data being copied to subscription renewals
		 */
		public function woocommerce_subscriptions_renewal_order_meta_query( $order_meta_query ) {
			$order_meta_query .= " AND `meta_key` NOT IN ( '_ss_shipping_label' )";

			return $order_meta_query;
		}

		/**
		 * Return Smart Send bulk actions
		 */
		private function get_bulk_actions() {
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
			$combo_path     = $this->get_label_path_from_shipment_id( $combo_name );
			$combo_url      = '';

			if ( file_exists( $combo_path ) ) {
				$combo_url = $this->get_label_url_from_shipment_id( $combo_name );
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
							$combo_url = $this->save_label_file( $combo_name, $response->pdf->base_64_encoded, null );
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

		/**
		 * Get an orders total weight.
		 *
		 * @param WC_Order $order The order.
		 * @return float weight in kg
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- pre-existing method name, kept for backwards compatibility.
		protected function getOrderWeight( $order ) {
			$weight_total = 0;

			// Get order item specific data.
			$ordered_items = $order->get_items();
			if ( ! empty( $ordered_items ) ) {
				foreach ( $ordered_items as $key => $item ) {
					$product = wc_get_product( $item['product_id'] );
					if ( ! empty( $item['variation_id'] ) ) {
						$product_variation = wc_get_product( $item['variation_id'] );
					} else {
						$product_variation = $product;
					}

					if ( $product_variation ) { // null|false if unable to load product.
						$product_weight = round( wc_get_weight( $product_variation->get_weight(), 'kg' ), 2 );
						if ( $product_weight ) {
							$weight_total += ( $item['qty'] * $product_weight );
						}
					}
				}
			}
			return $weight_total;
		}
	}

endif;
