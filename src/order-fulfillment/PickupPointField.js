/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The pickup point row (agent methods only): the stored - or freshly
 * resolved - point as "#<agent no> <company>" over its address, an
 * "Edit" link opening the agent number input + "Look up" (resolving it
 * through GET …/pickup-points/{agent_no}, so the merchant sees the point
 * before booking; the resolved point is rendered below the input). The
 * point is written to the order only once a booking succeeds
 * (persist-after-success, #182).
 */
import { createElement, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { FieldError } from './ErrorNotice';
import DetailRow, { NoneValue, PinIcon } from './Row';

/**
 * A pickup point as the box displays it: pin, "#agent_no company", the
 * street and "postal code city" in grey below.
 */
export function PickupPointValue( { pickupPoint } ) {
	const address = [ pickupPoint.address_line1, [ pickupPoint.postal_code, pickupPoint.city ].filter( Boolean ).join( ' ' ) ].filter( Boolean );

	return (
		<div className="smart-send-fulfillment__pickup-point" data-ss-value="pickup_point">
			<PinIcon />
			<div className="smart-send-fulfillment__pickup-point-text">
				<div>
					<span data-ss-value="pickup_point.agent_no">{ '#' + pickupPoint.agent_no }</span>
					{ pickupPoint.company ? ' ' + pickupPoint.company : '' }
				</div>
				{ address.length > 0 && (
					<div className="smart-send-fulfillment__address">
						{ address.map( ( line, index ) => (
							<span key={ index }>{ line }</span>
						) ) }
					</div>
				) }
			</div>
		</div>
	);
}

export default function PickupPointField( { pickupPoint, editing, editable = true, onEdit, onLookup, errors, disabled } ) {
	const [ agentNo, setAgentNo ] = useState( pickupPoint ? String( pickupPoint.agent_no || '' ) : '' );
	const [ lookingUp, setLookingUp ] = useState( false );

	const lookup = async () => {
		setLookingUp( true );
		try {
			await onLookup( agentNo.trim() );
		} finally {
			setLookingUp( false );
		}
	};

	return (
		<DetailRow section="pickup_point" label={ __( 'Pickup point', 'smart-send-logistics' ) } action="edit-pickup-point" editable={ editable } editing={ editing } onEdit={ onEdit }>
			{ editing && (
				<div className="smart-send-fulfillment__inline-actions smart-send-fulfillment__lookup">
					<input
						type="text"
						id="smart-send-pickup-point-agent-no"
						data-ss-field="pickup_point.agent_no"
						aria-label={ __( 'Agent number', 'smart-send-logistics' ) }
						placeholder={ __( 'Agent number', 'smart-send-logistics' ) }
						value={ agentNo }
						onChange={ ( event ) => setAgentNo( event.target.value ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
								lookup();
							}
						} }
						autoComplete="off"
						disabled={ disabled || lookingUp }
					/>
					<Button variant="secondary" data-ss-action="lookup-pickup-point" onClick={ lookup } disabled={ disabled || lookingUp || agentNo.trim() === '' }>
						{ __( 'Look up', 'smart-send-logistics' ) }
					</Button>
					{ lookingUp && <Spinner /> }
				</div>
			) }
			<FieldError field="pickup_point.agent_no" errors={ errors } />
			{ pickupPoint ? (
				<PickupPointValue pickupPoint={ pickupPoint } />
			) : (
				<div className="smart-send-fulfillment__value">
					<NoneValue field="pickup_point" />
				</div>
			) }
		</DetailRow>
	);
}
