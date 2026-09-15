/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The meta box app: a small state machine over the server's state object
 * (section 3.3 of #182) - idle → submitting → the state the POST returned
 * (booked, or failed with per-field errors) - rendering the section 1.2
 * states:
 *
 *   A not connected      notice + "Open settings", everything disabled
 *   B no Smart Send method   notice + method select (+ return method select
 *                            used by both actions); the order is bookable
 *   C not yet booked     method / pickup point / parcels rows, return toggle,
 *                        "Create shipping label" (primary) next to "Create
 *                        return label" (secondary, books a return leg only)
 *   D parcel editor      opened from C's "Edit"
 *   E/F booked           the full documents/codes/tracking block right after
 *                        booking (from the POST response); after a reload the
 *                        id + "see the order notes" (only the id is persisted)
 *   G book again         a disclosure re-opening the form with a confirm
 *                        checkbox; the request carries confirm_rebook
 *   H submitting         fieldset disabled, spinner, "Creating label…"
 *   I failures           per-field errors from error.form_fields, a general
 *                        notice for the rest + the Response ID
 *
 * Layout (Option A, #182 review): the box is a stack of sections
 * separated by dividers - the shipping section (callouts, then the
 * shipping method / pickup point / return method rows, each a read-only
 * value with an "Edit" link that swaps it for its control in place), the
 * parcels section (collapsed to one summary line; "Edit" expands the
 * editor, "Done" collapses it keeping the plan), the actions section
 * (the two buttons stacked) and the grey settings section (the return
 * checkbox). Editing is component state only, never persisted; the
 * server first paint renders the same read state with the same class
 * names so nothing jumps when the app mounts.
 *
 * Every request is POST …/fulfillment via apiFetch; a fulfilled leg's
 * order note is prepended to WooCommerce's ul.order_notes (both the HPOS
 * and the legacy screen render it) and the box re-renders from the state
 * the response carries. No page reload anywhere.
 */
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { fulfill, lookupPickupPoint } from './api';
import {
	STATE_BOOKED,
	STATE_NOT_CONNECTED,
	STATE_NO_METHOD,
	boxState,
	boxesFromPlan,
	generalErrorContent,
	isAgentMethod,
	planFromBoxes,
} from './model';
import MethodField, { ReturnMethodField } from './MethodField';
import PickupPointField from './PickupPointField';
import ParcelEditor from './ParcelEditor';
import ReturnToggle from './ReturnToggle';
import BookedShipment from './BookedShipment';
import ErrorNotice from './ErrorNotice';

/**
 * The form model derived from a state object.
 */
function formFromState( state ) {
	const { boxes, assignment } = boxesFromPlan( state.order.units, state.delivery_details.parcel_plan );

	return {
		shippingMethod: state.delivery_details.shipping_method || '',
		pickupPoint: state.delivery_details.pickup_point,
		withReturn: !! state.return.auto_default && !! state.return.method,
		returnMethod: state.return.method || '',
		boxes,
		assignment,
	};
}

/**
 * Prepend a rendered order note (WooCommerce's own <li>) to the order
 * notes list, replacing the "no notes yet" placeholder when present.
 */
function prependOrderNote( html ) {
	const list = document.querySelector( 'ul.order_notes' );

	if ( ! list || ! html ) {
		return;
	}

	const template = document.createElement( 'template' );
	template.innerHTML = html.trim();
	const item = template.content.firstElementChild;

	if ( ! item ) {
		return;
	}

	const placeholder = list.querySelector( 'li.no-items' );
	if ( placeholder ) {
		placeholder.remove();
	}

	list.insertBefore( item, list.firstChild );
}

/**
 * The general notice content of a rejected apiFetch call ({ code,
 * message, data }) or a transport failure.
 */
function requestErrorContent( error ) {
	return {
		message: error && error.message ? error.message : __( 'The request failed. Please try again.', 'smart-send-logistics' ),
		details: [],
		responseId: null,
		code: error && error.code ? error.code : 'request_failed',
	};
}

export default function App( { initialState, mount } ) {
	const [ state, setState ] = useState( initialState );
	const [ form, setForm ] = useState( () => formFromState( initialState ) );
	const [ submitting, setSubmitting ] = useState( null ); // null | 'outbound' | 'return'
	const [ run, setRun ] = useState( [] ); // the last POST's shipments[]
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ notices, setNotices ] = useState( [] ); // general error notices
	const [ editing, setEditing ] = useState( { method: false, pickupPoint: false, returnMethod: false, parcels: false } );
	const edit = ( key, value = true ) => setEditing( ( previous ) => ( { ...previous, [ key ]: value } ) );
	const [ rebook, setRebook ] = useState( { outbound: false, return: false } ); // disclosure open
	const [ confirmed, setConfirmed ] = useState( { outbound: false, return: false } ); // confirm checkbox

	const current = boxState( state );
	const agentMethod = isAgentMethod( form.shippingMethod );
	const disabled = submitting !== null || current === STATE_NOT_CONNECTED;
	// The not-connected state shows the rows read-only, without Edit links.
	const editable = current !== STATE_NOT_CONNECTED;
	// A return method chosen in the box that differs from the configured
	// one (or fills in a missing one) is sent as the return leg's method.
	const returnMethodOverride = form.returnMethod && form.returnMethod !== state.return.method ? form.returnMethod : null;

	// The fieldset the app mounted on carries the state for stable
	// selectors and is disabled while not connected / submitting - the
	// server rendered it disabled, so nothing is clickable before the app
	// took over.
	useEffect( () => {
		mount.setAttribute( 'data-ss-state', current );
		mount.setAttribute( 'data-ss-app', submitting ? 'submitting' : 'ready' );
		mount.disabled = disabled;
	}, [ mount, current, submitting, disabled ] );

	const runEntry = ( direction ) => run.find( ( entry ) => entry.direction === direction ) || null;

	const updateForm = ( patch ) => setForm( ( previous ) => ( { ...previous, ...patch } ) );

	const clearErrors = () => {
		setFieldErrors( {} );
		setNotices( [] );
	};

	const lookup = async ( agentNo ) => {
		setFieldErrors( ( previous ) => ( { ...previous, 'pickup_point.agent_no': undefined } ) );

		if ( agentNo === '' ) {
			return;
		}

		try {
			const point = await lookupPickupPoint( state.urls.rest, agentNo, form.shippingMethod );
			updateForm( { pickupPoint: point } );
			edit( 'pickupPoint', false );
		} catch ( error ) {
			const fields = error && error.data && error.data.form_fields ? error.data.form_fields : {};
			setFieldErrors( ( previous ) => ( {
				...previous,
				'pickup_point.agent_no': fields[ 'pickup_point.agent_no' ] || [ error && error.message ? error.message : __( 'The pickup point could not be found.', 'smart-send-logistics' ) ],
			} ) );
		}
	};

	/**
	 * The request body of a flow (section 3.1).
	 */
	const requestBody = ( flow ) => {
		const isReturn = flow === 'return';

		return {
			flow,
			with_return: isReturn ? null : ( state.return_shipment ? false : form.withReturn ),
			return_method: ! isReturn && form.withReturn ? returnMethodOverride : null,
			confirm_rebook: !! confirmed[ flow ],
			delivery_details: {
				// A return leg books with the configured return method unless
				// one was chosen in the box (the order has none - state B - or
				// the merchant edited it): then that one.
				shipping_method: isReturn ? returnMethodOverride : form.shippingMethod || null,
				pickup_point: ! isReturn && agentMethod && form.pickupPoint && form.pickupPoint.agent_no ? { agent_no: String( form.pickupPoint.agent_no ) } : null,
				parcel_plan: planFromBoxes( state.order.units, form.boxes, form.assignment ),
			},
		};
	};

	const submit = async ( flow ) => {
		clearErrors();
		setSubmitting( flow );

		let response;
		try {
			response = await fulfill( state.urls.rest, requestBody( flow ) );
		} catch ( error ) {
			const content = requestErrorContent( error );
			const fields = error && error.data && error.data.form_fields ? error.data.form_fields : {};
			setFieldErrors( fields );
			setNotices( [ { key: content.code, ...content } ] );
			setSubmitting( null );
			return;
		}

		const entries = response.shipments || [];
		const errors = {};
		const generalNotices = [];

		entries.forEach( ( entry ) => {
			if ( entry.status === 'fulfilled' ) {
				if ( entry.order_note && entry.order_note.html ) {
					prependOrderNote( entry.order_note.html );
				}
				return;
			}

			Object.assign( errors, entry.error.form_fields || {} );
			const content = generalErrorContent( entry.error );
			generalNotices.push( { key: entry.direction + '_failed', direction: entry.direction, ...content } );
		} );

		setFieldErrors( errors );
		setNotices( generalNotices );
		setRun( entries );
		setState( response.state );
		setRebook( { outbound: false, return: false } );
		setConfirmed( { outbound: false, return: false } );

		if ( response.state && response.state.delivery_details ) {
			// The booking persisted the submitted pickup point and split;
			// keep the merchant's choices, refresh what the server stored.
			updateForm( { pickupPoint: response.state.delivery_details.pickup_point || form.pickupPoint } );
		}

		setSubmitting( null );
	};

	const outboundEntry = runEntry( 'outbound' );
	const returnEntry = runEntry( 'return' );

	const buttonText = ( flow ) => {
		if ( submitting === flow ) {
			return __( 'Creating label…', 'smart-send-logistics' );
		}
		const demo = state.demo_mode ? __( 'DEMO MODE: ', 'smart-send-logistics' ) : '';

		return demo + ( flow === 'return' ? __( 'Create return label', 'smart-send-logistics' ) : __( 'Create shipping label', 'smart-send-logistics' ) );
	};

	const createButton = ( flow, primary, extraDisabled = false ) => (
		<Button
			variant={ primary ? 'primary' : 'secondary' }
			className="smart-send-fulfillment__action"
			data-ss-action={ flow === 'return' ? 'create-return-label' : 'create-label' }
			onClick={ () => submit( flow ) }
			disabled={ disabled || extraDisabled }
			isBusy={ submitting === flow }
		>
			{ buttonText( flow ) }
		</Button>
	);

	const generalNotices = notices.map( ( notice ) => (
		<ErrorNotice
			key={ notice.key }
			noticeKey={ notice.key }
			message={ notice.message }
			details={ notice.details }
			responseId={ notice.responseId }
			onDismiss={ () => setNotices( ( previous ) => previous.filter( ( other ) => other !== notice ) ) }
		/>
	) );

	const callouts = (
		<Fragment>
			{ current === STATE_NOT_CONNECTED && (
				<div className="smart-send-fulfillment__notice" data-ss-notice="not_connected">
					<Notice status="warning" isDismissible={ false }>
						<p>{ __( 'Smart Send is not connected. Enter your API token in the settings to create labels.', 'smart-send-logistics' ) }</p>
						<p>
							<a className="components-button is-secondary" href={ state.urls.settings } data-ss-action="open-settings">
								{ __( 'Open settings', 'smart-send-logistics' ) }
							</a>
						</p>
					</Notice>
				</div>
			) }
			{ current === STATE_NO_METHOD && (
				<div className="smart-send-fulfillment__notice" data-ss-notice="no_method">
					<Notice status="info" isDismissible={ false }>
						{ __( 'This order has no Smart Send shipping method. Choose the method to ship it with.', 'smart-send-logistics' ) }
					</Notice>
				</div>
			) }
			{ generalNotices }
		</Fragment>
	);

	/**
	 * A section of the box: 12px padding, a divider above every one but
	 * the first.
	 */
	const section = ( key, children, modifier = '' ) => (
		<div className={ 'smart-send-fulfillment__section' + ( modifier ? ' smart-send-fulfillment__section--' + modifier : '' ) } data-ss-section={ key }>
			{ children }
		</div>
	);

	/**
	 * The return method row: the configured method with "Edit", or the
	 * select right away when none is configured.
	 */
	const returnMethodRow = ( id ) => (
		<ReturnMethodField
			id={ id }
			groups={ state.methods.return }
			value={ form.returnMethod }
			configured={ state.return.method }
			editing={ editing.returnMethod }
			editable={ editable }
			onEdit={ () => edit( 'returnMethod' ) }
			onChange={ ( value ) => updateForm( { returnMethod: value } ) }
			errors={ fieldErrors }
		/>
	);

	// The return-only action needs a return method: the configured one or
	// the one chosen in the return method row.
	const noReturnMethod = ! state.return.method && ! form.returnMethod;

	/**
	 * The not-yet-booked form (states B, C, D) as its sections: the
	 * shipping section (with the callouts), the parcels section, the
	 * actions and the settings section with the return checkbox.
	 */
	const detailsForm = ( withActions = true, extraDisabled = false, withCallouts = true ) => {
		// A return label already booked on its own: the outbound form
		// books outbound only.
		const withReturnToggle = ! state.return_shipment;

		return (
		<Fragment>
			{ section( 'details', (
				<Fragment>
					{ withCallouts && callouts }
					<MethodField
						groups={ state.methods.outbound }
						value={ form.shippingMethod }
						editing={ editing.method }
						editable={ editable }
						onEdit={ () => edit( 'method' ) }
						onChange={ ( value ) => updateForm( { shippingMethod: value } ) }
						errors={ fieldErrors }
						debugItems={ state.debug && state.debug.enabled ? state.debug.shipping_items : [] }
					/>
					{ agentMethod && (
						<PickupPointField
							pickupPoint={ form.pickupPoint }
							editing={ editing.pickupPoint }
							editable={ editable }
							onEdit={ () => edit( 'pickupPoint' ) }
							onLookup={ lookup }
							errors={ fieldErrors }
							disabled={ disabled }
						/>
					) }
					{ withReturnToggle && returnMethodRow( 'smart-send-return-method' ) }
				</Fragment>
			) ) }
			<ParcelEditor
				units={ state.order.units }
				boxes={ form.boxes }
				assignment={ form.assignment }
				editing={ editing.parcels }
				editable={ editable }
				onEdit={ () => edit( 'parcels' ) }
				onDone={ () => edit( 'parcels', false ) }
				onChange={ ( boxes, assignment ) => updateForm( { boxes, assignment } ) }
				errors={ fieldErrors }
				disabled={ disabled }
			/>
			{ withActions && section( 'actions', (
				<div className="smart-send-fulfillment__actions">
					{ createButton( 'outbound', true, extraDisabled || ! form.shippingMethod || ( withReturnToggle && form.withReturn && noReturnMethod ) ) }
					{ /* Both actions are always offered before booking: the
					     primary outbound action and, as the secondary one, the
					     return-only action (flow: return). */ }
					{ withReturnToggle && createButton( 'return', false, extraDisabled || noReturnMethod ) }
				</div>
			), 'actions' ) }
			{ withReturnToggle && section( 'settings', (
				<ReturnToggle
					checked={ form.withReturn }
					onChecked={ ( checked ) => updateForm( { withReturn: checked } ) }
					disabled={ disabled }
				/>
			), 'settings' ) }
		</Fragment>
		);
	};

	/**
	 * The "Book again" disclosure of a booked direction (state G).
	 */
	const rebookDisclosure = ( flow ) => (
		<div className="smart-send-fulfillment__rebook" data-ss-section={ flow === 'return' ? 'rebook_return' : 'rebook' }>
			<Button variant="link" size="small" data-ss-action={ flow === 'return' ? 'rebook-return' : 'rebook' } onClick={ () => setRebook( ( previous ) => ( { ...previous, [ flow ]: ! previous[ flow ] } ) ) } disabled={ disabled } aria-expanded={ rebook[ flow ] }>
				{ ( rebook[ flow ] ? '▾ ' : '▸ ' ) + __( 'Book again (creates a new shipment)', 'smart-send-logistics' ) }
			</Button>
			{ rebook[ flow ] && (
				<div className="smart-send-fulfillment__rebook-panel">
					<div className="smart-send-fulfillment__notice" data-ss-notice="rebook">
						<Notice status="warning" isDismissible={ false }>
							{ flow === 'return'
								? __( 'A return label already exists for this order. Booking again creates a new shipment at Smart Send; the old one is not cancelled.', 'smart-send-logistics' )
								: __( 'A shipping label already exists for this order. Booking again creates a new shipment at Smart Send; the old one is not cancelled.', 'smart-send-logistics' ) }
						</Notice>
					</div>
					<label className="smart-send-fulfillment__row smart-send-fulfillment__check">
						<input
							type="checkbox"
							data-ss-field={ flow === 'return' ? 'confirm_rebook_return' : 'confirm_rebook' }
							checked={ confirmed[ flow ] }
							onChange={ ( event ) => setConfirmed( ( previous ) => ( { ...previous, [ flow ]: event.target.checked } ) ) }
							disabled={ disabled }
							autoComplete="off"
						/>
						<span className="smart-send-fulfillment__check-text">{ __( 'I understand, create a new shipment', 'smart-send-logistics' ) }</span>
					</label>
					{ flow === 'return' ? (
						<Fragment>
							{ returnMethodRow( 'smart-send-return-method-rebook' ) }
							<div className="smart-send-fulfillment__actions">
								{ createButton( 'return', false, ! confirmed.return || noReturnMethod ) }
							</div>
						</Fragment>
					) : (
						detailsForm( true, ! confirmed.outbound, false )
					) }
				</div>
			) }
		</div>
	);

	/**
	 * One direction of the booked state: the fresh block from the run, a
	 * failed leg's error next to it, the legacy id-only block, or - for a
	 * direction not booked yet - its action.
	 */
	const bookedBlock = ( flow ) => {
		const isReturn = flow === 'return';
		const entry = isReturn ? returnEntry : outboundEntry;
		const stored = isReturn ? state.return_shipment : state.outbound_shipment;

		if ( entry && entry.status === 'fulfilled' ) {
			return (
				<BookedShipment isReturn={ isReturn } shipment={ entry.shipment } steps={ entry.steps } warnings={ entry.warnings || [] }>
					{ rebookDisclosure( flow ) }
				</BookedShipment>
			);
		}

		if ( stored ) {
			return (
				<BookedShipment isReturn={ isReturn } shipment={ stored } legacy>
					{ rebookDisclosure( flow ) }
				</BookedShipment>
			);
		}

		// The return not booked yet: its row with the return method and
		// the return-only action.
		return (
			<div className="smart-send-fulfillment__row" data-ss-section="return_shipment">
				<p>
					<strong>{ __( 'Return label', 'smart-send-logistics' ) }</strong> { __( 'not created', 'smart-send-logistics' ) }
				</p>
				{ returnMethodRow( 'smart-send-return-method-only' ) }
				<div className="smart-send-fulfillment__actions">
					{ createButton( 'return', false, noReturnMethod ) }
				</div>
			</div>
		);
	};

	if ( current === STATE_BOOKED ) {
		return (
			<Fragment>
				{ state.outbound_shipment
					? section( 'outbound', (
						<Fragment>
							{ callouts }
							{ bookedBlock( 'outbound' ) }
						</Fragment>
					) )
					// Return booked on its own, outbound not yet: the outbound
					// form's sections (with the callouts).
					: detailsForm() }
				{ section( 'return', bookedBlock( 'return' ) ) }
			</Fragment>
		);
	}

	// Not connected (read-only rows, no Edit links), no method (B), ready (C).
	return detailsForm();
}
