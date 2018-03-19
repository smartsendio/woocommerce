/**
/**
 * Ajax call to sync orders!
 */
function ssTestConnection( btn_id ) {
	var btn = jQuery( btn_id );

	// Remove elements after button
	btn.nextAll().remove();

	btn.attr("disabled", true);
	btn.text('Testing Connection...');

	var loaderContainer = jQuery( '<span/>', {
        'class': 'loader-image-container'
    }).insertAfter( btn );

    var loader = jQuery( '<img/>', {
        src: '/wp-admin/images/loading.gif',
        'class': 'loader-image'
    }).appendTo( loaderContainer );

	var data = {
		'action': 'test_ss_connection',
		'test_con_nonce': ss_test_con_obj.test_con_nonce
	};

	console.log(data);
	console.log(as_test_con_obj);

	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	jQuery.post(ss_test_con_obj.ajax_url, data, function(response) {
		btn.attr("disabled", false);
		btn.text( response.button_txt );
		loaderContainer.remove();

		if ( response.connection_success ) {
			var test_connection_class = 'ss_connection_succeeded';
			var test_connection_text = response.connection_success;
		} else {
			var test_connection_class = 'ss_connection_error';
			var test_connection_text = response.connection_error;
		}
		// alert(test_connection_text);
		loaderContainer = jQuery( '<span/>', {
	        'class': test_connection_class
	    }).insertAfter( btn );

		loaderContainer.append( test_connection_text );
	    // jQuery( ).appendTo( loaderContainer );
		// location.reload();
	});
}
