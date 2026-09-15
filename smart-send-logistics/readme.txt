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
Create shipping labels with a single click from the WooCommerce order screen. The order data is sent to the carrier, and the PDF label is ready to print right away. Tracking numbers are saved on the order, added to the order note and, with the WooCommerce Shipment Tracking plugin, shown to the customer.

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
6. Once the API Token is saved, press 'Validate API Token' to connect your WooCommerce store to Smart Send.

== Developers ==

The plugin has a formal extension API of `smart_send_*` hooks: filters for values, actions for events. Extend the plugin through these hooks instead of patching it - the names and signatures are stable, and they always pass typed value objects (never raw Smart Send API request or response shapes), so a snippet keeps working when the plugin moves to a newer API version.

An order goes through three stages, and each stage has its own hooks:

1. **Shipping methods at checkout** - the Smart Send shipping methods are offered as rates and, for pickup point methods, the customer picks a pickup point.
2. **Fulfillment** - the merchant creates the label: the plugin decides *what* ships and *how* (method, pickup point, parcels), calls booking, and writes the outcome onto the order (shipment id, documents, order note, tracking, status). Fulfillment has two flows - an outbound label, and a return label (created on its own or auto-generated after the outbound one).
3. **Booking** - one operation for both flows: the shipment is built from the order and the fulfillment decision, sent to the Smart Send API, and the result comes back as a booked shipment.

= 1. Shipping methods at checkout =

Rates (WooCommerce-standard names):

* **woocommerce_smart_send_shipping_shipping_add_rate** `( SS_Shipping_WC_Method $method, array $rate )`
    Action after a Smart Send rate is added - add further rates next to it
* **woocommerce_shipping_smart_send_shipping_is_available** `( bool $is_available, array $package, SS_Shipping_WC_Method $method )`
    Filter to hide a Smart Send shipping method for a package
* **woocommerce_shipping_smart_send_shipping_is_free_shipping** `( bool $is_free, array $package, SS_Shipping_WC_Method $method )`
    Filter to grant or deny free shipping for a method
* **woocommerce_settings_api_form_fields_smart_send_shipping** / **woocommerce_shipping_instance_form_fields_smart_send_shipping**
    WooCommerce's own filters on the plugin's global settings fields and per-zone shipping method fields

Pickup points:

* **smart_send_pickup_point_search_params** `( array $params )` (since 9.0.0)
    Filter on the search parameters (carrier, country, postal_code, city, street) before the closest pickup points are looked up
* **smart_send_pickup_points_found** `( SS_Shipping_Pickup_Point[] $pickup_points, array $params )` (since 9.0.0)
    Filter on the pickup points found, before they are cached and rendered - return fewer to limit the choices, or re-order them. Return only `SS_Shipping_Pickup_Point` objects (`get_agent_no()`, `get_company()`, `get_address_line1()`, `get_postal_code()`, `get_city()`, `get_country()`, `get_distance()`, `get_carrier()`, `get_latitude()`/`get_longitude()`, `get_opening_hours()`, `to_array()`)
* **smart_send_pickup_point_option_label** `( string $label, SS_Shipping_Pickup_Point $pickup_point )` (since 9.0.0)
    Filter on the label of a pickup point in the checkout drop-down
* **smart_send_default_selected_pickup_point** `( string $agent_no, SS_Shipping_Pickup_Point[] $pickup_points )` (since 9.0.0)
    Filter on which pickup point is pre-selected - return the agent number of one of the list
* **smart_send_pickup_point_timeout** `( int $seconds )`
    Filter on the API timeout used when looking up pickup points

The selected pickup point is shown on the order details page and in the order emails through WooCommerce's `woocommerce_order_details_after_order_table` and `woocommerce_email_after_order_table` actions.

Example: show at most 5 pickup points, and pre-select the first one that is open on Saturdays:

    add_filter('smart_send_pickup_points_found', function (array $pickup_points, array $params) {
        return array_slice($pickup_points, 0, 5);
    }, 10, 2);

    add_filter('smart_send_default_selected_pickup_point', function ($agent_no, array $pickup_points) {
        foreach ($pickup_points as $pickup_point) {
            foreach ($pickup_point->get_opening_hours() as $interval) {
                if ($interval['day'] === 'saturday') {
                    return $pickup_point->get_agent_no();
                }
            }
        }

        return $agent_no;
    }, 10, 2);

= 2. Fulfillment =

Fulfillment runs when the merchant creates a label on the order page or uses the bulk action. For each label (outbound, and the return label when one is created) it decides the delivery details, books, and then applies the side effects on the order one step at a time. Every step has a filter, and the run ends with one action.

Deciding what ships:

