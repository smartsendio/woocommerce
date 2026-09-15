/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The "Also create return label" row: the checkbox defaulting from the
 * method's auto-generate-return-label setting and naming the configured
 * return method. When the order has no configured return method (an
 * order without a Smart Send method - state B - or a zone method without
 * one) the checkbox stays usable and a return method select
 * (state.methods.return) is shown right away: the chosen method serves
 * both the combined outbound + return run and the separate "Create return
 * label" action, so a return-only booking never needs the checkbox.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { methodName } from './model';
import { MethodSelect } from './MethodField';
import { FieldError } from './ErrorNotice';

export default function ReturnToggle( { configuredMethod, groups, checked, onChecked, returnMethod, onReturnMethod, errors, disabled } ) {
	const configuredName = configuredMethod ? methodName( groups, configuredMethod ) || configuredMethod : '';

	return (
		<div className="smart-send-fulfillment__row" data-ss-section="return">
			<label>
				<input
					type="checkbox"
					data-ss-field="with_return"
					checked={ checked }
					onChange={ ( event ) => onChecked( event.target.checked ) }
					disabled={ disabled }
					autoComplete="off"
				/>{ ' ' }
				{ __( 'Also create return label', 'smart-send-logistics' ) }
				{ configuredName && (
					<span data-ss-value="return.method">{ ' (' + configuredName + ')' }</span>
				) }
			</label>
			{ ! configuredMethod && (
				<p className="description" data-ss-hint="no_return_method">
					{ __( 'No return method configured on the shipping method - choose one here, or set one under WooCommerce → Shipping → the zone method.', 'smart-send-logistics' ) }
				</p>
			) }
			{ ! configuredMethod && (
				<div className="smart-send-fulfillment__inline-form">
					<label htmlFor="smart-send-return-method">{ __( 'Return method', 'smart-send-logistics' ) }</label>
					<MethodSelect id="smart-send-return-method" field="return_method" groups={ groups } value={ returnMethod } onChange={ onReturnMethod } />
					<FieldError field="return_method" errors={ errors } />
				</div>
			) }
		</div>
	);
}
