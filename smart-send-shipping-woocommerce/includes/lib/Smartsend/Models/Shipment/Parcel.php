<?php

namespace Smartsend\Models\Shipment;

use Smartsend\Model\Shipment\Item;

require_once 'Item.php';

class Parcel implements \JsonSerializable
{
    private $internal_id;
    private $internal_reference;
    private $weight;
    private $height;
    private $width;
    private $length;
    private $freetext1;
    private $freetext2;
    private $freetext3;
    private $items;
    private $total_price_excluding_tax;
    private $total_price_including_tax;
    private $total_tax_amount;

    public function __construct($parcel=array())
    {
        if(isset($parcel['internal_id'])) {
            $this->setInternalId($parcel['internal_id']);
        }
        if(isset($parcel['internal_reference'])) {
            $this->setInternalReference($parcel['internal_reference']);
        }
        if(isset($parcel['weight'])) {
            $this->setWeight($parcel['weight']);
        }
        if(isset($parcel['height'])) {
            $this->setHeight($parcel['height']);
        }
        if(isset($parcel['width'])) {
            $this->setWidth($parcel['width']);
        }
        if(isset($parcel['length'])) {
            $this->setLength($parcel['length']);
        }
        if(isset($parcel['freetext1'])) {
            $this->setFreetext1($parcel['freetext1']);
        }
        if(isset($parcel['freetext2'])) {
            $this->setFreetext2($parcel['freetext2']);
        }
        if(isset($parcel['freetext3'])) {
            $this->setFreetext3($parcel['freetext3']);
        }
        if(isset($parcel['items'])) {
            $this->setItems($parcel['items']);
        }
        if(isset($parcel['city'])) {
            $this->setCity($parcel['city']);
        }
        if(isset($parcel['total_price_excluding_tax'])) {
            $this->setTotalPriceExcludingTax($parcel['total_price_excluding_tax']);
        }
        if(isset($parcel['total_price_including_tax'])) {
            $this->setTotalPriceIncludingTax($parcel['total_price_including_tax']);
        }
        if(isset($parcel['total_tax_amount'])) {
            $this->setTotalTaxAmount($parcel['total_tax_amount']);
        }
    }

    /**
     * @return mixed
     */
    public function getInternalId()
    {
        return $this->internal_id;
    }

    /**
     * @param mixed $internal_id
     * @return Parcel
     */
    public function setInternalId($internal_id)
    {
        $this->internal_id = $internal_id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getInternalReference()
    {
        return $this->internal_reference;
    }

    /**
     * @param mixed $internal_reference
     * @return Parcel
     */
    public function setInternalReference($internal_reference)
    {
        $this->internal_reference = $internal_reference;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getWeight()
    {
        return $this->weight;
    }

    /**
     * @param mixed $weight
     * @return Parcel
     */
    public function setWeight($weight)
    {
        $this->weight = $weight;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * @param mixed $height
     * @return Parcel
     */
    public function setHeight($height)
    {
        $this->height = $height;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @param mixed $width
     * @return Parcel
     */
    public function setWidth($width)
    {
        $this->width = $width;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getLength()
    {
        return $this->length;
    }

    /**
     * @param mixed $length
     * @return Parcel
     */
    public function setLength($length)
    {
        $this->length = $length;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFreetext1()
    {
        return $this->freetext1;
    }

    /**
     * @param mixed $freetext1
     * @return Parcel
     */
    public function setFreetext1($freetext1)
    {
        $this->freetext1 = $freetext1;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFreetext2()
    {
        return $this->freetext2;
    }

    /**
     * @param mixed $freetext2
     * @return Parcel
     */
    public function setFreetext2($freetext2)
    {
        $this->freetext2 = $freetext2;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFreetext3()
    {
        return $this->freetext3;
    }

    /**
     * @param mixed $freetext3
     * @return Parcel
     */
    public function setFreetext3($freetext3)
    {
        $this->freetext3 = $freetext3;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @param array $items
     * @return Parcel
     */
    public function setItems(Array $items)
    {
        $this->items = $items;
        return $this;
    }

    /**
     * @param Item $item
     * @return Parcel
     */
    public function addItem(Item $item)
    {
        if(is_array($this->items)) {
            $this->items[] = $item;
        } else {
            $this->setItems(array($item));
        }
        return $this;
    }

    /**
     * @return mixed
     */
    public function getTotalPriceExcludingTax()
    {
        return $this->total_price_excluding_tax;
    }

    /**
     * @param mixed $total_price_excluding_tax
     * @return Parcel
     */
    public function setTotalPriceExcludingTax($total_price_excluding_tax)
    {
        $this->total_price_excluding_tax = $total_price_excluding_tax;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getTotalPriceIncludingTax()
    {
        return $this->total_price_including_tax;
    }

    /**
     * @param mixed $total_price_including_tax
     * @return Parcel
     */
    public function setTotalPriceIncludingTax($total_price_including_tax)
    {
        $this->total_price_including_tax = $total_price_including_tax;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getTotalTaxAmount()
    {
        return $this->total_tax_amount;
    }

    /**
     * @param mixed $total_tax_amount
     * @return Parcel
     */
    public function setTotalTaxAmount($total_tax_amount)
    {
        $this->total_tax_amount = $total_tax_amount;
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }

}