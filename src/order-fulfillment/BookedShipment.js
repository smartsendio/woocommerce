/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * A booked shipment block (section 1.2-E/F). Two variants:
 *
 *  - right after booking, from the POST response's fulfilled entry: every
 *    document as a download link, every code (value, image, instructions,
 *    expiry), tracking per parcel and the shipment-level tracking, the
 *    number of parcels, plus any step warnings (e.g. the uploads copy
 *    could not be saved - the label is booked regardless);
 *  - after a page reload, from the state's { shipment_id, legacy: true }:
 *    the id and a pointer to the order notes, since nothing but the id is
 *    persisted (Decisions, #182).
 */
import { createElement } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

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

export default function BookedShipment( { isReturn, shipment, steps, warnings = [], legacy = false, children } ) {
	const section = isReturn ? 'return_shipment' : 'outbound_shipment';
	const title = isReturn ? __( 'Return label', 'smart-send-logistics' ) : __( 'Shipping label', 'smart-send-logistics' );

	return (
		<div className="smart-send-fulfillment__booked" data-ss-section={ section }>
			<p>
				<strong>{ title }</strong>{ ' ' }
				<span data-ss-value={ section + '.status' }>
					{ __( 'Booked', 'smart-send-logistics' ) + ' · #' }
					<span data-ss-value={ section + '.shipment_id' }>{ shipment.shipment_id }</span>
				</span>
			</p>

			{ legacy ? (
				<p className="description">{ __( 'Documents and tracking are in the order notes.', 'smart-send-logistics' ) }</p>
			) : (
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

					{ ( ( shipment.parcels && shipment.parcels.some( ( parcel ) => parcel.tracking_code ) ) || shipment.tracking_code ) && (
						<div data-ss-section="tracking">
							<dt>{ __( 'Tracking', 'smart-send-logistics' ) }</dt>
							{ ( shipment.parcels || [] ).map( ( parcel, index ) =>
								parcel.tracking_code ? (
									<dd key={ index } data-ss-parcel={ parcel.parcel_id }>
										{
											/* translators: %d: parcel number. */
											sprintf( __( 'Parcel %d', 'smart-send-logistics' ), index + 1 )
										}{ ' ' }
										{ parcel.tracking_url ? (
											<a href={ parcel.tracking_url } target="_blank" rel="noopener noreferrer">
												{ parcel.tracking_code } ↗
											</a>
										) : (
											parcel.tracking_code
										) }
									</dd>
								) : null
							) }
							{ shipment.tracking_code && ( ! shipment.parcels || shipment.parcels.length !== 1 || shipment.parcels[ 0 ].tracking_code !== shipment.tracking_code ) && (
								<dd data-ss-value="tracking_code">
									{ __( 'Shipment', 'smart-send-logistics' ) }{ ' ' }
									{ shipment.tracking_url ? (
										<a href={ shipment.tracking_url } target="_blank" rel="noopener noreferrer">
											{ shipment.tracking_code } ↗
										</a>
									) : (
										shipment.tracking_code
									) }
								</dd>
							) }
						</div>
					) }

					{ shipment.parcels && shipment.parcels.length > 0 && (
						<div>
							<dd className="description" data-ss-value="parcel_count">
								{ sprintf(
									/* translators: %d: number of parcels. */
									_n( 'Shipped as %d parcel', 'Shipped as %d parcels', shipment.parcels.length, 'smart-send-logistics' ),
									shipment.parcels.length
								) }
							</dd>
						</div>
					) }
				</dl>
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

			{ children }
		</div>
	);
}
