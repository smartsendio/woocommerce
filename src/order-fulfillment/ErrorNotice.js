/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The general error notice of a failed request or leg (section 1.2-I):
 * the message, the field errors no form field carries (e.g. the order's
 * shipping address) and the Response ID for support. Field errors the
 * merchant can fix in the box are rendered next to their field instead
 * (FieldError).
 */
import { createElement } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function ErrorNotice( { noticeKey, message, details = [], responseId = null, status = 'error', onDismiss } ) {
	return (
		<div className="smart-send-fulfillment__notice" data-ss-notice={ noticeKey }>
			<Notice status={ status } isDismissible={ !! onDismiss } onRemove={ onDismiss }>
				<p>{ message }</p>
				{ details.length > 0 && (
					<ul>
						{ details.map( ( detail, index ) => (
							<li key={ index }>{ detail }</li>
						) ) }
					</ul>
				) }
				{ responseId && (
					<p className="description" data-ss-value="response_id">
						{
							/* translators: %s: the Smart Send API response id. */
							sprintf( __( 'Response ID: %s', 'smart-send-logistics' ), responseId )
						}
					</p>
				) }
			</Notice>
		</div>
	);
}

/**
 * The inline error(s) of one form field.
 */
export function FieldError( { field, errors } ) {
	const messages = errors && errors[ field ] ? errors[ field ] : [];

	if ( messages.length === 0 ) {
		return null;
	}

	return (
		<p className="smart-send-fulfillment__field-error" data-ss-error={ field } role="alert">
			{ messages.join( ' ' ) }
		</p>
	);
}
