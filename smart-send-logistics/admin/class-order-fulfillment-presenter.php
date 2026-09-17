<?php

namespace Smart_Send\Admin;

use Smart_Send\Booking\Booked_Parcel;
use Smart_Send\Booking\Booked_Shipment;
use Smart_Send\Booking\Exceptions\Booking_Exception;
use Smart_Send\Booking\Order_Reader;
use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Delivery_Options\Pickup_Point_Formatter;
use Smart_Send\Fulfillment\Fulfillment_Result;
use Smart_Send\Fulfillment\Shipment_IDs;
use Smart_Send\Shipping_Method\Method_Catalog;
use Smart_Send\Shipping_Method\Method_Code;
use Smart_Send\Support\API_Factory;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;
use WC_Meta_Box_Order_Notes;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send order fulfillment presenter.
 *
 * Builds the one STATE object the order screen's "Smart Send" meta box
 * renders from (#182): the same array is inlined at first render
 * (window.smartSendOrderFulfillment), returned by the GET fulfillment
 * route and sent back after every POST. The box has ONE renderer and it
 * is the React app - this class produces no layout. What it does format
 * are values JS cannot reproduce faithfully: the pickup point block, the
 * booked-at display, the parcel weight/dimension strings in the store's
 * units and the order note as WooCommerce's own list item. It also shapes
 * the REST response of a fulfillment run
 * (Fulfillment_Result::to_array() plus the per-field mapping
 * and the rendered order note) and is the ONE place on the admin side
 * that knows the Smart Send API v1 field names (map_api_field()).
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Order_Fulfillment_Presenter {

	/**
	 * The REST namespace of the fulfillment routes (mirrors
	 * Fulfillment_REST_Controller::REST_NAMESPACE; the
	 * presenter builds the path the state advertises).
	 */
	const REST_NAMESPACE = 'smart-send/v1';

	/**
	 * The meta box states (section 1.2 of #182), exposed on the
	 * rendered form as data-ss-state for stable selectors.
	 */
	const STATE_NOT_CONNECTED = 'not_connected';
	const STATE_NO_METHOD     = 'no_method';
	const STATE_READY         = 'ready';
	const STATE_BOOKED        = 'booked';

	/**
	 * Order meta repository.
	 *
	 * @var Order_Meta
	 */
	protected Order_Meta $order_meta;

	/**
	 * Shipping method resolver.
	 *
	 * @var Method_Resolver
	 */
	protected Method_Resolver $method_resolver;

	/**
	 * Booked shipment id accessor.
	 *
	 * @var Shipment_IDs
	 */
	protected Shipment_IDs $shipment_ids;

	/**
	 * Pickup point display formatter.
	 *
	 * @var Pickup_Point_Formatter
	 */
	protected Pickup_Point_Formatter $pickup_point_formatter;

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * The translated method catalogue, built on first use (its
	 * constructor translates, which must not happen before init).
	 *
	 * @var Method_Catalog|null
	 */
	protected ?Method_Catalog $catalog = null;

	/**
	 * API client factory - used for its host resolution only (the
	 * smart_send_api_endpoint filter), which is where the link to a
	 * shipment in the Smart Send app comes from.
	 *
	 * @var API_Factory
	 */
	protected API_Factory $api_factory;

	/**
	 * @param Order_Meta             $order_meta             Order meta repository.
	 * @param Method_Resolver        $method_resolver        Shipping method resolver.
	 * @param Shipment_IDs           $shipment_ids           Booked shipment id accessor.
	 * @param Pickup_Point_Formatter $pickup_point_formatter Pickup point display formatter.
	 * @param Settings|null          $settings               Typed plugin settings reader (stateless; a fresh default is safe).
	 * @param API_Factory|null       $api_factory            API client factory (stateless; a fresh default is safe).
	 */
	public function __construct( Order_Meta $order_meta, Method_Resolver $method_resolver, Shipment_IDs $shipment_ids, Pickup_Point_Formatter $pickup_point_formatter, ?Settings $settings = null, ?API_Factory $api_factory = null ) {
		$this->order_meta             = $order_meta;
		$this->method_resolver        = $method_resolver;
		$this->shipment_ids           = $shipment_ids;
		$this->pickup_point_formatter = $pickup_point_formatter;
		$this->settings               = null === $settings ? new Settings() : $settings;
		$this->api_factory            = null === $api_factory ? new API_Factory( $this->settings ) : $api_factory;
	}

	/**
	 * The meta box state of an order (section 3.3 of #182):
	 *
	 *   array(
	 *     'order_id'          => int,
	 *     'connected'         => bool,            // an API token is configured
	 *     'screen'            => 'hpos'|'legacy',
	 *     'order'             => array( 'weight_kg' => float, 'shipping_country' => string,
	 *                                   'units' => array( array( 'id' => int, 'name' => string, 'unit_weight' => float ), ... ) ), // one row per unit
	 *     'delivery_details'  => array( 'shipping_method' => string|null, 'pickup_point' => array|null (to_array() + 'display_html'),
	 *                                   'parcel_plan' => array|null (to_array()), 'addons' => array ),
	 *     'methods'           => array( 'outbound' => array( array( 'code' => string, 'name' => string,
	 *                                       'services' => array( array( 'code' => string, 'name' => string, 'addons' => array() ), ... ) ), ... ),
	 *                                   'return' => ... ), // the smart_send_fulfillment_shipping_methods filter applies per list
	 *     'return'            => array( 'method' => string|null, 'auto_default' => bool, 'uses_stored_pickup_point' => bool ),
	 *     'outbound_shipment' => array( 'shipment_id' => string, 'app_url' => string )|null,  // the LATEST id of the direction (Decisions, #182: the booked DTO is not persisted)
	 *     'return_shipment'   => array( 'shipment_id' => string, 'app_url' => string )|null,
	 *     'timeline'          => array( array( 'direction' => 'outbound'|'return', 'shipment_id' => string, 'app_url' => string,
	 *                                          'booked_at' => string|null, 'booked_at_display' => string|null ), ... ), // newest first
	 *     'debug'             => array( 'enabled' => bool, 'shipping_items' => string[] ),
	 *     'urls'              => array( 'settings' => string, 'rest' => string ),
	 *   )
	 *
	 * @param WC_Order $order The WooCommerce order.
	 *
	 * @return array
	 */
	public function state( WC_Order $order ): array {
		$order_id = $order->get_id();
		$details  = $this->order_meta->read( $order );
		$units    = $this->order_units( $order );

		$outbound_method = $this->method_resolver->resolve_outbound( $order );

		try {
			$return_method = $this->method_resolver->resolve_return( $order );
		} catch ( Booking_Exception $e ) {
			$return_method = '';
		}

		$pickup_point = $details->get_pickup_point();

		$shipping_items = array();
		foreach ( $order->get_shipping_methods() as $method ) {
			$shipping_items[] = $method->get_method_id() . ':' . $method->get_instance_id();
		}

		return array(
			'order_id'          => $order_id,
			'parcel_plan_error' => $this->order_meta->parcel_plan_error( $order ),
			'connected'         => $this->is_connected(),
			'screen'            => $this->screen(),
			'order'             => array(
				'weight_kg'        => in_array( null, wp_list_pluck( $units, 'unit_weight' ), true ) ? null : round( array_sum( wp_list_pluck( $units, 'unit_weight' ) ), 2 ),
				'shipping_country' => (string) $order->get_shipping_country(),
				'units'            => $units,
			),
			'delivery_details'  => array(
				'shipping_method' => '' === $outbound_method ? null : $outbound_method,
				'pickup_point'    => null === $pickup_point ? null : $this->pickup_point_state( $pickup_point ),
				'parcel_plan'     => null === $details->get_parcel_plan() ? null : $details->get_parcel_plan()->to_array(),
				'addons'          => $details->get_addons(),
			),
			'methods'           => array(
				'outbound' => $this->offered_methods( $order, false, $outbound_method ),
				'return'   => $this->offered_methods( $order, true, $return_method ),
			),
			'return'            => array(
				'method'                   => '' === $return_method ? null : $return_method,
				'auto_default'             => $this->method_resolver->is_auto_return_enabled( $order ),
				'uses_stored_pickup_point' => $this->method_resolver->return_uses_stored_pickup_point( $order ),
			),
			'outbound_shipment' => $this->shipment_state( $order, false ),
			'return_shipment'   => $this->shipment_state( $order, true ),
			'timeline'          => $this->timeline( $order ),
			'debug'             => array(
				'enabled'        => $this->settings->debug_log(),
				'shipping_items' => $shipping_items,
			),
			'urls'              => array(
				'settings' => Settings::settings_screen_url(),
				'rest'     => '/' . self::REST_NAMESPACE . '/orders/' . $order_id . '/fulfillment',
			),
		);
	}

	/**
	 * A pickup point as the state carries it: its to_array() form plus
	 * the meta box address block (display_html, already passed through
	 * wp_kses_post so it is safe to print).
	 *
	 * @param Pickup_Point $pickup_point The pickup point.
	 *
	 * @return array
	 */
	public function pickup_point_state( Pickup_Point $pickup_point ): array {
		$state                 = $pickup_point->to_array();
		$state['display_html'] = wp_kses_post( $this->pickup_point_formatter->format_admin_block( $pickup_point ) );

		return $state;
	}

	/**
	 * The REST response of a fulfillment run (section 3.2 of #182):
	 *
	 *   array( 'order_id' => int, 'flow' => 'outbound'|'return', 'success' => bool,
	 *          'shipments' => Fulfillment_Result::to_array() with the booked shipment enriched
	 *                         for display (shipment_response()) and
	 *                         'error.form_fields' (the API field errors mapped onto form fields) added,
	 *          'state' => state( $order ) after the run )
	 *
	 * @param Fulfillment_Result $result The completed run.
	 * @param WC_Order                       $order  The WooCommerce order.
	 * @param string|null                    $flow   The requested flow; derived from the first leg when omitted.
	 *
	 * @return array
	 */
	public function response( Fulfillment_Result $result, WC_Order $order, ?string $flow = null ): array {
		$shipments = $result->to_array();

		foreach ( $shipments as &$row ) {
			if ( 'fulfilled' === $row['status'] ) {
				$row['shipment'] = $this->shipment_response( $row['shipment'] );
			} else {
				$row['error']['form_fields'] = $this->map_api_fields( $row['error']['fields'] );
			}
		}
		unset( $row );

		if ( null === $flow ) {
			$flow = ( array() !== $shipments && 'return' === $shipments[0]['direction'] ) ? 'return' : 'outbound';
		}

		return array(
			'order_id'  => $order->get_id(),
			'flow'      => $flow,
			'success'   => $result->is_successful(),
			'shipments' => $shipments,
			'state'     => $this->state( $order ),
		);
	}

	/**
	 * A booked shipment as the meta box renders it: its to_array()
	 * form plus the link into the Smart Send app and, per parcel, the
	 * weight and dimensions formatted in the store's units (the DTO
	 * carries kg and cm, the units Smart Send books in).
	 *
	 * @param array $shipment Booked_Shipment::to_array().
	 *
	 * @return array
	 */
	public function shipment_response( array $shipment ): array {
		$shipment['app_url'] = $this->app_url( isset( $shipment['shipment_id'] ) ? (string) $shipment['shipment_id'] : '' );

		foreach ( isset( $shipment['parcels'] ) ? array_keys( $shipment['parcels'] ) : array() as $index ) {
			$shipment['parcels'][ $index ] = array_merge(
				(array) $shipment['parcels'][ $index ],
				$this->parcel_display( (array) $shipment['parcels'][ $index ] )
			);
		}

		return $shipment;
	}

	/**
	 * The display strings of a booked parcel: its weight in the store's
	 * weight unit and its dimensions as "40 x 30 x 20 cm" in the store's
	 * dimension unit - null when the parcel carries no weight / not all
	 * three dimensions (the box renders only what is there).
	 *
	 * @param array $parcel Booked_Parcel::to_array().
	 *
	 * @return array{weight_display: string|null, dimensions_display: string|null}
	 */
	public function parcel_display( array $parcel ): array {
		$number = static function ( $key ) use ( $parcel ): ?float {
			return isset( $parcel[ $key ] ) && null !== $parcel[ $key ] && '' !== $parcel[ $key ] ? (float) $parcel[ $key ] : null;
		};

		$weight_unit    = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$dimension_unit = (string) get_option( 'woocommerce_dimension_unit', 'cm' );

		$weight     = $number( 'weight' );
		$dimensions = array( $number( 'length' ), $number( 'width' ), $number( 'height' ) );

		$display_dimensions = null;
		if ( ! in_array( null, $dimensions, true ) ) {
			$display_dimensions = implode(
				' × ',
				array_map(
					static function ( $value ) use ( $dimension_unit ): string {
						return (string) wc_format_localized_decimal( wc_get_dimension( (float) $value, $dimension_unit, 'cm' ) );
					},
					$dimensions
				)
			) . ' ' . $dimension_unit;
		}

		return array(
			'weight_display'     => null === $weight ? null : wc_format_localized_decimal( wc_get_weight( $weight, $weight_unit, 'kg' ) ) . ' ' . $weight_unit,
			'dimensions_display' => $display_dimensions,
		);
	}

	/**
	 * The link to a shipment in the Smart Send app: the same host the
	 * API client talks to (API_Factory::resolve_api_host(),
	 * which runs the smart_send_api_endpoint filter), so a sandbox
	 * override follows here too - never a hardcoded production host.
	 *
	 * @param string $shipment_id The Smart Send shipment id.
	 *
	 * @return string The URL, '' for an empty shipment id.
	 */
	public function app_url( string $shipment_id ): string {
		if ( '' === $shipment_id ) {
			return '';
		}

		return rtrim( $this->api_factory->resolve_api_host(), '/' ) . '/shipments/' . rawurlencode( $shipment_id );
	}

	/**
	 * Map a Smart Send API v1 validation field name onto the meta box
	 * form field the merchant can fix, or null when the field is
	 * outside the box (e.g. receiver.* - the order's shipping address):
	 *
	 *   agent_no, agent.*          -> pickup_point.agent_no
	 *   parcels.N.weight           -> parcel_plan.specs[N].weight  (likewise height, width, length)
	 *   parcels.N.items.M.<field>   -> null (the general notice)
	 *   parcels.N / parcels        -> parcel_plan.specs[N] / parcel_plan
	 *   shipping_method, shipping_carrier -> shipping_method
	 *
	 * @param string $api_field The API field name.
	 *
	 * @return string|null
	 */
	public function map_api_field( string $api_field ): ?string {
		if ( 'parcel_plan' === $api_field || 0 === strpos( $api_field, 'parcel_plan.items.' ) ) {
			return 'parcel_plan';
		}
		if ( 0 === strpos( $api_field, 'parcel_plan.specs.' ) ) {
			if ( false !== strpos( $api_field, '.items' ) ) {
				return 'parcel_plan';
			}
			return preg_replace( '/\.(\d+)(?=\.|$)/', '[$1]', $api_field );
		}
		if ( 'agent_no' === $api_field || 'agent' === $api_field || 0 === strpos( $api_field, 'agent.' ) ) {
			return 'pickup_point.agent_no';
		}

		if ( 'shipping_method' === $api_field || 'shipping_carrier' === $api_field ) {
			return 'shipping_method';
		}

		if ( 'parcels' === $api_field ) {
			return 'parcel_plan';
		}

		if ( preg_match( '/^parcels\.(\d+)(?:\.(.+))?$/', $api_field, $m ) ) {
			$field = 'parcel_plan.specs[' . (int) $m[1] . ']';

			if ( ! isset( $m[2] ) ) {
				return $field;
			}

			if ( ! in_array( $m[2], array( 'weight', 'length', 'width', 'height' ), true ) ) {
				// Item/customs fields have no editable control; keep their errors in the general notice.
				return null;
			}

			return $field . '.' . $m[2];
		}

		return null;
	}

	/**
	 * Map a set of API field errors (field => messages) onto form
	 * fields; unmapped fields are left out (the client shows them as a
	 * general notice from 'fields').
	 *
	 * @param array $fields The API field errors.
	 *
	 * @return array<string, string[]>
	 */
	public function map_api_fields( array $fields ): array {
		$mapped = array();

		foreach ( $fields as $api_field => $messages ) {
			$form_field = $this->map_api_field( (string) $api_field );

			if ( null === $form_field ) {
				continue;
			}

			$mapped[ $form_field ] = array_merge(
				isset( $mapped[ $form_field ] ) ? $mapped[ $form_field ] : array(),
				array_values( (array) $messages )
			);
		}

		return $mapped;
	}

	/**
	 * The shipping methods the box offers for a direction: the
	 * catalogue as carriers => services, passed through the public
	 * `smart_send_fulfillment_shipping_methods` filter, normalized, and
	 * with the order's own method added back when the filter dropped it.
	 *
	 * The filter is a UI narrowing only - it decides what the drop-downs
	 * offer, never what may be booked (the REST controller does not
	 * validate a submitted method against it).
	 *
	 * @param WC_Order $order     The order the box is rendered for.
	 * @param boolean  $is_return Whether this is the return method list.
	 * @param string   $selected  The method the order resolves to for this direction ('' when none).
	 *
	 * @return array
	 */
	protected function offered_methods( WC_Order $order, bool $is_return, string $selected ): array {
		$carriers = $this->method_carriers(
			$is_return ? $this->catalog()->get_return_shipping_methods() : $this->catalog()->get_shipping_methods()
		);

		/**
		 * Filter the shipping methods the order screen's "Smart Send" box
		 * offers in its method drop-downs.
		 *
		 * Runs once per list, so the outbound and the return drop-down can
		 * be restricted differently. The method code booked with is
		 * '<carrier code>_<service code>', e.g. 'postnord_agent'. Returning
		 * an empty array leaves the drop-down empty.
		 *
		 * 'addons' is reserved for the delivery addons landing with the API
		 * v2 work and is always an empty array today.
		 *
		 * This narrows what the box offers; it is not an authorisation
		 * boundary - a method submitted by an editor is still booked.
		 *
		 * @since 9.0.0
		 *
		 * @param array    $carriers  array( array( 'code' => string, 'name' => string, 'services' => array( array( 'code' => string, 'name' => string, 'addons' => array() ), ... ) ), ... )
		 * @param WC_Order $order     The order the box is rendered for.
		 * @param boolean  $is_return Whether this is the return method list.
		 */
		$carriers = apply_filters( 'smart_send_fulfillment_shipping_methods', $carriers, $order, $is_return );

		$carriers = $this->normalize_method_carriers( is_array( $carriers ) ? $carriers : array() );

		return $this->ensure_method_offered( $carriers, $selected, $is_return );
	}

	/**
	 * The catalogue's carrier => methods map as the nested carriers =>
	 * services list the state carries: the method code 'postnord_agent'
	 * becomes the service 'agent' of the carrier 'postnord'.
	 *
	 * @param array $catalog_methods Method_Catalog::get_shipping_methods() / get_return_shipping_methods().
	 *
	 * @return array
	 */
	protected function method_carriers( array $catalog_methods ): array {
		$carriers = array();

		foreach ( $catalog_methods as $group => $methods ) {
			if ( ! is_array( $methods ) ) {
				continue; // The '- Select Method -' placeholder.
			}

			foreach ( $methods as $code => $name ) {
				$carrier = ( new Method_Code( (string) $code ) )->carrier();

				if ( ! isset( $carriers[ $carrier ] ) ) {
					$carriers[ $carrier ] = array(
						'code'     => $carrier,
						'name'     => $this->carrier_name( $carrier, (string) $group ),
						'services' => array(),
					);
				}

				$carriers[ $carrier ]['services'][] = array(
					'code'   => $this->service_code( (string) $code, $carrier ),
					'name'   => (string) $name,
					// Reserved for the delivery addons of the API v2 work.
					'addons' => array(),
				);
			}
		}

		return array_values( $carriers );
	}

	/**
	 * The display name of a carrier: the catalogue's own, falling back
	 * to the group heading the methods were listed under (e.g. 'Bifrost
	 * Logistics' for the carrier code 'bifrost').
	 *
	 * @param string $carrier The carrier code.
	 * @param string $group   The catalogue group heading the method was listed under.
	 *
	 * @return string
	 */
	protected function carrier_name( string $carrier, string $group ): string {
		$name = (string) $this->catalog()->get_carrier_name( $carrier );

		return $name === $carrier ? $group : $name;
	}

	/**
	 * The service part of a method code, i.e. the code without its
	 * '<carrier>_' prefix.
	 *
	 * @param string $code    The method code, e.g. 'postnord_agent'.
	 * @param string $carrier The carrier code, e.g. 'postnord'.
	 *
	 * @return string
	 */
	protected function service_code( string $code, string $carrier ): string {
		$prefix = $carrier . '_';

		return 0 === strpos( $code, $prefix ) ? substr( $code, strlen( $prefix ) ) : $code;
	}

	/**
	 * A filtered carriers list, defensively normalized: anything that is
	 * not a carrier with at least one service is dropped, every value is
	 * cast, and 'addons' is always present as an array.
	 *
	 * @param array $carriers The filtered list.
	 *
	 * @return array
	 */
	protected function normalize_method_carriers( array $carriers ): array {
		$normalized = array();

		foreach ( $carriers as $carrier ) {
			if ( ! is_array( $carrier ) || ! isset( $carrier['code'] ) || '' === (string) $carrier['code'] ) {
				continue;
			}

			$code     = (string) $carrier['code'];
			$services = array();

			foreach ( isset( $carrier['services'] ) && is_array( $carrier['services'] ) ? $carrier['services'] : array() as $service ) {
				if ( ! is_array( $service ) || ! isset( $service['code'] ) || '' === (string) $service['code'] ) {
					continue;
				}

				$service_code = (string) $service['code'];
				$name         = isset( $service['name'] ) ? (string) $service['name'] : '';

				if ( '' === $name ) {
					$name = $this->method_name( $code . '_' . $service_code );
					$name = '' === $name ? $code . '_' . $service_code : $name;
				}

				$services[] = array(
					'code'   => $service_code,
					'name'   => $name,
					'addons' => isset( $service['addons'] ) && is_array( $service['addons'] ) ? array_values( $service['addons'] ) : array(),
				);
			}

			if ( array() === $services ) {
				continue;
			}

			$name = isset( $carrier['name'] ) && '' !== (string) $carrier['name'] ? (string) $carrier['name'] : $this->carrier_name( $code, $code );

			$normalized[] = array(
				'code'     => $code,
				'name'     => $name,
				'services' => $services,
			);
		}

		return $normalized;
	}

	/**
	 * The order's own method always survives the filter: when the method
	 * the order resolves to for this direction is no longer offered, it
	 * is added back (to its carrier, or as a carrier of its own) so the
	 * box never shows a selected value its drop-down cannot offer.
	 *
	 * @param array   $carriers  The filtered carriers list.
	 * @param string  $selected  The method code the order resolves to ('' when none).
	 * @param boolean $is_return Whether this is the return method list.
	 *
	 * @return array
	 */
	protected function ensure_method_offered( array $carriers, string $selected, bool $is_return ): array {
		if ( '' === $selected ) {
			return $carriers;
		}

		$method  = new Method_Code( $selected );
		$carrier = $method->carrier();
		$service = $this->service_code( $selected, $carrier );

		foreach ( $carriers as $index => $row ) {
			if ( $row['code'] !== $carrier ) {
				continue;
			}

			foreach ( $row['services'] as $offered ) {
				if ( $offered['code'] === $service ) {
					return $carriers; // Still offered.
				}
			}

			$carriers[ $index ]['services'][] = $this->method_service( $selected, $service );

			Logger::debug(
				'The smart_send_fulfillment_shipping_methods filter removed the method of the order - added back',
				array(
					'method'    => $selected,
					'is_return' => $is_return,
				)
			);

			return $carriers;
		}

		$carriers[] = array(
			'code'     => $carrier,
			'name'     => $this->carrier_name( $carrier, $carrier ),
			'services' => array( $this->method_service( $selected, $service ) ),
		);

		Logger::debug(
			'The smart_send_fulfillment_shipping_methods filter removed the carrier of the order - added back',
			array(
				'method'    => $selected,
				'is_return' => $is_return,
			)
		);

		return $carriers;
	}

	/**
	 * A single service row of a method code, named from the catalogue.
	 *
	 * @param string $code    The full method code.
	 * @param string $service The service part of it.
	 *
	 * @return array
	 */
	protected function method_service( string $code, string $service ): array {
		$name = $this->method_name( $code );

		return array(
			'code'   => $service,
			'name'   => '' === $name ? $code : $name,
			'addons' => array(),
		);
	}

	/**
	 * The stored shipment of a direction: the LATEST shipment id booked
	 * for it (the frozen public meta key), with the link into the Smart
	 * Send app. Nothing else of a booked shipment is persisted
	 * (Decisions, #182) - the documents, tracking and parcels of a run
	 * are shown from its response and live in the client's memory only.
	 *
	 * What survives a reload is this id and the "Booked shipments"
	 * timeline (see timeline()).
	 *
	 * @param WC_Order $order     The order.
	 * @param boolean  $is_return Which direction.
	 *
	 * @return array|null
	 */
	protected function shipment_state( WC_Order $order, bool $is_return ): ?array {
		$shipment_id = $this->shipment_ids->get( $order, $is_return );

		if ( '' === $shipment_id ) {
			return null;
		}

		return array(
			'shipment_id' => $shipment_id,
			'app_url'     => $this->app_url( $shipment_id ),
		);
	}

	/**
	 * The order's "Booked shipments" timeline, newest first: every label
	 * this plugin booked for the order, from the append-only list
	 * Shipment_IDs keeps (booking outcomes are its domain,
	 * not the delivery-details repository's).
	 *
	 * Back-compat: an order booked before that list existed carries only
	 * the two frozen id keys, with no timestamp. Such an id - one the
	 * list does not mention - is appended as an entry WITHOUT a
	 * booked_at, which the box renders as a link with no time under it.
	 * The same fallback covers an order that carries both (booked
	 * before the upgrade and again after it): only the ids the list
	 * does not already carry are added.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array[]
	 */
	public function timeline( WC_Order $order ): array {
		$labels  = $this->shipment_ids->labels( $order );
		$entries = array();

		foreach ( array_reverse( $labels ) as $label ) {
			$entries[] = array(
				'direction'         => $label['direction'],
				'shipment_id'       => $label['shipment_id'],
				'app_url'           => $this->app_url( $label['shipment_id'] ),
				'booked_at'         => $label['booked_at'],
				'booked_at_display' => null === $label['booked_at'] ? null : $this->format_booked_at( $label['booked_at'] ),
			);
		}

		$known = wp_list_pluck( $labels, 'shipment_id' );

		foreach ( array( false, true ) as $is_return ) {
			$shipment_id = $this->shipment_ids->get( $order, $is_return );

			if ( '' === $shipment_id || in_array( $shipment_id, $known, true ) ) {
				continue;
			}

			$entries[] = array(
				'direction'         => $is_return ? 'return' : 'outbound',
				'shipment_id'       => $shipment_id,
				'app_url'           => $this->app_url( $shipment_id ),
				'booked_at'         => null,
				'booked_at_display' => null,
			);
		}

		return $entries;
	}

	/**
	 * A stored ISO 8601 booking timestamp as the box shows it: the
	 * store's own timezone, "Y-m-d H:i:s".
	 *
	 * @param string $booked_at The ISO 8601 timestamp (UTC).
	 *
	 * @return string
	 */
	public function format_booked_at( string $booked_at ): string {
		$time = strtotime( $booked_at );

		if ( false === $time ) {
			return $booked_at;
		}

		return wp_date( 'Y-m-d H:i:s', $time );
	}

	/**
	 * The order's units, one row per unit (quantity expanded), with the
	 * order-item id the parcel plan allocates by, separate catalog ids,
	 * the order line name and unit weight in kg. A deleted product yields
	 * a translated placeholder and unknown weight, requiring manual input.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array[]
	 */
	public function order_units( WC_Order $order ): array {
		$units = array();

		foreach ( $order->get_items() as $item ) {
			$catalog_ids = Order_Reader::product_ids_for_item( $item );
			$product     = Order_Reader::product_for_item( $item );
			$weight      = $product ? round( (float) wc_get_weight( $product->get_weight(), 'kg' ), 2 ) : null;

			$quantity = (int) $item->get_quantity();

			for ( $unit = 0; $unit < $quantity; $unit++ ) {
				$units[] = array(
					'order_item_id'   => (int) $item->get_id(),
					'product_id'      => $catalog_ids['product_id'],
					'variation_id'    => $catalog_ids['variation_id'],
					'name'            => $product ? (string) $item->get_name() : __( 'Deleted', 'smart-send-logistics' ),
					'sku'             => $product ? (string) $product->get_sku() : '',
					'unit_weight'     => $weight,
					'product_missing' => ! $product,
				);
			}
		}

		return $units;
	}

	/**
	 * Whether the plugin can book: an API token is configured.
	 *
	 * @return boolean
	 */
	public function is_connected(): bool {
		return null !== $this->settings->api_token();
	}

	/**
	 * The order screen backend.
	 *
	 * @return string 'hpos' or 'legacy'.
	 */
	public function screen(): string {
		return wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy';
	}

	/**
	 * The human readable name of an outbound or return method code, ''
	 * when the catalogue does not know it.
	 *
	 * @param string $code The method code.
	 *
	 * @return string
	 */
	public function method_name( string $code ): string {
		$name = $this->catalog()->get_shipping_method_name( $code );

		if ( '' !== $name ) {
			return $name;
		}

		foreach ( $this->catalog()->get_return_shipping_methods() as $methods ) {
			if ( is_array( $methods ) && isset( $methods[ $code ] ) ) {
				return (string) $methods[ $code ];
			}
		}

		return '';
	}

	/**
	 * The translated method catalogue.
	 *
	 * @return Method_Catalog
	 */
	protected function catalog(): Method_Catalog {
		if ( null === $this->catalog ) {
			$this->catalog = new Method_Catalog();
		}

		return $this->catalog;
	}
}
