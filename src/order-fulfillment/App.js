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
 *                            for a combined run); the order is bookable
 *   C not yet booked     method / pickup point / parcels rows, return toggle
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
 * Every request is POST …/fulfillment via apiFetch; a fulfilled leg's
 * order note is prepended to WooCommerce's ul.order_notes (both the HPOS
 * and the legacy screen render it) and the box re-renders from the state
 * the response carries. No page reload anywhere.
 */
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
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
import MethodField from './MethodField';
import { MethodSelect } from './MethodField';
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
	const [ editing, setEditing ] = useState( { method: false, pickupPoint: false, parcels: false } );
	const [ rebook, setRebook ] = useState( { outbound: false, return: false } ); // disclosure open
	const [ confirmed, setConfirmed ] = useState( { outbound: false, return: false } ); // confirm checkbox

	const current = boxState( state );
	const agentMethod = isAgentMethod( form.shippingMethod );
	const disabled = submitting !== null || current === STATE_NOT_CONNECTED;

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
			setEditing( ( previous ) => ( { ...previous, pickupPoint: false } ) );
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
		const needsReturnMethod = ! state.return.method;

		return {
			flow,
			with_return: isReturn ? null : ( state.return_shipment ? false : form.withReturn ),
			return_method: ! isReturn && form.withReturn && needsReturnMethod ? form.returnMethod || null : null,
			confirm_rebook: !! confirmed[ flow ],
			delivery_details: {
				// A return leg books with the configured return method unless
				// the order has none (state B): then the chosen one.
				shipping_method: isReturn ? ( needsReturnMethod ? form.returnMethod || null : null ) : form.shippingMethod || null,
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

	/**
	 * The return method select for a return-only action on an order
	 * without a configured return method.
	 */
	const returnMethodRow = ! state.return.method && (
		<div className="smart-send-fulfillment__row" data-ss-section="return_method">
			<label htmlFor="smart-send-return-method-only">
				<strong>{ __( 'Return method', 'smart-send-logistics' ) }</strong>
			</label>
			<MethodSelect id="smart-send-return-method-only" field="return_method" groups={ state.methods.return } value={ form.returnMethod } onChange={ ( value ) => updateForm( { returnMethod: value } ) } />
		</div>
	);

	/**
	 * The not-yet-booked form (states B, C, D): the details rows, the
	 * return toggle and the create button.
	 */
	const detailsForm = ( withActions = true, extraDisabled = false ) => {
		// A return label already booked on its own: the outbound form
		// books outbound only.
		const withReturnToggle = ! state.return_shipment;

		return (
		<Fragment>
			<MethodField
				groups={ state.methods.outbound }
				value={ form.shippingMethod }
				editing={ editing.method }
				onEdit={ () => setEditing( ( previous ) => ( { ...previous, method: true } ) ) }
				onChange={ ( value ) => updateForm( { shippingMethod: value } ) }
				errors={ fieldErrors }
				debugItems={ state.debug && state.debug.enabled ? state.debug.shipping_items : [] }
			/>
			{ agentMethod && (
				<PickupPointField
					pickupPoint={ form.pickupPoint }
					editing={ editing.pickupPoint }
					onEdit={ () => setEditing( ( previous ) => ( { ...previous, pickupPoint: true } ) ) }
					onCancel={ () => setEditing( ( previous ) => ( { ...previous, pickupPoint: false } ) ) }
					onLookup={ lookup }
					errors={ fieldErrors }
					disabled={ disabled }
				/>
			) }
			<ParcelEditor
				units={ state.order.units }
				boxes={ form.boxes }
				assignment={ form.assignment }
				editing={ editing.parcels }
				onEdit={ () => setEditing( ( previous ) => ( { ...previous, parcels: true } ) ) }
				onDone={ () => setEditing( ( previous ) => ( { ...previous, parcels: false } ) ) }
				onChange={ ( boxes, assignment ) => updateForm( { boxes, assignment } ) }
				errors={ fieldErrors }
				disabled={ disabled }
			/>
			{ withReturnToggle && (
				<ReturnToggle
					configuredMethod={ state.return.method }
					groups={ state.methods.return }
					checked={ form.withReturn }
					onChecked={ ( checked ) => updateForm( { withReturn: checked } ) }
					returnMethod={ form.returnMethod }
					onReturnMethod={ ( value ) => updateForm( { returnMethod: value } ) }
					errors={ fieldErrors }
					disabled={ disabled }
				/>
			) }
			{ withActions && (
				<p className="smart-send-fulfillment__actions">
					{ createButton( 'outbound', true, extraDisabled || ! form.shippingMethod || ( withReturnToggle && form.withReturn && ! state.return.method && ! form.returnMethod ) ) }
					{ /* The separate return-only action (a return method is
					     needed: the configured one, or the one chosen above). */ }
					{ withReturnToggle && createButton( 'return', false, extraDisabled || ( ! state.return.method && ! form.returnMethod ) ) }
					{ submitting !== null && <Spinner /> }
				</p>
			) }
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
					<label className="smart-send-fulfillment__row">
						<input
							type="checkbox"
							data-ss-field={ flow === 'return' ? 'confirm_rebook_return' : 'confirm_rebook' }
							checked={ confirmed[ flow ] }
							onChange={ ( event ) => setConfirmed( ( previous ) => ( { ...previous, [ flow ]: event.target.checked } ) ) }
							disabled={ disabled }
							autoComplete="off"
						/>{ ' ' }
						{ __( 'I understand, create a new shipment', 'smart-send-logistics' ) }
					</label>
					{ flow === 'return' ? (
						<Fragment>
							{ returnMethodRow }
							<p className="smart-send-fulfillment__actions">
								{ createButton( 'return', false, ! confirmed.return || ( ! state.return.method && ! form.returnMethod ) ) }
								{ submitting === 'return' && <Spinner /> }
							</p>
						</Fragment>
					) : (
						detailsForm( true, ! confirmed.outbound )
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

		if ( isReturn ) {
			return (
				<div className="smart-send-fulfillment__row" data-ss-section="return_shipment">
					<p>
						<strong>{ __( 'Return label', 'smart-send-logistics' ) }</strong> { __( 'not created', 'smart-send-logistics' ) }
					</p>
					{ returnMethodRow }
					<p className="smart-send-fulfillment__actions">
						{ createButton( 'return', false, ! state.return.method && ! form.returnMethod ) }
						{ submitting === 'return' && <Spinner /> }
					</p>
				</div>
			);
		}

		// Return booked on its own, outbound not yet: the outbound form.
		return detailsForm();
	};

	if ( current === STATE_NOT_CONNECTED ) {
		return (
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
		);
	}

	if ( current === STATE_BOOKED ) {
		return (
			<Fragment>
				{ generalNotices }
				{ bookedBlock( 'outbound' ) }
				<hr />
				{ bookedBlock( 'return' ) }
			</Fragment>
		);
	}

	return (
		<Fragment>
			{ current === STATE_NO_METHOD && (
				<div className="smart-send-fulfillment__notice" data-ss-notice="no_method">
					<Notice status="info" isDismissible={ false }>
						{ __( 'This order was not placed with a Smart Send shipping method. Choose one to book anyway.', 'smart-send-logistics' ) }
					</Notice>
				</div>
			) }
			{ generalNotices }
			{ detailsForm() }
		</Fragment>
	);
}
