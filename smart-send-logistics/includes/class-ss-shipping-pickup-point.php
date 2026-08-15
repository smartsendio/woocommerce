<?php
/**
 * WooCommerce Smart Send pickup point value object.
 *
 * @package  SS_Shipping_Pickup_Point
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Pickup_Point' ) ) :

	/**
	 * The selected pickup point (carrier agent) of an order, as part of the
	 * delivery-details domain (#139).
	 *
	 * A serializable value object with no live WC_Order or WordPress
	 * dependency (Phase 7 queues delivery details). The stored order meta
	 * format is the frozen public contract - a plain object under the
	 * _ss_shipping_order_agent key - so this class can round-trip that
	 * object losslessly: from_object() keeps unknown properties (e.g.
	 * opening hours delivered by the API) plus the original property set,
	 * and to_object() reproduces them.
	 */
	class SS_Shipping_Pickup_Point {

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
				'company'       => 'company',
				'address_line1' => 'address_line1',
				'address_line2' => 'address_line2',
				'postal_code'   => 'postal_code',
				'city'          => 'city',
				'country'       => 'country',
				'distance'      => 'distance',
			);
		}

		/**
		 * Build a pickup point from a plain object: the stored
		 * _ss_shipping_order_agent meta object, or a pickup point returned
		 * by the Smart Send API lookup.
		 *
		 * @param object $agent The plain pickup point (agent) object.
		 *
		 * @return self
		 */
		public static function from_object( $agent ): self {
			$pickup_point              = new self();
			$pickup_point->source_keys = array();

			$field_map = self::field_map();

			foreach ( get_object_vars( $agent ) as $key => $value ) {
				$pickup_point->source_keys[] = $key;

				if ( isset( $field_map[ $key ] ) ) {
					$field                        = $field_map[ $key ];
					$pickup_point->{"set_$field"}( $value );
				} else {
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
					if ( isset( $field_map[ $key ] ) ) {
						$field          = $field_map[ $key ];
						$object->{$key} = $this->{"get_$field"}();
					} else {
						$object->{$key} = isset( $this->extra[ $key ] ) ? $this->extra[ $key ] : null;
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

			return $object;
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
	}

endif;
