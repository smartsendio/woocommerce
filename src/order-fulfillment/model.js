/**
 * Pure helpers over the meta box state (section 3.3 of #182) and the
 * app's form model - no React, no DOM, so the mapping between the
 * server's parcel plan (specs of item allocations) and the editor's
 * "which unit sits in which box" view lives in one testable spot.
 */

/**
 * The meta box states of section 1.2 (the same vocabulary the presenter
 * exposes as data-ss-state).
 */
export const STATE_NOT_CONNECTED = 'not_connected';
export const STATE_NO_METHOD = 'no_method';
export const STATE_READY = 'ready';
export const STATE_BOOKED = 'booked';

/**
 * Which state a state object is in.
 *
 * @param {Object} state The state.
 * @return {string} One of the STATE_* constants.
 */
export function boxState( state ) {
	if ( ! state.connected ) {
		return STATE_NOT_CONNECTED;
	}

	if ( state.outbound_shipment || state.return_shipment ) {
		return STATE_BOOKED;
	}

	if ( ! state.delivery_details.shipping_method ) {
		return STATE_NO_METHOD;
	}

	return STATE_READY;
}

/**
 * Whether a method code is a pickup point ("agent") method - the type
 * part of e.g. postnord_agent, mirroring SS_Shipping_Method_Code::type().
 *
 * @param {string} code The method code.
 * @return {boolean} True for an agent method.
 */
export function isAgentMethod( code ) {
	if ( ! code ) {
		return false;
	}

	const parts = String( code ).split( '_' );
	const type = parts.length > 1 ? parts.slice( 1 ).join( '_' ) : code;

	return /agent/i.test( type );
}

/**
 * The human readable name of a method code in the grouped method lists,
 * '' when unknown.
 *
 * @param {Array}  groups A methods list (state.methods.outbound / .return).
 * @param {string} code   The method code.
 * @return {string} The name.
 */
export function methodName( groups, code ) {
	for ( const group of groups || [] ) {
		for ( const option of group.options || [] ) {
			if ( option.code === code ) {
				return option.name;
			}
		}
	}

	return '';
}

/**
 * An empty box (a parcel spec without allocations).
 *
 * @return {Object} { weight, length, width, height } as input strings.
 */
export function emptyBox() {
	return { weight: '', length: '', width: '', height: '' };
}

/**
 * The editor's boxes and per-unit assignment from a stored parcel plan:
 * walks every spec's allocations and hands the order's units out by
 * product id, in order (the same walk
 * SS_Shipping_Order_Fulfillment_Presenter::stored_box_numbers() does).
 * Units the plan does not mention land in the first box; an empty plan is
 * one box holding everything.
 *
 * @param {Array}       units The order's units (state.order.units).
 * @param {Object|null} plan  The stored plan (state.delivery_details.parcel_plan).
 * @return {{boxes: Array, assignment: number[]}} The boxes and, per unit index, its box index.
 */
export function boxesFromPlan( units, plan ) {
	const specs = plan && Array.isArray( plan.specs ) ? plan.specs : [];

	if ( specs.length === 0 ) {
		return {
			boxes: [ emptyBox() ],
			assignment: units.map( () => 0 ),
		};
	}

	const remaining = {};
	units.forEach( ( unit, index ) => {
		const id = String( unit.id );
		remaining[ id ] = remaining[ id ] || [];
		remaining[ id ].push( index );
	} );

	const assignment = units.map( () => 0 );
	const boxes = specs.map( ( spec, boxIndex ) => {
		( spec.items || [] ).forEach( ( item ) => {
			const id = String( item.id );
			const quantity = parseInt( item.quantity, 10 ) || 1;

			for ( let unit = 0; unit < quantity; unit++ ) {
				if ( ! remaining[ id ] || remaining[ id ].length === 0 ) {
					break;
				}
				assignment[ remaining[ id ].shift() ] = boxIndex;
			}
		} );

		return {
			weight: numberToInput( spec.weight ),
			length: numberToInput( spec.length ),
			width: numberToInput( spec.width ),
			height: numberToInput( spec.height ),
		};
	} );

	return { boxes, assignment };
}

/**
 * The parcel plan to submit for the editor's boxes: one spec per box with
 * the units grouped into { id, quantity } allocations (names are filled
 * server-side) and the optional weight/dimensions as numbers. A single
 * box without an explicit weight or dimension is the "one parcel" default
 * and submits an empty specs list, which clears a stored split.
 *
 * @param {Array}    units      The order's units.
 * @param {Array}    boxes      The editor's boxes.
 * @param {number[]} assignment Per unit index, its box index.
 * @return {{specs: Array}} The plan.
 */
