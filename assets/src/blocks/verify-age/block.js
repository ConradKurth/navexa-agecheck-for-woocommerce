/**
 * The Verify age button for the checkout block.
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
			if ( action === 'start' ) {
				await customerDataSettled( isUpdating );
			}
			await checkout.extensionCartUpdate( {
				namespace: NAMESPACE,
				data: { v: 1, action },
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

	const onClick = useCallback( () => {
		if ( loaderRef.current ) {
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
			className={ 'navexa-agecheck wc-block-components-checkout-step__container' + ( showError ? ' has-error' : '' ) }
			data-status={ status }
		>
			<p className="navexa-agecheck__message" role="status" aria-live="polite">
				{ message }
			</p>
			{ status !== 'passed' && (
				<button
					type="button"
					className="wc-block-components-button wp-element-button contained navexa-agecheck__verify"
					onClick={ onClick }
					disabled={ busy || ! ready }
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
