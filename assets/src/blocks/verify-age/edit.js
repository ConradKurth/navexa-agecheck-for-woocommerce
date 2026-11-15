/**
 * Editor view of the Verify age block: a static preview. The block has no
 * settings; whether it shows, and what it says, depends on the cart.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit() {
	return (
		<div { ...useBlockProps( { className: 'navexa-agecheck' } ) }>
			<p className="navexa-agecheck__message">
				{ __(
					'This order contains age-restricted items. Verify your age to place it.',
					'navexa-agecheck-for-woocommerce'
				) }
			</p>
			<button
				type="button"
				className="wc-block-components-button wp-element-button"
				disabled
			>
				{ __( 'Verify age', 'navexa-agecheck-for-woocommerce' ) }
			</button>
			<p style={ { fontSize: '0.8em', opacity: 0.7 } }>
				{ __(
					'Navexa AgeCheck shows this only when the cart has age-restricted items.',
					'navexa-agecheck-for-woocommerce'
				) }
			</p>
		</div>
	);
}
