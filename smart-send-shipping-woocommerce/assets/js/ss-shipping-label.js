jQuery(function ($) {

    var ss_shipping_label_items = {
        // init Class
        init: function () {
            $('#ss-shipping-label-form')
                .on('click', '#ss-shipping-label-button', this.save_ss_shipping_label);
        },

        save_ss_shipping_label: function () {
            // console.log(ss_shipping_label_data);
            // Remove any errors from last attempt to create label
            $('#ss-shipping-label-form .ss-shipping-error').remove();
            $('#ss-shipping-label-form .ss_label_created').remove();

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
                ss_shipping_agent_no: $('#ss_shipping_agent_no').val(),
                ss_shipping_label_nonce: $('#ss_shipping_label_nonce').val()
            };

            $.post(woocommerce_admin_meta_boxes.ajax_url, data, function (response) {
                $('#ss-shipping-label-form').unblock();
                if (response.error) {
                    $('#ss-shipping-label-form').append('<div id="ss-shipping-error" class="ss-shipping-error">' + response.error.message + '</div>');
                    if (response.error.errors) {
                        $('#ss-shipping-error').append("<ul id='ss-shipping-error-list'></ul>");
                        $.each(response.error.errors, function (index, value) {
                            $('#ss-shipping-error-list').append('<li class="' + index + '">' + value + '</li>');
                        });
                    }
                    if (response.id) {
                        $('#ss-shipping-error').append('<p id="ss-shipping-error-id">Unique error id: ' + response.error.id + '</p>');
                    }
                } else {
                    // console.log(response);
                    $('.ss_agent_address').html(response.agent_address);
                    $('#ss-shipping-label-form').append('<div class="ss_label_created">' + response.label_link + '</div>');

                    if (response.tracking_note) {

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
                            note: response.tracking_note,
                            security: woocommerce_admin_meta_boxes.add_order_note_nonce
                        };

                        $.post(woocommerce_admin_meta_boxes.ajax_url, data, function (response_note) {
                            // alert(response_note);
                            $('ul.order_notes').prepend(response_note);
                            $('#woocommerce-order-notes').unblock();
                            $('#add_order_note').val('');
                        });
                    }

                }
            });

            return false;
        },
    }

    ss_shipping_label_items.init();

});
