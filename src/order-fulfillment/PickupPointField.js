/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The pickup point row (agent methods only): the stored - or freshly
 * resolved - point with its address block, a "Change" action opening the
 * agent number input, and "Look up" resolving it through
 * GET …/pickup-points/{agent_no} so the merchant sees the point before
 * booking. The point is written to the order only once a booking succeeds
 * (persist-after-success, #182).
 */
import { createElement, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { FieldError } from './ErrorNotice';

export default function PickupPointField( { pickupPoint, editing, onEdit, onCancel, onLookup, errors, disabled } ) {
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
		<div className="smart-send-fulfillment__row" data-ss-section="pickup_point">
			<strong>{ __( 'Pickup point', 'smart-send-logistics' ) }</strong>
			{ pickupPoint ? (
				<div className="smart-send-fulfillment__value" data-ss-value="pickup_point">
					<span data-ss-value="pickup_point.agent_no">
						{
							/* translators: %s: pickup point agent number. */
							sprintf( __( 'Agent No.: %s', 'smart-send-logistics' ), pickupPoint.agent_no )
						}
					</span>
					{ pickupPoint.display_html && (
						// display_html is built server-side through wp_kses_post().
						<div className="smart-send-fulfillment__address" dangerouslySetInnerHTML={ { __html: pickupPoint.display_html } } />
					) }
				</div>
			) : (
				<p className="description" data-ss-value="pickup_point">
					{ __( 'No pickup point selected.', 'smart-send-logistics' ) }
				</p>
			) }

			{ editing ? (
				<div className="smart-send-fulfillment__inline-form">
					<label htmlFor="smart-send-pickup-point-agent-no">{ __( 'Agent number', 'smart-send-logistics' ) }</label>
					<div className="smart-send-fulfillment__inline-actions">
						<input
							type="text"
							id="smart-send-pickup-point-agent-no"
							data-ss-field="pickup_point.agent_no"
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
						<Button variant="secondary" size="small" data-ss-action="lookup-pickup-point" onClick={ lookup } disabled={ disabled || lookingUp || agentNo.trim() === '' }>
							{ __( 'Look up', 'smart-send-logistics' ) }
						</Button>
						<Button variant="tertiary" size="small" data-ss-action="cancel-pickup-point" onClick={ onCancel } disabled={ disabled || lookingUp }>
							{ __( 'Cancel', 'smart-send-logistics' ) }
						</Button>
						{ lookingUp && <Spinner /> }
					</div>
					<FieldError field="pickup_point.agent_no" errors={ errors } />
				</div>
			) : (
				<div>
					<Button variant="link" size="small" data-ss-action="change-pickup-point" onClick={ onEdit } disabled={ disabled }>
						{ __( 'Change', 'smart-send-logistics' ) }
					</Button>
					<FieldError field="pickup_point.agent_no" errors={ errors } />
				</div>
			) }
		</div>
	);
}
