<?php
/**
 * Pre-payment admission: the checks classic and block checkout share.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Checkout;

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\ShipTo;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a pre-payment checkout may place its order. The server
 * decides: the session's checkout guard must be valid for this session and
 * this ship-to, and GetVerification (fulfilment key) must answer PASSED with
 * a clearing pass class in the key's mode. Every error refuses.
 *
 * A resumed order that is already bound to a verification (a failed payment
 * being retried) is admitted without a guard only when its ship-to is the one
 * it was bound with; release still needs GetClearance CLEARED. Which order a
 * retry resumes depends on the checkout placing it: bound_awaiting_order()
 * for the classic checkout, bound_draft_order() for the Store API
 * (resumable_bound_order() picks by route). Start::state() reports such an
 * order as passed for that route only, so the shopper is never sent through
 * a second check that nothing would use, and never told passed for an order
 * the checkout they are on will not resume.
 *
 * Outage fallback (TAX-1841, owner decision #21; off unless the store chose
 * it, Options::holds_on_outage()): an order is also admitted without a check
 * when this session's own start saw AgeCheck down for this ship-to within
 * Session::FALLBACK_TTL (a server-observed outage, never anything the
 * browser sends), and never after a failed check; it binds nothing and
 * verifies by link after payment. A guarded (passed) check whose
 * GetVerification gets no answer is admitted and bound as usual. Every
 * other refusal is unchanged.
 *
 * Which carts and orders need a check is Requirement's job
 * (Requirement::cart_required() for the session cart,
 * Requirement::evaluate() for the order a Store API checkout is placing,
 * which records nothing until the order is placed), the same rules the hold
 * path applies. Local pickup orders skip AgeCheck entirely (owner decision
 * 2026-11-14: staff check ID at the counter), by Requirement's one pickup
 * list.
 */
final class Gate {

	const REFUSE_VERIFY_FIRST    = 'verify_first';
	const REFUSE_SHIP_TO_CHANGED = 'ship_to_changed';
	const REFUSE_US_ONLY         = 'us_only';
	const REFUSE_NO_ADDRESS      = 'no_address';
	const REFUSE_UNAVAILABLE     = 'unavailable';
	const REFUSE_FAILED          = 'failed';

	/**
	 * A resumed bound order was dropped because its ship-to changed, and the
	 * session already holds a passed check for the new ship-to: placing the
	 * order again creates a new order that check admits.
	 */
	const REFUSE_PLACE_AGAIN = 'place_again';

	/**
	 * The checkout that will place the order (resumable_bound_order()): the
	 * classic checkout (?wc-ajax=checkout), or the Store API (the checkout
	 * block, express buttons).
	 */
	const ROUTE_CLASSIC   = 'classic';
	const ROUTE_STORE_API = 'store_api';

	/**
	 * Pass classes that never clear an order. Every other class admits:
	 * DB_VERIFIED, DOC_ONLY, MERCHANT_OVERRIDE, DATA_VERIFIED and REUSED
	 * (a returning customer's per-store reuse, E12a) clear by the proto, and
	 * a class added later admits too, because the hold still waits for
	 * GetClearance CLEARED before the order ships.
	 */
	const NON_CLEARING_PASS_CLASSES = array( 'PASS_CLASS_UNSPECIFIED', 'PASS_CLASS_DB_UNATTESTED' );

	/**
	 * VerificationStatus numbers (a Connect JSON client may send them).
	 */
	const STATUS_NUMBERS = array(
		1 => 'CREATED',
		2 => 'DB_CHECKING',
		3 => 'PASSED',
		4 => 'NEEDS_DOC',
		5 => 'DOC_PENDING',
		6 => 'HELD',
		7 => 'FAILED',
		8 => 'AWAITING_IDENTITY',
	);

	/**
	 * PassClass numbers that matter here.
	 */
	const PASS_CLASS_NUMBERS = array(
		0 => 'UNSPECIFIED',
		2 => 'DB_UNATTESTED',
	);

