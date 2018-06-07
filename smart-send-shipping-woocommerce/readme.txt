=== Smart Send Logistics ===
Contributors: SmartSend
Donate link: https://www.smartsend.io/
Author: SmartSend
Author URI: https://smartsend.io/
Developer: SmartSend
Developer URI: https://smartsend.io/
Tags: smartsend, smart send, shipping, pickup, pakkeboks, pakkeshop, hente selv, døgnboks, post danmark, gls, swipbox, bring, carrier, pacsoft, yourgls, mybring, postage, shipping method, your-gls, my-bring, pacosft-online, pacsoftonline, denmark, sweeden, posten, norway, post 
Requires at least: 3.0.1
Tested up to: 5.0
Stable tag: 8.0.0
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
WC requires at least: 2.6.0
WC tested up to: 3.4
Requires PHP: 5.6.0

Complete WooCommerce shipping solution for PostNord, GLS and Bring.

== Description ==

Complete shipping solution for PostNord, GLS and Bring. Setup shipping methods with rates calculated based on products, shipping address, weight, subtotal, user roles, shipping classes and much more. Show pick-up points to the customer during checkout and create shipping labels directly from the WooCommerce admin panel.

From now on, everything is incorporated directly into your WooCommerce store.

Supported carriers:

* GLS (YourGLS)
* Bring (MyBring)
* Post Nord (Pacsoft)
* Post Danmark (Pacsoft)
* Posten (Pacsoft)

Supports worldwide shipping from these countries:

* Denmark
* Sweden
* Finland
* Norway

= Table rates =
Table rate settings enables multiple shipping methods to be easily configured in one table. Determine the shipping price for each method based on multiple condition.

Calculate shipping rate based on:

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
* Pick-up point (collect the parcel at a shop near the customer)
* Flex delivery (leave parcel at specified location)
* Home delivery
* Handling of special good, eg food
* TAX handling
* Enable free delivery based on condition

= Pick-up point =
Let the customer choose a pick-up point close to them during checkout. The package will be delivered to the selected pick-up point, where the customer can collect the package at their own convenience.

* Nearest pick-up points based on entered shipping address
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

This plugin replaces the two previous modules “Smart Send Labelgenerator” and “Smart Send Pickup Shipping”.

== Installation ==

See our online installation guide at [SmartSend.io](https://smartsend.io/woocommerce/configuration), or follow these steps:

1. Log in to the WordPress dashboard
2. Navigate to the Plugin menu
3. Click 'Add New' in the Plugin sub-menu
4. Enter 'Smart Send Logistics' in the search field and click 'Search Plugins'
5. Click the 'Install Now'-button
6. Once the plugin is installed, click the 'Activate Plugin' link to active the plugin
7. The plugin is installed, activated and ready to use once you see the succes message 'Plugin activated' at the top of the plugin page

= Connect plugin to Smart Send using API Token =

The plugin must be connected to Smart Send for all functions to work properly. You can create a [Smart Send account here](https://smartsend.io/signup)

[youtube https://www.youtube.com/embed/wyJYbwwI0h8]

See our written guide on our [Smart Send website](https://smartsend.io/woocommerce/api-token/) or followed these steps:

1. Log in to the WordPress dashboard
2. Choose 'WooCommerce' in the menu to the left and select 'Settings'
3. Choose the 'Shipping' tab in the top menu bar
4. Click on 'Smart Send' in the list under the tabs
5. Enter the API Token you received in your welcome email and click save. Signup [here](https://smartsend.io/woocommerce/api-token/) to get an API Token.
6. Once the API Token is saved, press 'Validate API Token' to connect your WooCommerce store to Smart Send.


== Screenshots ==

1. Create shipping labels from backend and let tracking information being saved automatically
2. Let the customer choose a close pickup point where the parcel can be collected
3. Easy setup of the centrale module settings
4. Table rate setup of shipping methods have never been easier.
5. Services for each carrier is easily setup in WooCommerce backend.

== Changelog ==

= 8.0.0 =
* Completely refactoring of plugin
* Using Shipping Zones instead of WooCommerce legacy shipping API
* Plugin is not backwards compatible. All settings must be setup from scratch
* Separates standard settings from the more advanced settings for simplicity
* Includes more information about pick-up points in checkout page
* Limit shipping methods by weight, price, user role, shipping zone, shipping class and much more

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
* Add cURL error description is no response from server

= 7.1.11 =
* Fixing problem with missing file for version 7.1.10

= 7.1.10 =
* Fixing PHP notification for WooCommerce 3.0+
* Fixing problem fetching pickup point data for some installations
* Adding compatability for WooCommerce 2.5+
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
* Adding the possibility to shop dropdown of pickup points for WooCommerce Free shipping
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
* Bugfixes
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

= 8.0.0 =
Complete redesign of plugin and moved to Shipping Zones. OBS: Require completely new configuration.
