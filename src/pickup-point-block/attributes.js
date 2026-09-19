/**
 * Shared block attributes for the Smart Send pickup point block.
 *
 * block.json cannot carry translated strings, so - like WooCommerce's own
 * checkout inner blocks - the attribute definitions live in JS with
 * translated defaults and are merged over the block.json metadata at
 * registration time. The frontend component ALSO needs these defaults
 * directly: a forced block (rendered because it is missing from the saved
 * page markup) receives no props at all, so the component falls back to
 * these values itself.
 *
 * The `lock.default.remove: true` attribute is load-bearing twice over:
 * WooCommerce's checkout registry derives the block's `force` flag from it
 * (frontend force-rendering when absent from saved content) and the block
 * editor's forced-layout pass auto-inserts blocks carrying it into the
 * parent inner-block area. Removing it would silently break agent-method
 * orders, hence the block cannot be deleted in the editor (it remains
 * styleable and text-editable).
 */
import { __ } from '@wordpress/i18n';

export const defaultTitle = __( 'Pickup Point', 'smart-send-logistics' );

export const defaultDescription = __(
	'Select where you would like to pick up your order.',
	'smart-send-logistics'
);

export const attributes = {
	title: {
		type: 'string',
		default: defaultTitle,
	},
	description: {
		type: 'string',
		default: defaultDescription,
	},
	lock: {
		type: 'object',
		default: {
			remove: true,
			move: false,
		},
	},
};
