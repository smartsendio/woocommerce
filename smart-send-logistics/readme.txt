=== Smart Send ===
Contributors: SmartSend
Donate link: https://smartsend.io/
Author: SmartSend
Author URI: https://smartsend.io/
Developer: SmartSend
Developer URI: https://smartsend.io/
Tags: shipping, pickup-points, shipping-label, postnord, smart send
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 9.0.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce
WC requires at least: 8.2.0
WC tested up to: 11.1
Requires PHP: 7.4

Shipping methods, pickup points and shipping labels for many carriers, like PostNord, GLS, DAO, Burd, Budbee and Bring, directly in WooCommerce.

== Description ==

Smart Send is a complete shipping solution for WooCommerce and many carriers, like PostNord, GLS, DAO, Budbee, Burd and Bring. Set up shipping methods with rates based on shipping address, weight, subtotal, shipping class and user role, let the customer choose a pickup point at checkout, and create shipping labels with one click from the WooCommerce order screen. Everything runs inside your WooCommerce store; the plugin talks to Smart Send in the background.

Works with both the classic checkout and the WooCommerce Checkout Block, and with High-Performance Order Storage (HPOS).

Supported carriers include:

* PostNord (Posten / Post Danmark)
* GLS (YourGLS)
* Bring (MyBring)
* DAO
* Burd
* Budbee

