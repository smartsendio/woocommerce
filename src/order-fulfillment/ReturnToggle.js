/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The settings section's "Also create return label" checkbox, defaulting
 * from the method's auto-generate-return-label setting ("Default from the
 * shipping method settings" under the label). Which return method a
 * return books with is the "Return method" row of the shipping section
 * (ReturnMethodField) - shown as a select right away when none is
 * configured, so a return-only booking never needs this checkbox.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function ReturnToggle( { checked, onChecked, disabled } ) {
	return (
		<div className="smart-send-fulfillment__row" data-ss-section="return">
			<label className="smart-send-fulfillment__check">
				<input
					type="checkbox"
					data-ss-field="with_return"
					checked={ checked }
					onChange={ ( event ) => onChecked( event.target.checked ) }
					disabled={ disabled }
					autoComplete="off"
				/>
				<span className="smart-send-fulfillment__check-text">
					{ __( 'Also create return label', 'smart-send-logistics' ) }
					<span className="smart-send-fulfillment__hint">{ __( 'Default from the shipping method settings', 'smart-send-logistics' ) }</span>
				</span>
			</label>
		</div>
	);
}
