=== Smart Send Logistics ===
Contributors: SmartSend
Donate link: https://smartsend.io/
Author: SmartSend
Author URI: https://smartsend.io/
Developer: SmartSend
Developer URI: https://smartsend.io/
Tags: shipping, pickup-points, shipping-label, postnord, smart send
Requires at least: 3.0.1
Tested up to: 7.0
Stable tag: 8.2.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce
WC requires at least: 3.0.0
WC tested up to: 11.0
Requires PHP: 7.4

Complete WooCommerce shipping solution for PostNord, GLS, DAO, Burd, Budbee and Bring.

== Description ==

Complete shipping solution for PostNord, GLS, DAO, Budbee, Burd and Bring. Setup shipping methods with rates calculated based on products, shipping address, weight, subtotal, user roles, shipping classes and much more. Show pickup points to the customer during checkout and create shipping labels directly from the WooCommerce admin panel.

From now on, everything is incorporated directly into your WooCommerce store.

Supported carriers:

* GLS (YourGLS)
* Bring (MyBring)
* Post Nord (Posten / Post Danmark)
* DAO
* Burd
* Budbee

Supports worldwide shipping from these countries:

* Denmark
* Sweden
* Finland
* Norway

= Shipping method =
Shipping methods are setup in WooCommerce Shipping Zones and the shipping cost can be calculated based on a range of criteria:

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
* Handling of special good, eg food
* TAX handling
* Enable free delivery based on condition

= Pickup point =
Let the customer choose a pickup point close to them during checkout. The package will be delivered to the selected pickup point, where the customer can collect the package at their own convenience.

* Nearest pickup points based on entered shipping address
* Automatically updated list
* User friendly dropdown list
* One step/page checkout compatible

Shipping to pickup points are the most widely used shipping method due to it's flexibility and the reduced shipping cost.

= Shipping labels =
Create shipping labels directly from the backend by a single click. The information is automatically formatted and send to the carrier for processing. A PDF label is immediately shown and ready to print. Tracking information is automatically saved in the system and can be included in customer emails or can be sendt by text message.

Easily create:

* Shipping labels as PDF files
* Return shipping labels
* Tracking information