	/**
	 * Verification id admitted in this request, for order binding.
	 *
	 * @var string|null
	 */
	private static $admitted = null;

	/**
	 * Whether the last check() in this request admitted the order through
	 * the outage fallback (no verification), for order binding.
	 *
	 * @var bool
	 */
	private static $admitted_fallback = false;

	/**
	 * Whether shoppers verify before payment (Options::MODE_PRE_PAYMENT).
	 * In accept-and-hold mode checkout is never gated here: the hold is.
	 */
	public static function enforces(): bool {
		return Options::MODE_PRE_PAYMENT === Options::mode();
	}

	/**
	 * Whether the session cart needs an age check (Requirement::cart_required()).
	 * False without a cart.
	 */
	public static function cart_required(): bool {
		$cart = Session::cart();
		return null !== $cart && Requirement::cart_required( $cart );
	}

	/**
	 * Decides a checkout.
	 *
	 * @param ShipTo|null    $ship_to The checkout's ship-to; null when incomplete or invalid.
	 * @param string         $country Its country as entered (for the US-only copy when incomplete).
	 * @param \WC_Order|null $bound   The order being resumed when it is already bound (Meta::VERIFICATION_ID set), else null.
	 * @return array{code: string, message: string, clear_resumable: bool}|null Null admits; otherwise the refusal.
	 */
	public static function check( ?ShipTo $ship_to, string $country, ?\WC_Order $bound = null ): ?array {
		self::$admitted          = null;
		self::$admitted_fallback = false;
		if ( null === $ship_to ) {
			return self::refuse( '' !== $country && ! ShipTo::is_accepted_country( $country ) ? self::REFUSE_US_ONLY : self::REFUSE_NO_ADDRESS );
		}
		if ( ! $ship_to->is_accepted() ) {
			return self::refuse( self::REFUSE_US_ONLY );
		}
		$mac = $ship_to->mac();
		if ( '' === $mac || Install::is_paused() ) {
			Client::log( 'error', 'agecheck checkout refused reason=' . ( '' === $mac ? 'no_guard_secret' : 'install_unconfirmed' ) );
			return self::refuse( self::REFUSE_UNAVAILABLE );
		}

		if ( null !== $bound ) {
			$bound_vid = self::bound_verification_id( $bound );
			if ( self::is_bound_ship_to( $bound, $ship_to ) ) {
				self::$admitted = '' !== $bound_vid ? $bound_vid : null;
				return null;
			}
			Client::log( 'info', sprintf( 'agecheck checkout refused reason=bound_ship_to_changed order=%d', $bound->get_id() ) );
			// The caller drops the bound order. A check the shopper already
			// passed for the new ship-to stays and admits the next attempt.
			$guarded = Session::guarded_verification_id( $mac );
			return self::refuse( null !== $guarded && $guarded !== $bound_vid ? self::REFUSE_PLACE_AGAIN : self::REFUSE_SHIP_TO_CHANGED, true );
		}

		$vid = Session::guarded_verification_id( $mac );
		if ( null === $vid ) {
			$record = Session::record();
			if ( null !== $record && ! hash_equals( $record['shipto_mac'], $mac ) ) {
				return self::refuse( self::REFUSE_SHIP_TO_CHANGED );
			}
			if ( null !== $record && Session::STATUS_FAILED === $record['status'] ) {
				return self::refuse( self::REFUSE_FAILED );
			}
			// The outage fallback (TAX-1841): this session's own start saw
			// AgeCheck down within Session::FALLBACK_TTL and the store takes
			// and holds orders then. The order binds nothing and verifies by
			// link after payment (Binding). Never after a failed check
			// (Session::fallback_for() refuses then too).
			if ( Options::holds_on_outage() && Session::fallback_for( $mac ) ) {
				self::$admitted_fallback = true;
				Client::log( 'warning', 'agecheck checkout admitted reason=outage_fallback' );
				return null;
			}
			return self::refuse( self::REFUSE_VERIFY_FIRST );
		}

		try {
			$body = Client::for_key( Client::KEY_FULFILMENT )->call( 'GetVerification', array( 'id' => $vid ) );
		} catch ( ConnectError $e ) {
			if ( 'not_found' === $e->connect_code() ) {
				Session::forget_verification();
				return self::refuse( self::REFUSE_VERIFY_FIRST );
			}
			if ( $e->is_outage() && Options::holds_on_outage() ) {
				// The guard is minted only on a pass this session saw, so the
				// check is already passed: bind it as usual. AttachOrder then
				// fails and stays pending (ATTACH_PENDING) for the release
				// run and the sweep: no second check, no second bill. Release
				// still waits for GetClearance CLEARED.
				self::$admitted = $vid;
				Client::log( 'warning', 'agecheck checkout admitted reason=outage_guarded vid=' . $vid );
				return null;
			}
			return self::refuse( self::REFUSE_UNAVAILABLE );
		}
		$verification = isset( $body['verification'] ) && is_array( $body['verification'] ) ? $body['verification'] : array();
		$verdict      = self::verdict( $verification, Client::KEY_FULFILMENT );
		if ( 'passed' !== $verdict ) {
			Client::log( 'warning', sprintf( 'agecheck checkout refused vid=%s verdict=%s', $vid, $verdict ) );
			if ( 'failed' === $verdict ) {
				return self::refuse( self::REFUSE_FAILED );
			}
			return self::refuse( in_array( $verdict, array( 'mode_unknown', 'mode_mismatch' ), true ) ? self::REFUSE_UNAVAILABLE : self::REFUSE_VERIFY_FIRST );
		}
		$bound_to = isset( $verification['orderExternalId'] ) && is_string( $verification['orderExternalId'] ) ? $verification['orderExternalId'] : '';
		if ( '' !== $bound_to ) {
			// Bound to an earlier order: this checkout needs its own check.
			Session::forget_verification();
			return self::refuse( self::REFUSE_VERIFY_FIRST );
		}
		self::$admitted = $vid;
		return null;
	}