export function planFromBoxes( units, boxes, assignment ) {
	if ( boxes.length === 1 && ! hasExplicitValues( boxes[ 0 ] ) ) {
		return { specs: [] };
	}

	const specs = boxes.map( ( box, boxIndex ) => {
		const items = [];

		units.forEach( ( unit, unitIndex ) => {
			if ( assignment[ unitIndex ] !== boxIndex ) {
				return;
			}

			const existing = items.find( ( item ) => String( item.id ) === String( unit.id ) );

			if ( existing ) {
				existing.quantity += 1;
			} else {
				items.push( { id: unit.id, quantity: 1 } );
			}
		} );

		return {
			reference: String( boxIndex + 1 ),
			weight: inputToNumber( box.weight ),
			length: inputToNumber( box.length ),
			width: inputToNumber( box.width ),
			height: inputToNumber( box.height ),
			items,
		};
	} );

	return { specs };
}

/**
 * The computed weight of a box: the sum of its units' weights (what the
 * server books with when no explicit weight is entered, before the
 * smart_send_parcel_default_weight filter).
 *
 * @param {Array}    units      The order's units.
 * @param {number[]} assignment Per unit index, its box index.
 * @param {number}   boxIndex   The box.
 * @return {number} Weight in kg.
 */
export function computedBoxWeight( units, assignment, boxIndex ) {
	return units.reduce( ( sum, unit, unitIndex ) => {
		return assignment[ unitIndex ] === boxIndex ? sum + ( parseFloat( unit.unit_weight ) || 0 ) : sum;
	}, 0 );
}

/**
 * The total weight of the boxes as booked: an explicit box weight wins
 * over its computed one.
 *
 * @param {Array}    units      The order's units.
 * @param {Array}    boxes      The editor's boxes.
 * @param {number[]} assignment Per unit index, its box index.
 * @return {number} Weight in kg.
 */
export function totalWeight( units, boxes, assignment ) {
	return boxes.reduce( ( sum, box, boxIndex ) => {
		const explicit = inputToNumber( box.weight );

		return sum + ( explicit === null ? computedBoxWeight( units, assignment, boxIndex ) : explicit );
	}, 0 );
}

/**
 * Format a weight for display.
 *
 * @param {number} weight Weight in kg.
 * @return {string} e.g. "1.20".
 */
export function formatWeight( weight ) {
	return ( Math.round( weight * 100 ) / 100 ).toFixed( 2 );
}

/**
 * Whether a box carries an explicit weight or any dimension.
 *
 * @param {Object} box The box.
 * @return {boolean} True when explicit values were entered.
 */
export function hasExplicitValues( box ) {
	return [ 'weight', 'length', 'width', 'height' ].some( ( key ) => inputToNumber( box[ key ] ) !== null );
}

/**
 * An input string as a number, null when empty or not numeric.
 *
 * @param {string} value The input value.
 * @return {number|null} The number.
 */
export function inputToNumber( value ) {
	if ( value === null || value === undefined || String( value ).trim() === '' ) {
		return null;
	}

	const number = parseFloat( String( value ).replace( ',', '.' ) );

	return Number.isFinite( number ) ? number : null;
}

/**
 * A stored number as an input string ('' for null).
 *
 * @param {number|null} value The stored value.
 * @return {string} The input value.
 */
export function numberToInput( value ) {
	return value === null || value === undefined ? '' : String( value );
}

/**
 * The merchant-facing lines of a failed leg's error that no form field
 * carries: the API field errors the presenter could not map (e.g.
 * receiver.*, the order's shipping address) and the Response ID.
 *
 * @param {Object} error The leg's error ({ message, response_id, fields, form_fields }).
 * @return {{message: string, details: string[], responseId: string|null}} The notice content.
 */
export function generalErrorContent( error ) {
	const mapped = new Set();
	Object.values( error.form_fields || {} ).forEach( ( messages ) => {
		( messages || [] ).forEach( ( message ) => mapped.add( message ) );
	} );

	const details = [];
	Object.entries( error.fields || {} ).forEach( ( [ field, messages ] ) => {
		( messages || [] ).forEach( ( message ) => {
			if ( ! mapped.has( message ) ) {
				details.push( field + ': ' + message );
			}
		} );
	} );

	return {
		message: error.message || '',
		details,
		responseId: error.response_id || null,
	};
}
