<?php

namespace Smart_Send\Frontend;

use Exception;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Delivery_Options\Checkout_Options;
use Smart_Send\Delivery_Options\Pickup_Point_Formatter;
use Smart_Send\Delivery_Options\Pickup_Point_Lookup;
use Smart_Send\Exceptions\Not_Connected_Exception;
use Smart_Send\Shipping_Method\Method_Code;
use Smart_Send\Support\Checkout_Debug;
use Smart_Send\Support\Logger;
use Smart_Send\Support\Settings;
use WC_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Frontend.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Checkout {

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
	 * ad-hoc construction (tests construct the frontend directly).
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
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'display_ss_pickup_points' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_agent_selected' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'process_ss_pickup_points' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'display_ss_shipping_agent' ), 10, 2 );
	}

	/**
	 * Enqueue the checkout stylesheet - owned by this component instead
	 * of a blanket enqueue on the plugin singleton (#140).
	 *
	 * @return void
	 */
	public function enqueue_frontend_styles() {
		wp_enqueue_style( 'ss-shipping-frontend-css', SS_SHIPPING_PLUGIN_DIR_URL . '/public/css/ss-shipping-frontend.css', array(), SS_SHIPPING_VERSION );
		if ( is_checkout() && ! has_block( 'woocommerce/checkout' ) ) {
			wp_enqueue_script( 'ss-shipping-checkout', SS_SHIPPING_PLUGIN_DIR_URL . '/public/js/ss-shipping-checkout.js', array( 'jquery', 'wc-checkout' ), SS_SHIPPING_VERSION, true );
		}
	}

	/**
	 * Display the pickup point section next to the chosen Smart Send
	 * agent rate: the selector when the lookup found pickup points, a
	 * status message (enter address / not connected / auth failure /
	 * none found / fallback) otherwise. The statuses and their texts
	 * live on Checkout_Options, shared with the Checkout
	 * Block surface.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $index is part of the woocommerce_after_shipping_rate hook signature.
	public function display_ss_pickup_points( $method, $index ) {

		// Only display pickup points on checkout
		if ( ! is_checkout() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- pre-existing behaviour: this renders inside WooCommerce's own checkout update_order_review AJAX cycle, which carries no plugin nonce; changing the input handling is out of scope for the #43 move.

		$chosen_methods  = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_shipping = is_array( $chosen_methods ) ? current( $chosen_methods ) : false;

		$method_id   = $method->get_method_id();
		$shipping_id = $method->get_id();

		$meta_data = $method->get_meta_data();

		if ( ! $chosen_shipping || $chosen_shipping !== $shipping_id ) {
			return;
		}
		if ( SS_SHIPPING_METHOD_ID !== $method_id || empty( $meta_data['smart_send_shipping_method'] ) ) {
			$this->pickup_point_lookup->clear_selection();
			return;
		}

		$method_code = new Method_Code( $meta_data['smart_send_shipping_method'] );

		if ( ! $this->checkout_options->show_pickup_points( $method_code ) ) {
			$this->pickup_point_lookup->clear_selection();
			return;
		}

		list( $country, $postal_code, $city, $street ) = $this->resolve_shipping_address();
		$selection                                     = $this->selection_for_display( $method_code->carrier(), (string) $country );
		$selection['address']                          = $this->address_context( array( $country, $postal_code, $city, $street ) );

		$ss_pickup_points = array();
		$lookup_exception = null;

		if ( empty( $country ) || empty( $postal_code ) || empty( $street ) ) {
			// No lookup can run without an address - render the
			// enter-your-address hint (also on the very first, non-AJAX
			// page load).
			$status = Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE;
		} else {
			try {
				$ss_pickup_points = $this->find_closest_agents_by_address( $method_code->carrier(), $country, $postal_code, $city, $street );

				$status = empty( $ss_pickup_points )
					? Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND
					: Checkout_Options::PICKUP_POINT_STATUS_FOUND;
			} catch ( Exception $e ) {
				// Logged and session-cached by the lookup itself - only
				// rendering is left to do here.
				$status           = $this->checkout_options->pickup_point_status_for_exception( $e );
				$lookup_exception = $e;
			}
		}

		// One-line debug bar summary of the delivery-options section:
		// pickup points apply to this method, plus the lookup outcome.
		$this->add_pickup_point_section_notice( $method->get_label(), $method->get_id(), $status, count( $ss_pickup_points ), $lookup_exception );

		if ( $selection['point'] ) {
			$ss_pickup_points[] = $selection['point'];
			$status             = Checkout_Options::PICKUP_POINT_STATUS_FOUND;
		}

		if ( Checkout_Options::PICKUP_POINT_STATUS_FOUND === $status || $selection['error'] ) {
			$pickup_point_options = array();
			if ( ! $this->settings->default_select_agent() || $selection['error'] ) {
				$pickup_point_options[''] = __( '- Select Pickup Point -', 'smart-send-logistics' );
			}

			foreach ( $ss_pickup_points as $pickup_point ) {
				$pickup_point_options[ $pickup_point->get_agent_no() ] = $this->pickup_point_formatter->dropdown_label( $pickup_point );
			}

			if ( ! $selection['point'] && ! $selection['error'] && $this->settings->default_select_agent() && $ss_pickup_points ) {
				$first = reset( $ss_pickup_points );
				try {
					$selection['point']    = $this->pickup_point_lookup->select( $method_code->carrier(), (string) $country, (string) $first->get_agent_no(), false );
					$selection['agent_no'] = $selection['point']->get_agent_no();
					$selection['origin']   = 'automatic';
				} catch ( Exception $e ) {
					$selection['error'] = $this->selection_error_message();
				}
			}

			if ( $selection['error'] ) {
				// Keep an explicit rejected choice visible until the shopper changes it.
				$pickup_point_options[ $selection['agent_no'] ] = sprintf(
					/* translators: %s: previously selected pickup point number. */
					__( 'Previously selected pickup point (%s)', 'smart-send-logistics' ),
					$selection['agent_no']
				);
				printf( '<div class="woocommerce-error ss-agent-selection-error" role="alert">%s</div>', esc_html( $selection['error'] ) );
			}

			woocommerce_form_field(
				'ss_shipping_store_pickup',
				array(
					'type'              => 'select',
					'options'           => $pickup_point_options,
					'input_class'       => array( 'ss-agent-list' ),
					'custom_attributes' => array(
						'data-ss-carrier' => $method_code->carrier(),
						'data-ss-country' => (string) $country,
					),
				),
				$selection['agent_no']
			);
			foreach ( array( 'origin', 'carrier', 'country', 'address' ) as $field ) {
				printf( '<input type="hidden" name="ss_shipping_pickup_%1$s" value="%2$s" />', esc_attr( $field ), esc_attr( $selection[ $field ] ) );
			}
		} else {
			printf(
				'<div class="%1$s ss-agent-info ss-agent-info--%2$s">%3$s</div>',
				$this->checkout_options->is_pickup_point_error_status( $status ) ? 'woocommerce-error' : 'woocommerce-info',
				esc_attr( $status ),
				esc_html( $this->checkout_options->pickup_point_status_message( $status ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Show the one-line delivery-options summary in the checkout shipping
	 * debug bar: pickup points apply to the chosen method, plus the lookup
	 * outcome (found N / waiting for the address / the failure).
	 *
	 * @param string          $method_label     The chosen shipping rate's label.
	 * @param string          $rate_id          The chosen shipping rate's id (e.g. 'smart_send_shipping:2').
	 * @param string          $status           The Checkout_Options::PICKUP_POINT_STATUS_* slug.
	 * @param int             $found_count      How many pickup points the lookup found.
	 * @param \Exception|null $lookup_exception The lookup failure, when the status came from one.
	 * @return void
	 */
	protected function add_pickup_point_section_notice( $method_label, $rate_id, $status, $found_count, $lookup_exception ) {
		$error_message = null === $lookup_exception ? '' : $lookup_exception->getMessage();

		switch ( $status ) {
			case Checkout_Options::PICKUP_POINT_STATUS_FOUND:
				$outcome = sprintf(
					/* translators: %d: number of pickup points found. */
					_n( 'Found %d pickup point near the entered address.', 'Found %d pickup points near the entered address.', $found_count, 'smart-send-logistics' ),
					$found_count
				);
				break;
			case Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE:
				$outcome = __( 'Waiting for the shipping address.', 'smart-send-logistics' );
				break;
			case Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED:
				$outcome = __( 'No API token configured (plugin not connected).', 'smart-send-logistics' );
				break;
			case Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED:
				/* translators: %s: error message returned by the Smart Send API. */
				$outcome = sprintf( __( 'Failed with an authentication error (HTTP 401): %s', 'smart-send-logistics' ), $error_message );
				break;
			case Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED:
				/* translators: %s: error message returned by the Smart Send API. */
				$outcome = sprintf( __( 'Failed with an authorization error (HTTP 403): %s', 'smart-send-logistics' ), $error_message );
				break;
			case Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND:
				$outcome = __( 'No pickup points found near the entered address.', 'smart-send-logistics' );
				break;
			default:
				if ( $lookup_exception instanceof \Smart_Send\API\Exceptions\Connection_Exception ) {
					/* translators: %s: transport error message. */
					$outcome = sprintf( __( 'Failed with a transport error: %s', 'smart-send-logistics' ), $error_message );
				} else {
					/* translators: %s: error message returned by the Smart Send API. */
					$outcome = sprintf( __( 'Failed with an API error: %s', 'smart-send-logistics' ), $error_message );
				}
				break;
		}

		Checkout_Debug::add_notice(
			sprintf(
				/* translators: 1: shipping rate label, 2: shipping rate id, 3: the pickup point lookup outcome sentence. */
				__( 'Smart Send: Showing pickup point for "%1$s" (%2$s). %3$s', 'smart-send-logistics' ),
				$method_label,
				$rate_id,
				$outcome
			)
		);
	}

	/**
	 * The shipping address the pickup point lookup should run for: the
	 * address fields posted by WooCommerce's update_order_review AJAX
	 * cycle when present, the customer's session-stored shipping address
	 * otherwise (the very first, non-AJAX checkout page load posts
	 * nothing - the same server-side source the Checkout Block surface
	 * reads).
	 *
	 * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null} [country, postal_code, city, street]
	 */
	protected function resolve_shipping_address(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: values are wc_clean-ed; this renders inside WooCommerce's own checkout update_order_review AJAX cycle, which carries no plugin nonce.
		if ( ! empty( $_POST['s_country'] ) && ! empty( $_POST['s_postcode'] ) && ! empty( $_POST['s_address'] ) ) {
			return array(
				wc_clean( wp_unslash( $_POST['s_country'] ) ),
				wc_clean( wp_unslash( $_POST['s_postcode'] ) ),
				( ! empty( $_POST['s_city'] ) ? wc_clean( wp_unslash( $_POST['s_city'] ) ) : null ), // not required but preferred.
				wc_clean( wp_unslash( $_POST['s_address'] ) ),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		$customer = WC()->customer;

		if ( null === $customer ) {
			return array( null, null, null, null );
		}

		$city = $customer->get_shipping_city();

		return array(
			$customer->get_shipping_country(),
			$customer->get_shipping_postcode(),
			empty( $city ) ? null : $city,
			$customer->get_shipping_address(),
		);
	}

	/**
	 * Find the closest pickup points by address - delegates to the
	 * headless Pickup_Point_Lookup (#139), which owns the
	 * API call, the lookup filters, the failure reporting and the
	 * session cache.
	 *
	 * @param $carrier string | unique carrier code
	 * @param $country string | ISO3166-A2 Country code
	 * @param $postal_code string
	 * @param $city string
	 * @param $street string
	 *
	 * @throws Not_Connected_Exception When no API token is configured.
	 * @throws \Smart_Send\API\Exceptions\HTTP_Client_Exception When the API call fails.
	 *
	 * @return Pickup_Point[] The found pickup points (possibly empty).
	 */
	public function find_closest_agents_by_address( $carrier, $country, $postal_code, $city, $street ) {
		return $this->pickup_point_lookup->find_closest_by_address( $carrier, $country, $postal_code, $city, $street );
	}

	/**
	 * Read the selector value and its provenance from WooCommerce's request.
	 *
	 * @return array Posted selection fields and whether a selector was submitted.
	 */
	protected function posted_selection(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout and update_order_review requests.
		$posted = wc_clean( wp_unslash( $_POST ) );
		if ( isset( $posted['post_data'] ) && is_string( $posted['post_data'] ) ) {
			parse_str( $posted['post_data'], $posted );
			$posted = wc_clean( $posted );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$value = static function ( $key ) use ( $posted ): string {
			return isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? (string) $posted[ $key ] : '';
		};
		return array(
			'agent_no' => $value( 'ss_shipping_store_pickup' ),
			'origin'   => 'automatic' === $value( 'ss_shipping_pickup_origin' ) ? 'automatic' : 'explicit',
			'carrier'  => $value( 'ss_shipping_pickup_carrier' ),
			'country'  => strtoupper( $value( 'ss_shipping_pickup_country' ) ),
			'address'  => $value( 'ss_shipping_pickup_address' ),
			'present'  => array_key_exists( 'ss_shipping_store_pickup', $posted ),
		);
	}

	/**
	 * A posted choice must belong to the displayed carrier and country.
	 *
	 * @param array  $selection Posted selector data.
	 * @param string $carrier   Current carrier.
	 * @param string $country   Current destination country.
	 * @return bool Whether the choice was made in a compatible context.
	 */
	protected function selection_context_matches( array $selection, string $carrier, string $country ): bool {
		return ( '' === $selection['carrier'] || $carrier === $selection['carrier'] )
			&& ( '' === $selection['country'] || strtoupper( $country ) === $selection['country'] );
	}

	/**
	 * Preserve explicit choices across nearest-list refreshes.
	 *
	 * @param string $carrier Current carrier.
	 * @param string $country Current destination country.
	 * @return array Display selection, origin and any actionable error.
	 */
	protected function selection_for_display( string $carrier, string $country ): array {
		$posted    = $this->posted_selection();
		$selection = array(
			'point'    => null,
			'agent_no' => '',
			'origin'   => 'automatic',
			'carrier'  => $carrier,
			'country'  => $country,
			'error'    => null,
		);
		if ( $posted['present'] && 'explicit' === $posted['origin'] ) {
			if ( '' === $posted['agent_no'] ) {
				$this->pickup_point_lookup->clear_selection();
				return $selection;
			}
			$selection['agent_no'] = $posted['agent_no'];
			$selection['origin']   = 'explicit';
			try {
				if ( ! $this->selection_context_matches( $posted, $carrier, $country ) ) {
					$selection['carrier'] = $posted['carrier'];
					$selection['country'] = $posted['country'];
					$this->pickup_point_lookup->clear_selection();
					throw new Exception( esc_html( $this->selection_error_message() ) );
				}
				$selection['point'] = $this->pickup_point_lookup->select( $carrier, $country, $posted['agent_no'], true );
			} catch ( Exception $e ) {
				$selection['error'] = $this->selection_error_message();
			}
			return $selection;
		}

		$selection['point'] = $this->pickup_point_lookup->get_selected( $carrier, $country, true );
		if ( $selection['point'] ) {
			$selection['agent_no'] = $selection['point']->get_agent_no();
			$selection['origin']   = 'explicit';
		} else {
			$this->pickup_point_lookup->clear_selection();
		}
		return $selection;
	}

	/**
	 * Resolve the selected Smart Send rate from WooCommerce's calculated packages.
	 *
	 * @return Method_Code|null Current agent service, if any.
	 */
	protected function chosen_agent_method_code(): ?Method_Code {
		if ( null === WC()->session || null === WC()->shipping() ) {
			return null;
		}
		$chosen  = WC()->session->get( 'chosen_shipping_methods', array() );
		$rate_id = is_array( $chosen ) ? current( $chosen ) : false;
		foreach ( WC()->shipping()->get_packages() as $package ) {
			if ( ! isset( $package['rates'][ $rate_id ] ) ) {
				continue;
			}
			$rate = $package['rates'][ $rate_id ];
			$meta = $rate->get_meta_data();
			if ( SS_SHIPPING_METHOD_ID !== $rate->get_method_id() || empty( $meta['smart_send_shipping_method'] ) ) {
				return null;
			}
			$method = new Method_Code( $meta['smart_send_shipping_method'] );
			return $this->checkout_options->show_pickup_points( $method ) ? $method : null;
		}
		return null;
	}

	/**
	 * Message for an explicit choice that cannot be verified.
	 *
	 * @return string Translated customer-facing instruction.
	 */
	protected function selection_error_message(): string {
		return __( 'The selected pickup point could not be verified for this shipping method and country. Please select a pickup point again.', 'smart-send-logistics' );
	}

	/**
	 * Identify the address an automatic default was displayed for.
	 *
	 * @param array $address Country, postcode, city and street.
	 * @return string Deterministic address context.
	 */
	protected function address_context( array $address ): string {
		$address    = array_map( 'strval', $address );
		$address[0] = strtoupper( $address[0] );
		$address[1] = wc_format_postcode( $address[1], $address[0] );
		return hash( 'sha256', wp_json_encode( $address ) );
	}

	/**
	 * Resolve a submitted selection, allowing an empty choice only after
	 * an exact server-side address search offered no points.
	 *
	 * @param Method_Code $method  Current shipping service.
	 * @param array       $address Country, postcode, city and street.
	 * @return Pickup_Point|null Verified selection, or the nearest-point fallback.
	 * @throws Exception When an explicit choice or required selection is invalid.
	 */
	protected function resolve_posted_selection( Method_Code $method, array $address ): ?Pickup_Point {
		list( $country, $postcode, $city, $street ) = $address;
		$posted                                     = $this->posted_selection();
		if ( '' === $posted['agent_no'] ) {
			if ( null === $this->pickup_point_lookup->get_selected( $method->carrier(), $country, true )
				&& $this->pickup_point_lookup->no_pickup_points_for_address( $method->carrier(), $country, $postcode, $city, $street ) ) {
				return null;
			}
			throw new Exception( esc_html__( 'A pickup point must be selected.', 'smart-send-logistics' ) );
		}
		if ( ! $this->selection_context_matches( $posted, $method->carrier(), $country )
			|| ( 'automatic' === $posted['origin'] && $posted['address'] !== $this->address_context( $address ) ) ) {
			throw new Exception( esc_html( $this->selection_error_message() ) );
		}
		try {
			return $this->pickup_point_lookup->select( $method->carrier(), $country, $posted['agent_no'], 'explicit' === $posted['origin'] );
		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception is diagnostic data; the displayed message is escaped above.
			throw new Exception( esc_html( $this->selection_error_message() ), 0, $e );
		}
	}

	/**
	 * Validate after WooCommerce has updated the customer and shipping rates.
	 *
	 * @param array    $data   WooCommerce's normalized checkout fields.
	 * @param WP_Error $errors Checkout errors to append to.
	 * @return void
	 */
	public function validate_agent_selected( array $data, WP_Error $errors ) {
		$method = $this->chosen_agent_method_code();
		if ( null === $method ) {
			$this->pickup_point_lookup->clear_selection();
			return;
		}
		$customer = WC()->customer;
		$address  = array(
			(string) ( $data['shipping_country'] ?? $customer->get_shipping_country() ),
			(string) ( $data['shipping_postcode'] ?? $customer->get_shipping_postcode() ),
			(string) ( $data['shipping_city'] ?? $customer->get_shipping_city() ),
			(string) ( $data['shipping_address_1'] ?? $customer->get_shipping_address_1() ),
		);
		try {
			$this->resolve_posted_selection( $method, $address );
		} catch ( Exception $e ) {
			$errors->add( 'ss_shipping_pickup_point', $e->getMessage(), array( 'id' => 'ss_shipping_store_pickup' ) );
		}
	}

	/**
	 * Stage the verified selection on WooCommerce's not-yet-saved order.
	 *
	 * @param WC_Order $order  Order WooCommerce is creating.
	 * @param array    $posted Normalized checkout fields.
	 * @return void
	 * @throws Exception When the chosen point cannot be verified before saving.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $posted belongs to WooCommerce's checkout_create_order hook.
	public function process_ss_pickup_points( WC_Order $order, $posted ) {
		$method = null;
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if ( SS_SHIPPING_METHOD_ID === $shipping_item->get_method_id() ) {
				$candidate = new Method_Code( (string) $shipping_item->get_meta( 'smart_send_shipping_method', true ) );
				if ( $this->checkout_options->show_pickup_points( $candidate ) ) {
					$method = $candidate;
					break;
				}
			}
		}
		if ( null === $method ) {
			// A resumed unpaid order may still hold the previous checkout's choice.
			if ( $order->get_meta( Order_Meta::META_AGENT, true ) || $order->get_meta( Order_Meta::META_AGENT_NO, true ) ) {
				$details = new Delivery_Details();
				$details->clear_pickup_point();
				$this->order_meta->write( $order, $details, false );
			}
			return;
		}
		$point = $this->resolve_posted_selection(
			$method,
			array( $order->get_shipping_country(), $order->get_shipping_postcode(), $order->get_shipping_city(), $order->get_shipping_address_1() )
		);
		if ( null === $point ) {
			// A resumed unpaid order must not retain its earlier destination
			// when this checkout uses the verified nearest-point fallback.
			$details = new Delivery_Details();
			$details->clear_pickup_point();
			$this->order_meta->write( $order, $details, false );
			return;
		}
		$details = new Delivery_Details();
		$details->set_pickup_point( $point );
		$this->order_meta->write( $order, $details, false );
		Logger::info(
			'Pickup point selected at checkout',
			array(
				'order_id' => $order->get_id(),
				'agent_no' => $point->get_agent_no(),
			)
		);
	}

	/**
	 * Display the Smart Sent Pickup Point on Thank You order details
	 */
	public function display_ss_shipping_agent( $order ) {

		$order_id             = $this->get_order_id( $order );
		$ordered_pickup_point = $this->order_meta->read( $order_id )->get_pickup_point();

		if ( null !== $ordered_pickup_point && $ordered_pickup_point->get_agent_no() ) {

			$formatted_address = $this->pickup_point_formatter->format( $ordered_pickup_point, -1 );
			// Display in block instead of one line
			$formatted_address = str_replace( ',', '<br/>', $formatted_address );

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour: the formatted pickup point address deliberately carries <br/> markup; escaping is a behaviour change out of scope for the #43 move.
			echo '<h2>' . __( 'Pickup Point', 'smart-send-logistics' ) . '</h2>'
				. '<address>' . $formatted_address . '</address>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	protected function get_order_id( $order ) {
		return $order->get_id();
	}
}
