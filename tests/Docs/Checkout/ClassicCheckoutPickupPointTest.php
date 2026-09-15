<?php

/*
|--------------------------------------------------------------------------
| Guides -> Checkout -> Pickup point selection (classic checkout)
|--------------------------------------------------------------------------
|
| Screenshots for the customer-facing pickup point documentation on the
| classic (shortcode) checkout: the shipping methods offered at checkout,
| the pickup point dropdown appearing when a pickup point shipping method
| is chosen, and a pickup point selected.
|
| One test per UI state, each producing its own named screenshot under
| docs/screenshots/Checkout/ (see tests/Docs/Support/Screenshots.php).
| Store state (zone + method, product, classic checkout page, the API mock
| feeding the dropdown) comes from the Browser suite's shared seeding
| helpers; pest-plugin-browser resets the browser after every test, so each
| test walks the checkout journey from the start - and the visit() call has
| to stay inline in every test closure for pest-plugin-browser to recognise
| it as a browser test (see the ShippingMethod test's docblock).
|
*/

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_seed_store();
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('shows the shipping methods offered at the classic checkout', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page = $page->navigate(base_url('/?page_id=' . $state['checkout_page_id']))
        ->assertSee('Billing details')
        ->fill('#billing_first_name', 'Docs')
        ->fill('#billing_last_name', 'Screenshot')
        ->fill('#billing_address_1', 'Islands Brygge 39')
        ->fill('#billing_city', 'Copenhagen')
        ->fill('#billing_postcode', '2300')
        ->fill('#billing_phone', '+4512345678')
        ->fill('#billing_email', 'ss-browser-test@smartsend.io')
        ->assertSee('Flat rate')
        ->assertSee('Smart Send Pickup Point');

    highlight_element($page, 'ul#shipping_method');

    capture_doc_screenshot($page, 'Checkout', 'checkout-shipping-methods');
});

it('shows the pickup point dropdown when the pickup point method is chosen', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page = $page->navigate(base_url('/?page_id=' . $state['checkout_page_id']))
        ->assertSee('Billing details')
        ->fill('#billing_first_name', 'Docs')
        ->fill('#billing_last_name', 'Screenshot')
        ->fill('#billing_address_1', 'Islands Brygge 39')
        ->fill('#billing_city', 'Copenhagen')
        ->fill('#billing_postcode', '2300')
        ->fill('#billing_phone', '+4512345678')
        ->fill('#billing_email', 'ss-browser-test@smartsend.io')
        ->click('#shipping_method_0_smart_send_shipping' . $state['instance_id'])
        ->assertPresent('select[name=ss_shipping_store_pickup]')
        ->assertSourceHas('Browser Test Shop');

    highlight_element($page, 'select[name=ss_shipping_store_pickup]');

    capture_doc_screenshot($page, 'Checkout', 'pickup-point-dropdown');
});

it('shows a selected pickup point on the classic checkout', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page = $page->navigate(base_url('/?page_id=' . $state['checkout_page_id']))
        ->assertSee('Billing details')
        ->fill('#billing_first_name', 'Docs')
        ->fill('#billing_last_name', 'Screenshot')
        ->fill('#billing_address_1', 'Islands Brygge 39')
        ->fill('#billing_city', 'Copenhagen')
        ->fill('#billing_postcode', '2300')
        ->fill('#billing_phone', '+4512345678')
        ->fill('#billing_email', 'ss-browser-test@smartsend.io')
        ->click('#shipping_method_0_smart_send_shipping' . $state['instance_id'])
        ->assertPresent('select[name=ss_shipping_store_pickup]')
        ->assertSourceHas('Browser Test Shop')
        ->select('ss_shipping_store_pickup', '1234');

    highlight_element($page, 'select[name=ss_shipping_store_pickup]');

    capture_doc_screenshot($page, 'Checkout', 'pickup-point-selected');
});
