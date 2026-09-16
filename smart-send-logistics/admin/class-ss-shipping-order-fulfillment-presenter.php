<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

/**
 * Smart Send order fulfillment presenter.
 *
 * Builds the one STATE object the order screen's "Smart Send"
 * meta box renders from (#182): the same array is inlined at first render
 * (window.smartSendOrderFulfillment), returned by the GET fulfillment
 * route and sent back after every POST, so the server-rendered form and
 * the client (PR 3's React app) share one shape. It also renders that
 * state as the escaped server-side form, shapes the REST response of a
 * fulfillment run (SS_Shipping_Fulfillment_Result::to_array() plus the
 * per-field mapping and the rendered order note) and is the ONE place on
 * the admin side that knows the Smart Send API v1 field names
 * (map_api_field()).
 *
 * @package  SS_Shipping_Order_Fulfillment_Presenter
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Fulfillment_Presenter' ) ) :

	class SS_Shipping_Order_Fulfillment_Presenter {

		/**
		 * The REST namespace of the fulfillment routes (mirrors
		 * SS_Shipping_Fulfillment_Rest_Controller::REST_NAMESPACE; the
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
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Shipping method resolver.
		 *
		 * @var SS_Shipping_Method_Resolver
		 */
		protected SS_Shipping_Method_Resolver $method_resolver;

		/**
		 * Booked shipment id accessor.
		 *
		 * @var SS_Shipping_Shipment_Ids
		 */
		protected SS_Shipping_Shipment_Ids $shipment_ids;

		/**
		 * Pickup point display formatter.
		 *
		 * @var SS_Shipping_Pickup_Point_Formatter
		 */
		protected SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter;

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * The translated method catalogue, built on first use (its
		 * constructor translates, which must not happen before init).
		 *
		 * @var SS_Shipping_Method_Catalog|null
		 */
		protected ?SS_Shipping_Method_Catalog $catalog = null;

		/**
		 * API client factory - used for its host resolution only (the
		 * smart_send_api_endpoint filter), which is where the link to a
		 * shipment in the Smart Send app comes from.
		 *
		 * @var SS_Shipping_Api_Factory
		 */
		protected SS_Shipping_Api_Factory $api_factory;

		/**
		 * @param SS_Shipping_Order_Meta             $order_meta             Order meta repository.
		 * @param SS_Shipping_Method_Resolver        $method_resolver        Shipping method resolver.
		 * @param SS_Shipping_Shipment_Ids           $shipment_ids           Booked shipment id accessor.
		 * @param SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter Pickup point display formatter.
		 * @param SS_Shipping_Settings|null          $settings               Typed plugin settings reader (stateless; a fresh default is safe).
		 * @param SS_Shipping_Api_Factory|null       $api_factory            API client factory (stateless; a fresh default is safe).
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Method_Resolver $method_resolver, SS_Shipping_Shipment_Ids $shipment_ids, SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter, ?SS_Shipping_Settings $settings = null, ?SS_Shipping_Api_Factory $api_factory = null ) {
			$this->order_meta             = $order_meta;
			$this->method_resolver        = $method_resolver;
			$this->shipment_ids           = $shipment_ids;
			$this->pickup_point_formatter = $pickup_point_formatter;
			$this->settings               = null === $settings ? new SS_Shipping_Settings() : $settings;
			$this->api_factory            = null === $api_factory ? new SS_Shipping_Api_Factory( $this->settings ) : $api_factory;
		}

		/**
		 * The meta box state of an order (section 3.3 of #182):
		 *
		 *   array(
		 *     'order_id'          => int,
		 *     'connected'         => bool,            // an API token is configured, or demo mode is on
		 *     'demo_mode'         => bool,
		 *     'screen'            => 'hpos'|'legacy',
		 *     'order'             => array( 'weight_kg' => float, 'shipping_country' => string,
		 *                                   'units' => array( array( 'id' => int, 'name' => string, 'unit_weight' => float ), ... ) ), // one row per unit
		 *     'delivery_details'  => array( 'shipping_method' => string|null, 'pickup_point' => array|null (to_array() + 'display_html'),
		 *                                   'parcel_plan' => array|null (to_array()), 'addons' => array ),
		 *     'methods'           => array( 'outbound' => array( array( 'carrier' => string, 'options' => array( array( 'code', 'name' ), ... ) ), ... ), 'return' => ... ),
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
			} catch ( SS_Shipping_Booking_Exception $e ) {
				$return_method = '';
			}

			$pickup_point = $details->get_pickup_point();

			$shipping_items = array();
			foreach ( $order->get_shipping_methods() as $method ) {
				$shipping_items[] = $method->get_method_id() . ':' . $method->get_instance_id();
			}

			return array(
				'order_id'          => $order_id,
				'connected'         => $this->is_connected(),
				'demo_mode'         => $this->settings->demo_mode(),
				'screen'            => $this->screen(),
				'order'             => array(
					'weight_kg'        => round( array_sum( wp_list_pluck( $units, 'unit_weight' ) ), 2 ),
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
					'outbound' => $this->method_groups( $this->catalog()->get_shipping_methods() ),
					'return'   => $this->method_groups( $this->catalog()->get_return_shipping_methods() ),
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
					'settings' => admin_url( 'admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping' ),
					'rest'     => '/' . self::REST_NAMESPACE . '/orders/' . $order_id . '/fulfillment',
				),
			);
		}

		/**
		 * A pickup point as the state carries it: its to_array() form plus
		 * the meta box address block (display_html, already passed through
		 * wp_kses_post so it is safe to print).
		 *
		 * @param SS_Shipping_Pickup_Point $pickup_point The pickup point.
		 *
		 * @return array
		 */
		public function pickup_point_state( SS_Shipping_Pickup_Point $pickup_point ): array {
			$state                 = $pickup_point->to_array();
			$state['display_html'] = wp_kses_post( $this->pickup_point_formatter->format_admin_block( $pickup_point ) );

			return $state;
		}

		/**
		 * The REST response of a fulfillment run (section 3.2 of #182):
		 *
		 *   array( 'order_id' => int, 'flow' => 'outbound'|'return', 'success' => bool,
		 *          'shipments' => SS_Shipping_Fulfillment_Result::to_array() with 'order_note.html' rendered,
		 *                         the booked shipment enriched for display (shipment_response()) and
		 *                         'error.form_fields' (the API field errors mapped onto form fields) added,
		 *          'state' => state( $order ) after the run )
		 *
		 * @param SS_Shipping_Fulfillment_Result $result The completed run.
		 * @param WC_Order                       $order  The WooCommerce order.
		 * @param string|null                    $flow   The requested flow; derived from the first leg when omitted.
		 *
		 * @return array
		 */
		public function response( SS_Shipping_Fulfillment_Result $result, WC_Order $order, ?string $flow = null ): array {
			$shipments = $result->to_array();

			foreach ( $shipments as &$row ) {
				if ( 'fulfilled' === $row['status'] ) {
					$row['order_note']['html'] = null === $row['order_note']['id'] ? null : $this->render_order_note( (int) $row['order_note']['id'] );
					$row['shipment']           = $this->shipment_response( $row['shipment'] );
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
		 * @param array $shipment SS_Shipping_Booked_Shipment::to_array().
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
		 * @param array $parcel SS_Shipping_Booked_Parcel::to_array().
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
		 * API client talks to (SS_Shipping_Api_Factory::resolve_api_host(),
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
		 *   parcels.N.items.M.<field>  -> parcel_plan.specs[N].items[M].<field>
		 *   parcels.N / parcels        -> parcel_plan.specs[N] / parcel_plan
		 *   shipping_method, shipping_carrier -> shipping_method
		 *
		 * @param string $api_field The API field name.
		 *
		 * @return string|null
		 */
		public function map_api_field( string $api_field ): ?string {
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

				if ( preg_match( '/^items\.(\d+)(?:\.(.+))?$/', $m[2], $item ) ) {
					return $field . '.items[' . (int) $item[1] . ']' . ( isset( $item[2] ) ? '.' . $item[2] : '' );
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
		 * Render an order note as the list item WooCommerce's order-notes
		 * meta box renders, so the client can prepend it to ul.order_notes
		 * and WooCommerce's own delete handler keeps working. Uses
		 * WooCommerce's own view (includes/admin/meta-boxes/views/html-order-notes.php,
		 * the template WC_Meta_Box_Order_Notes renders the list with) for a
		 * one-note list and returns its <li>.
		 *
		 * @param integer $note_id The order note (comment) id.
		 *
		 * @return string The <li> markup, or '' when the note does not exist.
		 */
		public function render_order_note( int $note_id ): string {
			$note = wc_get_order_note( $note_id );

			if ( ! $note ) {
				return '';
			}

			$notes = array( $note ); // Read by the included view.

			ob_start();
			include WC()->plugin_path() . '/includes/admin/meta-boxes/views/html-order-notes.php';
			$html = (string) ob_get_clean();

			if ( preg_match( '/<li\b.*<\/li>/s', $html, $m ) ) {
				return trim( $m[0] );
			}

			return '';
		}

		/**
		 * Render the meta box form for a state (section 1.2 of #182). Every
		 * value is escaped on output; the markup carries data-ss-* attributes
		 * for stable selectors and semantic smart_send[...] field names.
		 *
		 * Layout (Option A): a stack of sections separated by dividers - the
		 * shipping section (callouts, then the shipping method / pickup
		 * point / return method rows as read-only values with an "Edit"
		 * link each), the parcels section collapsed to its summary line,
		 * the actions section (both buttons stacked) and the grey settings
		 * section (the return checkbox). The React app renders the same
		 * structure and class names (src/order-fulfillment/), so the box
		 * does not jump when it mounts; the Edit/Done toggling is the app's
		 * (component state, never persisted).
		 *
		 * Rendered as a <fieldset>, not a <form>: WooCommerce's order screen
		 * wraps every meta box in its own <form> (post.php's #post, the HPOS
		 * screen's #order) and the HTML parser drops a nested <form> start
		 * tag, which would leave no #smart-send-fulfillment element at all.
		 * Submission is JS-only via the REST controller (Decisions, #182):
		 * the React app mounts on the fieldset, hydrates from the inlined
		 * state and replaces this markup - what is rendered here is the
		 * first paint and the fallback while the bundle loads.
		 *
		 * @param array $state The state (see state()).
		 *
		 * @return string HTML
		 */
		public function render_form( array $state ): string {
			$box_state = $this->box_state( $state );
			// The not-connected state shows the rows read-only, without Edit links.
			$editable = self::STATE_NOT_CONNECTED !== $box_state;

			$html = '<div class="smart-send-fulfillment">';
			// Always rendered disabled: submission is JS-only, and the app
			// enables the fieldset once it has mounted (and keeps it disabled
			// while not connected / submitting), so nothing is clickable
			// before the app took over - Playwright's actionability wait
			// doubles as the hydration gate.
			$html .= '<fieldset id="smart-send-fulfillment" class="smart-send-fulfillment__form" data-ss-form="fulfillment" data-ss-state="' . esc_attr( $box_state ) . '" data-ss-order-id="' . esc_attr( (string) $state['order_id'] ) . '" disabled>';

			// Demo mode: a warning callout at the top of the box, in every state.
			$callouts = $state['demo_mode'] ? $this->render_notice( 'warning', 'demo_mode', esc_html__( 'Demo mode active', 'smart-send-logistics' ) ) : '';

			if ( self::STATE_NOT_CONNECTED === $box_state ) {
				$callouts .= $this->render_notice(
					'warning',
					'not_connected',
					esc_html__( 'Smart Send is not connected. Enter your API token in the settings to create labels.', 'smart-send-logistics' ),
					'<a class="button" href="' . esc_url( $state['urls']['settings'] ) . '" data-ss-action="open-settings">' . esc_html__( 'Open settings', 'smart-send-logistics' ) . '</a>'
				);
			} elseif ( self::STATE_NO_METHOD === $box_state ) {
				$callouts .= $this->render_notice(
					'info',
					'no_method',
					esc_html__( 'Shipping method is not from the Smart Send plugin.', 'smart-send-logistics' )
				);
			}

			// The form never closes (#182 review, 2026-09-16): booking a
			// label adds a timeline entry and, in the client, the green
			// result box of the run - the rows, the parcels and the actions
			// stay exactly as they were.
			$html .= $this->render_details_form( $state, $callouts, $editable );

			$html .= '</fieldset></div>';

			return $html;
		}

		/**
		 * Which of the section 1.2 states a state array is in.
		 *
		 * @param array $state The state.
		 *
		 * @return string One of the STATE_* constants.
		 */
		public function box_state( array $state ): string {
			if ( ! $state['connected'] ) {
				return self::STATE_NOT_CONNECTED;
			}

			if ( null !== $state['outbound_shipment'] || null !== $state['return_shipment'] ) {
				return self::STATE_BOOKED;
			}

			if ( null === $state['delivery_details']['shipping_method'] ) {
				return self::STATE_NO_METHOD;
			}

			return self::STATE_READY;
		}

		/**
		 * A section of the box: 12px padding, a divider above every one but
		 * the first.
		 *
		 * @param string $key      The data-ss-section selector value.
		 * @param string $content  The (already escaped) content.
		 * @param string $modifier Optional BEM modifier (e.g. 'settings').
		 *
		 * @return string HTML
		 */
		protected function render_section( string $key, string $content, string $modifier = '' ): string {
			$class = 'smart-send-fulfillment__section' . ( '' === $modifier ? '' : ' smart-send-fulfillment__section--' . $modifier );

			return '<div class="' . esc_attr( $class ) . '" data-ss-section="' . esc_attr( $key ) . '">' . $content . '</div>';
		}

		/**
		 * The box, in every state (the form never closes - #182 review,
		 * 2026-09-16), as its sections, top to bottom: the shipping section
		 * (callouts, then the shipping method, pickup point and return
		 * method rows), the parcels section, the actions (in the client the
		 * green result boxes of the run just made sit above the two
		 * buttons - they live in component memory only and are never
		 * rendered here), the "Booked shipments" timeline of every label
		 * this order has, and the grey settings section with the return
		 * checkbox.
		 *
		 * @param array   $state    The state.
		 * @param string  $callouts The (already escaped) notices for the first section.
		 * @param boolean $editable Whether the rows offer an Edit link.
		 *
		 * @return string HTML
		 */
		protected function render_details_form( array $state, string $callouts, bool $editable ): string {
			$details  = $callouts . $this->render_details_rows( $state, $editable );
			$details .= $this->render_return_method_row( $state, $editable );

			$html  = $this->render_section( 'details', $details );
			$html .= $this->render_parcels_section( $state, $editable );

			$actions  = $this->render_button( 'outbound', $state, true );
			$actions .= $this->render_button( 'return', $state, false );
			$html    .= $this->render_section( 'actions', '<div class="smart-send-fulfillment__actions">' . $actions . '</div>', 'actions' );

			$html .= $this->render_timeline( $state );
			$html .= $this->render_section( 'settings', $this->render_return_toggle( $state ), 'settings' );

			return $html;
		}

		/**
		 * The "Booked shipments" section: a light timeline of every label
		 * this order has, newest first, each entry a link to the shipment in
		 * the Smart Send app with the time it was booked under it. Rendered
		 * from what is persisted on the order (state['timeline']), which is
		 * why it is not detailed - the documents and tracking of the run
		 * just made are the green result boxes in the actions section.
		 *
		 * Nothing booked yet: no section at all.
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_timeline( array $state ): string {
			$entries = isset( $state['timeline'] ) ? $state['timeline'] : array();

			if ( array() === $entries ) {
				return '';
			}

			$rows = '';
			foreach ( $entries as $entry ) {
				$title = 'return' === $entry['direction']
					? esc_html__( 'Return shipment', 'smart-send-logistics' )
					: esc_html__( 'Shipment', 'smart-send-logistics' );

				$rows .= '<a class="smart-send-fulfillment__timeline-row" href="' . esc_url( $entry['app_url'] ) . '" target="_blank" rel="noopener noreferrer" data-ss-timeline="' . esc_attr( $entry['direction'] ) . '" data-ss-shipment-id="' . esc_attr( $entry['shipment_id'] ) . '">';
				$rows .= '<span class="smart-send-fulfillment__timeline-title">' . $title . $this->render_external_icon() . '</span>';

				// An order booked before the booked-labels list existed
				// carries only the frozen shipment ids: the entry is a link
				// with no time under it.
				if ( ! empty( $entry['booked_at_display'] ) ) {
					$rows .= '<span class="smart-send-fulfillment__timeline-when">' . esc_html( $entry['booked_at_display'] ) . '</span>';
				}

				$rows .= '</a>';
			}

			$content  = '<div class="smart-send-fulfillment__timeline-label">' . esc_html__( 'Booked shipments', 'smart-send-logistics' ) . '</div>';
			$content .= '<div class="smart-send-fulfillment__timeline">' . $rows . '</div>';

			return $this->render_section( 'timeline', $content, 'timeline' );
		}

		/**
		 * The small external-link icon of a timeline entry / a green result
		 * box header (WordPress admin has no Dashicon at this size that
		 * reads as "opens in the Smart Send app").
		 *
		 * @return string HTML
		 */
		protected function render_external_icon(): string {
			return '<svg class="smart-send-fulfillment__external" width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
				. '<path d="M4.5 2H2.5v7.5H10V7.5"></path><path d="M7 2h3v3"></path><path d="M10 2 5.5 6.5"></path>'
				. '</svg>';
		}

		/**
		 * The shipping method and pickup point rows.
		 *
		 * @param array   $state    The state.
		 * @param boolean $editable Whether the rows offer an Edit link.
		 *
		 * @return string HTML
		 */
		protected function render_details_rows( array $state, bool $editable ): string {
			$method = $state['delivery_details']['shipping_method'];

			if ( null === $method ) {
				$value = $this->render_none( 'shipping_method' );
			} else {
				$name  = $this->method_name( $method );
				$value = '<span data-ss-value="shipping_method">' . esc_html( '' === $name ? $method : $name ) . '</span>';
			}

			$content = '<div class="smart-send-fulfillment__value">' . $value . '</div>';

			if ( $state['debug']['enabled'] ) {
				foreach ( $state['debug']['shipping_items'] as $shipping_item ) {
					$content .= '<pre data-ss-debug="shipping_item">' . sprintf(
						/* translators: %s: shipping method id and instance id. */
						esc_html__( 'Debug id: %s', 'smart-send-logistics' ),
						esc_html( $shipping_item )
					) . '</pre>';
				}
			}

			$html = $this->render_row(
				'shipping_method',
				esc_html__( 'Shipping method', 'smart-send-logistics' ),
				__( 'Shipping method used for booking of outgoing shipment', 'smart-send-logistics' ),
				$editable ? 'edit-method' : '',
				$content
			);

			// The pickup point row is rendered only for an agent-type method;
			// the client toggles it when the method changes.
			if ( null !== $method && false !== stripos( ( new SS_Shipping_Method_Code( $method ) )->type(), 'agent' ) ) {
				$html .= $this->render_pickup_point_row( $state['delivery_details']['pickup_point'], $editable );
			}

			return $html;
		}

		/**
		 * A detail row: the label line (label, optional help tip, the
		 * right-aligned control - an Edit link for an action, or custom
		 * markup) over the row's content.
		 *
		 * @param string $section The data-ss-section selector value.
		 * @param string $label   The (already escaped) label.
		 * @param string $help    The help text (unescaped; '' for no tip).
		 * @param string $action  The Edit link's data-ss-action ('' for no link).
		 * @param string $content The (already escaped) row content.
		 * @param string $head    Optional (already escaped) extra label-line markup, before the help tip.
		 * @param string $extra_class Optional extra class on the row (the parcels row is a section of its own).
		 *
		 * @return string HTML
		 */
		protected function render_row( string $section, string $label, string $help, string $action, string $content, string $head = '', string $extra_class = '' ): string {
			$html  = '<div class="' . esc_attr( trim( $extra_class . ' smart-send-fulfillment__row' ) ) . '" data-ss-section="' . esc_attr( $section ) . '">';
			$html .= '<div class="smart-send-fulfillment__row-head">';
			$html .= '<span class="smart-send-fulfillment__label">' . $label . '</span>';
			$html .= $head;
			if ( '' !== $help ) {
				$html .= $this->render_help_tip( $help );
			}
			if ( '' !== $action ) {
				$html .= '<button type="button" class="button-link smart-send-fulfillment__edit" data-ss-action="' . esc_attr( $action ) . '">' . esc_html__( 'Edit', 'smart-send-logistics' ) . '</button>';
			}
			$html .= '</div>';
			$html .= $content;
			$html .= '</div>';

			return $html;
		}

		/**
		 * The grey "None" of a missing value.
		 *
		 * @param string $field The data-ss-value selector value.
		 *
		 * @return string HTML
		 */
		protected function render_none( string $field ): string {
			return '<span class="smart-send-fulfillment__none" data-ss-value="' . esc_attr( $field ) . '">' . esc_html__( 'None', 'smart-send-logistics' ) . '</span>';
		}

		/**
		 * WooCommerce's own help tip: the markup WooCommerce's admin stylesheet
		 * renders as the question mark icon, with the text as `data-tip` (what
		 * WooCommerce's tipTip binding reads) and as `aria-label` (screen
		 * readers, and what the tests assert) - the same markup the app
		 * renders (HelpTip in src/order-fulfillment/Row.js).
		 *
		 * @param string $text The help text (unescaped).
		 *
		 * @return string HTML
		 */
		protected function render_help_tip( string $text ): string {
			return '<span class="woocommerce-help-tip" tabindex="0" aria-label="' . esc_attr( $text ) . '" data-tip="' . esc_attr( $text ) . '" data-ss-help=""></span>';
		}

		/**
		 * The 16px map pin in front of a pickup point.
		 *
		 * @return string HTML
		 */
		protected function render_pin_icon(): string {
			return '<svg class="smart-send-fulfillment__pin" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
				. '<path d="M8 14.5s-4.5-4.3-4.5-8a4.5 4.5 0 0 1 9 0c0 3.7-4.5 8-4.5 8z"></path><circle cx="8" cy="6.5" r="1.6"></circle>'
				. '</svg>';
		}

		/**
		 * The pickup point row: the stored point as "#agent no company" over
		 * its address in grey (the pin in front), or "None".
		 *
		 * @param array|null $pickup_point The state's pickup point, or null.
		 * @param boolean    $editable     Whether the row offers an Edit link.
		 *
		 * @return string HTML
		 */
		protected function render_pickup_point_row( ?array $pickup_point, bool $editable ): string {
			if ( null === $pickup_point ) {
				$content = '<div class="smart-send-fulfillment__value">' . $this->render_none( 'pickup_point' ) . '</div>';
			} else {
				$content = $this->render_pickup_point( $pickup_point );
			}

			return $this->render_row( 'pickup_point', esc_html__( 'Pickup point', 'smart-send-logistics' ), '', $editable ? 'edit-pickup-point' : '', $content );
		}

		/**
		 * A pickup point as the box displays it (mirrors the app's
		 * PickupPointValue): pin, "#agent_no company", the street and
		 * "postal code city" below.
		 *
		 * @param array $pickup_point The state's pickup point (to_array() form).
		 *
		 * @return string HTML
		 */
		public function render_pickup_point( array $pickup_point ): string {
			$field = static function ( string $key ) use ( $pickup_point ): string {
				return isset( $pickup_point[ $key ] ) && null !== $pickup_point[ $key ] ? trim( (string) $pickup_point[ $key ] ) : '';
			};

			$lines = array_filter(
				array(
					$field( 'address_line1' ),
					trim( $field( 'postal_code' ) . ' ' . $field( 'city' ) ),
				)
			);

			$html  = '<div class="smart-send-fulfillment__pickup-point" data-ss-value="pickup_point">' . $this->render_pin_icon();
			$html .= '<div class="smart-send-fulfillment__pickup-point-text"><div>';
			$html .= '<span data-ss-value="pickup_point.agent_no">' . esc_html( '#' . $field( 'agent_no' ) ) . '</span>';
			$html .= '' === $field( 'company' ) ? '' : ' ' . esc_html( $field( 'company' ) );
			$html .= '</div>';

			if ( array() !== $lines ) {
				$html .= '<div class="smart-send-fulfillment__address">';
				foreach ( $lines as $line ) {
					$html .= '<span>' . esc_html( $line ) . '</span>';
				}
				$html .= '</div>';
			}

			$html .= '</div></div>';

			return $html;
		}

		/**
		 * The parcels section, collapsed to its header line: "Parcels", the
		 * summary ("2 parcels · 3.00 kg" - a box's explicit weight wins over
		 * the sum of its units', mirroring the app's totalWeight()), the
		 * help tip and the Edit link. The editor itself is the app's.
		 *
		 * @param array   $state    The state.
		 * @param boolean $editable Whether the row offers an Edit link.
		 *
		 * @return string HTML
		 */
		protected function render_parcels_section( array $state, bool $editable ): string {
			$summary = $this->parcel_summary( $state );

			// The row is the section itself (as the app renders it).
			return $this->render_row(
				'parcel_plan',
				esc_html__( 'Parcels', 'smart-send-logistics' ),
				__( 'Move items between boxes with the arrows. Weight is calculated from the items unless you enter one.', 'smart-send-logistics' ),
				$editable ? 'edit-parcels' : '',
				'',
				'<span class="smart-send-fulfillment__summary" data-ss-value="parcel_plan.summary">' . esc_html( $summary ) . '</span>',
				'smart-send-fulfillment__section'
			);
		}

		/**
		 * The parcels summary of a state: the number of boxes of the stored
		 * plan (one without a plan) and their total weight - a box's
		 * explicit weight, else the sum of its units' weights; units the
		 * plan does not mention count towards the first box.
		 *
		 * @param array $state The state.
		 *
		 * @return string e.g. "2 parcels · 3.00 kg".
		 */
		public function parcel_summary( array $state ): string {
			$plan  = $state['delivery_details']['parcel_plan'];
			$specs = null === $plan || empty( $plan['specs'] ) ? array() : array_values( $plan['specs'] );
			$count = max( 1, count( $specs ) );

			$box_of_unit = $this->stored_box_indexes( $state );
			$computed    = array_fill( 0, $count, 0.0 );
			foreach ( $state['order']['units'] as $index => $unit ) {
				$box = isset( $box_of_unit[ $index ] ) ? $box_of_unit[ $index ] : 0;

				$computed[ $box ] += (float) $unit['unit_weight'];
			}

			$total = 0.0;
			for ( $box = 0; $box < $count; $box++ ) {
				$explicit = isset( $specs[ $box ]['weight'] ) && null !== $specs[ $box ]['weight'] && '' !== $specs[ $box ]['weight'] ? (float) $specs[ $box ]['weight'] : null;

				$total += null === $explicit ? $computed[ $box ] : $explicit;
			}

			return sprintf(
				/* translators: 1: number of parcels, 2: total weight in kg. */
				_n( '%1$d parcel · %2$s kg', '%1$d parcels · %2$s kg', $count, 'smart-send-logistics' ),
				$count,
				number_format( round( $total, 2 ), 2, '.', '' )
			);
		}

		/**
		 * The stored parcel split as the box (spec index) of every unit row
		 * (unit index => spec index), matched to the state's units by
		 * walking each spec's allocations - the same walk the app's
		 * boxesFromPlan() does; empty when no split is stored.
		 *
		 * @param array $state The state.
		 *
		 * @return array<int, int>
		 */
		protected function stored_box_indexes( array $state ): array {
			$plan = $state['delivery_details']['parcel_plan'];

			if ( null === $plan || empty( $plan['specs'] ) ) {
				return array();
			}

			// Units of each product id, in order, still unassigned.
			$remaining = array();
			foreach ( $state['order']['units'] as $index => $unit ) {
				$remaining[ (string) $unit['id'] ][] = $index;
			}

			$boxes = array();
			foreach ( array_values( $plan['specs'] ) as $position => $spec ) {
				foreach ( isset( $spec['items'] ) ? $spec['items'] : array() as $item ) {
					$id = (string) $item['id'];
					for ( $unit = 0; $unit < (int) $item['quantity']; $unit++ ) {
						if ( empty( $remaining[ $id ] ) ) {
							break;
						}
						$boxes[ array_shift( $remaining[ $id ] ) ] = (int) $position;
					}
				}
			}

			return $boxes;
		}

		/**
		 * The settings section's "Also create return label" checkbox,
		 * defaulting from the method's auto-generate-return-label setting,
		 * with a help tip next to its label (outside the label, so a click
		 * on the tip does not toggle the checkbox).
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_return_toggle( array $state ): string {
			$available = null !== $state['return']['method'];

			$html  = '<div class="smart-send-fulfillment__row smart-send-fulfillment__check-row" data-ss-section="return">';
			$html .= '<label class="smart-send-fulfillment__check">';
			$html .= '<input type="checkbox" name="smart_send[with_return]" value="1" data-ss-field="with_return" autocomplete="off"' . checked( $available && $state['return']['auto_default'], true, false ) . '>';
			$html .= '<span class="smart-send-fulfillment__check-text">' . esc_html__( 'Also create return label', 'smart-send-logistics' ) . '</span>';
			$html .= '</label>';
			$html .= $this->render_help_tip( __( 'When booking an outgoing label, then we will automatically also book a return label', 'smart-send-logistics' ) );
			$html .= '</div>';

			return $html;
		}

		/**
		 * The return method row: the configured return method - grey "None"
		 * for an order without one (state B, or a zone method without one) -
		 * with an Edit link, collapsed in every state; the select behind Edit
		 * is the app's. A return method chosen there serves both the combined
		 * outbound + return run and the return-only action.
		 *
		 * @param array   $state    The state.
		 * @param boolean $editable Whether the row offers an Edit link.
		 *
		 * @return string HTML
		 */
		protected function render_return_method_row( array $state, bool $editable ): string {
			$return_method = $state['return']['method'];

			if ( null === $return_method ) {
				$value = $this->render_none( 'return_method' );
			} else {
				$name  = $this->method_name( $return_method );
				$value = '<span data-ss-value="return_method">' . esc_html( '' === $name ? $return_method : $name ) . '</span>';
			}

			return $this->render_row(
				'return_method',
				esc_html__( 'Return method', 'smart-send-logistics' ),
				__( 'Shipping method used for booking of return shipments', 'smart-send-logistics' ),
				$editable ? 'edit-return-method' : '',
				'<div class="smart-send-fulfillment__value">' . $value . '</div>'
			);
		}

		/**
		 * A create-label button: the flow as its value and a data-ss-action
		 * selector. While the method the flow needs is missing (no stored
		 * shipping method / no configured return method) the button stays
		 * enabled and explains itself in its title; the app shows the same
		 * sentence as an error on click instead of sending a request.
		 *
		 * @param string  $flow    'outbound' or 'return'.
		 * @param array   $state   The state.
		 * @param boolean $primary Whether to style it as the primary action.
		 *
		 * @return string HTML
		 */
		protected function render_button( string $flow, array $state, bool $primary ): string {
			$is_return = 'return' === $flow;

			if ( $is_return ) {
				$text  = __( 'Create return label', 'smart-send-logistics' );
				$title = null === $state['return']['method'] ? __( 'Select a return shipping method first', 'smart-send-logistics' ) : '';
			} else {
				$text  = __( 'Create shipping label', 'smart-send-logistics' );
				$title = null === $state['delivery_details']['shipping_method'] ? __( 'Select a shipping method first', 'smart-send-logistics' ) : '';
			}

			return '<button type="button" class="button' . ( $primary ? ' button-primary' : '' ) . ' smart-send-fulfillment__action" name="smart_send[flow]" value="' . esc_attr( $flow ) . '" data-ss-action="' . ( $is_return ? 'create-return-label' : 'create-label' ) . '"' . ( '' === $title ? '' : ' title="' . esc_attr( $title ) . '"' ) . '>' . esc_html( $text ) . '</button>';
		}

		/**
		 * An inline admin notice.
		 *
		 * @param string $type    notice-* type (info, warning, error, success).
		 * @param string $key     The data-ss-notice selector value.
		 * @param string $message The (already escaped) message.
		 * @param string $extra   Optional (already escaped) trailing markup, e.g. a button.
		 *
		 * @return string HTML
		 */
		protected function render_notice( string $type, string $key, string $message, string $extra = '' ): string {
			return '<div class="notice notice-' . esc_attr( $type ) . ' inline smart-send-fulfillment__notice" data-ss-notice="' . esc_attr( $key ) . '"><p>' . $message . '</p>' . ( '' === $extra ? '' : '<p>' . $extra . '</p>' ) . '</div>';
		}

		/**
		 * The catalogue's carrier => methods map as a list of groups.
		 *
		 * @param array $catalog_methods SS_Shipping_Method_Catalog::get_shipping_methods() / get_return_shipping_methods().
		 *
		 * @return array
		 */
		protected function method_groups( array $catalog_methods ): array {
			$groups = array();

			foreach ( $catalog_methods as $carrier => $methods ) {
				if ( ! is_array( $methods ) ) {
					continue; // The '- Select Method -' placeholder.
				}

				$options = array();
				foreach ( $methods as $code => $name ) {
					$options[] = array(
						'code' => (string) $code,
						'name' => (string) $name,
					);
				}

				$groups[] = array(
					'carrier' => (string) $carrier,
					'options' => $options,
				);
			}

			return $groups;
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
		 * SS_Shipping_Shipment_Ids keeps (booking outcomes are its domain,
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
		 * variation-aware id the parcel plan allocates by, the order line
		 * name and the unit weight in kg. A product that no longer exists
		 * yields a 0 weight rather than breaking the order screen.
		 *
		 * @param WC_Order $order The order.
		 *
		 * @return array[]
		 */
		public function order_units( WC_Order $order ): array {
			$units = array();

			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$product    = wc_get_product( $product_id );
				$weight     = $product ? round( (float) wc_get_weight( $product->get_weight(), 'kg' ), 2 ) : 0.0;

				$quantity = (int) $item->get_quantity();

				for ( $unit = 0; $unit < $quantity; $unit++ ) {
					$units[] = array(
						'id'          => (int) $product_id,
						'name'        => (string) $item->get_name(),
						'sku'         => $product ? (string) $product->get_sku() : '',
						'unit_weight' => $weight,
					);
				}
			}

			return $units;
		}

		/**
		 * Whether the plugin can book: an API token is configured, or demo
		 * mode is on (which books nothing live).
		 *
		 * @return boolean
		 */
		public function is_connected(): bool {
			return null !== $this->settings->api_token() || $this->settings->demo_mode();
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
		 * @return SS_Shipping_Method_Catalog
		 */
		protected function catalog(): SS_Shipping_Method_Catalog {
			if ( null === $this->catalog ) {
				$this->catalog = new SS_Shipping_Method_Catalog();
			}

			return $this->catalog;
		}
	}

endif;
