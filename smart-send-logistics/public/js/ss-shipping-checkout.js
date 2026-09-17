/* global jQuery */
( function ( $ ) {
	'use strict';

	// Only a shopper's change is explicit; a server-rendered first option
	// stays automatic and may follow a refreshed address search.
	$( document.body ).on( 'change', 'select[name="ss_shipping_store_pickup"]', function () {
		var select = $( this );
		var form = select.closest( 'form.checkout' );
		form.find( '[name="ss_shipping_pickup_origin"]' ).val( 'explicit' );
		form.find( '[name="ss_shipping_pickup_carrier"]' ).val( select.attr( 'data-ss-carrier' ) );
		form.find( '[name="ss_shipping_pickup_country"]' ).val( select.attr( 'data-ss-country' ) );
		$( document.body ).trigger( 'update_checkout' );
	} );
}( jQuery ) );
