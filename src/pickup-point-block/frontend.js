/**
 * Frontend entry for the Smart Send pickup point Checkout Block integration.
 *
 * PLACEHOLDER (PR 1 of issue #74): this file only exists to prove the build
 * pipeline end to end - wp-scripts build, WooCommerce dependency extraction
 * into the generated *.asset.php, the committed smart-send-logistics/build/
 * output and the CI freshness check. PR 3 replaces it with the real
 * checkout-block component.
 */
import { __ } from '@wordpress/i18n';

// A silent window side effect keeps the entry from being tree-shaken empty
// and makes the @wordpress/i18n external show up in the *.asset.php deps.
window.smartSendPickupPointBlock = window.smartSendPickupPointBlock || {};
window.smartSendPickupPointBlock.frontendPlaceholder = () =>
	__( 'Smart Send pick-up point selection', 'smart-send-logistics' );
