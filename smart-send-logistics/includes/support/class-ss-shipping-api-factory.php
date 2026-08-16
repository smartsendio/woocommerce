<?php
/**
 * Smart Send API client factory.
 *
 * @package  SS_Shipping_Api_Factory
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Api_Factory' ) ) :

	/**
	 * The single construction path for the \Smartsend\Api client (#140).
	 *
	 * Every client - the singleton's shared handle and the ad-hoc live
	 * client the demo-mode validation builds - comes through here, so the
	 * request logger is always wired (the settings-save validation client
	 * historically bypassed it) and the credentials are always resolved
	 * through SS_Shipping_Api_Credentials.
	 */
	class SS_Shipping_Api_Factory {

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * The settings reader is stateless, so a fresh default is safe for
		 * ad-hoc construction.
		 *
		 * @param SS_Shipping_Settings|null $settings Typed plugin settings reader.
		 */
		public function __construct( ?SS_Shipping_Settings $settings = null ) {
			$this->settings = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * Create the client from the saved settings: the resolved API token
		 * and the demo-mode setting.
		 *
		 * @return \Smartsend\Api
		 */
		public function create(): \Smartsend\Api {
			return $this->create_for_credentials(
				SS_Shipping_Api_Credentials::from_settings( $this->settings ),
				$this->settings->demo_mode()
			);
		}

		/**
		 * Create a client for explicit credentials and demo mode - used by
		 * the settings save flow to validate a just-posted (not yet saved)
		 * API token against live mode.
		 *
		 * @param SS_Shipping_Api_Credentials $credentials The credentials to connect with.
		 * @param boolean                     $demo_mode   Whether the client runs in demo mode.
		 *
		 * @return \Smartsend\Api
		 */
		public function create_for_credentials( SS_Shipping_Api_Credentials $credentials, bool $demo_mode ): \Smartsend\Api {
			$api_handle = new \Smartsend\Api( $credentials->api_token(), $credentials->website(), $demo_mode );

			// Log every API request/response (incl. HTTP status code and
			// endpoint) through the plugin's logger.
			$api_handle->setRequestLogger( array( 'SS_Shipping_Logger', 'log_api_request' ) );

			return $api_handle;
		}
	}

endif;
