/**
 * Editor entry for the Smart Send pickup point Checkout Block.
 *
 * Registers the block type with the parent set to the Checkout block's
 * shipping-methods inner block area. The editor's forced-layout pass
 * auto-inserts any registered block type whose lock attribute defaults to
 * remove: true into that area, so the block appears on the checkout page in
 * the Site Editor without the merchant inserting it - and cannot be
 * removed, only styled and re-worded.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import { attributes } from './attributes';
import { Edit, Save } from './edit';

registerBlockType( metadata.name, {
	...metadata,
	// The JS-side attribute definitions (translated defaults) win over the
	// static block.json ones.
	attributes: {
		...metadata.attributes,
		...attributes,
	},
	edit: Edit,
	save: Save,
} );
