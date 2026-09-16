/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The settings section's "Also create return label" checkbox, defaulting
 * from the method's auto-generate-return-label setting, with a help tip
 * next to its label. Which return method a return books with is the
 * "Return method" row of the shipping section (ReturnMethodField).
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { HelpTip } from './Row';

export const WITH_RETURN_HELP = __( 'When booking an outgoing label, then we will automatically also book a return label', 'smart-send-logistics' );

export default function ReturnToggle( { checked, onChecked, disabled } ) {
	return (
		<div className="smart-send-fulfillment__row smart-send-fulfillment__check-row" data-ss-section="return">
			<label className="smart-send-fulfillment__check">
				<input
					type="checkbox"
					data-ss-field="with_return"
					checked={ checked }
					onChange={ ( event ) => onChecked( event.target.checked ) }
					disabled={ disabled }
					autoComplete="off"
				/>
				<span className="smart-send-fulfillment__check-text">{ __( 'Also create return label', 'smart-send-logistics' ) }</span>
			</label>
			{ /* Outside the label: a click on the tip must not toggle the checkbox. */ }
			<HelpTip text={ WITH_RETURN_HELP } />
		</div>
	);
}
