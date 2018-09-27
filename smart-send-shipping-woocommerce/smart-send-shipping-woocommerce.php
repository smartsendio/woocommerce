<?php
/**
 * Plugin Name: Smart Send Shipping for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/smart-send-logistics/
 * Description: Smart Send Shipping for WooCommerce
 * Author: Smart Send ApS
 * Author URI: http://www.smartsend.io
 * Version: 8.0.0b8
 * WC requires at least: 2.6.0
 * WC tested up to: 3.4
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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'SS_Shipping_WC' ) ) :

class SS_Shipping_WC {

	private $version = "8.0.0b8";

	/**
	 * Instance to call certain functions globally within the plugin
	 *
	 * @var SS_Shipping_WC
	 */
	protected static $_instance = null;
	
	/**
	 * Smart Send Shipping Order for label and tracking.
	 *
	 * @var SS_Shipping_WC_Order
	 */
	public $ss_shipping_wc_order = null;/**
	 
	 * Smart Send Shipping Product
	 *
	 * @var SS_Shipping_WC_Order
	 */
	public $ss_shipping_wc_product = null;

	/**
	 * Smart Send Frontend
	 *
	 * @var SS_Shipping_Frontend
	 */
	protected $ss_shipping_frontend = null;
	
	/**
	 * Smart Send Shipping Order for label and tracking.
	 *
	 * @var SS_Shipping_Logger
	 */
	protected $logger = null;

	/**
	 * Smart Send agent address formats
	 *
	 * @var array
	 */
	protected $agents_address_format = array();

	/**
	 * Smart Send api handle
	 *
	 * @var object
	 */
	protected $api_handle = null;

	/**
	* Construct the plugin.
	*/
	public function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();

		$this->agents_address_format = array(
					'1' 		=> __('#Company', 'smart-send-shipping') . ', ' . __('#Street','smart-send-shipping'),
					'2'    		=> __('#Company', 'smart-send-shipping') . ', ' . __('#Street','smart-send-shipping') . ', ' .__('#Zipcode','smart-send-shipping'),
					'3'    		=> __('#Company', 'smart-send-shipping') . ', ' . __('#Street','smart-send-shipping') . ', ' . __('#City','smart-send-shipping'),
					'4'    		=> __('#Company', 'smart-send-shipping') . ', ' . __('#Street','smart-send-shipping') . ', ' .__('#Zipcode','smart-send-shipping').' ' . __('#City','smart-send-shipping'),
					'5'    		=> __('#Company', 'smart-send-shipping') . ', ' .__('#Zipcode','smart-send-shipping'),
					'6'    		=> __('#Company', 'smart-send-shipping') . ', ' .__('#Zipcode','smart-send-shipping') . ', ' . __('#City','smart-send-shipping'),
					'7'    		=> __('#Company', 'smart-send-shipping') . ', ' . __('#City','smart-send-shipping'),
				);
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
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Define WC Constants.
	 */
	private function define_constants() {
		$upload_dir = wp_upload_dir();

		// Path related defines
		$this->define( 'SS_SHIPPING_PLUGIN_FILE', __FILE__ );
		$this->define( 'SS_SHIPPING_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		$this->define( 'SS_SHIPPING_PLUGIN_DIR_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
		$this->define( 'SS_SHIPPING_PLUGIN_DIR_URL', untrailingslashit( plugins_url( '/', __FILE__ ) ) );
		$this->define( 'SS_SHIPPING_VERSION', $this->version );
		$this->define( 'SS_SHIPPING_LOG_DIR', $upload_dir['basedir'] . '/wc-logs/' );
		$this->define( 'SS_SHIPPING_METHOD_ID', 'smart_send_shipping' );
		$this->define( 'SS_BUTTON_TEST_CONNECTION', __('Validate API Token', 'smart-send-shipping' ) );
	}
	
	/**
	 * Include required core files used in admin and on the frontend.
	 */
	public function includes() {
		// Auto loader class
		include_once( 'includes/class-ss-shipping-autoloader.php' );
		include_once( 'includes/lib/Smartsend/Api.php' );
	}

	public function init_hooks() {
		add_action( 'init', array( $this, 'init' ), 0 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_filter( 'plugin_action_links_' . SS_SHIPPING_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'ss_shipping_plugin_row_meta'), 10, 2 );
		
		add_action( 'admin_enqueue_scripts', array( $this, 'ss_shipping_theme_enqueue_admin_styles') );
        add_action( 'wp_enqueue_scripts', array( $this, 'ss_shipping_theme_enqueue_frontend_styles') );

		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_method' ) );

		// Test connection
        add_action( 'wp_ajax_ss_test_connection', array( $this, 'ss_test_connection_callback' ) );
	}


	/**
	* Initialize the plugin.
	*/
	public function init() {
		
		// Checks if WooCommerce 2.6 is installed.
		if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.6', '>=' ) ) {
			$this->ss_shipping_frontend = new SS_Shipping_Frontend();
			$this->ss_shipping_wc_order = new SS_Shipping_WC_Order();
			$this->ss_shipping_wc_product = new SS_Shipping_WC_Product();
		} else {
			// Throw an admin error informing the user this plugin needs WooCommerce to function
			add_action( 'admin_notices', array( $this, 'notice_wc_required' ) );
		}

	}

	/**
	 * Localisation
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'smart-send-shipping', false, dirname( plugin_basename(__FILE__) ) . '/lang/' );
	}

	/**
	 * Load Admin CSS 
	 */
	public function ss_shipping_theme_enqueue_admin_styles() {
        wp_enqueue_style( 'ss-shipping-admin-css', SS_SHIPPING_PLUGIN_DIR_URL . '/assets/css/ss-shipping-admin.css' );
	}

    /**
     * Load Frontend CSS
     */
    public function ss_shipping_theme_enqueue_frontend_styles() {
        wp_enqueue_style( 'ss-shipping-frontend-css', SS_SHIPPING_PLUGIN_DIR_URL . '/assets/css/ss-shipping-frontend.css' );
    }

	/**
	 * Define constant if not already set.
	 *
	 * @param  string $name
	 * @param  string|bool $value
	 */
	public function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}


	/**
	 * Show action links on the plugin screen.
	 *
	 * @param	mixed $links Plugin Action links
	 * @return	array
	 */
	public static function plugin_action_links( $links ) {
		$action_links = array(
			'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping' ) . '" aria-label="' . esc_attr__( 'View WooCommerce settings', 'smart-send-shipping' ) . '">' . esc_html__( 'Settings', 'smart-send-shipping' ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}

	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param	mixed $links Plugin Row Meta
	 * @param	mixed $file  Plugin Base file
	 * @return	array
	 */
	function ss_shipping_plugin_row_meta( $links, $file ) {

		if ( SS_SHIPPING_PLUGIN_BASENAME == $file ) {
			$row_meta = array(
				'configuration'	=> '<a href="' . esc_url( apply_filters( 'smartsend_logistics_configuration_url', 'https://smartsend.io/woocommerce/configuration/' ) ) . '" title="' . esc_attr( __( 'Configuration guide','smart-send-shipping' ) ) . '" target="_blank">' . __( 'Configuration guide','smart-send-shipping' ) . '</a>',
				'support'		=> '<a href="' . esc_url( apply_filters( 'smartsend_logistics_support_url', 'https://smartsend.io/support/' ) ) . '" title="' . esc_attr( __( 'Support','smart-send-shipping' ) ) . '" target="_blank">' . __( 'Support','smart-send-shipping' ) . '</a>',
			);
			
			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}
	
	/**
	 * Add a new integration to WooCommerce.
	 */
	public function add_shipping_method( $shipping_method ) {
		$ss_shipping_shipping_method = 'SS_Shipping_WC_Method';
		$shipping_method['smart_send_shipping'] = $ss_shipping_shipping_method;

		return $shipping_method;
	}

	/**
	 * Admin error notifying user that WC is required
	 */
	public function notice_wc_required() {
	?>
		<div class="error">
			<p><?php _e( 'Smart Send Shipping requires WooCommerce 2.6 and above to be installed and activated!', 'smart-send-shipping' ); ?></p>
		</div>
	<?php
	}

	/**
	 * Get Smart Send Shipping settings
	 */
	public function get_ss_shipping_settings( ) {
		return get_option('woocommerce_' . SS_SHIPPING_METHOD_ID . '_settings');
	}

	/**
	 * Log debug message
	 */
	public function log_msg( $msg )	{
		$shipping_ss_settings = $this->get_ss_shipping_settings();
		$ss_debug = isset( $shipping_ss_settings['ss_debug'] ) ? $shipping_ss_settings['ss_debug'] : 'yes';
			
		if( ! $this->logger ) {
			$this->logger = new SS_Shipping_Logger( $ss_debug );
		}

		$this->logger->write( $msg );		
	}

	/**
	 * Get debug log file URL
	 */
	public function get_log_url( )	{
      	$shipping_ss_settings = $this->get_ss_shipping_settings();
		$ss_debug = isset( $shipping_ss_settings['ss_debug'] ) ? $shipping_ss_settings['ss_debug'] : 'yes';
		
		if( ! $this->logger ) {
			$this->logger = new SS_Shipping_Logger( $ss_debug );
		}
		
		return $this->logger->get_log_url( );		
	}

	/**
	 * Get Agent Address Format
	 */
	public function get_agents_address_format()	{
		return $this->agents_address_format;
	}

	/**
	 * Get Smart Shipping Order Object
	 */
	public function get_ss_shipping_wc_order() {
		return $this->ss_shipping_wc_order;
	}

	/**
	 * Shipping Method Carrier
	 */
	public function get_shipping_method_carrier( $ship_method ) {
		
		$ship_method_parts = $this->get_shipping_method_part( $ship_method );

		$arr_size = sizeof($ship_method_parts);

		if ( isset( $ship_method_parts[0] ) ) {
			return $ship_method_parts[0];
		}

		return $ship_method;
	}

	/**
	 * Shipping Method Type
	 */
	public function get_shipping_method_type( $ship_method ) {
		
		$ship_method_parts = $this->get_shipping_method_part( $ship_method );

		$arr_size = sizeof($ship_method_parts);

		if ( isset( $ship_method_parts[1] ) ) {
			return $ship_method_parts[1];
		}

		return $ship_method;
	}

	/**
	 * Shipping Method helper function
	 */
	protected function get_shipping_method_part( $ship_method ) {
		
		if( empty( $ship_method ) ) {
			return $ship_method;
		}

		// Assumes format 'carrier_type'
		$new_ship_method = explode('_', $ship_method );

		return $new_ship_method;
	}

	public function get_api_handle() {
		
		if( ! $this->api_handle ) {
			$ss_shipping_settings = $this->get_ss_shipping_settings();

			if( ! empty( $ss_shipping_settings['api_token'] ) ) {
				// Initiate an API handle with the login credentials.
                $demo_mode = (!isset($ss_shipping_settings['demo']) || $ss_shipping_settings['demo'] == 'yes');//default is yes
                $webshop_url = parse_url(get_site_url(),PHP_URL_HOST) . parse_url(get_site_url(),PHP_URL_PATH);
                $this->api_handle = new \Smartsend\Api( $ss_shipping_settings['api_token'], $webshop_url, $demo_mode );
			} else {
				return false;
			}

		}

		return $this->api_handle;
	}

	public function validate_api_token() {

		if ( $this->get_api_handle() ) {
			if( $this->api_handle->getAuthenticatedUser() ) {
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * Test connection AJAX call
	 */
	public function ss_test_connection_callback() {
		check_ajax_referer( 'ss-test-connection', 'test_connection_nonce' );

		if( $this->validate_api_token() ) {
			$connection_msg = sprintf(__('API Token verified: Connected to Smart Send as %s from %s', 'smart-send-shipping'),$this->get_api_handle()->getData()->email, $this->get_api_handle()->getData()->website);
			$error = 0;
		} else {
			$connection_msg = sprintf(__('API Token validation failed: %s. Make sure to save the settings before testing the connection.', 'smart-send-shipping'), $this->get_api_handle()->getError()->message);
			$error = 1;
		}

		$this->log_msg( $connection_msg );

		wp_send_json( array( 
			'message' 			=> $connection_msg,
			'error' 			=> $error,
			'button_txt'		=> SS_BUTTON_TEST_CONNECTION
			) );

		wp_die();
	}
}

endif;

function SS_SHIPPING_WC() {
	return SS_Shipping_WC::instance();
}

$SS_Shipping_WC = SS_SHIPPING_WC();
