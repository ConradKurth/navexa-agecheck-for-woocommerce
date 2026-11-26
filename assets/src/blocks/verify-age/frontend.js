/**
 * Front end of the age check in the block checkout (TAX-1874): registers the
 * Place order observer (agecheck.js) at module load, whichever block variant
 * is mounted, and the status block as a checkout inner block (forced into the
 * payment step, rendered even when the saved checkout page predates the
 * plugin). A second, fallback block in the fields area shows the status for
 * carts that need no payment, where WooCommerce hides the payment step.
 *
 * The check opens at Place order in the shared loader's in-page modal; the
 * server's woocommerce_checkout_validate_order_before_payment check is the
 * enforcement.
 */
import { useSelect } from '@wordpress/data';
import metadata from './block.json';
import noPaymentMetadata from '../verify-age-no-payment/block.json';
import Block from './block.js';
import { registerObserver } from './agecheck.js';

/**
 * The fallback for carts that need no payment: WooCommerce does not render
 * the payment step then, so the main block is not mounted either.
 */
function NoPaymentBlock() {
	const needsPayment = useSelect( ( select ) => {
		const cart = select( 'wc/store/cart' ).getCartData();
		return ! cart || cart.needsPayment !== false;
	}, [] );
	return needsPayment ? null : <Block />;
}

registerObserver();

const blocksCheckout = window.wc && window.wc.blocksCheckout;

if ( blocksCheckout && typeof blocksCheckout.registerCheckoutBlock === 'function' ) {
	blocksCheckout.registerCheckoutBlock( {
		metadata,
		component: Block,
	} );
	blocksCheckout.registerCheckoutBlock( {
		metadata: noPaymentMetadata,
		component: NoPaymentBlock,
	} );
}
