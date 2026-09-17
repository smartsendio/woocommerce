<?php
/**
 * Smart Send API test-connection feature.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Shipping_Method;

use Smart_Send\Support\API_Factory;
use Smart_Send\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The "Validate API Token" feature on the shipping method settings
 * screen, consolidated into one class (#140): the admin script + nonce
 * (previously enqueued from Method), the AJAX handler
 * (previously on the plugin singleton) and the button's inline onclick
 * (referenced from the settings field definition).
 */
class Test_Connection {

	/**
	 * The nonce action shared by the script localization and the AJAX
	 * handler's referer check.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'ss-test-connection';

	/**
	 * API client factory.
	 *
	 * @var API_Factory
	 */
	protected API_Factory $api_factory;

	/**
	 * The factory is stateless, so a fresh default is safe for ad-hoc
	 * construction.
	 *
	 * @param API_Factory|null $api_factory API client factory.
	 */
	public function __construct( ?API_Factory $api_factory = null ) {
		$this->api_factory = null === $api_factory ? new API_Factory() : $api_factory;
	}

	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );
		add_action( 'wp_ajax_ss_test_connection', array( $this, 'handle_ajax' ) );
	}

	/**
	 * The inline onclick attribute of the "Validate API Token" settings
	 * button, kept next to the script that defines ssTestConnection().
	 *
	 * @param string $field_id The settings field id the button posts for.
	 *
	 * @return string
	 */
	public static function button_onclick( $field_id ): string {
		return "ssTestConnection('#" . $field_id . "');";
	}

	/**
	 * Enqueue the test-connection script (with its AJAX url and nonce)
	 * on the WooCommerce settings screen only.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public function load_admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' != $hook ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #140 move.
			// Only applies to WC Settings panel.
			return;
		}

		$test_con_data = array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'test_connection_nonce' => wp_create_nonce( self::NONCE_ACTION ),
			'validating_connection' => __( 'Validating connection...', 'smart-send-logistics' ),
		);

		wp_enqueue_script(
			'smart-send-test-connection',
			SS_SHIPPING_PLUGIN_DIR_URL . '/admin/js/ss-shipping-test-connection.js',
			array( 'jquery' ),
			SS_SHIPPING_VERSION,
			false
		);
		wp_localize_script( 'smart-send-test-connection', 'ss_test_connection_obj', $test_con_data );

		// The stylesheet carrying the .ss-connection result styling this
		// feature's script injects - owned here instead of a blanket
		// admin enqueue on the plugin singleton (#140).
		wp_enqueue_style( 'ss-shipping-admin-css', SS_SHIPPING_PLUGIN_DIR_URL . '/admin/css/ss-shipping-admin.css', array(), SS_SHIPPING_VERSION );
	}

	/**
	 * The wp_ajax_ss_test_connection handler: validate the saved API
	 * token against the Smart Send account endpoint and report the
	 * outcome (also to the log - see #92).
	 *
	 * @return void
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'test_connection_nonce' );

		try {
			$response = $this->api_factory->create()->account()->get_authenticated_user();

			$connection_msg = sprintf(
				/* translators: 1: email address of the connected Smart Send account, 2: website of the connected Smart Send account. */
				__( 'API Token verified: Connected to Smart Send as %1$s from %2$s', 'smart-send-logistics' ),
				$response->data()->email,
				$response->data()->website
			);
			$error = 0;
		} catch ( \Smart_Send\API\Exceptions\HTTP_Client_Exception $e ) {
			$connection_msg = sprintf(
				/* translators: %s: error message returned by the Smart Send API. */
				__( 'API Token validation failed: %s. Make sure to save the settings before validating.', 'smart-send-logistics' ),
				$e->getMessage()
			);
			$error = 1;
		}

		if ( $error ) {
			Logger::error( 'API token connection test failed', array( 'message' => $connection_msg ) );
		} else {
			Logger::info( 'API token connection test succeeded', array( 'message' => $connection_msg ) );
		}

		wp_send_json(
			array(
				'message'    => $connection_msg,
				'error'      => $error,
				'button_txt' => __( 'Validate API Token', 'smart-send-logistics' ),
			)
		);

		wp_die();
	}
}
