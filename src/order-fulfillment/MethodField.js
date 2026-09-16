/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The shipping method and return method rows: the method name (grey
 * "None" when the order has none, or no return method is configured)
 * with an "Edit" link that swaps the value for the grouped method select
 * in place (state.methods.outbound / .return). Both rows are collapsed
 * to their read value in every state - the select appears only after
 * Edit; a return method chosen there serves both the combined outbound +
 * return run and the return-only action.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { methodName } from './model';
import { FieldError } from './ErrorNotice';
import DetailRow, { NoneValue } from './Row';

export const SHIPPING_METHOD_HELP = __( 'Shipping method used for booking of outgoing shipment', 'smart-send-logistics' );
export const RETURN_METHOD_HELP = __( 'Shipping method used for booking of return shipments', 'smart-send-logistics' );

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

export default function MethodField( { groups, value, editing, editable = true, onEdit, onChange, errors, debugItems = [] } ) {
	return (
		<DetailRow section="shipping_method" label={ __( 'Shipping method', 'smart-send-logistics' ) } help={ SHIPPING_METHOD_HELP } action="edit-method" editable={ editable } editing={ editing } onEdit={ onEdit }>
			{ editing ? (
				<MethodSelect id="smart-send-shipping-method" field="shipping_method" groups={ groups } value={ value } onChange={ onChange } />
			) : (
				<div className="smart-send-fulfillment__value">
					{ value ? <span data-ss-value="shipping_method">{ methodName( groups, value ) || value }</span> : <NoneValue field="shipping_method" /> }
				</div>
			) }
			<FieldError field="shipping_method" errors={ errors } />
			{ debugItems.map( ( item ) => (
				<pre key={ item } data-ss-debug="shipping_item">
					{ __( 'Debug id:', 'smart-send-logistics' ) + ' ' + item }
				</pre>
			) ) }
		</DetailRow>
	);
}

/**
 * The return method row. `configured` is the method the shipping method
 * settings resolve to (null when none): the row reads it - grey "None"
 * when there is none - and "Edit" opens the select, in every state.
 */
export function ReturnMethodField( { groups, value, configured, editing, editable = true, onEdit, onChange, errors, id = 'smart-send-return-method' } ) {
	return (
		<DetailRow section="return_method" label={ __( 'Return method', 'smart-send-logistics' ) } help={ RETURN_METHOD_HELP } action="edit-return-method" editable={ editable } editing={ editing } onEdit={ onEdit }>
			{ editing ? (
				<MethodSelect id={ id } field="return_method" groups={ groups } value={ value } onChange={ onChange } />
			) : (
				<div className="smart-send-fulfillment__value">
					{ configured ? <span data-ss-value="return_method">{ methodName( groups, configured ) || configured }</span> : <NoneValue field="return_method" /> }
				</div>
			) }
			<FieldError field="return_method" errors={ errors } />
		</DetailRow>
	);
}
