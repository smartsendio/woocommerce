<?php

namespace Smart_Send\API\Models\Shipment;

class Receiver implements \JsonSerializable {

	// Typed properties keep "= null" so get_object_vars() serialization is
	// unchanged.
	private ?string $internal_id        = null;
	private ?string $internal_reference = null;
	private ?string $company            = null;
	private ?string $name_line1         = null;
	private ?string $name_line2         = null;
	private ?string $address_line1      = null;
	private ?string $address_line2      = null;
	private ?string $postal_code        = null;
	private ?string $city               = null;
	private ?string $country            = null;
	private ?string $sms                = null;
	private ?string $email              = null;

	public function __construct( $receiver = array() ) {
		if ( isset( $receiver['internal_id'] ) ) {
			$this->set_internal_id( $receiver['internal_id'] );
		}

		if ( isset( $receiver['internal_reference'] ) ) {
			$this->set_internal_reference( $receiver['internal_reference'] );
		}

		if ( isset( $receiver['company'] ) ) {
			$this->set_company( $receiver['company'] );
		}

		if ( isset( $receiver['name_line1'] ) ) {
			$this->setName1( $receiver['name_line1'] );
		}

		if ( isset( $receiver['name_line2'] ) ) {
			$this->setName2( $receiver['name_line2'] );
		}

		if ( isset( $receiver['address_line1'] ) ) {
			$this->set_address_line1( $receiver['address_line1'] );
		}

		if ( isset( $receiver['address_line2'] ) ) {
			$this->set_address_line2( $receiver['address_line2'] );
		}

		if ( isset( $receiver['postal_code'] ) ) {
			$this->set_postal_code( $receiver['postal_code'] );
		}

		if ( isset( $receiver['city'] ) ) {
			$this->set_city( $receiver['city'] );
		}

		if ( isset( $receiver['country'] ) ) {
			$this->set_country( $receiver['country'] );
		}

		if ( isset( $receiver['sms'] ) ) {
			$this->set_sms( $receiver['sms'] );
		}

		if ( isset( $receiver['email'] ) ) {
			$this->set_email( $receiver['email'] );
		}
	}

	/**
	 * @return string|null
	 */
	public function get_internal_id(): ?string {
		return $this->internal_id;
	}

	/**
	 * @param string $internal_id
	 * @return self
	 */
	public function set_internal_id( $internal_id ): self {
		$this->internal_id = (string) $internal_id;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_internal_reference(): ?string {
		return $this->internal_reference;
	}

	/**
	 * @param string $internal_reference
	 * @return self
	 */
	public function set_internal_reference( $internal_reference ): self {
		$this->internal_reference = (string) $internal_reference;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_company(): ?string {
		return $this->company;
	}

	/**
	 * @param string|null $company
	 * @return self
	 */
	public function set_company( ?string $company ): self {
		$this->company = $company;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_name_line1(): ?string {
		return $this->name_line1;
	}

	/**
	 * @param string|null $name_line1
	 * @return self
	 */
	public function set_name_line1( ?string $name_line1 ): self {
		$this->name_line1 = $name_line1;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_name_line2(): ?string {
		return $this->name_line2;
	}

	/**
	 * @param string|null $name_line2
	 * @return self
	 */
	public function set_name_line2( ?string $name_line2 ): self {
		$this->name_line2 = $name_line2;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_address_line1(): ?string {
		return $this->address_line1;
	}

	/**
	 * @param string|null $address_line1
	 * @return self
	 */
	public function set_address_line1( ?string $address_line1 ): self {
		$this->address_line1 = $address_line1;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_address_line2(): ?string {
		return $this->address_line2;
	}

	/**
	 * @param string|null $address_line2
	 * @return self
	 */
	public function set_address_line2( ?string $address_line2 ): self {
		$this->address_line2 = $address_line2;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_postal_code(): ?string {
		return $this->postal_code;
	}

	/**
	 * @param string|null $postal_code
	 * @return self
	 */
	public function set_postal_code( ?string $postal_code ): self {
		$this->postal_code = $postal_code;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_city(): ?string {
		return $this->city;
	}

	/**
	 * @param string|null $city
	 * @return self
	 */
	public function set_city( ?string $city ): self {
		$this->city = $city;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_country(): ?string {
		return $this->country;
	}

	/**
	 * @param string|null $country
	 * @return self
	 */
	public function set_country( ?string $country ): self {
		$this->country = $country;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_sms(): ?string {
		return $this->sms;
	}

	/**
	 * @param string|null $sms
	 * @return self
	 */
	public function set_sms( ?string $sms ): self {
		$this->sms = $sms;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_email(): ?string {
		return $this->email;
	}

	/**
	 * @param string|null $email
	 * @return self
	 */
	public function set_email( ?string $email ): self {
		$this->email = $email;
		return $this;
	}

	public function jsonSerialize(): array {
		$vars = get_object_vars( $this );

		return $vars;
	}
}
