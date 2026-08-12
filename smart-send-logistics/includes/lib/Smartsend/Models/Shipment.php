<?php

namespace Smartsend\Models;

use Smartsend\Models\Shipment\Sender;
use Smartsend\Models\Shipment\Receiver;
use Smartsend\Models\Shipment\Agent as ShipmentAgent;
use Smartsend\Models\Shipment\Parcel;
use  Smartsend\Models\Shipment\Services;

require_once 'Shipment/Sender.php';
require_once 'Shipment/Receiver.php';
require_once 'Shipment/Agent.php';
require_once 'Shipment/Parcel.php';
require_once 'Shipment/Services.php';

class Shipment implements \JsonSerializable
{
    // Typed properties keep the "= null" default so jsonSerialize()'s
    // get_object_vars() output (and therefore the API payload) is identical
    // to the previous untyped declarations.
    private ?string $internal_id = null;
    private ?string $internal_reference = null;
    private ?string $shipping_carrier = null;
    private ?string $shipping_method = null;
    private ?string $shipping_date = null;
    private ?Sender $sender = null;
    private ?Receiver $receiver = null;
    private ?ShipmentAgent $agent = null;
    private ?array $parcels = null;
    private ?Services $services = null;
    private ?float $subtotal_price_excluding_tax = null;
    private ?float $subtotal_price_including_tax = null;
    private ?float $shipping_price_excluding_tax = null;
    private ?float $shipping_price_including_tax = null;
    private ?float $total_price_excluding_tax = null;
    private ?float $total_price_including_tax = null;
    private ?float $total_tax_amount = null;
    private ?string $currency = null;

