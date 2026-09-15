/**
 * Fallback for the `react-jsx-runtime` script handle on WordPress < 6.6.
 *
 * The built block bundles (build/pickup-point-block/*.js) are compiled with
 * the automatic JSX runtime, so they read `window.ReactJSXRuntime` and
 * declare a dependency on the `react-jsx-runtime` script handle. WordPress
 * only registers that handle (and global) from 6.6; on the plugin's
 * WordPress 6.5 floor the unmet dependency would keep the bundles - and,
 * because WooCommerce merges integration handles into the Checkout block's
 * own script dependencies, the whole Checkout block - from loading (#183).
 *
 * SS_Shipping_Block_Checkout registers this file under the same handle when
 * WordPress has not. It mirrors the public surface of react/jsx-runtime:
 * jsx(), jsxs() and Fragment, all on top of the React global WordPress
 * ships (React.createElement takes key and ref out of the props itself).
 */
( function ( window ) {
	if ( window.ReactJSXRuntime ) {
		return;
	}

	var React = window.React;

	function jsx( type, props, maybeKey ) {
		if ( maybeKey !== undefined ) {
			props = Object.assign( {}, props, { key: '' + maybeKey } );
		}

		return React.createElement( type, props );
	}

	window.ReactJSXRuntime = {
		Fragment: React.Fragment,
		jsx: jsx,
		jsxs: jsx,
	};
} )( window );
