<?php
/**
 * The Smart Send plugin bootstrap and composition root.
 *
 * Loaded (and its singleton instantiated) by the thin plugin entry file
 * smart-send-logistics.php, which defines SS_SHIPPING_PLUGIN_FILE and the
 * SS_SHIPPING_WC() accessor. This class constructs
 * every feature component and wires their hooks (#140).
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send;

use Smart_Send\Admin\Fulfillment_REST_Controller;
use Smart_Send\Admin\Order_Bulk_Actions;
use Smart_Send\Admin\Order_Fulfillment_Presenter;
use Smart_Send\Admin\Order_Meta_Box;
use Smart_Send\Admin\Plugins_Screen_Updates;
use Smart_Send\Admin\Product;
use Smart_Send\Booking\Booking_Service;
use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery_Options\Checkout_Options;
use Smart_Send\Delivery_Options\Pickup_Point_Formatter;
use Smart_Send\Delivery_Options\Pickup_Point_Lookup;
use Smart_Send\Delivery_Options\Pickup_Point_Validator;
use Smart_Send\Delivery_Options\Store_API;
use Smart_Send\Frontend\Block_Checkout;
use Smart_Send\Frontend\Checkout;
use Smart_Send\Fulfillment\Fulfillment_Service;
use Smart_Send\Fulfillment\Shipment_IDs;
use Smart_Send\Shipping_Method\Method;
use Smart_Send\Shipping_Method\Rate_Sorter;
use Smart_Send\Shipping_Method\Test_Connection;
use Smart_Send\Support\API_Factory;
use Smart_Send\Support\Admin_Notices;
use Smart_Send\Support\Settings;
use Smart_Send\Support\Subscriptions_Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Plugin {

	private const MINIMUM_WC_VERSION = '8.2.0';

	private string $version = '9.0.0';

	/**
	 * Instance to call certain functions globally within the plugin
	 *
	 * @var Plugin|null
	 */
	protected static ?Plugin $instance = null;

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings|null
	 */
	protected ?Settings $settings = null;

	/**
	 * Order meta repository.
	 *
	 * @var Order_Meta|null
	 */
	protected ?Order_Meta $order_meta = null;

	/**
	 * Shipping method resolver.
	 *
	 * @var Method_Resolver|null
	 */
	protected ?Method_Resolver $method_resolver = null;

	/**
	 * Booked shipment id accessor.
	 *
	 * @var Shipment_IDs|null
	 */
	protected ?Shipment_IDs $shipment_ids = null;

	/**
	 * Pickup point (agent number) validator.
	 *
	 * @var Pickup_Point_Validator|null
	 */
	protected ?Pickup_Point_Validator $pickup_point_validator = null;

	/**
	 * Order screen meta box component.
	 *
	 * @var Order_Meta_Box|null
	 */
	protected ?Order_Meta_Box $meta_box = null;

	/**
	 * The fulfillment service running the label workflow.
	 *
	 * @var Fulfillment_Service|null
	 */
	protected ?Fulfillment_Service $fulfillment_service = null;

	/**
	 * The order meta box presenter (state + server-rendered form).
	 *
	 * @var Order_Fulfillment_Presenter|null
	 */
	protected ?Order_Fulfillment_Presenter $fulfillment_presenter = null;

	/**
	 * The fulfillment REST controller (smart-send/v1).
	 *
	 * @var Fulfillment_REST_Controller|null
	 */
	protected ?Fulfillment_REST_Controller $fulfillment_controller = null;

	/**
	 * Bulk actions component.
	 *
	 * @var Order_Bulk_Actions|null
	 */
	protected ?Order_Bulk_Actions $bulk_actions = null;

	/**
	 * API test-connection feature.
	 *
	 * @var Test_Connection|null
	 */
	protected ?Test_Connection $test_connection = null;

	/**
	 * Checkout rate sorter.
	 *
	 * @var Rate_Sorter|null
	 */
	protected ?Rate_Sorter $rate_sorter = null;

	/**
	 * Admin notices component used to flash one-time notices after
	 * label-generation actions.
	 *
	 * @var Admin_Notices|null
	 */
	protected ?Admin_Notices $admin_notices = null;

	/**
	 * WooCommerce Subscriptions compatibility component.
	 *
	 * @var Subscriptions_Compat|null
	 */
	protected ?Subscriptions_Compat $subscriptions_compat = null;

	/**
	 * Smart Send Shipping Product
	 *
	 * Public: reachable via SS_SHIPPING_WC() from merchant code snippets.
	 * It is only ever assigned the real Product instance
	 * in the constructor below, so typing it is a deliberate v9 breaking
	 * change - a snippet that replaces it with something else now fatals
	 * instead of silently corrupting state.
	 *
	 * @var Product|null
	 */
	public ?Product $ss_shipping_wc_product = null;

	/**
	 * Smart Send Frontend
	 *
	 * @var Checkout|null
	 */
	protected ?Checkout $ss_shipping_frontend = null;

	/**
	 * Checkout Block integration.
	 *
	 * @var Block_Checkout|null
	 */
	protected ?Block_Checkout $block_checkout = null;

	/**
	 * Store API extensions for the Checkout Block.
	 *
	 * @var Store_API|null
	 */
	protected ?Store_API $store_api = null;

	/**
	 * Pickup point display formatter.
	 *
	 * @var Pickup_Point_Formatter|null
	 */
	protected ?Pickup_Point_Formatter $pickup_point_formatter = null;

	/**
	 * Headless pickup point lookup (API + session cache).
	 *
	 * @var Pickup_Point_Lookup|null
	 */
	protected ?Pickup_Point_Lookup $pickup_point_lookup = null;

	/**
	 * Checkout delivery-option resolution (which sections checkout
	 * renders for a shipping method, pickup point section statuses).
	 *
	 * @var Checkout_Options|null
	 */
	protected ?Checkout_Options $checkout_options = null;

	/**
	 * Smart Send api handle
	 *
	 * @var \Smart_Send\API\API|null
	 */
	protected ?\Smart_Send\API\API $api_handle = null;

	/**
	 * Smart Send Plugin Screen Updates
	 *
	 * @var Plugins_Screen_Updates|null
	 */
	protected ?Plugins_Screen_Updates $ss_plugin_screen_updates = null;

	/**
	 * Construct the plugin.
	 */
	public function __construct() {
		add_action( 'before_woocommerce_init', [ $this, 'declaring_hpos_compatibility' ] );

		$this->define_constants();

		$this->settings = new Settings();

		$this->init_hooks();
	}

	public function declaring_hpos_compatibility() {
		if ( ! $this->is_woocommerce_supported() ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SS_SHIPPING_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SS_SHIPPING_PLUGIN_FILE, true );
	}

	/**
	 * Main Smart Send Shipping Instance.
	 *
	 * Ensures only one instance is loaded or can be loaded.
	 *
	 * @static
	 * @see Plugin()
	 * @return Plugin - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Define WC Constants.
	 */
	private function define_constants() {
		// Stable plugin identity and paths used by the feature components.
		$this->define( 'SS_SHIPPING_PLUGIN_BASENAME', plugin_basename( SS_SHIPPING_PLUGIN_FILE ) );
		$this->define( 'SS_SHIPPING_PLUGIN_DIR_PATH', untrailingslashit( plugin_dir_path( SS_SHIPPING_PLUGIN_FILE ) ) );
		$this->define( 'SS_SHIPPING_PLUGIN_DIR_URL', untrailingslashit( plugins_url( '/', SS_SHIPPING_PLUGIN_FILE ) ) );
		// Mirrors the private $version property for the handful of files
		// (API client, logger, method class, upgrade notices) that need
		// the version number but aren't handed the Plugin instance.
		$this->define( 'SS_SHIPPING_VERSION', $this->version );
		// The literal shipping method id ('smart_send_shipping'). Kept as a
		// global constant so it is available before WooCommerce loads Method.
		$this->define( 'SS_SHIPPING_METHOD_ID', 'smart_send_shipping' );
	}
	protected function init_hooks() {
		add_action( 'init', array( $this, 'init' ), 0 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_filter( 'plugin_action_links_' . SS_SHIPPING_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'ss_shipping_plugin_row_meta' ), 10, 2 );

		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_method' ) );
	}


	/**
	 * Check the dependency before any WooCommerce integration is loaded.
	 *
	 * WordPress checks the plugin dependency, but not its minimum version.
	 * The compatibility declaration and shipping-method filter can run
	 * before init(), so all three entry points share this requirement.
	 *
	 * @return bool
	 */
	private function is_woocommerce_supported(): bool {
		return defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, self::MINIMUM_WC_VERSION, '>=' );
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		if ( $this->is_woocommerce_supported() ) {

			$this->pickup_point_formatter = new Pickup_Point_Formatter( $this->settings );
			$this->pickup_point_lookup    = new Pickup_Point_Lookup( $this->settings );
			$this->order_meta             = new Order_Meta();
			$this->checkout_options       = new Checkout_Options();

			$this->ss_shipping_frontend     = new Checkout( $this->pickup_point_lookup, $this->pickup_point_formatter, $this->settings, $this->order_meta, $this->checkout_options );
			$this->ss_shipping_wc_product   = new Product();
			$this->ss_plugin_screen_updates = new Plugins_Screen_Updates();

			$this->admin_notices          = new Admin_Notices();
			$this->method_resolver        = new Method_Resolver( $this->settings );
			$this->shipment_ids           = new Shipment_IDs();
			$this->pickup_point_validator = new Pickup_Point_Validator( $this->order_meta, $this->method_resolver, $this->pickup_point_lookup );
			$this->fulfillment_presenter  = new Order_Fulfillment_Presenter( $this->order_meta, $this->method_resolver, $this->shipment_ids, $this->pickup_point_formatter, $this->settings, new API_Factory( $this->settings ) );
			$this->meta_box               = new Order_Meta_Box( $this->fulfillment_presenter );
			$this->fulfillment_service    = new Fulfillment_Service(
				$this->order_meta,
				$this->method_resolver,
				$this->shipment_ids,
				new Booking_Service(),
				$this->settings,
				$this->pickup_point_lookup
			);
			$this->fulfillment_controller = new Fulfillment_REST_Controller(
				$this->fulfillment_service,
				$this->fulfillment_presenter,
				$this->shipment_ids,
				$this->method_resolver,
				$this->pickup_point_lookup,
				$this->order_meta
			);
			$this->test_connection        = new Test_Connection( new API_Factory( $this->settings ) );
			$this->rate_sorter            = new Rate_Sorter( $this->settings );
			$this->bulk_actions           = new Order_Bulk_Actions( $this->method_resolver, $this->fulfillment_service, $this->admin_notices );
			$this->subscriptions_compat   = new Subscriptions_Compat();

			$this->block_checkout = new Block_Checkout();
			$this->store_api      = new Store_API( $this->pickup_point_lookup, $this->pickup_point_formatter, $this->settings, $this->order_meta, $this->checkout_options );

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
		$this->fulfillment_controller->register_hooks();
		$this->test_connection->register_hooks();
		$this->rate_sorter->register_hooks();
		$this->bulk_actions->register_hooks();
		$this->subscriptions_compat->register_hooks();
		$this->block_checkout->register_hooks();
		$this->store_api->register_hooks();
	}

	/**
	 * Localisation
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'smart-send-logistics', false, dirname( plugin_basename( SS_SHIPPING_PLUGIN_FILE ) ) . '/lang/' );
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param  string $name
	 * @param  string|bool $value
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Private helper defines only the SS_SHIPPING_* constants above.
		}
	}


	/**
	 * Show action links on the plugin screen.
	 *
	 * @param    mixed $links Plugin Action links
	 * @return    array
	 */
	public static function plugin_action_links( $links ) {
		$settings_url   = esc_url( Settings::settings_screen_url() );
		$settings_label = esc_attr__( 'View WooCommerce settings', 'smart-send-logistics' );

		$action_links = array(
			'settings' => '<a href="' . $settings_url . '" aria-label="' . $settings_label . '">' . esc_html__( 'Settings', 'smart-send-logistics' ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}

	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param    mixed $links Plugin Row Meta
	 * @param    mixed $file Plugin Base file
	 * @return    array
	 */
	public function ss_shipping_plugin_row_meta( $links, $file ) {

		if ( SS_SHIPPING_PLUGIN_BASENAME == $file ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for this formatting pass.
			$configuration_url   = apply_filters( 'smart_send_configuration_url', 'https://smartsend.io/woocommerce/configuration/' );
			$support_url         = apply_filters( 'smart_send_support_url', 'https://smartsend.io/support/' );
			$configuration_label = __( 'Configuration guide', 'smart-send-logistics' );
			$support_label       = __( 'Support', 'smart-send-logistics' );

			$row_meta = array(
				'configuration' => '<a href="' . esc_url( $configuration_url ) . '" title="' . esc_attr( $configuration_label ) . '" target="_blank">' . $configuration_label . '</a>',
				'support'       => '<a href="' . esc_url( $support_url ) . '" title="' . esc_attr( $support_label ) . '" target="_blank">' . $support_label . '</a>',
			);

			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}

	/**
	 * Add a new integration to WooCommerce.
	 */
	public function add_shipping_method( $shipping_method ) {
		if ( ! $this->is_woocommerce_supported() ) {
			return $shipping_method;
		}

		// WooCommerce autoloads the method when it instantiates this registration.
		$shipping_method['smart_send_shipping'] = Method::class;

		return $shipping_method;
	}

	/**
	 * Admin error notifying user that WC is required
	 */
	public function notice_wc_required() {
		?>
		<div class="error">
			<p>
			<?php
			printf(
				/* translators: %s: minimum required WooCommerce version. */
				esc_html__( 'Smart Send requires WooCommerce %s or newer to be installed and activated.', 'smart-send-logistics' ),
				esc_html( self::MINIMUM_WC_VERSION )
			);
			?>
					</p>
		</div>
		<?php
	}

	/**
	 * Get the typed plugin settings reader.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Get the pickup point display formatter.
	 *
	 * @return Pickup_Point_Formatter
	 */
	public function pickup_point_formatter(): Pickup_Point_Formatter {
		return $this->pickup_point_formatter;
	}

	/**
	 * Get the order meta access component.
	 *
	 * @return Order_Meta
	 */
	public function order_meta(): Order_Meta {
		return $this->order_meta;
	}

	/**
	 * Get the shipping method resolver.
	 *
	 * @return Method_Resolver
	 */
	public function method_resolver(): Method_Resolver {
		return $this->method_resolver;
	}

	/**
	 * Get the booked shipment id accessor.
	 *
	 * @return Shipment_IDs
	 */
	public function shipment_ids(): Shipment_IDs {
		return $this->shipment_ids;
	}

	/**
	 * Get the pickup point (agent number) validator.
	 *
	 * @return Pickup_Point_Validator
	 */
	public function pickup_point_validator(): Pickup_Point_Validator {
		return $this->pickup_point_validator;
	}

	/**
	 * Get the fulfillment service running the label workflow.
	 *
	 * @return Fulfillment_Service
	 */
	public function fulfillment(): Fulfillment_Service {
		return $this->fulfillment_service;
	}

	/**
	 * Get the admin notices component.
	 *
	 * @return Admin_Notices
	 */
	public function admin_notices(): Admin_Notices {
		return $this->admin_notices;
	}

	/**
	 * Get the order screen meta box component.
	 *
	 * @return Order_Meta_Box
	 */
	public function meta_box(): Order_Meta_Box {
		return $this->meta_box;
	}

	/**
	 * Get the order meta box presenter (state + server-rendered form).
	 *
	 * @return Order_Fulfillment_Presenter
	 */
	public function fulfillment_presenter(): Order_Fulfillment_Presenter {
		return $this->fulfillment_presenter;
	}

	/**
	 * Get the fulfillment REST controller (smart-send/v1).
	 *
	 * @return Fulfillment_REST_Controller
	 */
	public function fulfillment_controller(): Fulfillment_REST_Controller {
		return $this->fulfillment_controller;
	}

	/**
	 * Get the bulk actions component.
	 *
	 * Reachable directly (not just via hooks) because the Integration
	 * test suite calls add_bulk_order_actions()/handle_bulk_order_actions()
	 * on it outside a real bulk-action request.
	 *
	 * @return Order_Bulk_Actions
	 */
	public function bulk_actions(): Order_Bulk_Actions {
		return $this->bulk_actions;
	}

	/**
	 * Get the shared Smart Send API client, constructed on first use
	 * through the single API_Factory construction path.
	 *
	 * @return \Smart_Send\API\API
	 */
	public function get_api_handle() {
		if ( ! $this->api_handle ) {
			$this->api_handle = ( new API_Factory( $this->settings ) )->create();
		}

		return $this->api_handle;
	}

	/**
	 * Get the API test-connection feature.
	 *
	 * @return Test_Connection
	 */
	public function test_connection(): Test_Connection {
		return $this->test_connection;
	}

	/**
	 * Get the checkout rate sorter.
	 *
	 * @return Rate_Sorter
	 */
	public function rate_sorter(): Rate_Sorter {
		return $this->rate_sorter;
	}

	/**
	 * Get the headless pickup point lookup (API + session cache).
	 *
	 * @return Pickup_Point_Lookup
	 */
	public function pickup_point_lookup(): Pickup_Point_Lookup {
		return $this->pickup_point_lookup;
	}

	/**
	 * Get the checkout delivery-option resolution component.
	 *
	 * @return Checkout_Options
	 */
	public function checkout_options(): Checkout_Options {
		return $this->checkout_options;
	}

	/**
	 * Get the Checkout Block integration.
	 *
	 * @return Block_Checkout
	 */
	public function block_checkout(): Block_Checkout {
		return $this->block_checkout;
	}

	/**
	 * Get the Store API extensions for the Checkout Block.
	 *
	 * @return Store_API
	 */
	public function store_api(): Store_API {
		return $this->store_api;
	}
}
