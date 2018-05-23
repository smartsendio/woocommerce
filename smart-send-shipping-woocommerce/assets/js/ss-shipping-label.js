jQuery(function ($) {

    var ss_shipping_label_items = {
        // init Class
        init: function () {
            $('#ss-shipping-label-form')
                .on('click', '#ss-shipping-label-button', {return_label: 0}, this.save_ss_shipping_label);
            $('#ss-shipping-label-form')
                .on('click', '#ss-shipping-return-label-button', {return_label: 1}, this.save_ss_shipping_label);
        },

        save_ss_shipping_label: function (event) {
            // Remove any errors from last attempt to create label
            $('#ss-shipping-label-form .error').remove();
            $('#ss-shipping-label-form .updated').remove();

            $('#ss-shipping-label-form').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });

            var data = {
                action: 'ss_shipping_generate_label',
                order_id: woocommerce_admin_meta_boxes.post_id,
                return_label: event.data.return_label,
                ss_shipping_agent_no: $('#ss_shipping_agent_no').val(),
                ss_shipping_label_nonce: $('#ss_shipping_label_nonce').val()
            };

            $.post(woocommerce_admin_meta_boxes.ajax_url, data, function (response) {
                $('#ss-shipping-label-form').unblock();

                if (response.error) {
                    // Print error message
                    $('#ss-shipping-label-form').append('<div id="ss-shipping-error" class="error ss-meta-message"><strong>' + response.error.message + '</strong></div>');
                    // Print 'Read more here' link to error explanation
                    if (response.error.links.about) {
                        $('#ss-shipping-error').append('<p id="ss-shipping-error-link" class="error ss-meta-message"><a href="' + response.error.links.about + '" target="_blank">Read more</a></p>'); //TODO: Add translation
                    }
                    // Print unique error ID if one exists
                    if (response.id) {
                        $('#ss-shipping-error').append('<p id="ss-shipping-error-id" class="error ss-meta-message">Unique error id: ' + response.error.id + '</p>'); //TODO: Add translation
                    }
                    // Print each error
                    if (response.error.errors) {
                        $('#ss-shipping-error').append('<ul id="ss-shipping-error-list" class="error ss-meta-message"></ul>');
                        $.each(response.error.errors, function (index, value) { //each() iterates both object and array
                            if($.isArray(value)) { // If there are more errors for each field, then show each of them
                                $.each(value, function(index2,value2) {
                                    $('#ss-shipping-error-list').append('<li class="' + index2 + ' error ss-meta-message">' + value2 + '</li>');
                                    }
                                );
                            } else { // otherwise just show the single error
                                $('#ss-shipping-error-list').append('<li class="' + index + ' error ss-meta-message">' + value + '</li>');
                            }
                        });
                    }

                } else if (response.success) {
                    //$('.ss_agent_address').html(response.success.agent_address);
                    $('#ss-shipping-label-form').append('<div id="ss-label-created" class="updated ss-meta-message"><a href="' + response.success.label_link + '" target="_blank">Download label</a></div>'); //TODO: Add translation
                    if (response.success.order_note) {

                        $('#woocommerce-order-notes').block({
                            message: null,
                            overlayCSS: {
                                background: '#fff',
                                opacity: 0.6
                            }
                        });

                        var data = {
                            action: 'woocommerce_add_order_note',
                            post_id: woocommerce_admin_meta_boxes.post_id,
                            note_type: '',
                            note: response.success.order_note,
                            security: woocommerce_admin_meta_boxes.add_order_note_nonce
                        };

                        $.post(woocommerce_admin_meta_boxes.ajax_url, data, function (response_note) {
                            // alert(response_note);
                            $('ul.order_notes').prepend(response_note);
                            $('#woocommerce-order-notes').unblock();
                            $('#add_order_note').val('');
                        });
                    }

                } else {
                    // Print error message
                    $('#ss-shipping-label-form').append('<div id="ss-shipping-error" class="error ss-meta-message"><strong>Unexpected error</strong></div>'); //TODO: Add translation
                }
            });

            return false;
        },
    }

    ss_shipping_label_items.init();

});
