<?php

namespace Smart_Send\API\Models\Shipment;

class Item implements \JsonSerializable {

	// Typed properties keep "= null" so get_object_vars() serialization is
	// unchanged.
	private ?string $internal_id              = null;
	private ?string $internal_reference       = null;
	private ?string $sku                      = null;
	private ?string $name                     = null;
	private ?string $description              = null;
	private ?string $hs_code                  = null;
	private ?string $country_of_origin        = null;
	private ?string $image_url                = null;
	private ?float $unit_weight               = null;
	private ?float $unit_price_excluding_tax  = null;
	private ?float $unit_price_including_tax  = null;
	private ?float $quantity                  = null;
	private ?float $total_price_excluding_tax = null;
	private ?float $total_price_including_tax = null;
	private ?float $total_tax_amount          = null;

	public function __construct( $item = array() ) {
		if ( isset( $item['internal_id'] ) ) {
			$this->set_internal_id( $item['internal_id'] );
		}

		if ( isset( $item['internal_reference'] ) ) {
			$this->set_internal_reference( $item['internal_reference'] );
		}

		if ( isset( $item['sku'] ) ) {
			$this->set_sku( $item['sku'] );
		}

		if ( isset( $item['name'] ) ) {
			$this->set_name( $item['name'] );
		}

		if ( isset( $item['description'] ) ) {
			$this->set_description( $item['description'] );
		}

		if ( isset( $item['hs_code'] ) ) {
			$this->set_hs_code( $item['hs_code'] );
		}

		if ( isset( $item['country_of_origin'] ) ) {
			$this->set_country_of_origin( $item['country_of_origin'] );
		}

		if ( isset( $item['image_url'] ) ) {
			$this->set_image_url( $item['image_url'] );
		}

		if ( isset( $item['unit_weight'] ) ) {
			$this->set_unit_weight( $item['unit_weight'] );
		}

		if ( isset( $item['unit_price_excluding_tax'] ) ) {
			$this->set_unit_price_excluding_tax( $item['unit_price_excluding_tax'] );
		}

		if ( isset( $item['unit_price_including_tax'] ) ) {
			$this->set_unit_price_including_tax( $item['unit_price_including_tax'] );
		}

		if ( isset( $item['quantity'] ) ) {
			$this->set_quantity( $item['quantity'] );
		}

		if ( isset( $item['total_price_excluding_tax'] ) ) {
			$this->set_total_price_excluding_tax( $item['total_price_excluding_tax'] );
		}

		if ( isset( $item['total_price_including_tax'] ) ) {
			$this->set_total_price_including_tax( $item['total_price_including_tax'] );
		}

		if ( isset( $item['total_tax_amount'] ) ) {
			$this->set_total_tax_amount( $item['total_tax_amount'] );
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
	public function get_sku(): ?string {
		return $this->sku;
	}

	/**
	 * @param string|null $sku
	 * @return self
	 */
	public function set_sku( ?string $sku ): self {
		$this->sku = $sku;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_name(): ?string {
		return $this->name;
	}

	/**
	 * @param string|null $name
	 * @return self
	 */
	public function set_name( ?string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_description(): ?string {
		return $this->description;
	}

	/**
	 * @param string|null $description
	 * @return self
	 */
	public function set_description( ?string $description ): self {
		$this->description = $description;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_hs_code(): ?string {
		return $this->hs_code;
	}

	/**
	 * @param string|null $hs_code
	 * @return self
	 */
	public function set_hs_code( ?string $hs_code ): self {
		$this->hs_code = $hs_code;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_country_of_origin(): ?string {
		return $this->country_of_origin;
	}

	/**
	 * @param string|null $country_of_origin
	 * @return self
	 */
	public function set_country_of_origin( ?string $country_of_origin ): self {
		$this->country_of_origin = $country_of_origin;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_image_url(): ?string {
		return $this->image_url;
	}

	/**
	 * @param string|null $image_url
	 * @return self
	 */
	public function set_image_url( ?string $image_url ): self {
		$this->image_url = $image_url;
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_unit_weight(): ?float {
		return $this->unit_weight;
	}

	/**
	 * @param mixed $unit_weight
	 * @return self
	 */
	public function set_unit_weight( $unit_weight ): self {

		$this->unit_weight = is_null( $unit_weight ) ? null : ( (float) $unit_weight );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_unit_price_excluding_tax(): ?float {
		return $this->unit_price_excluding_tax;
	}

	/**
	 * @param mixed $unit_price_excluding_tax
	 * @return self
	 */
	public function set_unit_price_excluding_tax( $unit_price_excluding_tax ): self {
		$this->unit_price_excluding_tax = is_null( $unit_price_excluding_tax ) ? null : ( (float) $unit_price_excluding_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_unit_price_including_tax(): ?float {
		return $this->unit_price_including_tax;
	}

	/**
	 * @param mixed $unit_price_including_tax
	 * @return self
	 */
	public function set_unit_price_including_tax( $unit_price_including_tax ): self {
		$this->unit_price_including_tax = is_null( $unit_price_including_tax ) ? null : ( (float) $unit_price_including_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_quantity(): ?float {
		return $this->quantity;
	}

	/**
	 * @param mixed $quantity
	 * @return self
	 */
	public function set_quantity( $quantity ): self {
		$this->quantity = is_null( $quantity ) ? null : ( (float) $quantity );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_total_price_excluding_tax(): ?float {
		return $this->total_price_excluding_tax;
	}

	/**
	 * @param mixed $total_price_excluding_tax
	 * @return self
	 */
	public function set_total_price_excluding_tax( $total_price_excluding_tax ): self {
		$this->total_price_excluding_tax = is_null( $total_price_excluding_tax ) ? null : ( (float) $total_price_excluding_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_total_price_including_tax(): ?float {
		return $this->total_price_including_tax;
	}

	/**
	 * @param mixed $total_price_including_tax;
	 * @return self
	 */
	public function set_total_price_including_tax( $total_price_including_tax ): self {
		$this->total_price_including_tax = is_null( $total_price_including_tax ) ? null : ( (float) $total_price_including_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_total_tax_amount(): ?float {
		return $this->total_tax_amount;
	}

	/**
	 * @param mixed $total_tax_amount
	 * @return self
	 */
	public function set_total_tax_amount( $total_tax_amount ): self {
		$this->total_tax_amount = is_null( $total_tax_amount ) ? null : ( (float) $total_tax_amount );
		return $this;
	}

	public function jsonSerialize(): array {
		$vars = get_object_vars( $this );
		return $vars;
	}
}
