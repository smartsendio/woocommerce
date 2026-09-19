<?php

use Smart_Send\API\Models\Shipment;
use Smart_Send\API\Models\Shipment\Agent;
use Smart_Send\API\Models\Shipment\Item;
use Smart_Send\API\Models\Shipment\Parcel;
use Smart_Send\API\Models\Shipment\Receiver;
use Smart_Send\API\Models\Shipment\Services;

/**
 * Exercise complete wire models, including null defaults and scalar coercion.
 * Golden payloads were captured before #195 from develop commit
 * 9e0344c965bd54d6f4393f3c5e99a308fac18ba7. Expected values remain fixed
 * fixtures, never generated from the implementation under test.
 */
function api_wire_serialization_cases(): array
{
    $receiver = (new Receiver())
        ->set_internal_id(451)
        ->set_internal_reference('')
        ->set_company('Ærø & Co')
        ->set_name_line1('Åse')
        ->set_name_line2(null)
        ->set_address_line1('Østergade 1')
        ->set_address_line2('')
        ->set_postal_code('0012')
        ->set_city('København')
        ->set_country('DK')
        ->set_sms('+4512345678')
        ->set_email('receiver@example.test');

    $agent = (new Agent())
        ->set_internal_id(null)
        ->set_internal_reference(0)
        ->set_agent_no('0007')
        ->set_company('Kiosk / "Nord"')
        ->set_name_line1('Pickup')
        ->set_name_line2('Desk')
        ->set_address_line1('Havnevej 2')
        ->set_address_line2(null)
        ->set_postal_code('2100')
        ->set_city('København')
        ->set_country('DK')
        ->set_sms('')
        ->set_email(null);

    $item = (new Item())
        ->set_internal_id(73)
        ->set_internal_reference(null)
        ->set_sku('SKU/01')
        ->set_name('Krus "Æ"')
        ->set_description('')
        ->set_hs_code('001234')
        ->set_country_of_origin('DK')
        ->set_image_url('https://example.test/image.png')
        ->set_unit_weight('0.75')
        ->set_unit_price_excluding_tax(0)
        ->set_unit_price_including_tax('12.50')
        ->set_quantity(2)
        ->set_total_price_excluding_tax(25)
        ->set_total_price_including_tax(31.25)
        ->set_total_tax_amount(6.25);

    $parcel = (new Parcel())
        ->set_internal_id(123)
        ->set_internal_reference('parcel-1')
        ->set_weight('1.5')
        ->set_height(0)
        ->set_width(null)
        ->set_length('30')
        ->set_freetext("Fragile\nÆøå")
        ->set_items([])
        ->set_total_price_excluding_tax(25)
        ->set_total_price_including_tax(31.25)
        ->set_total_tax_amount(null);

    $services = (new Services())
        ->set_email_notification('')
        ->set_sms_notification(null)
        ->set_flex_delivery(false);

    $shipment = (new Shipment())
        ->set_internal_id(451)
        ->set_internal_reference(null)
        ->set_shipping_carrier('postnord')
        ->set_shipping_method('agent')
        ->set_shipping_date('2026-09-17')
        ->set_receiver(new Receiver())
        ->set_agent(new Agent())
        ->set_parcels([])
        ->add_parcel((new Parcel())->add_item(new Item()))
        ->set_services(new Services())
        ->set_subtotal_price_excluding_tax(0)
        ->set_subtotal_price_including_tax('12.50')
        ->set_shipping_price_excluding_tax(null)
        ->set_shipping_price_including_tax(0)
        ->set_total_price_excluding_tax(25)
        ->set_total_price_including_tax(31.25)
        ->set_total_tax_amount(6.25)
        ->set_currency('DKK');

    return [
        'shipment' => ['defaults' => new Shipment(), 'populated' => $shipment],
        'receiver' => ['defaults' => new Receiver(), 'populated' => $receiver],
        'agent' => ['defaults' => new Agent(), 'populated' => $agent],
        'item' => ['defaults' => new Item(), 'populated' => $item],
        'parcel' => ['defaults' => new Parcel(), 'populated' => $parcel],
        // flex_delivery intentionally accepts multiple types in v1. Preserve
        // that existing serialization contract without narrowing its type here.
        'services' => [
            'defaults' => new Services(),
            'false' => $services,
            'true' => (clone $services)->set_flex_delivery(true),
            'zero' => (clone $services)->set_flex_delivery(0),
            'window' => (clone $services)->set_flex_delivery('09:00-12:00'),
        ],
    ];
}

it('preserves the pre-namespace v1 JSON wire contract', function (string $model) {
    $golden = json_decode(
        file_get_contents(dirname(__DIR__) . '/Support/API/v1-wire-before-namespaces.json'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );

    foreach (api_wire_serialization_cases()[$model] as $scenario => $value) {
        // Compare the JSON used by Client, preserving null versus omitted keys,
        // numbers versus strings, false versus zero, empty arrays and escaping.
        expect(json_encode($value, JSON_THROW_ON_ERROR))
            ->toBe(json_encode($golden->{$model}->{$scenario}, JSON_THROW_ON_ERROR));
    }
})->with(['shipment', 'receiver', 'agent', 'item', 'parcel', 'services']);
