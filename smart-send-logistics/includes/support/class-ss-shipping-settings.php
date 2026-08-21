<?php
/**
 * Smart Send plugin settings.
 *
 * @package  SS_Shipping_Settings
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Settings' ) ) :

	/**
	 * Typed read access to the plugin's global settings (#140).
	 *
	 * The single place the WooCommerce settings option of the Smart Send
	 * shipping method is read and normalized: every getter owns its
	 * checkbox-to-boolean ('yes'/'no') translation and its missing-key
	 * default, replacing the raw settings-array reads (with hand-rolled
	 * isset/=='yes' checks) that used to be scattered across the
	 * components.
	 *
	 * The KEY_* constants are the settings vocabulary shared with
	 * SS_Shipping_Method_Settings' form field definitions, so each option
	 * key exists exactly once.
	 *
	 * The object is stateless: nothing is cached, every getter reads the
	 * option live (get_option() already memoizes within a request via the
	 * WordPress options cache), so settings saved mid-request are observed
	 * exactly as before.
	 */
	class SS_Shipping_Settings {

		/**
		 * Option key: the Smart Send API token (may hold multiple
		 * site:token pairs - see SS_Shipping_Api_Credentials).
		 *
		 * @var string
		 */
		const KEY_API_TOKEN = 'api_token';

		/**
		 * Option key: demo mode checkbox.
		 *
		 * @var string
		 */
		const KEY_DEMO = 'demo';

		/**
		 * Option key: debug log checkbox.
		 *
		 * @var string
		 */
		const KEY_DEBUG = 'ss_debug';

		/**
		 * Option key: order status to set after label generation.
		 *
		 * @var string
		 */
		const KEY_ORDER_STATUS = 'order_status';

		/**
		 * Option key: Smart Send method used for WooCommerce Free Shipping.
		 *
		 * @var string
		 */
		const KEY_FREE_SHIPPING_METHOD = 'shipping_method_for_free_shipping';

		/**
		 * Option key: include the order comment on the label checkbox.
		 *
		 * @var string
		 */
		const KEY_INCLUDE_ORDER_COMMENT = 'include_order_comment';

		/**
		 * Option key: save a copy of the label PDF in uploads checkbox.
		 *
		 * @var string
		 */
		const KEY_SAVE_LABELS_IN_UPLOADS = 'save_shipping_labels_in_uploads';

		/**
		 * Option key: pickup point drop-down display format.
		 *
		 * @var string
		 */
		const KEY_DROPDOWN_DISPLAY_FORMAT = 'dropdown_display_format';

		/**
		 * Option key: pre-select the closest pickup point checkbox.
		 *
		 * @var string
		 */
		const KEY_DEFAULT_SELECT_AGENT = 'default_select_agent';

		/**
		 * Option key: sort shipping methods by cost checkbox.
		 *
		 * @var string
		 */
		const KEY_SORT_METHODS_BY_COST = 'sort_methods_by_cost';

		/**
		 * Whether demo mode is enabled. An unsaved (missing) setting counts
		 * as demo mode - the plugin never books live without an explicit
		 * opt-out.
		 *
		 * @return boolean
		 */
		public function demo_mode(): bool {
			$value = $this->value( self::KEY_DEMO );

			return empty( $value ) ? true : 'yes' == $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison, kept verbatim from the historic singleton wrapper.
		}

		/**
		 * Whether the "Debug Log" setting is enabled.
		 *
		 * v8 oddity, preserved deliberately: the missing-key default differs
		 * per caller - the logger historically treated a missing key as
		 * enabled while the meta box treated it as disabled - so the caller
		 * declares its default.
		 *
		 * @param boolean $default_when_missing The value to report when the setting has never been saved.
		 *
		 * @return boolean
		 */
		public function debug_log( bool $default_when_missing = false ): bool {
			$value = $this->value( self::KEY_DEBUG );

			return null === $value ? $default_when_missing : 'yes' === $value;
		}

		/**
		 * The raw API token setting, or null when empty. May contain
		 * multiple tokens in the site1:token1,site2:token2 format - resolve
		 * it through SS_Shipping_Api_Credentials.
		 *
		 * @return string|null
		 */
		public function api_token(): ?string {
			$value = $this->value( self::KEY_API_TOKEN );

			return empty( $value ) ? null : (string) $value;
		}

		/**
		 * The order status to set after generating an outbound label, or
		 * null when the merchant chose "Do not change order status".
		 *
		 * @return string|null
		 */
		public function order_status_after_label(): ?string {
			$value = $this->value( self::KEY_ORDER_STATUS );

			return empty( $value ) ? null : (string) $value;
		}

		/**
		 * The Smart Send method mapped onto WooCommerce's native Free
		 * Shipping method, or null when none is configured.
		 *
		 * @return string|null
		 */
		public function shipping_method_for_free_shipping(): ?string {
			$value = $this->value( self::KEY_FREE_SHIPPING_METHOD );

			return empty( $value ) ? null : (string) $value;
		}

		/**
		 * Whether the customer's order comment is printed on the label.
		 *
		 * @return boolean
		 */
		public function include_order_comment(): bool {
			return 'yes' === $this->value( self::KEY_INCLUDE_ORDER_COMMENT );
		}

		/**
		 * Whether a copy of the label PDF is saved in the uploads folder.
		 *
		 * @return boolean
		 */
		public function save_labels_in_uploads(): bool {
			$value = $this->value( self::KEY_SAVE_LABELS_IN_UPLOADS );

			return empty( $value ) ? false : 'yes' == $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison, kept verbatim from the historic singleton wrapper.
		}

		/**
		 * The pickup point drop-down display format id (1-7), or 0 when the
		 * setting has never been saved (the formatter renders its default
		 * block template for 0).
		 *
		 * @return integer
		 */
		public function dropdown_display_format(): int {
			return (int) $this->value( self::KEY_DROPDOWN_DISPLAY_FORMAT );
		}

		/**
		 * Whether the closest pickup point is pre-selected at checkout
		 * (instead of the "- Select Pickup Point -" placeholder).
		 *
		 * @return boolean
		 */
		public function default_select_agent(): bool {
			$value = $this->value( self::KEY_DEFAULT_SELECT_AGENT );

			return null !== $value && 'no' != $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- pre-existing loose comparison, kept verbatim from the historic frontend read.
		}

		/**
		 * Whether shipping methods are sorted by cost at checkout.
		 *
		 * @return boolean
		 */
		public function sort_methods_by_cost(): bool {
			$value = $this->value( self::KEY_SORT_METHODS_BY_COST );

			return ! empty( $value ) && 'yes' == $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison, kept verbatim from the historic singleton read.
		}

		/**
		 * Read one raw settings value, or null when the key (or the whole
		 * option) is missing.
		 *
		 * @param string $key The option key (one of the KEY_* constants).
		 *
		 * @return mixed|null
		 */
		protected function value( string $key ) {
			$settings = get_option( 'woocommerce_' . SS_SHIPPING_METHOD_ID . '_settings' );

			return is_array( $settings ) && isset( $settings[ $key ] ) ? $settings[ $key ] : null;
		}
	}

endif;
