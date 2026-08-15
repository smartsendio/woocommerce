<?php
/**
 * Smart Send API credentials.
 *
 * @package  SS_Shipping_Api_Credentials
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Api_Credentials' ) ) :

	/**
	 * The credentials the Smart Send API client is constructed with: the
	 * current site's host name and the API token resolved for it (#140).
	 *
	 * Owns the multi-site token format historically parsed by the plugin
	 * singleton: the API Token setting may hold one token per site as
	 * site1:apitoken1,site2:apitoken2,... - in that case the token
	 * matching this site's host is used, and a raw token that matches no
	 * site falls through unchanged (preserved v8 behaviour).
	 */
	class SS_Shipping_Api_Credentials {

		/**
		 * The raw API token (possibly the multi-site format), or null.
		 *
		 * @var string|null
		 */
		protected ?string $raw_api_token;

		/**
		 * The site url the credentials are resolved for.
		 *
		 * @var string|null
		 */
		protected ?string $site_url;

		/**
		 * @param string|null $raw_api_token The raw API token setting value (possibly site1:token1,site2:token2,...).
		 * @param string|null $site_url      The site url to resolve for; defaults to get_site_url().
		 */
		public function __construct( ?string $raw_api_token, ?string $site_url = null ) {
			$this->raw_api_token = $raw_api_token;
			$this->site_url      = $site_url;
		}

		/**
		 * Build the credentials from the saved API Token setting.
		 *
		 * @param SS_Shipping_Settings $settings Typed plugin settings reader.
		 *
		 * @return SS_Shipping_Api_Credentials
		 */
		public static function from_settings( SS_Shipping_Settings $settings ): SS_Shipping_Api_Credentials {
			return new self( $settings->api_token() );
		}

		/**
		 * The host name of the site the credentials belong to (e.g.
		 * example.com), as reported to the Smart Send API.
		 *
		 * @return string|null
		 */
		public function website(): ?string {
			$site_url = null === $this->site_url ? get_site_url() : $this->site_url;
			$host     = parse_url( $site_url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pre-existing use of parse_url(), kept verbatim from the historic singleton helper.

			return is_string( $host ) ? $host : null;
		}

		/**
		 * The API token for this site: the raw token, or - when the setting
		 * holds multiple site:token pairs - the token registered for this
		 * site's host.
		 *
		 * @return string|null
		 */
		public function api_token(): ?string {
			$api_token = $this->raw_api_token;

			if ( is_string( $api_token ) && strpos( $api_token, ',' ) && strpos( $api_token, ':' ) ) {
				// The API Token field contains multiple tokens in the format:
				// site1:apitoken1,site2:apitoken2,...
				$tokens          = array();
				$site_and_tokens = explode( ',', $api_token );
				foreach ( $site_and_tokens as $site_and_token ) {
					$parts = explode( ':', $site_and_token );
					if ( ! empty( $parts[0] ) && ! empty( $parts[1] ) ) {
						$tokens[ $parts[0] ] = $parts[1]; // key=site, value=apitoken.
					}
				}

				if ( ! empty( $tokens[ $this->website() ] ) ) {
					return $tokens[ $this->website() ];
				}
			}

			return $api_token;
		}
	}

endif;
