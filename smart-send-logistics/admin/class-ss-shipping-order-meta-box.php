<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send order screen meta box.
 *
 * Registers and renders the "Smart Send Shipping" meta box on the
 * WooCommerce order edit screen (legacy post-based and HPOS).
 *
 * @package  SS_Shipping_Order_Meta_Box
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Meta_Box' ) ) :

	class SS_Shipping_Order_Meta_Box {

		/**
		 * Order meta repository.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Shipping method resolver.
		 *
		 * @var SS_Shipping_Method_Resolver
		 */
		protected SS_Shipping_Method_Resolver $method_resolver;

		/**
		 * Pickup point display formatter.
		 *
		 * @var SS_Shipping_Pickup_Point_Formatter
		 */
		protected SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter;

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * @param SS_Shipping_Order_Meta             $order_meta             Order meta repository.
		 * @param SS_Shipping_Method_Resolver        $method_resolver        Shipping method resolver.
		 * @param SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter Pickup point display formatter.
		 * @param SS_Shipping_Settings|null          $settings               Typed plugin settings reader (stateless; a fresh default is safe).
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Method_Resolver $method_resolver, SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter, ?SS_Shipping_Settings $settings = null ) {
			$this->order_meta             = $order_meta;
			$this->method_resolver        = $method_resolver;
			$this->pickup_point_formatter = $pickup_point_formatter;
			$this->settings               = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			$this->define_button_labels();

			add_action( 'add_meta_boxes', array( $this, 'add_smart_send_order_meta_box' ), 20 );
		}

		/**
		 * Define the label-generation button label constants.
		 *
		 * Kept as global constants (rather than class properties) because
		 * the meta box's own HTML-building code below reads them directly,
		 * matching the surrounding markup's style.
		 */
		protected function define_button_labels() {
			SS_SHIPPING_WC()->define(
				'SS_SHIPPING_BUTTON_LABEL_GEN',
				$this->settings->demo_mode()
					? __( 'DEMO MODE: Generate label', 'smart-send-logistics' )
					: __( 'Generate label', 'smart-send-logistics' )
			);
			SS_SHIPPING_WC()->define(
				'SS_SHIPPING_BUTTON_RETURN_LABEL_GEN',
				$this->settings->demo_mode()
					? __( 'DEMO MODE: Generate return label', 'smart-send-logistics' )
					: __( 'Generate return label', 'smart-send-logistics' )
			);
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

			$ss_shipping_method_id = $this->method_resolver->resolve_outbound( $order );

			// Only display Smart Shipping (SS) meta box is SS selected as shipping method OR free shipping is set to SS method.
			if ( ! $ss_shipping_method_id ) {
				SS_Shipping_Logger::debug( 'No Smart Send shipping method on order - skipping meta box content', array( 'order_id' => $order_id ) );

				echo '<p>' . esc_html__( 'Order placed with a shipping method that is not from the Smart Send plugin', 'smart-send-logistics' ) . '</p>';

				return;
			}

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour: the meta box HTML is built from translated strings and internally generated markup exactly as before the #43 move; escaping it is a behaviour change out of scope here.

			$ss_shipping_method_name = SS_SHIPPING_WC()->get_shipping_method_name_from_all_shipping_method_instances( $ss_shipping_method_id );

			// The stored delivery configuration (pickup point + parcel plan).
			$delivery_details           = $this->order_meta->read( $order );
			$ss_shipping_order_agent    = null === $delivery_details->get_pickup_point() ? null : $delivery_details->get_pickup_point()->to_object();
			$ss_shipping_order_agent_no = null === $delivery_details->get_pickup_point() ? null : $delivery_details->get_pickup_point()->get_agent_no();

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
			if ( $this->settings->debug_log() ) {
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
				floatval( $this->get_order_weight( $order ) )
			) . '</p>';

			// Display Agent No. field if pickup-point shipping method selected.
			if ( false !== stripos( $shipping_method_type, 'agent' ) ) {
				echo '<h3>' . esc_html__( 'Pickup Point', 'smart-send-logistics' ) . '</h3>';
				echo '<strong>' . sprintf(
					/* translators: %s: pickup point agent number. */
					esc_html__( 'Agent No.: %s', 'smart-send-logistics' ),
					esc_html( (string) $ss_shipping_order_agent_no )
				) . '</strong>';
				echo wp_kses_post( $this->pickup_point_formatter->format_admin_block( $ss_shipping_order_agent ) );
			}

			echo '<hr>';

			$parcels        = null === $delivery_details->get_parcel_plan() ? array() : $delivery_details->get_parcel_plan()->to_box_rows();
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
		 * Get an orders total weight.
		 *
		 * @param WC_Order $order The order.
		 * @return float weight in kg
		 */
		protected function get_order_weight( $order ) {
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
