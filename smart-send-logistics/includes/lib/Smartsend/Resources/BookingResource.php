<?php

namespace Smartsend\Resources;

require_once __DIR__ . '/../Client.php';
require_once __DIR__ . '/../Models/Shipment.php';

use Smartsend\Client;
use Smartsend\Models\Shipment;
use Smartsend\Models\Shipment\Agent as ShipmentAgent;
use Smartsend\Models\Shipment\Item;
use Smartsend\Models\Shipment\Parcel;
use Smartsend\Models\Shipment\Receiver;
use Smartsend\Models\Shipment\Services;

/**
 * Books a shipment (and its labels) with the Smart Send API.
 *
 * This is the single point where the internal shipment representation
 * assembled by SS_Shipping_Shipment_Builder (#113) is translated into the
 * v1 wire request (Smartsend\Models\Shipment and its sub-models) - the
 * excl/incl price pairs the v1 API expects are (re)computed here, once,
 * from the representation's single net/tax amounts. A future v2 client
 * would replace fromRepresentation() with a v2-shaped translation; nothing
 * above this resource (SS_Shipping_Shipment, SS_Shipping_Shipment_Builder,
 * SS_Shipping_Order_Data) would need to change (#111).
 */
class BookingResource
{
    /** @var Client */
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Book a shipment and create its labels in a single call.
     *
     * @param   Shipment $shipment The v1 wire shipment, see self::fromRepresentation().
     * @return  object|true|false
     */
    public function create(Shipment $shipment)
    {
        return $this->client->httpPost('shipments/labels', array(), array(), $shipment);
    }

    /**
     * Combine the labels of multiple already-booked shipments into a
     * single PDF.
     *
     * @param   string[] $shipment_ids
     * @return  object|true|false
     */
    public function combine(array $shipment_ids)
    {
        $request = array(
            'shipments' => array(),
        );

        foreach ($shipment_ids as $shipment_id) {
            $request['shipments'][] = array('shipment_id' => $shipment_id);
        }

        return $this->client->httpPost('shipments/labels/combine', array(), array(), $request);
    }

    /**
     * Translate the internal shipment representation into the v1 wire
     * shipment model.
     *
     * @param   array $representation The internal shipment representation from SS_Shipping_Shipment_Builder::build().
     * @return  Shipment
     */
    public function fromRepresentation(array $representation): Shipment
    {
        $receiver = $this->buildReceiver($representation);

        $shipment = new Shipment();
        $shipment->setReceiver($receiver);

        if (!empty($representation['pickup_point'])) {
            $shipment->setAgent($this->buildAgent($representation['pickup_point']));
        }

        $parcels = array();
        foreach ($representation['parcels'] as $parcel_row) {
            $parcels[] = $this->buildParcel($parcel_row);
        }

        // Create services.
        $services = new Services();
        $services->setSmsNotification($receiver->getSms()) // Always enable SMS notification.
            ->setEmailNotification($receiver->getEmail()); // Always enable Email notification.

        $shipment->setInternalId($representation['internal_id'])
            ->setInternalReference($this->valueOrNull($representation['internal_reference']))
            ->setShippingCarrier($this->valueOrNull($representation['shipping_carrier']))
            ->setShippingMethod($this->valueOrNull($representation['shipping_method']))
            ->setShippingDate($representation['shipping_date'])
            ->setParcels($parcels) // Alternatively add each parcel using $shipment->addParcel(Parcel $parcel).
            ->setServices($services)
            ->setSubtotalPriceExcludingTax($this->valueOrNull($representation['subtotal_net_amount']))
            ->setSubtotalPriceIncludingTax($this->valueOrNull($this->netPlusTax($representation['subtotal_net_amount'], $representation['subtotal_tax_amount'])))
            ->setTotalPriceExcludingTax($this->valueOrNull($representation['total_net_amount']))
            ->setTotalPriceIncludingTax($this->valueOrNull($this->netPlusTax($representation['total_net_amount'], $representation['total_tax_amount'])))
            ->setShippingPriceExcludingTax($this->valueOrNull($representation['shipping_net_amount']))
            ->setShippingPriceIncludingTax($this->valueOrNull($this->netPlusTax($representation['shipping_net_amount'], $representation['shipping_tax_amount'])))
            ->setTotalTaxAmount($this->valueOrNull($representation['total_tax_amount']))
            ->setCurrency($this->valueOrNull($representation['currency']));

        return $shipment;
    }

    /**
     * Build the receiver model from the representation's receiver section.
     *
     * @param   array $representation The internal shipment representation.
     * @return  Receiver
     */
    protected function buildReceiver(array $representation): Receiver
    {
        $receiver_data = $representation['receiver'];

        $receiver = new Receiver();
        $receiver->setInternalId($representation['internal_id'])
            ->setInternalReference($this->valueOrNull($representation['internal_reference']))
            ->setCompany($this->valueOrNull($receiver_data['company']))
            ->setNameLine1($this->valueOrNull($receiver_data['name_line1']))
            ->setNameLine2($this->valueOrNull($receiver_data['name_line2']))
            ->setAddressLine1($this->valueOrNull($receiver_data['address_line1']))
            ->setAddressLine2($this->valueOrNull($receiver_data['address_line2']))
            ->setPostalCode($this->valueOrNull($receiver_data['postal_code']))
            ->setCity($this->valueOrNull($receiver_data['city']))
            ->setCountry($this->valueOrNull($receiver_data['country']))
            ->setSms($this->valueOrNull($receiver_data['phone']))
            ->setEmail($this->valueOrNull($receiver_data['email']));

        return $receiver;
    }

