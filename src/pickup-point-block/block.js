/**
 * The Smart Send pickup point selector rendered inside the Checkout block
 * (below the shipping options - the shipping-methods inner block area).
 *
 * All state is server-computed and rides the cart response under
 * extensions['smart-send'] (see SS_Shipping_Store_Api::cart_extension_data()):
 * whether the chosen rate is an agent-type method, the pickup points close
 * to the shipping address with pre-formatted labels, the session-stored
 * selection and the "Select Default" setting. The block re-renders from the
 * wc/store/cart store, so every address or rate change updates the selector
 * with zero client-side fetch logic.
 *
 * A selection travels on BOTH channels, each with its own job:
 *  - setExtensionData() puts it in the checkout POST's extensions payload,
 *    which SS_Shipping_Store_Api::persist_pickup_point_from_request() reads
 *    when the order is placed;
 *  - extensionCartUpdate() posts it to the cart extensions endpoint, whose
 *    update callback stores it in the WooCommerce session so the selection
 *    survives cart refreshes (and feeds selected_agent_no back down).
 *
 * While an agent rate is chosen with no pickup point selected, a validation
 * error in wc/store/validation blocks Place Order client-side; the server's
 * RouteException (HTTP 400) remains the backstop.
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
		no_pickup_points_found: noPickupPointsFound = false,
		selected_agent_no: selectedAgentNo = null,
		select_default: selectDefault = false,
	} = extensionData || {};

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
			pickupPoints.length > 0
		) {
			pushSelection( pickupPoints[ 0 ].agent_no );
		}
	}, [
		selectDefault,
		selectedRateIsAgent,
		agentNo,
		pickupPoints,
		pushSelection,
	] );

	// When the lookup found NO pickup points for the address, there is
	// nothing to select: clear any stale selection (e.g. picked for a
	// previous address) from the session and the checkout POST payload so
	// the server never receives an agent_no that no longer applies.
	useEffect( () => {
		if ( selectedRateIsAgent && noPickupPointsFound && agentNo ) {
			pushSelection( '' );
		}
	}, [ selectedRateIsAgent, noPickupPointsFound, agentNo, pushSelection ] );

	// Block Place Order while an agent rate has no pickup point selected.
	// Hidden until the customer submits (the checkout then reveals all
	// validation errors); cleared on selection and on unmount (rate change).
	// No error when the lookup found NO pickup points at all - the order may
	// then be placed without a selection (classic-checkout parity), and the
	// server accepts it based on its own session-cached lookup result.
	useEffect( () => {
		if ( selectedRateIsAgent && ! agentNo && ! noPickupPointsFound ) {
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
		noPickupPointsFound,
		setValidationErrors,
		clearValidationError,
	] );

	if ( ! selectedRateIsAgent ) {
		return null;
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

	// The lookup ran and found nothing: no selector, no validation error -
	// just the same friendly, carrier-neutral fallback text the classic
	// checkout shows. The customer places the order without a selection.
	if ( noPickupPointsFound ) {
		return (
			<div
				className={ `ss-pickup-point-block ${ className }` }
				data-status="empty"
				data-selected-agent=""
			>
				{ !! title && (
					<h2 className="ss-pickup-point-block__title">{ title }</h2>
				) }
				<p className="ss-pickup-point-block__empty" role="status">
					{ __(
						'Shipping to closest pickup point',
						'smart-send-logistics'
					) }
				</p>
			</div>
		);
	}

	return (
		<div
			className={ `ss-pickup-point-block ${ className }` }
			// Testability affordances (used by the browser tests to wait on
			// observable state instead of racing the Store API round trips):
			// data-status flips to "ready" once the server-computed pickup
			// points have arrived (or to "empty" above when the lookup found
			// none); data-selected-agent reflects the selection the component
			// has pushed into the checkout POST payload.
			data-status={ pickupPoints.length > 0 ? 'ready' : 'loading' }
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
