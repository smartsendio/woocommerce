<?php
/**
 * WooCommerce Smart Send pickup point value object.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Delivery;

use WC_Order;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The selected pickup point (carrier agent) of an order, as part of the
 * delivery-details domain (#139).
 *
 * A serializable value object with no live WC_Order or WordPress
 * dependency (Phase 7 queues delivery details). The stored order meta
 * format is the frozen public contract - a plain object under the
 * _ss_shipping_order_agent key - so this class can round-trip that
 * object losslessly: from_object() keeps unknown properties plus the
 * original property set, and to_object() reproduces them.
 *
 * This is also the shape the checkout hooks pass (#170):
 * smart_send_pickup_points_found, smart_send_default_selected_pickup_point
 * and smart_send_pickup_point_option_label receive instances of this
 * class, never raw API objects. Every field the Smart Send API delivers
 * for a pickup point is modeled here (identity, carrier, name and
 * address lines, distance, coordinates and opening hours), so a lookup
 * result loses nothing when it is mapped at the API boundary.
 */
class Pickup_Point {

	/**
	 * The carrier's pickup point number (v1: agent_no, v2:
	 * service_point_code).
	 *
	 * @var string|null
	 */
	protected ?string $agent_no = null;

	/**
	 * Smart Send internal id of the pickup point, when it came from the
	 * Smart Send API ('id' on the stored object).
	 *
	 * @var string|null
	 */
	protected ?string $internal_id = null;

	/**
	 * Company (shop) name.
	 *
	 * @var string|null
	 */
	protected ?string $company = null;

	/**
	 * Address line 1.
	 *
	 * @var string|null
	 */
	protected ?string $address_line1 = null;

	/**
	 * Address line 2.
	 *
	 * @var string|null
	 */
	protected ?string $address_line2 = null;

	/**
	 * Postal code.
	 *
	 * @var string|null
	 */
	protected ?string $postal_code = null;

	/**
	 * City.
	 *
	 * @var string|null
	 */
	protected ?string $city = null;

	/**
	 * ISO3166-A2 country code.
	 *
	 * @var string|null
	 */
	protected ?string $country = null;

	/**
	 * Distance from the searched address in kilometers, when the pickup
	 * point came from a lookup.
	 *
	 * @var float|null
	 */
	protected ?float $distance = null;

	/**
	 * The carrier code the pickup point belongs to (e.g. 'postnord'),
	 * when known.
	 *
	 * @var string|null
	 */
	protected ?string $carrier = null;

	/**
	 * Name line 1 (the contact/attention name the carrier lists, if
	 * any - distinct from the company name).
	 *
	 * @var string|null
	 */
	protected ?string $name_line1 = null;

	/**
	 * Name line 2.
	 *
	 * @var string|null
	 */
	protected ?string $name_line2 = null;

	/**
	 * GPS latitude, when known.
	 *
	 * @var float|null
	 */
	protected ?float $latitude = null;

	/**
	 * GPS longitude, when known.
	 *
	 * @var float|null
	 */
	protected ?float $longitude = null;

	/**
	 * Opening hours: one row per interval with the weekday (lowercase
	 * English, 'monday' ... 'sunday') and the opening/closing time as
	 * 'HH:MM:SS'. Empty when unknown.
	 *
	 * @var array<int, array{day: string, opens: string, closes: string}>
	 */
	protected array $opening_hours = array();

	/**
	 * Properties of the source object that are not modeled as fields,
	 * kept for lossless round-tripping (key => value).
	 *
	 * @var array
	 */
	protected array $extra = array();

	/**
	 * The ordered property list of the object this value object was
	 * built from, or null when constructed directly. Used by
	 * to_object() to reproduce the original property set and order.
	 *
	 * @var string[]|null
	 */
	protected ?array $source_keys = null;

	/**
	 * Map between object property names and value-object fields.
	 *
	 * @return array<string, string> property name => field name.
	 */
	protected static function field_map() {
		return array(
			'id'            => 'internal_id',
			'agent_no'      => 'agent_no',
			'carrier'       => 'carrier',
			'company'       => 'company',
			'name_line1'    => 'name_line1',
			'name_line2'    => 'name_line2',
			'address_line1' => 'address_line1',
			'address_line2' => 'address_line2',
			'postal_code'   => 'postal_code',
			'city'          => 'city',
			'country'       => 'country',
			'distance'      => 'distance',
			'coordinates'   => 'coordinates',
			'opening_hours' => 'opening_hours',
		);
	}

