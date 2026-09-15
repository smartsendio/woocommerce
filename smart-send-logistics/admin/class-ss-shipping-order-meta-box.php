<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send order screen meta box.
 *
 * Registers and renders the "Smart Send" meta box on the
 * WooCommerce order edit screen (legacy post-based and HPOS). The box is
 * rendered from the one state object SS_Shipping_Order_Fulfillment_Presenter
 * builds (#182): the presenter renders the server-side first paint and the
 * same state is inlined as window.smartSendOrderFulfillment for the React
 * app (src/order-fulfillment/, built into build/order-fulfillment/), which
 * mounts on #smart-send-fulfillment and talks to the REST controller - no
 * admin-ajax, no page reload.
 *
 * @package  SS_Shipping_Order_Meta_Box
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Meta_Box' ) ) :

	class SS_Shipping_Order_Meta_Box {

		/**
		 * The meta box id.
		 */
		const META_BOX_ID = 'woocommerce-ss-shipping-label';

		/**
		 * The script handle of the meta box app: the built
		 * build/order-fulfillment/index.js bundle (src/order-fulfillment/),
		 * with the state riding as an inline script before it.
		 */
		const STATE_SCRIPT_HANDLE = 'ss-order-fulfillment';

		/**
		 * The stylesheet handle of the meta box app
		 * (build/order-fulfillment/style-index.css).
		 */
		const STYLE_HANDLE = 'ss-order-fulfillment';

		/**
		 * The built entry inside build/, without extension.
		 */
		const BUILD_ENTRY = 'order-fulfillment/index';

		/**
		 * The meta box presenter (state + form rendering).
		 *
		 * @var SS_Shipping_Order_Fulfillment_Presenter
		 */
		protected SS_Shipping_Order_Fulfillment_Presenter $presenter;

		/**
		 * @param SS_Shipping_Order_Fulfillment_Presenter $presenter The meta box presenter.
		 */
		public function __construct( SS_Shipping_Order_Fulfillment_Presenter $presenter ) {
			$this->presenter = $presenter;
		}

		/**
		 * Register this component's hooks.
		 *
		 * @return void
		 */
		public function register_hooks() {
			add_action( 'add_meta_boxes', array( $this, 'add_smart_send_order_meta_box' ), 20 );
		}

		/**
		 * Add the meta box for shipment info on the order page
		 */
		public function add_smart_send_order_meta_box() {
			// @see https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#audit-for-order-administration-screen-functions
			$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

			add_meta_box(
				self::META_BOX_ID,
				__( 'Smart Send', 'smart-send-logistics' ),
				array( $this, 'render_smart_send_order_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}

		/**
		 * Render the content of the order meta box: the presenter's form for
		 * the order's state, with the state inlined for the client.
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

			$state = $this->presenter->state( $order );

			if ( null === $state['delivery_details']['shipping_method'] ) {
				SS_Shipping_Logger::debug( 'No Smart Send shipping method on order - the meta box offers a method choice', array( 'order_id' => $order->get_id() ) );
			}

			$this->enqueue_assets( $state );

			// The presenter escapes every value it renders (no phpcs:disable).
			echo $this->presenter->render_form( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SS_Shipping_Order_Fulfillment_Presenter::render_form() escapes every value on output.
		}

		/**
		 * The meta box owns its assets (#182): the built React app
		 * (src/order-fulfillment/, registered from its generated
		 * *.asset.php so the externals - wp-element, wp-components,
		 * wp-api-fetch, wp-i18n, wp-url - are declared as dependencies), its
		 * stylesheet plus WordPress' components stylesheet, the script
		 * translations, and the state the app hydrates from
		 * (window.smartSendOrderFulfillment). Enqueued from the render
		 * callback, so only the order screen (HPOS and legacy) ever loads
		 * them.
		 *
		 * @param array $state The state (see SS_Shipping_Order_Fulfillment_Presenter::state()).
		 *
		 * @return void
		 */
		protected function enqueue_assets( array $state ) {
			$asset = require SS_SHIPPING_PLUGIN_DIR_PATH . '/build/' . self::BUILD_ENTRY . '.asset.php';

			wp_register_script(
				self::STATE_SCRIPT_HANDLE,
				SS_SHIPPING_PLUGIN_DIR_URL . '/build/' . self::BUILD_ENTRY . '.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
			wp_set_script_translations( self::STATE_SCRIPT_HANDLE, 'smart-send-logistics', SS_SHIPPING_PLUGIN_DIR_PATH . '/lang' );

			// The state as inline JSON. JSON_HEX_TAG keeps a "</script>" inside
			// a value (e.g. a product name) from closing the script element.
			wp_add_inline_script(
				self::STATE_SCRIPT_HANDLE,
				'window.smartSendOrderFulfillment = ' . wp_json_encode( $state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';',
				'before'
			);
			wp_enqueue_script( self::STATE_SCRIPT_HANDLE );

			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style(
				self::STYLE_HANDLE,
				SS_SHIPPING_PLUGIN_DIR_URL . '/build/order-fulfillment/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}

endif;
