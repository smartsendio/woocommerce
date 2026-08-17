/**
 * Frontend entry for the Smart Send pickup point Checkout Block.
 *
 * registerCheckoutBlock maps the block name to the React component the
 * Checkout block renders in place of the saved markup, and derives the
 * block's `force` flag from metadata.attributes.lock.default.remove: a
 * forced block is rendered inside its parent inner-block area even when the
 * saved checkout page does not contain it, so the selector works with no
 * merchant action on existing checkout pages.
 */
import { registerCheckoutBlock } from '@woocommerce/blocks-checkout';

import metadata from './block.json';
import { attributes } from './attributes';
import Block from './block';

registerCheckoutBlock( {
	metadata: {
		...metadata,
		// Merge the JS-side definitions (translated defaults) over the
		// static block.json attributes, mirroring the editor registration.
		attributes: {
			...metadata.attributes,
			...attributes,
		},
	},
	component: Block,
} );