	/**
	 * Whether $ship_to is the ship-to $bound was bound with
	 * (Meta::SHIP_TO_HASH, written by Binding). False when the order holds
	 * none (fail closed).
	 *
	 * @param \WC_Order $bound   Order bound to a verification.
	 * @param ShipTo    $ship_to Ship-to.
	 */
	public static function is_bound_ship_to( \WC_Order $bound, ShipTo $ship_to ): bool {
		$bound_hash = Meta::get( $bound, Meta::SHIP_TO_HASH );
		return '' !== $bound_hash && hash_equals( $bound_hash, $ship_to->hash() );
	}

	/**
	 * The bound order a payment retry through $route would resume at
	 * $ship_to, or null.
	 *
	 * Binding binds the order to the shopper's check before payment and drops
	 * the check from the session. After a declined payment WooCommerce resumes
	 * that order for the same cart, and check() admits it at the ship-to it
	 * was bound with, without a guard. This is that order when $ship_to is its
	 * bound ship-to (is_bound_ship_to()), so the shopper's state can say
	 * passed instead of asking for a second, billed check that would never be
	 * attached. Another ship-to, another cart, or an order that was never
	 * bound: null, and the checkout needs its own check. Reads the session and
	 * the order only.
	 *
	 * Each checkout resumes only its own session key: the classic checkout
	 * order_awaiting_payment (bound_awaiting_order()), the Store API
	 * store_api_draft_order (bound_draft_order()). The keys are not kept in
	 * step: WooCommerce 11.x's Store API also writes order_awaiting_payment
	 * (9.9 does not), Blocks::validate() drops only store_api_draft_order and
	 * Classic::validate() only order_awaiting_payment. Asking the other
	 * route's key would read passed for an order this route will not place,
	 * hide the Verify age control and leave the shopper with a checkout the
	 * gate refuses. An unknown route resumes nothing.
	 *
	 * @param ShipTo $ship_to The checkout's ship-to.
	 * @param string $route   self::ROUTE_CLASSIC or self::ROUTE_STORE_API: the checkout that will place the order.
	 */
	public static function resumable_bound_order( ShipTo $ship_to, string $route ): ?\WC_Order {
		if ( self::ROUTE_CLASSIC === $route ) {
			$order = self::bound_awaiting_order();
		} elseif ( self::ROUTE_STORE_API === $route ) {
			$order = self::bound_draft_order();
		} else {
			return null;
		}
		return null !== $order && self::is_bound_ship_to( $order, $ship_to ) ? $order : null;
	}

