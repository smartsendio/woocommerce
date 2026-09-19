<?php

require_once dirname(__DIR__) . '/ShippingMethod/ShippingFixtures.php';

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    docs_shipping_cleanup();
    docs_seed_store();
    docs_shipping_fixture();
    $GLOBALS['ss_docs_block_checkout_page_id'] = ss_browser_create_block_checkout_page();
    $id = (int) $GLOBALS['ss_docs_block_checkout_page_id'];
    $title = var_export(docs_text('Checkout', 'Kasse'), true);
    ss_browser_wp_eval("wp_update_post(array('ID' => $id, 'post_title' => $title)); update_post_meta($id, '_wp_page_template', 'template-fullwidth.php'); echo json_encode(array('updated' => true));");
});

afterEach(function (): void {
    // The shared store state records and cleans this page, even after a failed capture.
    unset($GLOBALS['ss_docs_block_checkout_page_id']);
    docs_shipping_cleanup();
    docs_cleanup_store();
});

function docs_block_checkout($page): void
{
    $page->navigate(base_url('/?page_id=' . $GLOBALS['ss_docs_block_checkout_page_id']))
        ->assertPresent('#email')
        ->fill('#email', 'alex@example.com')
        ->fill('#shipping-first_name', 'Alex')
        ->fill('#shipping-last_name', 'Example')
        ->fill('#shipping-address_1', 'Eksempelvej 12')
        ->fill('#shipping-city', docs_text('Copenhagen', 'København'))
        ->fill('#shipping-postcode', '2300')
        ->fill('#shipping-phone', '+4512345678')
        ->assertSee(docs_text('Home delivery', 'Hjemmelevering'))
        ->assertSee(docs_text('Pickup point', 'Afhentningssted'));
    docs_block_wait_for_total($page, '184.00');
}

function docs_block_select_pickup($page): void
{
    $state = ss_browser_state();
    $page->click('input[value="smart_send_shipping:' . $state['instance_id'] . '"]')
        ->assertPresent('.ss-pickup-point-block[data-status="ready"]')
        ->assertPresent('#ss-pickup-point-select option[value="1234"]');
    docs_block_wait_for_total($page, '164.00');
}

/** Address and pickup updates can finish before the order summary recalculates. */
function docs_block_wait_for_total($page, string $expected): void
{
    $expected = json_encode($expected);
    ss_wait_for_script($page, "(function () { const total = document.querySelector('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'); if (!total || document.querySelector('.wc-block-components-skeleton__element')) return false; const match = total.textContent.match(/[0-9][0-9.,]*/); return match && match[0].replace(/\\./g, '').replace(',', '.') === $expected; })()", 15);
}

it('documents the shipping methods in block checkout', function () {
    $state = ss_browser_state();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_block_checkout($page);
    highlight_element($page, '.wc-block-components-shipping-rates-control');
    capture_doc_screenshot($page, 'checkout-block', 'shipping-methods');
});

it('documents the pickup selector in block checkout', function () {
    $state = ss_browser_state();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_block_checkout($page);
    docs_block_select_pickup($page);
    highlight_element($page, '.ss-pickup-point-block');
    capture_doc_screenshot($page, 'checkout-block', 'pickup-selector');
});

it('documents a selected pickup point in block checkout', function () {
    $state = ss_browser_state();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_block_checkout($page);
    docs_block_select_pickup($page);
    $page->select('#ss-pickup-point-select', '1234')
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"][data-status="ready"]');
    docs_block_wait_for_total($page, '164.00');
    highlight_element($page, '.ss-pickup-point-block');
    capture_doc_screenshot($page, 'checkout-block', 'pickup-selected');
});
