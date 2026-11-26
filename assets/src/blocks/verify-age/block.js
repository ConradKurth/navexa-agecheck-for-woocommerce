/**
 * The age check status in the block checkout (TAX-1874). No button: the
 * check opens at Place order (agecheck.js, the onCheckoutValidation
 * observer), in the shared loader's in-page modal.
 *
 * - Before a pass: "You'll confirm your age when you place the order" (or
 *   the server's own message, such as a changed ship-to); after: "Age
 *   verified". The status line is a polite live region (this component is
 *   not re-mounted on cart updates, so the region persists).
 * - With Turnstile on it holds the Turnstile container the start renders
 *   into; an interactive challenge shows there and gets the focus.
 * - After a pass WooCommerce's own address cards replace the address forms
 *   (setEditingShippingAddress(false) / setEditingBillingAddress(false));
 *   their Edit button unlocks, and a notice says a new address means a new
 *   check. Nothing is reverted: the server binds the ship-to and decides.
 * - Back from the loader's full-page redirect fallback (pending on load):
 *   one refresh, no modal.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { CART_STORE, NAMESPACE, settings, t, transport } from './agecheck.js';

const CHECKOUT_STORE = 'wc/store/checkout';
const NOTICE_ID = 'navexa-agecheck-edited';

/**
 * Locks the address forms behind WooCommerce's address cards after a pass,
 * once per pass; a notice when the shopper opens one again.
 *
 * @param {boolean} passed Whether the check passed.
 */
function useAddressLock( passed ) {
	const editing = useSelect( ( select ) => {
		const store = select( CHECKOUT_STORE );
		return {
			shipping:
				typeof store.getEditingShippingAddress === 'function'
					? store.getEditingShippingAddress()
					: false,
			billing:
				typeof store.getEditingBillingAddress === 'function'
					? store.getEditingBillingAddress()
					: false,
		};
	}, [] );
	const checkout = useDispatch( CHECKOUT_STORE );
	const notices = useDispatch( 'core/notices' );
	const locked = useRef( false );

	useEffect( () => {
		if ( ! passed ) {
			locked.current = false;
			return;
		}
		if ( locked.current ) {
			return;
		}
		locked.current = true;
		if ( typeof checkout.setEditingShippingAddress === 'function' ) {
			checkout.setEditingShippingAddress( false );
		}
		if ( typeof checkout.setEditingBillingAddress === 'function' ) {
			checkout.setEditingBillingAddress( false );
		}
	}, [ passed, checkout ] );

	useEffect( () => {
		if ( ! passed || ! locked.current || ( ! editing.shipping && ! editing.billing ) ) {
			return;
		}
		if ( notices && typeof notices.createInfoNotice === 'function' ) {
			notices.createInfoNotice( t( 'edited' ), {
				id: NOTICE_ID,
				context: editing.shipping
					? 'wc/checkout/shipping-address'
					: 'wc/checkout/billing-address',
			} );
		}
	}, [ passed, editing.shipping, editing.billing, notices ] );
}

export default function Block() {
	const server = useSelect( ( select ) => {
		const cart = select( CART_STORE ).getCartData();
		return ( cart && cart.extensions && cart.extensions[ NAMESPACE ] ) || null;
	}, [] );
	const required = !! ( server && server.required );
	const status = ( server && server.status ) || 'none';
	const turnstile = settings().turnstile || null;
	const refreshed = useRef( false );

	useEffect( () => {
		if ( refreshed.current || status !== 'pending' ) {
			return;
		}
		// Back from a full-page redirect, or a reload mid-check: ask once.
		refreshed.current = true;
		transport( 'refresh' ).catch( () => undefined );
	}, [ status ] );

	useAddressLock( required && status === 'passed' );

	if ( ! required ) {
		return null;
	}

	let message = server && server.message ? server.message : '';
	if ( ! message ) {
		if ( status === 'passed' ) {
			message = t( 'passed' );
		} else if ( status === 'failed' ) {
			message = t( 'failed' );
		} else if ( status === 'pending' ) {
			message = t( 'pending' );
		} else {
			message = t( 'prompt' );
		}
	}

	return (
		<div
			className="navexa-agecheck wc-block-components-checkout-step__container"
			data-navexa-agecheck="1"
			data-status={ status }
		>
			<p
				className="navexa-agecheck__message"
				role="status"
				aria-live="polite"
				tabIndex={ -1 }
			>
				{ message }
			</p>
			{ turnstile && status !== 'passed' && (
				<div
					className="navexa-agecheck__turnstile"
					data-navexa-agecheck-turnstile="1"
					role="group"
					aria-label={ t( 'security' ) }
					tabIndex={ -1 }
				/>
			) }
		</div>
	);
}
