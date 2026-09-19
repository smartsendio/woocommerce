<?php

namespace Smart_Send\API\Models;

use Smart_Send\API\Models\Shipment\Receiver;
use Smart_Send\API\Models\Shipment\Agent as Shipment_Agent;
use Smart_Send\API\Models\Shipment\Parcel;
use Smart_Send\API\Models\Shipment\Services;


class Shipment implements \JsonSerializable {

	// Typed properties keep the "= null" default so jsonSerialize()'s
	// get_object_vars() output (and therefore the API payload) is identical
	// to the previous untyped declarations.
	private ?string $internal_id        = null;
	private ?string $internal_reference = null;
	private ?string $shipping_carrier   = null;
	private ?string $shipping_method    = null;
	private ?string $shipping_date      = null;
	// $sender: always null - the plugin never sets a sender (the Smart Send
	// account's own address is used server-side) and the Sender model was
	// deleted as dead code (#141), but the null "sender" key stays part of
	// the frozen v1 wire payload (see the ShipmentPayloadTest goldens).
	private $sender                              = null;
	private ?Receiver $receiver                  = null;
	private ?Shipment_Agent $agent               = null;
	private ?array $parcels                      = null;
	private ?Services $services                  = null;
	private ?float $subtotal_price_excluding_tax = null;
	private ?float $subtotal_price_including_tax = null;
	private ?float $shipping_price_excluding_tax = null;
	private ?float $shipping_price_including_tax = null;
	private ?float $total_price_excluding_tax    = null;
	private ?float $total_price_including_tax    = null;
	private ?float $total_tax_amount             = null;
	private ?string $currency                    = null;

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
	public function get_shipping_carrier(): ?string {
		return $this->shipping_carrier;
	}

	/**
	 * @param string|null $shipping_carrier
	 * @return self
	 */
	public function set_shipping_carrier( ?string $shipping_carrier ): self {
		$this->shipping_carrier = $shipping_carrier;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_shipping_method(): ?string {
		return $this->shipping_method;
	}

	/**
	 * @param string|null $shipping_method
	 * @return self
	 */
	public function set_shipping_method( ?string $shipping_method ): self {
		$this->shipping_method = $shipping_method;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_shipping_date(): ?string {
		return $this->shipping_date;
	}

	/**
	 * @param string|null $shipping_date
	 * @return self
	 */
	public function set_shipping_date( ?string $shipping_date ): self {
		$this->shipping_date = $shipping_date;
		return $this;
	}

	/**
	 * @return Receiver|null
	 */
	public function get_receiver(): ?Receiver {
		return $this->receiver;
	}

	/**
	 * @param Receiver $receiver
	 * @return self
	 */
	public function set_receiver( Receiver $receiver ): self {
		$this->receiver = $receiver;
		return $this;
	}

	/**
	 * @return Shipment_Agent|null
	 */
	public function get_agent(): ?Shipment_Agent {
		return $this->agent;
	}

	/**
	 * @param Shipment_Agent $agent
	 * @return self
	 */
	public function set_agent( Shipment_Agent $agent ): self {
		$this->agent = $agent;
		return $this;
	}

	/**
	 * @return Parcel[]|null
	 */
	public function get_parcels(): ?array {
		return $this->parcels;
	}

	/**
	 * @param Parcel[] $parcels
	 * @return self
	 */
	public function set_parcels( array $parcels ): self {
		$this->parcels = $parcels;
		return $this;
	}

	/**
	 * @param Parcel $parcel
	 * @return self
	 */
	public function add_parcel( Parcel $parcel ): self {
		if ( is_array( $this->parcels ) ) {
			$this->parcels[] = $parcel;
		} else {
			$this->set_parcels( array( $parcel ) );
		}

		return $this;
	}

	/**
	 * @return Services|null
	 */
	public function get_services(): ?Services {
		return $this->services;
	}

	/**
	 * @param Services $services
	 * @return self
	 */
	public function set_services( Services $services ): self {
		$this->services = $services;
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_subtotal_price_excluding_tax(): ?float {
		return $this->subtotal_price_excluding_tax;
	}

	/**
	 * @param mixed $subtotal_price_excluding_tax
	 * @return self
	 */
	public function set_subtotal_price_excluding_tax( $subtotal_price_excluding_tax ): self {
		$this->subtotal_price_excluding_tax = is_null( $subtotal_price_excluding_tax ) ? null : ( (float) $subtotal_price_excluding_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_subtotal_price_including_tax(): ?float {
		return $this->subtotal_price_including_tax;
	}

	/**
	 * @param mixed $subtotal_price_including_tax
	 * @return self
	 */
	public function set_subtotal_price_including_tax( $subtotal_price_including_tax ): self {
		$this->subtotal_price_including_tax = is_null( $subtotal_price_including_tax ) ? null : ( (float) $subtotal_price_including_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_shipping_price_excluding_tax(): ?float {
		return $this->shipping_price_excluding_tax;
	}

	/**
	 * @param mixed $shipping_price_excluding_tax
	 * @return self
	 */
	public function set_shipping_price_excluding_tax( $shipping_price_excluding_tax ): self {
		$this->shipping_price_excluding_tax = is_null( $shipping_price_excluding_tax ) ? null : ( (float) $shipping_price_excluding_tax );
		return $this;
	}

	/**
	 * @return float|null
	 */
	public function get_shipping_price_including_tax(): ?float {
		return $this->shipping_price_including_tax;
	}

	/**
	 * @param mixed $shipping_price_including_tax
	 * @return self
	 */
	public function set_shipping_price_including_tax( $shipping_price_including_tax ): self {
		$this->shipping_price_including_tax = is_null( $shipping_price_including_tax ) ? null : ( (float) $shipping_price_including_tax );
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

	/**
	 * @return string|null
	 */
	public function get_currency(): ?string {
		return $this->currency;
	}

	/**
	 * @param string|null $currency
	 * @return self
	 */
	public function set_currency( ?string $currency ): self {
		$this->currency = $currency;
		return $this;
	}

	public function jsonSerialize(): array {
		$vars = get_object_vars( $this );
		return $vars;
	}
}
