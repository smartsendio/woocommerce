<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Order Queue.
 *
 * @package  SS_Shipping_WC_Order_Bulk
 * @category Shipping
 * @author   Smart Send
 */

if (!class_exists('SS_Shipping_WC_Order_Bulk')) :


    class SS_Shipping_WC_Order_Bulk
    {
        protected $ss_order = null;

        /**
         * Init and hook in the integration.
         * 
         * @param object SS_Shipping_WC_Order
         */
        public function __construct( $ss_order )
        {
            $this->ss_order = $ss_order;

            $this->define_constants();
            $this->init_hooks();
        }

        /**
         * Define constants
         */
        protected function define_constants()
        {
            SS_SHIPPING_WC()->define('SS_QUEUE_CALLBACK_URL', SS_SHIPPING_WC()->get_website_url() . '/wc-api/smart-send-queue-response');
        }

        /**
         * Init hooks
         */
        public function init_hooks()
        {
            // add bulk actions to the Orders screen table bulk action drop-downs
            add_action('admin_footer-edit.php', array($this, 'add_order_bulk_actions'));

            // process orders bulk actions
            add_action('load-edit.php', array($this, 'process_orders_bulk_actions'));

            // display admin notices for bulk actions
            add_action('admin_notices', array($this, 'render_messages'));

            // New Smart Send queue status
            add_filter('wc_order_statuses', array($this, 'add_smart_send_status'), 10, 1 );

            // Call WC from Smart Send server with queue results 
            // URL: "example.com/wc-api/smart-send-queue-response"
            add_action( 'woocommerce_api_smart-send-queue-response' , array( $this, 'queue_response' ) );
        }

        /**
         * Add Smart Send bulk actions
         */
        public function add_order_bulk_actions()
        {
            global $post_type, $post_status;

            if ($post_type === 'shop_order' && $post_status !== 'trash') :

                ?>
                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        $('select[name^=action]').append(
                            <?php $index = count($actions = $this->get_bulk_actions()); ?>
                            <?php foreach ( $actions as $action => $name ) : ?>
                            $('<option>').val('<?php echo esc_js($action); ?>').text('<?php echo esc_js($name); ?>')
                            <?php --$index; ?>
                            <?php if ($index) {
                                echo ',';
                            } ?>
                            <?php endforeach; ?>
                        );
                    });
                </script>
                <?php

            endif;
        }

        /**
         * Return Smart Send bulk actions
         */
        public function get_bulk_actions()
        {

            $shop_manager_actions = array();

            $shop_manager_actions = array(
                'ss_shipping_label_bulk'  => (SS_SHIPPING_WC()->get_demo_mode_setting() ? __('DEMO MODE',
                            'smart-send-logistics') . ': ' : '') . __('Smart Send - Generate Labels',
                        'smart-send-logistics'),
                'ss_shipping_return_bulk' => (SS_SHIPPING_WC()->get_demo_mode_setting() ? __('DEMO MODE',
                            'smart-send-logistics') . ': ' : '') . __('Smart Send - Generate Return Labels',
                        'smart-send-logistics'),
            );

            return $shop_manager_actions;
        }

        /**
         * Process bulk actions
         */
        public function process_orders_bulk_actions()
        {
            global $typenow;
            $array_messages = array('msg_user_id' => get_current_user_id());

            if ('shop_order' === $typenow) {

                // Get the bulk action
                $wp_list_table = _get_list_table('WP_Posts_List_Table');
                $action = $wp_list_table->current_action();
                $order_ids = array();

                if (!$action || !array_key_exists($action, $this->get_bulk_actions())) {
                    return;
                }

                // Make sure order IDs are submitted
                if (isset($_REQUEST['post'])) {
                    $order_ids = array_map('absint', $_REQUEST['post']);
                }

                $redirect_url = admin_url('edit.php?post_type=shop_order');

                if ('ss_shipping_label_bulk' === $action || 'ss_shipping_return_bulk' === $action) {

                    // Determine if the request is for a return label
                    $return = ('ss_shipping_return_bulk' === $action);

                    // Trigger an admin notice to have the user manually open a print window
                    $is_error = false;
                    $orders_count = count($order_ids);

                    if ($orders_count < 1) {
                        array_push($array_messages, array(
                            'message' => __('No orders selected, please select the orders to create labels for.',
                                'smart-send-logistics'),
                            'type'    => 'error',
                        ));
                    } elseif ($orders_count > 5) {
                        // IF 403 RETURNED THEN USER DOESNT HAVE PRO ACCOUNT
                        $array_combo_messages = $this->smart_send_bulk_queue( $order_ids, $return );

                        $array_messages = array_merge($array_messages, $array_combo_messages);
                        /*
                        array_push($array_messages, array(
                            'message' => __('It is not possible to create labels for more than 5 orders at the moment. This feature is coming soon.',
                                'smart-send-logistics'),
                            'type'    => 'error',
                        ));*/
                    } else {

                        $array_combo_messages = $this->smart_send_bulk_iteration( $order_ids, $return );

                        $array_messages = array_merge($array_messages, $array_combo_messages);

                    }

                    /* @see render_messages() */
                    update_option('_ss_shipping_bulk_action_confirmation', $array_messages);

                }
            }
        }

        protected function smart_send_bulk_queue( $order_ids, $return ) {
            $array_messages_success = array();
            $array_messages_error = array();
            $array_shipments = array();

            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);

                // Ensure the selected orders have a Smart Send Shipping method
                $ss_shipping_method_id = $this->ss_order->get_smart_send_method_id($order_id);

                if (!empty($ss_shipping_method_id)) {

                    $shipment_arr = $this->ss_order->create_shipment_for_single_order_maybe_return($order_id, $return, true);

                    array_push($array_messages_success, array(
                        'message' => sprintf(__('Order #%s', 'smart-send-logistics'),
                                $order->get_order_number()) . ': ' . __('The selected order was queued on the Send Smart server.',
                                'smart-send-logistics'),
                        'type'    => 'success',
                    ));

                    $array_shipments = array_merge($array_shipments, $shipment_arr);

                } else {
                    array_push($array_messages_error, array(
                        'message' => sprintf(__('Order #%s', 'smart-send-logistics'),
                                $order->get_order_number()) . ': ' . __('The selected order did not include a Send Smart shipping method',
                                'smart-send-logistics'),
                        'type'    => 'error',
                    ));
                }
            }

            // error_log(print_r($array_shipments,true));
            error_log(SS_QUEUE_CALLBACK_URL);
            if (!empty($array_shipments)) {
                $response = SS_SHIPPING_WC()->get_api_handle()->createShipmentAndLabelsAsync($array_shipments, SS_QUEUE_CALLBACK_URL );

                $data = SS_SHIPPING_WC()->get_api_handle()->getData();
                error_log(print_r($data,true));
            }

            return array_merge($array_messages_success, $array_messages_error);
        }

        protected function smart_send_bulk_iteration( $order_ids, $return ) {
            $array_messages_success = array();
            $array_messages_error = array();
            $array_shipment_ids = array();

            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);

                // Ensure the selected orders have a Smart Send Shipping method
                $ss_shipping_method_id = $this->ss_order->get_smart_send_method_id($order_id);

                if (!empty($ss_shipping_method_id)) {

                    $response = $this->ss_order->create_label_for_single_order_maybe_return($order_id, $return, true);

                    foreach ($response as $key => $value) {

                        if (isset($value['success'])) {
                            array_push($array_messages_success, array(
                                'message' => sprintf(__('Order #%s', 'smart-send-logistics'),
                                        $order->get_order_number()) . ': '
                                    . (empty($value['success']->woocommerce['return']) ?
                                        __('Shipping label created by Smart Send',
                                            'smart-send-logistics') : __('Return label created by Smart Send',
                                            'smart-send-logistics'))
                                    . ': ' . $this->ss_order->get_ss_shipping_label_link($value['success']->woocommerce['label_url'],
                                        !empty($value['success']->woocommerce['return'])),
                                'type'    => 'success',
                            ));

                            array_push($array_shipment_ids, array(
                                'shipment_id' => $value['success']->shipment_id,
                                'order_id'    => $order->get_order_number(),
                            ));

                        } else {
                            // Print error message
                            $message = sprintf(__('Order #%s', 'smart-send-logistics'),
                                    $order->get_order_number()) . ': ' . $value['error'];

                            array_push($array_messages_error, array(
                                'message' => $message,
                                'type'    => 'error',
                            ));
                        }
                    }

                } else {
                    array_push($array_messages_error, array(
                        'message' => sprintf(__('Order #%s', 'smart-send-logistics'),
                                $order->get_order_number()) . ': ' . __('The selected order did not include a Send Smart shipping method',
                                'smart-send-logistics'),
                        'type'    => 'error',
                    ));
                }
            }

            return $this->create_combo_file($array_messages_success, $array_messages_error,
                $array_shipment_ids);
        }
        /**
         * Create Combo File
         */
        protected function create_combo_file($array_messages_success, $array_messages_error, $array_shipment_ids)
        {

            $array_messages = array();
            $combo_name = $this->get_combo_label_file_name($array_shipment_ids);
            $combo_path = $this->ss_order->get_label_path_from_shipment_id($combo_name);
            $combo_url = '';
            // $combine_shipments_payload = array_map(function($element) { return array('shipment_id' => $element); }, $array_shipment_ids);

            if (file_exists($combo_path)) {
                $combo_url = $this->ss_order->get_label_url_from_shipment_id($combo_name);
            } else {

                // If more than one smart send shipment label created, then create combo labels
                if (count($array_shipment_ids) > 1) {
                    // Create combined label with successful shipments
                    $combined_shipments = SS_SHIPPING_WC()->get_api_handle()->combineLabelsForShipments(wp_list_pluck($array_shipment_ids,
                        'shipment_id'));

                    // Write API request to log
                    SS_SHIPPING_WC()->log_msg('Called "combineLabelsForShipments" with arguments: ' . SS_SHIPPING_WC()->get_api_handle()->getRequestBody());

                    if (SS_SHIPPING_WC()->get_api_handle()->isSuccessful()) {

                        $response = SS_SHIPPING_WC()->get_api_handle()->getData();
                        if (SS_SHIPPING_WC()->get_setting_save_shipping_labels_in_uploads()) {
                            try {
                                // Save the PDF file and save order meta data
                                $combo_url = $this->save_label_file($combo_name, $response->pdf->base_64_encoded, null);
                            } catch (Exception $e) {
                                array_push($array_messages, array(
                                    'message' => $e->getMessage(),
                                    'type'    => 'error',
                                ));
                            }
                        }

                        // Get the combined label link
                        $combo_url = $response->pdf->link;

                        // Write API response to log
                        SS_SHIPPING_WC()->log_msg('Response from "combineLabelsForShipments" : ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());

                    } else {
                        SS_SHIPPING_WC()->log_msg('Error response from "combineLabelsForShipments" : ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());
                        array_push($array_messages, array(
                            'message' => __('Error combining shipping labels:',
                                    'smart-send-logistics') . ' ' . SS_SHIPPING_WC()->get_api_handle()->getErrorString(),
                            'type'    => 'error',
                        ));
                    }
                }
            }

            if (!empty($combo_url)) {
                $order_id_list = wp_list_pluck($array_shipment_ids, 'order_id');
                $order_id_list = array_unique($order_id_list);
                $label_count = count($order_id_list);
                $order_ids_str = __('Orders: #', 'smart-send-logistics') . implode(', #', $order_id_list);

                array_push($array_messages, array(
                    'message' => sprintf(__('Shipping labels created by Smart Send for %s orders: <a href="%s" target="_blank">Download combined pdf</a>',
                            'smart-send-logistics'), $label_count, $combo_url)
                        . '<br/>' . $order_ids_str,
                    'type'    => 'success',
                ));

                $array_messages = array_merge($array_messages, $array_messages_error);
            } else {
                $array_messages = array_merge($array_messages, $array_messages_success, $array_messages_error);
            }

            return $array_messages;
        }

        /**
         * Create file name from shipment ids, separated by "-" and hash it
         */
        protected function get_combo_label_file_name($shipment_ids)
        {
            $shipment_id_list = wp_list_pluck($shipment_ids, 'shipment_id');
            $shipment_ids_str = implode('-', $shipment_id_list);
            return hash('sha256', $shipment_ids_str);
        }

        /**
         * Display messages on order view screen
         */
        public function render_messages($current_screen = null)
        {
            if (!$current_screen instanceof WP_Screen) {
                $current_screen = get_current_screen();
            }

            if (isset($current_screen->id) && in_array($current_screen->id, array('shop_order', 'edit-shop_order'),
                    true)) {

                $bulk_action_message_opt = get_option('_ss_shipping_bulk_action_confirmation');

                if (($bulk_action_message_opt) && is_array($bulk_action_message_opt)) {

                    // $user_id = key( $bulk_action_message_opt );
                    // remove first element from array and verify if it is the user id
                    $user_id = array_shift($bulk_action_message_opt);
                    if (get_current_user_id() !== (int)$user_id) {
                        return;
                    }

                    foreach ($bulk_action_message_opt as $key => $value) {
                        $message = wp_kses_post($value['message']);
                        $type = wp_kses_post($value['type']);

                        switch ($type) {
                            case 'error':
                                echo '<div class="notice notice-error"><ul><li>' . $message . '</li></ul></div>';
                                break;
                            case 'success':
                                echo '<div class="notice notice-success"><ul><li><strong>' . $message . '</strong></li></ul></div>';
                                break;
                            default:
                                echo '<div class="notice notice-warning"><ul><li><strong>' . $message . '</strong></li></ul></div>';
                        }
                    }

                    delete_option('_ss_shipping_bulk_action_confirmation');
                }
            }
        }

        public function add_smart_send_status( $order_statuses ) {
            $order_statuses['ss-queue'] = __('Smart Send Queue', 'smart-send-logistics');
            return $order_statuses;
        }

        public function queue_response() {
            error_log('queue_response');
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Permission denied!', 'smart-send-logistics' ) );
            }

            // GET RESPONSES AND UPDATE QUEUED ORDERS BASED ON SETTINGS

            // wp_die();
        }
    }

endif;
