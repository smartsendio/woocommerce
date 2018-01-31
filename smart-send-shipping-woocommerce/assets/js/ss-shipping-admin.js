jQuery(function($) {
	function showDisplayTaxExempt(e) {
	    if( $('#woocommerce_smart_send_shipping_advanced_settings_enable').is(':checked')) {
	    	console.log('is checked');
	        $('#woocommerce_smart_send_shipping_advanced_settings_enable').closest('tr').nextAll().show();
	        // $('.display-smart-send').show();

	    } else {
	    	console.log('is NOT checked');
	        $('#woocommerce_smart_send_shipping_advanced_settings_enable').closest('tr').nextAll().hide();
	        // $('.display-smart-send').hide();
	    }
	}

	$( document ).ready(function() {
		// Handler for .ready() called.
		showDisplayTaxExempt();
		$('#woocommerce_smart_send_shipping_advanced_settings_enable').on("click", showDisplayTaxExempt);
	});
});
