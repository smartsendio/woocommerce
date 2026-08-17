<?php
/**
 * WooCommerce Checkout Block integration for Smart Send.
 *
 * The entry-point controller for the block-checkout surface: registers the
 * scripts built into build/ (see the repo-root src/ and webpack.config.js)
 * with the WooCommerce Blocks integration registry. In this skeleton (PR 1
 * of issue #74) the scripts are near-empty placeholders; PR 2 adds the
 * Store API extensions and PR 3 the pickup point block UI.
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
		 * Register the WordPress hooks this component owns. Called once by
		 * the composition root (hook registration convention: constructors
		 * have zero side effects).
		 *
		 * @return void
		 */
		public function register_hooks(): void {
			add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
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
		 * registry (key: the integration name). Minimal for the skeleton;
		 * PR 3 adds the display settings the block needs.
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
