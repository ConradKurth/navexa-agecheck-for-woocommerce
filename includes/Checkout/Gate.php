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
 * it was bound with; release still needs GetClearance CLEARED.
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
	 * Pass classes that never clear an order.
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
		self::$admitted = null;
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
			return self::refuse( self::REFUSE_VERIFY_FIRST );
		}

		try {
			$body = Client::for_key( Client::KEY_FULFILMENT )->call( 'GetVerification', array( 'id' => $vid ) );
		} catch ( ConnectError $e ) {
			if ( 'not_found' === $e->connect_code() ) {
				Session::forget_verification();
				return self::refuse( self::REFUSE_VERIFY_FIRST );
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
	 * Forgets the request's admission (tests, and long-running processes).
	 */
	public static function reset(): void {
		self::$admitted = null;
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