[youtube https://www.youtube.com/watch?v=Vl_rPb-t8xE]

This plugin replaces the two previous modules *Smart Send Labelgenerator* and *Smart Send Pickup Shipping*.

== Installation ==

See our online installation guide at [https://smartsend.io](https://smartsend.io/woocommerce/configuration), or follow these steps:

1. Log in to the WordPress dashboard
2. Navigate to the Plugin menu
3. Click 'Add New' in the Plugin sub-menu
4. Enter 'Smart Send Logistics' in the search field and click 'Search Plugins'
5. Click the 'Install Now'-button
6. Once the plugin is installed, click the 'Activate Plugin' link to active the plugin
7. The plugin is installed, activated and ready to use once you see the succes message 'Plugin activated' at the top of the plugin page

= Connect the plugin to Smart Send using an API Token =

The plugin must be connected to Smart Send for all functions to work properly. You can create a [Smart Send account here](https://smartsend.io/signup)

[youtube https://www.youtube.com/watch?v=wyJYbwwI0h8]

See our written guide on our [Smart Send website](https://smartsend.io/woocommerce/api-token/) or followed these steps:

1. Log in to the WordPress dashboard
2. Choose 'WooCommerce' in the menu to the left and select 'Settings'
3. Choose the 'Shipping' tab in the top menu bar
4. Click on 'Smart Send' in the list under the tabs
5. Enter the API Token you received in your welcome email and click save. Signup [here](https://smartsend.io/woocommerce/api-token/) to get an API Token.
6. Once the API Token is saved, press 'Validate API Token' to connect your WooCommerce store to Smart Send.

== Developers ==

The plugin implements a formal extension API of `smart_send_*` hooks: filters for values, actions for events. Extend the plugin through these hooks instead of patching it - the names and signatures are stable.

= API connection =

* **smart_send_api_endpoint**
    A filter to change the API endpoint the plugin talks to, e.g. to point at the Smart Send sandbox environment
* **smart_send_sslverify**
    A filter to disable SSL certificate verification for API requests (only for local development)
* **smart_send_pickup_point_timeout**
    A filter to change the timeout (seconds) used when searching for pickup points on the checkout page

= Pickup point lookup and selector (checkout) =

* **smart_send_pickup_point_search_params** (since 9.0.0)
    A filter on the search parameters (carrier, country, postal_code, city, street) used to look up the closest pickup points, before the API call is made
* **smart_send_pickup_points_found** (since 9.0.0)
    A filter on the list of pickup points returned by the lookup, before it is cached in the session and rendered - return fewer entries to limit the choices, or re-order them
* **smart_send_pickup_point_option_label** (since 9.0.0)
    A filter on the label shown for each pickup point in the checkout drop-down
* **smart_send_default_selected_pickup_point** (since 9.0.0)
    A filter on which pickup point is pre-selected in the checkout drop-down - return the agent_no of one of the found pickup points

Example: show at most 5 pickup points and pre-select the closest one:

    add_filter('smart_send_pickup_points_found', function ($pickup_points, $search_params) {
        return array_slice($pickup_points, 0, 5);
    }, 10, 2);

    add_filter('smart_send_default_selected_pickup_point', function ($default_pickup_point_no, $pickup_points) {
        return $pickup_points ? $pickup_points[0]->agent_no : $default_pickup_point_no;
    }, 10, 2);

= Shipping label creation =

* **smart_send_delivery_details** (since 9.0.0)
    A filter on the merged `SS_Shipping_Delivery_Details` value object (shipping method, pickup point, parcel plan) right before a shipping label is booked. Receives the details, the `WC_Order` and whether the label is a return label; return the (modified) details object. This is the one extension point for overriding the shipping method, clearing or replacing the pickup point (`SS_Shipping_Pickup_Point`), or declaring a parcel plan (`SS_Shipping_Parcel_Plan` of `SS_Shipping_Parcel_Spec` rows - a spec may carry dimensions and an explicit weight with no item allocations at all)
* **smart_send_order_receiver**
    A filter to change the receiver address that is used for shipping labels
* **smart_send_receiver_phone** (since 9.0.0)
    A filter to change the receiver phone number used for the shipping label and the SMS notification
* **smart_send_payload_receiver** (since 9.0.0)
    A filter on the receiver section (array) of the booking request
* **smart_send_payload_items** (since 9.0.0)
    A filter on the item lines (array) of the booking request
* **smart_send_payload_totals** (since 9.0.0)
    A filter on the totals section (array) of the booking request
* **smart_send_order_note**
    A filter to change the freetext that is inserted on shipping labels

Example: ship every order in two parcels of fixed size and weight, with no item allocation:

    add_filter('smart_send_delivery_details', function ($details, $order, $is_return) {
        $plan = new SS_Shipping_Parcel_Plan();
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(4)->set_length(30)->set_width(20)->set_height(10));
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(2.5)->set_length(15)->set_width(15)->set_height(15));

        return $details->set_parcel_plan($plan);
    }, 10, 3);

= After the shipping label is created =

* **smart_send_shipping_label_created**
    An action which is called once a shipping label has been created for an order. Since 9.0.0 it receives the order id, the raw (unmutated) API booking response and a typed `SS_Shipping_Label_Entry` (label URL, order note HTML, return flag)
* **smart_send_shipping_label_comment**
    A filter to modify the order comment that is added once a shipping label is created
* **smart_send_tracking_url**
    A filter to modify the tracking url that is entered in WooCommerce once a shipping label is created

= Logging and admin =

* **smart_send_logging** (since 9.0.0)
    A filter on every message the plugin writes to the WooCommerce log - return a modified string to rewrite it, or null/false to suppress the entry
* **smart_send_configuration_url**
    A filter on the settings link shown on the WordPress plugins screen
* **smart_send_support_url**
    A filter on the support link shown on the WordPress plugins screen

= WooCommerce-standard hooks =

The following hooks follow WooCommerce naming conventions and can be used as well:

* **woocommerce_smart_send_shipping_shipping_add_rate**
    An action that allows 3rd parties to add rates after the Smart Send rate is added.
* **woocommerce_shipping_smart_send_shipping_is_available**
    A filter that allows 3rd parties to disable a shipping method
* **woocommerce_shipping_smart_send_shipping_is_free_shipping**
    A filter that allows 3rd parties to disable/enable free shipping for a method

The following filters are inherited from WooCommerce and can be used as well:

* **woocommerce_settings_api_form_fields_smart_send_shipping**
    A filter to override the main setting fields.
* **woocommerce_shipping_instance_form_fields_smart_send_shipping**
    A filter to override shipping method settings.

The plugin shows the selected pickup point relevant places using these two hooks:

* **woocommerce_order_details_after_order_table**
    Show the selected pickup point below the table of order items
* **woocommerce_email_after_order_table**
   Show the selected pickup point below the table of order items

= Meta fields =

The following meta fields are used by the plugin. The order meta keys and their stored formats are a stable public contract - in particular the pickup point selection under **ss_shipping_order_agent_no** (the pickup point number) and **_ss_shipping_order_agent** (the stored pickup point object):

* **smart_send_shipping_method**
    Shipping method meta field used to store the shipping method used when generating shipping labels
* **smart_send_return_method**
    Shipping method meta field used to store the shipping method used when generating return shipping labels
* **smart_send_auto_generate_return_label**
    Field used for storing setting whether or not a return label should automatically be created when creating a shipping label
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

== Frequently Asked Questions ==

= Why are no pickup point shown at checkout? =
Make sure, that the selected shipping method is "Select Pickup Point".

= Info box: Shipping to closest pickup point =
This box appears when a "Select Pickup Point" shipping method is selected, but no pickup points were found. Check that the entered shipping address is valid, that pickup points are possible in the selected region and that a valid API Token is entered in the plugins settings.

= Info box: Enter shipping information =
This box appears when a "Select Pickup Point" shipping method is selected, but no shipping address is entered. Enter a valid shipping address so that the plugin can search for nearby pickup points.

== Screenshots ==

1. Show closest pickup points during checkout
2. Create PDF shipping labels from backend with just one click
3. Save tracking information automatically after creating shipping labels
4. Get detailed error description if something is incorrect
5. Add shipping methods to WooCommerce Shipping Zones
6. Connect WooCommerce to Smart Send by entering the API Token


== Changelog ==

= 9.0.0 =
* Breaking change: the weight table now defines which cart weights the Smart Send shipping method is available for at all. Free shipping (the flat-rate threshold) only zeroes the price of an otherwise-available rate and can no longer make the method available for a cart weight outside the configured weight table - see the "9.0.0" entry under Upgrade Notice
* Breaking change: the filters smart_send_shipping_label_args, smart_send_order_pickup_point, smart_send_order_parcels, smart_send_parcel_weight and smart_send_payload_parcels are removed, replaced by the single typed smart_send_delivery_details filter - see the "9.0.0" entry under Upgrade Notice
* Breaking change: the smart_send_shipping_label_created action no longer mutates the API response with a ->woocommerce presentation array; listeners receive a typed SS_Shipping_Label_Entry third argument instead - see the "9.0.0" entry under Upgrade Notice
* Fix: hand-editing the pickup point number (ss_shipping_order_agent_no) in the order screen's Custom Fields box is now validated against the Smart Send API on stores using High-Performance Order Storage too (previously the validation silently never ran on HPOS stores)
* Fix: with the "save shipping labels in uploads" setting enabled, the label link in the order note and label response now points at the saved uploads copy (previously the computed uploads URL was discarded and the Smart Send API link was always used)
* Temporary restriction: the "Smart Send - Generate Labels" / "Generate Return Labels" bulk actions on the Orders screen now only process a single selected order at a time (previously up to 5 orders with a combined-PDF download). Selecting more than one order shows an error and processes nothing. Multi-order bulk processing will be back in an upcoming release - see the "9.0.0" entry under Upgrade Notice

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
9.0 is the v9 major release and carries breaking changes for sites that rely on Smart Send hooks or filters, or on the pre-9.0 free-shipping behaviour. Note: 9.0.0 has not been released yet as of this writing; this entry is maintained on develop ahead of the actual release. Review before upgrading from 8.x:

* The weight table now defines which cart weights the shipping method is available for at all. Previously, meeting the free-shipping (flat-rate) threshold made the method available and free regardless of the weight table, even for a cart weight the table did not cover. Now, free shipping only zeroes the price of a rate the weight table already allows - a cart weight outside every configured weight-table row means the method is not offered, no matter the subtotal. An empty weight table still means every weight is valid. Sites relying on free shipping to override the weight table should either widen the weight table or use the `woocommerce_shipping_smart_send_shipping_is_available` filter to restore the old behaviour.

* The shipping-label filter surface is rebuilt around one typed extension point. The filters `smart_send_shipping_label_args` (the `$ss_args` array of carrier/method/agent/parcels), `smart_send_order_pickup_point`, `smart_send_order_parcels`, `smart_send_parcel_weight` and the unreleased `smart_send_payload_parcels` are removed and no longer fire. Their replacement is the single `smart_send_delivery_details` filter, which receives the merged `SS_Shipping_Delivery_Details` value object (plus the `WC_Order` and an is-return flag) before booking: use `set_shipping_method()` to override the method (was: `$ss_args['ss_carrier']`/`$ss_args['ss_type']`), `set_pickup_point()` with a `SS_Shipping_Pickup_Point` (or `null`) to replace or clear the pickup point (was: `smart_send_order_pickup_point`), and `set_parcel_plan()` with a `SS_Shipping_Parcel_Plan` of `SS_Shipping_Parcel_Spec` rows to control the parcel split (was: `smart_send_order_parcels`) - a spec's explicit `set_weight()` replaces `smart_send_parcel_weight`, and specs may declare dimensions and weight with no item allocations at all. Snippets registering the removed filters must be rewritten; see the Developers section for an example.

* Listeners of the `smart_send_shipping_label_created` action now receive different data. The raw API response (second argument) is no longer mutated with a `->woocommerce` array - snippets reading `$response->woocommerce['label_url']`, `['order_note']` or `['return']` must switch to the new typed third argument, an `SS_Shipping_Label_Entry` with `get_label_url()`, `get_order_note()` and `is_return()`. Register the action with `add_action('smart_send_shipping_label_created', $callback, 10, 3)` to receive it.

* With the "Save shipping labels in uploads folder" setting enabled, the label link placed in the order note and the label response now points at the saved uploads copy instead of the Smart Send API link (the historic behaviour computed the uploads URL and then discarded it). Sites relying on the API link while the setting is enabled should disable the setting or read the link from the API response.

* The Orders screen bulk label actions temporarily process only a single selected order at a time. The previous multi-order flow (up to 5 orders, with a combined-PDF download) is removed while label processing is being rebuilt; selecting more than one order shows an error and processes nothing. Multi-order bulk processing will return in an upcoming release. Workflows that generate labels for several orders should run the bulk action once per order (or use the "Generate label" button on each order) until then.

= 8.0 =
8.0 is a major update. Shipping methods moved to WooCommerce Shipping Zones and must be setup again after upgrading. Make a full site backup, and [review update best practices](https://docs.woocommerce.com/document/how-to-update-your-site) before upgrading.

= 7.2 =
Version 7.1 is deprecated. Upgrading to 7.2 can be done at no risk, but is needed for continuous use of the plugin.