    public function __construct(Array $shipment=null)
    {
        if (isset($shipment['internal_id'])) {
            $this->setInternalId($shipment['internal_id']);
        }

        if (isset($shipment['internal_reference'])) {
            $this->setInternalReference($shipment['internal_reference']);
        }

        if (isset($shipment['shipping_carrier'])) {
            $this->setShippingCarrier($shipment['shipping_carrier']);
        }

        if (isset($shipment['shipping_method'])) {
            $this->setShippingMethod($shipment['shipping_method']);
        }

        if (isset($shipment['shipping_date'])) {
            $this->setShippingDate($shipment['shipping_date']);
        }

        if (isset($shipment['sender'])) {
            $this->setSender($shipment['sender']);
        }

        if (isset($shipment['receiver'])) {
            $this->setReceiver($shipment['receiver']);
        }

        if (isset($shipment['agent'])) {
            $this->setAgent($shipment['agent']);
        }

        if (isset($shipment['parcels'])) {
            $this->setParcels($shipment['parcels']);
        }

        if (isset($shipment['services'])) {
            $this->setServices($shipment['services']);
        }

        if (isset($shipment['subtotal_price_excluding_tax'])) {
            $this->setSubtotalPriceExcludingTax($shipment['subtotal_price_excluding_tax']);
        }

        if (isset($shipment['subtotal_price_including_tax'])) {
            $this->setSubtotalPriceIncludingTax($shipment['subtotal_price_including_tax']);
        }

        if (isset($shipment['shipping_price_excluding_tax'])) {
            $this->setShippingPriceExcludingTax($shipment['shipping_price_excluding_tax']);
        }

        if (isset($shipment['shipping_price_including_tax'])) {
            $this->setShippingPriceIncludingTax($shipment['shipping_price_including_tax']);
        }

        if (isset($shipment['total_price_excluding_tax'])) {
            $this->setTotalPriceExcludingTax($shipment['total_price_excluding_tax']);
        }

        if (isset($shipment['total_price_including_tax'])) {
            $this->setTotalPriceIncludingTax($shipment['total_price_including_tax']);
        }

        if (isset($shipment['total_tax_amount'])) {
            $this->setTotalTaxAmount($shipment['total_tax_amount']);
        }

        if (isset($shipment['currency'])) {
            $this->setCurrency($shipment['currency']);
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
    public function getShippingCarrier(): ?string
    {
        return $this->shipping_carrier;
    }

    /**
     * @param string|null $shipping_carrier
     * @return self
     */
    public function setShippingCarrier(?string $shipping_carrier): self
    {
        $this->shipping_carrier = $shipping_carrier;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getShippingMethod(): ?string
    {
        return $this->shipping_method;
    }

    /**
     * @param string|null $shipping_method
     * @return self
     */
    public function setShippingMethod(?string $shipping_method): self
    {
        $this->shipping_method = $shipping_method;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getShippingDate(): ?string
    {
        return $this->shipping_date;
    }

    /**
     * @param string|null $shipping_date
     * @return self
     */
    public function setShippingDate(?string $shipping_date): self
    {
        $this->shipping_date = $shipping_date;
        return $this;
    }

    /**
     * @return Sender|null
     */
    public function getSender(): ?Sender
    {
        return $this->sender;
    }

    /**
     * @param Sender $sender
     * @return self
     */
    public function setSender(Sender $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    /**
     * @return Receiver|null
     */
    public function getReceiver(): ?Receiver
    {
        return $this->receiver;
    }

    /**
     * @param Receiver $receiver
     * @return self
     */
    public function setReceiver(Receiver $receiver): self
    {
        $this->receiver = $receiver;
        return $this;
    }

    /**
     * @return ShipmentAgent|null
     */
    public function getAgent(): ?ShipmentAgent
    {
        return $this->agent;
    }

    /**
     * @param ShipmentAgent $agent
     * @return self
     */
    public function setAgent(ShipmentAgent $agent): self
    {
        $this->agent = $agent;
        return $this;
    }

    /**
     * @return Parcel[]|null
     */
    public function getParcels(): ?array
    {
        return $this->parcels;
    }

    /**
     * @param Parcel[] $parcels
     * @return self
     */
    public function setParcels(array $parcels): self
    {
        $this->parcels = $parcels;
        return $this;
    }

    /**
     * @param Parcel $parcel
     * @return self
     */
    public function addParcel(Parcel $parcel): self
    {
        if (is_array($this->parcels)) {
            $this->parcels[] = $parcel;
        } else {
            $this->setParcels(array($parcel));
        }

        return $this;
    }

    /**
     * @return Services|null
     */
    public function getServices(): ?Services
    {
        return $this->services;
    }

    /**
     * @param Services $services
     * @return self
     */
    public function setServices(Services $services): self
    {
        $this->services = $services;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getSubtotalPriceExcludingTax(): ?float
    {
        return $this->subtotal_price_excluding_tax;
    }

    /**
     * @param mixed $subtotal_price_excluding_tax
     * @return self
     */
    public function setSubtotalPriceExcludingTax($subtotal_price_excluding_tax): self
    {
        $this->subtotal_price_excluding_tax = is_null($subtotal_price_excluding_tax) ? null : ((float) $subtotal_price_excluding_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getSubtotalPriceIncludingTax(): ?float
    {
        return $this->subtotal_price_including_tax;
    }

    /**
     * @param mixed $subtotal_price_including_tax
     * @return self
     */
    public function setSubtotalPriceIncludingTax($subtotal_price_including_tax): self
    {
        $this->subtotal_price_including_tax = is_null($subtotal_price_including_tax) ? null : ((float) $subtotal_price_including_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getShippingPriceExcludingTax(): ?float
    {
        return $this->shipping_price_excluding_tax;
    }

    /**
     * @param mixed $shipping_price_excluding_tax
     * @return self
     */
    public function setShippingPriceExcludingTax($shipping_price_excluding_tax): self
    {
        $this->shipping_price_excluding_tax = is_null($shipping_price_excluding_tax) ? null : ((float) $shipping_price_excluding_tax);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getShippingPriceIncludingTax(): ?float
    {
        return $this->shipping_price_including_tax;
    }

    /**
     * @param mixed $shipping_price_including_tax
     * @return self
     */
    public function setShippingPriceIncludingTax($shipping_price_including_tax): self
    {
        $this->shipping_price_including_tax = is_null($shipping_price_including_tax) ? null : ((float) $shipping_price_including_tax);
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

    /**
     * @return string|null
     */
    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    /**
     * @param string|null $currency
     * @return self
     */
    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }

}