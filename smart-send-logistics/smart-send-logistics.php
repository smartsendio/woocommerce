<?php
/**
 * Plugin Name: Smart Send Shipping for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/smart-send-logistics/
 * Description: Smart Send Shipping for WooCommerce
 * Author: Smart Send ApS
 * Author URI: https://www.smartsend.io
 * Text Domain: smart-send-logistics
 * Version: 8.2.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 4.7.0
 * WC tested up to: 11.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// A second copy of the plugin (e.g. bundled elsewhere) may already have defined the class.
if ( ! class_exists( 'SS_Shipping_WC' ) ) :

    class SS_Shipping_WC
    {

		private string $version = '8.2.0';

		/**
		 * Instance to call certain functions globally within the plugin
		 *
		 * @var SS_Shipping_WC|null
		 */
		protected static ?SS_Shipping_WC $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- pre-existing name, renaming is out of scope here.

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings|null
		 */
		protected ?SS_Shipping_Settings $settings = null;

		/**
		 * Order meta repository.
		 *
		 * @var SS_Shipping_Order_Meta|null
		 */
		protected ?SS_Shipping_Order_Meta $order_meta = null;

		/**
		 * Shipping method resolver.
		 *
		 * @var SS_Shipping_Method_Resolver|null
		 */
		protected ?SS_Shipping_Method_Resolver $method_resolver = null;

		/**
		 * Booked shipment id accessor.
		 *
		 * @var SS_Shipping_Shipment_Ids|null
		 */
		protected ?SS_Shipping_Shipment_Ids $shipment_ids = null;

		/**
		 * Pickup point (agent number) validator.
		 *
		 * @var SS_Shipping_Pickup_Point_Validator|null
		 */
		protected ?SS_Shipping_Pickup_Point_Validator $pickup_point_validator = null;

		/**
		 * Order screen meta box component.
		 *
		 * @var SS_Shipping_Order_Meta_Box|null
		 */
		protected ?SS_Shipping_Order_Meta_Box $meta_box = null;

		/**
		 * The fulfillment service running the label workflow.
		 *
		 * @var SS_Shipping_Fulfillment_Service|null
		 */
		protected ?SS_Shipping_Fulfillment_Service $fulfillment_service = null;

		/**
		 * AJAX label-generation controller.
		 *
		 * @var SS_Shipping_Label_Creator|null
		 */
		protected ?SS_Shipping_Label_Creator $label_creator = null;

		/**
		 * Bulk actions component.
		 *
		 * @var SS_Shipping_Order_Bulk_Actions|null
		 */
		protected ?SS_Shipping_Order_Bulk_Actions $bulk_actions = null;

		/**
		 * API test-connection feature.
		 *
		 * @var SS_Shipping_Test_Connection|null
		 */
		protected ?SS_Shipping_Test_Connection $test_connection = null;

		/**
		 * Checkout rate sorter.
		 *
		 * @var SS_Shipping_Rate_Sorter|null
		 */
		protected ?SS_Shipping_Rate_Sorter $rate_sorter = null;

		/**
		 * Admin notices component used to flash one-time notices after
		 * label-generation actions.
		 *
		 * @var SS_Shipping_Admin_Notices|null
		 */
		protected ?SS_Shipping_Admin_Notices $admin_notices = null;

		/**
		 * WooCommerce Subscriptions compatibility component.
		 *
		 * @var SS_Shipping_Subscriptions_Compat|null
		 */
		protected ?SS_Shipping_Subscriptions_Compat $subscriptions_compat = null;

		/**
		 * Smart Send Shipping Product
		 *
		 * Public: reachable via SS_SHIPPING_WC() from merchant code snippets.
		 * It is only ever assigned the real SS_Shipping_WC_Product instance
		 * in the constructor below, so typing it is a deliberate v9 breaking
		 * change - a snippet that replaces it with something else now fatals
		 * instead of silently corrupting state.
		 *
		 * @var SS_Shipping_WC_Product|null
		 */
		public ?SS_Shipping_WC_Product $ss_shipping_wc_product = null;

		/**
		 * Smart Send Frontend
		 *
		 * @var SS_Shipping_Frontend|null
		 */
		protected ?SS_Shipping_Frontend $ss_shipping_frontend = null;

		/**
		 * Pickup point display formatter.
		 *
		 * @var SS_Shipping_Pickup_Point_Formatter|null
		 */
		protected ?SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter = null;

		/**
		 * Headless pickup point lookup (API + session cache).
		 *
		 * @var SS_Shipping_Pickup_Point_Lookup|null
		 */
		protected ?SS_Shipping_Pickup_Point_Lookup $pickup_point_lookup = null;

		/**
		 * Smart Send api handle
		 *
		 * @var \Smartsend\Api|null
		 */
		protected ?\Smartsend\Api $api_handle = null;

		/**
		 * Smart Send Plugin Screen Updates
		 *
		 * @var SS_Plugins_Screen_Updates|null
		 */
		protected ?SS_Plugins_Screen_Updates $ss_plugin_screen_updates = null;

        /**
         * Construct the plugin.
         */
        public function __construct()
        {
            add_action('before_woocommerce_init', [$this, 'declaring_hpos_compatibility']);

            $this->define_constants();
            $this->includes();

			$this->settings = new SS_Shipping_Settings();

			$this->init_hooks();
		}

		public function declaring_hpos_compatibility() {
			// FeaturesUtil exists since WC 6.5; the plugin's WC floor is 4.7.
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		}

        /**
         * Main Smart Send Shipping Instance.
         *
         * Ensures only one instance is loaded or can be loaded.
         *
         * @static
         * @see SS_Shipping_WC()
         * @return SS_Shipping_WC - Main instance.
         */
        public static function instance()
        {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

		/**
		 * Define WC Constants.
		 */
		private function define_constants() {
			// Path related defines, used throughout includes() and
			// include_shipping_method_class() for require_once paths.
			$this->define( 'SS_SHIPPING_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
			$this->define( 'SS_SHIPPING_PLUGIN_DIR_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
			$this->define( 'SS_SHIPPING_PLUGIN_DIR_URL', untrailingslashit( plugins_url( '/', __FILE__ ) ) );
			// Mirrors the private $version property for the handful of files
			// (API client, logger, method class, upgrade notices) that need
			// the version number but aren't handed the SS_Shipping_WC instance.
			$this->define( 'SS_SHIPPING_VERSION', $this->version );
			// The literal shipping method id ('smart_send_shipping'). Kept as a
			// global constant rather than a class constant on
			// SS_Shipping_WC_Method: it's read from files loaded well before
			// that class (this file, SS_Shipping_Logger), and that class is
			// only require_once'd lazily once WooCommerce is confirmed active.
			$this->define( 'SS_SHIPPING_METHOD_ID', 'smart_send_shipping' );
		}

		/**
		 * Include required core files used in admin and on the frontend.
		 *
		 * Classes are loaded with explicit require_once calls (no autoloader):
		 * Composer cannot be assumed on a WordPress install and a bespoke
		 * autoloader is not worth maintaining. See issue #43.
		 */
		public function includes() {
			// Smart Send PHP API client (PSR-style, namespace Smartsend).
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/lib/Smartsend/Api.php';

			// Shared components used in admin and on the frontend.
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-settings.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-api-credentials.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-api-factory.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-logger.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-checkout-debug.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-admin-notices.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-order-reader.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-pickup-point.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-parcel-spec.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-parcel-plan.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-delivery-details.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-pickup-point-formatter.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-label-entry.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-fulfillment-result.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-fulfillment-service.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/includes/class-ss-shipping-subscriptions-compat.php';

			// Shipping-method layer helpers. Loaded eagerly: unlike
			// SS_Shipping_WC_Method they extend nothing from WooCommerce, so
			// only the method class itself needs the lazy require (see
			// include_shipping_method_class()).
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-method-code.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-method-catalog.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-method-settings.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-method-form-renderer.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-test-connection.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-weight-table.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-availability-reporter.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-rate-sorter.php';

			// Admin components.
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-plugins-screen-updates.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-parcel.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-shipment.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-shipment-builder.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-booking.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-booking-exception.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-booking-service.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-order-meta.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-method-resolver.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-shipment-ids.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-pickup-point-validator.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-order-meta-box.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-label-creator.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-order-bulk-actions.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-wc-product.php';

			// Frontend components.
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/public/class-ss-shipping-pickup-point-lookup.php';
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/public/class-ss-shipping-frontend.php';
		}

		/**
		 * Load the shipping method class.
		 *
		 * SS_Shipping_WC_Method extends WC_Shipping_Flat_Rate, so its file can
		 * only be loaded once WooCommerce (and its autoloader) is available -
		 * this plugin loads before WooCommerce. Loaded from init() behind the
		 * bootstrap-level WooCommerce gate and defensively from
		 * add_shipping_method(); require_once makes the call idempotent. Its
		 * helper classes extend nothing and are loaded eagerly in includes().
		 */
		public function include_shipping_method_class() {
			require_once SS_SHIPPING_PLUGIN_DIR_PATH . '/admin/class-ss-shipping-wc-method.php';
		}

        protected function init_hooks()
        {
            add_action('init', array($this, 'init'), 0);
            add_action('init', array($this, 'load_textdomain'));

            add_filter('plugin_action_links_' . SS_SHIPPING_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
            add_filter('plugin_row_meta', array($this, 'ss_shipping_plugin_row_meta'), 10, 2);

            add_action('admin_enqueue_scripts', array($this, 'ss_shipping_theme_enqueue_admin_styles'));
            add_action('wp_enqueue_scripts', array($this, 'ss_shipping_theme_enqueue_frontend_styles'));

            add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
        }


        /**
         * Initialize the plugin.
		 */
		public function init() {
			// The single bootstrap-level WooCommerce-active gate: `Requires Plugins: woocommerce`
			// already guarantees WooCommerce on WP >= 6.5, so this check is belt-and-braces for
			// older WordPress. Everything downstream of it assumes WooCommerce is present.
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.6', '>=' ) ) {
				$this->include_shipping_method_class();

				$this->pickup_point_formatter = new SS_Shipping_Pickup_Point_Formatter( $this->settings );
				$this->pickup_point_lookup    = new SS_Shipping_Pickup_Point_Lookup();

				$this->ss_shipping_frontend     = new SS_Shipping_Frontend( $this->pickup_point_lookup, $this->pickup_point_formatter, $this->settings );
				$this->ss_shipping_wc_product   = new SS_Shipping_WC_Product();
				$this->ss_plugin_screen_updates = new SS_Plugins_Screen_Updates();

				$this->admin_notices          = new SS_Shipping_Admin_Notices();
				$this->order_meta             = new SS_Shipping_Order_Meta();
				$this->method_resolver        = new SS_Shipping_Method_Resolver( $this->settings );
				$this->shipment_ids           = new SS_Shipping_Shipment_Ids();
				$this->pickup_point_validator = new SS_Shipping_Pickup_Point_Validator( $this->order_meta, $this->method_resolver );
				$this->meta_box               = new SS_Shipping_Order_Meta_Box( $this->order_meta, $this->method_resolver, $this->pickup_point_formatter, $this->settings );
				$this->fulfillment_service    = new SS_Shipping_Fulfillment_Service(
					$this->order_meta,
					$this->method_resolver,
					$this->shipment_ids,
					new SS_Shipping_Booking_Service( $this->order_meta, $this->method_resolver ),
					$this->settings
				);
				$this->label_creator          = new SS_Shipping_Label_Creator( $this->fulfillment_service );
				$this->test_connection        = new SS_Shipping_Test_Connection( new SS_Shipping_Api_Factory( $this->settings ) );
				$this->rate_sorter            = new SS_Shipping_Rate_Sorter( $this->settings );
				$this->bulk_actions           = new SS_Shipping_Order_Bulk_Actions( $this->method_resolver, $this->fulfillment_service, $this->admin_notices, $this->settings );
				$this->subscriptions_compat   = new SS_Shipping_Subscriptions_Compat();

				$this->register_component_hooks();
			} else {
				// Throw an admin error informing the user this plugin needs WooCommerce to function.
				add_action( 'admin_notices', array( $this, 'notice_wc_required' ) );
			}

        }

		/**
		 * Register the hooks of every feature component constructed in
		 * init(). Hook registration convention: constructing a component has
		 * zero side effects, this composition root calls register_hooks()
		 * explicitly on each one, in one clear pass.
		 *
		 * @return void
		 */
		protected function register_component_hooks() {
			$this->ss_shipping_frontend->register_hooks();
			$this->ss_shipping_wc_product->register_hooks();
			$this->ss_plugin_screen_updates->register_hooks();

			$this->admin_notices->register_hooks();
			$this->pickup_point_validator->register_hooks();
			$this->meta_box->register_hooks();
			$this->label_creator->register_hooks();
			$this->test_connection->register_hooks();
			$this->rate_sorter->register_hooks();
			$this->bulk_actions->register_hooks();
			$this->subscriptions_compat->register_hooks();
		}

        /**
         * Localisation
         */
        public function load_textdomain()
        {
            load_plugin_textdomain('smart-send-logistics', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        }

		/**
		 * Load Admin CSS
		 */
		public function ss_shipping_theme_enqueue_admin_styles() {
			wp_enqueue_style( 'ss-shipping-admin-css', SS_SHIPPING_PLUGIN_DIR_URL . '/admin/css/ss-shipping-admin.css' ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- pre-existing behaviour: the default WordPress version query string is kept; pinning a version is out of scope for the #43 move.
		}

		/**
		 * Load Frontend CSS
		 */
		public function ss_shipping_theme_enqueue_frontend_styles() {
			wp_enqueue_style( 'ss-shipping-frontend-css', SS_SHIPPING_PLUGIN_DIR_URL . '/public/css/ss-shipping-frontend.css' ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- pre-existing behaviour: the default WordPress version query string is kept; pinning a version is out of scope for the #43 move.
		}

        /**
         * Define constant if not already set.
         *
         * @param  string $name
         * @param  string|bool $value
         */
        public function define($name, $value)
        {
            if (!defined($name)) {
                define($name, $value);
            }
        }


        /**
         * Show action links on the plugin screen.
         *
         * @param    mixed $links Plugin Action links
         * @return    array
         */
        public static function plugin_action_links($links)
        {
            $action_links = array(
                'settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping') . '" aria-label="' . esc_attr__('View WooCommerce settings',
                        'smart-send-logistics') . '">' . esc_html__('Settings', 'smart-send-logistics') . '</a>',
            );

            return array_merge($action_links, $links);
        }

        /**
         * Show row meta on the plugin screen.
         *
         * @param    mixed $links Plugin Row Meta
         * @param    mixed $file Plugin Base file
         * @return    array
         */
        function ss_shipping_plugin_row_meta($links, $file)
        {

            if (SS_SHIPPING_PLUGIN_BASENAME == $file) {
                $row_meta = array(
                    'configuration' => '<a href="' . esc_url(apply_filters('smart_send_configuration_url',
                            'https://smartsend.io/woocommerce/configuration/')) . '" title="' . esc_attr(__('Configuration guide',
                            'smart-send-logistics')) . '" target="_blank">' . __('Configuration guide',
                            'smart-send-logistics') . '</a>',
                    'support'       => '<a href="' . esc_url(apply_filters('smart_send_support_url',
                            'https://smartsend.io/support/')) . '" title="' . esc_attr(__('Support',
                            'smart-send-logistics')) . '" target="_blank">' . __('Support',
                            'smart-send-logistics') . '</a>',
                );

                return array_merge($links, $row_meta);
            }

            return (array)$links;
        }

		/**
		 * Add a new integration to WooCommerce.
		 */
		public function add_shipping_method( $shipping_method ) {
			$this->include_shipping_method_class();

			$ss_shipping_shipping_method            = 'SS_Shipping_WC_Method';
			$shipping_method['smart_send_shipping'] = $ss_shipping_shipping_method;

			return $shipping_method;
		}

        /**
         * Admin error notifying user that WC is required
         */
        public function notice_wc_required()
        {
            ?>
            <div class="error">
                <p><?php _e('Smart Send Shipping requires WooCommerce 2.6 and above to be installed and activated!',
                        'smart-send-logistics'); ?></p>
            </div>
            <?php
        }

		/**
		 * Get the typed plugin settings reader.
		 *
		 * @return SS_Shipping_Settings
		 */
		public function settings(): SS_Shipping_Settings {
			return $this->settings;
		}

		/**
		 * Get the pickup point display formatter.
		 *
		 * @return SS_Shipping_Pickup_Point_Formatter
		 */
		public function pickup_point_formatter(): SS_Shipping_Pickup_Point_Formatter {
			return $this->pickup_point_formatter;
		}

		/**
		 * Get the order meta access component.
		 *
		 * @return SS_Shipping_Order_Meta
		 */
		public function order_meta(): SS_Shipping_Order_Meta {
			return $this->order_meta;
		}

		/**
		 * Get the shipping method resolver.
		 *
		 * @return SS_Shipping_Method_Resolver
		 */
		public function method_resolver(): SS_Shipping_Method_Resolver {
			return $this->method_resolver;
		}

		/**
		 * Get the booked shipment id accessor.
		 *
		 * @return SS_Shipping_Shipment_Ids
		 */
		public function shipment_ids(): SS_Shipping_Shipment_Ids {
			return $this->shipment_ids;
		}

		/**
		 * Get the pickup point (agent number) validator.
		 *
		 * @return SS_Shipping_Pickup_Point_Validator
		 */
		public function pickup_point_validator(): SS_Shipping_Pickup_Point_Validator {
			return $this->pickup_point_validator;
		}

		/**
		 * Get the fulfillment service running the label workflow.
		 *
		 * @return SS_Shipping_Fulfillment_Service
		 */
		public function fulfillment(): SS_Shipping_Fulfillment_Service {
			return $this->fulfillment_service;
		}

		/**
		 * Get the admin notices component.
		 *
		 * @return SS_Shipping_Admin_Notices
		 */
		public function admin_notices(): SS_Shipping_Admin_Notices {
			return $this->admin_notices;
		}

		/**
		 * Get the order screen meta box component.
		 *
		 * @return SS_Shipping_Order_Meta_Box
		 */
		public function meta_box(): SS_Shipping_Order_Meta_Box {
			return $this->meta_box;
		}

		/**
		 * Get the bulk actions component.
		 *
		 * Reachable directly (not just via hooks) because the Integration
		 * test suite calls add_bulk_order_actions()/handle_bulk_order_actions()
		 * on it outside a real bulk-action request.
		 *
		 * @return SS_Shipping_Order_Bulk_Actions
		 */
		public function bulk_actions(): SS_Shipping_Order_Bulk_Actions {
			return $this->bulk_actions;
		}

		/**
		 * Get the shared Smart Send API client, constructed on first use
		 * through the single SS_Shipping_Api_Factory construction path.
		 *
		 * @return \Smartsend\Api
		 */
		public function get_api_handle() {
			if ( ! $this->api_handle ) {
				$this->api_handle = ( new SS_Shipping_Api_Factory( $this->settings ) )->create();
			}

			return $this->api_handle;
		}

		/**
		 * Get the API test-connection feature.
		 *
		 * @return SS_Shipping_Test_Connection
		 */
		public function test_connection(): SS_Shipping_Test_Connection {
			return $this->test_connection;
		}

		/**
		 * Get the checkout rate sorter.
		 *
		 * @return SS_Shipping_Rate_Sorter
		 */
		public function rate_sorter(): SS_Shipping_Rate_Sorter {
			return $this->rate_sorter;
		}

	    /**
	     * Find the closest agents by address - Convenience wrapper
	     *
	     * @param $carrier string unique carrier code
	     * @param $country string ISO3166-A2 Country code
	     * @param $postal_code string
	     * @param $street string
         * @param $city string optional but providing a city yields better accuracy for geocoding
	     *
	     * @return array
	     */
	    public function ss_find_closest_agents_by_address($carrier, $country, $postal_code, $street, $city=null) {
	        return $this->ss_shipping_frontend
                ->find_closest_agents_by_address($carrier, $country, $postal_code, $city, $street);
        }
    }

endif;

function SS_SHIPPING_WC()
{
    return SS_Shipping_WC::instance();
}

$SS_Shipping_WC = SS_SHIPPING_WC();
