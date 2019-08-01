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
        /** SS_Shipping_WC_Order */
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
            $url = WC()->api_request_url( 'smart-send-queue-ping');
            $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'order_id={internal_id}&shipment_id={id}';
            SS_SHIPPING_WC()->define('SS_QUEUE_CALLBACK_URL', $url);
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
            add_filter('woocommerce_register_shop_order_post_statuses', array($this, 'register_smart_send_statuses'), 10, 1 );
            
            add_filter('wc_order_statuses', array($this, 'add_smart_send_statuses'), 10, 1 );

            // Call WC from Smart Send server with queue results 
            // URL: "https://example.com/wc-api/smart-send-queue-ping"
            add_action( 'woocommerce_api_smart-send-queue-ping' , array( $this, 'queue_ping' ) );
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
         *
         * @return array
         */
        public function get_bulk_actions()
        {
            $shop_manager_actions = array(
                'ss_shipping_label_bulk'  => (SS_SHIPPING_WC()->get_demo_mode_setting() ? __('DEMO MODE',
                            'smart-send-logistics') . ': ' : '') . __('Smart Send - Generate Labels',
                        'smart-send-logistics'),
                'ss_shipping_return_bulk' => (SS_SHIPPING_WC()->get_demo_mode_setting() ? __('DEMO MODE',
                            'smart-send-logistics') . ': ' : '') . __('Smart Send - Generate Return Labels',
                        'smart-send-logistics'),
                'ss_shipping_combine_labels' => (SS_SHIPPING_WC()->get_demo_mode_setting() ? __('DEMO MODE',
                            'smart-send-logistics') . ': ' : '') . __('Smart Send - Combine labels',
                        'smart-send-logistics'),
            );

            return $shop_manager_actions;
        }

        /**
         * Process bulk actions
         *
         * This method is fired when clicking one of the Smart Send bulk actions
         * from the order list action dropdown
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

                if (substr($action, 0, strlen('ss_shipping_')) === 'ss_shipping_') {//Starts with 'ss_shipping_'?
                    // This is a Smart Send action, let handle it:
                    SS_SHIPPING_WC()->log_msg('Smart Send bulk action: ' . $action);
                    SS_SHIPPING_WC()->log_msg('Demo mode: ' . (SS_SHIPPING_WC()->get_api_handle()->getDemo() ? 'yes' : 'no'));

                    $orders_count = count($order_ids);
                    SS_SHIPPING_WC()->log_msg('Order count: ' . $orders_count);

                    switch ($action) {
                        case 'ss_shipping_return_bulk':
                            // Do not break, but handle instead in next case 'ss_shipping_label_bulk'
                        case 'ss_shipping_label_bulk':
                            // Determine if the request is for a return label
                            $return = ('ss_shipping_return_bulk' === $action);

                            if ($orders_count < 1) {
                                array_push($array_messages, array(
                                    'message' => __('No orders selected, please select the orders to create labels for.',
                                        'smart-send-logistics'),
                                    'type'    => 'error',
                                ));
                            } elseif ($orders_count > 5) {
                                SS_SHIPPING_WC()->log_msg('Handling orders asynchronously');

                                $array_combo_messages = $this->smart_send_bulk_queue( $order_ids, $return );

                                $array_messages = array_merge($array_messages, $array_combo_messages);
                            } else {
                                SS_SHIPPING_WC()->log_msg('Handling orders synchronously');

                                $array_combo_messages = $this->smart_send_bulk_iteration( $order_ids, $return );

                                $array_messages = array_merge($array_messages, $array_combo_messages);
                            }

                            break;
                        case 'ss_shipping_combine_labels':
                            SS_SHIPPING_WC()->log_msg('Combining labels');
                            $array_combo_messages = $this->smart_send_bulk_combine( $order_ids );
                            $array_messages = array_merge($array_messages, $array_combo_messages);
                            break;
                        default:
                            array_push($array_messages, array(
                                'message' => __('Unknown Smart Send action',
                                    'smart-send-logistics') . ': ' . $action,
                                'type'    => 'error',
                            ));
                    }

                    /* @see render_messages() */
                    update_option('_ss_shipping_bulk_action_confirmation', $array_messages);
                }
            }
        }

        /**
         * Create one combined PDF containing labels for selected orders.
         *
         * @param array   $order_ids    WC Orders or order id's to create shipping label for
         * @return array                Array of messages (type:error/success)
         */
        protected function smart_send_bulk_combine( $order_ids )
        {
            $array_messages = array();
            $array_shipment_ids = array();

            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);

                $smartsend_shipment_id_normal = $order->get_meta('_ss_shipping_label_id', true);
                $smartsend_shipment_id_return = $order->get_meta( '_ss_shipping_return_label_id', true);

                if (!$smartsend_shipment_id_normal && !$smartsend_shipment_id_return) {
                    // A label has never been created for the order
                    array_push($array_messages, array(
                        'message' => sprintf(__('Order #%s has no shipping labels saved', 'smart-send-logistics'), $order->get_order_number()),
                        'type'    => 'error',
                    ));
                } else {
                    // At least a return label or a normal label has been created
                    if ($smartsend_shipment_id_normal) {
                        SS_SHIPPING_WC()->log_msg(sprintf('Adding normal shipment <%s> for order #%s', $smartsend_shipment_id_normal, $order->get_order_number()));
                        $array_shipment_ids[] = array(
                            'shipment_id' => $smartsend_shipment_id_normal,
                            'order_id' => $order->get_order_number(),
                        );
                    }

                    if ($smartsend_shipment_id_return) {
                        SS_SHIPPING_WC()->log_msg(sprintf('Adding return shipment <%s> for order #%s', $smartsend_shipment_id_normal, $order->get_order_number()));
                        $array_shipment_ids[] = array(
                            'shipment_id' => $smartsend_shipment_id_return,
                            'order_id' => $order->get_order_number(),
                        );
                    }
                }
            }

            if (count($array_shipment_ids) > 1) {
                $array_combo_messages = $this->create_combo_file($array_shipment_ids);
                $array_messages = array_merge($array_messages, $array_combo_messages);
            } elseif (count($array_shipment_ids) == 1) {
                // Get label for 'shipment_id'
                SS_SHIPPING_WC()->log_msg('Getting label for single shipment using getLabels()');
                SS_SHIPPING_WC()->get_api_handle()->getLabels( $array_shipment_ids[0]['shipment_id'] );
                SS_SHIPPING_WC()->log_msg('API response for getLabels(): ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());

                if ( SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {
                    $response = SS_SHIPPING_WC()->get_api_handle()->getData();

                    array_push($array_messages, array(
                        'message' => sprintf(__('<a href="%s" target="_blank">Download shipping label</a>',
                                'smart-send-logistics'), $response->pdf->link),
                        'type'    => 'success',
                    ));
                } else {
                    array_push($array_messages, array(
                        'message' => __('Error trying to download label:', 'smart-send-logistics') . ' ' . SS_SHIPPING_WC()->get_api_handle()->getErrorString(),
                        'type'    => 'error',
                    ));
                }

            }

            return $array_messages;
        }


        /**
         * Queue an array of WC Orders for generation of shipping labels.
         *
         * This is fired whenever creating more than 5 labels due to timeout issues.
         *
         * Smart Send will either accept or reject the shipments. If the shipments are accepted
         * then Smart Send will ping the callback url once each time a shipment has been processed.
         *
         * @param array   $order_ids    WC Orders or order id's to create shipping label for
         * @param boolean $return       Whether or not the label is return (true) or normal (false)
         * @return array                Array of messages (type:error/success)
         */
        protected function smart_send_bulk_queue( $order_ids, $return ) {
            $array_messages_success = array();
            $array_messages_error = array();
            $array_shipments = array();
	        $array_order_numbers = array();

            foreach ($order_ids as $order_id) {

                try {
                    $order = wc_get_order($order_id);

                    // Ensure the selected orders have a Smart Send Shipping method
                    $ss_shipping_method_id = $this->ss_order->get_smart_send_method_id($order_id);

                    if ($ss_shipping_method_id) {

                        if ($order->get_status() != 'ss-queue') {

                            $array_shipments[] = $this->ss_order->get_shipment_object_for_order($order_id, $return);
                            $array_order_numbers[] = $order->get_order_number();
                        } else {
                            $message = sprintf(__('Order #%s', 'smart-send-logistics'), $order->get_order_number())
                                . ': ' . __('The selected order is already queued by Smart Send', 'smart-send-logistics');
                            throw new Exception($message);
                        }

                    } else {
                        $message = sprintf(__('Order #%s', 'smart-send-logistics'), $order->get_order_number())
                            . ': ' . __('The selected order did not include a Send Smart shipping method', 'smart-send-logistics');
                        throw new Exception($message);
                    }
                } catch (Exception $exception) {
                    array_push($array_messages_error, array(
                        'message' => $exception->getMessage(),
                        'type'    => 'error',
                    ));
                }
            }

            if (!empty($array_shipments)) {
                SS_SHIPPING_WC()->log_msg('API request for createShipmentAndLabelsAsync() with parameters: ' . json_encode($array_shipments));
                $response = SS_SHIPPING_WC()->get_api_handle()->createShipmentAndLabelsAsync($array_shipments, null, SS_QUEUE_CALLBACK_URL );
                SS_SHIPPING_WC()->log_msg('API response for createShipmentAndLabelsAsync(): ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());

                if ( SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {
                    $queue_count = count($array_order_numbers);
                    $order_ids_str = '#' . implode(', #', $array_order_numbers);

                    array_push($array_messages_success, array(
                        'message' => sprintf(__('Smart Send will create labels for the %s selected orders:',
                                'smart-send-logistics'), $queue_count)
                            . '<br/>' . $order_ids_str,
                        'type'    => 'success',
                    ));


                    $data = SS_SHIPPING_WC()->get_api_handle()->getData();

                    // Save 'shipment_id' for each order
                    foreach ($data->shipments as $shipment_key => $shipment_value) {

                        try {
                            if (stripos($shipment_value->shipping_method, 'return') !== false) {
                                $return = true;
                            } else {
                                $return = false;
                            }

                            $order = wc_get_order($shipment_value->internal_id);

                            // Update order status
                            $order->update_status('wc-ss-queue');

                            // Save meta information
                            $this->ss_order->save_ss_shipment_id_in_order_meta($shipment_value->internal_id, $shipment_value->shipment_id, $return);
                        } catch (Exception $exception) {
                            array_push($array_messages_error, array(
                                'message' => $exception->getMessage(),
                                'type'    => 'error',
                            ));
                        }

                    }
                } else {
                    // Either some of the shipments failed validation or user
                    // does not have access to the async label generation
                    array_push($array_messages_error, array(
                        'message' => SS_SHIPPING_WC()->get_api_handle()->getErrorString(),
                        'type'    => 'error',
                    ));
                }
            }
            
            $array_messages = array_merge($array_messages_success, $array_messages_error);

            return $array_messages;
        }

        protected function smart_send_bulk_iteration( $order_ids, $return )
        {
            $array_messages_success = array();
            $array_messages_error = array();
            $array_shipment_ids = array();

            foreach ($order_ids as $order_id) {
                try {
                    $order = wc_get_order($order_id);
                    SS_SHIPPING_WC()->log_msg('Creating a ' . ($return ? 'return ' : '')
                        . 'label for order #' . $order->get_order_number() . ' with post_id='.$order_id);

                    // Ensure the selected orders have a Smart Send Shipping method
                    $ss_shipping_method_id = $this->ss_order->get_smart_send_method_id($order_id);

                    if ($ss_shipping_method_id) {

                        if ($order->get_status() != 'ss-queue') {

                            $shipment_response = $this->ss_order->create_label_for_single_order($order_id, $return, true);

                            $label_link = $this->ss_order->get_ss_shipping_label_link($shipment_response->pdf->link, $return);
                            $message = sprintf(__('Order #%s', 'smart-send-logistics'), $order->get_order_number())
                                . ': ' . ($return ? __('Return label created by Smart Send', 'smart-send-logistics')
                                    : __('Shipping label created by Smart Send', 'smart-send-logistics'))
                                . '. ' . $label_link;

                            array_push($array_messages_success, array(
                                'message' =>  $message,
                                'type' => 'success',
                            ));

                            array_push($array_shipment_ids, array(
                                'shipment_id' => $shipment_response->shipment_id,
                                'order_id' => $order->get_order_number(),
                            ));

                            $auto_generate_return = (!$return && $this->ss_order->should_auto_generate_return($order_id));

                            SS_SHIPPING_WC()->log_msg('Should ' . ($auto_generate_return ? '' : 'not ')
                                . 'automatically generate a return label for order #' . $order->get_order_number() . ' with post_id='.$order_id);

                            if ($auto_generate_return) {

                                SS_SHIPPING_WC()->log_msg('Creating a ' . (!$return ? 'return ' : '')
                                    . 'label for order #' . $order->get_order_number() . ' with post_id='.$order_id);

                                $shipment_response = $this->ss_order->create_label_for_single_order($order_id, !$return, true);

                                $label_link = $this->ss_order->get_ss_shipping_label_link($shipment_response->pdf->link, $return);
                                $message = sprintf(__('Order #%s', 'smart-send-logistics'), $order->get_order_number())
                                    . ': ' . (!$return ? __('Return label created by Smart Send', 'smart-send-logistics')
                                        : __('Shipping label created by Smart Send', 'smart-send-logistics'))
                                    . '. ' . $label_link;

                                array_push($array_messages_success, array(
                                    'message' =>  $message,
                                    'type' => 'success',
                                ));

                                array_push($array_shipment_ids, array(
                                    'shipment_id' => $shipment_response->shipment_id,
                                    'order_id' => $order->get_order_number(),
                                ));
                            }

                        } else {
                            // Add error message
                            $message = sprintf(__('Order #%s', 'smart-send-logistics'), $order->get_order_number())
                                . ': ' .  __('The selected order is already queued by Smart Send', 'smart-send-logistics');

                            throw new Exception($message);
                        }

                    } else {
                        // Add error message
                        $message = sprintf(__('Order #%s', 'smart-send-logistics'), $order->get_order_number())
                            . ': ' .  __('The selected order did not include a Send Smart shipping method', 'smart-send-logistics');

                        throw new Exception($message);
                    }
                } catch (Exception $exception) {
                    SS_SHIPPING_WC()->log_msg('Failed to handle order. Exception with error: ' . $exception->getMessage());

                    array_push($array_messages_error, array(
                        'message' => $exception->getMessage(),
                        'type' => 'error',
                    ));
                }
            }

            return $this->create_combo_file(
		        $array_shipment_ids,
                $array_messages_success,
                $array_messages_error
	        );
        }

	    /**
         * Create a combined PDF file.
         *
	     * @param $array_shipment_ids
         * @param $array_messages_success
	     * @param $array_messages_error
	     *
	     * @return array
	     */
        protected function create_combo_file($array_shipment_ids, $array_messages_success = array(), $array_messages_error = array())
        {

            $array_messages = array();
            $combo_url = '';

            // If more than one smart send shipment label created, then create combo labels
            if (count($array_shipment_ids) > 1) {
                // Create combined label with successful shipments
                $combined_shipments = SS_SHIPPING_WC()->get_api_handle()->combineLabelsForShipments(wp_list_pluck($array_shipment_ids,
                    'shipment_id'));

                // Write API request to log
                SS_SHIPPING_WC()->log_msg('Called "combineLabelsForShipments" with arguments: ' . SS_SHIPPING_WC()->get_api_handle()->getRequestBody());

                if (SS_SHIPPING_WC()->get_api_handle()->isSuccessful()) {

                    $response = SS_SHIPPING_WC()->get_api_handle()->getData();

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

            if (!empty($combo_url)) {
                $order_id_list = wp_list_pluck($array_shipment_ids, 'order_id');
                $order_id_list = array_unique($order_id_list);
                $label_count = count($order_id_list);
                $order_ids_str = '#' . implode(', #', $order_id_list);

                array_push($array_messages, array(
                    'message' => sprintf(__('Shipping labels for %s orders: <a href="%s" target="_blank">Download combined pdf</a>',
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
         *
         * @param array $shipment_ids
         *
         * @return string
         */
        protected function get_combo_label_file_name($shipment_ids)
        {
            $shipment_id_list = wp_list_pluck($shipment_ids, 'shipment_id');
            $shipment_ids_str = implode('-', $shipment_id_list);
            return hash('sha256', $shipment_ids_str);
        }

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

                        SS_SHIPPING_WC()->log_msg('Showing ' . $type . '-message: ' . $message);

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

	    /**
         * Register new Smart Send status for queueing
         *
	     * @param $order_statuses
	     *
	     * @return array
	     */
        public function register_smart_send_statuses( $order_statuses ) {
            $smart_send_statuses['wc-ss-queue'] = array(
                'label'                     => __('Smart Send Queue', 'smart-send-logistics'),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop( 'Smart Send Queue <span class="count">(%s)</span>', 'Smart Send Queue <span class="count">(%s)</span>', 'smart-send-logistics' ),
            );

            return array_merge( $order_statuses, $smart_send_statuses);
        }

	    /**
         * Add new Smart Send status for queueing in status list array
         *
	     * @param $order_statuses
	     *
	     * @return array
	     */
        public function add_smart_send_statuses( $order_statuses ) {
            $order_statuses['wc-ss-queue'] = __('Smart Send Queue', 'smart-send-logistics');
            return $order_statuses;
        }


	    /**
	     * Callback function with GET parameters; order_id and shipment_id
         * This function call the Smart Send API to verify a label status and get label data
	     */
        public function queue_ping() {
            SS_SHIPPING_WC()->log_msg('Queue Ping API request with parameters: ' . json_encode($_GET));

            if ( isset( $_GET['order_id'] ) && isset( $_GET['shipment_id'] ) ) {
                $order_id = wc_clean( $_GET['order_id'] );
                $order = wc_get_order( $order_id );
                
                $shipment_id = wc_clean( $_GET['shipment_id'] );

                // Only handle request if the order was queued to Smart Send server
                if( $order && $order->get_status() == 'ss-queue' ) {
                    SS_SHIPPING_WC()->log_msg('Order was queued, will check for updates.');

                    // Determine if the label is a return or normal
	                $meta_shipment_id = $this->ss_order->get_ss_shipment_id_from_order_meta( $order_id, false );
                    $meta_shipment_return_id = $this->ss_order->get_ss_shipment_id_from_order_meta( $order_id, true );

                    if ($meta_shipment_id == $shipment_id) {
                        $return = false;
                    } elseif ($meta_shipment_return_id == $shipment_id) {
	                    $return = true;
                    }

                    // Handle the label if the shipment_id matches
                    if (isset($return)) {
	                    // Get label for 'shipment_id'
                        SS_SHIPPING_WC()->log_msg('API request for getLabels()');
	                    SS_SHIPPING_WC()->get_api_handle()->getLabels( $shipment_id );
                        SS_SHIPPING_WC()->log_msg('API response for getLabels(): ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());

	                    if ( SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {
		                    $response = SS_SHIPPING_WC()->get_api_handle()->getData();
		                    $this->ss_order->handle_generated_label($order_id, $response, $return, $setting_save_order_note = true, $created_queued=true);

		                    $ss_shipping_method_id = $this->ss_order->get_smart_send_method_id($order_id, $return);
		                    if ($return == false && $this->ss_order->should_auto_generate_return($order_id)) {

		                        // We need to queue a return label for the order
			                    $shipment = $this->ss_order->get_shipment_object_for_order($order_id, $return);
                                SS_SHIPPING_WC()->log_msg('Queuing a return label');
                                SS_SHIPPING_WC()->log_msg('API request for createShipmentAndLabelsAsync() with parameters: ' . json_encode(array($shipment)));
			                    SS_SHIPPING_WC()->get_api_handle()->createShipmentAndLabelsAsync(array($shipment), null, SS_QUEUE_CALLBACK_URL );
                                SS_SHIPPING_WC()->log_msg('API response for createShipmentAndLabelsAsync(): ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());

                                if (SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {
                                    $response = SS_SHIPPING_WC()->get_api_handle()->getData();

				                    // Update order status
				                    $order->update_status('wc-ss-queue');

				                    // Save meta information
				                    $this->ss_order->save_ss_shipment_id_in_order_meta($response->shipments[0]->internal_id, $response->shipments[0]->shipment_id, $return);
			                    } else {
				                    $error = SS_SHIPPING_WC()->get_api_handle()->getError();
				                    $this->ss_order->handle_failed_label( $order_id, $error, $return, true, $created_queued=true );
			                    }
                            }
	                    } else {
		                    $error = SS_SHIPPING_WC()->get_api_handle()->getError();
		                    $this->ss_order->handle_failed_label( $order_id, $error, $return, true, $created_queued=true );

		                    SS_SHIPPING_WC()->log_msg('Error response from "getLabels" : ' . SS_SHIPPING_WC()->get_api_handle()->getResponseBody());
	                    }
                    } else {
                        SS_SHIPPING_WC()->log_msg('Order did not match the shipment_id');
                    }
                } else {
                    SS_SHIPPING_WC()->log_msg('Order was not queued - stops here.');
                }
            }

            // wp_die();
        }
    }

endif;
