/**
 * The Smart Send pickup point selector rendered inside the Checkout block
 * (below the shipping options - the shipping-methods inner block area).
 *
 * All state is server-computed and rides the cart response under
 * extensions['smart-send'] (see SS_Shipping_Store_Api::cart_extension_data()):
 * whether the chosen rate is an agent-type method, the pickup points close
 * to the shipping address with pre-formatted labels, the section status
 * (one of the SS_Shipping_Checkout_Options PICKUP_POINT_STATUS_* slugs) with its
 * server-translated message, the session-stored selection and the "Select
 * Default" setting. The block re-renders from the wc/store/cart store, so
 * every address or rate change updates the selector with zero client-side
 * fetch logic and no client-side string tables.
 *
 * A selection travels on BOTH channels, each with its own job:
 *  - setExtensionData() puts it in the checkout POST's extensions payload,
 *    which SS_Shipping_Store_Api::persist_pickup_point_from_request() reads
 *    when the order is placed;
 *  - extensionCartUpdate() posts it to the cart extensions endpoint, whose
 *    update callback stores it in the WooCommerce session so the selection
 *    survives cart refreshes (and feeds selected_agent_no back down).
 *
 * While an agent rate offers pickup points with none selected, a validation
 * error in wc/store/validation blocks Place Order client-side; the server's
 * RouteException (HTTP 400) remains the backstop. No degraded state
 * (address incomplete, not connected, auth failure, none found, lookup
 * failure) ever blocks placing the order.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { CART_STORE_KEY, VALIDATION_STORE_KEY } from '@woocommerce/block-data';

import { defaultTitle, defaultDescription } from './attributes';

const EXTENSION_NAMESPACE = 'smart-send';
const VALIDATION_ERROR_ID = 'smart-send-pickup-point';

// Server-computed section statuses (SS_Shipping_Checkout_Options PICKUP_POINT_STATUS_*).
const STATUS_FOUND = 'found';
const ERROR_STATUSES = [ 'not_connected', 'auth_failed', 'access_denied' ];

// The data-status testability value per section status (see the render
// comment below). none_found and lookup_failed both render the quiet
// "empty" state; the shop-side error statuses render as "error".
const DATA_STATUS = {
	found: 'ready',
	address_incomplete: 'awaiting-address',
	none_found: 'empty',
	lookup_failed: 'empty',
	not_connected: 'error',
	auth_failed: 'error',
	access_denied: 'error',
};

const Block = ( {
	// A forced render (block missing from the saved page markup) passes no
	// props, so the translated attribute defaults live here too.
	title = defaultTitle,
	description = defaultDescription,
	className = '',
	checkoutExtensionData,
} ) => {
	const { setExtensionData } = checkoutExtensionData;

	const extensionData = useSelect(
		( select ) =>
			select( CART_STORE_KEY ).getCartData().extensions[
				EXTENSION_NAMESPACE
			],
		[]
	);
	const validationError = useSelect(
		( select ) =>
			select( VALIDATION_STORE_KEY ).getValidationError(
				VALIDATION_ERROR_ID
			),
		[]
	);
	const { setValidationErrors, clearValidationError } =
		useDispatch( VALIDATION_STORE_KEY );

	const {
		selected_rate_is_agent: selectedRateIsAgent = false,
		pickup_points: pickupPoints = [],
		pickup_point_status: pickupPointStatus = null,
		pickup_point_message: pickupPointMessage = null,
		selected_agent_no: selectedAgentNo = null,
		select_default: selectDefault = false,
	} = extensionData || {};

	// Pickup points were actually offered to the customer - the only state
	// in which a selection is required.
	const pickupPointsOffered =
		pickupPointStatus === STATUS_FOUND && pickupPoints.length > 0;

	// The select's value: locally owned for an immediate UI response, synced
	// from the session-stored selection the cart response carries.
	const [ agentNo, setAgentNo ] = useState( selectedAgentNo || '' );
	useEffect( () => {
		setAgentNo( selectedAgentNo || '' );
	}, [ selectedAgentNo ] );

	const pushSelection = useCallback(
		( value ) => {
			setAgentNo( value );
			setExtensionData( EXTENSION_NAMESPACE, 'agent_no', value );
			extensionCartUpdate( {
				namespace: EXTENSION_NAMESPACE,
				data: { agent_no: value },
			} );
		},
		[ setExtensionData ]
	);

	// Keep the checkout POST payload in sync with the session-stored
	// selection, covering selections restored from a previous visit (the
	// session already has an agent_no the customer never re-picks).
	useEffect( () => {
		if ( selectedRateIsAgent ) {
			setExtensionData( EXTENSION_NAMESPACE, 'agent_no', agentNo );
		}
	}, [ selectedRateIsAgent, agentNo, setExtensionData ] );

	// The "Select Default" setting: pre-select the closest pickup point when
	// nothing is selected yet, exactly as the classic checkout does.
	useEffect( () => {
		if (
			selectDefault &&
			selectedRateIsAgent &&
			! agentNo &&
			pickupPointsOffered
		) {
			pushSelection( pickupPoints[ 0 ].agent_no );
		}
	}, [
		selectDefault,
		selectedRateIsAgent,
		agentNo,
		pickupPointsOffered,
		pickupPoints,
		pushSelection,
	] );

	// When no pickup points are offered (none near the address, lookup
	// failure, plugin not connected), there is nothing to select: clear any
	// stale selection (e.g. picked for a previous address) from the session
	// and the checkout POST payload so the server never receives an
	// agent_no that no longer applies.
	useEffect( () => {
		if (
			selectedRateIsAgent &&
			pickupPointStatus &&
			pickupPointStatus !== STATUS_FOUND &&
			agentNo
		) {
			pushSelection( '' );
		}
	}, [ selectedRateIsAgent, pickupPointStatus, agentNo, pushSelection ] );

	// Block Place Order while pickup points are offered with none selected.
	// Hidden until the customer submits (the checkout then reveals all
	// validation errors); cleared on selection and on unmount (rate change).
	// No degraded state registers an error - the order may then be placed
	// without a selection (classic-checkout parity), and the server accepts
	// it based on its own session-cached lookup result.
	useEffect( () => {
		if ( selectedRateIsAgent && ! agentNo && pickupPointsOffered ) {
			setValidationErrors( {
				[ VALIDATION_ERROR_ID ]: {
					message: __(
						'A pickup point must be selected.',
						'smart-send-logistics'
					),
					hidden: true,
				},
			} );
		} else {
			clearValidationError( VALIDATION_ERROR_ID );
		}

		return () => {
			clearValidationError( VALIDATION_ERROR_ID );
		};
	}, [
		selectedRateIsAgent,
		agentNo,
		pickupPointsOffered,
		setValidationErrors,
		clearValidationError,
	] );

	if ( ! selectedRateIsAgent ) {
		return null;
	}

	// Testability affordances (used by the browser tests to wait on
	// observable state instead of racing the Store API round trips):
	// data-status starts at "loading" until the server-computed state has
	// arrived, then maps the section status - "ready" (selector),
	// "awaiting-address", "empty" (none found / lookup failed) or "error"
	// (shop-side connection problems); data-selected-agent reflects the
	// selection the component has pushed into the checkout POST payload.
	const dataStatus =
		( pickupPointStatus && DATA_STATUS[ pickupPointStatus ] ) || 'loading';

	// Any non-found section status renders as a message instead of the
	// selector: the server-translated text, styled and announced as an
	// error only for the shop-side error statuses.
	if ( pickupPointStatus && pickupPointStatus !== STATUS_FOUND ) {
		const isErrorStatus = ERROR_STATUSES.includes( pickupPointStatus );

		return (
			<div
				className={ `ss-pickup-point-block ${ className }` }
				data-status={ dataStatus }
				data-selected-agent=""
			>
				{ !! title && (
					<h2 className="ss-pickup-point-block__title">{ title }</h2>
				) }
				<p
					className={ `ss-pickup-point-block__message ss-pickup-point-block__message--${ pickupPointStatus }` }
					role={ isErrorStatus ? 'alert' : 'status' }
				>
					{ pickupPointMessage }
				</p>
			</div>
		);
	}

	const options = pickupPoints.map( ( pickupPoint ) => ( {
		label: pickupPoint.label,
		value: pickupPoint.agent_no,
	} ) );

	if ( ! selectDefault ) {
		options.unshift( {
			label: __( '- Select Pickup Point -', 'smart-send-logistics' ),
			value: '',
		} );
	}

	const hasVisibleError = !! validationError && ! validationError.hidden;

	return (
		<div
			className={ `ss-pickup-point-block ${ className }` }
			data-status={ dataStatus }
			data-selected-agent={ agentNo }
		>
			{ !! title && (
				<h2 className="ss-pickup-point-block__title">{ title }</h2>
			) }
			{ !! description && (
				<p className="ss-pickup-point-block__description">
					{ description }
				</p>
			) }
			<SelectControl
				id="ss-pickup-point-select"
				className="ss-pickup-point-block__select"
				label={ __( 'Pickup point', 'smart-send-logistics' ) }
				hideLabelFromVision={ true }
				value={ agentNo }
				options={ options }
				onChange={ pushSelection }
				__nextHasNoMarginBottom={ true }
			/>
			{ hasVisibleError && (
				<div
					className="wc-block-components-validation-error ss-pickup-point-block__error"
					role="alert"
				>
					<p>{ validationError.message }</p>
				</div>
			) }
		</div>
	);
};

export default Block;