* **smart_send_delivery_details** `( SS_Shipping_Delivery_Details $details, WC_Order $order, bool $is_return )` (since 9.0.0)
    Filter on the merged delivery details - the stored order configuration plus the shipping method resolved from the order - right before booking is called. This is the one place to override the shipping method (`set_shipping_method('gls_shop')`), clear or replace the pickup point (`set_pickup_point()` with a `SS_Shipping_Pickup_Point` or `null`) or declare the parcel split (`set_parcel_plan()` with a `SS_Shipping_Parcel_Plan` of `SS_Shipping_Parcel_Spec` rows - a spec may carry dimensions and an explicit weight with no item allocations at all). Return the details object.
    The details the merchant submitted in the order meta box (shipping method, pickup point, parcels) are already merged into `$details` when the filter runs - a submitted value wins over the stored one. They are stored on the order only after the booking succeeds: the submitted pickup point and the parcel item rows are written first, then the shipment id; a failed booking leaves the order meta untouched. The submitted shipping method and a parcel's weight and dimensions are per booking and are never stored. What the filter returns is what gets booked, but it is not what gets stored.
* **smart_send_parcel_default_weight** `( float $weight, SS_Shipping_Parcel_Spec $spec, WC_Order $order )` (since 9.0.0)
    Filter on the weight of a parcel that has no explicit weight: the sum of the weights of the items allocated to it (0 when the items weigh nothing or the parcel has no items). Use it to add packaging weight or apply a minimum. A parcel with an explicit weight - entered in the order meta box, or `set_weight()` on the spec in `smart_send_delivery_details` - bypasses this filter entirely

Side effects on the order, in the order they run (the submitted delivery details, then the shipment id, are always stored in order meta first):

* **smart_send_fulfillment_save_documents** `( bool $save, SS_Shipping_Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on whether a copy of the shipment's documents is saved in the uploads folder; defaults to the "Save shipping labels in uploads folder" setting. When saved, the label document's `download_url()` points at the copy. A copy that cannot be saved does not fail the label: the shipment stays fulfilled with a warning (`get_warnings($shipment)` on the result, `save_documents` = `'failed'` in `get_steps($shipment)`) and `download_url()` falls back to the Smart Send URL
* **smart_send_fulfillment_order_note** `( string $note_html, SS_Shipping_Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on the order note added once the shipment is booked (document links, codes, tracking numbers). Return an empty string to add no note. Replaces `smart_send_shipping_label_comment`
* **smart_send_fulfillment_tracking** `( bool $push, SS_Shipping_Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on whether the parcels' tracking numbers are pushed to the WooCommerce Shipment Tracking plugin; defaults to true for an outbound shipment and false for a return shipment
* **smart_send_fulfillment_order_status** `( string|false $status, SS_Shipping_Booked_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Filter on the status the order is set to (e.g. `wc-completed`), or `false` to leave it alone; defaults to the "Order status after label" setting for an outbound shipment and `false` for a return shipment

When the run is done:

* **smart_send_order_fulfilled** `( WC_Order $order, SS_Shipping_Fulfillment_Result $result )` (since 9.0.0)
    Action fired once per run, after every side effect of every label is applied, when at least one shipment was fulfilled. The result carries `shipments()`, `get_outbound_shipment()` and `get_return_shipment()` (each a `SS_Shipping_Booked_Shipment`, see Booking below), `get_order_note($shipment)`, `get_order_note_id($shipment)`, `get_steps($shipment)` (which side effects ran), `get_warnings($shipment)` and, for a leg that failed, `get_outbound_error()`/`get_return_error()` (HTML), `get_validation_errors($is_return)` and `get_error_details($is_return)` (message, Response-ID, field errors, HTML). `to_array()` is the serializable form: one row per attempted label with `direction`, `status` (`fulfilled`/`failed`), the shipment's `to_array()`, `steps`, `order_note`, `warnings` or `error`. Replaces `smart_send_shipping_label_created` (8.x), which no longer fires

Example: ship every order in two parcels of fixed size and weight, with no item allocation:

    add_filter('smart_send_delivery_details', function (SS_Shipping_Delivery_Details $details, WC_Order $order, bool $is_return) {
        $plan = new SS_Shipping_Parcel_Plan();
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(4)->set_length(30)->set_width(20)->set_height(10));
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(2.5)->set_length(15)->set_width(15)->set_height(15));

        return $details->set_parcel_plan($plan);
    }, 10, 3);

Example: override the pickup point for a specific customer:

    add_filter('smart_send_delivery_details', function (SS_Shipping_Delivery_Details $details, WC_Order $order, bool $is_return) {
        if ($order->get_billing_email() === 'vip@example.com') {
            $details->set_pickup_point(SS_Shipping_Pickup_Point::from_object(['agent_no' => '1234', 'country' => 'DK']));
        }

        return $details;
    }, 10, 3);

