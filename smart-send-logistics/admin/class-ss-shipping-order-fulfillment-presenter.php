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
		 * Highest box number the per-unit box selects offer (the frozen
		 * "Split into parcels" meta format uses box numbers 1-9).
		 */
		const MAX_BOXES = 9;

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
		 * @param SS_Shipping_Order_Meta             $order_meta             Order meta repository.
		 * @param SS_Shipping_Method_Resolver        $method_resolver        Shipping method resolver.
		 * @param SS_Shipping_Shipment_Ids           $shipment_ids           Booked shipment id accessor.
		 * @param SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter Pickup point display formatter.
		 * @param SS_Shipping_Settings|null          $settings               Typed plugin settings reader (stateless; a fresh default is safe).
		 */
		public function __construct( SS_Shipping_Order_Meta $order_meta, SS_Shipping_Method_Resolver $method_resolver, SS_Shipping_Shipment_Ids $shipment_ids, SS_Shipping_Pickup_Point_Formatter $pickup_point_formatter, ?SS_Shipping_Settings $settings = null ) {
			$this->order_meta             = $order_meta;
			$this->method_resolver        = $method_resolver;
			$this->shipment_ids           = $shipment_ids;
			$this->pickup_point_formatter = $pickup_point_formatter;
			$this->settings               = null === $settings ? new SS_Shipping_Settings() : $settings;
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
		 *     'outbound_shipment' => array( 'shipment_id' => string, 'legacy' => true )|null,  // nothing but the id is persisted (Decisions, #182)
		 *     'return_shipment'   => array( 'shipment_id' => string, 'legacy' => true )|null,
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
		 *          'shipments' => SS_Shipping_Fulfillment_Result::to_array() with 'order_note.html' rendered
		 *                         and 'error.form_fields' (the API field errors mapped onto form fields) added,
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
		 * Rendered as a <fieldset>, not a <form>: WooCommerce's order screen
		 * wraps every meta box in its own <form> (post.php's #post, the HPOS
		 * screen's #order) and the HTML parser drops a nested <form> start
		 * tag, which would leave no #smart-send-fulfillment element at all.
		 * Submission is JS-only via the REST controller (Decisions, #182):
		 * the React app (src/order-fulfillment/) mounts on the fieldset,
		 * hydrates from the inlined state and replaces this markup - what is
		 * rendered here is the first paint and the fallback while the
		 * bundle loads.
		 *
		 * @param array $state The state (see state()).
		 *
		 * @return string HTML
		 */
		public function render_form( array $state ): string {
			$box_state = $this->box_state( $state );

			$html = '<div class="smart-send-fulfillment">';
			// Always rendered disabled: submission is JS-only, and the app
			// enables the fieldset once it has mounted (and keeps it disabled
			// while not connected / submitting), so nothing is clickable
			// before the app took over - Playwright's actionability wait
			// doubles as the hydration gate.
			$html .= '<fieldset id="smart-send-fulfillment" class="smart-send-fulfillment__form" data-ss-form="fulfillment" data-ss-state="' . esc_attr( $box_state ) . '" data-ss-order-id="' . esc_attr( (string) $state['order_id'] ) . '" disabled>';

			if ( self::STATE_NOT_CONNECTED === $box_state ) {
				$html .= $this->render_notice(
					'warning',
					'not_connected',
					esc_html__( 'Smart Send is not connected. Enter your API token in the settings to create labels.', 'smart-send-logistics' ),
					'<a class="button" href="' . esc_url( $state['urls']['settings'] ) . '" data-ss-action="open-settings">' . esc_html__( 'Open settings', 'smart-send-logistics' ) . '</a>'
				);
				$html .= '</fieldset></div>';

				return $html;
			}

			if ( self::STATE_BOOKED === $box_state ) {
				$html .= $this->render_booked( $state );
				$html .= '</fieldset></div>';

				return $html;
			}

			if ( self::STATE_NO_METHOD === $box_state ) {
				$html .= $this->render_notice(
					'info',
					'no_method',
					esc_html__( 'This order has no Smart Send shipping method. Choose the method to ship it with.', 'smart-send-logistics' )
				);
			}

			$html .= $this->render_details_rows( $state );
			$html .= $this->render_return_row( $state );
			$html .= '<p class="smart-send-fulfillment__actions">';
			$html .= $this->render_button( 'outbound', $state, true );
			$html .= ' ' . $this->render_button( 'return', $state, false );
			$html .= '</p>';

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
		 * The shipping method, pickup point and parcels rows of the
		 * not-yet-booked form (states B and C; reused inside the "Book
		 * again" disclosure of state E).
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_details_rows( array $state ): string {
			$method = $state['delivery_details']['shipping_method'];

			$html  = '<p class="form-field smart-send-fulfillment__row" data-ss-section="shipping_method">';
			$html .= '<label for="smart-send-shipping-method"><strong>' . esc_html__( 'Shipping method', 'smart-send-logistics' ) . '</strong></label><br>';
			$html .= '<select id="smart-send-shipping-method" name="smart_send[delivery_details][shipping_method]" data-ss-field="shipping_method" autocomplete="off">';
			$html .= '<option value=""' . selected( null, $method, false ) . '>' . esc_html__( 'Select a method…', 'smart-send-logistics' ) . '</option>';
			$html .= $this->render_method_options( $state['methods']['outbound'], $method );
			$html .= '</select>';
			$html .= '</p>';

			if ( $state['debug']['enabled'] ) {
				foreach ( $state['debug']['shipping_items'] as $shipping_item ) {
					$html .= '<pre data-ss-debug="shipping_item">' . sprintf(
						/* translators: %s: shipping method id and instance id. */
						esc_html__( 'Debug id: %s', 'smart-send-logistics' ),
						esc_html( $shipping_item )
					) . '</pre>';
				}
			}

			$html .= '<p class="smart-send-fulfillment__row" data-ss-section="weight">' . sprintf(
				/* translators: %0.2f: total order weight in kg. */
				esc_html__( 'Weight: %0.2f kg', 'smart-send-logistics' ),
				floatval( $state['order']['weight_kg'] )
			) . '</p>';

			// The pickup point row is rendered only for an agent-type method;
			// the client toggles it when the method changes.
			if ( null !== $method && false !== stripos( ( new SS_Shipping_Method_Code( $method ) )->type(), 'agent' ) ) {
				$html .= $this->render_pickup_point_row( $state['delivery_details']['pickup_point'] );
			}

			$html .= $this->render_parcels_row( $state );

			return $html;
		}

		/**
		 * The pickup point row: the stored point (agent number + address
		 * block) and the agent number input that overrides it.
		 *
		 * @param array|null $pickup_point The state's pickup point, or null.
		 *
		 * @return string HTML
		 */
		protected function render_pickup_point_row( ?array $pickup_point ): string {
			$agent_no = null === $pickup_point || ! isset( $pickup_point['agent_no'] ) ? '' : (string) $pickup_point['agent_no'];

			$html  = '<div class="smart-send-fulfillment__row" data-ss-section="pickup_point">';
			$html .= '<h4>' . esc_html__( 'Pickup Point', 'smart-send-logistics' ) . '</h4>';
			$html .= '<strong data-ss-value="pickup_point.agent_no">' . sprintf(
				/* translators: %s: pickup point agent number. */
				esc_html__( 'Agent No.: %s', 'smart-send-logistics' ),
				esc_html( $agent_no )
			) . '</strong>';

			if ( null !== $pickup_point && ! empty( $pickup_point['display_html'] ) ) {
				// display_html is built by pickup_point_state() through wp_kses_post().
				$html .= wp_kses_post( $pickup_point['display_html'] );
			}

			$html .= '<p class="form-field"><label for="smart-send-pickup-point-agent-no">' . esc_html__( 'Change pickup point (agent number)', 'smart-send-logistics' ) . '</label><br>';
			$html .= '<input type="text" id="smart-send-pickup-point-agent-no" name="smart_send[delivery_details][pickup_point][agent_no]" value="' . esc_attr( $agent_no ) . '" data-ss-field="pickup_point.agent_no" autocomplete="off"></p>';
			$html .= '</div>';

			return $html;
		}

		/**
		 * The parcels row: the "Split into parcels" toggle and one box select
		 * per unit of the order (the stored split pre-selected).
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_parcels_row( array $state ): string {
			$boxes_by_unit = $this->stored_box_numbers( $state );
			$split         = array() !== $boxes_by_unit;

			$html  = '<div class="smart-send-fulfillment__row" data-ss-section="parcel_plan">';
			$html .= '<label><input type="checkbox" name="smart_send[delivery_details][parcel_plan][split]" value="1" data-ss-field="parcel_plan.split" autocomplete="off"' . checked( $split, true, false ) . '> <strong>' . esc_html__( 'Split into parcels', 'smart-send-logistics' ) . '</strong></label>';
			$html .= '<div class="smart-send-fulfillment__units' . ( $split ? '' : ' hidden' ) . '"><table width="100%">';

			foreach ( $state['order']['units'] as $index => $unit ) {
				$box    = isset( $boxes_by_unit[ $index ] ) ? (int) $boxes_by_unit[ $index ] : 1;
				$select = '<select name="smart_send[delivery_details][parcel_plan][units][' . (int) $index . ']" data-id="' . esc_attr( (string) $unit['id'] ) . '" data-ss-field="parcel_plan.units[' . (int) $index . '].box" autocomplete="off">';
				for ( $i = 1; $i <= self::MAX_BOXES; $i++ ) {
					$select .= '<option value="' . (int) $i . '"' . selected( $box, $i, false ) . '>' . (int) $i . '</option>';
				}
				$select .= '</select>';

				$html .= '<tr data-ss-unit="' . esc_attr( (string) $unit['id'] ) . '"><td width="80%">' . esc_html( $unit['name'] ) . '</td><td width="20%">' . $select . '</td></tr>';
			}

			$html .= '</table></div></div>';

			return $html;
		}

		/**
		 * The stored parcel split as one box number per unit row (unit index
		 * => box number), matched to the state's units by walking each
		 * spec's allocations; empty when no split is stored.
		 *
		 * @param array $state The state.
		 *
		 * @return array<int, string>
		 */
		protected function stored_box_numbers( array $state ): array {
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
			foreach ( $plan['specs'] as $position => $spec ) {
				$box = isset( $spec['reference'] ) && null !== $spec['reference'] ? (string) $spec['reference'] : (string) ( $position + 1 );

				foreach ( isset( $spec['items'] ) ? $spec['items'] : array() as $item ) {
					$id = (string) $item['id'];
					for ( $unit = 0; $unit < (int) $item['quantity']; $unit++ ) {
						if ( empty( $remaining[ $id ] ) ) {
							break;
						}
						$boxes[ array_shift( $remaining[ $id ] ) ] = $box;
					}
				}
			}

			return $boxes;
		}

		/**
		 * The "Also create return label" row: the checkbox defaulting from
		 * the method's auto-generate-return-label setting, disabled with a
		 * hint plus a return method select when no return method is
		 * configured (the select serves the return-only action too).
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_return_row( array $state ): string {
			$return_method = $state['return']['method'];
			$available     = null !== $return_method;

			$label = esc_html__( 'Also create return label', 'smart-send-logistics' );
			if ( $available ) {
				$name = $this->method_name( $return_method );
				if ( '' !== $name ) {
					$label .= ' <span data-ss-value="return.method">(' . esc_html( $name ) . ')</span>';
				}
			}

			$html  = '<div class="smart-send-fulfillment__row" data-ss-section="return">';
			$html .= '<label><input type="checkbox" name="smart_send[with_return]" value="1" data-ss-field="with_return" autocomplete="off"' . checked( $available && $state['return']['auto_default'], true, false ) . '> ' . $label . '</label>';

			if ( ! $available ) {
				$html .= $this->render_return_method_select( $state );
			}

			$html .= '</div>';

			return $html;
		}

		/**
		 * The return method choice for an order without a configured return
		 * method (state B, or a zone method without one): a hint plus the
		 * grouped return method select. The chosen method serves both the
		 * combined outbound + return run and the return-only action.
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_return_method_select( array $state ): string {
			$html  = '<p class="description" data-ss-hint="no_return_method">' . esc_html__( 'No return method configured on the shipping method - choose one here, or set one under WooCommerce → Shipping → the zone method.', 'smart-send-logistics' ) . '</p>';
			$html .= '<div class="smart-send-fulfillment__inline-form">';
			$html .= '<label for="smart-send-return-method">' . esc_html__( 'Return method', 'smart-send-logistics' ) . '</label>';
			$html .= '<select id="smart-send-return-method" class="smart-send-fulfillment__select" name="smart_send[return_method]" data-ss-field="return_method" autocomplete="off">';
			$html .= '<option value="" selected=\'selected\'>' . esc_html__( 'Select a method…', 'smart-send-logistics' ) . '</option>';
			$html .= $this->render_method_options( $state['methods']['return'], null );
			$html .= '</select>';
			$html .= '</div>';

			return $html;
		}

		/**
		 * State E: the booked outbound and/or return shipment (id + a pointer
		 * to the order notes - nothing but the id is persisted), the
		 * separate "Create return label" action while no return exists, and
		 * a "Book again" disclosure per booked direction re-opening the form
		 * with a confirm notice.
		 *
		 * @param array $state The state.
		 *
		 * @return string HTML
		 */
		protected function render_booked( array $state ): string {
			$html = '';

			if ( null !== $state['outbound_shipment'] ) {
				$html .= $this->render_booked_block( $state, false );
			} else {
				$html .= $this->render_details_rows( $state );
				$html .= $this->render_return_row( $state );
				$html .= '<p class="smart-send-fulfillment__actions">' . $this->render_button( 'outbound', $state, true ) . '</p>';
			}

			$html .= '<hr>';

			if ( null !== $state['return_shipment'] ) {
				$html .= $this->render_booked_block( $state, true );
			} else {
				$html .= '<div class="smart-send-fulfillment__row" data-ss-section="return_shipment"><p><strong>' . esc_html__( 'Return label', 'smart-send-logistics' ) . '</strong> ' . esc_html__( 'not created', 'smart-send-logistics' ) . '</p>';
				if ( null === $state['return']['method'] ) {
					$html .= $this->render_return_method_select( $state );
				}
				$html .= '<p class="smart-send-fulfillment__actions">' . $this->render_button( 'return', $state, false ) . '</p></div>';
			}

			return $html;
		}

		/**
		 * One booked shipment block (the v8-style id-only variant, since
		 * the booked shipment DTO is not persisted) with its "Book again"
		 * disclosure.
		 *
		 * @param array   $state     The state.
		 * @param boolean $is_return Which direction.
		 *
		 * @return string HTML
		 */
		protected function render_booked_block( array $state, bool $is_return ): string {
			$shipment = $is_return ? $state['return_shipment'] : $state['outbound_shipment'];
			$section  = $is_return ? 'return_shipment' : 'outbound_shipment';

			$html  = '<div class="smart-send-fulfillment__booked" data-ss-section="' . esc_attr( $section ) . '">';
			$html .= '<p><strong>' . ( $is_return ? esc_html__( 'Return label', 'smart-send-logistics' ) : esc_html__( 'Shipping label', 'smart-send-logistics' ) ) . '</strong> ';
			$html .= sprintf(
				/* translators: %s: Smart Send shipment id. */
				esc_html__( 'Booked · #%s', 'smart-send-logistics' ),
				'<span data-ss-value="' . esc_attr( $section . '.shipment_id' ) . '">' . esc_html( $shipment['shipment_id'] ) . '</span>'
			);
			$html .= '<br><span class="description">' . esc_html__( 'Documents and tracking are in the order notes.', 'smart-send-logistics' ) . '</span></p>';

			$html .= '<details data-ss-section="' . esc_attr( $is_return ? 'rebook_return' : 'rebook' ) . '">';
			$html .= '<summary>' . esc_html__( 'Book again (creates a new shipment)', 'smart-send-logistics' ) . '</summary>';
			$html .= $this->render_notice(
				'warning',
				'rebook',
				$is_return
					? esc_html__( 'A return label already exists for this order. Booking again creates a new shipment at Smart Send; the old one is not cancelled.', 'smart-send-logistics' )
					: esc_html__( 'A shipping label already exists for this order. Booking again creates a new shipment at Smart Send; the old one is not cancelled.', 'smart-send-logistics' )
			);
			$html .= '<input type="hidden" name="smart_send[confirm_rebook]" value="1" data-ss-field="confirm_rebook">';

			if ( ! $is_return ) {
				$html .= $this->render_details_rows( $state );
				$html .= $this->render_return_row( $state );
			}

			$html .= '<p class="smart-send-fulfillment__actions">' . $this->render_button( $is_return ? 'return' : 'outbound', $state, ! $is_return ) . '</p>';
			$html .= '</details>';
			$html .= '</div>';

			return $html;
		}

		/**
		 * A create-label button: the flow as its value and a data-ss-action
		 * selector.
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
				$text = $state['demo_mode'] ? __( 'DEMO MODE: Create return label', 'smart-send-logistics' ) : __( 'Create return label', 'smart-send-logistics' );
			} else {
				$text = $state['demo_mode'] ? __( 'DEMO MODE: Create shipping label', 'smart-send-logistics' ) : __( 'Create shipping label', 'smart-send-logistics' );
			}

			return '<button type="button" class="button' . ( $primary ? ' button-primary' : '' ) . '" name="smart_send[flow]" value="' . esc_attr( $flow ) . '" data-ss-action="' . ( $is_return ? 'create-return-label' : 'create-label' ) . '">' . esc_html( $text ) . '</button>';
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
		 * The optgroup markup of a method list, pre-selecting a code.
		 *
		 * @param array       $groups   Method groups (see method_groups()).
		 * @param string|null $selected The selected method code.
		 *
		 * @return string HTML
		 */
		protected function render_method_options( array $groups, ?string $selected ): string {
			$html = '';

			foreach ( $groups as $group ) {
				$html .= '<optgroup label="' . esc_attr( $group['carrier'] ) . '">';
				foreach ( $group['options'] as $option ) {
					$html .= '<option value="' . esc_attr( $option['code'] ) . '"' . selected( $selected, $option['code'], false ) . '>' . esc_html( $option['name'] ) . '</option>';
				}
				$html .= '</optgroup>';
			}

			return $html;
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
		 * The stored shipment of a direction: only the id is persisted
		 * (Decisions, #182), flagged legacy so the client shows the
		 * reduced "see the order notes" variant.
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
				'legacy'      => true,
			);
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