	/**
	 * Build a pickup point from a plain object: the stored
	 * _ss_shipping_order_agent meta object, or a pickup point returned
	 * by the Smart Send API lookup. Arrays (e.g. a to_array() result or
	 * a decoded JSON row) are accepted too.
	 *
	 * @param object|array $agent The plain pickup point (agent) object.
	 *
	 * @return self
	 */
	public static function from_object( $agent ): self {
		if ( is_array( $agent ) ) {
			$agent = (object) $agent;
		}

		$pickup_point              = new self();
		$pickup_point->source_keys = array();

		$field_map = self::field_map();

		foreach ( get_object_vars( $agent ) as $key => $value ) {
			$pickup_point->source_keys[] = $key;

			if ( isset( $field_map[ $key ] ) ) {
				$field = $field_map[ $key ];
				$pickup_point->{"set_$field"}( $value );
			} else {
				$pickup_point->extra[ $key ] = $value;
			}

			// The structured fields (coordinates, opening hours) are typed
			// on a best-effort basis: when the source value is not in the
			// canonical API shape (e.g. a hand-written meta object), the
			// raw value is kept as well so to_object() reproduces it
			// losslessly.
			if ( 'coordinates' === $key && ! self::is_same_value( $pickup_point->get_coordinates(), $value ) ) {
				$pickup_point->extra[ $key ] = $value;
			} elseif ( 'opening_hours' === $key && ! self::is_same_value( $pickup_point->get_opening_hours_objects(), $value ) ) {
				$pickup_point->extra[ $key ] = $value;
			}
		}

		return $pickup_point;
	}

	/**
	 * Reproduce the plain object representation stored in order meta
	 * (the frozen _ss_shipping_order_agent format).
	 *
	 * When this value object was built via from_object(), the original
	 * property set and order are reproduced (with any field changes
	 * applied). When constructed directly, the canonical address fields
	 * are emitted, plus id/distance when set.
	 *
	 * @return object
	 */
	public function to_object() {
		$field_map = self::field_map();
		$object    = new stdClass();

		if ( null !== $this->source_keys ) {
			foreach ( $this->source_keys as $key ) {
				if ( array_key_exists( $key, $this->extra ) ) {
					$object->{$key} = $this->extra[ $key ];
				} elseif ( 'coordinates' === $key ) {
					$object->coordinates = $this->get_coordinates();
				} elseif ( 'opening_hours' === $key ) {
					$object->opening_hours = $this->get_opening_hours_objects();
				} elseif ( isset( $field_map[ $key ] ) ) {
					$field          = $field_map[ $key ];
					$object->{$key} = $this->{"get_$field"}();
				} else {
					$object->{$key} = null;
				}
			}

			return $object;
		}

		if ( null !== $this->internal_id ) {
			$object->id = $this->internal_id;
		}
		$object->agent_no      = $this->agent_no;
		$object->company       = $this->company;
		$object->address_line1 = $this->address_line1;
		$object->address_line2 = $this->address_line2;
		$object->postal_code   = $this->postal_code;
		$object->city          = $this->city;
		$object->country       = $this->country;
		if ( null !== $this->distance ) {
			$object->distance = $this->distance;
		}
		if ( null !== $this->carrier ) {
			$object->carrier = $this->carrier;
		}
		if ( null !== $this->name_line1 || null !== $this->name_line2 ) {
			$object->name_line1 = $this->name_line1;
			$object->name_line2 = $this->name_line2;
		}
		if ( null !== $this->get_coordinates() ) {
			$object->coordinates = $this->get_coordinates();
		}
		if ( array() !== $this->opening_hours ) {
			$object->opening_hours = $this->get_opening_hours_objects();
		}

		return $object;
	}

	/**
	 * Plain-array form of to_object() (nested objects converted too),
	 * for array-minded snippets.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return json_decode( wp_json_encode( $this->to_object() ), true );
	}

	/**
	 * Whether the pickup point is a bare reference - an agent number
	 * and nothing else (e.g. array( 'agent_no' => '1234' ) submitted
	 * from the order meta box) - that still has to be resolved into the
	 * full pickup point through a lookup (#182).
	 *
	 * @return boolean
	 */
	public function is_agent_no_only(): bool {
		if ( null === $this->agent_no || '' === $this->agent_no || array() !== $this->extra ) {
			return false;
		}

		foreach ( self::field_map() as $field ) {
			if ( 'agent_no' === $field || 'coordinates' === $field ) {
				continue;
			}

			$value = 'opening_hours' === $field ? $this->opening_hours : $this->{$field};

			if ( null !== $value && '' !== $value && array() !== $value ) {
				return false;
			}
		}

		return null === $this->latitude && null === $this->longitude;
	}

	/**
	 * Get the pickup point number.
	 *
	 * @return string|null
	 */
	public function get_agent_no() {
		return $this->agent_no;
	}

