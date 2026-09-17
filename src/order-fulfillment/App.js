/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The meta box app: a small state machine over the server's state object
 * (section 3.3 of #182) - idle → submitting → the state the POST returned
 * (booked, or failed with per-field errors) - rendering the section 1.2
 * states:
 *
 *   A not connected      notice + "Open settings", everything disabled
 *   B no Smart Send method   notice; the method rows read "None" + Edit
 *                            (the select behind Edit); the order is bookable
 *   C not yet booked     method / pickup point / parcels rows, return toggle,
 *                        "Create shipping label" (primary) over "Create
 *                        return label" (secondary, books a return leg only)
 *   D parcel editor      opened from C's "Edit"
 *   E/F booked           THE FORM NEVER CLOSES (#182 review, 2026-09-16):
 *                        a booking adds the green result box of the run to
 *                        the top of the actions section and a new entry to
 *                        the "Booked shipments" timeline; every row, the
 *                        parcel editor, both actions and the return
 *                        checkbox stay exactly as they were
 *   G book again         normal: the first click on an action with any
 *                        already-booked direction warns and relabels
 *                        the button, the second one books with
 *                        confirm_rebook
 *   H submitting         fieldset disabled, spinner, "Creating label…"
 *   I failures           per-field errors from error.form_fields, a general
 *                        notice for the rest + the Response ID
 *
 * Layout (Option A + the booked design): the box is a stack of sections
 * separated by dividers - the shipping section (callouts, then the
 * shipping method / pickup point / return method rows, each a read-only
 * value with an "Edit" link that swaps it for its control in place), the
 * parcels section (collapsed to one summary line; "Edit" expands the
 * editor, "Done" collapses it keeping the plan), the actions section (the
 * green result boxes of the run just made over the two stacked buttons),
 * the "Booked shipments" timeline and the grey settings section (the
 * return checkbox). Editing is component state only, never persisted.
 * This app is the box's ONLY renderer: PHP inlines the state and renders
 * the mount point with a placeholder in it, nothing more.
 *
 * Every request is POST …/fulfillment via apiFetch. The box immediately
 * renders the booking result; WooCommerce renders persisted order notes
 * in its native history after the merchant reloads the page.
 */
import { createElement, Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { fulfill, lookupPickupPoint } from './api';
import {
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
import { bindHelpTips } from './Row';
import BookedShipment from './BookedShipment';
import Timeline from './Timeline';
import ErrorNotice from './ErrorNotice';

/**
 * The form model derived from a state object.
 */
function formFromState( state ) {
	const { boxes, assignment, error } = boxesFromPlan( state.order.units, state.delivery_details.parcel_plan );

	return {
		shippingMethod: state.delivery_details.shipping_method || '',
		pickupPoint: state.delivery_details.pickup_point,
		withReturn: !! state.return.auto_default && !! state.return.method,
		returnMethod: state.return.method || '',
		boxes,
		assignment,
		parcelPlanError: state.parcel_plan_error || ( error ? __( 'The saved parcel allocation no longer matches this order. Reset to one parcel before booking.', 'smart-send-logistics' ) : null ),
	};
}

/**
 * The general notice content of a rejected apiFetch call ({ code,
 * message, data }) or a transport failure.
 */
function requestErrorContent( error ) {
	return {
		message: error && error.message ? error.message : __( 'The request failed. Please try again.', 'smart-send-logistics' ),
		details: [],
		responseId: error && error.data && error.data.response_id ? error.data.response_id : null,
		code: error && error.code ? error.code : 'request_failed',
	};
}

export default function App( { initialState, mount } ) {
	const [ state, setState ] = useState( initialState );
	const [ form, setForm ] = useState( () => formFromState( initialState ) );
	const [ lookupPending, setLookupPending ] = useState( false );
	const lookupRevision = useRef( 0 );
	const [ submitting, setSubmitting ] = useState( null ); // null | 'outbound' | 'return'
	// The shipments of the LAST POST - the green result boxes. Component
	// memory only, deliberately: a reload loses them and falls back to the
	// timeline (which is persisted). Never rendered from the server state.
	const [ run, setRun ] = useState( [] );
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ notices, setNotices ] = useState( [] ); // general error notices
	const [ editing, setEditing ] = useState( { method: false, pickupPoint: false, returnMethod: false, parcels: false } );
	// The flow waiting for its re-book confirmation: the first click on
	// an action with any already-booked direction warns and relabels the
	// button, the second one books (with confirm_rebook). Any other click
	// cancels it.
	const [ pendingConfirm, setPendingConfirm ] = useState( null );

	const cancelConfirm = () => {
		setPendingConfirm( null );
		setNotices( ( previous ) => previous.filter( ( notice ) => notice.key !== 'rebook' ) );
	};
	const edit = ( key, value = true ) => {
		cancelConfirm();
		setEditing( ( previous ) => ( { ...previous, [ key ]: value } ) );
	};
	// A select to focus after the next render (the guard below opens a
	// method row's Edit state when an action is pressed without a method).
	const [ focusField, setFocusField ] = useState( null );

	const current = boxState( state );
	const agentMethod = isAgentMethod( form.shippingMethod );
	const disabled = submitting !== null || current === STATE_NOT_CONNECTED;
	// The not-connected state shows the rows read-only, without Edit links.
	const editable = current !== STATE_NOT_CONNECTED;
	// A return method chosen in the box that differs from the configured
	// one (or fills in a missing one) is sent as the return leg's method.
	const returnMethodOverride = form.returnMethod && form.returnMethod !== state.return.method ? form.returnMethod : null;

	// The fieldset the app mounted on carries the state for stable
	// selectors and is disabled while not connected / submitting. PHP
	// renders it disabled with data-ss-app="loading", so nothing is
	// clickable before the app took over; this is where it takes over.
	useEffect( () => {
		mount.setAttribute( 'data-ss-state', current );
		mount.setAttribute( 'data-ss-app', submitting ? 'submitting' : 'ready' );
		mount.removeAttribute( 'aria-busy' );
		mount.disabled = disabled;
	}, [ mount, current, submitting, disabled ] );

	// WooCommerce binds its tooltip to `.woocommerce-help-tip` on DOM ready
	// only; the help tips this app renders come later, so bind them after
	// every render (idempotent - see bindHelpTips).
	useEffect( () => {
		bindHelpTips( mount );
	} );

	useEffect( () => {
		if ( ! focusField ) {
			return;
		}
		const control = mount.querySelector( '[data-ss-field="' + focusField + '"]' );
		if ( control ) {
			control.focus();
		}
		setFocusField( null );
	}, [ mount, focusField ] );

	const runEntry = ( direction ) => run.find( ( entry ) => entry.direction === direction ) || null;

	const updateForm = ( patch ) => {
		cancelConfirm();
		setForm( ( previous ) => ( { ...previous, ...patch } ) );
	};

	const dropNotice = ( key ) => setNotices( ( previous ) => previous.filter( ( notice ) => notice.key !== key ) );

	const clearErrors = () => {
		setFieldErrors( {} );
		setNotices( [] );
	};

	const lookup = async ( agentNo ) => {
		cancelConfirm();
		setFieldErrors( ( previous ) => ( { ...previous, 'pickup_point.agent_no': undefined } ) );

		if ( agentNo === '' ) {
			return;
		}

		const revision = ++lookupRevision.current;
		setLookupPending( true );
		try {
			const point = await lookupPickupPoint( state.urls.rest, agentNo, form.shippingMethod );
			if ( revision !== lookupRevision.current ) {
				return;
			}
			updateForm( { pickupPoint: point } );
			edit( 'pickupPoint', false );
		} catch ( error ) {
			if ( revision !== lookupRevision.current ) {
				return;
			}
			const fields = error && error.data && error.data.form_fields ? error.data.form_fields : {};
			setFieldErrors( ( previous ) => ( {
				...previous,
				'pickup_point.agent_no': fields[ 'pickup_point.agent_no' ] || [ error && error.message ? error.message : __( 'The pickup point could not be found.', 'smart-send-logistics' ) ],
			} ) );
		} finally {
			if ( revision === lookupRevision.current ) {
				setLookupPending( false );
			}
		}
	};

	/**
	 * The request body of a flow (section 3.1).
	 */
	const requestBody = ( flow ) => {
		const isReturn = flow === 'return';

		return {
			flow,
			with_return: isReturn ? null : form.withReturn,
			return_method: ! isReturn && form.withReturn ? returnMethodOverride : null,
			// The server's 409 guard stays in force: only a confirmed
			// re-booking books a direction that already has a shipment id.
			confirm_rebook: pendingConfirm === flow,
			delivery_details: {
				// A return leg books with the configured return method unless
				// one was chosen in the box (the order has none - state B - or
				// the merchant edited it): then that one.
				shipping_method: isReturn ? returnMethodOverride : form.shippingMethod || null,
				pickup_point: isReturn || ! agentMethod ? null : ( form.pickupPoint && form.pickupPoint.agent_no ? { agent_no: String( form.pickupPoint.agent_no ) } : { clear: true } ),
				parcel_plan: planFromBoxes( state.order.units, form.boxes, form.assignment ),
			},
		};
	};

	// The return-only action and a combined run need a return method: the
	// configured one or the one chosen in the return method row.
	const noReturnMethod = ! state.return.method && ! form.returnMethod;

	/**
	 * The reason an action cannot run yet - the missing method - or null.
	 * Both buttons stay enabled (a disabled button cannot explain itself
	 * and is poor on touch): the sentence is the button's title and, on a
	 * click, the error notice shown instead of sending a request. The REST
	 * controller's 409s stay as the backstop.
	 */
	const missingMethod = ( flow ) => {
		if ( flow === 'outbound' && ! form.shippingMethod ) {
			return { field: 'shipping_method', notice: 'missing_method', message: __( 'Select a shipping method first', 'smart-send-logistics' ) };
		}
		if ( ( flow === 'return' || form.withReturn ) && noReturnMethod ) {
			return { field: 'return_method', notice: 'missing_return_method', message: __( 'Select a return shipping method first', 'smart-send-logistics' ) };
		}

		return null;
	};

	/**
	 * Existing labels in every requested direction, including a return
	 * accompanying an outbound booking. Each duplicate needs confirmation.
	 */
	const rebookingMessage = ( flow ) => {
		const outbound = flow === 'outbound' && !! state.outbound_shipment;
		const returning = ( flow === 'return' || form.withReturn ) && !! state.return_shipment;
		if ( outbound && returning ) {
			return __( 'This order already has shipping and return labels. Booking again creates new shipments at Smart Send; the old ones are not cancelled.', 'smart-send-logistics' );
		}
		if ( returning ) {
			return __( 'This order already has a return label. Booking again creates a new shipment at Smart Send; the old one is not cancelled.', 'smart-send-logistics' );
		}
		return outbound
			? __( 'This order already has a shipping label. Booking again creates a new shipment at Smart Send; the old one is not cancelled.', 'smart-send-logistics' )
			: null;
	};

	const requireConfirmation = ( flow, message ) => {
		setPendingConfirm( flow );
		setNotices( [ { key: 'rebook', status: 'warning', message, details: [], responseId: null } ] );
	};

	const submit = async ( flow ) => {
		if ( lookupPending ) {
			return;
		}
		clearErrors();

		if ( form.parcelPlanError ) {
			cancelConfirm();
			return;
		}

		const missing = missingMethod( flow );
		if ( missing ) {
			cancelConfirm();
			setNotices( [ { key: missing.notice, message: missing.message, details: [], responseId: null } ] );
			edit( missing.field === 'return_method' ? 'returnMethod' : 'method' );
			setFocusField( missing.field );
			return;
		}

		const confirmation = rebookingMessage( flow );
		if ( confirmation && pendingConfirm !== flow ) {
			requireConfirmation( flow, confirmation );
			return;
		}

		setSubmitting( flow );

		let response;
		try {
			response = await fulfill( state.urls.rest, requestBody( flow ) );
		} catch ( error ) {
			// Another screen may have booked since this one loaded. The
			// server blocks the request before booking and supplies the warning.
			if ( error && error.code === 'smart_send_already_booked' ) {
				requireConfirmation( flow, error.message );
				setSubmitting( null );
				return;
			}
			const content = requestErrorContent( error );
			const fields = error && error.data && error.data.form_fields ? error.data.form_fields : {};
			setFieldErrors( fields );
			setNotices( [ { key: content.code, ...content } ] );
			setSubmitting( null );
			cancelConfirm();
			return;
		}

		const entries = response.shipments || [];
		const errors = {};
		const generalNotices = [];

		entries.forEach( ( entry ) => {
			if ( entry.status === 'fulfilled' ) {
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
		cancelConfirm();

		if ( response.state && response.state.delivery_details ) {
			// The booking persisted the submitted pickup point and split;
			// keep the merchant's choices, refresh what the server stored.
			if ( entries.some( ( entry ) => entry.direction === 'outbound' && entry.status === 'fulfilled' ) ) {
				updateForm( { pickupPoint: response.state.delivery_details.pickup_point } );
			}
		}

		setSubmitting( null );
	};

	const buttonText = ( flow ) => {
		if ( submitting === flow ) {
			return __( 'Creating label…', 'smart-send-logistics' );
		}

		if ( pendingConfirm === flow ) {
			return __( 'Yes, create another', 'smart-send-logistics' );
		}

		return flow === 'return' ? __( 'Create return label', 'smart-send-logistics' ) : __( 'Create shipping label', 'smart-send-logistics' );
	};

	const createButton = ( flow, primary ) => {
		const missing = missingMethod( flow );

		return (
			<Button
				variant={ primary ? 'primary' : 'secondary' }
				className="smart-send-fulfillment__action"
				data-ss-action={ flow === 'return' ? 'create-return-label' : 'create-label' }
				data-ss-confirm={ pendingConfirm === flow ? 'rebook' : undefined }
				title={ form.parcelPlanError || ( missing ? missing.message : undefined ) }
				onClick={ () => submit( flow ) }
				disabled={ disabled || lookupPending }
				isBusy={ submitting === flow }
			>
				{ buttonText( flow ) }
			</Button>
		);
	};

	const generalNotices = notices.map( ( notice ) => (
		<ErrorNotice
			key={ notice.key }
			noticeKey={ notice.key }
			status={ notice.status }
			message={ notice.message }
			details={ notice.details }
			responseId={ notice.responseId }
			onDismiss={ () => {
				if ( notice.key === 'rebook' ) {
					cancelConfirm();
				} else {
					setNotices( ( previous ) => previous.filter( ( other ) => other !== notice ) );
				}
			} }
		/>
	) );

	const callouts = (
		<Fragment>
			{ current === STATE_NOT_CONNECTED && (
				<div className="smart-send-fulfillment__notice" data-ss-notice="not_connected">
					<Notice status="warning" isDismissible={ false }>
						<p>{ __( 'Smart Send is not connected. Enter your API token in the settings to create labels.', 'smart-send-logistics' ) }</p>
						<p>
							<a className="button button-small" href={ state.urls.settings } data-ss-action="open-settings">
								{ __( 'Open settings', 'smart-send-logistics' ) }
							</a>
						</p>
					</Notice>
				</div>
			) }
			{ current === STATE_NO_METHOD && (
				<div className="smart-send-fulfillment__notice" data-ss-notice="no_method">
					<Notice status="info" isDismissible={ false }>
						{ __( 'Shipping method is not from the Smart Send plugin.', 'smart-send-logistics' ) }
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
	 * The green result boxes of the run just made, over the actions - one
	 * per shipment the last POST booked, outbound first. From the response
	 * only (see BookedShipment.js).
	 */
	const resultBoxes = [ 'outbound', 'return' ]
		.map( ( direction ) => {
			const entry = runEntry( direction );

			if ( ! entry || entry.status !== 'fulfilled' ) {
				return null;
			}

			return (
				<BookedShipment
					key={ direction }
					isReturn={ direction === 'return' }
					shipment={ entry.shipment }
					steps={ entry.steps }
					warnings={ entry.warnings || [] }
				/>
			);
		} )
		.filter( Boolean );

	return (
		<Fragment>
			{ section( 'details', (
				<Fragment>
					{ callouts }
					<MethodField
						carriers={ state.methods.outbound }
						value={ form.shippingMethod }
						editing={ editing.method }
						editable={ editable }
						onEdit={ () => edit( 'method' ) }
						onChange={ ( value ) => {
							++lookupRevision.current;
							setLookupPending( false );
							const resetPoint = ! isAgentMethod( value ) || value.split( '_' )[ 0 ] !== form.shippingMethod.split( '_' )[ 0 ];
							updateForm( { shippingMethod: value, ...( resetPoint ? { pickupPoint: null } : {} ) } );
							setFieldErrors( ( previous ) => ( { ...previous, 'pickup_point.agent_no': undefined } ) );
							if ( resetPoint && isAgentMethod( value ) ) {
								edit( 'pickupPoint' );
							}
							if ( value ) {
								dropNotice( 'missing_method' );
							}
						} }
						errors={ fieldErrors }
						debugItems={ state.debug && state.debug.enabled ? state.debug.shipping_items : [] }
					/>
					{ agentMethod && (
						<PickupPointField
							key={ form.shippingMethod.split( '_' )[ 0 ] }
							pickupPoint={ form.pickupPoint }
							editing={ editing.pickupPoint }
							editable={ editable }
							onEdit={ () => edit( 'pickupPoint' ) }
							onLookup={ lookup }
							errors={ fieldErrors }
							disabled={ disabled }
						/>
					) }
					<ReturnMethodField
						id="smart-send-return-method"
						carriers={ state.methods.return }
						value={ form.returnMethod }
						configured={ state.return.method }
						editing={ editing.returnMethod }
						editable={ editable }
						onEdit={ () => edit( 'returnMethod' ) }
						onChange={ ( value ) => {
							updateForm( { returnMethod: value } );
							if ( value ) {
								dropNotice( 'missing_return_method' );
							}
						} }
						errors={ fieldErrors }
					/>
				</Fragment>
			) ) }
			<ParcelEditor
				units={ state.order.units }
				boxes={ form.boxes }
				assignment={ form.assignment }
				planError={ form.parcelPlanError }
				editing={ editing.parcels }
				editable={ editable }
				onEdit={ () => edit( 'parcels' ) }
				onDone={ () => edit( 'parcels', false ) }
				onChange={ ( boxes, assignment ) => updateForm( { boxes, assignment } ) }
				onReset={ ( boxes, assignment ) => {
					updateForm( { boxes, assignment, parcelPlanError: null } );
					clearErrors();
				} }
				errors={ fieldErrors }
				disabled={ disabled }
			/>
			{ section( 'actions', (
				<Fragment>
					{ resultBoxes }
					<div className="smart-send-fulfillment__actions">
						{ createButton( 'outbound', true ) }
						{ /* Both actions are always offered: the primary outbound
						     one and, as the secondary one, the return-only action
						     (flow: return). A missing method is explained on
						     click (missingMethod), not by disabling. */ }
						{ createButton( 'return', false ) }
					</div>
				</Fragment>
			), 'actions' ) }
			{ state.timeline && state.timeline.length > 0 && section( 'timeline', <Timeline entries={ state.timeline } />, 'timeline' ) }
			{ section( 'settings', (
				<ReturnToggle
					checked={ form.withReturn }
					onChecked={ ( checked ) => updateForm( { withReturn: checked } ) }
					disabled={ disabled }
				/>
			), 'settings' ) }
		</Fragment>
	);
}
