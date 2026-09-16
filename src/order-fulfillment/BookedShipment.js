/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * A booked shipment block (section 1.2-E/F, rebuilt in the #182 review of
 * 2026-09-16). Once anything is booked the form closes, and what a booked
 * direction shows is:
 *
 *  - a green "Shipment booked" / "Return shipment booked" callout with the
 *    shipment id and an external link to the shipment in the Smart Send app
 *    (shipment.app_url - built server side from the same host the API
 *    client talks to, so a sandbox override follows);
 *  - one row per parcel: its tracking number as a link (plain text without
 *    a url) and, in grey, the weight, dimensions and reference it was
 *    booked with (formatted server side in the store's units);
 *  - the documents and codes of the shipment.
 *
 * After a page reload only the shipment id is persisted (Decisions, #182),
 * so the block falls back to the callout, the app link and a pointer to
 * the order notes (`legacy`). Persisting
 * SS_Shipping_Booked_Shipment::to_array() later would light the full view
 * up on reload without a change here: the shape is the same.
 */
import { createElement, Fragment } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

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
 * The grey measures line of a parcel: weight, dimensions and reference,
 * whichever the parcel carries.
 */
function parcelMeta( parcel ) {
	return [
		parcel.weight_display || null,
		parcel.dimensions_display || null,
		parcel.reference
			? /* translators: %s: the parcel reference. */
			  sprintf( __( 'Ref. %s', 'smart-send-logistics' ), parcel.reference )
			: null,
	]
		.filter( Boolean )
		.join( ' · ' );
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

export default function BookedShipment( { isReturn, shipment, steps, warnings = [], legacy = false } ) {
	const section = isReturn ? 'return_shipment' : 'outbound_shipment';
	const title = isReturn ? __( 'Return shipment booked', 'smart-send-logistics' ) : __( 'Shipment booked', 'smart-send-logistics' );
	const parcels = shipment.parcels || [];
	// v1 derives the shipment-level tracking from the first parcel; show it
	// only when it is something the parcel rows do not already carry.
	const showShipmentTracking =
		!! shipment.tracking_code && ! parcels.some( ( parcel ) => parcel.tracking_code === shipment.tracking_code );

	return (
		<div className="smart-send-fulfillment__booked" data-ss-section={ section }>
			<div className="smart-send-fulfillment__notice" data-ss-notice={ isReturn ? 'booked_return' : 'booked' }>
				<Notice status="success" isDismissible={ false }>
					<p>
						{ title }
						{ ' · #' }
						<span data-ss-value={ section + '.shipment_id' }>{ shipment.shipment_id }</span>
					</p>
					{ shipment.app_url && (
						<p>
							<a href={ shipment.app_url } target="_blank" rel="noopener noreferrer" data-ss-action="view-shipment">
								{ __( 'View shipment', 'smart-send-logistics' ) } ↗
							</a>
						</p>
					) }
				</Notice>
			</div>

			{ legacy ? (
				<p className="description">{ __( 'Documents and tracking are in the order notes.', 'smart-send-logistics' ) }</p>
			) : (
				<Fragment>
					{ ( parcels.length > 0 || showShipmentTracking ) && (
						<div className="smart-send-fulfillment__parcels" data-ss-section="parcels">
							{ parcels.map( ( parcel, index ) => {
								const meta = parcelMeta( parcel );

								return (
									<div className="smart-send-fulfillment__parcel" data-ss-parcel={ parcel.parcel_id || String( index + 1 ) } key={ index }>
										<div className="smart-send-fulfillment__parcel-head">
											<span className="smart-send-fulfillment__parcel-name">
												{
													/* translators: %d: parcel number. */
													sprintf( __( 'Parcel %d', 'smart-send-logistics' ), index + 1 )
												}
											</span>
											<span data-ss-value="tracking_code">
												{ parcel.tracking_code ? (
													<TrackingLink code={ parcel.tracking_code } url={ parcel.tracking_url } />
												) : (
													<span className="smart-send-fulfillment__none">{ __( 'No tracking number', 'smart-send-logistics' ) }</span>
												) }
											</span>
										</div>
										{ meta && (
											<div className="description" data-ss-value="parcel_measures">
												{ meta }
											</div>
										) }
									</div>
								);
							} ) }

							{ showShipmentTracking && (
								<div className="smart-send-fulfillment__parcel" data-ss-parcel="shipment">
									<div className="smart-send-fulfillment__parcel-head">
										<span className="smart-send-fulfillment__parcel-name">{ __( 'Shipment', 'smart-send-logistics' ) }</span>
										<span data-ss-value="tracking_code">
											<TrackingLink code={ shipment.tracking_code } url={ shipment.tracking_url } />
										</span>
									</div>
								</div>
							) }
						</div>
					) }

					{ ( ( shipment.documents && shipment.documents.length > 0 ) || ( shipment.codes && shipment.codes.length > 0 ) ) && (
						<dl className="smart-send-fulfillment__outputs">
							{ shipment.documents && shipment.documents.length > 0 && (
								<div data-ss-section="documents">
									<dt>{ __( 'Documents', 'smart-send-logistics' ) }</dt>
									{ shipment.documents.map( ( document, index ) => (
										<dd key={ index }>
											<a className="button button-small" href={ document.local_url || document.url } target="_blank" rel="noopener noreferrer" data-ss-document={ document.type }>
												{ documentLabel( document, isReturn ) }
											</a>
										</dd>
									) ) }
								</div>
							) }

							{ shipment.codes && shipment.codes.length > 0 && (
								<div data-ss-section="codes">
									<dt>{ __( 'Codes', 'smart-send-logistics' ) }</dt>
									{ shipment.codes.map( ( code, index ) => (
										<dd key={ index } data-ss-code={ code.type }>
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
									) ) }
								</div>
							) }
						</dl>
					) }
				</Fragment>
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
