/**
 * Editor experience for the Smart Send pickup point block.
 *
 * The merchant edits the title and description inline (RichText, matching
 * the WooCommerce checkout inner-block pattern) and styles the block via
 * the block.json supports (color/typography/spacing panels). The selector
 * itself is a disabled preview: which pickup points exist depends on the
 * shopper's address, so nothing real can render in the editor.
 */
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { SelectControl, Disabled } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const Edit = ( { attributes, setAttributes } ) => {
	const { title, description } = attributes;
	const blockProps = useBlockProps( {
		className: 'ss-pickup-point-block',
	} );

	return (
		<div { ...blockProps }>
			<RichText
				tagName="h2"
				className="ss-pickup-point-block__title"
				value={ title }
				onChange={ ( value ) => setAttributes( { title: value } ) }
				placeholder={ __( 'Pickup Point', 'smart-send-logistics' ) }
				allowedFormats={ [] }
			/>
			<RichText
				tagName="p"
				className="ss-pickup-point-block__description"
				value={ description }
				onChange={ ( value ) =>
					setAttributes( { description: value } )
				}
				placeholder={ __(
					'Optional text describing the pickup point selection.',
					'smart-send-logistics'
				) }
				allowedFormats={ [] }
			/>
			<Disabled>
				<SelectControl
					className="ss-pickup-point-block__select"
					label={ __( 'Pickup point', 'smart-send-logistics' ) }
					hideLabelFromVision={ true }
					value=""
					options={ [
						{
							label: __(
								'- Select Pickup Point -',
								'smart-send-logistics'
							),
							value: '',
						},
					] }
					onChange={ () => {} }
					__nextHasNoMarginBottom={ true }
					help={ __(
						'Shoppers see the pickup points closest to their address when a Smart Send agent method is selected.',
						'smart-send-logistics'
					) }
				/>
			</Disabled>
		</div>
	);
};

/**
 * The saved markup is a placeholder div: the frontend replaces it with the
 * real component (registerCheckoutBlock), and WooCommerce's render_block
 * data-attributes filter copies the block attributes onto it as data-*
 * attributes, which is how the edited title/description reach the frontend
 * component as props (see SS_Shipping_Block_Checkout::register_hooks()).
 */
export const Save = () => {
	return <div { ...useBlockProps.save() } />;
};
