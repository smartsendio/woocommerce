/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The building blocks of the box's sectioned layout (Option A, #182):
 * the inline SVG icons (a help tip and the pickup point pin - WordPress
 * admin has no icon font for either, and an emoji would not match the
 * admin's line icons), and a detail row - a bold label line with an
 * optional help icon and an "Edit" link right-aligned, the read-only
 * value (or its control, once edited) below it.
 *
 * The server first paint (SS_Shipping_Order_Fulfillment_Presenter)
 * renders the same structure and class names so the box does not jump
 * when the app mounts.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A 16px question-mark-in-circle with the help text as its title and
 * aria-label (WordPress' own help tip pattern).
 */
export function HelpIcon( { text } ) {
	return (
		<span className="smart-send-fulfillment__help" title={ text } aria-label={ text } role="img" data-ss-help="">
			<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
				<circle cx="8" cy="8" r="6.5" />
				<path d="M6.2 6.2a1.9 1.9 0 0 1 3.7.5c0 1.2-1.9 1.4-1.9 2.6" />
				<circle cx="8" cy="11.6" r=".5" fill="currentColor" />
			</svg>
		</span>
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
				{ help && <HelpIcon text={ help } /> }
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
