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
                     $('#ss-shipping-label-form').append('<div id="ss-shipping-error" class="error ss-meta-message">' + response.error + '</div>'); 

                } else if (response.success) {
                    //$('.ss_agent_address').html(response.success.agent_address);
                    $('#ss-shipping-label-form').append('<div id="ss-label-created" class="updated ss-meta-message"><a href="' + response.success.label_link + '" target="_blank">' + ss_label_data.download_label + '</a></div>');
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
                    $('#ss-shipping-label-form').append('<div id="ss-shipping-error" class="error ss-meta-message"><strong>' + ss_label_data.unexpected_error + '</strong></div>');
                }
            });

            return false;
        },
    }

    ss_shipping_label_items.init();

});
