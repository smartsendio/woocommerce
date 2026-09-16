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
	 * Every client comes through here, so the request logger is always
	 * wired and the credentials are always resolved through
	 * SS_Shipping_Api_Credentials.
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
		 * Create the client from the saved settings: the resolved API token.
		 *
		 * @return \Smartsend\Api
		 */
		public function create(): \Smartsend\Api {
			return $this->create_for_credentials(
				SS_Shipping_Api_Credentials::from_settings( $this->settings )
			);
		}

		/**
		 * Create a client for explicit credentials, rather than the ones
		 * resolved from the saved settings.
		 *
		 * @param SS_Shipping_Api_Credentials $credentials The credentials to connect with.
		 *
		 * @return \Smartsend\Api
		 */
		public function create_for_credentials( SS_Shipping_Api_Credentials $credentials ): \Smartsend\Api {
			$api_handle = new \Smartsend\Api( $credentials->api_token(), $credentials->website(), $this->resolve_api_host() );

			// Log every API request/response (incl. HTTP status code and
			// endpoint) through the plugin's logger.
			$api_handle->setRequestLogger( array( 'SS_Shipping_Logger', 'log_api_request' ) );

			return $api_handle;
		}

		/**
		 * The host every client talks to: the production host unless the
		 * smart_send_api_endpoint filter points at another one (e.g. the
		 * Smart Send sandbox). The filter carries the HOST only - the client
		 * appends the API version path itself (#170), so the plugin can move
		 * to a newer API version without breaking sandbox overrides.
		 *
		 * A value that still ends with the API version path (the pre-9.0
		 * shape, e.g. 'https://app.smartsend.dev/api/v1/') is stripped to
		 * the host with a warning in the log, instead of producing a
		 * '/api/v1/api/v1/' URL.
		 *
		 * @return string
		 */
		public function resolve_api_host(): string {
			/*
			 * Filter the Smart Send host the plugin talks to, e.g. to point
			 * at the sandbox environment. Return the host only (scheme and
			 * hostname, e.g. 'https://app.smartsend.dev'); the plugin appends
			 * the API version path itself.
			 *
			 * @param string $api_host The host, 'https://app.smartsend.io' by default.
			 *
			 * @return string The host to use.
			 */
			$api_host = (string) apply_filters( 'smart_send_api_endpoint', \Smartsend\Client::DEFAULT_API_HOST );

			if ( \Smartsend\Client::hasApiVersionPath( $api_host ) ) {
				$normalized = \Smartsend\Client::stripApiVersionPath( $api_host );

				SS_Shipping_Logger::warning(
					'The smart_send_api_endpoint filter returned a URL including the API version path - since 9.0.0 it must return the host only. The version path was stripped.',
					array(
						'filtered_value' => $api_host,
						'api_host'       => $normalized,
					)
				);

				return $normalized;
			}

			return $api_host;
		}
	}

endif;
