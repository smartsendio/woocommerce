<?php

namespace Smart_Send\Delivery_Options;

use Exception;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Pickup_Point;
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
	 * @param Checkout_Options|null       $checkout_options       Checkout delivery-option resolution.
	 */
	public function __construct( ?Pickup_Point_Lookup $pickup_point_lookup = null, ?Pickup_Point_Formatter $pickup_point_formatter = null, ?Settings $settings = null, ?Order_Meta $order_meta = null, ?Checkout_Options $checkout_options = null ) {
		$this->pickup_point_lookup    = null === $pickup_point_lookup ? new Pickup_Point_Lookup() : $pickup_point_lookup;
		$this->pickup_point_formatter = null === $pickup_point_formatter ? new Pickup_Point_Formatter() : $pickup_point_formatter;
		$this->settings               = null === $settings ? new Settings() : $settings;
		$this->order_meta             = null === $order_meta ? new Order_Meta() : $order_meta;
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
		$selected      = null;
		$context       = null;

		if ( null !== $method_code ) {
			$context                = $this->pickup_point_context( $method_code, WC()->customer );
			$selected               = $this->pickup_point_lookup->get_selected( $method_code->carrier(), $context['country'], true );
			list( $found, $status ) = $this->lookup_pickup_points_for_customer( $method_code->carrier() );

			// Explicit choices may be near a workplace, outside the latest closest
			// results. A compatible, previously validated choice remains available.
			if ( null !== $selected ) {
				$found = array_filter(
					$found,
					static function ( Pickup_Point $point ) use ( $selected ) {
						return $point->get_agent_no() !== $selected->get_agent_no();
					}
				);
				array_unshift( $found, $selected );
				$status = Checkout_Options::PICKUP_POINT_STATUS_FOUND;
			} elseif ( $this->settings->default_select_agent() && ! empty( $found ) ) {
				$selected = $this->pickup_point_lookup->select( $method_code->carrier(), $context['country'], (string) reset( $found )->get_agent_no(), false );
			} else {
				$this->pickup_point_lookup->clear_selection();
			}

			foreach ( $found as $pickup_point ) {
				$pickup_points[] = array(
					'agent_no' => (string) $pickup_point->get_agent_no(),
					'label'    => $this->pickup_point_formatter->dropdown_label( $pickup_point ),
				);
			}
		} else {
			$this->pickup_point_lookup->clear_selection();
		}

		return array(
			'selected_rate_is_agent' => null !== $method_code,
			'pickup_points'          => $pickup_points,
			'pickup_point_status'    => $status,
			'pickup_point_message'   => null === $status ? null : $this->checkout_options->pickup_point_status_message( $status ),
			'no_pickup_points_found' => Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND === $status,
			'selected_agent_no'      => null === $selected ? null : (string) $selected->get_agent_no(),
			'selection_origin'       => null === $selected ? null : ( $this->pickup_point_lookup->is_selection_explicit() ? 'explicit' : 'automatic' ),
			'pickup_point_context'   => $context,
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
			'selection_origin'       => array(
				'type'     => array( 'string', 'null' ),
				'enum'     => array( 'explicit', 'automatic', null ),
				'readonly' => true,
			),
			'pickup_point_context'   => array_merge( $this->context_schema(), array( 'readonly' => true ) ),
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
			'agent_no'             => array(
				'description' => __( 'The agent number of the pickup point selected for the order.', 'smart-send-logistics' ),
				'type'        => array( 'string', 'null' ),
				'optional'    => true,
			),
			'selection_origin'     => array(
				'type'     => array( 'string', 'null' ),
				'enum'     => array( 'explicit', 'automatic', null ),
				'optional' => true,
			),
			'pickup_point_context' => array_merge( $this->context_schema(), array( 'optional' => true ) ),
		);
	}

	/**
	 * Context accompanies the selection so an old cart response cannot choose
	 * a point for a newer address or shipping method.
	 *
	 * @return array
	 */
	protected function context_schema(): array {
		$properties = array();
		foreach ( array( 'method', 'country', 'postcode', 'city', 'address_1' ) as $field ) {
			$properties[ $field ] = array( 'type' => 'string' );
		}
		return array(
			'type'       => array( 'object', 'null' ),
			'properties' => $properties,
		);
	}

	/**
	 * Build the same context from either the cart customer or final order.
	 *
	 * @param Method_Code $method_code Shipping method.
	 * @param object|null $address WooCommerce customer or order.
	 * @return array
	 */
	protected function pickup_point_context( Method_Code $method_code, $address ): array {
		return array(
			'method'    => $method_code->carrier() . '_' . $method_code->type(),
			'country'   => null === $address ? '' : strtoupper( trim( (string) $address->get_shipping_country() ) ),
			'postcode'  => null === $address ? '' : wc_format_postcode( trim( (string) $address->get_shipping_postcode() ), (string) $address->get_shipping_country() ),
			'city'      => null === $address ? '' : trim( (string) $address->get_shipping_city() ),
			'address_1' => null === $address ? '' : trim( (string) $address->get_shipping_address_1() ),
		);
	}

	/**
	 * Headless callers may omit context; a supplied context must match.
	 *
	 * @param array $data Submitted extension data.
	 * @param array $context Authoritative server context.
	 * @return void
	 */
	protected function validate_context( array $data, array $context ): void {
		if ( ! array_key_exists( 'pickup_point_context', $data ) ) {
			return;
		}
		$submitted = $data['pickup_point_context'];
		foreach ( $context as $field => $value ) {
			if ( ! is_array( $submitted ) || ! isset( $submitted[ $field ] ) || ! is_string( $submitted[ $field ] ) || $submitted[ $field ] !== $value ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'ss_shipping_pickup_point_context_changed',
					esc_html__( 'The shipping address or method changed. Please select a pickup point again.', 'smart-send-logistics' ),
					400
				);
			}
		}
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
		// The extensions endpoint invokes this callback before calculating totals.
		// A fresh request has no in-memory shipping packages yet.
		if ( null !== WC()->cart ) {
			WC()->cart->calculate_shipping();
		}
		$method_code = $this->chosen_agent_rate_method_code();
		if ( null === $method_code ) {
			$this->pickup_point_lookup->clear_selection();
			$this->reject_checkout();
		}
		$context = $this->pickup_point_context( $method_code, WC()->customer );
		$this->validate_context( $data, $context );
		$agent_no = $this->clean_agent_no( $data['agent_no'] );
		if ( '' === $agent_no ) {
			$this->pickup_point_lookup->clear_selection();
			return;
		}
		try {
			$this->pickup_point_lookup->select( $method_code->carrier(), $context['country'], $agent_no, true );
		} catch ( Exception $e ) {
			$this->reject_checkout();
		}
	}

	/**
	 * Sanitize the point number without accepting arrays or objects.
	 *
	 * @param mixed $agent_no Submitted point number.
	 * @return string
	 */
	protected function clean_agent_no( $agent_no ): string {
		if ( null !== $agent_no && ! is_scalar( $agent_no ) ) {
			$this->reject_checkout();
		}
		return (string) wc_clean( wp_unslash( (string) $agent_no ) );
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
		$method_code = $this->order_agent_method_code( $order );
		if ( null === $method_code ) {
			$this->pickup_point_lookup->clear_selection();
			$this->order_meta->write( $order, ( new Delivery_Details() )->clear_pickup_point() );
			return;
		}

		$extensions = $request->get_param( 'extensions' );
		$data       = is_array( $extensions ) && isset( $extensions[ Block_Checkout::INTEGRATION_NAME ] ) ? $extensions[ Block_Checkout::INTEGRATION_NAME ] : array();
		if ( ! is_array( $data ) ) {
			$this->reject_checkout();
		}
		$context = $this->pickup_point_context( $method_code, $order );
		$this->validate_context( $data, $context );
		$agent_no = $this->clean_agent_no( $data['agent_no'] ?? '' );

		if ( '' === $agent_no ) {
			$explicit = $this->pickup_point_lookup->get_selected( $method_code->carrier(), $context['country'], true );
			if ( null === $explicit && $this->pickup_point_lookup->no_pickup_points_for_address( $method_code->carrier(), $context['country'], $context['postcode'], '' === $context['city'] ? null : $context['city'], $context['address_1'] ) ) {
				$this->order_meta->write( $order, ( new Delivery_Details() )->clear_pickup_point() );
				Logger::info( 'No pickup points were available for the address - order placed without a pickup point selection', array( 'order_id' => $order->get_id() ) );
				return;
			}
			$this->reject_checkout();
		}

		try {
			if ( 'automatic' === ( $data['selection_origin'] ?? null ) ) {
				$closest = $this->pickup_point_lookup->find_closest_by_address( $method_code->carrier(), $context['country'], $context['postcode'], '' === $context['city'] ? null : $context['city'], $context['address_1'] );
				if ( empty( $closest ) || (string) reset( $closest )->get_agent_no() !== $agent_no ) {
					$this->reject_checkout();
				}
			}
			$pickup_point = $this->pickup_point_lookup->resolve_selection( $method_code->carrier(), $context['country'], $agent_no );
		} catch ( Exception $e ) {
			Logger::warning(
				'Pickup point not found - agent number rejected',
				array(
					'order_id' => $order->get_id(),
					'agent_no' => $agent_no,
					'carrier'  => $method_code->carrier(),
				)
			);
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
	 * Checkout only requires a point for an actual Smart Send agent rate.
	 * Booking fallbacks for third-party rates do not display our selector.
	 *
	 * @param WC_Order $order Checkout order.
	 * @return Method_Code|null
	 */
	protected function order_agent_method_code( WC_Order $order ): ?Method_Code {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( SS_SHIPPING_METHOD_ID !== $item->get_method_id() ) {
				return null;
			}
			$method_code = new Method_Code( (string) $item->get_meta( 'smart_send_shipping_method', true ) );
			return $this->checkout_options->show_pickup_points( $method_code ) ? $method_code : null;
		}
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
