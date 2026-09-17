<?php

namespace Smart_Send\Delivery_Options;

use Exception;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Method_Resolver;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception;
use Smart_Send\Frontend\Block_Checkout;
use Smart_Send\Frontend\Checkout;
use Smart_Send\Shipping_Method\Method;
use Smart_Send\Shipping_Method\Method_Code;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;
use WC_Order;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send Store API extensions for the WooCommerce Checkout Block.
 *
 * The headless twin of Checkout (#74): extends the Store API
 * cart endpoint with the server-computed pickup point state ("data down"),
 * accepts the shopper's agent_no on the checkout endpoint and persists it
 * through the delivery-details repository ("data up"), and keeps the
 * in-progress selection in the WooCommerce session via the extension
 * update callback. All Store API references live inside method bodies
 * (string class names, literal endpoint identifiers), so this file loads
 * eagerly with the rest of the delivery-options surface - no definition-time
 * dependency on WooCommerce.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Store_API {

	/**
	 * The Store API cart endpoint identifier. Deliberately the literal
	 * string instead of CartSchema::IDENTIFIER: the schema classes moved
	 * namespace when the Store API graduated into WooCommerce core, so
	 * no class-constant reference works across the supported WC range.
	 * The identifiers themselves are frozen public API.
	 */
	const ENDPOINT_CART = 'cart';

	/**
	 * The Store API checkout endpoint identifier (see ENDPOINT_CART on
	 * why this is a literal).
	 */
	const ENDPOINT_CHECKOUT = 'checkout';

	/**
	 * WooCommerce session key holding the shopper's in-progress pickup
	 * point selection on the block checkout (pushed by the block via the
	 * extension update callback), so it survives cart refreshes until
	 * checkout submission persists it on the order.
	 */
	const SESSION_SELECTED_AGENT_NO = 'ss_shipping_selected_agent_no';

	/**
	 * Headless pickup point lookup (API + session cache).
	 *
	 * @var Pickup_Point_Lookup
	 */
	protected Pickup_Point_Lookup $pickup_point_lookup;

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
	 * Order meta repository (delivery details).
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
	 * Checkout delivery-option resolution (which sections checkout
	 * renders, pickup point section statuses and texts).
	 *
	 * @var Checkout_Options
	 */
	protected Checkout_Options $checkout_options;

	/**
	 * All collaborators are stateless, so fresh defaults are safe for
	 * ad-hoc construction (tests construct the component directly).
	 *
	 * @param Pickup_Point_Lookup|null    $pickup_point_lookup    Headless pickup point lookup.
	 * @param Pickup_Point_Formatter|null $pickup_point_formatter Pickup point display formatter.
	 * @param Settings|null               $settings               Typed plugin settings reader.
	 * @param Order_Meta|null             $order_meta             Order meta repository.
	 * @param Method_Resolver|null        $method_resolver        Shipping method resolver.
	 * @param Checkout_Options|null       $checkout_options       Checkout delivery-option resolution.
	 */
	public function __construct( ?Pickup_Point_Lookup $pickup_point_lookup = null, ?Pickup_Point_Formatter $pickup_point_formatter = null, ?Settings $settings = null, ?Order_Meta $order_meta = null, ?Method_Resolver $method_resolver = null, ?Checkout_Options $checkout_options = null ) {
		$this->pickup_point_lookup    = null === $pickup_point_lookup ? new Pickup_Point_Lookup() : $pickup_point_lookup;
		$this->pickup_point_formatter = null === $pickup_point_formatter ? new Pickup_Point_Formatter() : $pickup_point_formatter;
		$this->settings               = null === $settings ? new Settings() : $settings;
		$this->order_meta             = null === $order_meta ? new Order_Meta() : $order_meta;
		$this->method_resolver        = null === $method_resolver ? new Method_Resolver( $this->settings ) : $method_resolver;
		$this->checkout_options       = null === $checkout_options ? new Checkout_Options() : $checkout_options;
	}

	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// woocommerce_blocks_loaded fires during plugins_loaded, which is
		// before this registration pass (the composition root wires
		// components on init 0) - so when it already fired, register the
		// Store API extensions directly instead of hooking it.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api_extensions();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extensions' ) );
		}

		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'persist_pickup_point_from_request' ), 10, 2 );
	}

	/**
	 * Register the cart/checkout schema extensions and the extension
	 * update callback with the Store API, under the same namespace as
	 * the Blocks integration (Block_Checkout::INTEGRATION_NAME).
	 *
	 * @return void
	 */
	public function register_store_api_extensions() {
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => self::ENDPOINT_CART,
				'namespace'       => Block_Checkout::INTEGRATION_NAME,
				'data_callback'   => array( $this, 'cart_extension_data' ),
				'schema_callback' => array( $this, 'cart_extension_schema' ),
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => self::ENDPOINT_CHECKOUT,
				'namespace'       => Block_Checkout::INTEGRATION_NAME,
				'schema_callback' => array( $this, 'checkout_extension_schema' ),
			)
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => Block_Checkout::INTEGRATION_NAME,
				'callback'  => array( $this, 'store_selected_agent_no' ),
			)
		);
	}

	/**
	 * The server-computed pickup point state riding the cart response
	 * ("data down"): whether the chosen rate is a Smart Send agent-type
	 * method, the pickup points near the customer's shipping address
	 * (labels pre-formatted, same pipeline as the classic checkout),
	 * the session-stored selection, and the display settings the block
	 * needs. The block re-fetches the cart on every address/rate change,
	 * so this recomputes automatically with no client-side fetch logic.
	 *
	 * @return array
	 */
	public function cart_extension_data() {
		$method_code   = $this->chosen_agent_rate_method_code();
		$pickup_points = array();
		$status        = null;

		if ( null !== $method_code ) {
			list( $found, $status ) = $this->lookup_pickup_points_for_customer( $method_code->carrier() );

			foreach ( $found as $pickup_point ) {
				$pickup_points[] = array(
					'agent_no' => (string) $pickup_point->get_agent_no(),
					'label'    => $this->pickup_point_formatter->dropdown_label( $pickup_point ),
				);
			}
		}

		return array(
			'selected_rate_is_agent' => null !== $method_code,
			'pickup_points'          => $pickup_points,
			// The pickup point section state (one of the
			// Checkout_Options PICKUP_POINT_STATUS_* slugs) and its
			// customer-facing text, server-side i18n - the block renders
			// the message verbatim and keys its behaviour (validation,
			// styling) off the status. Null when the chosen rate is not
			// an agent method.
			'pickup_point_status'    => $status,
			'pickup_point_message'   => null === $status ? null : $this->checkout_options->pickup_point_status_message( $status ),
			// Back-compat flag (pre-status consumers): true only when the
			// lookup ran and found nothing near the address.
			'no_pickup_points_found' => Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND === $status,
			'selected_agent_no'      => $this->get_selected_agent_no(),
			'select_default'         => $this->settings->default_select_agent(),
		);
	}

	/**
	 * Schema of the cart extension data (all read-only, server-computed).
	 *
	 * @return array
	 */
	public function cart_extension_schema() {
		return array(
			'selected_rate_is_agent' => array(
				'description' => __( 'Whether the chosen shipping rate is a Smart Send agent-type method.', 'smart-send-logistics' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'pickup_points'          => array(
				'description' => __( 'The pickup points closest to the customer shipping address, with pre-formatted labels.', 'smart-send-logistics' ),
				'type'        => 'array',
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'agent_no' => array(
							'description' => __( 'The pickup point agent number.', 'smart-send-logistics' ),
							'type'        => 'string',
							'readonly'    => true,
						),
						'label'    => array(
							'description' => __( 'The display label, formatted per the "Dropdown display format" setting.', 'smart-send-logistics' ),
							'type'        => 'string',
							'readonly'    => true,
						),
					),
				),
			),
			'pickup_point_status'    => array(
				'description' => __( 'The pickup point section state: found, address_incomplete, not_connected, auth_failed, access_denied, none_found or lookup_failed. Null when the chosen rate is not a Smart Send agent-type method.', 'smart-send-logistics' ),
				'type'        => array( 'string', 'null' ),
				'enum'        => array(
					Checkout_Options::PICKUP_POINT_STATUS_FOUND,
					Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE,
					Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED,
					Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED,
					Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED,
					Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND,
					Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED,
					null,
				),
				'readonly'    => true,
			),
			'pickup_point_message'   => array(
				'description' => __( 'The customer-facing text of the pickup point section state, translated server-side. Null when the selector renders instead of a message.', 'smart-send-logistics' ),
				'type'        => array( 'string', 'null' ),
				'readonly'    => true,
			),
			'no_pickup_points_found' => array(
				'description' => __( 'Whether the lookup ran for the customer address and found no pickup points at all.', 'smart-send-logistics' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'selected_agent_no'      => array(
				'description' => __( 'The agent number of the pickup point currently selected in this session, if any.', 'smart-send-logistics' ),
				'type'        => array( 'string', 'null' ),
				'readonly'    => true,
			),
			'select_default'         => array(
				'description' => __( 'Whether the closest pickup point is pre-selected (the "Select Default" setting).', 'smart-send-logistics' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Schema of the data the checkout endpoint accepts from the block
	 * ("data up"): the selected pickup point's agent number.
	 *
	 * @return array
	 */
	public function checkout_extension_schema() {
		return array(
			'agent_no' => array(
				'description' => __( 'The agent number of the pickup point selected for the order.', 'smart-send-logistics' ),
				'type'        => array( 'string', 'null' ),
				'optional'    => true,
			),
		);
	}

	/**
	 * Extension update callback (wc/store/v1/cart/extensions): keep the
	 * shopper's in-progress selection in the WooCommerce session so it
	 * survives cart refreshes. An empty agent_no clears the selection.
	 *
	 * @param array $data The posted extension data.
	 *
	 * @return void
	 */
	public function store_selected_agent_no( $data ) {
		if ( ! is_array( $data ) || ! array_key_exists( 'agent_no', $data ) || null === WC()->session ) {
			return;
		}

		$agent_no = (string) wc_clean( wp_unslash( $data['agent_no'] ) );

		WC()->session->set( self::SESSION_SELECTED_AGENT_NO, '' === $agent_no ? null : $agent_no );
	}

	/**
	 * The session-stored in-progress selection, if any.
	 *
	 * @return string|null
	 */
	public function get_selected_agent_no() {
		if ( null === WC()->session ) {
			return null;
		}

		$agent_no = WC()->session->get( self::SESSION_SELECTED_AGENT_NO );

		return empty( $agent_no ) ? null : (string) $agent_no;
	}

	/**
	 * Persist the submitted pickup point on the order during Store API
	 * checkout (woocommerce_store_api_checkout_update_order_from_request).
	 *
	 * Mirrors the classic checkout: a non-agent method ignores any
	 * submitted agent_no; an agent method requires one and re-resolves
	 * it server-side (session-cached lookup results first, then the
	 * find_by_agent_no API call) before writing the SERVER-side pickup
	 * point object through the repository - client data never reaches
	 * order meta, and the stored meta is byte-identical to the classic
	 * checkout path.
	 *
	 * @param WC_Order        $order   The order being placed.
	 * @param WP_REST_Request $request The checkout request.
	 *
	 * @throws Exception A Store API RouteException (HTTP 400) when an agent method is submitted without a resolvable agent_no.
	 *
	 * @return void
	 */
	public function persist_pickup_point_from_request( $order, $request ) {
		$method_code = new Method_Code( $this->method_resolver->resolve_outbound( $order ) );

		if ( ! $this->checkout_options->show_pickup_points( $method_code ) ) {
			return;
		}

		$agent_no = $this->requested_agent_no( $request );

		if ( '' === $agent_no ) {
			// Classic-checkout parity: when the lookup offered NO pickup
			// points for this session - none near the address, lookup
			// failure, or plugin not connected - there is nothing to
			// select, so the order is allowed through with no pickup
			// point meta (the classic checkout renders no dropdown in
			// those cases and its validation passes). The distinction
			// comes from the server-side session cache the lookup
			// maintains (it caches an empty array in every failure path
			// too) - never from a client-supplied claim.
			if ( $this->no_pickup_points_were_available() ) {
				Logger::info(
					'No pickup points were available for the address - order placed without a pickup point selection',
					array( 'order_id' => $order->get_id() )
				);

				return;
			}

			$this->reject_checkout();
		}

		$pickup_point = $this->resolve_pickup_point( $method_code->carrier(), $order, $agent_no );

		if ( null === $pickup_point ) {
			$this->reject_checkout();
		}

		$details = new Delivery_Details();
		$details->set_pickup_point( $pickup_point );
		$this->order_meta->write( $order, $details );

		Logger::info(
			'Pickup point selected at checkout',
			array(
				'order_id' => $order->get_id(),
				'agent_no' => $pickup_point->get_agent_no(),
			)
		);
	}

	/**
	 * The agent_no submitted under our extension namespace, or '' when
	 * none was submitted.
	 *
	 * @param WP_REST_Request $request The checkout request.
	 *
	 * @return string
	 */
	protected function requested_agent_no( $request ) {
		$extensions = $request->get_param( 'extensions' );

		if ( ! is_array( $extensions ) || empty( $extensions[ Block_Checkout::INTEGRATION_NAME ]['agent_no'] ) ) {
			return '';
		}

		return (string) wc_clean( wp_unslash( $extensions[ Block_Checkout::INTEGRATION_NAME ]['agent_no'] ) );
	}

	/**
	 * Whether the last pickup point lookup ran for this session and
	 * found no points at all. Reads the lookup's session cache: an
	 * EMPTY array means a lookup ran and found nothing (the lookup
	 * caches empty results too); null/absent means no lookup ran, which
	 * keeps the conservative rejection path.
	 *
	 * @return bool
	 */
	protected function no_pickup_points_were_available(): bool {
		$cached = $this->pickup_point_lookup->get_session_pickup_points();

		return array() === $cached;
	}

	/**
	 * Re-resolve a submitted agent number into the server-side pickup
	 * point: the session-cached lookup results first (cheap, already
	 * validated - the same cache the classic checkout resolves
	 * against), the shared find_by_agent_no() API lookup (#182) as
	 * fallback.
	 *
	 * @param string   $carrier  Unique carrier code (e.g. 'postnord').
	 * @param WC_Order $order    The order being placed.
	 * @param string   $agent_no The submitted agent number.
	 *
	 * @return Pickup_Point|null The pickup point, or null when the agent number cannot be resolved.
	 */
	protected function resolve_pickup_point( $carrier, $order, $agent_no ): ?Pickup_Point {
		$cached = $this->pickup_point_lookup->find_cached_by_agent_no( $agent_no );

		if ( null !== $cached ) {
			return $cached;
		}

		// The request and response (incl. HTTP status code and endpoint)
		// are logged by the client's request logger.
		try {
			return $this->pickup_point_lookup->find_by_agent_no( $carrier, (string) $order->get_shipping_country(), $agent_no );
		} catch ( Pickup_Point_Not_Found_Exception $e ) {
			unset( $e ); // The agent number cannot be resolved - fall through to the rejection below.
		}

		Logger::warning(
			'Pickup point not found - agent number rejected',
			array(
				'order_id' => $order->get_id(),
				'agent_no' => $agent_no,
				'carrier'  => $carrier,
			)
		);

		return null;
	}

	/**
	 * Reject the checkout with a Store API validation error (HTTP 400),
	 * message-parity with the classic checkout's validate_agent_selected().
	 *
	 * @throws Exception The Store API RouteException.
	 *
	 * @return void
	 */
	protected function reject_checkout() {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'ss_shipping_pickup_point_required',
			esc_html__( 'A pickup point must be selected.', 'smart-send-logistics' ),
			400
		);
	}

	/**
	 * The Smart Send method code of the session's chosen shipping rate,
	 * when that rate is a Smart Send agent-type method; null otherwise.
	 *
	 * The server-side twin of the classic checkout's rate check in
	 * Checkout::display_ss_pickup_points(): the chosen rate
	 * comes from the session, its Smart Send method code from the rate
	 * meta added by Method::calculate_shipping().
	 *
	 * @return Method_Code|null
	 */
	protected function chosen_agent_rate_method_code() {
		if ( null === WC()->session || null === WC()->shipping() ) {
			return null;
		}

		$chosen_methods  = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_shipping = is_array( $chosen_methods ) ? current( $chosen_methods ) : false;

		if ( empty( $chosen_shipping ) ) {
			return null;
		}

		foreach ( WC()->shipping()->get_packages() as $package ) {
			if ( ! isset( $package['rates'][ $chosen_shipping ] ) ) {
				continue;
			}

			$rate = $package['rates'][ $chosen_shipping ];

			if ( SS_SHIPPING_METHOD_ID !== $rate->get_method_id() ) {
				return null;
			}

			$meta_data = $rate->get_meta_data();

			if ( empty( $meta_data['smart_send_shipping_method'] ) ) {
				return null;
			}

			$method_code = new Method_Code( $meta_data['smart_send_shipping_method'] );

			return stripos( $method_code->type(), 'agent' ) !== false ? $method_code : null;
		}

		return null;
	}

	/**
	 * Look up the closest pickup points from the customer's shipping
	 * address as WooCommerce knows it server-side (WC()->customer, never
	 * client request data), yielding the points plus the section status.
	 * An incomplete address (no country, postcode or street - city is
	 * optional, matching the classic checkout) makes no API call and
	 * yields the address_incomplete status. Lookup failures are logged
	 * and session-cached by Pickup_Point_Lookup itself and
	 * mapped to their status here - only rendering data leaves this
	 * method.
	 *
	 * @param string $carrier Unique carrier code (e.g. 'postnord').
	 *
	 * @return array{0: Pickup_Point[], 1: string} [pickup points (possibly empty), PICKUP_POINT_STATUS_* slug]
	 */
	protected function lookup_pickup_points_for_customer( $carrier ): array {
		$customer = WC()->customer;

		$country     = null === $customer ? '' : $customer->get_shipping_country();
		$postal_code = null === $customer ? '' : $customer->get_shipping_postcode();
		$city        = null === $customer ? '' : $customer->get_shipping_city();
		$street      = null === $customer ? '' : $customer->get_shipping_address();

		if ( empty( $country ) || empty( $postal_code ) || empty( $street ) ) {
			return array( array(), Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE );
		}

		try {
			$found = $this->pickup_point_lookup->find_closest_by_address( $carrier, $country, $postal_code, empty( $city ) ? null : $city, $street );
		} catch ( Exception $e ) {
			return array( array(), $this->checkout_options->pickup_point_status_for_exception( $e ) );
		}

		return array(
			$found,
			empty( $found ) ? Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND : Checkout_Options::PICKUP_POINT_STATUS_FOUND,
		);
	}
}
