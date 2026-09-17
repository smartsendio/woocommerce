<?php
/**
 * Smart Send API client factory.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The single construction path for the \Smart_Send\API\API client (#140).
 *
 * Every client comes through here, so the request logger is always
 * wired and the credentials are always resolved through
 * API_Credentials.
 */
class API_Factory {

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * The settings reader is stateless, so a fresh default is safe for
	 * ad-hoc construction.
	 *
	 * @param Settings|null $settings Typed plugin settings reader.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = null === $settings ? new Settings() : $settings;
	}

	/**
	 * Create the client from the saved settings: the resolved API token.
	 *
	 * @return \Smart_Send\API\API
	 */
	public function create(): \Smart_Send\API\API {
		return $this->create_for_credentials(
			API_Credentials::from_settings( $this->settings )
		);
	}

	/**
	 * Create a client for explicit credentials, rather than the ones
	 * resolved from the saved settings.
	 *
	 * @param API_Credentials $credentials The credentials to connect with.
	 *
	 * @return \Smart_Send\API\API
	 */
	public function create_for_credentials( API_Credentials $credentials ): \Smart_Send\API\API {
		$api_handle = new \Smart_Send\API\API( $credentials->api_token(), $credentials->website(), $this->resolve_api_host() );

		// Log every API request/response (incl. HTTP status code and
		// endpoint) through the plugin's logger.
		$api_handle->set_request_logger( array( Logger::class, 'log_api_request' ) );

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
		$api_host = (string) apply_filters( 'smart_send_api_endpoint', \Smart_Send\API\Client::DEFAULT_API_HOST );

		if ( \Smart_Send\API\Client::has_api_version_path( $api_host ) ) {
			$normalized = \Smart_Send\API\Client::strip_api_version_path( $api_host );

			Logger::warning(
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
