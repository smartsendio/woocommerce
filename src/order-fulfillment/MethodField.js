/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The shipping method and return method rows: the method name (grey
 * "None" when the order has none) with an "Edit" link that swaps the
 * value for the grouped method select in place (state.methods.outbound /
 * .return). The return method row shows the select right away when the
 * shipping method has no return method configured (state B, or a zone
 * method without one) - the chosen method serves both the combined
 * outbound + return run and the return-only action.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { methodName } from './model';
import { FieldError } from './ErrorNotice';
import DetailRow, { NoneValue } from './Row';

export const SHIPPING_METHOD_HELP = __( 'Taken from the order. Choose another method to ship it differently; the order is not changed.', 'smart-send-logistics' );
export const RETURN_METHOD_HELP = __( 'Used for return labels. Taken from the shipping method settings; change it here for this booking only.', 'smart-send-logistics' );

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
 * settings resolve to (null when none): with one, the row reads it and
 * "Edit" opens the select; without one, the select is shown right away
 * under a hint.
 */
export function ReturnMethodField( { groups, value, configured, editing, editable = true, onEdit, onChange, errors, id = 'smart-send-return-method' } ) {
	const showSelect = editing || ! configured;

	return (
		<DetailRow section="return_method" label={ __( 'Return method', 'smart-send-logistics' ) } help={ RETURN_METHOD_HELP } action="edit-return-method" editable={ editable && !! configured } editing={ showSelect } onEdit={ onEdit }>
			{ showSelect ? (
				<MethodSelect id={ id } field="return_method" groups={ groups } value={ value } onChange={ onChange } />
			) : (
				<div className="smart-send-fulfillment__value">
					<span data-ss-value="return_method">{ methodName( groups, configured ) || configured }</span>
				</div>
			) }
			{ ! configured && (
				<p className="smart-send-fulfillment__hint" data-ss-hint="no_return_method">
					{ __( 'No return method configured on the shipping method - choose one here, or set one under WooCommerce → Shipping → the zone method.', 'smart-send-logistics' ) }
				</p>
			) }
			<FieldError field="return_method" errors={ errors } />
		</DetailRow>
	);
}
