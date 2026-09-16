/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The "Booked shipments" section: a light timeline of every label this
 * order has, newest first - a thin vertical rail with a small dot per
 * entry, each entry a link into the Smart Send app with the title on the
 * first line and the time it was booked under it.
 *
 * It renders state.timeline, which comes from the order meta the
 * fulfillment run appends to (SS_Shipping_Shipment_Ids), so it survives a
 * reload - unlike the green result boxes, which are the memory of the run
 * just made. An order booked before that list existed carries only the
 * frozen shipment ids and no timestamp: such an entry is a link with no
 * time line under it.
 *
 * The server first paint renders the same structure and class names
 * (SS_Shipping_Order_Fulfillment_Presenter::render_timeline()).
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ExternalIcon } from './Row';

export default function Timeline( { entries = [] } ) {
	if ( entries.length === 0 ) {
		return null;
	}

	return (
		<div>
			<div className="smart-send-fulfillment__timeline-label">{ __( 'Booked shipments', 'smart-send-logistics' ) }</div>
			<div className="smart-send-fulfillment__timeline">
				{ entries.map( ( entry, index ) => (
					<a
						className="smart-send-fulfillment__timeline-row"
						href={ entry.app_url }
						target="_blank"
						rel="noopener noreferrer"
						data-ss-timeline={ entry.direction }
						data-ss-shipment-id={ entry.shipment_id }
						key={ entry.shipment_id + ':' + index }
					>
						<span className="smart-send-fulfillment__timeline-title">
							{ entry.direction === 'return' ? __( 'Return shipment', 'smart-send-logistics' ) : __( 'Shipment', 'smart-send-logistics' ) }
							<ExternalIcon />
						</span>
						{ entry.booked_at_display && <span className="smart-send-fulfillment__timeline-when">{ entry.booked_at_display }</span> }
					</a>
				) ) }
			</div>
		</div>
	);
}