	/**
	 * The order the classic checkout's WC_Checkout::create_order() will
	 * resume (the session's order_awaiting_payment, with the same cart hash,
	 * pending or failed), when it is already bound to a verification.
	 */
	public static function bound_awaiting_order(): ?\WC_Order {
		$order = self::session_order( 'order_awaiting_payment' );
		$cart  = Session::cart();
		if ( null === $order || null === $cart || ! $order->has_cart_hash( $cart->get_cart_hash() ) || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return null;
		}
		return '' !== self::bound_verification_id( $order ) ? $order : null;
	}

	/**
	 * The draft the Store API checkout will resume (the session's
	 * store_api_draft_order, by WooCommerce's own rule,
	 * DraftOrderTrait::is_valid_draft_order(): a checkout-draft, or an order
	 * that needs payment with the same cart hash), when it is already bound to
	 * a verification.
	 */
	public static function bound_draft_order(): ?\WC_Order {
		$order = self::session_order( 'store_api_draft_order' );
		$cart  = Session::cart();
		if ( null === $order || null === $cart ) {
			return null;
		}
		if ( ! $order->has_status( 'checkout-draft' ) && ! ( $order->needs_payment() && $order->has_cart_hash( $cart->get_cart_hash() ) ) ) {
			return null;
		}
		return '' !== self::bound_verification_id( $order ) ? $order : null;
	}

