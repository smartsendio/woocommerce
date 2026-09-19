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

	/** Search context qualifying the displayed results, including empty outcomes. */
	const SESSION_CONTEXT = 'ss_shipping_agents_context';

	/** Independently resolved selection, unaffected by nearest-result refreshes. */
	const SESSION_SELECTION = 'ss_shipping_selected_pickup_point';

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
	 * boundary (#170): the smart_send_pickup_point_list filter, the
	 * session cache and every consumer downstream (formatter, classic
	 * checkout, Store API cart extension) see the typed DTO only.
	 *
	 * @throws Not_Connected_Exception When no API token is configured (no API call is made).
	 * @throws \Smart_Send\API\Exceptions\HTTP_Client_Exception When the API call fails.
	 *
	 * @return Pickup_Point[] The found pickup points (possibly empty).
	 */
	public function find_closest_by_address( $carrier, $country, $postal_code, $city, $street ) {
		$context = $this->search_context( $carrier, $country, $postal_code, $city, $street );
		// Without an API token the lookup cannot succeed - skip the API
		// call entirely.
		if ( null === $this->settings->api_token() ) {
			Logger::error(
				'Smart Send: pickup point lookup skipped - no API token configured (plugin not connected).',
				array( 'carrier' => $carrier )
			);

			$this->cache_search_result( array(), $context );

			throw new Not_Connected_Exception( 'No Smart Send API token is configured.' );
		}

		/*
		 * Filter the search parameters used when looking up the closest
		 * pickup points at checkout, before the API call is made.
		 *
		 * The number of returned pickup points is determined by the API
		 * and cannot be requested here; use the smart_send_pickup_point_list
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

		$carrier            = strtolower( trim( (string) $search_params['carrier'] ) );
		$country            = strtoupper( trim( (string) $search_params['country'] ) );
		$context['carrier'] = $carrier;
		$context['country'] = $country;

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
			$this->cache_search_result( array(), $context );

			throw $e;
		}

		$ss_pickup_points = $this->map_api_pickup_points( $response->data(), $carrier, $country );

		if ( empty( $ss_pickup_points ) ) {
			// Not an error, but worth noticing: the API answered, there
			// are just no pickup points near the entered address -
			// typically an address the geocoder cannot resolve.
			Logger::info(
				sprintf( 'Smart Send: no %s pickup points found near the entered address.', $carrier ),
				array( 'carrier' => $carrier )
			);

			$this->cache_search_result( array(), $context );

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
			apply_filters( 'smart_send_pickup_point_list', $ss_pickup_points, $search_params ),
			$carrier,
			$country
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
		$this->cache_search_result( $ss_pickup_points, $context );

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
		$cached = $this->session_value( self::SESSION_KEY );

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
	 * Resolve a reference using only trusted state for its carrier and country.
	 *
	 * Display-list membership is not required: an explicit choice may be farther
	 * away than the latest nearest results. A cache miss is verified at the API.
	 *
	 * @param string $carrier  Shipping carrier from the server's method.
	 * @param string $country  Destination country from the server's address.
	 * @param string $agent_no Submitted reference; no client address fields are used.
	 * @return Pickup_Point
	 * @throws Pickup_Point_Not_Found_Exception When the reference cannot be verified.
	 */
	public function resolve_selection( string $carrier, string $country, string $agent_no ): Pickup_Point {
		$agent_no = trim( $agent_no );
		$selected = $this->get_selected( $carrier, $country );
		if ( null !== $selected && $selected->get_agent_no() === $agent_no ) {
			return $selected;
		}
		$cached = $this->find_cached_by_agent_no( $carrier, $country, $agent_no );

		return null === $cached ? $this->find_by_agent_no( $carrier, $country, $agent_no ) : $cached;
	}

	/**
	 * Verify and retain an explicit or automatic choice independently of results.
	 *
	 * @param string $carrier  Server-derived carrier.
	 * @param string $country  Server-derived destination country.
	 * @param string $agent_no Submitted reference.
	 * @param bool   $explicit Whether the customer actively chose the point.
	 * @return Pickup_Point
	 */
	public function select( string $carrier, string $country, string $agent_no, bool $explicit = true ): Pickup_Point {
		$point = $this->resolve_selection( $carrier, $country, $agent_no );
		$this->set_session_value(
			self::SESSION_SELECTION,
			array(
				'carrier' => strtolower( trim( $carrier ) ),
				'country' => strtoupper( trim( $country ) ),
				'source'  => $explicit ? 'explicit' : 'automatic',
				'point'   => $point->to_object(),
			)
		);

		return $point;
	}

	/**
	 * Read a trusted compatible choice; changing carrier or country invalidates it.
	 *
	 * @param string $carrier       Server-derived carrier.
	 * @param string $country       Server-derived country.
	 * @param bool   $explicit_only Exclude automatic defaults when preserving a choice.
	 * @return Pickup_Point|null
	 */
	public function get_selected( string $carrier, string $country, bool $explicit_only = false ): ?Pickup_Point {
		$selection = $this->session_value( self::SESSION_SELECTION );
		if ( ! is_array( $selection ) ) {
			return null;
		}
		if ( ! $this->context_matches( $selection, $carrier, $country ) || ! isset( $selection['point'] ) ) {
			$this->clear_selection();
			return null;
		}
		if ( $explicit_only && 'explicit' !== ( $selection['source'] ?? null ) ) {
			return null;
		}
		$point = $this->verified_point( $selection['point'], $carrier, $country );
		if ( null === $point ) {
			$this->clear_selection();
		}

		return $point;
	}

	/** Whether the retained choice was explicitly selected by the customer. */
	public function is_selection_explicit(): bool {
		$selection = $this->session_value( self::SESSION_SELECTION );
		return is_array( $selection ) && 'explicit' === ( $selection['source'] ?? null );
	}

	/** Clear a choice when the customer clears it or leaves pickup delivery. */
	public function clear_selection(): void {
		$this->set_session_value( self::SESSION_SELECTION, null );
	}

	/**
	 * Look up a point in the latest trusted carrier/country-scoped results.
	 *
	 * @param string $carrier  Carrier whose point is being selected.
	 * @param string $country  Destination country.
	 * @param string $agent_no Exact carrier reference, preserving leading zeroes.
	 * @return Pickup_Point|null
	 */
	public function find_cached_by_agent_no( string $carrier, string $country, string $agent_no ): ?Pickup_Point {
		$context = $this->session_value( self::SESSION_CONTEXT );
		if ( '' === trim( $agent_no ) || ! is_array( $context ) || ! $this->context_matches( $context, $carrier, $country ) ) {
			return null;
		}
		foreach ( $this->get_session_pickup_points() ?? array() as $point ) {
			$verified = $this->verified_point( $point->to_object(), $carrier, $country, trim( $agent_no ) );
			if ( null !== $verified ) {
				return $verified;
			}
		}

		return null;
	}

	/**
	 * Prove that the current address lookup offered no points, including failures.
	 *
	 * @param string      $carrier     Current shipping carrier.
	 * @param string      $country     Current destination country.
	 * @param string      $postal_code Current postal code.
	 * @param string|null $city        Current city.
	 * @param string      $street      Current street.
	 * @return bool
	 */
	public function no_pickup_points_for_address( $carrier, $country, $postal_code, $city, $street ): bool {
		return $this->search_context( $carrier, $country, $postal_code, $city, $street ) === $this->session_value( self::SESSION_CONTEXT )
			&& array() === $this->get_session_pickup_points();
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
		$carrier  = strtolower( trim( $carrier ) );
		$country  = strtoupper( trim( $country ) );
		$agent_no = trim( $agent_no );
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries plain validation data; callers escape when rendering.
		if ( '' === $carrier || '' === $country || '' === $agent_no ) {
			throw new Pickup_Point_Not_Found_Exception( $carrier, $agent_no );
		}
		try {
			$response = SS_SHIPPING_WC()->get_api_handle()->pickup_points()->find_by_agent_no( $carrier, $country, $agent_no );
		} catch ( \Smart_Send\API\Exceptions\HTTP_Client_Exception $e ) {
			throw new Pickup_Point_Not_Found_Exception( $carrier, $agent_no, $e );
		}
		$point = $this->verified_point( $response->data(), $carrier, $country, $agent_no );
		if ( null === $point ) {
			throw new Pickup_Point_Not_Found_Exception( $carrier, $agent_no );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $point;
	}

	/**
	 * Map the raw API pickup point objects of a lookup response into
	 * value objects - the API boundary of the pickup point contract.
	 *
	 * @param mixed $data The response data (a list of plain agent objects).
	 *
	 * @return Pickup_Point[]
	 */
	protected function map_api_pickup_points( $data, string $carrier, string $country ): array {
		$pickup_points = array();

		foreach ( is_array( $data ) ? $data : array() as $agent ) {
			$point = $this->verified_point( $agent, $carrier, $country );
			if ( null !== $point ) {
				$pickup_points[] = $point;
			}
		}

		return $pickup_points;
	}

	/**
	 * Keep only Pickup_Point instances from a filtered list
	 * (a snippet returning something else is logged and dropped rather
	 * than crashing checkout), re-indexed from 0.
	 *
	 * @param mixed $filtered The smart_send_pickup_point_list return value.
	 *
	 * @return Pickup_Point[]
	 */
	protected function only_pickup_points( $filtered, string $carrier, string $country ): array {
		$pickup_points = array();

		foreach ( is_array( $filtered ) ? $filtered : array() as $pickup_point ) {
			if ( $pickup_point instanceof Pickup_Point ) {
				$data             = (array) $pickup_point->to_object();
				$data['carrier']  = $pickup_point->get_carrier();
				$data['country']  = $pickup_point->get_country();
				$data['agent_no'] = $pickup_point->get_agent_no();
				$verified         = $this->verified_point( $data, $carrier, $country );
				if ( null !== $verified ) {
					$pickup_points[] = $verified;
				}
			} else {
				Logger::warning(
					'The smart_send_pickup_point_list filter returned an entry that is not a Pickup_Point - entry dropped.',
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
	 * Validate a trusted API/filter/session row and stamp its verified scope.
	 *
	 * Client-supplied DTOs never enter this method. Scope omitted by the API
	 * comes from the scoped endpoint, and is inserted before DTO construction
	 * so lossless source-key serialization retains it.
	 *
	 * @param mixed       $data     Trusted source row.
	 * @param string      $carrier  Expected carrier.
	 * @param string      $country  Expected country.
	 * @param string|null $agent_no Expected reference, or any non-empty reference.
	 * @return Pickup_Point|null
	 */
	protected function verified_point( $data, string $carrier, string $country, ?string $agent_no = null ): ?Pickup_Point {
		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return null;
		}
		$data = (array) $data;
		if ( ! isset( $data['agent_no'] ) || ( ! is_string( $data['agent_no'] ) && ! is_int( $data['agent_no'] ) ) ) {
			return null;
		}
		$reference = trim( (string) $data['agent_no'] );
		$carrier   = strtolower( trim( $carrier ) );
		$country   = strtoupper( trim( $country ) );
		if ( '' === $reference || '' === $carrier || '' === $country || ( null !== $agent_no && $reference !== $agent_no ) ) {
			return null;
		}
		foreach ( array(
			'carrier' => $carrier,
			'country' => $country,
		) as $field => $expected ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
				if ( ! is_string( $data[ $field ] ) || 0 !== strcasecmp( trim( $data[ $field ] ), $expected ) ) {
					return null;
				}
			}
			$data[ $field ] = $expected;
		}
		$data['agent_no'] = $reference;

		return Pickup_Point::from_object( $data );
	}

	/**
	 * Check the identity scope of an already trusted server-side point.
	 *
	 * This proves compatibility, not provenance: never use it to authorize
	 * a DTO received from a client. Submitted points must be resolved by ID.
	 *
	 * @param Pickup_Point $point   Trusted stored or looked-up point.
	 * @param string       $carrier Expected shipping carrier.
	 * @param string       $country Expected destination country.
	 * @return bool
	 */
	public function matches_context( Pickup_Point $point, string $carrier, string $country ): bool {
		$carrier = strtolower( trim( $carrier ) );
		$country = strtoupper( trim( $country ) );

		return '' !== trim( (string) $point->get_agent_no() ) && '' !== $carrier && '' !== $country
			&& strtolower( trim( (string) $point->get_carrier() ) ) === $carrier
			&& strtoupper( trim( (string) $point->get_country() ) ) === $country;
	}

	/**
	 * Compare authoritative carrier/country scope independently of an address.
	 *
	 * @param array  $context Stored scope.
	 * @param string $carrier Expected carrier.
	 * @param string $country Expected country.
	 * @return bool
	 */
	protected function context_matches( array $context, string $carrier, string $country ): bool {
		return strtolower( trim( $carrier ) ) === ( $context['carrier'] ?? null )
			&& strtoupper( trim( $country ) ) === ( $context['country'] ?? null );
	}

	/** Build a stable context from server-known search inputs. */
	protected function search_context( $carrier, $country, $postal_code, $city, $street ): array {
		$country = strtoupper( trim( (string) $country ) );

		return array(
			'carrier'     => strtolower( trim( (string) $carrier ) ),
			'country'     => $country,
			'postal_code' => wc_format_postcode( trim( (string) $postal_code ), $country ),
			'city'        => trim( (string) $city ),
			'street'      => trim( (string) $street ),
		);
	}

	/** Store display results and their context together, never the selection. */
	protected function cache_search_result( array $points, array $context ): void {
		$this->set_session_value( self::SESSION_KEY, $this->to_session_value( $points ) );
		$this->set_session_value( self::SESSION_CONTEXT, $context );
	}

	/** Read session data without requiring a checkout session in admin callers. */
	protected function session_value( string $key ) {
		return function_exists( 'WC' ) && null !== WC()->session ? WC()->session->get( $key ) : null;
	}

	/** Write session data only when a WooCommerce session exists. */
	protected function set_session_value( string $key, $value ): void {
		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			WC()->session->set( $key, $value );
		}
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
