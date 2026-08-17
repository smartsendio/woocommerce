<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send pickup point lookup.
 *
 * Headless closest-pickup-points lookup (#139), extracted from
 * SS_Shipping_Frontend: the API call, the search/result filters, the
 * failure reporting and the session cache - everything except rendering.
 * This is the seam Checkout Block support (Phase 6) builds on.
 *
 * @package  SS_Shipping_Pickup_Point_Lookup
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Pickup_Point_Lookup' ) ) :

	class SS_Shipping_Pickup_Point_Lookup {

		/**
		 * WooCommerce session key caching the pickup points found for the
		 * shopper's entered address.
		 */
		const SESSION_KEY = 'ss_shipping_agents';

		/**
		 * Find the closest pickup points by address.
		 *
		 * The found pickup points are cached in the WooCommerce session
		 * (see get_session_pickup_points()) so the checkout submission can
		 * resolve the shopper's selection.
		 *
		 * @param $carrier string | unique carrier code
		 * @param $country string | ISO3166-A2 Country code
		 * @param $postal_code string
		 * @param $city string
		 * @param $street string
		 *
		 * @return array
		 */
		public function find_closest_by_address( $carrier, $country, $postal_code, $city, $street ) {
			/*
			 * Filter the search parameters used when looking up the closest
			 * pickup points at checkout, before the API call is made.
			 *
			 * The number of returned pickup points is determined by the API
			 * and cannot be requested here; use the smart_send_pickup_points_found
			 * filter to trim the returned list.
			 *
			 * @since 9.0.0
			 *
			 * @param array $search_params {
			 *     The pickup point search parameters.
			 *
			 *     @type string      $carrier     Unique carrier code (e.g. 'postnord').
			 *     @type string      $country     ISO3166-A2 country code.
			 *     @type string      $postal_code Postal code.
			 *     @type string|null $city        City (optional but preferred).
			 *     @type string      $street      Street address.
			 * }
			 *
			 * @return array The search parameters to use for the lookup.
			 */
			$search_params = apply_filters(
				'smart_send_pickup_point_search_params',
				array(
					'carrier'     => $carrier,
					'country'     => $country,
					'postal_code' => $postal_code,
					'city'        => $city,
					'street'      => $street,
				)
			);

			$carrier = $search_params['carrier'];

			// The request and response (incl. HTTP status code and endpoint)
			// are logged by the client's request logger.
			$response = SS_SHIPPING_WC()->get_api_handle()->pickupPoints()->findClosestByAddress( $carrier, $search_params['country'], $search_params['postal_code'], $search_params['city'], $search_params['street'] );

			if ( $response->isSuccessful() ) {

				$ss_pickup_points = $response->data();

				/*
				 * Filter the pickup points returned by the lookup before they
				 * are cached in the session and rendered in the checkout
				 * drop-down. Return a smaller (or re-ordered) array to limit
				 * or re-rank the choices offered to the customer.
				 *
				 * @since 9.0.0
				 *
				 * @param object[] $ss_pickup_points     The pickup points returned by the API.
				 * @param array    $search_params The (filtered) search parameters used for the lookup.
				 *
				 * @return object[] The pickup points to cache and render.
				 */
				$ss_pickup_points = apply_filters( 'smart_send_pickup_points_found', $ss_pickup_points, $search_params );

				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send: found %1$d %2$s pickup points near the entered address.', count( $ss_pickup_points ), $carrier ),
					array(
						'carrier'      => $carrier,
						'result_count' => count( $ss_pickup_points ),
					)
				);
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: 1: number of pickup points found, 2: carrier code. */
						_n(
							'Smart Send: found %1$d %2$s pickup point near the entered address.',
							'Smart Send: found %1$d %2$s pickup points near the entered address.',
							count( $ss_pickup_points ),
							'smart-send-logistics'
						),
						count( $ss_pickup_points ),
						$carrier
					)
				);

				// Save all of the pickup points in the session.
				WC()->session->set( self::SESSION_KEY, $ss_pickup_points );

				return $ss_pickup_points;
			} else {
				$this->report_lookup_failure( $carrier, $response->error() );

				return array();
			}
		}

		/**
		 * The pickup points cached in the WooCommerce session by the last
		 * lookup, if any.
		 *
		 * @return object[]|null
		 */
		public function get_session_pickup_points() {
			return WC()->session->get( self::SESSION_KEY );
		}

		/**
		 * Find one session-cached pickup point by its agent number - the
		 * resolution both checkout paths run on submission: the shopper can
		 * only have picked from the cached lookup results, so a match here
		 * is already server-validated.
		 *
		 * @param string $agent_no The submitted agent number.
		 *
		 * @return object|null The cached pickup point, or null when none matches.
		 */
		public function find_cached_by_agent_no( $agent_no ) {
			$cached_pickup_points = $this->get_session_pickup_points();

			if ( ! is_array( $cached_pickup_points ) ) {
				return null;
			}

			foreach ( $cached_pickup_points as $pickup_point ) {
				if ( isset( $pickup_point->agent_no ) && $pickup_point->agent_no == $agent_no ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison, moved verbatim from SS_Shipping_Frontend::process_ss_pickup_points().
					return $pickup_point;
				}
			}

			return null;
		}

		/**
		 * Log why a pickup point lookup failed and surface the reason in
		 * WooCommerce's shipping debug mode.
		 *
		 * The checkout falls back to the "Shipping to closest pickup point"
		 * text whenever no pickup points are available. That fallback hides several
		 * distinct causes, so this classifies the failure (transport error,
		 * API error response, empty result) and reports it: always to the log
		 * (error level for failures, debug level for an empty result) and via
		 * SS_Shipping_Checkout_Debug::add_notice() when WooCommerce shipping
		 * debug mode is on (a no-op during checkout AJAX requests, matching
		 * core - the log entry is then the only trace).
		 *
		 * @param string      $carrier Unique carrier code the lookup ran for.
		 * @param object|null $error   Smartsend\Models\Error describing the failure, if any.
		 */
		protected function report_lookup_failure( $carrier, $error ) {
			$code    = ( is_object( $error ) && isset( $error->code ) && is_scalar( $error->code ) ) ? (string) $error->code : '';
			$message = ( is_object( $error ) && isset( $error->message ) && is_scalar( $error->message ) ) ? (string) $error->message : '';

			if ( 'NoResults' === $code ) {
				// Not an error: the API answered, there are just no pickup
				// points near the entered address.
				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send: no %s pickup points found near the entered address - falling back to "Shipping to closest pickup point".', $carrier ),
					array( 'carrier' => $carrier )
				);
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: %s: carrier code. */
						__( 'Smart Send: no %s pickup points found near the entered address - falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ),
						$carrier
					)
				);

				return;
			}

			if ( 0 === strpos( $code, 'transport-' ) ) {
				$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with a transport error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', $carrier, $code, $message );
				/* translators: 1: carrier code, 2: error code, 3: error message. */
				$notice_message = sprintf( __( 'Smart Send: pickup point lookup for %1$s failed with a transport error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ), $carrier, $code, $message );
			} elseif ( '' !== $code || '' !== $message ) {
				$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with an API error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', $carrier, $code, $message );
				/* translators: 1: carrier code, 2: error code, 3: error message. */
				$notice_message = sprintf( __( 'Smart Send: pickup point lookup for %1$s failed with an API error (%2$s): %3$s Falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ), $carrier, $code, $message );
			} else {
				$log_message = sprintf( 'Smart Send: pickup point lookup for %s failed for an unknown reason. Falling back to "Shipping to closest pickup point".', $carrier );
				/* translators: %s: carrier code. */
				$notice_message = sprintf( __( 'Smart Send: pickup point lookup for %s failed for an unknown reason. Falling back to "Shipping to closest pickup point".', 'smart-send-logistics' ), $carrier );
			}

			SS_Shipping_Logger::error(
				$log_message,
				array(
					'carrier'       => $carrier,
					'error_code'    => $code,
					'error_message' => $message,
				)
			);
			SS_Shipping_Checkout_Debug::add_notice( $notice_message );
		}
	}

endif;
