<?php

/*
|--------------------------------------------------------------------------
| Guides -> Checkout -> Pickup point selection (block checkout)
|--------------------------------------------------------------------------
|
| The block-checkout mirror of the classic checkout screenshots (issue
| #74): the shipping options in the Checkout block, the pickup point block
| appearing below them when a pickup point shipping method is chosen, and a
| pickup point selected.
|
| One test per UI state, each producing its own named screenshot under
| docs/screenshots/BlockCheckout/ (see tests/Docs/Support/Screenshots.php).
| The block checkout page carries the stock minimal Checkout block markup
| (ss_browser_create_block_checkout_page()), so these screenshots show the
| default, no-merchant-action rendering. The tests wait on the block's
| data-status="ready" affordance before capturing, so the dropdown is
| always populated in the shots; the visit() call stays inline in every
| test closure for pest-plugin-browser to recognise it as a browser test
| (see the ShippingMethod test's docblock).
|
*/

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_seed_store();
    $GLOBALS['ss_docs_block_checkout_page_id'] = ss_browser_create_block_checkout_page();
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    if (!empty($GLOBALS['ss_docs_block_checkout_page_id'])) {
        ss_browser_delete_block_checkout_page($GLOBALS['ss_docs_block_checkout_page_id']);
        unset($GLOBALS['ss_docs_block_checkout_page_id']);
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('shows the shipping options in the checkout block', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page = $page->navigate(base_url('/?page_id=' . $GLOBALS['ss_docs_block_checkout_page_id']))
        ->assertSee('Contact information')
        ->fill('#email', 'ss-browser-test@smartsend.io')
        ->fill('#shipping-first_name', 'Docs')
        ->fill('#shipping-last_name', 'Screenshot')
        ->fill('#shipping-address_1', 'Islands Brygge 39')
        ->fill('#shipping-city', 'Copenhagen')
        ->fill('#shipping-postcode', '2300')
        ->fill('#shipping-phone', '+4512345678')
        ->assertSee('Flat rate')
        ->assertSee('Smart Send Pickup Point');

    highlight_element($page, '.wc-block-components-shipping-rates-control');

    capture_doc_screenshot($page, 'BlockCheckout', 'checkout-shipping-options');
});

it('shows the pickup point block when the pickup point method is chosen', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page = $page->navigate(base_url('/?page_id=' . $GLOBALS['ss_docs_block_checkout_page_id']))
        ->assertSee('Contact information')
        ->fill('#email', 'ss-browser-test@smartsend.io')
        ->fill('#shipping-first_name', 'Docs')
        ->fill('#shipping-last_name', 'Screenshot')
        ->fill('#shipping-address_1', 'Islands Brygge 39')
        ->fill('#shipping-city', 'Copenhagen')
        ->fill('#shipping-postcode', '2300')
        ->fill('#shipping-phone', '+4512345678')
        ->assertSee('Smart Send Pickup Point')
        ->click('input[value="smart_send_shipping:' . $state['instance_id'] . '"]')
        ->assertPresent('.ss-pickup-point-block[data-status="ready"]')
        ->assertPresent('#ss-pickup-point-select option[value="1234"]');

    highlight_element($page, '.ss-pickup-point-block');

    capture_doc_screenshot($page, 'BlockCheckout', 'pickup-point-block');
});

it('shows a selected pickup point in the checkout block', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page = $page->navigate(base_url('/?page_id=' . $GLOBALS['ss_docs_block_checkout_page_id']))
        ->assertSee('Contact information')
        ->fill('#email', 'ss-browser-test@smartsend.io')
        ->fill('#shipping-first_name', 'Docs')
        ->fill('#shipping-last_name', 'Screenshot')
        ->fill('#shipping-address_1', 'Islands Brygge 39')
        ->fill('#shipping-city', 'Copenhagen')
        ->fill('#shipping-postcode', '2300')
        ->fill('#shipping-phone', '+4512345678')
        ->assertSee('Smart Send Pickup Point')
        ->click('input[value="smart_send_shipping:' . $state['instance_id'] . '"]')
        ->assertPresent('.ss-pickup-point-block[data-status="ready"]')
        ->assertPresent('#ss-pickup-point-select option[value="1234"]')
        ->select('#ss-pickup-point-select', '1234')
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"]');

    highlight_element($page, '#ss-pickup-point-select');

    capture_doc_screenshot($page, 'BlockCheckout', 'pickup-point-selected');
});
