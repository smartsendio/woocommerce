<?php

namespace Smart_Send\Admin;

use Smart_Send\Support\Logger;
use WC_Order;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send order screen meta box.
 *
 * Registers and renders the "Smart Send" meta box on the
 * WooCommerce order edit screen (legacy post-based and HPOS). The box is
 * rendered from the one state object Order_Fulfillment_Presenter
 * builds (#182): the presenter renders the server-side first paint and the
 * same state is inlined as window.smartSendOrderFulfillment for the React
 * app (src/order-fulfillment/, built into build/order-fulfillment/), which
 * mounts on #smart-send-fulfillment and talks to the REST controller - no
 * admin-ajax, no page reload.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Order_Meta_Box {

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
	 * @var Order_Fulfillment_Presenter
	 */
	protected Order_Fulfillment_Presenter $presenter;

	/**
	 * @param Order_Fulfillment_Presenter $presenter The meta box presenter.
	 */
	public function __construct( Order_Fulfillment_Presenter $presenter ) {
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
	 * Render the content of the order meta box: the mount point the app
	 * renders into, with the state inlined for the client.
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
			Logger::debug( 'No Smart Send shipping method on order - the meta box offers a method choice', array( 'order_id' => $order->get_id() ) );
		}

		$this->enqueue_assets( $state );

		echo $this->render_mount(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_mount() is static markup with no dynamic values.
	}

	/**
	 * The mount point the app renders into, holding a placeholder until
	 * it does.
	 *
	 * The box has ONE renderer, and it is the app (#182 shipped a PHP
	 * copy of the whole box as the interim UI, before the React client
	 * existed; two renderers of the same markup drifted apart - see the
	 * "Open settings" button). PHP's job is the state (inlined above,
	 * so the app paints on its first frame without a round trip) and
	 * this element.
	 *
	 * A <fieldset>, not a <form>: WooCommerce's order screen wraps every
	 * meta box in its own <form> (post.php's #post, the HPOS screen's
	 * #order) and the HTML parser drops a nested <form> start tag, which
	 * would leave no #smart-send-fulfillment element to mount on. It is
	 * rendered disabled; the app owns data-ss-state/data-ss-app and the
	 * disabled attribute from the moment it mounts.
	 *
	 * The placeholder rows are three grey bars and a button, sized like
	 * the real box so its arrival does not shift the page. They stay
	 * invisible for the first 200ms (the stylesheet's delayed fade-in),
	 * so the usual sub-frame mount shows no flash at all and only a
	 * genuinely slow load ever shows them.
	 *
	 * @return string HTML
	 */
	protected function render_mount(): string {
		$rows = str_repeat(
			'<div class="smart-send-fulfillment__placeholder-row"><span></span><span></span></div>',
			3
		);

		return '<fieldset id="smart-send-fulfillment" class="smart-send-fulfillment__form" data-ss-state="loading" data-ss-app="loading" aria-busy="true" disabled>'
			. '<div class="smart-send-fulfillment__placeholder" aria-hidden="true">'
			. $rows
			. '<div class="smart-send-fulfillment__placeholder-button"></div>'
			. '</div></fieldset>';
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
	 * @param array $state The state (see Order_Fulfillment_Presenter::state()).
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