See the full list of carriers on [smartsend.io](https://smartsend.io/).

Supports worldwide shipping from these countries:

* Denmark
* Sweden
* Finland
* Norway

= Shipping methods =
Shipping methods are set up in WooCommerce Shipping Zones, and the shipping cost can be calculated based on a range of criteria:

* Shipping address
* Order weight
* Order subtotal
* Shipping class
* User role
* Shipping Zone

= Services =
Enable services for shipping methods:

* Customer notification by email
* Customer notification by SMS
* Pickup point (collect the parcel at a shop near the customer)
* Flex delivery (leave parcel at specified location)
* Home delivery
* Handling of special goods, e.g. food
* Tax handling
* Free delivery based on conditions

= Pickup points =
Let the customer choose a pickup point close to them during checkout. The parcel is delivered to the selected pickup point, where the customer collects it at their own convenience.

* Nearest pickup points based on the entered shipping address
* List updates automatically when the address changes
* Works in the classic checkout and in the WooCommerce Checkout Block
* Compatible with one-step/one-page checkouts

Shipping to pickup points is the most widely used delivery method thanks to its flexibility and lower shipping cost.

= Shipping labels =
Create shipping labels with a single click from the WooCommerce order screen. The order data is sent to the carrier, and the PDF label is ready to print right away. Tracking numbers are saved on the order, added to the order note and, with the WooCommerce Shipment Tracking plugin, shown to the customer. The Smart Send box shows the result immediately; reload the page to see the new note in WooCommerce's order history.

Creating another label requires an extra confirmation when any requested shipping or return label already exists, including a return label created together with a shipping label. Existing shipments are not cancelled. Editing the form or dismissing the warning cancels the pending confirmation.

Easily create:

* Shipping labels as PDF files
* Return shipping labels, on their own or automatically together with the shipping label
* Tracking information

[youtube https://www.youtube.com/watch?v=Vl_rPb-t8xE]

= For developers =
The plugin is built around a clear separation between fulfillment (what ships and how, and what is written to the WooCommerce order) and booking (ordering the shipment from the carrier), with `smart_send_*` hooks at every stage. See the Developers section.

== Installation ==

See our online installation guide at [https://smartsend.io](https://smartsend.io/woocommerce/configuration), or follow these steps:

1. Log in to the WordPress dashboard
2. Navigate to the Plugin menu
3. Click 'Add New' in the Plugin sub-menu
4. Enter 'Smart Send' in the search field and click 'Search Plugins'
5. Click the 'Install Now'-button
6. Once the plugin is installed, click the 'Activate Plugin' link to activate the plugin
7. The plugin is installed, activated and ready to use once you see the success message 'Plugin activated' at the top of the plugin page

= Connect the plugin to Smart Send using an API Token =

The plugin must be connected to Smart Send for all functions to work properly. You can create a [Smart Send account here](https://smartsend.io/signup)

[youtube https://www.youtube.com/watch?v=wyJYbwwI0h8]

See our written guide on the [Smart Send website](https://smartsend.io/woocommerce/api-token/) or follow these steps:

1. Log in to the WordPress dashboard
2. Choose 'WooCommerce' in the menu to the left and select 'Settings'
3. Choose the 'Shipping' tab in the top menu bar
4. Click on 'Smart Send' in the list under the tabs
5. Enter the API Token you received in your welcome email and click save. Signup [here](https://smartsend.io/woocommerce/api-token/) to get an API Token.
6. Save the settings to validate the API Token. The connection result appears below the API Token field every time you save.

== Developers ==

The plugin has a formal extension API of `smart_send_*` hooks: filters for values, actions for events. Extend the plugin through these hooks instead of patching it - the names and signatures are stable, and shipment/pickup hooks pass typed value objects (never raw Smart Send API request or response shapes), so a snippet keeps working when the plugin moves to a newer API version.

PHP classes use the `Smart_Send\` namespace with domain subnamespaces, such as `Smart_Send\Delivery\Pickup_Point` and `Smart_Send\Booking\Booked_Shipment`. All classes follow WordPress naming conventions. The examples below use fully qualified class names and can be pasted into a snippet without imports. The global `SS_SHIPPING_WC()` accessor remains available; the plugin's bundled loader requires no Composer installation.

An order goes through three stages, and each stage has its own hooks:

1. **Shipping methods at checkout** - the Smart Send shipping methods are offered as rates and, for pickup point methods, the customer picks a pickup point.
2. **Fulfillment** - the merchant creates the label: the plugin decides *what* ships and *how* (method, pickup point, parcels), calls booking, and writes the outcome onto the order (shipment id, documents, order note, tracking, status). Fulfillment has two flows - an outbound label, and a return label (created on its own or auto-generated after the outbound one).
3. **Booking** - one operation for both flows: the shipment is built from the order and the fulfillment decision, sent to the Smart Send API, and the result comes back as a booked shipment.

= 1. Shipping methods at checkout =

Rates (WooCommerce-standard names):

* **woocommerce_smart_send_shipping_shipping_add_rate** `( Smart_Send\Shipping_Method\Method $method, array $rate )`
    Action after a Smart Send rate is added - add further rates next to it
* **woocommerce_shipping_smart_send_shipping_is_available** `( bool $is_available, array $package, Smart_Send\Shipping_Method\Method $method )`
    Filter to hide a Smart Send shipping method for a package
* **woocommerce_shipping_smart_send_shipping_is_free_shipping** `( bool $is_free, array $package, Smart_Send\Shipping_Method\Method $method )`
    Filter to grant or deny free shipping for a method

Pickup points:

* **smart_send_pickup_point_search_params** `( array $params )` (since 9.0.0)
    Filter the search parameters (carrier, country, postal_code, city, street) before looking up the closest pickup points
* **smart_send_pickup_point_list** `( Smart_Send\Delivery\Pickup_Point[] $pickup_points, array $params )` (since 9.0.0)
    Filter the available pickup points before caching and displaying them: return fewer to limit the list, or reorder them. Return only `\Smart_Send\Delivery\Pickup_Point` objects (`get_agent_no()`, `get_company()`, `get_address_line1()`, `get_postal_code()`, `get_city()`, `get_country()`, `get_distance()`, `get_carrier()`, `get_latitude()`/`get_longitude()`, `get_opening_hours()`, `to_array()`). Points must match the lookup's carrier and country.
* **smart_send_pickup_point_label** `( string $label, Smart_Send\Delivery\Pickup_Point $pickup_point )` (since 9.0.0)
    Filter a pickup point's plain-text display label. Return text, not HTML or pre-escaped markup; each renderer escapes the label for its own output. This contract applies to pickup point labels independently of whether a future interface uses a list or map.
* **smart_send_pickup_point_timeout** `( int $seconds )`
    Filter the API timeout used when looking up pickup points

Pickup point identity is the exact agent number together with its carrier and country. Submitted names and addresses are not trusted: checkout and label creation resolve the point from compatible server data or the API. Nearest search results and the customer's explicit selection are stored separately. An explicit compatible choice survives a refreshed list, even when the chosen point is no longer among the nearest results; changing carrier or country requires a compatible choice. If no points are available, checkout permits the existing fallback only when a server lookup for the current address confirms that state.

The **Select Default** setting chooses the first available point only when there is no explicit compatible choice. Use `smart_send_pickup_point_list` to influence that order. There is no separate default-selection filter. The wider checkout interface rewrite remains planned for a later 9.x release; these contracts do not depend on the current dropdown.

The selected pickup point is shown on the order details page and in order emails through WooCommerce's `woocommerce_order_details_after_order_table` and `woocommerce_email_after_order_table` actions.

Example: prioritize points open on Saturdays, preserving distance order within each group, and display at most five. Enable **Select Default** to use the first point as the automatic default:

    add_filter( 'smart_send_pickup_point_list', function ( array $pickup_points, array $params ) {
        $saturday = array();
        $other = array();
        foreach ( $pickup_points as $pickup_point ) {
            $open_saturday = false;
            foreach ( $pickup_point->get_opening_hours() as $interval ) {
                if ( 'saturday' === $interval['day'] ) {
                    $open_saturday = true;
                    break;
                }
            }
            if ( $open_saturday ) {
                $saturday[] = $pickup_point;
            } else {
                $other[] = $pickup_point;
            }
        }
        return array_slice( array_merge( $saturday, $other ), 0, 5 );
    }, 10, 2 );

Example: append the agent number to the plain-text label:

    add_filter( 'smart_send_pickup_point_label', function ( string $label, \Smart_Send\Delivery\Pickup_Point $pickup_point ) {
        return $label . ' (#' . $pickup_point->get_agent_no() . ')';
    }, 10, 2 );

= 2. Fulfillment =

Fulfillment runs when the merchant creates a label on the order page or uses the bulk action. For each label (outbound, and the return label when one is created) it decides the delivery details, books, and then applies the side effects on the order one step at a time. Every step has a filter, and the run ends with one action.

For the order-screen REST request, `with_return: null` follows the original order shipping method's automatic-return setting; true/false overrides it. Changing the outbound method for this booking does not change that default, and a submitted `return_method` alone does not request a return label. Before any lookup or booking, the endpoint requires `confirm_rebook: true` if any requested direction already has a label. A stale order screen receives `409 smart_send_already_booked` and asks for one new confirming click while keeping the merchant's edits.

Deciding what ships:

* **smart_send_delivery_details** `( Smart_Send\Delivery\Delivery_Details $details, WC_Order $order, bool $is_return )` (since 9.0.0)
    Filter on the merged delivery details - the stored order configuration plus the shipping method resolved from the order - right before booking is called. This is the one place to override the shipping method (`set_shipping_method('gls_shop')`), clear or replace the pickup point (`set_pickup_point()` with a `\Smart_Send\Delivery\Pickup_Point` or `null`) or declare the parcel split (`set_parcel_plan()` with a `\Smart_Send\Delivery\Parcel_Plan` of `\Smart_Send\Delivery\Parcel_Spec` rows). Allocate items with `$spec->add_item($order_item_id, $quantity, $name)`: the ID is a WooCommerce order-item ID, never a product or variation ID. The optional name is only a display label. Return the details object.
    The details the merchant submitted in the order meta box (shipping method, pickup point, parcels) are already merged into `$details` when the filter runs - a submitted value wins over the stored one. They are stored on the order only after the booking succeeds: the submitted pickup point and the parcel item rows are written first, then the shipment id; a failed booking leaves the order meta untouched. The submitted shipping method and a parcel's weight and dimensions are per booking and are never stored. A combined outbound/return booking uses the submitted parcel plan for both legs unless an explicit return plan overrides it. What the filter returns is what gets booked, but it is not what gets stored.
* **smart_send_parcel_default_weight** `( float $weight, Smart_Send\Delivery\Parcel_Spec $spec, WC_Order $order )` (since 9.0.0)
    Filter applied at booking to the weight of a parcel that has no explicit weight: the sum of each allocated product's weight multiplied by its quantity, in kg (0 when the items weigh nothing or the parcel has no items). Use it to add packaging weight or apply a minimum. It changes the booking weight, not the item allocation, the calculated weight shown on the order page or the saved parcel plan. This remains a float-to-float filter and applies to outbound and return bookings. A parcel with an explicit weight - entered in the order meta box, or `set_weight()` on the spec in `smart_send_delivery_details` - bypasses this filter entirely. When a deleted product leaves an allocated item's weight unknown, enter a positive explicit parcel weight; booking stops with a weight-field error before this filter runs.

Every order unit must be allocated exactly once across the plan, including for return labels. Unknown order-item IDs, duplicate rows within a parcel, fractional or non-positive quantities, and under- or over-allocation are rejected. An absent or empty plan means one parcel containing everything. One spec without item allocations also contains everything, and can supply a manual weight and dimensions. Multiple specs without allocations are rejected when the order contains items. Each parcel contains one item row per allocated order line, with its allocated quantity and share of the line's discounted net and tax amounts. Rounding follows the store's currency precision, with the final allocation taking the remainder so the amounts reconcile. Parcel totals contain the allocated merchandise amounts; order-level fees remain in the shipment totals.

What the order screen offers:

* **smart_send_fulfillment_shipping_methods** `( array $carriers, WC_Order $order, bool $is_return )` (since 9.0.0)
    Filter on the shipping methods the order screen's "Smart Send" box offers in its method drop-downs. It runs once per list, so the outbound and the return drop-down can be restricted differently (`$is_return` tells them apart). The methods are carriers, each with services, each with addons:

        array(
            array(
                'code'     => 'postnord',
                'name'     => 'PostNord',
                'services' => array(
                    array( 'code' => 'agent', 'name' => 'PostNord: Select pickup point (MyPack Collect)', 'addons' => array() ),
                    array( 'code' => 'homedelivery', 'name' => 'PostNord: Private delivery to address (MyPack Home)', 'addons' => array() ),
                ),
            ),
        )

    The method code booked with is `<carrier code>_<service code>`, e.g. `postnord_agent`. Returning an empty array leaves the drop-down empty. `addons` is **reserved** for the delivery addons landing with the API v2 work and is always an empty array today - do not build an addon catalogue on it.
    The method the order itself resolves to (its stored/resolved shipping method, and the configured return method) is always offered, even when the filter removes its carrier or service: the box never shows a selected value its drop-down cannot offer, and the plugin logs that at `debug` level naming the method code.
    This narrows what the **box offers**; it is **not an authorisation boundary**. The REST route behind the box does not validate a submitted method against the filter, and only users with the `edit_shop_orders` capability reach any of it. Use `smart_send_delivery_details` when a method must not be booked at all.

After a successful booking, submitted delivery details are stored first. Document copies are then attempted, followed by shipment ID/history persistence, the order note, tracking and order status. The following filters control the optional steps:

* **smart_send_fulfillment_save_documents** `( bool $save, Smart_Send\Booking\Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on whether a copy of the shipment's documents is saved in the uploads folder; defaults to the "Save shipping labels in uploads folder" setting. When saved, the label document's `download_url()` points at the copy. A copy that cannot be saved does not fail the label: the shipment stays fulfilled with a warning (`get_warnings($shipment)` on the result, `save_documents` = `'failed'` in `get_steps($shipment)`) and `download_url()` falls back to the Smart Send URL
* **smart_send_fulfillment_order_note** `( string $note_html, Smart_Send\Booking\Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on the order note added once the shipment is booked (document links, codes, tracking numbers). The filtered content is saved through `WC_Order::add_order_note()`. Return an empty string to add no note. WooCommerce displays saved notes in its native history after a page reload; the Smart Send box displays the booking result immediately. Replaces `smart_send_shipping_label_comment`
* **smart_send_fulfillment_tracking** `( bool $push, Smart_Send\Booking\Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on whether the parcels' tracking numbers are pushed to the WooCommerce Shipment Tracking plugin; defaults to true for an outbound shipment and false for a return shipment
* **smart_send_fulfillment_order_status** `( string|false $status, Smart_Send\Booking\Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on the status the order is set to (e.g. `wc-completed`), or `false` to leave it alone; defaults to the "Order status after label" setting for an outbound shipment and `false` for a return shipment

When the run is done:

* **smart_send_order_fulfilled** `( WC_Order $order, Smart_Send\Fulfillment\Fulfillment_Result $result )` (since 9.0.0)
    Action fired once per run, after every side effect of every label is applied, when at least one shipment was fulfilled. The result carries `shipments()`, `get_outbound_shipment()` and `get_return_shipment()` (each a `\Smart_Send\Booking\Booked_Shipment`, see Booking below), `get_order_note($shipment)`, `get_order_note_id($shipment)`, `get_steps($shipment)` (which side effects ran), `get_warnings($shipment)` and, for a leg that failed, `get_outbound_error()`/`get_return_error()` (HTML), `get_validation_errors($is_return)` and `get_error_details($is_return)` (message, Response-ID, field errors, HTML). `to_array()` is the serializable form: one row per attempted label with `direction`, `status` (`fulfilled`/`failed`), the shipment's `to_array()`, `steps`, `order_note` (`{id: int|null}`), `warnings` or `error`. `steps.order_note` records whether the note was saved; the serialized result and REST response contain no note HTML. Replaces `smart_send_shipping_label_created` (8.x), which no longer fires

Example: offer only PostNord pickup point services for outbound orders with a shipping charge of at least 10 in the store currency:

    add_filter('smart_send_fulfillment_shipping_methods', function (array $carriers, WC_Order $order, bool $is_return) {
        if ($is_return || $order->get_shipping_total() < 10) {
            return $carriers;
        }

        foreach ($carriers as $index => $carrier) {
            if ($carrier['code'] !== 'postnord') {
                unset($carriers[$index]);
                continue;
            }

            $carriers[$index]['services'] = array_values(array_filter($carrier['services'], function ($service) {
                return in_array($service['code'], array('agent', 'collect'), true);
            }));
        }

        return array_values($carriers);
    }, 10, 3);

Example: ship all order items in one parcel with a fixed size and weight:

    add_filter('smart_send_delivery_details', function (\Smart_Send\Delivery\Delivery_Details $details, WC_Order $order, bool $is_return) {
        $plan = new \Smart_Send\Delivery\Parcel_Plan();
        $plan->add_spec((new \Smart_Send\Delivery\Parcel_Spec())->set_weight(4)->set_length(30)->set_width(20)->set_height(10));

        return $details->set_parcel_plan($plan);
    }, 10, 3);

Example: override the pickup point for a specific customer:

    add_filter('smart_send_delivery_details', function (\Smart_Send\Delivery\Delivery_Details $details, WC_Order $order, bool $is_return) {
        if ($order->get_billing_email() === 'vip@example.com') {
            $details->set_pickup_point(\Smart_Send\Delivery\Pickup_Point::from_object(['agent_no' => '1234', 'country' => 'DK']));
        }

        return $details;
    }, 10, 3);

Example: never push tracking numbers to Shipment Tracking, and shorten the order note:

    add_filter('smart_send_fulfillment_tracking', '__return_false');

    add_filter('smart_send_fulfillment_order_note', function (string $note, \Smart_Send\Booking\Booked_Shipment $shipment, WC_Order $order) {
        return 'Smart Send shipment ' . $shipment->get_shipment_id() . ' (' . $shipment->get_tracking_code() . ')';
    }, 10, 3);

Example: send the tracking code to your own system once the order is fulfilled:

    add_action('smart_send_order_fulfilled', function (WC_Order $order, \Smart_Send\Fulfillment\Fulfillment_Result $result) {
        $shipment = $result->get_outbound_shipment();
        if (! $shipment) {
            return; // return-only run, or the outbound label failed - see $result->get_outbound_error()
        }

        $label = $shipment->label_document(); // null when the booking produced no label document (e.g. a QR code only)
        my_system_register_shipment(
            $order->get_id(),
            $shipment->get_shipment_id(),
            $shipment->get_tracking_code(),
            $label ? $label->download_url() : null
        );
    }, 10, 2);

= 3. Booking =

Booking is one operation for outbound and return shipments: the `Smart_Send\Booking\Shipment` request is built from the order and the delivery details, sent to the Smart Send API, and the response is mapped to a `Smart_Send\Booking\Booked_Shipment`. Booking never writes to the order - that happens in fulfillment.

Reading the order (the data that goes into the request):

* **smart_send_order_receiver** `( array $shipping_address, int $order_id )`
    Filter on the order's shipping address used as the receiver
* **smart_send_receiver_phone** `( string|null $phone, WC_Order $order )` (since 9.0.0)
    Filter on the receiver phone number used for the label and the carrier's SMS notification
* **smart_send_payload_receiver** `( array $receiver, WC_Order $order )` (since 9.0.0)
    Filter on the receiver data read from the order
* **smart_send_payload_items** `( array $items, WC_Order $order )` (since 9.0.0)
    Filter on the item lines read from the order. Each row identifies the purchased line with `order_item_id`, and keeps the catalog `product_id` and `variation_id` separate (`variation_id` is 0 for a simple product). Rows also contain `sku`, the saved order-line `name`, `description`, `hs_code`, `country_of_origin`, `quantity`, `unit_weight` in kg, `total_net_amount`, `total_tax_amount` and `product_missing`. Preserve the order-item identity and quantity so parcel allocations can be validated. The API item's `internal_id` and `internal_reference` use the order-item ID.
    A deleted product or variation is shown as the translatable "Deleted" with an empty SKU; its order-line quantity and amounts are retained, while weight and customs fields are null. Historical product data is not reconstructed. A new outbound or return booking requires an explicit parcel weight when any allocated weight is unknown. Missing required customs data remains an actionable API validation error rather than being guessed.
* **smart_send_payload_totals** `( array $totals, WC_Order $order )` (since 9.0.0)
    Filter on the order totals read from the order
* **smart_send_shipment_freetext** `( string|null $freetext, WC_Order $order )` (since 9.0.0)
    Filter on the freetext printed on the label (the customer's order comment when the "Include order comment" setting is on). Replaces `smart_send_order_note`

The request and the result:

* **smart_send_booking_request** `( Smart_Send\Booking\Shipment $shipment, WC_Order $order, bool $is_return )` (since 9.0.0)
    Filter on the complete shipment about to be booked - receiver, pickup point, parcels with item lines, amounts - right before it is sent. Return the shipment
* **smart_send_booking_completed** `( Smart_Send\Booking\Booked_Shipment $booked, Smart_Send\Booking\Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Action when the API booked the shipment, before anything is written to the order
* **smart_send_booking_failed** `( Smart_Send\Booking\Exceptions\Booking_Exception $exception, Smart_Send\Booking\Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Action when the API rejected the shipment, right before the exception is thrown. `$exception->getMessage()` is the API message, `errors()` the per-field validation errors (field => list of messages), `response_id()` the Smart Send Response-ID for support, `getPrevious()` the API client exception

The booked shipment (`Smart_Send\Booking\Booked_Shipment`) carries `get_shipment_id()`, `get_carrier()` (the carrier code, e.g. `postnord`), `get_service_code()`, `is_return()`, `get_state()`, `get_booked_at()`, shipment-level `get_tracking_code()`/`get_tracking_url()`, `parcels()` (`Smart_Send\Booking\Booked_Parcel`: parcel id, tracking code and URL, plus the weight, dimensions and reference it was booked with - carried over from the request parcel, since the API does not echo them back), `documents()` (`Smart_Send\Booking\Shipment_Document`: type such as `label` or `customs_declaration`, format such as `pdf` or `zpl`, layout, `get_url()`, and `get_local_url()`/`get_local_path()` when fulfillment stored a copy - `download_url()` prefers that copy) and `codes()` (`Smart_Send\Booking\Shipment_Code`: type such as `qr_code`, value, image URL, expiry, instructions). Documents and codes are lists on the shipment, never on a parcel - do not assume one PDF; `label_document()` is a shortcut to the first label document, or null. `to_array()`/`from_array()` round-trip every field. Today (API v1) a booking yields exactly one `label`/`pdf` document and no codes; API v2 will add QR codes, label codes and ZPL/customs documents without changing this contract.

Example: react to a completed booking and to a rejected one:

    add_action('smart_send_booking_completed', function (\Smart_Send\Booking\Booked_Shipment $booked, \Smart_Send\Booking\Shipment $shipment, WC_Order $order) {
        error_log(sprintf('Order %d booked as %s with %d parcel(s)', $order->get_id(), $booked->get_shipment_id(), count($booked->parcels())));
    }, 10, 3);

    add_action('smart_send_booking_failed', function (\Smart_Send\Booking\Exceptions\Booking_Exception $exception, \Smart_Send\Booking\Shipment $shipment, WC_Order $order) {
        foreach ($exception->errors() as $field => $messages) {
            error_log(sprintf('Order %d: %s - %s', $order->get_id(), $field, implode(', ', $messages)));
        }
    }, 10, 3);

= API connection and logging =

* **smart_send_api_endpoint** `( string $host )`
    Filter on the Smart Send host the plugin talks to, e.g. to point at the sandbox environment. Since 9.0.0 it receives and must return the host only (e.g. `https://app.smartsend.dev`) - the plugin appends the API version path itself. A returned value that still ends in `/api/v1/` is stripped to the host with a warning in the WooCommerce log
* **smart_send_sslverify** `( bool $verify )`
    Filter to disable SSL certificate verification for API requests (only for local development)
* **smart_send_logging** `( string $message, string $level, array $context )` (since 9.0.0)
    Filter on every message the plugin writes to the WooCommerce log - return a modified string to rewrite it, or null/false to suppress the entry
* **smart_send_configuration_url** `( string $url )` / **smart_send_support_url** `( string $url )`
    Filters on the settings and support links shown on the WordPress plugins screen
* **ss_in_plugin_update_message** `( string $notice_html )`
    Retained legacy filter on the major-version upgrade notice in the plugins list. Return trusted, safe HTML; the filtered result is rendered as HTML. This notice filter is separate from the replaced version 8 shipping and booking hooks

= WooCommerce Subscriptions =

The renewal integration targets the documented APIs available in WooCommerce Subscriptions 4.9 and later. Subscriptions must also meet its own WordPress/WooCommerce requirements. Smart Send uses the [HPOS-compatible data-copy hooks](https://developer.woocommerce.com/2023/03/07/woocommerce-subscriptions-hpos-understanding-next-steps/), without the deprecated SQL-query filters or pre-2.0 compatibility branch.

Renewals retain pickup-point configuration and receive their own shipping labels: previous shipment IDs, booking history and WooCommerce Shipment Tracking entries are excluded from copied metadata. Notes and document files are not duplicated by this integration.

A subscription's parcel plan must reference that subscription's own order-item IDs. During renewal, the exact source item identities are carried through the item-copy hook and replaced with the renewal's new IDs; products and line positions are never used to guess correspondence. Temporary item markers are removed afterward. If a plan is old, stale or cannot be matched unambiguously, the renewal requires "Reset to one parcel" and a new allocation before booking. Pickup-point data remains available. Parcel weights and dimensions remain per booking.

= Meta fields =

The following meta fields are used by the plugin. Version 9 changes the parcel allocation format described below. The other meta keys and stored formats remain unchanged, including the pickup point selection under **ss_shipping_order_agent_no** (the pickup point number) and **_ss_shipping_order_agent** (the stored pickup point object). Read and write them through the hooks above rather than directly where you can (`smart_send_delivery_details` sees the pickup point and parcel split; `smart_send_order_fulfilled` sees the booked shipment ids):

* **smart_send_shipping_method**
    Shipping item meta storing the Smart Send shipping method used when generating shipping labels (copied from the rate's meta at checkout)
* **smart_send_return_method**
    Shipping item meta storing the method used when generating return shipping labels
* **smart_send_auto_generate_return_label**
    Shipping item meta storing whether a return label is automatically created together with the shipping label
* **ss_shipping_order_parcels**
    Stores the canonical parcel plan: `array('specs' => array(array('reference' => '1', 'weight' => null, 'length' => null, 'width' => null, 'height' => null, 'items' => array(array('order_item_id' => 123, 'quantity' => 2, 'name' => 'Example item')))))`. Order-item IDs are local to the order. Weights and dimensions are per booking: the repository strips them to null when saving the plan. An empty plan clears the stored split. Old product-ID `id`/`name`/`value` rows are not read or migrated; use "Reset to one parcel" and enter the allocation again before booking. An allocation that no longer matches the order requires the same explicit reset.
* **ss_shipping_order_agent_no**
    Used for storing the id of the selected pickup point
* **_ss_shipping_order_agent**
    Hidden field used for storing the address of the selected pickup point
* **_ss_shipping_label_id**
    Hidden field used for storing the unique Smart Send id of the generated shipping label
* **_ss_shipping_return_label_id**
    Hidden field used for storing the unique Smart Send id of the generated return shipping label
* **_ss_shipping_labels** (since 9.0.0)
    Hidden field holding a chronological list of the 50 most recently booked labels for the order - one row per shipment with its direction, Smart Send shipment id and booking time - which the order screen shows as "Booked shipments"
* **_ss_hs_code**
    Hidden field used to store the customs HS code for products in WooCommerce
* **_ss_customs_desc**
    Hidden field used to store the customs description for products in WooCommerce
* **_ss_country_of_origin**
    Hidden field used to store the country of origin for products in WooCommerce

The global setting "Order status after label" is stored under the settings key `smart_send_shipping_order_status`; its value is what `smart_send_fulfillment_order_status` receives by default.

== Frequently Asked Questions ==

= Why are no pickup point shown at checkout? =
Make sure, that the selected shipping method is "Select Pickup Point".

= Info box: Shipping to closest pickup point =
This box appears when a "Select Pickup Point" shipping method is selected, but no pickup points were found. Check that the entered shipping address is valid, that pickup points are possible in the selected region and that a valid API Token is entered in the plugins settings.

= Info box: Enter shipping information =
This box appears when a "Select Pickup Point" shipping method is selected, but no shipping address is entered. Enter a valid shipping address so that the plugin can search for nearby pickup points.

= Can I create a label for an order placed with another shipping method? =
Yes. Open the order and use the Smart Send box: when the order has no Smart Send shipping method (for example a Flat rate order), the box says so ("Shipping method is not from the Smart Send plugin.") and the shipping method row reads "None". Press its Edit link, choose the Smart Send method to ship with and create the label - the method applies to that label only and is not stored on the order.

= Does the plugin work with the WooCommerce Checkout Block and HPOS? =
Yes. Pickup point selection works in both the classic checkout and the WooCommerce Checkout Block, and the plugin is compatible with High-Performance Order Storage (HPOS).

= I used Smart Send hooks or filters in version 8. Do they still work in version 9? =
Several hooks were removed or changed in version 9, while others remain available. Review every custom snippet against the Developers section and the "9.0.0" entry under Upgrade Notice before upgrading.

= Are the plugin settings deleted when I deactivate or uninstall the plugin? =
No - this is by design. Neither deactivating nor uninstalling the plugin deletes its settings (the API Token, the general settings or the configured shipping methods), so deactivating and re-activating - or removing and re-installing - the plugin brings it back exactly as it was configured. If you want to start over, clear the fields on the settings pages manually before saving.

== Screenshots ==

1. Show closest pickup points during checkout
2. Create shipping labels from the order screen - change the shipping method, pickup point and parcels before booking, with recent booked labels listed under "Booked shipments"
3. Once booked, the box confirms the shipment, links to it in the Smart Send app and lists the parcels with their tracking numbers, weight and dimensions
4. Booking errors are shown on the field they belong to, with a response ID for support
5. Add shipping methods to WooCommerce Shipping Zones
6. Connect WooCommerce to Smart Send by entering the API Token


== Changelog ==

= 9.0.0 =
* Complete rewrite of the plugin, now built around a clear separation between fulfillment (deciding what ships and how, and updating the WooCommerce order) and booking (ordering the shipment from the carrier)
* PHP classes now use Smart_Send namespaces, WordPress naming conventions and a bundled autoloader; no Composer installation is required
* Revised hook and filter API (smart_send_*) for every stage: shipping methods at checkout, fulfillment and booking. Several version 8 hooks were removed or changed; review custom snippets against the Developers section
* Support for the WooCommerce Checkout Block: pickup point selection now works in the block-based checkout as well as the classic checkout
* Validate pickup points against their carrier and country, preserve explicit choices when nearest results refresh, and resolve missing caches through the API before saving an order or booking a label
* Use smart_send_pickup_point_list to filter, reorder or limit pickup points before caching and checkout display
* Use the plain-text smart_send_pickup_point_label filter for pickup labels; remove the pre-release option-label and default-selection filters
* Support for High-Performance Order Storage (HPOS)
* Check permissions before pickup-point custom-field lookups or changes, reject metadata rows belonging to another order, and persist pickup-point deletion with both order storage backends
* Rebuilt order-screen meta box: books without a page reload, lets you change the shipping method, pickup point and parcels (weight and dimensions per box) before booking, and books orders placed with another shipping method. A booking is confirmed right in the box - the shipment with a link into the Smart Send app, every parcel with its tracking number, weight and dimensions, and the documents - and the newest 50 booked labels appear under "Booked shipments", with existing older shipment IDs still accessible; the form stays open for further bookings
* Require confirmation before repeating any requested shipping or return label, including combined bookings; a stale order screen can confirm after the server reports an existing label
* Save booking notes through WooCommerce's order-note API and show them in its native history after a reload, while the Smart Send box confirms the booking immediately
* Render pickup-point addresses as readable text in plain-text order emails and escaped HTML in HTML emails and customer order pages
* Preserve other plugins' redirect URLs and result parameters when they handle bulk order actions
* Use WooCommerce Subscriptions 4.9+ renewal data hooks, exclude previous labels/history/tracking, and rebind parcel allocations to the renewal's new order-item IDs; require an explicit reset when correspondence cannot be established
* Parcel allocations now use WooCommerce order-item IDs, keeping repeated purchases of the same product separate and requiring every ordered unit to be allocated exactly once. Item quantities and discounted amounts are aggregated per line and parcel, with rounding that preserves line totals
* Replace the old product-ID parcel split format with a canonical parcel plan. Existing splits must be reset and entered again; parcel weights and dimensions remain per booking
* Deleted products and variations display as "Deleted" without a SKU. Existing booked labels remain accessible; new bookings require an explicit parcel weight when a product's weight is unavailable
* Read and save product customs fields through WooCommerce product objects; preserve quoted text and leading-zero HS codes, ignore malformed inputs, and keep variation overrides intact
* New filter smart_send_fulfillment_shipping_methods: restrict the shipping methods the order screen's "Smart Send" box offers in its method drop-downs, per order and per direction - see the Developers section
* Minimum required WordPress version raised to 6.5
* Minimum required WooCommerce version raised from 4.7 to 8.2
* Show a dependency notice without loading WooCommerce integrations when WooCommerce is missing or older than 8.2
* Minimum required PHP version is 7.4 (unchanged since 8.2.0)
* Tested with WordPress 7.1 and WooCommerce 11.1
* The plugin is renamed from "Smart Send Logistics" to "Smart Send" (the plugin slug smart-send-logistics is unchanged, so updates arrive as before)

= 8.2.0 =
* Tested with WordPress 7.0
* Tested with WooCommerce 11.0
* Minimum required PHP version raised from 5.6 to 7.4 (sites on older PHP will not be offered this update)
* Fix fatal error "Call to a member function get_meta() on bool" when order hooks run without a real order, e.g. the WooCommerce email preview
* Fix "_load_textdomain_just_in_time was called incorrectly" notice on WordPress 6.7+ by deferring early translation calls
* Fix incorrect import that could break instanceof checks in the order handling class
* Fix type error in the bundled API client when adding a single item to a parcel
* Replace deprecated WC()->cart->tax_display_cart with WC()->cart->get_tax_price_display_mode() (thanks @Saggre)
* PHP 8.1-8.4 compatibility: fix dynamic property creation and null passed to strpos()

= 8.1.3 =
* Fix issue when shipping cost is a string instead of a number (WC_Shipping_Rate::get_cost() can from WooCommerce 9.9.3 be a string)

= 8.1.2 =
* Gracefully handle when order cannot be loaded during deletion of agent meta data

= 8.1.1 =
* Tested with WordPress 6.8
* Tested with WooCommerce 9.7
* Fixing issue that order mass actions were missing on non-HPOS sites
* Removing PHP warning

= 8.1.0 =
* Add High-Performance Order Storage (HPOS) compatibility

= 8.0.27 =
* Remove PostNord EMS shipping method
* Add PostNord Tracked Letter shipping method

= 8.0.26 =
* Add carrier Burd
* Add carrier Budbee
* Add PostNord methods: International Express Mail (EMS), Express Letter and Speciel size pallet

= 8.0.25 =
* Fix issue with missing receiver phone on some WooCommerce versions (v5.6+)

= 8.0.24 =
* Add filter smart_send_sslverify to fix ssl issues on older servers with incorrect SSL libraries

= 8.0.23 =
* Add WordPress 5.7 support
* Add WooCommerce 5.1 support
* Add new DAO methods: dropoffagent, dropoffdoorstep

= 8.0.22 =
* Add WooCommerce 4.2-5.0 support
* Upated Bifrost shipping methods

= 8.0.21 =
* Add WooCommerce 4.1 support
* Add WooCommerce 4.2 support

= 8.0.20 =
* Add PostNord pallet shipping methods. Full size pallet, Half size pallet and Quarter size pallet.

= 8.0.19 =
* Bugfix: Order page failed when purchased products had been deleted

= 8.0.18 =
* Add extra info about cart content to debug log

= 8.0.17 =
* Add hidden product meta field **_ss_country_of_origin** used for custom declarations

= 8.0.16 =
* Bugfix: Change unique shipping code used for PostNord: Untracked letter

= 8.0.15 =
* Add new PostNord shipping methods: Valuable parcel, Registred letter, Tracked letter, Untracked letter
* Add field name to error message when failing to create shipping labels
* Add support for using multiple API Tokens on one site (useful for WPML and other plugins)
* Update PostNord shipping method order
* Remove input field to change pickup point while creating a label
* Show upgrade notices in Wordpress Plugin list
* Bugfix: Drop usage of deprecated methods get_order_currency() and get_total_shipping()
* Bugfix: Order status was changed before saving meta data, tracking data and other important information

= 8.0.14 =
* Bugfix: Invalid API endpoint for old cURL versions

= 8.0.13 =
* Bugfix: City was not used when looking for closest pickup points
* Change from cURL to wp_remote_request

= 8.0.12 =
* Add city to request when searching for closest agents for improved accuracy
* Change WooCommerce minimum requirement to WC 3.0

= 8.0.11 =
* Bugfix: PHP error when using name_line2 field for WooCommerce orders
* Bugfix: PHP error for older PHP versions
* Change WooCommerce minimum requirement to WC 2.7

= 8.0.10 =
* Add convenience wrapper for pickup point function
* Add PostNord shipping method: Private delivery to address Small (MyPack Home Small)

= 8.0.9 =
* Bugfix: Link to PDF label not always formatted as link
* Change width of agent select box on checkout page
* Add meta box to orders without a Smart Send shipping method

= 8.0.8 =
* Add DAO shipping methods
* Add filter for receiver address
* Add option if PDF labels should be saved in the WordPress Uploads folder
* Add PostNord Untracked Valuemail shipping methods
* Rename PostNord Tracked Valuemail shipping methods
* Show shipping method id and instance id on order page if debug is enabled

= 8.0.7 =
* Add order weight to Smart Send meta box on admin order page
* Bugfix: Some translation plugins caused the pickup point to not display properly

= 8.0.6 =
* Add support for extra shipping methods from the plugin: vConnect PostNord Delivery Checkout

= 8.0.5 =
* Bugfix: Show selected pickup point on order confirmation page and confirmation email
* Changing default setting whether or not to include order comment on shipping labels
* Make label links open in a new tab
* Add carrier Bifrost Logistics

= 8.0.4 =
* Add a help text to action buttons when operating in demo demo
* Fix unexpected error when no API Token is entered in the plugin settings

= 8.0.3 =
* Fix problem with pickup point format

= 8.0.2 =
* Fix problem with demo-mode disabling not working

= 8.0.1 =
* Add error when trying to validate an empty API Token
* Add setting to auto sort shipping methods by cost on checkout page

= 8.0.0 =
* Completely refactoring of plugin
* Using Shipping Zones instead of WooCommerce legacy shipping API
* Plugin is not backwards compatible. All settings must be setup from scratch
* Separates standard settings from the more advanced settings for simplicity
* Includes more information about pickup points in checkout page
* Limit shipping methods by weight, price, user role, shipping zone, shipping class and much more

= 7.2.0 =
* Update API endpoint

= 7.1.18 =
* Fix for international delivery with vConnect All in 1 plugin to PostNord

= 7.1.17 =
* Fix breaking change in WooCommerce 3.4.x: Shipping Rate method_id is used instead of the id when saving shipping methods.

= 7.1.16 =
* Minor fixes
* Add video to readme file
* Add WooCommerce requirements

= 7.1.15 =
* Fix issue with unknown shipping method for PostNord Valuemailsmall

= 7.1.14 =
* Fixing issue with local pickup shipping method being intrepretered as Bring pickup
* Fix help text under shipping table, explaining about tax settings

= 7.1.13 =
* Updating PostNord tracking link used for Shipment Tracking
* Changing API booking endpoint
* Adding support for vConnect All-in-1 module v2.x

= 7.1.12 =
* Changing API booking endpoint
* Add cURL error description if no response from server

= 7.1.11 =
* Fixing problem with missing file for version 7.1.10

= 7.1.10 =
* Fixing PHP notification for WooCommerce 3.0+
* Fixing problem fetching pickup point data for some installations
* Adding compatibility for WooCommerce 2.5+
* Adding cURL timeout to API calls

= 7.1.9 =
* Adding compatibility with WooCommerce 3.1.0
* Adding shipping method 'Post Danmark Valuemail small'
* Fixing problem with setting whether or not to include order comment on labels.
* Fixing PHP notifications

= 7.1.8 =
* Fixing problem with WooCommerce Shipment Tracking version 1.6.4

= 7.1.7 =
* Compatible with WooCommerce 3
* Updating Post Danmark tracking url
* Updating Posten tracking url
* Updating Post Danmark tracking url
* Updating Posten tracking url

= 7.1.6 =
* Show pickup dropdown under shipping method (supported by WooCommerce 2.5+).
* Adding support for WooCommerce Subscriptions.
* Performance improvement: Not using sessions when showing notifications.
* Performance improvement: Only making API calls when valid input parameters presented.
* Adding Wordpress filters for cart subtotal and cart weight.

= 7.1.5 =
* Fixing problem with shipment weight when unit was gram.

= 7.1.4 =
* Fix problem with shipping method Free Shipping for WooCommerce 2.6

= 7.1.3 =
* Compatible with Wordpress 4.6
* Fix problem with vConnect All-in-one support

= 7.1.2 =
* Implementing support for vConnect WooCommerce 2.6 plugin
* Minor bugfixes
* Adding help text about the unit of weight used by WooCommerce

= 7.1.1 =
* Implementing support for Free Shipping in WooCommerce 2.6
* Adding more options to the flex delivery dropdown
* Fixing error with showing Pacsoft label print links
* Fixing error with shipping method display format
* Fixing error with translation of flex delivery methods
* Catching errors for unknown shipping methods

= 7.1.0 =
* WooCommerce 2.6 compatible
* Multisite compatible
* Adding Flexdelivery option for Post Danmark
* Adding the possibility to exclude private shipping methods from TAX for Post Danmark
* Adding the possibility to show dropdown of pickup points for WooCommerce Free shipping
* Adding setting to change order status once a label is created
* Adding more frontend display formats for shipping methods
* Adding the possibility to change shipping method from backend
* Calculate order price criteria for shopping cart total including tax
* Removed carrier ‘Pickuppoint’ since this was often misunderstood. Pickup methods are set under each carrier separately.
* Trim leading hashtags from order number for support for older WooCommerce installations
* Settings moved to separate WooCommerce tab
* Setting whether or not to include order comment on shipping label
* Interprete a star (*) as all the countries given in the general shipping settings of WooCommerce and not just all countries
* Fixing problem with shipping classes

= 7.0.17 =
* Adding support for WooCommerce Sequential Order Numbers
* Minor bugfixes
* Adding notification function to notify about major updates
* Showing correct order numbers in succes/error messages when creating a label
* Remove text above frontend-dropdown showing pickup points

= 7.0.16 =
* Change layout of pickup point dropdown menu. Now works with SSL.
* Fixing PHP error when updating WooCommerce plugin
* Add order comment when creating label

= 7.0.15 =
* Fixing problem with missing arrow on dropdown menu
* Add Bring shipping method ‘Miniparcel’
* Add Post Danmark shipping method ‘Business Priority’
* Adding ‘Date shipped’ and removing unintended comma in tracking number when using Shipment Tracking plugin
* Formatting dropdown menu in settings
* Adding support for WooCommerce shipping method ‘Free shipping’
* Track and Trace codes are now added correctly to the order if multiple labels are create with one action
* Fixing problem with entering ‘*’ as all countries in the table settings
* Fixing incorrect weight if gram is used for product weight

= 7.0.14 =
* Add support for plugin WooCommerce Sequential Order Numbers
* Adding Bring shipping methods 'express' and 'bulksplit'
* Fixing PHP notification problem caused by missing classes for default shipping methods.

= 7.0.13 =
* Tested with WordPress 4.5
* Tested with WooCommerce 2.5
* Fixing PHP notification when clearing table rates
* Fixing PHP notification causing JavaScript error when adding/deleting table rates with debug activated.
* Fixing checkout error message if no pickup point is choosen
* Adding Post Danmark shipping method ‘Last mile’ for food delivery
* Updating pickup point dropdown if zip code is changed during checkout
* Changing the default shipping table rates installed when module is activated

= 7.0.12 =
* Fixing Danish (DK) translation problems
* Adding flex delivery support for vConnect module

= 7.0.11 =
* Adding Track&Trace links to order
* Fixing problem with service Prenotification

= 7.0.10 =
* Fixing problem where the billing address was used for vConnect shipping methods other than pickup
* Fixing small PHP notification

= 7.0.9 =
* Adding method to create a normal and a return label at the same time
* Adding support for vConnect All-in-one module
* A few PHP fixes

= 7.0.8 =
* Cleaning up settings
* Fixing problem with country when adding a new table rate
* Fixing problem with pickup dropdown only visible for shipping country Denmark
* Fixing problem with label generation for pickup shipping methods, when using order grid actions
* If maximum weight or price is empty in table rate table then take it as infinity
* Only install shipping methods ‘Pickup’ and ‘Private’ when installing the plugin
* Remove carrier SwipBox
* Adding Danish translation

= 7.0.7 =
* Fixing error when using vConnect checkout module
* Adding Post Danmark shipping methods; Post Danmark Privatpakker Norden Samsending, Post Danmark Parcel Economy and Post Danmark Private Priority
* Renaming shipping methods in table rate dropdown

= 7.0.6 =
* Adding support of WooCommerce 2.4
* Adding return labels
* Adding waybills
* Adding support for Shipment Tracking
* Changing standard value for settings
* Updating class files

= 7.0.5 =
* Fixing error with the possibility to place pickup point dropdown using custom hook
* Use live environment instead of development (by mistake)
* Fixing problem when no pickup points are found

= 7.0.4 =
* Fixing problem with CSS for pickup point dropdown
* Fixing problem when shipping and billing country is not the same
* Adding the possibility to place pickup point dropdown using custom hook

= 7.0.3 =
* Initial release for Wordpress.org

== Upgrade Notice ==

= 9.0.0 =
Version 9 is a complete rewrite of the plugin. Make a full site backup and [review update best practices](https://woocommerce.com/document/how-to-update-your-site/) before upgrading from 8.x. Existing settings, shipping methods, pickup points and booked-label access are kept. Saved parcel splits require the reset described below.

* If your site uses Smart Send hooks or filters (custom code, a Code Snippets plugin or a theme), review every snippet: several version 8 hooks were removed or changed, and shipment/pickup hooks now pass typed objects. Other hooks retain their existing signatures. The Developers section lists the current hooks, removed hooks, arguments and examples. Update affected snippets and test them on a staging site before upgrading production.
* For snippets using pre-release version 9 hooks, rename `smart_send_pickup_point_option_label` to `smart_send_pickup_point_label` and return plain text. `smart_send_default_selected_pickup_point` is removed without an alias: reorder `smart_send_pickup_point_list` results and use the **Select Default** setting instead. Compatible explicit customer choices are preserved. Older stored points missing carrier/country information are verified through the API when next used.
* Parcel splits saved in the old product-ID format are not migrated. On affected orders, choose "Reset to one parcel" and enter the allocation again before creating another shipping or return label. Custom integrations must use `order_item_id` and allocate every ordered unit exactly once. Parcel weights and dimensions still apply only to the current booking.
* Products or variations deleted since the order was placed display as "Deleted" with no SKU. Enter an explicit parcel weight before booking them; missing customs data is not reconstructed. This does not remove access to labels already booked.
* Requires WordPress 6.5, WooCommerce 8.2 and PHP 7.4 or newer. Sites on older versions should stay on the 8.x series.
* The optional WooCommerce Subscriptions integration now targets its 4.9+ data-copy APIs. Renewals do not inherit prior labels or tracking. Custom subscription parcel plans must use the subscription's own item IDs; old or unmatched plans require an explicit reset on the renewal before booking.
* Free shipping no longer makes a shipping method available for a cart weight outside the configured weight table; the weight table alone decides when the method is offered, and free shipping only zeroes the price. Check your weight tables if you relied on the free-shipping threshold to cover heavy carts.
* The bulk label actions on the Orders screen process one selected order at a time. Bulk printing of several orders returns in a later 9.x release; if you need it now, stay on version 8.x.

= 8.0 =
8.0 is a major update. Shipping methods moved to WooCommerce Shipping Zones and must be setup again after upgrading. Make a full site backup, and [review update best practices](https://docs.woocommerce.com/document/how-to-update-your-site) before upgrading.

= 7.2 =
Version 7.1 is deprecated. Upgrading to 7.2 can be done at no risk, but is needed for continuous use of the plugin.