Example: never push tracking numbers to Shipment Tracking, and shorten the order note:

    add_filter('smart_send_fulfillment_tracking', '__return_false');

    add_filter('smart_send_fulfillment_order_note', function (string $note, SS_Shipping_Booked_Shipment $shipment, WC_Order $order) {
        return 'Smart Send shipment ' . $shipment->get_shipment_id() . ' (' . $shipment->get_tracking_code() . ')';
    }, 10, 3);

Example: send the tracking code to your own system once the order is fulfilled:

    add_action('smart_send_order_fulfilled', function (WC_Order $order, SS_Shipping_Fulfillment_Result $result) {
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

Booking is one operation for outbound and return shipments: the `SS_Shipping_Shipment` request is built from the order and the delivery details, sent to the Smart Send API, and the response is mapped to a `SS_Shipping_Booked_Shipment`. Booking never writes to the order - that happens in fulfillment.

Reading the order (the data that goes into the request):

* **smart_send_order_receiver** `( array $shipping_address, int $order_id )`
    Filter on the order's shipping address used as the receiver
* **smart_send_receiver_phone** `( string|null $phone, WC_Order $order )` (since 9.0.0)
    Filter on the receiver phone number used for the label and the carrier's SMS notification
* **smart_send_payload_receiver** `( array $receiver, WC_Order $order )` (since 9.0.0)
    Filter on the receiver data read from the order
* **smart_send_payload_items** `( array $items, WC_Order $order )` (since 9.0.0)
    Filter on the item lines read from the order
* **smart_send_payload_totals** `( array $totals, WC_Order $order )` (since 9.0.0)
    Filter on the order totals read from the order
* **smart_send_shipment_freetext** `( string|null $freetext, WC_Order $order )` (since 9.0.0)
    Filter on the freetext printed on the label (the customer's order comment when the "Include order comment" setting is on). Replaces `smart_send_order_note`

The request and the result:

* **smart_send_booking_request** `( SS_Shipping_Shipment $shipment, WC_Order $order, bool $is_return )` (since 9.0.0)
    Filter on the complete shipment about to be booked - receiver, pickup point, parcels with item lines, amounts - right before it is sent. Return the shipment
* **smart_send_booking_completed** `( SS_Shipping_Booked_Shipment $booked, SS_Shipping_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Action when the API booked the shipment, before anything is written to the order
* **smart_send_booking_failed** `( SS_Shipping_Booking_Exception $exception, SS_Shipping_Shipment $shipment, WC_Order $order )` (since 9.0.0)
    Action when the API rejected the shipment, right before the exception is thrown. `$exception->getMessage()` is the API message, `errors()` the per-field validation errors (field => list of messages), `response_id()` the Smart Send Response-ID for support, `getPrevious()` the API client exception

The booked shipment (`SS_Shipping_Booked_Shipment`) carries `get_shipment_id()`, `get_carrier()` (the carrier code, e.g. `postnord`), `get_service_code()`, `is_return()`, `get_state()`, `get_booked_at()`, shipment-level `get_tracking_code()`/`get_tracking_url()`, `parcels()` (`SS_Shipping_Booked_Parcel`: parcel id, tracking code and URL), `documents()` (`SS_Shipping_Shipment_Document`: type such as `label` or `customs_declaration`, format such as `pdf` or `zpl`, layout, `get_url()`, and `get_local_url()`/`get_local_path()` when fulfillment stored a copy - `download_url()` prefers that copy) and `codes()` (`SS_Shipping_Shipment_Code`: type such as `qr_code`, value, image URL, expiry, instructions). Documents and codes are lists on the shipment, never on a parcel - do not assume one PDF; `label_document()` is a shortcut to the first label document, or null. `to_array()`/`from_array()` round-trip every field. Today (API v1) a booking yields exactly one `label`/`pdf` document and no codes; API v2 will add QR codes, label codes and ZPL/customs documents without changing this contract.

Example: react to a completed booking and to a rejected one:

    add_action('smart_send_booking_completed', function (SS_Shipping_Booked_Shipment $booked, SS_Shipping_Shipment $shipment, WC_Order $order) {
        error_log(sprintf('Order %d booked as %s with %d parcel(s)', $order->get_id(), $booked->get_shipment_id(), count($booked->parcels())));
    }, 10, 3);

    add_action('smart_send_booking_failed', function (SS_Shipping_Booking_Exception $exception, SS_Shipping_Shipment $shipment, WC_Order $order) {
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

= Meta fields =

The following meta fields are used by the plugin. The order meta keys and their stored formats are a stable public contract - in particular the pickup point selection under **ss_shipping_order_agent_no** (the pickup point number) and **_ss_shipping_order_agent** (the stored pickup point object). Read and write them through the hooks above rather than directly where you can (`smart_send_delivery_details` sees the pickup point and parcel split; `smart_send_order_fulfilled` sees the booked shipment ids):

* **smart_send_shipping_method**
    Shipping item meta storing the Smart Send shipping method used when generating shipping labels (copied from the rate's meta at checkout)
* **smart_send_return_method**
    Shipping item meta storing the method used when generating return shipping labels
* **smart_send_auto_generate_return_label**
    Shipping item meta storing whether a return label is automatically created together with the shipping label
* **ss_shipping_order_parcels**
    Used for storing information how the orders items are split into parcels
* **ss_shipping_order_agent_no**
    Used for storing the id of the selected pickup point
* **_ss_shipping_order_agent**
    Hidden field used for storing the address of the selected pickup point
* **_ss_shipping_label_id**
    Hidden field used for storing the unique Smart Send id of the generated shipping label
* **_ss_shipping_return_label_id**
    Hidden field used for storing the unique Smart Send id of the generated return shipping label
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
Yes. Open the order and use the Smart Send box: when the order has no Smart Send shipping method (for example a Flat rate order), the box says so and offers a shipping method drop-down. Choose the Smart Send method to ship with and create the label - the method applies to that label only and is not stored on the order.

= Does the plugin work with the WooCommerce Checkout Block and HPOS? =
Yes. Pickup point selection works in both the classic checkout and the WooCommerce Checkout Block, and the plugin is compatible with High-Performance Order Storage (HPOS).

= I used Smart Send hooks or filters in version 8. Do they still work in version 9? =
No. Version 9 is a complete rewrite, and the hook and filter API is new. See the Developers section for the current hooks and the "9.0.0" entry under Upgrade Notice.

= Are the plugin settings deleted when I deactivate or uninstall the plugin? =
No - this is by design. Neither deactivating nor uninstalling the plugin deletes its settings (the API Token, the general settings or the configured shipping methods), so deactivating and re-activating - or removing and re-installing - the plugin brings it back exactly as it was configured. If you want to start over, clear the fields on the settings pages manually before saving.

== Screenshots ==

1. Show closest pickup points during checkout
2. Create shipping labels from the order screen - change the shipping method, pickup point and parcels before booking
3. See the booked label's documents and tracking right on the order, with an order note added automatically
4. Booking errors are shown on the field they belong to, with a response ID for support
5. Add shipping methods to WooCommerce Shipping Zones
6. Connect WooCommerce to Smart Send by entering the API Token


== Changelog ==

= 9.0.0 =
* Complete rewrite of the plugin, now built around a clear separation between fulfillment (deciding what ships and how, and updating the WooCommerce order) and booking (ordering the shipment from the carrier)
* New hook and filter API (smart_send_*) for every stage: shipping methods at checkout, fulfillment and booking. The version 8 hooks and filters no longer work - see the Developers section
* Support for the WooCommerce Checkout Block: pickup point selection now works in the block-based checkout as well as the classic checkout
* Support for High-Performance Order Storage (HPOS)
* Rebuilt order-screen meta box: books without a page reload, lets you change the shipping method, pickup point and parcels (weight and dimensions per box) before booking, and books orders placed with another shipping method
* Minimum required WordPress version raised to 6.5
* Minimum required WooCommerce version raised from 4.7 to 8.2
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
Version 9 is a complete rewrite of the plugin. Make a full site backup and [review update best practices](https://woocommerce.com/document/how-to-update-your-site/) before upgrading from 8.x. Existing settings, shipping methods and order data are kept.

* If your site uses Smart Send hooks or filters (custom code, a Code Snippets plugin or a theme), that code must be updated: the version 8 hooks and filters no longer fire, and the new hook API passes typed objects instead of raw data. The Developers section lists every hook, its arguments and examples. Test your snippets on a staging site before upgrading production.
* Requires WordPress 6.5, WooCommerce 8.2 and PHP 7.4 or newer. Sites on older versions should stay on the 8.x series.
* Free shipping no longer makes a shipping method available for a cart weight outside the configured weight table; the weight table alone decides when the method is offered, and free shipping only zeroes the price. Check your weight tables if you relied on the free-shipping threshold to cover heavy carts.
* The bulk label actions on the Orders screen process one selected order at a time. Bulk printing of several orders returns in a later 9.x release; if you need it now, stay on version 8.x.

= 8.0 =
8.0 is a major update. Shipping methods moved to WooCommerce Shipping Zones and must be setup again after upgrading. Make a full site backup, and [review update best practices](https://docs.woocommerce.com/document/how-to-update-your-site) before upgrading.

= 7.2 =
Version 7.1 is deprecated. Upgrading to 7.2 can be done at no risk, but is needed for continuous use of the plugin.
