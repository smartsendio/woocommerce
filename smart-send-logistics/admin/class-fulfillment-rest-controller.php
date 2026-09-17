<?php

namespace Smart_Send\Admin;

use Smart_Send\Booking\Exceptions\Booking_Exception;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Parcel_Plan;
use Smart_Send\Delivery\Parcel_Spec;
use Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception;
use Smart_Send\Delivery_Options\Pickup_Point_Lookup;
use Smart_Send\Fulfillment\Fulfillment_Service;
use Smart_Send\Fulfillment\Shipment_IDs;
use Smart_Send\Shipping_Method\Method_Code;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send fulfillment REST controller.
 *
 * The transport of the order screen's "Smart Send" meta box (#182):
 * WP REST under the smart-send/v1 namespace, three routes below
 * orders/{id} -
 *
 *   GET  /fulfillment                  the meta box state (Order_Fulfillment_Presenter::state())
 *   POST /fulfillment                  book: { flow, with_return, return_method, confirm_rebook, delivery_details }
 *   GET  /pickup-points/{agent_no}     resolve an entered agent number through the shared lookup
 *
 * The request body is validated declaratively by the args schema
 * request_schema() generates, which mirrors
 * Delivery_Details::from_array(); the controller only ever
 * calls Fulfillment_Service::fulfill_outbound()/fulfill_return()
 * - never the booking service or the repository. Request-level failures
 * are HTTP errors (400 rest_invalid_param, 403 rest_forbidden, 404
 * smart_send_order_not_found, 409 smart_send_not_connected /
 * smart_send_already_booked / smart_send_no_return_method, 422
 * smart_send_pickup_point_not_found); a completed run - even one whose
 * leg failed at Smart Send - is a 200 carrying the presenter's response.
 *
 * Authentication is WordPress' own: the cookie session plus the REST
 * nonce @wordpress/api-fetch sends (X-WP-Nonce), then
 * current_user_can( 'edit_shop_orders' ).
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Fulfillment_REST_Controller {

	/**
	 * The REST namespace.
	 */
	const REST_NAMESPACE = 'smart-send/v1';

	/**
	 * The route base below the namespace; the order id is captured as 'id'.
	 */
	const ROUTE_ORDER = '/orders/(?P<id>\d+)';

	/**
	 * The fulfillment service running the label workflow.
	 *
	 * @var Fulfillment_Service
	 */
	protected Fulfillment_Service $fulfillment_service;

	/**
	 * The meta box presenter (state + response shapes).
	 *
	 * @var Order_Fulfillment_Presenter
	 */
	protected Order_Fulfillment_Presenter $presenter;

	/**
	 * Booked shipment id accessor (the re-book guard).
	 *
	 * @var Shipment_IDs
	 */
	protected Shipment_IDs $shipment_ids;

	/**
	 * Shipping method resolver.
	 *
	 * @var Method_Resolver
	 */
	protected Method_Resolver $method_resolver;

	/**
	 * Shared pickup point lookup.
	 *
	 * @var Pickup_Point_Lookup
	 */
	protected Pickup_Point_Lookup $pickup_point_lookup;

	/**
	 * Order meta repository (the stored pickup point a submitted agent
	 * number is compared against).
	 *
	 * @var Order_Meta
	 */
	protected Order_Meta $order_meta;

	/**
	 * Typed plugin settings reader.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * @param Fulfillment_Service         $fulfillment_service The fulfillment service.
	 * @param Order_Fulfillment_Presenter $presenter           The meta box presenter.
	 * @param Shipment_IDs                $shipment_ids        Booked shipment id accessor.
	 * @param Method_Resolver             $method_resolver     Shipping method resolver.
	 * @param Pickup_Point_Lookup         $pickup_point_lookup Shared pickup point lookup.
	 * @param Order_Meta                  $order_meta          Order meta repository.
	 * @param Settings|null               $settings            Typed plugin settings reader (stateless; a fresh default is safe).
	 */
	public function __construct( Fulfillment_Service $fulfillment_service, Order_Fulfillment_Presenter $presenter, Shipment_IDs $shipment_ids, Method_Resolver $method_resolver, Pickup_Point_Lookup $pickup_point_lookup, Order_Meta $order_meta, ?Settings $settings = null ) {
		$this->fulfillment_service = $fulfillment_service;
		$this->presenter           = $presenter;
		$this->shipment_ids        = $shipment_ids;
		$this->method_resolver     = $method_resolver;
		$this->pickup_point_lookup = $pickup_point_lookup;
		$this->order_meta          = $order_meta;
		$this->settings            = null === $settings ? new Settings() : $settings;
	}

	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ORDER . '/fulfillment',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_state' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'fulfill' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->request_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ORDER . '/pickup-points/(?P<agent_no>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'lookup_pickup_point' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'method' => array(
							'description' => __( 'The Smart Send shipping method to resolve the agent number for (its carrier); defaults to the order\'s shipping method.', 'smart-send-logistics' ),
							'type'        => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * The JSON schema of the POST body (section 3.1 of #182), the one
	 * PHP array the args are generated from. 'delivery_details' mirrors
	 * Delivery_Details::from_array() property for property
	 * (a test pins that every property round-trips); every field of it
	 * is optional, absent/null meaning "keep the stored/derived value".
	 *
	 * @return array
	 */
	public function request_schema(): array {
		$nullable_number = array(
			'type' => array( 'number', 'null' ),
		);

		return array(
			'flow'             => array(
				'description' => __( 'Which label to create.', 'smart-send-logistics' ),
				'type'        => 'string',
				'enum'        => array( 'outbound', 'return' ),
				'required'    => true,
			),
			'with_return'      => array(
				'description' => __( 'Whether to also create the return label (outbound flow only); null follows the shipping method\'s auto-generate-return-label setting.', 'smart-send-logistics' ),
				'type'        => array( 'boolean', 'null' ),
				'default'     => null,
			),
			'return_method'    => array(
				'description' => __( 'The Smart Send return method to book the return label with when the order has none configured (outbound flow with with_return, e.g. an order placed without a Smart Send method); null follows the shipping method\'s configured return method.', 'smart-send-logistics' ),
				'type'        => array( 'string', 'null' ),
				'default'     => null,
			),
			'confirm_rebook'   => array(
				'description' => __( 'Must be true to book again when the order already has a shipment for the flow.', 'smart-send-logistics' ),
				'type'        => 'boolean',
				'default'     => false,
			),
			'delivery_details' => array(
				'description' => __( 'The delivery details to book with; every field optional (keep the stored/derived value).', 'smart-send-logistics' ),
				'type'        => 'object',
				'default'     => array(),
				'properties'  => array(
					'shipping_method' => array(
						'description' => __( 'The Smart Send shipping method code (e.g. postnord_agent).', 'smart-send-logistics' ),
						'type'        => array( 'string', 'null' ),
					),
					'pickup_point'    => array(
						'description' => __( 'The pickup point: { agent_no } to resolve server-side, { clear: true } to remove the stored one, or a full pickup point.', 'smart-send-logistics' ),
						'type'        => array( 'object', 'null' ),
						'properties'  => array(
							'agent_no' => array(
								'type' => array( 'string', 'integer' ),
							),
							'clear'    => array(
								'type' => 'boolean',
							),
						),
					),
					'parcel_plan'     => array(
						'description' => __( 'The parcel plan; an empty specs list is one parcel containing everything.', 'smart-send-logistics' ),
						'type'        => array( 'object', 'null' ),
						'properties'  => array(
							'specs' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'reference' => array(
											'type' => array( 'string', 'integer', 'null' ),
										),
										'weight'    => $nullable_number,
										'length'    => $nullable_number,
										'width'     => $nullable_number,
										'height'    => $nullable_number,
										'items'     => array(
											'type'  => 'array',
											'items' => array(
												'type'     => 'object',
												'properties' => array(
													'order_item_id' => array(
														'type' => 'integer',
														'minimum' => 1,
													),
													'quantity' => array(
														'type'    => 'integer',
														'minimum' => 1,
													),
													'name' => array(
														'type' => array( 'string', 'null' ),
													),
												),
												'required' => array( 'order_item_id', 'quantity' ),
												'additionalProperties' => false,
											),
										),
									),
								),
							),
						),
					),
					'addons'          => array(
						'type' => 'array',
					),
				),
			),
		);
	}

	/**
	 * The registered args: the schema plus the order-aware validation
	 * of the delivery details (an item id the order does not have).
	 *
	 * @return array
	 */
	protected function request_args(): array {
		$args = $this->request_schema();

		$args['delivery_details']['validate_callback'] = array( $this, 'validate_delivery_details' );

		return $args;
	}

	/**
	 * Validate the delivery_details argument: the schema first, then
	 * allocations must reference this order's lines and cover every unit
	 * exactly once. An empty plan or one itemless spec includes all items.
	 * The booking builder enforces the same rules for non-REST callers.
	 *
	 * @param mixed           $value   The submitted value.
	 * @param WP_REST_Request $request The request.
	 * @param string          $param   The parameter name.
	 *
	 * @return true|WP_Error
	 */
	public function validate_delivery_details( $value, $request, $param ) {
		$schema = $this->request_schema();
		$valid  = rest_validate_value_from_schema( $value, $schema[ $param ], $param );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! is_array( $value ) || empty( $value['parcel_plan']['specs'] ) || ! is_array( $value['parcel_plan']['specs'] ) ) {
			return true;
		}

		$order = wc_get_order( (int) $request->get_param( 'id' ) );

		if ( ! $order instanceof WC_Order ) {
			return true; // The permission callback reports the missing order.
		}

		try {
			$plan = Parcel_Plan::from_array( $value['parcel_plan'] );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'rest_invalid_param', __( 'The parcel plan must use order_item_id and quantity. Reset it and enter the allocation again.', 'smart-send-logistics' ) );
		}
		$quantities = array();
		foreach ( $order->get_items() as $item ) {
			$quantities[ $item->get_id() ] = $item->get_quantity();
		}
		$errors = $plan->validation_errors( $quantities );
		if ( $errors ) {
			$messages = array();
			foreach ( $errors as $field => $field_errors ) {
				$messages[] = $this->presenter->map_api_field( $field ) . ': ' . implode( ' ', $field_errors );
			}
			return new WP_Error( 'rest_invalid_param', implode( ' ', $messages ) );
		}

		return true;
	}

	/**
	 * Permission callback of every route: the user must be able to edit
	 * orders (403; 401 when not logged in) and the order must exist (404).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function permission_callback( $request ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to create shipping labels for orders.', 'smart-send-logistics' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! $this->load_order( $request ) instanceof WC_Order ) {
			return new WP_Error(
				'smart_send_order_not_found',
				__( 'The order could not be found.', 'smart-send-logistics' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * GET /fulfillment: the meta box state.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_state( $request ) {
		return rest_ensure_response( $this->presenter->state( $this->load_order( $request ) ) );
	}

	/**
	 * POST /fulfillment: book the requested label(s).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function fulfill( $request ) {
		$order     = $this->load_order( $request );
		$flow      = (string) $request->get_param( 'flow' );
		$is_return = 'return' === $flow;

		if ( ! $this->presenter->is_connected() ) {
			return $this->not_connected_error();
		}

		$existing = $this->shipment_ids->get( $order, $is_return );

		if ( '' !== $existing && true !== $request->get_param( 'confirm_rebook' ) ) {
			return new WP_Error(
				'smart_send_already_booked',
				$is_return
					? __( 'A return label already exists for this order. Confirm to book again; the existing shipment is not cancelled.', 'smart-send-logistics' )
					: __( 'A shipping label already exists for this order. Confirm to book again; the existing shipment is not cancelled.', 'smart-send-logistics' ),
				array(
					'status'      => 409,
					'shipment_id' => $existing,
				)
			);
		}

		try {
			$details = Delivery_Details::from_array( (array) $request->get_param( 'delivery_details' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'rest_invalid_param', __( 'The parcel plan must use order_item_id and quantity. Reset it and enter the allocation again.', 'smart-send-logistics' ), array( 'status' => 400 ) );
		}
		$this->fill_item_names( $details, $order );

		$method = $this->method_for_flow( $order, $details, $is_return );

		$with_return = $request->get_param( 'with_return' );
		$with_return = is_bool( $with_return ) ? $with_return : null;

		// A return method submitted for the return leg of a combined
		// outbound + return run (state B: an order without a Smart Send
		// method has no configured return method).
		$return_overrides        = null;
		$submitted_return_method = (string) $request->get_param( 'return_method' );

		if ( ! $is_return && '' !== $submitted_return_method ) {
			$return_overrides = new Delivery_Details();
			$return_overrides->set_shipping_method( $submitted_return_method );
		}

		if ( $is_return || true === $with_return ) {
			// A return is explicitly requested: fail the request, not the
			// leg, when no return method is resolvable and none was
			// submitted. (A null with_return follows the setting, whose
			// missing return method stays a failed leg.)
			$return_method = $is_return ? $method : ( '' !== $submitted_return_method ? $submitted_return_method : $this->configured_return_method( $order ) );

			if ( '' === $return_method ) {
				return new WP_Error(
					'smart_send_no_return_method',
					__( 'No return method is configured on the shipping method - set one under WooCommerce → Shipping → the zone method, or choose one for this label.', 'smart-send-logistics' ),
					array( 'status' => 409 )
				);
			}
		}

		$resolved = $this->resolve_pickup_point_override( $details, $order, $method );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$result = $is_return
			? $this->fulfillment_service->fulfill_return( $order, true, $details )
			: $this->fulfillment_service->fulfill_outbound( $order, true, $details, $with_return, $return_overrides );

		return rest_ensure_response( $this->presenter->response( $result, $order, $flow ) );
	}

	/**
	 * GET /pickup-points/{agent_no}: resolve an entered agent number for
	 * the order (carrier from the given or the order's method, country
	 * from the shipping address) into the full pickup point.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup_pickup_point( $request ) {
		$order    = $this->load_order( $request );
		$agent_no = (string) wc_clean( wp_unslash( $request->get_param( 'agent_no' ) ) );

		if ( ! $this->presenter->is_connected() ) {
			return $this->not_connected_error();
		}

		$method  = (string) $request->get_param( 'method' );
		$method  = '' === $method ? $this->method_resolver->resolve_outbound( $order ) : $method;
		$carrier = '' === $method ? '' : ( new Method_Code( $method ) )->carrier();

		if ( '' === $carrier ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'A shipping method is needed to look a pickup point up by its agent number.', 'smart-send-logistics' ),
				array( 'status' => 400 )
			);
		}

		try {
			$pickup_point = $this->pickup_point_lookup->find_by_agent_no( $carrier, (string) $order->get_shipping_country(), $agent_no );
		} catch ( Pickup_Point_Not_Found_Exception $e ) {
			return $this->pickup_point_not_found_error( $e, 404 );
		}

		return rest_ensure_response( $this->presenter->pickup_point_state( $pickup_point ) );
	}

	/**
	 * Resolve every client-submitted pickup point by its agent number before the
	 * run, so a number Smart Send does not know is a request-level 422
	 * (with the form field) rather than a failed leg. Client-supplied
	 * names, addresses and context are never trusted; a submitted point on
	 * a method that is not an agent-type method is dropped (nothing to
	 * resolve, nothing to persist).
	 *
	 * @param Delivery_Details $details The submitted details (modified in place).
	 * @param WC_Order                     $order   The order.
	 * @param string                       $method  The method the flow books with ('' when unknown).
	 *
	 * @return true|WP_Error
	 */
	protected function resolve_pickup_point_override( Delivery_Details $details, WC_Order $order, string $method ) {
		$submitted = $details->get_pickup_point();

		if ( null === $submitted ) {
			return true;
		}

		$method_code = new Method_Code( $method );

		if ( '' === $method || false === stripos( $method_code->type(), 'agent' ) ) {
			$details->set_pickup_point( null );

			return true;
		}

		$agent_no = (string) $submitted->get_agent_no();
		$stored   = $this->order_meta->read( $order )->get_pickup_point();
		if ( null !== $stored && (string) $stored->get_agent_no() === $agent_no
			&& $this->pickup_point_lookup->matches_context( $stored, $method_code->carrier(), (string) $order->get_shipping_country() ) ) {
			$details->set_pickup_point( $stored );
			return true;
		}

		try {
			$details->set_pickup_point( $this->pickup_point_lookup->find_by_agent_no( $method_code->carrier(), (string) $order->get_shipping_country(), $agent_no ) );
		} catch ( Pickup_Point_Not_Found_Exception $e ) {
			Logger::warning(
				'Pickup point not found - agent number rejected',
				array(
					'order_id' => $order->get_id(),
					'agent_no' => $agent_no,
					'carrier'  => $method_code->carrier(),
				)
			);

			return $this->pickup_point_not_found_error( $e, 422 );
		}

		return true;
	}

	/**
	 * The method a flow books with: the submitted one, else the resolved
	 * outbound method, else the configured return method ('' when none).
	 *
	 * @param WC_Order                     $order     The order.
	 * @param Delivery_Details $details   The submitted details.
	 * @param boolean                      $is_return Which flow.
	 *
	 * @return string
	 */
	protected function method_for_flow( WC_Order $order, Delivery_Details $details, bool $is_return ): string {
		$submitted = $details->get_shipping_method();

		if ( null !== $submitted && '' !== $submitted ) {
			return $submitted;
		}

		return $is_return ? $this->configured_return_method( $order ) : $this->method_resolver->resolve_outbound( $order );
	}

	/**
	 * The order's configured return method, '' when none (the resolver
	 * throws for a Smart Send shipping item without one).
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return string
	 */
	protected function configured_return_method( WC_Order $order ): string {
		try {
			return $this->method_resolver->resolve_return( $order );
		} catch ( Booking_Exception $e ) {
			return '';
		}
	}

	/**
	 * Fill allocation labels from the current order presentation rather
	 * than trusting client-supplied names, including deleted placeholders.
	 *
	 * @param Delivery_Details $details The submitted details (modified in place).
	 * @param WC_Order                     $order   The order.
	 *
	 * @return void
	 */
	protected function fill_item_names( Delivery_Details $details, WC_Order $order ): void {
		$plan = $details->get_parcel_plan();

		if ( null === $plan || $plan->is_empty() ) {
			return;
		}

		$names = array();
		foreach ( $this->presenter->order_units( $order ) as $unit ) {
			$names[ (string) $unit['order_item_id'] ] = $unit['name'];
		}

		$named = new Parcel_Plan();
		foreach ( $plan->get_specs() as $spec ) {
			$copy = Parcel_Spec::from_array( array_merge( $spec->to_array(), array( 'items' => array() ) ) );

			foreach ( $spec->get_items() as $item ) {
				$id = (string) $item['order_item_id'];
				$copy->add_item( $item['order_item_id'], $item['quantity'], isset( $names[ $id ] ) ? $names[ $id ] : $item['name'] );
			}

			$named->add_spec( $copy );
		}

		$details->set_parcel_plan( $named );
	}

	/**
	 * The order of the route's {id}.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WC_Order|false
	 */
	protected function load_order( $request ) {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );

		return $order instanceof WC_Order ? $order : false;
	}

	/**
	 * The 409 "not connected" error.
	 *
	 * @return WP_Error
	 */
	protected function not_connected_error(): WP_Error {
		return new WP_Error(
			'smart_send_not_connected',
			__( 'Smart Send is not connected. Enter your API token in the settings to create labels.', 'smart-send-logistics' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The pickup-point-not-found error, carrying the form field the
	 * merchant can fix.
	 *
	 * @param Pickup_Point_Not_Found_Exception $e      The miss.
	 * @param integer                                      $status 404 on the lookup route, 422 on a booking request.
	 *
	 * @return WP_Error
	 */
	protected function pickup_point_not_found_error( Pickup_Point_Not_Found_Exception $e, int $status ): WP_Error {
		return new WP_Error(
			'smart_send_pickup_point_not_found',
			$e->getMessage(),
			array(
				'status'      => $status,
				'agent_no'    => $e->agent_no(),
				'carrier'     => $e->carrier(),
				'form_fields' => array(
					'pickup_point.agent_no' => array( $e->getMessage() ),
				),
			)
		);
	}
}