	/**
	 * Set the pickup point number.
	 *
	 * @param mixed $agent_no The pickup point number.
	 *
	 * @return self
	 */
	public function set_agent_no( $agent_no ): self {
		$this->agent_no = null === $agent_no ? null : (string) $agent_no;

		return $this;
	}

	/**
	 * Get the Smart Send internal id, when known.
	 *
	 * @return string|null
	 */
	public function get_internal_id() {
		return $this->internal_id;
	}

	/**
	 * Set the Smart Send internal id.
	 *
	 * @param mixed $internal_id The internal id.
	 *
	 * @return self
	 */
	public function set_internal_id( $internal_id ): self {
		$this->internal_id = null === $internal_id ? null : (string) $internal_id;

		return $this;
	}

	/**
	 * Get the company (shop) name.
	 *
	 * @return string|null
	 */
	public function get_company() {
		return $this->company;
	}

	/**
	 * Set the company (shop) name.
	 *
	 * @param mixed $company The company name.
	 *
	 * @return self
	 */
	public function set_company( $company ): self {
		$this->company = null === $company ? null : (string) $company;

		return $this;
	}

	/**
	 * Get address line 1.
	 *
	 * @return string|null
	 */
	public function get_address_line1() {
		return $this->address_line1;
	}

	/**
	 * Set address line 1.
	 *
	 * @param mixed $address_line1 Address line 1.
	 *
	 * @return self
	 */
	public function set_address_line1( $address_line1 ): self {
		$this->address_line1 = null === $address_line1 ? null : (string) $address_line1;

		return $this;
	}

	/**
	 * Get address line 2.
	 *
	 * @return string|null
	 */
	public function get_address_line2() {
		return $this->address_line2;
	}

	/**
	 * Set address line 2.
	 *
	 * @param mixed $address_line2 Address line 2.
	 *
	 * @return self
	 */
	public function set_address_line2( $address_line2 ): self {
		$this->address_line2 = null === $address_line2 ? null : (string) $address_line2;

		return $this;
	}

	/**
	 * Get the postal code.
	 *
	 * @return string|null
	 */
	public function get_postal_code() {
		return $this->postal_code;
	}

	/**
	 * Set the postal code.
	 *
	 * @param mixed $postal_code The postal code.
	 *
	 * @return self
	 */
	public function set_postal_code( $postal_code ): self {
		$this->postal_code = null === $postal_code ? null : (string) $postal_code;

		return $this;
	}

	/**
	 * Get the city.
	 *
	 * @return string|null
	 */
	public function get_city() {
		return $this->city;
	}

	/**
	 * Set the city.
	 *
	 * @param mixed $city The city.
	 *
	 * @return self
	 */
	public function set_city( $city ): self {
		$this->city = null === $city ? null : (string) $city;

		return $this;
	}

	/**
	 * Get the country code.
	 *
	 * @return string|null
	 */
	public function get_country() {
		return $this->country;
	}

	/**
	 * Set the country code.
	 *
	 * @param mixed $country The ISO3166-A2 country code.
	 *
	 * @return self
	 */
	public function set_country( $country ): self {
		$this->country = null === $country ? null : (string) $country;

		return $this;
	}

	/**
	 * Get the distance in kilometers, when known.
	 *
	 * @return float|null
	 */
	public function get_distance() {
		return $this->distance;
	}

	/**
	 * Set the distance in kilometers.
	 *
	 * @param mixed $distance The distance.
	 *
	 * @return self
	 */
	public function set_distance( $distance ): self {
		$this->distance = ( null === $distance || '' === $distance ) ? null : (float) $distance;

		return $this;
	}

	/**
	 * Get the carrier code, when known.
	 *
	 * @return string|null
	 */
	public function get_carrier() {
		return $this->carrier;
	}

	/**
	 * Set the carrier code.
	 *
	 * @param mixed $carrier The carrier code (e.g. 'postnord').
	 *
	 * @return self
	 */
	public function set_carrier( $carrier ): self {
		$this->carrier = null === $carrier ? null : (string) $carrier;

		return $this;
	}

	/**
	 * Get name line 1.
	 *
	 * @return string|null
	 */
	public function get_name_line1() {
		return $this->name_line1;
	}

	/**
	 * Set name line 1.
	 *
	 * @param mixed $name_line1 Name line 1.
	 *
	 * @return self
	 */
	public function set_name_line1( $name_line1 ): self {
		$this->name_line1 = null === $name_line1 ? null : (string) $name_line1;

		return $this;
	}

	/**
	 * Get name line 2.
	 *
	 * @return string|null
	 */
	public function get_name_line2() {
		return $this->name_line2;
	}

