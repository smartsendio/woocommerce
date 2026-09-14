<?php
/**
 * Smart Send admin notices.
 *
 * @package SmartSendLogistics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Smart Send admin notices: transient-backed one-time notices ("flash
 * messages") and the per-user dismissible Orders screen notice.
 *
 * Flash messages: push notices during an admin action, mark the post-action
 * redirect URL with a query parameter, render the pending notices exactly
 * once on the next page load, and clear them.
 *
 * Storage is the WordPress Transients API keyed per user, so notices are
 * only ever shown to the user who triggered the action. Retrieval is gated
 * on the marker query parameter: admin requests that did not just perform a
 * Smart Send action never touch the transient.
 *
 * Rendering uses the plain `admin_notices` hook rather than WooCommerce's
 * `WC_Admin_Notices`: that API stores notices site-wide in a single option
 * and keeps them until dismissed, which does not fit per-user render-once
 * flash messages.
 *
 * Dismissible notices: the "bulk label printing removed" notice (#173) shows
 * on the Orders list screen to users who can manage WooCommerce until they
 * dismiss it. Dismissal is a nonce-protected link (no JavaScript) stored per
 * user in user meta.
 */
class SS_Shipping_Admin_Notices {

	/**
	 * Query parameter added to post-action redirect URLs. Pending notices
	 * are only looked up when this parameter is present on the request.
	 */
	const QUERY_ARG = 'ss_shipping_notices';

	/**
	 * Prefix for the per-user transient key holding the pending notices.
	 */
	const TRANSIENT_PREFIX = 'ss_shipping_notices_';

