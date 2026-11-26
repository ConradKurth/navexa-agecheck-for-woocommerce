/**
 * Editor view of the age check block: a static preview. The block has no
 * settings; whether it shows, and what it says, depends on the cart. The
 * check itself opens at Place order.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export default function Edit() {
	return (
		<div { ...useBlockProps( { className: 'navexa-agecheck' } ) }>
			<p className="navexa-agecheck__message">
				{ __(
					'This order contains age-restricted items. When you place it, you\'ll confirm your age in a secure window on this page.',
					'navexa-agecheck-for-woocommerce'
				) }
			</p>
			<p style={ { fontSize: '0.8em', opacity: 0.7 } }>
				{ __(
					'Navexa AgeCheck shows this only when the cart has age-restricted items.',
					'navexa-agecheck-for-woocommerce'
				) }
			</p>
		</div>
	);
}
