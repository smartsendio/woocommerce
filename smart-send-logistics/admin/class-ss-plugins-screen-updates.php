<?php
/**
 * Manages Smart Send plugin updating on the Plugins screen.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Class SS_Plugins_Screen_Updates
 */
class SS_Plugins_Screen_Updates {

	/**
	 * Register this component's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'in_plugin_update_message-smart-send-logistics/smart-send-logistics.php', array( $this, 'in_plugin_update_message' ), 10, 2 );
	}

	/**
	 * Show a generic major-version upgrade notice on the plugins screen.
	 *
	 * No external request is made: the notice is computed locally by
	 * comparing the major version component of the installed and available
	 * versions. Minor and patch bumps show no notice.
	 *
	 * @param array    $args Unused parameter.
	 * @param stdClass $response Plugin update response.
	 */
	public function in_plugin_update_message( $args, $response ) {
		if ( ! $this->is_major_version_bump( SS_SHIPPING_VERSION, $response->new_version ) ) {
			return;
		}

		echo apply_filters( 'ss_in_plugin_update_message', '</p>' . wp_kses_post( $this->get_upgrade_notice() ) . '<p class="dummy">' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- notice HTML is wp_kses_post-sanitised above; the surrounding markup is static.
	}

	/**
	 * Whether the available version differs from the installed version in
	 * its major (semver) component.
	 *
	 * @param  string $installed_version Currently installed plugin version.
	 * @param  string $new_version Version offered by the update.
	 * @return bool
	 */
	protected function is_major_version_bump( $installed_version, $new_version ) {
		return $this->major_version( $installed_version ) !== $this->major_version( $new_version );
	}

	/**
	 * Extract the major version component from a semver-ish version string.
	 *
	 * @param  string $version Version string, e.g. "8.2.0".
	 * @return string
	 */
	private function major_version( $version ) {
		$parts = explode( '.', $version );

		return $parts[0];
	}

	/**
	 * Build the fixed, translated major-version upgrade notice.
	 *
	 * @return string
	 */
	protected function get_upgrade_notice() {
		$notice = sprintf(
			/* translators: %s: URL to the plugin's WordPress.org upgrade notice section. */
			__( 'This is a major version upgrade. In most cases it will work without any issues. If you have extended this plugin\'s functionality or used its filters/actions, please consult the <a href="%s" target="_blank">upgrade guide</a> before upgrading to make sure everything keeps working.', 'smart-send-logistics' ),
			esc_url( 'https://wordpress.org/plugins/smart-send-logistics/#upgrade-notice' )
		);

		return '<p class="wc_plugin_upgrade_notice">' . $notice . '</p>';
	}
}
