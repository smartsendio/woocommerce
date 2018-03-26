<?php
/**
 * Plugin Name: Smart Send Shipping for WooCommerce
 * Plugin URI: https://github.com/
 * Description: Smart Send Shipping for WooCommerce
 * Author: Smart Send ApS
 * Author URI: http://www.smartsend.dk
 * Version: 8.0.0
 * WC requires at least: 2.6.0
 * WC tested up to: 3.3
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

	private $version = "8.0.0";

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


	protected $agents_address_format = array();

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
		$this->define( 'SS_SHIPPING_BUTTON_TEST_CONNECTION', __( 'Test Connection', 'smart-send-shipping' ) );

		$this->define( 'SS_SHIPPING_METHOD_ID', 'smart_send_shipping' );

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
		
		// add_action( 'admin_notices', array( $tif ( WC_PLUGIN_BASENAME == $file ) {
		add_action( 'admin_enqueue_scripts', array( $this, 'ss_shipping_theme_enqueue_styles') );		

		// add_action( 'woocommerce_shipping_init', array( $this, 'includes' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_method' ) );
		// Test connection
		add_action( 'wp_ajax_test_as_connection', array( $this, 'ss_shipping_test_connection_callback' ) );
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

	public function ss_shipping_theme_enqueue_styles() {
		wp_enqueue_style( 'ss-shipping-admin-css', SS_SHIPPING_PLUGIN_DIR_URL . '/assets/css/ss-shipping-admin.css' );
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
				'installation'	=> '<a href="' . esc_url( apply_filters( 'smartsend_logistics_installation_url', 'https://smartsend.io/woocommerce/installation/' ) ) . '" title="' . esc_attr( __( 'Installation guide','smart-send-shipping' ) ) . '" target="_blank">' . __( 'Installation guide','smart-send-shipping' ) . '</a>',
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

	public function get_base_country() {
		$origin_point = wc_get_base_location();
		return $origin_point['country'];
	}

	public function get_ss_shipping_settings( ) {
		return get_option('woocommerce_' . SS_SHIPPING_METHOD_ID . '_settings');
	}

	public function ss_shipping_test_connection_callback() {
        // Is this used? And why do we have a try/catch here?
		check_ajax_referer( 'ss-shipping-test-con', 'test_con_nonce' );
		try {

			$shipping_as_settings = $this->get_shipping_as_settings();
		
			$api_user = $shipping_as_settings['as_api_user']; 
			$api_pwd = $shipping_as_settings['as_api_pwd'];
		
			$connection = $as_obj->as_test_connection( $api_user, $api_pwd );
				
			$connection_msg = __('Connection Successful!', 'smart-send-shipping');
			$this->log_msg( $connection_msg );

			wp_send_json( array( 
				'connection_success' 	=> $connection_msg,
				'button_txt'			=> SS_SHIPPING_BUTTON_TEST_CONNECTION
				) );

		} catch (Exception $e) {
			$this->log_msg($e->getMessage());

			wp_send_json( array( 
				'connection_error' => sprintf( __('Connected Failed: %s Make sure to save the settings before testing the connection. ', 'smart-send-shipping'), $e->getMessage() ),
				'button_txt'			=> SS_SHIPPING_BUTTON_TEST_CONNECTION
				 ) );
		}

		wp_die();
	}

	public function log_msg( $msg )	{
		// Why do we have a try/catch here?
		try {
			$shipping_as_settings = $this->get_shipping_as_settings();
			$as_debug = isset( $shipping_as_settings['as_debug'] ) ? $shipping_as_settings['as_debug'] : 'yes';
			
			if( ! $this->logger ) {
				$this->logger = new SS_Shipping_Logger( $as_debug );
			}

			$this->logger->write( $msg );
			
		} catch (Exception $e) {
			// do nothing
		}
	}

	public function get_log_url( )	{
        // Why do we have a try/catch here?
		try {
			$shipping_as_settings = $this->get_shipping_as_settings();
			$as_debug = isset( $shipping_as_settings['as_debug'] ) ? $shipping_as_settings['as_debug'] : 'yes';
			
			if( ! $this->logger ) {
				$this->logger = new SS_Shipping_Logger( $as_debug );
			}
			
			return $this->logger->get_log_url( );
			
		} catch (Exception $e) {
			throw $e;
		}
	}

	public function get_agents_address_format()	{
		return $this->agents_address_format;
	}

	public function get_ss_shipping_wc_order() {
		return $this->ss_shipping_wc_order;
	}

	public function get_shipping_method_carrier( $ship_method ) {
		
		$ship_method_parts = $this->get_shipping_method_part( $ship_method );

		// Might be 2 parts or 3 because of 'free shipping' option
		$arr_size = sizeof($ship_method_parts);

		if ( $ship_method_parts[ $arr_size - 2 ] ) {
			return $ship_method_parts[ $arr_size - 2 ];
		}

		return $ship_method;
	}

	public function get_shipping_method_type( $ship_method ) {
		
		$ship_method_parts = $this->get_shipping_method_part( $ship_method );

		// Might be 2 parts or 3 because of 'free shipping' option
		$arr_size = sizeof($ship_method_parts);

		if ( $ship_method_parts[ $arr_size - 1 ] ) {
			return $ship_method_parts[ $arr_size - 1 ];
		}

		return $ship_method;
	}

	public function get_shipping_method_part( $ship_method ) {
		
		if( empty( $ship_method ) ) {
			return $ship_method;
		}

		// Assumes format 'name:instance_carrier_method' or 'instance_carrier_method'
		// error_log($ship_method);
		$new_ship_method = explode(':', $ship_method );
		// error_log(print_r($new_ship_method,true));

		// If no ':' included then will be 1 array and should explode that item
		$arr_size = sizeof($new_ship_method);
		// error_log($arr_size);

		if ( isset($new_ship_method[ $arr_size - 1 ] ) ) {
			// error_log('set val');
			// error_log($new_ship_method[ $arr_size - 1 ]);
			// Assumes format 'instance_carrier_method'
			return explode('_', $new_ship_method[ $arr_size - 1 ] );
		}

		return $new_ship_method;
	}
}

endif;

function SS_SHIPPING_WC() {
	return SS_Shipping_WC::instance();
}

$SS_Shipping_WC = SS_SHIPPING_WC();
