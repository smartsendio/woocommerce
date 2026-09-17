/**
 * Server-validated pickup point selection for the Checkout Block.
 *
 * Selection updates consume only our extension response. In particular, an
 * older response must never replace WooCommerce's newer address or rate.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { CART_STORE_KEY, VALIDATION_STORE_KEY } from '@woocommerce/block-data';

import { defaultTitle, defaultDescription } from './attributes';

const EXTENSION_NAMESPACE = 'smart-send';
const VALIDATION_ERROR_ID = 'smart-send-pickup-point';
const ERROR_STATUSES = [ 'not_connected', 'auth_failed', 'access_denied' ];
const DATA_STATUS = {
	found: 'ready',
	address_incomplete: 'awaiting-address',
	none_found: 'empty',
	lookup_failed: 'empty',
	not_connected: 'error',
	auth_failed: 'error',
	access_denied: 'error',
};
const contextKey = ( context ) =>
	JSON.stringify(
		context && [
			context.method,
			context.country,
			context.postcode,
			context.city,
			context.address_1,
		]
	);
const addressValue = ( value, field ) => {
	const text = String( value || '' ).trim();
	return field === 'postcode'
		? text.replace( /\s/g, '' ).toUpperCase()
		: text;
};
const matchesAddress = ( context, address ) =>
	!! context &&
	[ 'country', 'postcode', 'city', 'address_1' ].every(
		( field ) =>
			addressValue( context[ field ], field ) ===
			addressValue( address[ field ], field )
	);

const Block = ( {
	title = defaultTitle,
	description = defaultDescription,
	className = '',
	checkoutExtensionData,
} ) => {
	const { setExtensionData } = checkoutExtensionData;
	const { cart, changingAddress, changingRate } = useSelect( ( select ) => {
		const store = select( CART_STORE_KEY );
		return {
			cart: store.getCartData(),
			changingAddress: store.isCustomerDataUpdating(),
			changingRate: store.isShippingRateBeingSelected(),
		};
	}, [] );
	const extensionData = cart.extensions[ EXTENSION_NAMESPACE ] || {};
	const {
		selected_rate_is_agent: selectedRateIsAgent = false,
		pickup_points: pickupPoints = [],
		pickup_point_status: pickupPointStatus = null,
		pickup_point_message: pickupPointMessage = null,
		selected_agent_no: selectedAgentNo = null,
		selection_origin: selectionOrigin = null,
		pickup_point_context: context = null,
		select_default: selectDefault = false,
	} = extensionData;
	const key = contextKey( context );
	const contextReady =
		selectedRateIsAgent &&
		! changingAddress &&
		! changingRate &&
		matchesAddress( context, cart.shippingAddress || {} );
	const validationError = useSelect(
		( select ) =>
			select( VALIDATION_STORE_KEY ).getValidationError(
				VALIDATION_ERROR_ID
			),
		[]
	);
	const { setValidationErrors, clearValidationError } =
		useDispatch( VALIDATION_STORE_KEY );
	const [ selection, setSelection ] = useState( {
		agentNo: selectedAgentNo || '',
		origin: selectionOrigin,
	} );
	const [ pending, setPending ] = useState( false );
	const [ updateError, setUpdateError ] = useState( '' );
	const confirmed = useRef( selection );
	const intent = useRef( null );
	const queue = useRef( null );
	const running = useRef( false );
	const revision = useRef( 0 );
	const mounted = useRef( true );
	const current = useRef( {} );
	current.current = { key, contextReady, selectedRateIsAgent };

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	// A local explicit choice owns its value until the context changes. Cart
	// responses that began before that choice cannot roll it back afterwards.
	useEffect( () => {
		if (
			! selectedRateIsAgent ||
			( intent.current && intent.current.key !== key )
		) {
			intent.current = null;
			queue.current = null;
			revision.current++;
			setPending( false );
			setUpdateError( '' );
		}
		if ( ! intent.current ) {
			confirmed.current = {
				agentNo: selectedRateIsAgent ? selectedAgentNo || '' : '',
				origin: selectedRateIsAgent ? selectionOrigin : null,
			};
			setSelection( confirmed.current );
		}
	}, [ key, selectedRateIsAgent, selectedAgentNo, selectionOrigin ] );

	const drainQueue = useCallback( async () => {
		if ( running.current ) {
			return;
		}
		running.current = true;
		while ( queue.current && mounted.current ) {
			const request = queue.current;
			queue.current = null;
			try {
				// WooCommerce's Store API middleware adds the current nonce to
				// apiFetch requests on all supported WooCommerce versions.
				const response = await apiFetch( {
					path: '/wc/store/v1/cart/extensions',
					method: 'POST',
					data: {
						namespace: EXTENSION_NAMESPACE,
						data: {
							agent_no: request.value,
							selection_origin: 'explicit',
							pickup_point_context: request.context,
						},
					},
					cache: 'no-store',
				} );
				if (
					mounted.current &&
					request.revision === revision.current &&
					request.key === current.current.key
				) {
					const acknowledged =
						response.extensions[ EXTENSION_NAMESPACE ];
					if (
						contextKey( acknowledged.pickup_point_context ) !==
						request.key
					) {
						throw new Error( 'Pickup point context changed.' );
					}
					confirmed.current = {
						agentNo: acknowledged.selected_agent_no || '',
						origin: acknowledged.selection_origin,
					};
					setSelection( confirmed.current );
					setPending( false );
					setUpdateError( '' );
				}
			} catch ( error ) {
				if (
					mounted.current &&
					request.revision === revision.current &&
					request.key === current.current.key
				) {
					setSelection( confirmed.current );
					setPending( false );
					setUpdateError(
						__(
							'The pickup point could not be saved. Please select it again.',
							'smart-send-logistics'
						)
					);
				}
			}
		}
		running.current = false;
	}, [] );

	const pushSelection = useCallback(
		( value ) => {
			if ( ! contextReady ) {
				return;
			}
			const request = {
				value,
				context,
				key,
				revision: ++revision.current,
			};
			intent.current = request;
			queue.current = request;
			setSelection( {
				agentNo: value,
				origin: value ? 'explicit' : null,
			} );
			setPending( true );
			setUpdateError( '' );
			drainQueue();
		},
		[ contextReady, context, key, drainQueue ]
	);

	// An outdated address, unfinished selection, or rejected choice may not
	// enter checkout. The server independently validates the same context.
	useEffect( () => {
		const usable = contextReady && ! pending && ! updateError;
		setExtensionData(
			EXTENSION_NAMESPACE,
			'agent_no',
			usable ? selection.agentNo : ''
		);
		setExtensionData(
			EXTENSION_NAMESPACE,
			'selection_origin',
			usable ? selection.origin : null
		);
		setExtensionData(
			EXTENSION_NAMESPACE,
			'pickup_point_context',
			selectedRateIsAgent ? context : null
		);
	}, [
		contextReady,
		pending,
		updateError,
		selection,
		selectedRateIsAgent,
		key,
		setExtensionData,
	] );

	useEffect( () => {
		let message = '';
		if ( selectedRateIsAgent && ( pending || ! contextReady ) ) {
			message = __(
				'Please wait while pickup points are updated.',
				'smart-send-logistics'
			);
		} else if ( selectedRateIsAgent && updateError ) {
			message = updateError;
		} else if (
			selectedRateIsAgent &&
			pickupPoints.length > 0 &&
			! selection.agentNo
		) {
			message = __(
				'A pickup point must be selected.',
				'smart-send-logistics'
			);
		}
		if ( message ) {
			setValidationErrors( {
				[ VALIDATION_ERROR_ID ]: { message, hidden: ! updateError },
			} );
		} else {
			clearValidationError( VALIDATION_ERROR_ID );
		}
		return () => clearValidationError( VALIDATION_ERROR_ID );
	}, [
		selectedRateIsAgent,
		pending,
		contextReady,
		updateError,
		pickupPoints.length,
		selection.agentNo,
		setValidationErrors,
		clearValidationError,
	] );

	if ( ! selectedRateIsAgent ) {
		return null;
	}
	const options = pickupPoints.map( ( point ) => ( {
		label: point.label,
		value: point.agent_no,
	} ) );
	if ( ! selectDefault || ! selection.agentNo ) {
		options.unshift( {
			label: __( '- Select Pickup Point -', 'smart-send-logistics' ),
			value: '',
		} );
	}
	const hasVisibleError = !! validationError && ! validationError.hidden;
	return (
		<div
			className={ `ss-pickup-point-block ${ className }` }
			data-status={
				contextReady
					? DATA_STATUS[ pickupPointStatus ] || 'loading'
					: 'loading'
			}
			data-selected-agent={ selection.agentNo }
			data-selection-pending={ pending ? 'true' : 'false' }
		>
			{ !! title && (
				<h2 className="ss-pickup-point-block__title">{ title }</h2>
			) }
			{ pickupPoints.length > 0 ? (
				<>
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
						value={ selection.agentNo }
						options={ options }
						disabled={ ! contextReady }
						onChange={ pushSelection }
						__nextHasNoMarginBottom={ true }
					/>
				</>
			) : (
				<p
					className={ `ss-pickup-point-block__message ss-pickup-point-block__message--${ pickupPointStatus }` }
					role={
						ERROR_STATUSES.includes( pickupPointStatus )
							? 'alert'
							: 'status'
					}
				>
					{ pickupPointMessage }
				</p>
			) }
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