	/**
	 * Set name line 2.
	 *
	 * @param mixed $name_line2 Name line 2.
	 *
	 * @return self
	 */
	public function set_name_line2( $name_line2 ): self {
		$this->name_line2 = null === $name_line2 ? null : (string) $name_line2;

		return $this;
	}

	/**
	 * Get the GPS latitude, when known.
	 *
	 * @return float|null
	 */
	public function get_latitude() {
		return $this->latitude;
	}

	/**
	 * Get the GPS longitude, when known.
	 *
	 * @return float|null
	 */
	public function get_longitude() {
		return $this->longitude;
	}

	/**
	 * Set the GPS coordinates - both, or neither (null clears them).
	 *
	 * @param mixed $latitude  The latitude.
	 * @param mixed $longitude The longitude.
	 *
	 * @return self
	 */
	public function set_latitude_longitude( $latitude, $longitude ): self {
		unset( $this->extra['coordinates'] );

		if ( null === $latitude || '' === $latitude || null === $longitude || '' === $longitude ) {
			$this->latitude  = null;
			$this->longitude = null;
		} else {
			$this->latitude  = (float) $latitude;
			$this->longitude = (float) $longitude;
		}

		return $this;
	}

	/**
	 * The coordinates as the plain object the API delivers and the
	 * order meta stores ({latitude, longitude}), or null when unknown.
	 *
	 * @return object|null
	 */
	public function get_coordinates() {
		if ( null === $this->latitude || null === $this->longitude ) {
			return null;
		}

		$coordinates            = new stdClass();
		$coordinates->latitude  = $this->latitude;
		$coordinates->longitude = $this->longitude;

		return $coordinates;
	}

	/**
	 * Set the coordinates from the plain {latitude, longitude} object
	 * (or array) the API delivers; anything else clears them.
	 *
	 * @param object|array|null $coordinates The coordinates.
	 *
	 * @return self
	 */
	public function set_coordinates( $coordinates ): self {
		if ( is_array( $coordinates ) ) {
			$coordinates = (object) $coordinates;
		}

		if ( ! is_object( $coordinates ) ) {
			return $this->set_latitude_longitude( null, null );
		}

		return $this->set_latitude_longitude(
			isset( $coordinates->latitude ) ? $coordinates->latitude : null,
			isset( $coordinates->longitude ) ? $coordinates->longitude : null
		);
	}

	/**
	 * Get the opening hours: one row per interval with 'day' (lowercase
	 * English weekday), 'opens' and 'closes' ('HH:MM:SS'). Empty when
	 * unknown.
	 *
	 * @return array<int, array{day: string, opens: string, closes: string}>
	 */
	public function get_opening_hours(): array {
		return $this->opening_hours;
	}

	/**
	 * Set the opening hours from a list of rows, each a plain object or
	 * array with day/opens/closes (the API shape); rows missing any of
	 * the three are dropped. Null or an empty list clears them.
	 *
	 * @param array|null $opening_hours The opening hour rows.
	 *
	 * @return self
	 */
	public function set_opening_hours( $opening_hours ): self {
		unset( $this->extra['opening_hours'] );

		$rows = array();

		foreach ( is_array( $opening_hours ) ? $opening_hours : array() as $row ) {
			$row = (array) $row;

			if ( ! isset( $row['day'], $row['opens'], $row['closes'] ) ) {
				continue;
			}

			$rows[] = array(
				'day'    => (string) $row['day'],
				'opens'  => (string) $row['opens'],
				'closes' => (string) $row['closes'],
			);
		}

		$this->opening_hours = $rows;

		return $this;
	}

	/**
	 * Strict structural equality of two plain values: same scalar type
	 * and value, or same container type (array vs object) with the same
	 * keys in the same order and equal values, recursively.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool
	 */
	protected static function is_same_value( $a, $b ): bool {
		if ( is_object( $a ) || is_object( $b ) ) {
			if ( ! is_object( $a ) || ! is_object( $b ) || get_class( $a ) !== get_class( $b ) ) {
				return false;
			}

			return self::is_same_value( get_object_vars( $a ), get_object_vars( $b ) );
		}

		if ( is_array( $a ) || is_array( $b ) ) {
			if ( ! is_array( $a ) || ! is_array( $b ) || array_keys( $a ) !== array_keys( $b ) ) {
				return false;
			}

			foreach ( $a as $key => $item ) {
				if ( ! self::is_same_value( $item, $b[ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		return $a === $b;
	}

	/**
	 * The opening hours as the list of plain objects the API delivers
	 * and the order meta stores.
	 *
	 * @return object[]
	 */
	protected function get_opening_hours_objects(): array {
		return array_map(
			static function ( array $row ) {
				return (object) $row;
			},
			$this->opening_hours
		);
	}
}
