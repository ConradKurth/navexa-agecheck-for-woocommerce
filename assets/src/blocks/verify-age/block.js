/**
 * The Verify age button for the checkout block.
 *
 * Accessibility: a native button that stays focusable while a check runs
 * (aria-disabled, not disabled), a status line that is a polite live region
 * (this component is not re-mounted on cart updates, so the region persists),
 * aria-busy while busy, and, when a check the shopper started ends, the
 * focus on the status line after a pass (the button is gone then) or back on
 * the button, unless the shopper moved it elsewhere.
 *
 * With Turnstile on (settings().turnstile), a start first gets a Turnstile
 * token (window.NavexaAgeCheckTurnstile, assets/src/loader/turnstile.ts)
 * rendered into this block; without one the start is refused here. When
 * Cloudflare shows an interactive challenge instead, the start is refused at
 * once (the popup closes), the status says to complete the check shown, and
 * the focus goes to it; the next Verify age click uses its token.
 */
import { select, useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const NAMESPACE = 'navexa-agecheck';
const CART_STORE = 'wc/store/cart';
const VALIDATION_STORE = 'wc/store/validation';
const VALIDATION_KEY = 'navexa-agecheck';

function settings() {
	const wcSettings = window.wc && window.wc.wcSettings;
	const data =
		wcSettings && typeof wcSettings.getSetting === 'function'
			? wcSettings.getSetting( NAMESPACE + '_data', {} )
			: {};
	return data && typeof data === 'object' ? data : {};
}

function t( key ) {
	const i18n = settings().i18n || {};
	return typeof i18n[ key ] === 'string' ? i18n[ key ] : '';
}

/**
 * Waits until the block checkout has pushed the shopper's address to the
 * session (the guard is bound to the session's ship-to), at most 8 seconds.
 *
 * @param {Function} isUpdating Whether customer data is being pushed.
 * @return {Promise<void>} Resolves when idle.
 */
function customerDataSettled( isUpdating ) {
	return new Promise( ( resolve ) => {
		const started = Date.now();
		const check = () => {
			if ( ! isUpdating() || Date.now() - started > 8000 ) {
				resolve();
				return;
			}
			window.setTimeout( check, 150 );
		};
		check();
	} );
}

export default function Block() {
	const server = useSelect( ( select ) => {
		const cart = select( CART_STORE ).getCartData();
		return ( cart && cart.extensions && cart.extensions[ NAMESPACE ] ) || null;
	}, [] );
	const { setValidationErrors, clearValidationError } =
		useDispatch( VALIDATION_STORE );
	const validationError = useSelect(
		( select ) => select( VALIDATION_STORE ).getValidationError( VALIDATION_KEY ),
		[]
	);
	const [ local, setLocal ] = useState( null );
	const [ ready, setReady ] = useState( false );
	const loaderRef = useRef( null );
	const latestServer = useRef( server );
	latestServer.current = server;
	const containerRef = useRef( null );
	const messageRef = useRef( null );
	const buttonRef = useRef( null );
	const turnstileRef = useRef( null );
	const turnstileClient = useRef( null );
	// The shopper started the running check from the button.
	const owned = useRef( false );
	const messageId = useRef(
		'navexa-agecheck-message-' + Math.random().toString( 36 ).slice( 2 )
	);
	const turnstile = settings().turnstile || null;

	const required = !! ( server && server.required );
	const status = ( local && local.status ) || ( server && server.status ) || 'none';
	const busy = !! ( local && local.busy );

	// One loader per mount; its transport is the Store API extension update.
	useEffect( () => {
		const api = window.NavexaAgeCheck;
		const checkout = window.wc && window.wc.blocksCheckout;
		if ( ! api || ! checkout || typeof checkout.extensionCartUpdate !== 'function' ) {
			return undefined;
		}
		const isUpdating = () => {
			const store = select( CART_STORE );
			return typeof store.isCustomerDataUpdating === 'function' && store.isCustomerDataUpdating();
		};
		const transport = async ( action ) => {
			const data = { v: 1, action };
			if ( action === 'start' ) {
				await customerDataSettled( isUpdating );
				const config = settings().turnstile;
				if ( config ) {
					const factory = window.NavexaAgeCheckTurnstile;
					if ( ! turnstileClient.current && factory ) {
						turnstileClient.current = factory.create( config );
					}
					const token = turnstileClient.current
						? await turnstileClient.current.token( turnstileRef.current )
						: null;
					if ( ! token ) {
						const challenging =
							turnstileClient.current && turnstileClient.current.challenging();
						return {
							v: 1,
							status: 'refused',
							message: challenging ? t( 'challenge' ) : t( 'turnstile' ),
						};
					}
					data.turnstile = token;
				}
			}
			await checkout.extensionCartUpdate( {
				namespace: NAMESPACE,
				data,
			} );
			const cart = select( CART_STORE ).getCartData();
			const answer = cart && cart.extensions && cart.extensions[ NAMESPACE ];
			if ( ! answer || typeof answer.status !== 'string' ) {
				throw new Error( 'navexa-agecheck: no answer' );
			}
			return answer;
		};
		const loader = api.create( {
			transport,
			status: ( latestServer.current && latestServer.current.status ) || 'none',
			openingText: t( 'opening' ),
			onChange: ( state ) => setLocal( state ),
		} );
		loaderRef.current = loader;
		setReady( true );
		if ( latestServer.current && latestServer.current.status === 'pending' ) {
			// Back from a full-page redirect, or a reload mid-check.
			loader.refresh();
		}
		return () => {
			loader.destroy();
			loaderRef.current = null;
			setReady( false );
		};
	}, [] );

	// A cart update the loader did not cause (an address change) is the newer truth.
	useEffect( () => {
		if ( local && ! local.busy && server && server.status !== local.status && server.status !== 'pending' ) {
			setLocal( null );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ server && server.status ] );

	// Block Place order until the server says passed.
	useEffect( () => {
		if ( required && status !== 'passed' ) {
			setValidationErrors( {
				[ VALIDATION_KEY ]: {
					message: t( 'required' ),
					hidden: true,
				},
			} );
		} else {
			clearValidationError( VALIDATION_KEY );
		}
	}, [ required, status, setValidationErrors, clearValidationError ] );

	useEffect( () => () => clearValidationError( VALIDATION_KEY ), [ clearValidationError ] );

	// When a check the shopper started ends, put the focus back on the control.
	useEffect( () => {
		if ( busy || status === 'pending' || ! owned.current ) {
			return;
		}
		owned.current = false;
		const active = document.activeElement;
		const lost = ! active || active === document.body;
		const inside = containerRef.current && containerRef.current.contains( active );
		if ( ! lost && ! inside ) {
			return; // The shopper moved on: leave the focus alone.
		}
		const challenging =
			turnstileClient.current && turnstileClient.current.challenging();
		let target = buttonRef.current;
		if ( status === 'passed' || ! buttonRef.current ) {
			target = messageRef.current;
		} else if ( challenging && turnstileRef.current ) {
			target = turnstileRef.current;
		}
		if ( target && typeof target.focus === 'function' ) {
			target.focus();
			// Right after a popup closed, Firefox ignores focus() for a moment:
			// try again (10 x 100 ms) unless the shopper moved the focus.
			let tries = 0;
			const retry = () => {
				const now = document.activeElement;
				const mine =
					! now ||
					now === document.body ||
					( containerRef.current && containerRef.current.contains( now ) );
				if ( now === target || ++tries > 10 || ! mine || ! target.isConnected ) {
					return;
				}
				target.focus();
				window.setTimeout( retry, 100 );
			};
			window.setTimeout( retry, 100 );
		}
	}, [ busy, status ] );

	const onClick = useCallback( () => {
		if ( loaderRef.current && ! loaderRef.current.getState().busy ) {
			owned.current = true;
			loaderRef.current.verify();
		}
	}, [] );

	if ( ! required ) {
		return null;
	}

	let message = ( local && local.message ) || ( server && server.message ) || '';
	if ( ! message ) {
		if ( local && local.reason === 'closed' ) {
			message = t( 'closed' );
		} else if ( busy && status !== 'pending' ) {
			message = t( 'checking' );
		} else {
			message = t( status === 'none' || status === 'refused' ? 'prompt' : status );
		}
	}
	const showError = validationError && ! validationError.hidden;

	return (
		<div
			ref={ containerRef }
			className={ 'navexa-agecheck wc-block-components-checkout-step__container' + ( showError ? ' has-error' : '' ) }
			data-status={ status }
			aria-busy={ busy ? 'true' : 'false' }
		>
			<p
				ref={ messageRef }
				id={ messageId.current }
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
					ref={ turnstileRef }
					role="group"
					aria-label={ t( 'security' ) }
					tabIndex={ -1 }
				/>
			) }
			{ status !== 'passed' && (
				<button
					ref={ buttonRef }
					type="button"
					className="wc-block-components-button wp-element-button contained navexa-agecheck__verify"
					onClick={ onClick }
					disabled={ ! ready }
					aria-disabled={ busy ? 'true' : 'false' }
					aria-describedby={ messageId.current }
				>
					<span className="wc-block-components-button__text">
						{ busy ? t( 'verifying' ) : t( 'verify' ) }
					</span>
				</button>
			) }
			{ showError && (
				<div className="wc-block-components-validation-error" role="alert">
					<p>{ validationError.message }</p>
				</div>
			) }
		</div>
	);
}
