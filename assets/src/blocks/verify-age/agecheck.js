/**
 * The block checkout's side of the age check (TAX-1874): the Store API
 * transport (extensionCartUpdate), the Place order observer
 * (assets/src/loader/checkout-observer.ts, registered with WooCommerce's
 * onCheckoutValidation) and the settings the block reads.
 *
 * The cart response's extensions['navexa-agecheck'] is the server's state:
 * {v, required, status, hosted_url?, message?, code?}. The browser never
 * holds a verification id, a guard or a key, and never decides:
 * Checkout\Blocks::validate() refuses an order that has not passed.
 */
import { dispatch, select } from '@wordpress/data';
import { createCheckoutObserver } from '../../loader/checkout-observer.ts';
import { originOf } from '../../loader/types.ts';

export const NAMESPACE = 'navexa-agecheck';
export const CART_STORE = 'wc/store/cart';
const VALIDATION_STORE = 'wc/store/validation';
const PAYMENT_STORE = 'wc/store/payment';
const TURNSTILE = '[data-navexa-agecheck-turnstile]';

/** The block's data (wc.wcSettings 'navexa-agecheck_data', Frontend::script_data()). */
export function settings() {
	const wcSettings = window.wc && window.wc.wcSettings;
	const data =
		wcSettings && typeof wcSettings.getSetting === 'function'
			? wcSettings.getSetting( NAMESPACE + '_data', {} )
			: {};
	return data && typeof data === 'object' ? data : {};
}

/** Shopper copy by key. */
export function t( key ) {
	const i18n = settings().i18n || {};
	return typeof i18n[ key ] === 'string' ? i18n[ key ] : '';
}

/** The server's state in the cart, or null. */
export function cartState() {
	const cart = select( CART_STORE ).getCartData();
	return ( cart && cart.extensions && cart.extensions[ NAMESPACE ] ) || null;
}

let turnstileClient = null;

function turnstile() {
	const config = settings().turnstile;
	const factory = window.NavexaAgeCheckTurnstile;
	if ( config && ! turnstileClient && factory ) {
		turnstileClient = factory.create( config );
	}
	return turnstileClient;
}

/**
 * One start or refresh through the Store API extension; the answer is the
 * cart's extensions['navexa-agecheck'] after the update. A start first asks
 * for a Turnstile token when the merchant turned Turnstile on.
 *
 * @param {string} action start or refresh.
 * @return {Promise<Object>} The store's answer.
 */
export async function transport( action ) {
	const checkout = window.wc && window.wc.blocksCheckout;
	if ( ! checkout || typeof checkout.extensionCartUpdate !== 'function' ) {
		throw new Error( 'navexa-agecheck: no extensionCartUpdate' );
	}
	const data = { v: 1, action };
	if ( action === 'start' && settings().turnstile ) {
		const client = turnstile();
		const token = client
			? await client.token( document.querySelector( TURNSTILE ) )
			: null;
		if ( ! token ) {
			const challenging = client && client.challenging();
			return {
				v: 1,
				status: 'refused',
				message: challenging ? t( 'challenge' ) : t( 'turnstile' ),
			};
		}
		data.turnstile = token;
	}
	await checkout.extensionCartUpdate( { namespace: NAMESPACE, data } );
	const answer = cartState();
	if ( ! answer || typeof answer.status !== 'string' ) {
		throw new Error( 'navexa-agecheck: no answer' );
	}
	return answer;
}

/**
 * Sends the address the shopper sees to the session, as WooCommerce's own
 * debounced push does, and waits for it (at most 8 seconds).
 */
async function pushCustomerData() {
	const store = select( CART_STORE );
	const actions = dispatch( CART_STORE );
	if ( typeof store.getCustomerData === 'function' && typeof actions.updateCustomerData === 'function' ) {
		const customer = store.getCustomerData();
		await actions.updateCustomerData(
			{
				billing_address: customer.billingAddress,
				shipping_address: customer.shippingAddress,
			},
			true
		);
	}
	const started = Date.now();
	while (
		typeof store.isCustomerDataUpdating === 'function' &&
		select( CART_STORE ).isCustomerDataUpdating() &&
		Date.now() - started < 8000
	) {
		await new Promise( ( resolve ) => window.setTimeout( resolve, 150 ) );
	}
}

/** The Place order observer: one run at a time (concurrent emits share it). */
export const observer = createCheckoutObserver( {
	state: cartState,
	hasValidationErrors: () => {
		const store = select( VALIDATION_STORE );
		return typeof store.hasValidationErrors === 'function' && store.hasValidationErrors();
	},
	expressActive: () => {
		const store = select( PAYMENT_STORE );
		return typeof store.isExpressPaymentMethodActive === 'function' && store.isExpressPaymentMethodActive();
	},
	pushCustomerData,
	transport,
	verify: async ( url ) => {
		const api = window.NavexaAgeCheck;
		if ( ! api || typeof api.verify !== 'function' ) {
			return null;
		}
		return api.verify( { url }, { host: originOf( url ), labels: settings().labels } );
	},
	t,
	challenging: () => !! ( turnstileClient && turnstileClient.challenging() ),
	onChallenge: () => {
		const widget = document.querySelector( TURNSTILE );
		if ( widget && typeof widget.focus === 'function' ) {
			widget.focus();
		}
	},
} );

/**
 * Registers the observer with WooCommerce (once). Without the checkout
 * events API (not expected on 9.9 or later) the block only shows its status
 * and the server refuses an order that has not passed.
 *
 * @return {boolean} Whether it is registered.
 */
export function registerObserver() {
	const events =
		window.wc && window.wc.blocksCheckoutEvents && window.wc.blocksCheckoutEvents.checkoutEvents;
	if ( ! events || typeof events.onCheckoutValidation !== 'function' ) {
		return false;
	}
	events.onCheckoutValidation( observer, 10 );
	return true;
}
