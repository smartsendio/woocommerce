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
 * Transient-backed one-time admin notices ("flash messages").
 *
 * Owns the full lifecycle: push notices during an admin action, mark the
 * post-action redirect URL with a query parameter, render the pending
 * notices exactly once on the next page load, and clear them.
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
	 * Hook the renderer into the admin.
	 */
	public function init_hooks() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
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
