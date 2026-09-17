<?php

namespace Smart_Send\Delivery_Options;

use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception;
use Smart_Send\Exceptions\Not_Connected_Exception;
use Smart_Send\Frontend\Checkout;
use Smart_Send\Fulfillment\Fulfillment_Service;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send pickup point lookup.
 *
 * Headless closest-pickup-points lookup (#139), extracted from
 * Checkout: the API call, the search/result filters, the
 * failure reporting and the session cache - everything except rendering.
 * This is the seam Checkout Block support (Phase 6) builds on.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Pickup_Point_Lookup {

	/**
	 * WooCommerce session key caching the pickup points found for the
	 * shopper's entered address.
	 */
	const SESSION_KEY = 'ss_shipping_agents';

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * The settings reader is stateless, so a fresh default is safe for
	 * ad-hoc construction (tests construct the lookup directly).
	 *
	 * @param Settings|null $settings Typed plugin settings reader.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = null === $settings ? new Settings() : $settings;
	}

	/**
	 * Find the closest pickup points by address.
	 *
	 * The found pickup points are cached in the WooCommerce session
	 * (see get_session_pickup_points()) so the checkout submission can
	 * resolve the shopper's selection. An empty array is a valid
	 * outcome: the lookup ran and found no pickup points near the
	 * address. Every failure throws - the lookup logs and session-caches
	 * (an empty array, so checkout submission allows an order without a
	 * selection) before rethrowing, leaving only rendering to the
	 * caller.
	 *
	 * @param $carrier string | unique carrier code
	 * @param $country string | ISO3166-A2 Country code
	 * @param $postal_code string
	 * @param $city string
	 * @param $street string
	 *
	 * The raw API pickup point objects are mapped into
	 * Pickup_Point value objects right here, at the API
	 * boundary (#170): the smart_send_pickup_points_found filter, the
	 * session cache and every consumer downstream (formatter, classic
	 * checkout, Store API cart extension) see the typed DTO only.
	 *
	 * @throws Not_Connected_Exception When no API token is configured (no API call is made).
	 * @throws \Smart_Send\API\Exceptions\HTTP_Client_Exception When the API call fails.
	 *
	 * @return Pickup_Point[] The found pickup points (possibly empty).
	 */
	public function find_closest_by_address( $carrier, $country, $postal_code, $city, $street ) {
		// Without an API token the lookup cannot succeed - skip the API
		// call entirely.
		if ( null === $this->settings->api_token() ) {
			Logger::error(
				'Smart Send: pickup point lookup skipped - no API token configured (plugin not connected).',
				array( 'carrier' => $carrier )
			);

			WC()->session->set( self::SESSION_KEY, array() );

			throw new Not_Connected_Exception( 'No Smart Send API token is configured.' );
		}

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
		try {
			$response = SS_SHIPPING_WC()->get_api_handle()->pickup_points()->find_closest_by_address( $carrier, $search_params['country'], $search_params['postal_code'], $search_params['city'], $search_params['street'] );
		} catch ( \Smart_Send\API\Exceptions\HTTP_Client_Exception $e ) {
			$this->report_lookup_failure( $carrier, $e );

			// Cache the empty result (replacing any stale points from a
			// previous address): checkout submission reads this to
			// distinguish "no pickup points were available - nothing to
			// select" (allowed without a selection) from "points were
			// offered but none chosen" (rejected).
			WC()->session->set( self::SESSION_KEY, array() );

			throw $e;
		}

		$ss_pickup_points = $this->map_api_pickup_points( $response->data() );

		if ( empty( $ss_pickup_points ) ) {
			// Not an error, but worth noticing: the API answered, there
			// are just no pickup points near the entered address -
			// typically an address the geocoder cannot resolve.
			Logger::info(
				sprintf( 'Smart Send: no %s pickup points found near the entered address.', $carrier ),
				array( 'carrier' => $carrier )
			);

			WC()->session->set( self::SESSION_KEY, array() );

			return array();
		}

		/*
		 * Filter the pickup points returned by the lookup before they
		 * are cached in the session and rendered in the checkout
		 * drop-down. Return a smaller (or re-ordered) array to limit
		 * or re-rank the choices offered to the customer.
		 *
		 * @since 9.0.0
		 *
		 * @param Pickup_Point[] $ss_pickup_points The pickup points found, closest first (typed value objects, not raw API objects - #170).
		 * @param array                      $search_params    The (filtered) search parameters used for the lookup.
		 *
		 * @return Pickup_Point[] The pickup points to cache and render.
		 */
		$ss_pickup_points = $this->only_pickup_points(
			apply_filters( 'smart_send_pickup_points_found', $ss_pickup_points, $search_params )
		);

		Logger::debug(
			sprintf( 'Smart Send: found %1$d %2$s pickup points near the entered address.', count( $ss_pickup_points ), $carrier ),
			array(
				'carrier'      => $carrier,
				'result_count' => count( $ss_pickup_points ),
			)
		);

		// Save all of the pickup points in the session, in their plain
		// serializable form (see get_session_pickup_points()).
		WC()->session->set( self::SESSION_KEY, $this->to_session_value( $ss_pickup_points ) );

		return $ss_pickup_points;
	}

	/**
	 * The pickup points cached in the WooCommerce session by the last
	 * lookup, if any: an empty array when the last lookup found nothing
	 * (or failed), null when no lookup ran in this session.
	 *
	 * The session holds the pickup points in their plain-object form
	 * (Pickup_Point::to_object(), the same shape the order
	 * meta stores) rather than serialized class instances, so a cached
	 * session never depends on the plugin's class definitions to
	 * unserialize; they are mapped back into value objects here.
	 *
	 * @return Pickup_Point[]|null
	 */
	public function get_session_pickup_points() {
		$cached = WC()->session->get( self::SESSION_KEY );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		$pickup_points = array();
		foreach ( $cached as $pickup_point ) {
			if ( $pickup_point instanceof Pickup_Point ) {
				$pickup_points[] = $pickup_point;
			} elseif ( is_object( $pickup_point ) || is_array( $pickup_point ) ) {
				$pickup_points[] = Pickup_Point::from_object( $pickup_point );
			}
		}

		return $pickup_points;
	}

	/**
	 * Find one session-cached pickup point by its agent number - the
	 * resolution both checkout paths run on submission: the shopper can
	 * only have picked from the cached lookup results, so a match here
	 * is already server-validated.
	 *
	 * A miss is not logged here: an absent or expired session cache is
	 * normal on fresh sessions, and the Store API caller falls back to
	 * a find_by_agent_no API call on a miss - each caller decides whether
	 * a miss is terminal and reports it accordingly.
	 *
	 * @param string $agent_no The submitted agent number.
	 *
	 * @return Pickup_Point|null The cached pickup point, or null when none matches.
	 */
	public function find_cached_by_agent_no( string $agent_no ): ?Pickup_Point {
		if ( '' === $agent_no ) {
			return null;
		}

		$cached_pickup_points = $this->get_session_pickup_points();

		if ( ! is_array( $cached_pickup_points ) ) {
			return null;
		}

		foreach ( $cached_pickup_points as $pickup_point ) {
			if ( null !== $pickup_point->get_agent_no() && $pickup_point->get_agent_no() == $agent_no ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison, moved verbatim from Checkout::process_ss_pickup_points().
				return $pickup_point;
			}
		}

		return null;
	}

	/**
	 * Find one pickup point by its agent number at the Smart Send API -
	 * the shared "resolve an entered agent number" call (#182) behind
	 * the order screen's Custom Fields validation
	 * (Pickup_Point_Validator) and a pickup point override
	 * submitted with a fulfillment request (Fulfillment_Service).
	 *
	 * No session cache is involved (unlike the checkout lookups): the
	 * admin has no checkout session, and an entered number is verified
	 * against the API every time. The request and response are logged
	 * by the client's request logger; the callers log what the miss
	 * means in their context.
	 *
	 * @param string $carrier  Unique carrier code (e.g. 'postnord').
	 * @param string $country  ISO3166-A2 country code of the order's shipping address.
	 * @param string $agent_no The agent number to resolve.
	 *
	 * @throws Pickup_Point_Not_Found_Exception When the API knows no such pickup point (or the lookup failed).
	 *
	 * @return Pickup_Point The resolved pickup point, mapped at the API boundary.
	 */
	public function find_by_agent_no( string $carrier, string $country, string $agent_no ): Pickup_Point {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the exception carries the carrier and agent number as data for its callers (validator error message, fulfillment field error, REST 422), never echoed directly.
		try {
			$response = SS_SHIPPING_WC()->get_api_handle()->pickup_points()->find_by_agent_no( $carrier, $country, $agent_no );
		} catch ( \Smart_Send\API\Exceptions\HTTP_Client_Exception $e ) {
			throw new Pickup_Point_Not_Found_Exception( $carrier, $agent_no, $e );
		}

		$data = $response->data();

		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			throw new Pickup_Point_Not_Found_Exception( $carrier, $agent_no );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return Pickup_Point::from_object( $data );
	}

	/**
	 * Map the raw API pickup point objects of a lookup response into
	 * value objects - the API boundary of the pickup point contract.
	 *
	 * @param mixed $data The response data (a list of plain agent objects).
	 *
	 * @return Pickup_Point[]
	 */
	protected function map_api_pickup_points( $data ): array {
		$pickup_points = array();

		foreach ( is_array( $data ) ? $data : array() as $agent ) {
			if ( is_object( $agent ) || is_array( $agent ) ) {
				$pickup_points[] = Pickup_Point::from_object( $agent );
			}
		}

		return $pickup_points;
	}

	/**
	 * Keep only Pickup_Point instances from a filtered list
	 * (a snippet returning something else is logged and dropped rather
	 * than crashing checkout), re-indexed from 0.
	 *
	 * @param mixed $filtered The smart_send_pickup_points_found return value.
	 *
	 * @return Pickup_Point[]
	 */
	protected function only_pickup_points( $filtered ): array {
		$pickup_points = array();

		foreach ( is_array( $filtered ) ? $filtered : array() as $pickup_point ) {
			if ( $pickup_point instanceof Pickup_Point ) {
				$pickup_points[] = $pickup_point;
			} else {
				Logger::warning(
					'The smart_send_pickup_points_found filter returned an entry that is not a Pickup_Point - entry dropped.',
					array( 'entry_type' => is_object( $pickup_point ) ? get_class( $pickup_point ) : gettype( $pickup_point ) )
				);
			}
		}

		return $pickup_points;
	}

	/**
	 * The plain, serializable session form of a pickup point list.
	 *
	 * @param Pickup_Point[] $pickup_points The pickup points.
	 *
	 * @return object[]
	 */
	protected function to_session_value( array $pickup_points ): array {
		return array_map(
			static function ( Pickup_Point $pickup_point ) {
				return $pickup_point->to_object();
			},
			$pickup_points
		);
	}

	/**
	 * Log why a pickup point lookup failed and surface the reason in
	 * WooCommerce's shipping debug mode.
	 *
	 * The checkout falls back to the "Shipping to closest pickup point"
	 * text whenever no pickup points are available. That fallback hides several
	 * distinct causes, so this classifies the failure (transport error,
	 * API error response) by exception type and logs it at the error
	 * level. Log only: the checkout shipping debug bar carries one
	 * pickup-point-section line per render, emitted by the classic
	 * checkout surface (Checkout) with the method context
	 * this headless lookup does not have.
	 *
	 * @param string                                    $carrier Unique carrier code the lookup ran for.
	 * @param \Smart_Send\API\Exceptions\HTTP_Client_Exception $e       The failure.
	 */
	protected function report_lookup_failure( $carrier, \Smart_Send\API\Exceptions\HTTP_Client_Exception $e ) {
		$message = $e->getMessage();

		if ( $e instanceof \Smart_Send\API\Exceptions\Unauthenticated_Exception ) {
			$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with an authentication error (HTTP 401): %2$s', $carrier, $message );
		} elseif ( $e instanceof \Smart_Send\API\Exceptions\Forbidden_Exception ) {
			$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with an authorization error (HTTP 403): %2$s', $carrier, $message );
		} elseif ( $e instanceof \Smart_Send\API\Exceptions\Connection_Exception ) {
			$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with a transport error: %2$s Falling back to "Shipping to closest pickup point".', $carrier, $message );
		} else {
			$log_message = sprintf( 'Smart Send: pickup point lookup for %1$s failed with an API error: %2$s Falling back to "Shipping to closest pickup point".', $carrier, $message );
		}

		Logger::error(
			$log_message,
			array(
				'carrier'       => $carrier,
				'error_message' => $message,
			)
		);
	}
}
