<?php
/**
 * Validate the saved Smart Send connection and render its settings feedback.
 *
 * @package Smart_Send
 */

namespace Smart_Send\Shipping_Method;

use Smart_Send\API\Exceptions\HTTP_Client_Exception;
use Smart_Send\API\Exceptions\Request_Exception;
use Smart_Send\API\Exceptions\Unexpected_Response_Exception;
use Smart_Send\Support\API_Credentials;
use Smart_Send\Support\API_Factory;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection feedback belongs to the current settings save only.
 * A plain page view never makes an API request or replays an old success.
 */
class Test_Connection {

	/** @var API_Factory */
	protected API_Factory $api_factory;

	/** @var array|null Result of this request's settings save. */
	private ?array $result = null;

	/**
	 * @param API_Factory|null $api_factory API client factory.
	 */
	public function __construct( ?API_Factory $api_factory = null ) {
		$this->api_factory = null === $api_factory ? new API_Factory() : $api_factory;
	}

	/**
	 * Register the settings assets.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );
	}

	/**
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function load_admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'smart-send-test-connection',
			SS_SHIPPING_PLUGIN_DIR_URL . '/admin/js/ss-shipping-test-connection.js',
			array( 'jquery' ),
			SS_SHIPPING_VERSION,
			false
		);
		wp_enqueue_style( 'ss-shipping-admin-css', SS_SHIPPING_PLUGIN_DIR_URL . '/admin/css/ss-shipping-admin.css', array(), SS_SHIPPING_VERSION );
	}

	/**
	 * Validate after WooCommerce has persisted the settings, including saves
	 * where no values changed. WooCommerce owns permission and nonce checks.
	 *
	 * @return void
	 */
	public function validate_saved_token(): void {
		$credentials = API_Credentials::from_settings( new Settings() );
		if ( null === $credentials->api_token() || '' === trim( $credentials->api_token() ) ) {
			$this->result = array(
				'error'   => true,
				'message' => __( 'Enter an API Token and save the settings to connect to Smart Send.', 'smart-send-logistics' ),
			);
			return;
		}

		try {
			$response = $this->api_factory->create()->account()->get_authenticated_user();
			if ( 200 !== (int) $response->status_code() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; response bodies are never rendered.
				throw new Unexpected_Response_Exception( $response );
			}

			$this->result = array(
				'error'   => false,
				'message' => __( 'Connected to Smart Send', 'smart-send-logistics' ),
			);
			Logger::info( 'API token connection test succeeded', array( 'message' => $this->result['message'] ) );
		} catch ( HTTP_Client_Exception $e ) {
			$status   = $e instanceof Request_Exception ? (int) $e->get_response()->status_code() : 0;
			$messages = array(
				401 => __( 'The API Token is invalid. Enter a valid API Token and save the settings again.', 'smart-send-logistics' ),
				403 => __( 'Smart Send denied access. Check the team access and subscription in Smart Send.', 'smart-send-logistics' ),
				404 => __( 'No team is registered for this webshop in Smart Send. Register the webshop in Smart Send and save the settings again.', 'smart-send-logistics' ),
			);
			$message  = $e instanceof Request_Exception ? $e->get_response()->message() : null;
			if ( null === $message || '' === trim( $message ) ) {
				$message = $messages[ $status ] ?? __( 'The connection to Smart Send could not be verified. Please try saving the settings again later.', 'smart-send-logistics' );
			}
			$this->result = array(
				'error'   => true,
				'message' => $message,
			);
			Logger::error(
				'API token connection test failed',
				array(
					'message' => $this->result['message'],
					'status'  => $status,
				)
			);
		}
	}

	/**
	 * Render safe, inline feedback immediately below the API Token field.
	 *
	 * @return string
	 */
	public function result_html(): string {
		if ( null === $this->result ) {
			return '';
		}

		return sprintf(
			'<div id="ss-connection-result" class="notice inline ss-connection %1$s" role="status"><p>%2$s</p></div>',
			$this->result['error'] ? 'notice-error error' : 'notice-success updated',
			esc_html( $this->result['message'] )
		);
	}
}