    /**
     * Build the agent (pickup point) model from the representation's
     * pickup-point section.
     *
     * @param   array $pickup_point Pickup point data, see SS_Shipping_Shipment_Builder::build_pickup_point().
     * @return  ShipmentAgent
     */
    protected function buildAgent(array $pickup_point): ShipmentAgent
    {
        $agent = new ShipmentAgent();
        $agent->setInternalId($pickup_point['internal_id'])
            ->setInternalReference($pickup_point['internal_reference'])
            ->setAgentNo($pickup_point['service_point_code'])
            ->setCompany($pickup_point['company'])
            ->setAddressLine1($pickup_point['address_line1'])
            ->setAddressLine2($pickup_point['address_line2'])
            ->setPostalCode($pickup_point['postal_code'])
            ->setCity($pickup_point['city'])
            ->setCountry($pickup_point['country']);

        return $agent;
    }

    /**
     * Build an item model from an item row of the representation.
     *
     * @param   array $item_row Item row, see SS_Shipping_Order_Data::get_items_data().
     * @return  Item
     */
    protected function buildItem(array $item_row): Item
    {
        $quantity = $item_row['quantity'] ? $item_row['quantity'] : 1;

        // The wire format's unit_price_* pair is recomputed here, once,
        // from the representation's single net/tax amount (#113).
        $unit_net_amount = $item_row['total_net_amount'] / $quantity;
        $unit_tax_amount = $item_row['total_tax_amount'] / $quantity;

        $item = new Item();
        $item->setInternalId($this->valueOrNull($item_row['id']))
            ->setInternalReference($this->valueOrNull($item_row['id']))
            ->setSku($this->valueOrNull($item_row['sku']))
            ->setName($this->valueOrNull($item_row['name']))
            ->setDescription($this->valueOrNull($item_row['description'])) // The product description can be used, but is often too long (255).
            ->setHsCode($this->valueOrNull($item_row['hs_code']))
            ->setCountryOfOrigin($this->valueOrNull($item_row['country_of_origin']))
            ->setImageUrl(null) // The product image url can be used, but sometimes includes spaces (bug) which causes validation error.
            ->setUnitWeight($item_row['unit_weight'] > 0 ? $item_row['unit_weight'] : null)
            ->setUnitPriceExcludingTax($this->valueOrNull($unit_net_amount))
            ->setUnitPriceIncludingTax($this->valueOrNull($unit_net_amount + $unit_tax_amount))
            ->setQuantity($this->valueOrNull($item_row['quantity']))
            ->setTotalPriceExcludingTax($this->valueOrNull($item_row['total_net_amount']))
            ->setTotalPriceIncludingTax($this->valueOrNull($this->netPlusTax($item_row['total_net_amount'], $item_row['total_tax_amount'])))
            ->setTotalTaxAmount($this->valueOrNull($item_row['total_tax_amount']));

        return $item;
    }

    /**
     * Build a parcel model from a parcel row of the representation.
     *
     * @param   array $parcel_row Parcel row, see SS_Shipping_Shipment_Builder::build().
     * @return  Parcel
     */
    protected function buildParcel(array $parcel_row): Parcel
    {
        $items = array();
        foreach ($parcel_row['items'] as $item_row) {
            $items[] = $this->buildItem($item_row);
        }

        $parcel = new Parcel();
        $parcel->setInternalId($this->valueOrNull($parcel_row['internal_id']))
            ->setInternalReference($this->valueOrNull($parcel_row['internal_reference']))
            ->setWeight($this->valueOrNull($parcel_row['weight']))
            ->setHeight($parcel_row['height'])
            ->setWidth($parcel_row['width'])
            ->setLength($parcel_row['length'])
            ->setFreetext($this->valueOrNull($parcel_row['freetext']))
            ->setItems($items) // Alternatively add each item using $parcel->addItem(Item $item).
            ->setTotalPriceExcludingTax($this->valueOrNull($parcel_row['total_net_amount']))
            ->setTotalPriceIncludingTax($this->valueOrNull($this->netPlusTax($parcel_row['total_net_amount'], $parcel_row['total_tax_amount'])))
            ->setTotalTaxAmount($this->valueOrNull($parcel_row['total_tax_amount']));

        return $parcel;
    }

    /**
     * Add a net amount and a tax amount together, treating a null operand
     * as zero - the including-tax wire figure derived from the
     * representation's single net/tax amount (#113).
     *
     * @param   float|null $net_amount Net (excluding tax) amount.
     * @param   float|null $tax_amount Tax amount.
     * @return  float
     */
    protected function netPlusTax($net_amount, $tax_amount)
    {
        return (float) $net_amount + (float) $tax_amount;
    }

    /**
     * Return the value when truthy, null otherwise (the API models expect
     * null instead of empty/zero values).
     *
     * @param   mixed $value The value to check.
     * @return  mixed|null
     */
    protected function valueOrNull($value)
    {
        if ($value) {
            return $value;
        }

        return null;
    }
}
