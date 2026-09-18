(function($) {
	// Attach before DOM ready so feedback cannot outlive an early edit or save.
	$(document).on('input change', '#woocommerce_smart_send_shipping_api_token', function() {
		$('#ss-connection-result').remove();
		$(this).removeAttr('aria-describedby');
	});

	$(document).on('submit', '#mainform', function() {
		$('#ss-connection-result').remove();
	});

	$(function() {
		// Saving also rechecks team access when the settings are unchanged.
		if ($('#woocommerce_smart_send_shipping_api_token').length) {
			$('.woocommerce-save-button').prop('disabled', false);
		}
	});
})(jQuery);