	/**
	 * The order a WooCommerce session key names, or null.
	 *
	 * @param string $key Session key (order_awaiting_payment, store_api_draft_order).
	 */
	private static function session_order( string $key ): ?\WC_Order {
		$session = Session::wc_session();
		if ( null === $session ) {
			return null;
		}
		$order_id = absint( $session->get( $key ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The verification id an order is bound to (Meta::VERIFICATION_ID), or ''.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function bound_verification_id( \WC_Order $order ): string {
		return Meta::get( $order, Meta::VERIFICATION_ID );
	}

	/**
	 * The verification id the last check() in this request admitted, for
	 * order binding (Binding binds it only when it is the session's guarded
	 * one); null when it refused or admitted nothing, and in any request that
	 * ran no check (order-pay, accept-and-hold mode).
	 */
	public static function admitted_verification_id(): ?string {
		return self::$admitted;
	}

	/**
	 * Whether the last check() in this request admitted the order through
	 * the outage fallback (TAX-1841): no verification to bind; Binding marks
	 * the order (Meta::FALLBACK) and it takes the order-bound path.
	 */
	public static function admitted_fallback(): bool {
		return self::$admitted_fallback;
	}

	/**
	 * Forgets the request's admission (tests, and long-running processes).
	 */
	public static function reset(): void {
		self::$admitted          = null;
		self::$admitted_fallback = false;
	}

	/**
	 * What a Verification message allows, for a $key_kind key:
	 * 'passed' (PASSED, a clearing pass class, livemode matching the key),
	 * 'failed', 'pending', 'not_clearing' (passed in a class that never
	 * clears), 'mode_unknown' (the key's mode could not be read) or
	 * 'mode_mismatch' (a test verification under a live key, or the reverse).
	 *
	 * @param array<string, mixed> $verification Verification (proto3 JSON).
	 * @param string               $key_kind     Client::KEY_*.
	 */
	public static function verdict( array $verification, string $key_kind ): string {
		$status = self::enum( $verification, 'status', 'VERIFICATION_STATUS_', self::STATUS_NUMBERS );
		if ( 'FAILED' === $status ) {
			return 'failed';
		}
		if ( 'PASSED' !== $status ) {
			return 'pending';
		}
		$class = self::enum( $verification, 'passClass', 'PASS_CLASS_', self::PASS_CLASS_NUMBERS );
		if ( '' === $class || in_array( 'PASS_CLASS_' . $class, self::NON_CLEARING_PASS_CLASSES, true ) ) {
			return 'not_clearing';
		}
		$info = Mode::info( $key_kind );
		if ( null === $info ) {
			return 'mode_unknown';
		}
		$livemode = isset( $verification['livemode'] ) && true === $verification['livemode'];
		return Mode::accepts_livemode( $livemode, $key_kind, $info ) ? 'passed' : 'mode_mismatch';
	}

	/**
	 * Shopper-facing copy for a refusal code (plain text: block checkout
	 * escapes it).
	 *
	 * @param string $code REFUSE_* constant.
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case self::REFUSE_SHIP_TO_CHANGED:
				return __( 'Your shipping address changed after your age check. Verify your age again for the new address.', 'navexa-agecheck-for-woocommerce' );
			case self::REFUSE_US_ONLY:
				return __( 'Age-restricted items ship only to US addresses, including US territories and APO/FPO addresses. Change the shipping address or remove those items.', 'navexa-agecheck-for-woocommerce' );
			case self::REFUSE_NO_ADDRESS:
				return __( 'Enter your full shipping address, then verify your age.', 'navexa-agecheck-for-woocommerce' );
			case self::REFUSE_UNAVAILABLE:
				return __( 'Age verification is temporarily unavailable, so this order cannot be placed right now. Please try again in a few minutes.', 'navexa-agecheck-for-woocommerce' );
			case self::REFUSE_FAILED:
				return __( 'We could not verify your age, so this order cannot be placed.', 'navexa-agecheck-for-woocommerce' );
			case self::REFUSE_PLACE_AGAIN:
				return __( 'Your shipping address changed since this order was started, so it was set aside. Your age check for the new address is still valid: place your order again.', 'navexa-agecheck-for-woocommerce' );
			default:
				return __( 'Verify your age on the checkout page before placing this order.', 'navexa-agecheck-for-woocommerce' );
		}
	}

	/**
	 * A refusal.
	 *
	 * @param string $code            REFUSE_* constant.
	 * @param bool   $clear_resumable Whether the caller must stop resuming the bound order.
	 * @return array{code: string, message: string, clear_resumable: bool}
	 */
	private static function refuse( string $code, bool $clear_resumable = false ): array {
		return array(
			'code'            => $code,
			'message'         => self::message( $code ),
			'clear_resumable' => $clear_resumable,
		);
	}

	/**
	 * An enum field's value name without $prefix ('' when absent): accepts
	 * the proto3 JSON name and the number for the values in $numbers.
	 *
	 * @param array<string, mixed> $message Message.
	 * @param string               $field   Field (proto3 JSON name).
	 * @param string               $prefix  Enum value prefix.
	 * @param array<int, string>   $numbers Known numbers => names.
	 */
	private static function enum( array $message, string $field, string $prefix, array $numbers ): string {
		$value = isset( $message[ $field ] ) ? $message[ $field ] : 0;
		if ( is_int( $value ) ) {
			return isset( $numbers[ $value ] ) ? $numbers[ $value ] : ( 0 === $value ? '' : 'NUMBER_' . $value );
		}
		if ( ! is_string( $value ) ) {
			return '';
		}
		return 0 === strpos( $value, $prefix ) ? substr( $value, strlen( $prefix ) ) : $value;
	}
}
