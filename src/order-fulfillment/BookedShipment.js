/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The green result box of a shipment the run just booked (the design
 * Bille approved on 2026-09-16), rendered at the top of the actions
 * section:
 *
 *  - a header with the title ("Shipment" / "Return shipment") and, right
 *    aligned, "Open" linking to the shipment in the Smart Send app
 *    (shipment.app_url - built server side from the same host the API
 *    client talks to, so a sandbox override follows), with the shipment
 *    id in grey under it (no time: this box only ever shows a booking
 *    made seconds ago - the timeline is what carries the times);
 *  - one row per parcel: the tracking number as a link over a grey line
 *    of weight and dimensions, each part only when present (the parcel
 *    reference is the order number repeated on every parcel, which tells
 *    the merchant nothing - it stays on the DTO, out of this box);
 *  - under a green divider, the documents (and codes) as download links.
 *
 * IMPORTANT: these boxes are the memory of the run that just happened and
 * come from its POST response ONLY - they are never rendered from the
 * server state and never persisted. A page reload loses them, which is
 * intended: what survives is the "Booked shipments" timeline, which is
 * rendered from order meta (see Timeline.js).
 */
import { createElement, Fragment } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { ExternalIcon } from './Row';

function documentLabel( document, isReturn ) {
	const format = ( document.format || '' ).toUpperCase();

	if ( document.type === 'label' ) {
		return isReturn
			? /* translators: %s: document format, e.g. PDF. */
			  sprintf( __( 'Download return label (%s)', 'smart-send-logistics' ), format )
			: /* translators: %s: document format, e.g. PDF. */
			  sprintf( __( 'Download shipping label (%s)', 'smart-send-logistics' ), format );
	}

	const type = ( document.type || __( 'Document', 'smart-send-logistics' ) ).replace( /_/g, ' ' );

	return type.charAt( 0 ).toUpperCase() + type.slice( 1 ) + ( format ? ' (' + format + ')' : '' );
}

/**
 * The grey measures line of a parcel: the weight and the dimensions,
 * whichever the parcel carries.
 */
function parcelMeta( parcel ) {
	return [ parcel.weight_display || null, parcel.dimensions_display || null ].filter( Boolean ).join( ' · ' );
}

function TrackingLink( { code, url } ) {
	if ( ! url ) {
		return code;
	}

	return (
		<a href={ url } target="_blank" rel="noopener noreferrer">
			{ code } ↗
		</a>
	);
}

export default function BookedShipment( { isReturn, shipment, steps, warnings = [] } ) {
	const section = isReturn ? 'return_shipment' : 'outbound_shipment';
	const title = isReturn ? __( 'Return shipment', 'smart-send-logistics' ) : __( 'Shipment', 'smart-send-logistics' );
	const parcels = shipment.parcels || [];
	const documents = shipment.documents || [];
	const codes = shipment.codes || [];
	// v1 derives the shipment-level tracking from the first parcel; show it
	// only when it is something the parcel rows do not already carry.
	const showShipmentTracking =
		!! shipment.tracking_code && ! parcels.some( ( parcel ) => parcel.tracking_code === shipment.tracking_code );
	const meta = shipment.shipment_id ? '#' + shipment.shipment_id : '';

	return (
		<div className="smart-send-fulfillment__result" data-ss-section={ section } data-ss-result={ isReturn ? 'return' : 'outbound' }>
			<div className="smart-send-fulfillment__result-head">
				<span className="smart-send-fulfillment__result-title">{ title }</span>
				{ shipment.app_url && (
					<a className="smart-send-fulfillment__external-link" href={ shipment.app_url } target="_blank" rel="noopener noreferrer" data-ss-action="view-shipment">
						{ __( 'Open', 'smart-send-logistics' ) }
						<ExternalIcon />
					</a>
				) }
			</div>
			<div className="smart-send-fulfillment__result-meta">
				<span data-ss-value={ section + '.shipment_id' }>{ meta }</span>
			</div>

			{ ( parcels.length > 0 || showShipmentTracking ) && (
				<div className="smart-send-fulfillment__parcels" data-ss-section="parcels">
					{ parcels.map( ( parcel, index ) => {
						const measures = parcelMeta( parcel );

						return (
							<div className="smart-send-fulfillment__parcel" data-ss-parcel={ String( index + 1 ) } key={ index }>
								<span data-ss-value="tracking_code">
									{ parcel.tracking_code ? (
										<TrackingLink code={ parcel.tracking_code } url={ parcel.tracking_url } />
									) : (
										<span className="smart-send-fulfillment__none">{ __( 'No tracking number', 'smart-send-logistics' ) }</span>
									) }
								</span>
								{ measures && (
									<span className="smart-send-fulfillment__parcel-measures" data-ss-value="parcel_measures">
										{ measures }
									</span>
								) }
							</div>
						);
					} ) }

					{ showShipmentTracking && (
						<div className="smart-send-fulfillment__parcel" data-ss-parcel="shipment">
							<span data-ss-value="tracking_code">
								<TrackingLink code={ shipment.tracking_code } url={ shipment.tracking_url } />
							</span>
						</div>
					) }
				</div>
			) }

			{ ( documents.length > 0 || codes.length > 0 ) && (
				<div className="smart-send-fulfillment__outputs">
					{ documents.length > 0 && (
						<div className="smart-send-fulfillment__documents" data-ss-section="documents">
							{ documents.map( ( document, index ) => (
								<a
									className="smart-send-fulfillment__external-link"
									href={ document.local_url || document.url }
									target="_blank"
									rel="noopener noreferrer"
									data-ss-document={ document.type }
									key={ index }
								>
									{ documentLabel( document, isReturn ) }
									<ExternalIcon />
								</a>
							) ) }
						</div>
					) }

					{ codes.length > 0 && (
						<dl className="smart-send-fulfillment__codes" data-ss-section="codes">
							{ codes.map( ( code, index ) => (
								<Fragment key={ index }>
									<dt>{ code.type }</dt>
									<dd data-ss-code={ code.type }>
										<strong>{ code.value }</strong>
										{ code.image_url && <img src={ code.image_url } alt={ code.value || code.type } className="smart-send-fulfillment__code-image" /> }
										{ code.instructions && <span className="description"> { code.instructions }</span> }
										{ code.expires_at && (
											<span className="description">
												{ ' ' }
												{
													/* translators: %s: expiry date/time. */
													sprintf( __( '(until %s)', 'smart-send-logistics' ), code.expires_at )
												}
											</span>
										) }
									</dd>
								</Fragment>
							) ) }
						</dl>
					) }
				</div>
			) }

			{ steps && steps.order_status && (
				<p className="description" data-ss-value="order_status">
					{
						/* translators: %s: the WooCommerce order status the order was set to. */
						sprintf( __( 'Order status set to %s.', 'smart-send-logistics' ), String( steps.order_status ).replace( /^wc-/, '' ) )
					}
				</p>
			) }

			{ warnings.map( ( warning, index ) => (
				<div className="smart-send-fulfillment__notice" data-ss-notice="warning" key={ index }>
					<Notice status="warning" isDismissible={ false }>
						{ warning }
					</Notice>
				</div>
			) ) }
		</div>
	);
}
