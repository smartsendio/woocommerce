<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send shipping method catalogue.
 *
 * Owns the translated lists of Smart Send shipping methods and return
 * shipping methods per carrier, and the lookup from a method code to its
 * human readable name.
 *
 * @package  SS_Shipping_Method_Catalog
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Method_Catalog' ) ) :

	class SS_Shipping_Method_Catalog {


		private array $shipping_method = array();

		private array $return_shipping_method = array();

		/**
		 * Build the translated catalogue.
		 */
		public function __construct() {
			$this->shipping_method = array(
				'0'                 => __( '- Select Method -', 'smart-send-logistics' ),
				'PostNord'          =>
					array(
						'postnord_agent'                   => __(
							'PostNord: Select pickup point (MyPack Collect)',
							'smart-send-logistics'
						),
						'postnord_collect'                 => __(
							'PostNord: Closest pickup point (MyPack Collect)',
							'smart-send-logistics'
						),
						'postnord_homedelivery'            => __(
							'PostNord: Private delivery to address (MyPack Home)',
							'smart-send-logistics'
						),
						'postnord_doorstep'                => __(
							'PostNord: Leave at door (Flexdelivery)',
							'smart-send-logistics'
						),
						'postnord_flexhome'                => __(
							'PostNord: Flexible home delivery (FlexChange)',
							'smart-send-logistics'
						),
						'postnord_homedeliveryeconomy'     => __(
							'PostNord: Private economy delivery to address (MyPack Home Economy)',
							'smart-send-logistics'
						),
						'postnord_homedeliverysmall'       => __(
							'PostNord: Private delivery to address Small (MyPack Home Small)',
							'smart-send-logistics'
						),
						'postnord_commercial'              => __(
							'PostNord: Commercial delivery to address (Parcel)',
							'smart-send-logistics'
						),
						'postnord_valuableparcel'          => __(
							'PostNord: Valuable parcel',
							'smart-send-logistics'
						),
						'postnord_valuemaillarge'          => __(
							'PostNord: Tracked Valuemail Large',
							'smart-send-logistics'
						),
						'postnord_valuemailmaxi'           => __(
							'PostNord: Tracked Valuemail Maxi',
							'smart-send-logistics'
						),
						'postnord_valuemailfirstclass'     => __(
							'PostNord: Tracked Valuemail First Class',
							'smart-send-logistics'
						),
						'postnord_valuemaileconomy'        => __(
							'PostNord: Tracked Valuemail Economy',
							'smart-send-logistics'
						),
						'postnord_valuemaileco'            => __(
							'PostNord: Tracked Valuemail Eco Friendly',
							'smart-send-logistics'
						),
						'postnord_valuemailuntrackedlarge' => __(
							'PostNord: Untracked Valuemail Large',
							'smart-send-logistics'
						),
						'postnord_valuemailuntrackedmaxi'  => __(
							'PostNord: Untracked Valuemail Maxi',
							'smart-send-logistics'
						),
						'postnord_letterregistered'        => __(
							'PostNord: Registred letter',
							'smart-send-logistics'
						),
						'postnord_lettertracked'           => __(
							'PostNord: Tracked letter',
							'smart-send-logistics'
						),
						'postnord_lettertrackedlarge'      => __(
							'PostNord: Tracked letter Large',
							'smart-send-logistics'
						),
						'postnord_letteruntracked'         => __(
							'PostNord: Untracked letter',
							'smart-send-logistics'
						),
						'postnord_expressletter'           => __(
							'PostNord: Express Letter',
							'smart-send-logistics'
						),
						'postnord_fullpallet'              => __(
							'PostNord: Full size pallet',
							'smart-send-logistics'
						),
						'postnord_halfpallet'              => __(
							'PostNord: Half size pallet',
							'smart-send-logistics'
						),
						'postnord_quarterpallet'           => __(
							'PostNord: Quarter size pallet',
							'smart-send-logistics'
						),
						'postnord_specialpallet'           => __(
							'PostNord: Speciel size pallet',
							'smart-send-logistics'
						),
					),
				'GLS'               =>
					array(
						'gls_agent'        => __( 'GLS: Select pickup point (ParcelShop)', 'smart-send-logistics' ),
						'gls_collect'      => __( 'GLS: Closest pickup point (ParcelShop)', 'smart-send-logistics' ),
						'gls_homedelivery' => __(
							'GLS: Private delivery to address (PrivateDelivery)',
							'smart-send-logistics'
						),
						'gls_doorstep'     => __( 'GLS: Leave at door (DepositService)', 'smart-send-logistics' ),
						'gls_flexhome'     => __( 'GLS: Flexible home delivery (FlexDelivery)', 'smart-send-logistics' ),
						'gls_commercial'   => __(
							'GLS: Commercial delivery to address (BusinessParcel)',
							'smart-send-logistics'
						),
					),
				'DAO'               =>
					array(
						'dao_agent'           => __( 'DAO: Select pickup point (ParcelShop)', 'smart-send-logistics' ),
						'dao_collect'         => __( 'DAO: Closest pickup point (ParcelShop)', 'smart-send-logistics' ),
						'dao_doorstep'        => __( 'DAO: Leave at door (Direct)', 'smart-send-logistics' ),
						'dao_dropoffagent'    => __( 'DAO: From pickup point to pickup point (Shop2Shop)', 'smart-send-logistics' ),
						'dao_dropoffdoorstep' => __( 'DAO: From pickup point to doorstep (ParcelShop to Direct)', 'smart-send-logistics' ),
					),
				'Budbee'            =>
						array(
							'budbee_home' => __( 'Budbee: Home', 'smart-send-logistics' ),
						),
				'Burd'              =>
						array(
							'burd_home' => __( 'Burd: Home Delivery', 'smart-send-logistics' ),
						),
				'Bring'             =>
					array(
						'bring_agent'                   => __(
							'Bring: Select pickup point (PickUp Parcel / Serviceparcel)',
							'smart-send-logistics'
						),
						'bring_bulkagent'               => __(
							'Bring: Select pickup point, send as bulk (PickUp Parcel Bulk)',
							'smart-send-logistics'
						),
						'bring_collect'                 => __(
							'Bring: Closest pickup point (PickUp Parcel / Serviceparcel)',
							'smart-send-logistics'
						),
						'bring_bulkcollect'             => __(
							'Bring: Closest pickup point, send as bulk (PickUp Parcel Bulk)',
							'smart-send-logistics'
						),
						'bring_homedelivery'            => __(
							'Bring: Private delivery to address (Home Delivery Parcel)',
							'smart-send-logistics'
						),
						'bring_commercial'              => __(
							'Bring: Commercial delivery to address (Business Parcel)',
							'smart-send-logistics'
						),
						'bring_bulkcommercial'          => __(
							'Bring: Commercial delivery to address, send as bulk (Business Parcel Bulk)',
							'smart-send-logistics'
						),
						'bring_commercialfullpallet'    => __(
							'Bring: Commercial delivery of full size pallet (Business Pallet)',
							'smart-send-logistics'
						),
						'bring_commercialhalfpallet'    => __(
							'Bring: Commercial delivery of half size pallet (Business Pallet)',
							'smart-send-logistics'
						),
						'bring_commercialquarterpallet' => __(
							'Bring: Commercial delivery of quarter size pallet (Business Pallet)',
							'smart-send-logistics'
						),
						'bring_express9'                => __(
							'Bring: Express delivery before 9:00 (Express Nordic 09:00)',
							'smart-send-logistics'
						),
						'bring_bulkexpress9'            => __(
							'Bring: Express delivery before 9:00, send as bulk (Express Nordic 09:00 Bulk)',
							'smart-send-logistics'
						),
					),
				'Bifrost Logistics' =>
					array(
						// eTail Tracked
						'bifrost_etailtracked'      => __(
							'Bifrost Logistics: eTail Tracked',
							'smart-send-logistics'
						),
						'bifrost_etailtrackedlarge' => __(
							'Bifrost Logistics: eTail Tracked large',
							'smart-send-logistics'
						),
						// eTail Gain
						'bifrost_etailgainsmall'    => __(
							'Bifrost Logistics: eTail Gain small',
							'smart-send-logistics'
						),
						'bifrost_etailgainlarge'    => __(
							'Bifrost Logistics: eTail Gain large',
							'smart-send-logistics'
						),
						// Express
						'bifrost_expresscollect'    => __(
							'Bifrost Logistics: Nordic Express Collect',
							'smart-send-logistics'
						),
						'bifrost_expresshome'       => __(
							'Bifrost Logistics: Nordic Express Home',
							'smart-send-logistics'
						),
						// phpcs:disable Squiz.PHP.CommentedOutCode.Found -- deliberately disabled catalogue entries kept as documentation of not-yet-offered Bifrost methods.
					/*
						// Letter Priority
						'bifrost_letterprioritysmall'     => __('Bifrost Logistics: Letter priority small',
							'smart-send-logistics'),
						'bifrost_letterprioritylarge'     => __('Bifrost Logistics: Letter priority large',
							'smart-send-logistics'),
						'bifrost_letterprioritymaxi'      => __('Bifrost Logistics: Letter priority maxi',
							'smart-send-logistics'),
						//Letter Economy
						'bifrost_lettereconomysmall'      => __('Bifrost Logistics: Letter economy small',
							'smart-send-logistics'),
						'bifrost_lettereconomylarge'      => __('Bifrost Logistics: Letter economy large',
							'smart-send-logistics'),
						'bifrost_lettereconomymaxi'       => __('Bifrost Logistics: Letter economy maxi',
							'smart-send-logistics'),
						// Press Priority
						'bifrost_pressprioritylarge'      => __('Bifrost Logistics: Press priority large',
							'smart-send-logistics'),
						'bifrost_pressprioritymaxi'       => __('Bifrost Logistics: Press priority maxi',
							'smart-send-logistics'),
						// Advertising Economy
						'bifrost_advertisingeconomysmall' => __('Bifrost Logistics: Advertising economy small',
							'smart-send-logistics'),
						'bifrost_advertisingeconomylarge' => __('Bifrost Logistics: Advertising economy large',
							'smart-send-logistics'),
						'bifrost_advertisingeconomymaxi'  => __('Bifrost Logistics: Advertising economy maxi',
							'smart-send-logistics'),
						// Ecom priority large
						'bifrost_ecomprioritylarge'       => __('Bifrost Logistics: Ecom priority large',
							'smart-send-logistics'),
						'bifrost_ecomprioritymaxi'        => __('Bifrost Logistics: Ecom priority maxi',
							'smart-send-logistics'),
						*/
					// phpcs:enable Squiz.PHP.CommentedOutCode.Found
					),
			);

			$this->return_shipping_method = array(
				'0'        => __( '- Select Method -', 'smart-send-logistics' ),
				'PostNord' =>
					array(
						'postnord_returndropoff' => __(
							'PostNord: Return from pickup point (Return Drop Off)',
							'smart-send-logistics'
						),
						'postnord_returnpickup'  => __(
							'PostNord: Return from address (Return Pickup)',
							'smart-send-logistics'
						),
					),
				'GLS'      =>
					array(
						'gls_returndropoff' => __(
							'GLS: Return from pickup point (ShopReturn)',
							'smart-send-logistics'
						),
					),
				'DAO'      =>
					array(
						'dao_returndropoff' => __(
							'DAO: Return from pickup point (ParcelShop Return)',
							'smart-send-logistics'
						),
					),
				'Bring'    =>
					array(
						'bring_returndropoff' => __(
							'Bring: Return from pickup point (PickUp Parcel Return)',
							'smart-send-logistics'
						),
						'bring_returnpickup'  => __(
							'Bring: Return from address (Parcel Return)',
							'smart-send-logistics'
						),
					),
			);
		}

		/**
		 * The available Smart Send shipping methods, grouped by carrier.
		 *
		 * @return array
		 */
		public function get_shipping_methods() {
			return $this->shipping_method;
		}

		/**
		 * The available Smart Send return shipping methods, grouped by carrier.
		 *
		 * @return array
		 */
		public function get_return_shipping_methods() {
			return $this->return_shipping_method;
		}
		/**
		 * Get the human readable name of the Smart Send shipping method
		 * Example: 'PostNord: Closest pickup point (MyPack Collect)'
		 *
		 * Details: This method look for valid method with code $shipping_method_code
		 * in the $shipping_method array from this SS_Shipping_WC_Method class
		 *
		 * @param string $shipping_method_code    Id that identifies the Smart Send method. Example 'postnord_collect'
		 * @return string
		 */
		public function get_shipping_method_name( $shipping_method_code ) {
			if ( $this->shipping_method ) {
				foreach ( $this->shipping_method as $carrier_name => $carrier_code ) {
					if ( is_array( $carrier_code ) ) {
						foreach ( $carrier_code as $method_code => $method_name ) {
							if ( $method_code == $shipping_method_code ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
								return $method_name;
							}
						}
					}
				}
			}
			return '';
		}
	}

endif;
