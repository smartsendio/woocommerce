<?php
/**
 * WooCommerce Checkout Block integration for Smart Send.
 *
 * The entry-point controller for the block-checkout surface: registers the
 * scripts built into build/ (see the repo-root src/ and webpack.config.js)
 * with the WooCommerce Blocks integration registry, and opts the pickup
 * point block into WooCommerce's data-attribute rendering so the block's
 * saved attributes (editable title/description) reach the frontend
 * component. The Store API extensions feeding the block live in
 * SS_Shipping_Store_Api (#74).
 *
 * This file references Automattic\WooCommerce\Blocks classes at class
 * definition time, so the composition root loads it lazily (like
 * SS_Shipping_WC_Method) - this plugin loads before WooCommerce. The
 * interface itself is guaranteed by the plugin's WC 5.0 floor.
 *
 * @package  SS_Shipping_WC
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;

if ( ! class_exists( 'SS_Shipping_Block_Checkout' ) ) :

	class SS_Shipping_Block_Checkout implements IntegrationInterface {

		/**
		 * The integration name handed to the Blocks integration registry.
		 * Also becomes the Store API extension namespace (PRs 2-3 of #74) -
		 * matches the smart_send_* hook-prefix vocabulary, deliberately not
		 * the plugin slug.
		 */
		const INTEGRATION_NAME = 'smart-send';

		/**
		 * Script handle for the checkout-frontend bundle.
		 */
		const HANDLE_FRONTEND = 'smart-send-pickup-point-block-frontend';

		/**
		 * Script handle for the block-editor bundle.
		 */
		const HANDLE_EDITOR = 'smart-send-pickup-point-block-editor';

		/**
		 * The pickup point block's name (src/pickup-point-block/block.json).
		 */
		const BLOCK_NAME = 'smart-send/pickup-point-block';

		/**
		 * Register the WordPress hooks this component owns. Called once by
		 * the composition root (hook registration convention: constructors
		 * have zero side effects).
		 *
		 * @return void
		 */
		public function register_hooks(): void {
			add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
			add_filter( '__experimental_woocommerce_blocks_add_data_attributes_to_block', array( $this, 'add_block_to_data_attribute_allowlist' ) );
		}

		/**
		 * Opt the pickup point block into WooCommerce's render_block
		 * data-attribute pass (BlockTypesController::add_data_attributes()):
		 * the saved placeholder div gains data-block-name plus one data-*
		 * attribute per saved block attribute, which is how the frontend
		 * maps the markup to the React component and how the merchant's
		 * edited title/description reach it as props. Only woocommerce/*
		 * blocks get this by default; third-party blocks register here.
		 *
		 * @param array $blocks Block names opted into data attributes.
		 * @return array
		 */
		public function add_block_to_data_attribute_allowlist( $blocks ) {
			$blocks   = is_array( $blocks ) ? $blocks : array();
			$blocks[] = self::BLOCK_NAME;

			return $blocks;
		}

		/**
		 * Register this integration with the Checkout block's integration
		 * registry. The registry then calls initialize() and enqueues the
		 * handles from get_script_handles()/get_editor_script_handles() on
		 * block-checkout pages.
		 *
		 * @param IntegrationRegistry $integration_registry The Checkout block's registry.
		 * @return void
		 */
		public function register_integration( IntegrationRegistry $integration_registry ) {
			$integration_registry->register( $this );
		}

		/**
		 * The name of the integration.
		 *
		 * @return string
		 */
		public function get_name() {
			return self::INTEGRATION_NAME;
		}

		/**
		 * Register the built scripts with their generated *.asset.php
		 * dependencies and version. Registration only - the Blocks
		 * integration registry decides when to enqueue.
		 *
		 * @return void
		 */
		public function initialize() {
			$this->register_built_script( self::HANDLE_FRONTEND, 'pickup-point-block/frontend' );
			$this->register_built_script( self::HANDLE_EDITOR, 'pickup-point-block/index' );
		}

		/**
		 * Script handles enqueued in the checkout-frontend context.
		 *
		 * @return string[]
		 */
		public function get_script_handles() {
			return array( self::HANDLE_FRONTEND );
		}

		/**
		 * Script handles enqueued in the block-editor context.
		 *
		 * @return string[]
		 */
		public function get_editor_script_handles() {
			return array( self::HANDLE_EDITOR );
		}

		/**
		 * Data made available to the scripts via the wc.wcSettings data
		 * registry (key: the integration name). Deliberately minimal: the
		 * cart extension data (SS_Shipping_Store_Api::cart_extension_data())
		 * is the single data channel the block consumes - display settings
		 * ride it so they update live like everything else.
		 *
		 * @return array
		 */
		public function get_script_data() {
			return array(
				'pluginVersion' => SS_SHIPPING_VERSION,
			);
		}

		/**
		 * Register one built bundle from build/ using the dependencies and
		 * content-hash version from its generated *.asset.php.
		 *
		 * @param string $handle Script handle to register.
		 * @param string $entry  Entry path inside build/, without extension.
		 * @return void
		 */
		protected function register_built_script( string $handle, string $entry ): void {
			$asset = require SS_SHIPPING_PLUGIN_DIR_PATH . '/build/' . $entry . '.asset.php';

			wp_register_script(
				$handle,
				SS_SHIPPING_PLUGIN_DIR_URL . '/build/' . $entry . '.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}
	}

endif;
