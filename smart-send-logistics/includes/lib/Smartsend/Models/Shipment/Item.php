<?php

namespace Smartsend\Models\Shipment;


class Item implements \JsonSerializable
{
    // Typed properties keep "= null" so get_object_vars() serialization is
    // unchanged.
    private ?string $internal_id = null;
    private ?string $internal_reference = null;
    private ?string $sku = null;
    private ?string $name = null;
    private ?string $description = null;
    private ?string $hs_code = null;
    private ?string $country_of_origin = null;
    private ?string $image_url = null;
    private ?float $unit_weight = null;
    private ?float $unit_price_excluding_tax = null;
    private ?float $unit_price_including_tax = null;
    private ?float $quantity = null;
    private ?float $total_price_excluding_tax = null;
    private ?float $total_price_including_tax = null;
    private ?float $total_tax_amount = null;

    public function __construct($item=array())
    {
        if (isset($item['internal_id'])) {
            $this->setInternalId($item['internal_id']);
        }

        if (isset($item['internal_reference'])) {
            $this->setInternalReference($item['internal_reference']);
        }

        if (isset($item['sku'])) {
            $this->setSku($item['sku']);
        }

        if (isset($item['name'])) {
            $this->setName($item['name']);
        }

        if (isset($item['description'])) {
            $this->setDescription($item['description']);
        }

        if (isset($item['hs_code'])) {
            $this->setHsCode($item['hs_code']);
        }

        if (isset($item['country_of_origin'])) {
            $this->setCountryOfOrigin($item['country_of_origin']);
        }

        if (isset($item['image_url'])) {
            $this->setImageUrl($item['image_url']);
        }

        if (isset($item['unit_weight'])) {
            $this->setUnitWeight($item['unit_weight']);
        }

        if (isset($item['unit_price_excluding_tax'])) {
            $this->setUnitPriceExcludingTax($item['unit_price_excluding_tax']);
        }

        if (isset($item['unit_price_including_tax'])) {
            $this->setUnitPriceIncludingTax($item['unit_price_including_tax']);
        }

        if (isset($item['quantity'])) {
            $this->setQuantity($item['quantity']);
        }

        if (isset($item['total_price_excluding_tax'])) {
            $this->setTotalPriceExcludingTax($item['total_price_excluding_tax']);
        }

        if (isset($item['total_price_including_tax'])) {
            $this->setTotalPriceIncludingTax($item['total_price_including_tax']);
        }

        if (isset($item['total_tax_amount'])) {
            $this->setTotalTaxAmount($item['total_tax_amount']);
        }
    }

    /**
     * @return string|null
     */
    public function getInternalId(): ?string
    {
        return $this->internal_id;
    }

    /**
     * @param string $internal_id
     * @return self
     */
    public function setInternalId($internal_id): self
    {
        $this->internal_id = (string) $internal_id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getInternalReference(): ?string
    {
        return $this->internal_reference;
    }

    /**
     * @param string $internal_reference
     * @return self
     */
    public function setInternalReference($internal_reference): self
    {
        $this->internal_reference = (string) $internal_reference;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSku(): ?string
    {
        return $this->sku;
    }

    /**
     * @param string|null $sku
     * @return self
     */
    public function setSku(?string $sku): self
    {
        $this->sku = $sku;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string|null $name
     * @return self
     */
    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     * @return self
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getHsCode(): ?string
    {
        return $this->hs_code;
    }

    /**
     * @param string|null $hs_code
     * @return self
     */
    public function setHsCode(?string $hs_code): self
    {
        $this->hs_code = $hs_code;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCountryOfOrigin(): ?string
    {
        return $this->country_of_origin;
    }

    /**
     * @param string|null $country_of_origin
     * @return self
     */
    public function setCountryOfOrigin(?string $country_of_origin): self
    {
        $this->country_of_origin = $country_of_origin;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getImageUrl(): ?string
    {
        return $this->image_url;
    }

    /**
     * @param string|null $image_url
     * @return self
     */
    public function setImageUrl(?string $image_url): self
    {
        $this->image_url = $image_url;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getUnitWeight(): ?float
    {
        return $this->unit_weight;
    }

    /**
     * @param mixed $unit_weight
     * @return self
     */
    public function setUnitWeight($unit_weight): self
    {

        $this->unit_weight = is_null($unit_weight) ? null : ((float) $unit_weight);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getUnitPriceExcludingTax(): ?float
    {
        return $this->unit_price_excluding_tax;
    }

    /**
     * @param mixed $unit_price_excluding_tax
     * @return self
     */
    public function setUnitPriceExcludingTax($unit_price_excluding_tax): self
    {
        $this->unit_price_excluding_tax = is_null($unit_price_excluding_tax) ? null : ((float) $unit_price_excluding_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getUnitPriceIncludingTax(): ?float
    {
        return $this->unit_price_including_tax;
    }

    /**
     * @param mixed $unit_price_including_tax
     * @return self
     */
    public function setUnitPriceIncludingTax($unit_price_including_tax): self
    {
        $this->unit_price_including_tax = is_null($unit_price_including_tax) ? null : ((float) $unit_price_including_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    /**
     * @param mixed $quantity
     * @return self
     */
    public function setQuantity($quantity): self
    {
        $this->quantity = is_null($quantity) ? null : ((float) $quantity);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getTotalPriceExcludingTax(): ?float
    {
        return $this->total_price_excluding_tax;
    }

    /**
     * @param mixed $total_price_excluding_tax
     * @return self
     */
    public function setTotalPriceExcludingTax($total_price_excluding_tax): self
    {
        $this->total_price_excluding_tax = is_null($total_price_excluding_tax) ? null : ((float) $total_price_excluding_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getTotalPriceIncludingTax(): ?float
    {
        return $this->total_price_including_tax;
    }

    /**
     * @param mixed $total_price_including_tax;
     * @return self
     */
    public function setTotalPriceIncludingTax($total_price_including_tax): self
    {
        $this->total_price_including_tax = is_null($total_price_including_tax) ? null : ((float) $total_price_including_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getTotalTaxAmount(): ?float
    {
        return $this->total_tax_amount;
    }

    /**
     * @param mixed $total_tax_amount
     * @return self
     */
    public function setTotalTaxAmount($total_tax_amount): self
    {
        $this->total_tax_amount = is_null($total_tax_amount) ? null : ((float) $total_tax_amount);
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }

}