<?php

namespace Smart_Send\API\Models\Shipment;

class Parcel implements \JsonSerializable {

	// Typed properties keep "= null" so get_object_vars() serialization is
	// unchanged.
	private ?string $internal_id              = null;
	private ?string $internal_reference       = null;
	private ?float $weight                    = null;
	private ?float $height                    = null;
	private ?float $width                     = null;
	private ?float $length                    = null;
	private ?string $freetext                 = null;
	private ?array $items                     = null;
	private ?float $total_price_excluding_tax = null;
	private ?float $total_price_including_tax = null;
	private ?float $total_tax_amount          = null;

	public function __construct( $parcel = array() ) {
		if ( isset( $parcel['internal_id'] ) ) {
			$this->set_internal_id( $parcel['internal_id'] );
		}

		if ( isset( $parcel['internal_reference'] ) ) {
			$this->set_internal_reference( $parcel['internal_reference'] );
		}

		if ( isset( $parcel['weight'] ) ) {
			$this->set_weight( $parcel['weight'] );
		}

		if ( isset( $parcel['height'] ) ) {
			$this->set_height( $parcel['height'] );
		}

		if ( isset( $parcel['width'] ) ) {
			$this->set_width( $parcel['width'] );
		}

		if ( isset( $parcel['length'] ) ) {
			$this->set_length( $parcel['length'] );
		}

		if ( isset( $parcel['freetext'] ) ) {
			$this->set_freetext( $parcel['freetext'] );
		}

		if ( isset( $parcel['items'] ) ) {
			$this->set_items( $parcel['items'] );
		}

		if ( isset( $parcel['city'] ) ) {
			$this->set_city( $parcel['city'] );
		}

		if ( isset( $parcel['total_price_excluding_tax'] ) ) {
			$this->set_total_price_excluding_tax( $parcel['total_price_excluding_tax'] );
		}

		if ( isset( $parcel['total_price_including_tax'] ) ) {
			$this->set_total_price_including_tax( $parcel['total_price_including_tax'] );
		}

		if ( isset( $parcel['total_tax_amount'] ) ) {
			$this->set_total_tax_amount( $parcel['total_tax_amount'] );
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
	 * @return float|null
	 */
	public function get_weight(): ?float {
		return $this->weight;
	}

	/**
	 * @param mixed $weight
	 * @return self
	 */
	public function set_weight( $weight ): self {
		$this->weight = is_null( $weight ) ? null : ( (float) $weight );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_height(): ?float {
		return $this->height;
	}

	/**
	 * @param mixed $height
	 * @return self
	 */
	public function set_height( $height ): self {
		$this->height = is_null( $height ) ? null : ( (float) $height );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_width(): ?float {
		return $this->width;
	}

	/**
	 * @param mixed $width
	 * @return self
	 */
	public function set_width( $width ): self {
		$this->width = is_null( $width ) ? null : ( (float) $width );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_length(): ?float {
		return $this->length;
	}

	/**
	 * @param mixed $length
	 * @return self
	 */
	public function set_length( $length ): self {
		$this->length = is_null( $length ) ? null : ( (float) $length );
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_freetext(): ?string {
		return $this->freetext;
	}

	/**
	 * @param string|null $freetext
	 * @return self
	 */
	public function set_freetext( ?string $freetext ): self {
		$this->freetext = $freetext;
		return $this;
	}

	/**
	 * @return Item[]|null
	 */
	public function get_items(): ?array {
		return $this->items;
	}

	/**
	 * @param Item[] $items
	 * @return self
	 */
	public function set_items( array $items ): self {
		$this->items = $items;
		return $this;
	}

	/**
	 * @param Item $item
	 * @return self
	 */
	public function add_item( Item $item ): self {
		if ( is_array( $this->items ) ) {
			$this->items[] = $item;
		} else {
			$this->set_items( array( $item ) );
		}

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
	 * @param mixed $total_price_including_tax
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
