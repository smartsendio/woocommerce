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
 * assembled by SS_Shipping_Shipment_Builder (#113) - a typed WP-side value
 * object, \SS_Shipping_Shipment, not to be confused with this namespace's
 * own Shipment (the v1 wire model, see the use statement below) - is
 * translated into the v1 wire request (Smartsend\Models\Shipment and its
 * sub-models). The excl/incl price pairs the v1 API expects are
 * (re)computed here, once, from the representation's single net/tax
 * amounts. A future v2 client would replace fromShipment() with a
 * v2-shaped translation; nothing above this resource (\SS_Shipping_Shipment,
 * SS_Shipping_Shipment_Builder, SS_Shipping_Order_Reader) would need to
 * change (#111).
 *
 * This class deliberately stays WordPress-light: it only depends on
 * \SS_Shipping_Shipment by its fully-qualified global name as a type hint,
 * and that class itself makes no WordPress calls - the dependency points
 * from WP-side code down into this PSR-style lib, never the other way
 * around. It must never construct or return a WP-side type such as
 * SS_Shipping_Booking; that wrapping is SS_Shipping_Booking_Service's job.
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
     * @param   Shipment $shipment The v1 wire shipment, see self::fromShipment().
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
     * @param   \SS_Shipping_Shipment $shipment The internal shipment representation from SS_Shipping_Shipment_Builder.
     * @return  Shipment
     */
    public function fromShipment(\SS_Shipping_Shipment $shipment): Shipment
    {
        $receiver = $this->buildReceiver($shipment);

        $wire_shipment = new Shipment();
        $wire_shipment->setReceiver($receiver);

        if (!empty($shipment->get_pickup_point())) {
            $wire_shipment->setAgent($this->buildAgent($shipment->get_pickup_point()));
        }

        $parcels = array();
        foreach ($shipment->get_parcels() as $parcel_row) {
            $parcels[] = $this->buildParcel($parcel_row);
        }

        // Create services.
        $services = new Services();
        $services->setSmsNotification($receiver->getSms()) // Always enable SMS notification.
            ->setEmailNotification($receiver->getEmail()); // Always enable Email notification.

        $wire_shipment->setInternalId($shipment->get_internal_id())
            ->setInternalReference($this->valueOrNull($shipment->get_internal_reference()))
            ->setShippingCarrier($this->valueOrNull($shipment->get_shipping_carrier()))
            ->setShippingMethod($this->valueOrNull($shipment->get_shipping_method()))
            ->setShippingDate($shipment->get_shipping_date())
            ->setParcels($parcels) // Alternatively add each parcel using $shipment->addParcel(Parcel $parcel).
            ->setServices($services)
            ->setSubtotalPriceExcludingTax($this->valueOrNull($shipment->get_subtotal_net_amount()))
            ->setSubtotalPriceIncludingTax($this->valueOrNull($this->netPlusTax($shipment->get_subtotal_net_amount(), $shipment->get_subtotal_tax_amount())))
            ->setTotalPriceExcludingTax($this->valueOrNull($shipment->get_total_net_amount()))
            ->setTotalPriceIncludingTax($this->valueOrNull($this->netPlusTax($shipment->get_total_net_amount(), $shipment->get_total_tax_amount())))
            ->setShippingPriceExcludingTax($this->valueOrNull($shipment->get_shipping_net_amount()))
            ->setShippingPriceIncludingTax($this->valueOrNull($this->netPlusTax($shipment->get_shipping_net_amount(), $shipment->get_shipping_tax_amount())))
            ->setTotalTaxAmount($this->valueOrNull($shipment->get_total_tax_amount()))
            ->setCurrency($this->valueOrNull($shipment->get_currency()));

        return $wire_shipment;
    }

    /**
     * Build the receiver model from the representation's receiver section.
     *
     * @param   \SS_Shipping_Shipment $shipment The internal shipment representation.
     * @return  Receiver
     */
    protected function buildReceiver(\SS_Shipping_Shipment $shipment): Receiver
    {
        $receiver_data = $shipment->get_receiver();

        $receiver = new Receiver();
        $receiver->setInternalId($shipment->get_internal_id())
            ->setInternalReference($this->valueOrNull($shipment->get_internal_reference()))
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
     * @param   array $pickup_point Pickup point data, sourced from \SS_Shipping_Shipment::get_pickup_point(), see SS_Shipping_Shipment_Builder::build_pickup_point().
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
     * @param   array $item_row Item row, sourced from one of \SS_Shipping_Shipment::get_parcels()'s 'items' arrays, see SS_Shipping_Order_Reader::get_items_data().
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
     * @param   array $parcel_row Parcel row, sourced from \SS_Shipping_Shipment::get_parcels().
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