	/**
	 * How long pending notices are kept before the transient expires.
	 * A safety net for notices that are pushed but never rendered
	 * (e.g. the user closes the tab before the redirect completes).
	 */
	const EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Id of the notice telling merchants the Orders screen bulk label
	 * actions were removed in 9.0.0 (#173).
	 */
	const NOTICE_BULK_LABELS_REMOVED = 'bulk_labels_removed';

	/**
	 * User meta key holding the ids of the notices a user has dismissed.
	 */
	const DISMISSED_META_KEY = '_ss_shipping_dismissed_notices';

	/**
	 * Query parameter carrying the id of the notice to dismiss.
	 */
	const DISMISS_QUERY_ARG = 'ss_shipping_dismiss_notice';

	/**
	 * Nonce action prefix for dismissal links; the notice id is appended.
	 */
	const DISMISS_NONCE_ACTION = 'ss_shipping_dismiss_notice_';

	/**
	 * WordPress.org page listing the plugin's previous versions.
	 */
	const PREVIOUS_VERSIONS_URL = 'https://wordpress.org/plugins/smart-send-logistics/advanced/';

	/**
	 * Screen ids of the Orders list: legacy post-based and HPOS.
	 */
	const ORDERS_SCREEN_IDS = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );

	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_bulk_labels_removed_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_dismiss' ) );
	}

	/**
	 * Queue notices to be shown once to a user on their next marked request.
	 *
	 * @param array<array{message: string, type: string, dismissible?: bool}> $messages Notices to queue.
	 * @param int|null                                                        $user_id  User to queue for. Defaults to the current user.
	 */
	public function push( array $messages, $user_id = null ) {
		if ( empty( $messages ) ) {
			return;
		}

		$pending = $this->get_pending( $user_id );

		set_transient(
			$this->get_transient_key( $user_id ),
			array_merge( $pending, array_values( $messages ) ),
			self::EXPIRATION
		);
	}

	/**
	 * Add the marker query parameter to a redirect URL so the next request
	 * knows to look for pending notices.
	 *
	 * @param string $url Redirect URL.
	 * @return string
	 */
	public function add_notices_query_arg( $url ) {
		return add_query_arg( self::QUERY_ARG, '1', $url );
	}

	/**
	 * Render the current user's pending notices when the marker query
	 * parameter is present, then clear them.
	 *
	 * Without the marker parameter this is a no-op: no transient lookup
	 * happens on unrelated admin requests.
	 */
	public function maybe_render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check of a display-only marker; no action is taken based on it.
		if ( empty( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}

		$messages = $this->get_pending();

		if ( empty( $messages ) ) {
			return;
		}

		$this->print_notices( $messages );

		$this->clear();
	}

	/**
	 * Get the pending notices for a user.
	 *
	 * @param int|null $user_id User to read for. Defaults to the current user.
	 * @return array<array{message: string, type: string, dismissible?: bool}>
	 */
	public function get_pending( $user_id = null ) {
		$messages = get_transient( $this->get_transient_key( $user_id ) );

		return is_array( $messages ) ? $messages : array();
	}

	/**
	 * Delete the pending notices for a user.
	 *
	 * @param int|null $user_id User to clear for. Defaults to the current user.
	 */
	public function clear( $user_id = null ) {
		delete_transient( $this->get_transient_key( $user_id ) );
	}

	/**
	 * Tell users who can manage WooCommerce, on the Orders list screen, that
	 * bulk label printing was removed in 9.0.0 - until they dismiss it.
	 *
	 * @return void
	 */
	public function maybe_render_bulk_labels_removed_notice() {
		if ( ! $this->is_orders_list_screen()
			|| ! current_user_can( 'manage_woocommerce' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce core capability, registered by WooCommerce rather than WordPress.
			|| $this->is_dismissed( self::NOTICE_BULK_LABELS_REMOVED ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: URL of the WordPress.org page listing the plugin's previous versions. */
			__( '<strong>Smart Send:</strong> Bulk printing of shipping labels from the Orders screen has been removed in version 9.0.0. We are building a much better version, and it is coming soon. You can still create labels one order at a time from the order page. If you need the old bulk printing, you can <a href="%s" target="_blank">downgrade to version 8.1.3</a>.', 'smart-send-logistics' ),
			esc_url( self::PREVIOUS_VERSIONS_URL )
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
			wp_kses_post( $message ),
			esc_url( $this->get_dismiss_url( self::NOTICE_BULK_LABELS_REMOVED ) ),
			esc_html__( 'Dismiss', 'smart-send-logistics' )
		);
	}

	/**
	 * Handle a dismissal link: with a valid nonce for a known notice, store
	 * the dismissal for the current user and redirect back without the
	 * dismissal parameters. Anything else is ignored.
	 *
	 * @return void
	 */
	public function maybe_handle_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified below before anything is stored.
		if ( empty( $_GET[ self::DISMISS_QUERY_ARG ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified below before anything is stored.
		$notice_id = sanitize_key( wp_unslash( $_GET[ self::DISMISS_QUERY_ARG ] ) );
		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( self::NOTICE_BULK_LABELS_REMOVED !== $notice_id
			|| ! wp_verify_nonce( $nonce, self::DISMISS_NONCE_ACTION . $notice_id ) ) {
			return;
		}

		$this->dismiss( $notice_id );

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_QUERY_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Whether a user has dismissed a notice.
	 *
	 * @param string   $notice_id Notice id.
	 * @param int|null $user_id   User to check. Defaults to the current user.
	 * @return bool
	 */
	public function is_dismissed( $notice_id, $user_id = null ) {
		return in_array( $notice_id, $this->get_dismissed( $user_id ), true );
	}

	/**
	 * Store a notice as dismissed for a user.
	 *
	 * @param string   $notice_id Notice id.
	 * @param int|null $user_id   User to dismiss for. Defaults to the current user.
	 * @return void
	 */
	public function dismiss( $notice_id, $user_id = null ) {
		if ( $this->is_dismissed( $notice_id, $user_id ) ) {
			return;
		}

		$dismissed   = $this->get_dismissed( $user_id );
		$dismissed[] = $notice_id;

		update_user_meta( null === $user_id ? get_current_user_id() : $user_id, self::DISMISSED_META_KEY, $dismissed );
	}

	/**
	 * Get the nonce-protected URL (current request URL plus the dismissal
	 * parameters) dismissing a notice for the current user. Unescaped:
	 * escape with esc_url() on output. (wp_nonce_url() is avoided because it
	 * HTML-escapes the URL itself.)
	 *
	 * @param string $notice_id Notice id.
	 * @return string
	 */
	public function get_dismiss_url( $notice_id ) {
		return add_query_arg(
			array(
				self::DISMISS_QUERY_ARG => $notice_id,
				'_wpnonce'              => wp_create_nonce( self::DISMISS_NONCE_ACTION . $notice_id ),
			)
		);
	}

	/**
	 * Print the given notices as admin notice markup.
	 *
	 * @param array<array{message: string, type: string, dismissible?: bool}> $messages Notices to print.
	 */
	protected function print_notices( array $messages ) {
		foreach ( $messages as $message ) {
			switch ( isset( $message['type'] ) ? $message['type'] : '' ) {
				case 'error':
					$type = 'error';
					break;
				case 'success':
					$type = 'success';
					break;
				default:
					$type = 'warning';
			}

			printf(
				'<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>',
				esc_attr( $type ), // One of info/warning/error/success.
				( isset( $message['dismissible'] ) && $message['dismissible'] ) ? 'is-dismissible' : '',
				wp_kses_post( $message['message'] )
			);
		}
	}

	/**
	 * Whether the current admin screen is the Orders list (legacy or HPOS).
	 *
	 * The HPOS list and single-order screens share one screen id; like
	 * WooCommerce's own PageController, the `action` query parameter
	 * (edit/new) tells them apart.
	 *
	 * @return bool
	 */
	protected function is_orders_list_screen() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, self::ORDERS_SCREEN_IDS, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		return ! in_array( $action, array( 'edit', 'new' ), true );
	}

	/**
	 * Get the ids of the notices a user has dismissed.
	 *
	 * @param int|null $user_id User id. Defaults to the current user.
	 * @return string[]
	 */
	protected function get_dismissed( $user_id = null ) {
		$dismissed = get_user_meta( null === $user_id ? get_current_user_id() : $user_id, self::DISMISSED_META_KEY, true );

		return is_array( $dismissed ) ? $dismissed : array();
	}

	/**
	 * Get the transient key holding a user's pending notices.
	 *
	 * @param int|null $user_id User id. Defaults to the current user.
	 * @return string
	 */
	protected function get_transient_key( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		return self::TRANSIENT_PREFIX . $user_id;
	}
}
