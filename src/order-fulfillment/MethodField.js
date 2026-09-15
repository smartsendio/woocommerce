/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The shipping method row: the resolved method with a "Change" action that
 * opens the grouped method select (state.methods.outbound); the select is
 * shown right away when the order has no Smart Send method (state B).
 */
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { methodName } from './model';
import { FieldError } from './ErrorNotice';

export function MethodSelect( { id, field, groups, value, onChange, placeholder } ) {
	return (
		<select
			id={ id }
			className="smart-send-fulfillment__select"
			data-ss-field={ field }
			value={ value || '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			autoComplete="off"
		>
			<option value="">{ placeholder || __( 'Select a method…', 'smart-send-logistics' ) }</option>
			{ groups.map( ( group ) => (
				<optgroup key={ group.carrier } label={ group.carrier }>
					{ group.options.map( ( option ) => (
						<option key={ option.code } value={ option.code }>
							{ option.name }
						</option>
					) ) }
				</optgroup>
			) ) }
		</select>
	);
}

export default function MethodField( { groups, value, editing, onEdit, onChange, errors, debugItems = [] } ) {
	const showSelect = editing || ! value;

	return (
		<div className="smart-send-fulfillment__row" data-ss-section="shipping_method">
			<label htmlFor="smart-send-shipping-method">
				<strong>{ __( 'Shipping method', 'smart-send-logistics' ) }</strong>
			</label>
			{ showSelect ? (
				<MethodSelect id="smart-send-shipping-method" field="shipping_method" groups={ groups } value={ value } onChange={ onChange } />
			) : (
				<div className="smart-send-fulfillment__value">
					<span data-ss-value="shipping_method">{ methodName( groups, value ) || value }</span>{ ' ' }
					<Button variant="link" size="small" data-ss-action="change-method" onClick={ onEdit }>
						{ __( 'Change', 'smart-send-logistics' ) }
					</Button>
				</div>
			) }
			<FieldError field="shipping_method" errors={ errors } />
			{ debugItems.map( ( item ) => (
				<pre key={ item } data-ss-debug="shipping_item">
					{ __( 'Debug id:', 'smart-send-logistics' ) + ' ' + item }
				</pre>
			) ) }
		</div>
	);
}
