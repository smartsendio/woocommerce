=== Smart Send Logistics ===
Contributors: SmartSend
Donate link: http://www.SmartSend.dk/
Tags: shipping, pickup, pakkeboks, pakkeshop, hente selv, døgnboks, post danmark, gls, swipbox, bring, carrier, pacsoft, yourgls, mybring, postage, shipping method, your-gls, my-bring, pacosft-online, pacsoftonline, denmark, sweeden, posten, norway, post 
Requires at least: 3.0.1
Tested up to: 4.8
Stable tag: 7.1.9
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Table rate shipping methods with flexible conditions determining the rate and even let the customer chose a pick-up point during checkout. Integrates the shipping methods directly with carrier systems and create PDF labels directly from the backend.

== Description ==

The module is a complete user friendly shipping system that allows for easy setup of multiple shipping methods and allows for a direct integration to the carries. Integrate tracking information directly in the system and customer emails.

Supported carriers:

* GLS (YourGLS)
* Bring (MyBring)
* Post Danmark (Pacsoft)
* Posten (Pacsoft)
* Post Nord (Pacsoft)

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
Let the customer choose a close by pickup point during checkout. The package will be delivered to that pickup point. The customer can collect the package at selected pickup point at convenience.

* Nearest pickup points based on customer address
* Automatically updated list
* User friendly dropdown list
* One step/page checkout compatible

= Shipping labels =
Create shipping labels directly from the backend by a single click. The information is automatically formatted and send to the carrier for processing. A PDF label is immediately shown and ready to print. Tracking information is automatically saved in the system and can be included in customer emails or can be sendt by text message.

Easily create:

* Shipping labels as PDF files
* Return shipping labels
* Tracking information

[youtube http://www.youtube.com/watch?v=9da6kvp0Ajo]

This plugin replaces the two previous modules “Smart Send Labelgenerator” and “Smart Send Pickup Shipping”.

== Installation ==

= Automatic installation (recommended) =
Automatic installing a Plugin using WordPress Plugin Search is the easiest option as WordPress handles the file transfer and no FTP access is required. This installation method is therefore the recommended method.

To install a plugin automatically using the WordPress Plugin Search, follow the process:

1. Log in to the WordPress dashboard
2. Navigate to the Plugin menu
3. Click 'Add New' in the Plugin sub-menu
4. Enter 'Smart Send Logistics' in the search field and click 'Search Plugins'
5. Click the 'Install Now'-button
6. Once the plugin is installed, click the 'Activate Plugin' link to active the plugin
7. The plugin is installed, activated and ready to use once you see the succes message 'Plugin activated' at the top of the plugin page

= Manual installation =

The manual installation requires that the plugin is downloaded, extracted and transfered to the server hosting the WordPress site. This is usually done using an FTP client like FileZilla.

To install a plugin manually, follow the process:

1. Download the plugin either from [WordPress](https://wordpress.org/plugins/smart-send-logistics/) or from the [Smart Send website](http://smartsend.dk/woocommerce/).
2. Extract the plugin using appropriate software (WinRAR, TheUnarchiver...)
3. Open FTP client eg FileZilla
4. Connect to WordPress site
5. Move to folder 'wp-content/plugins/'
6. Transfer the folder 'smart-send-logistics' to the remote folder 'wp-content/plugins/'
7. The plugin should now be installed and can be seen in the WordPress plugin page
8. Once the plugin is installed, click the 'Activate Plugin' link at the plugin page to active the plugin
9. The plugin is installed, activated and ready to use once you see the succes message 'Plugin activated' at the top of the plugin page


== Screenshots ==

1. Setup multiple shipping methods and let the customer choose between them.
2. A dropdown with pickup points is shown for shipping methods where the customer collects the package at a store.
3. Easy setup of the centrale module settings
4. Table rate setup of shipping methods have never been easier.
5. Services for each carrier is easily setup in WooCommerce backend.

== Changelog ==

= 7.1.9 =
* Adding compatibility with WooCommerce 3.1.0
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