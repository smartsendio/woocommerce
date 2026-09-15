/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * Entry of the order screen "Smart Send Shipping" meta box app (#182).
 *
 * Mounts App on the server-rendered <fieldset id="smart-send-fulfillment">
 * (SS_Shipping_Order_Fulfillment_Presenter::render_form()) and hydrates it
 * from the state SS_Shipping_Order_Meta_Box inlines as
 * window.smartSendOrderFulfillment. Everything after that goes through the
 * smart-send/v1 REST routes via @wordpress/api-fetch - no admin-ajax, no
 * page reload.
 *
 * Every file of this app opts into the CLASSIC JSX runtime (the pragma
 * comments above): @wordpress/scripts compiles JSX to the automatic runtime
 * by default, which makes the bundle depend on the `react-jsx-runtime`
 * script handle that WordPress only registers from 6.6 - on the 6.5 floor
 * the script would silently never load (#183). With the classic runtime the
 * bundle depends on wp-element only.
 */
import { createElement, createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

const mount = document.getElementById( 'smart-send-fulfillment' );
const state = window.smartSendOrderFulfillment;

if ( mount && state ) {
	createRoot( mount ).render( <App initialState={ state } mount={ mount } /> );
}
