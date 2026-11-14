/**
 * Front end of the Verify age block: registers it as a checkout inner block
 * (forced into the payment step, rendered even when the saved checkout page
 * predates the plugin) and drives the AgeCheck loader through the Store API
 * (extensionCartUpdate). A second, fallback block in the fields area shows
 * the same button for carts that need no payment, where WooCommerce hides
 * the payment step.
 *
 * The cart response's extensions['navexa-agecheck'] is the server's state:
 * {v, required, status, hosted_url?, message?}. Until it says passed, a
 * validation-store error keeps the Place order button from submitting; the
 * server's woocommerce_checkout_validate_order_before_payment check is the
 * enforcement, this only saves the shopper a round trip.
 */
import { useSelect } from '@wordpress/data';
import metadata from './block.json';
import noPaymentMetadata from '../verify-age-no-payment/block.json';
import Block from './block.js';

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
