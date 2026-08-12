<?php

namespace Smartsend\Models\Shipment;

use Smartsend\Models\Shipment\Item;

require_once 'Item.php';

class Parcel implements \JsonSerializable
{
    // Typed properties keep "= null" so get_object_vars() serialization is
    // unchanged.
    private ?string $internal_id = null;
    private ?string $internal_reference = null;
    private ?float $weight = null;
    private ?float $height = null;
    private ?float $width = null;
    private ?float $length = null;
    private ?string $freetext = null;
    private ?array $items = null;
    private ?float $total_price_excluding_tax = null;
    private ?float $total_price_including_tax = null;
    private ?float $total_tax_amount = null;

    public function __construct($parcel=array())
    {
        if (isset($parcel['internal_id'])) {
            $this->setInternalId($parcel['internal_id']);
        }

        if (isset($parcel['internal_reference'])) {
            $this->setInternalReference($parcel['internal_reference']);
        }

        if (isset($parcel['weight'])) {
            $this->setWeight($parcel['weight']);
        }

        if (isset($parcel['height'])) {
            $this->setHeight($parcel['height']);
        }

        if (isset($parcel['width'])) {
            $this->setWidth($parcel['width']);
        }

        if (isset($parcel['length'])) {
            $this->setLength($parcel['length']);
        }

        if (isset($parcel['freetext'])) {
            $this->setFreetext($parcel['freetext']);
        }

        if (isset($parcel['items'])) {
            $this->setItems($parcel['items']);
        }

        if (isset($parcel['city'])) {
            $this->setCity($parcel['city']);
        }

        if (isset($parcel['total_price_excluding_tax'])) {
            $this->setTotalPriceExcludingTax($parcel['total_price_excluding_tax']);
        }

        if (isset($parcel['total_price_including_tax'])) {
            $this->setTotalPriceIncludingTax($parcel['total_price_including_tax']);
        }

        if (isset($parcel['total_tax_amount'])) {
            $this->setTotalTaxAmount($parcel['total_tax_amount']);
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
     * @return float|null
     */
    public function getWeight(): ?float
    {
        return $this->weight;
    }

    /**
     * @param mixed $weight
     * @return self
     */
    public function setWeight($weight): self
    {
        $this->weight = is_null($weight) ? null : ((float) $weight);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getHeight(): ?float
    {
        return $this->height;
    }

    /**
     * @param mixed $height
     * @return self
     */
    public function setHeight($height): self
    {
        $this->height = is_null($height) ? null : ((float) $height);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getWidth(): ?float
    {
        return $this->width;
    }

    /**
     * @param mixed $width
     * @return self
     */
    public function setWidth($width): self
    {
        $this->width = is_null($width) ? null : ((float) $width);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getLength(): ?float
    {
        return $this->length;
    }

    /**
     * @param mixed $length
     * @return self
     */
    public function setLength($length): self
    {
        $this->length = is_null($length) ? null : ((float) $length);
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFreetext(): ?string
    {
        return $this->freetext;
    }

    /**
     * @param string|null $freetext
     * @return self
     */
    public function setFreetext(?string $freetext): self
    {
        $this->freetext = $freetext;
        return $this;
    }

    /**
     * @return Item[]|null
     */
    public function getItems(): ?array
    {
        return $this->items;
    }

    /**
     * @param Item[] $items
     * @return self
     */
    public function setItems(array $items): self
    {
        $this->items = $items;
        return $this;
    }

    /**
     * @param Item $item
     * @return self
     */
    public function addItem(Item $item): self
    {
        if (is_array($this->items)) {
            $this->items[] = $item;
        } else {
            $this->setItems(array($item));
        }

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
     * @param mixed $total_price_including_tax
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