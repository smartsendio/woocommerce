/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The building blocks of the box's sectioned layout (Option A, #182):
 * WooCommerce's help tip (its markup, styled by WooCommerce's admin
 * stylesheet and bound to its tipTip tooltip), the pickup point pin (an
 * inline SVG - WordPress admin has no icon for it), and a detail row - a
 * bold label line with an optional help tip and an "Edit" link
 * right-aligned, the read-only value (or its control, once edited) below
 * it.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * WooCommerce's own help tip: an empty span WooCommerce's admin stylesheet
 * renders as the Dashicons question mark, with the text as `data-tip`
 * (what WooCommerce's tipTip binding reads - tipTip removes it once bound,
 * which is what makes re-binding idempotent) and as `aria-label` (kept for
 * screen readers and for the tests to assert). WooCommerce binds tipTip
 * on DOM ready only; nodes this app inserts later are bound by
 * bindHelpTips() after every render.
 */
export function HelpTip( { text } ) {
	return <span className="woocommerce-help-tip" tabIndex="0" aria-label={ text } data-tip={ text } data-ss-help=""></span>;
}

/**
 * Bind WooCommerce's tooltip to every help tip under `root` that is not
 * bound yet - the same call, with the same options, as WooCommerce's
 * `init_tooltips` in woocommerce_admin.js. tipTip strips the `data-tip`
 * attribute of a bound element and skips elements without one, so
 * calling this after every render is safe. A no-op without jQuery/tipTip
 * (the icon still renders and the aria-label still reads).
 *
 * @param {Element} root The element to search under.
 */
export function bindHelpTips( root ) {
	const jQuery = window.jQuery;

	if ( ! root || ! jQuery || ! jQuery.fn || ! jQuery.fn.tipTip ) {
		return;
	}

	jQuery( root ).find( '.woocommerce-help-tip[data-tip]' ).tipTip( {
		attribute: 'data-tip',
		fadeIn: 50,
		fadeOut: 50,
		delay: 200,
		keepAlive: true,
	} );
}

/**
 * The small external-link icon of a link that opens the Smart Send app (a
 * timeline entry, a green result box's "Open", a document download).
 */
export function ExternalIcon() {
	return (
		<svg className="smart-send-fulfillment__external" width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
			<path d="M4.5 2H2.5v7.5H10V7.5" />
			<path d="M7 2h3v3" />
			<path d="M10 2 5.5 6.5" />
		</svg>
	);
}

/**
 * A 16px map pin, in front of a pickup point.
 */
export function PinIcon() {
	return (
		<svg className="smart-send-fulfillment__pin" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
			<path d="M8 14.5s-4.5-4.3-4.5-8a4.5 4.5 0 0 1 9 0c0 3.7-4.5 8-4.5 8z" />
			<circle cx="8" cy="6.5" r="1.6" />
		</svg>
	);
}

/**
 * The grey "None" of a missing value.
 */
export function NoneValue( { field } ) {
	return (
		<span className="smart-send-fulfillment__none" data-ss-value={ field }>
			{ __( 'None', 'smart-send-logistics' ) }
		</span>
	);
}

/**
 * A detail row: label line (label, optional help icon, optional summary,
 * the Edit link - or whatever `control` replaces it with, e.g. the
 * parcels' Done button) over the row's content.
 */
export default function DetailRow( { section, label, help, summary, action, editable = true, editing = false, onEdit, control, className = '', children } ) {
	return (
		<div className={ ( className ? className + ' ' : '' ) + 'smart-send-fulfillment__row' } data-ss-section={ section }>
			<div className="smart-send-fulfillment__row-head">
				<span className="smart-send-fulfillment__label">{ label }</span>
				{ summary }
				{ help && <HelpTip text={ help } /> }
				{ control }
				{ ! control && editable && ! editing && (
					<button type="button" className="button-link smart-send-fulfillment__edit" data-ss-action={ action } onClick={ onEdit }>
						{ __( 'Edit', 'smart-send-logistics' ) }
					</button>
				) }
			</div>
			{ children }
		</div>
	);
}
