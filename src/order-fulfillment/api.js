/**
 * The REST calls of the meta box app, all against the routes
 * SS_Shipping_Fulfillment_Rest_Controller registers under smart-send/v1
 * (the state advertises the fulfillment path as urls.rest). apiFetch adds
 * the REST root and the X-WP-Nonce header WordPress printed for the
 * wp-api-fetch script, so a request is authenticated the WordPress way.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * POST …/fulfillment: book the requested label(s).
 *
 * @param {string} restPath The fulfillment route (state.urls.rest).
 * @param {Object} body     The request body (section 3.1 of #182).
 * @return {Promise<Object>} The run response (section 3.2), or a rejection carrying { code, message, data }.
 */
export function fulfill( restPath, body ) {
	return apiFetch( { path: restPath, method: 'POST', data: body } );
}

/**
 * GET …/pickup-points/{agent_no}: resolve an entered agent number.
 *
 * @param {string} restPath The fulfillment route (state.urls.rest).
 * @param {string} agentNo  The agent number to look up.
 * @param {string} method   The Smart Send method whose carrier to look it up for ('' for the order's).
 * @return {Promise<Object>} The pickup point (to_array() + display_html), or a rejection (404 smart_send_pickup_point_not_found).
 */
export function lookupPickupPoint( restPath, agentNo, method ) {
	const path = restPath.replace( /\/fulfillment$/, '' ) + '/pickup-points/' + encodeURIComponent( agentNo );

	return apiFetch( { path: method ? addQueryArgs( path, { method } ) : path } );
}
