<?php
/**
 * The shopper's pre-payment AgeCheck state, kept in the WooCommerce session.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Checkout;

use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\GuardToken;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side state of a shopper's checkout age check. The browser never sees
 * any of it: it holds no verification id and no guard.
 *
 * - sid: a random 128-bit id for this shopper's checkout, created at the first
 *   start. WooCommerce moves session data to the user's session when a guest
 *   signs in, so the sid (and a guard) survive the sign-in.
 * - counter: the n in the CreateVerification idempotency key
 *   {install}:{sid}:{ship-to MAC}:{n}. It only grows, so a new verification
 *   for the same session and ship-to (after a failure, or after an earlier
 *   one was bound to an order) never replays an old answer.
 * - record: {vid, shipto_mac, n, status} of the session's current
 *   verification: one per (sid, ship-to MAC).
 * - guard: the checkout guard token (GuardToken), minted once AgeCheck
 *   answered PASSED with a clearing pass class, and next to it the
 *   verification id alone (KEY_VID), which order binding reads.
 *
 * Order binding (Binding::bind_checkout()) binds KEY_VID once an order is
 * placed, and only when the gate admitted that same verification in the
 * request (Gate::admitted_verification_id()): KEY_VID outlives the guard's
 * TTL. It records it on the order, then calls forget_verification(): the
 * id, the guard and the record leave the session, so the next checkout
 * needs its own check.
 */
final class Session {

	const KEY_SID     = 'navexa_agecheck_sid';
	const KEY_COUNTER = 'navexa_agecheck_counter';
	const KEY_RECORD  = 'navexa_agecheck_verification';
	const KEY_GUARD   = 'navexa_agecheck_guard';

	/**
	 * The guarded verification's id on its own, set and cleared with the
	 * guard: what order binding (Binding::bind_checkout()) reads.
	 */
	const KEY_VID = 'navexa_agecheck_vid';

	/**
	 * The outage fallback (TAX-1841): {shipto_mac, until}. Set when this
	 * session's start saw AgeCheck down and the store takes and holds orders
	 * then; while it is valid for the ship-to, the gate admits an order
	 * without a check, and that order verifies by link after payment. It
	 * expires on its own (FALLBACK_TTL), so a payment retried within it is
	 * admitted again: neither binding nor forget_verification() clears it.
	 */
	const KEY_FALLBACK = 'navexa_agecheck_fallback';

	/**
	 * Seconds a fallback stays valid after the outage was seen.
	 */
	const FALLBACK_TTL = 900;

	const STATUS_PENDING = 'pending';
	const STATUS_PASSED  = 'passed';
	const STATUS_FAILED  = 'failed';

	/**
	 * The WooCommerce session, or null outside a shopper request.
	 *
	 * @return \WC_Session|null
	 */
	public static function wc_session() {
		$wc      = function_exists( 'WC' ) ? WC() : null;
		$session = is_object( $wc ) ? $wc->session : null;
		return $session instanceof \WC_Session ? $session : null;
	}

	/**
	 * The session cart, or null where WooCommerce has not loaded one.
	 */
	public static function cart(): ?\WC_Cart {
		$wc   = function_exists( 'WC' ) ? WC() : null;
		$cart = is_object( $wc ) ? $wc->cart : null;
		return $cart instanceof \WC_Cart ? $cart : null;
	}

	/**
	 * The session customer, or null where WooCommerce has not loaded one.
	 */
	public static function customer(): ?\WC_Customer {
		$wc       = function_exists( 'WC' ) ? WC() : null;
		$customer = is_object( $wc ) ? $wc->customer : null;
		return $customer instanceof \WC_Customer ? $customer : null;
	}

	/**
	 * The session's sid; '' when there is none and $create is false, or when
	 * there is no WooCommerce session.
	 *
	 * @param bool $create Create one when missing.
	 */
	public static function sid( bool $create = false ): string {
		$session = self::wc_session();
		if ( null === $session ) {
			return '';
		}
		$sid = $session->get( self::KEY_SID );
		if ( is_string( $sid ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $sid ) ) {
			return $sid;
		}
		if ( ! $create ) {
			return '';
		}
		$sid = bin2hex( random_bytes( 16 ) );
		$session->set( self::KEY_SID, $sid );
		return $sid;
	}

	/**
	 * Who the per-session rate limit counts: WooCommerce's customer id for
	 * the session (the guest's t_ id from the session cookie, or the user
	 * id), which every request of the session shares from its first one. The
	 * sid would not do: concurrent first starts each mint their own. ''
	 * without a session.
	 */
	public static function rate_subject(): string {
		$session = self::wc_session();
		if ( null === $session ) {
			return '';
		}
		$customer = (string) $session->get_customer_id();
		if ( '' !== $customer ) {
			return 'customer:' . $customer;
		}
		$sid = self::sid();
		return '' === $sid ? '' : 'sid:' . $sid;
	}

	/**
	 * Takes the next n for a CreateVerification idempotency key.
	 */
	public static function next_counter(): int {
		$session = self::wc_session();
		if ( null === $session ) {
			return 0;
		}
		$n = (int) $session->get( self::KEY_COUNTER, 0 );
		$session->set( self::KEY_COUNTER, $n + 1 );
		return $n;
	}

	/**
	 * The session's verification record, or null.
	 *
	 * @return array{vid: string, shipto_mac: string, n: int, status: string}|null
	 */
	public static function record(): ?array {
		$session = self::wc_session();
		$record  = null === $session ? null : $session->get( self::KEY_RECORD );
		if (
			! is_array( $record )
			|| ! isset( $record['vid'], $record['shipto_mac'], $record['status'] )
			|| ! is_string( $record['vid'] ) || ! Binding::is_verification_id( $record['vid'] )
			|| ! is_string( $record['shipto_mac'] ) || ! is_string( $record['status'] )
		) {
			return null;
		}
		return array(
			'vid'        => $record['vid'],
			'shipto_mac' => $record['shipto_mac'],
			'n'          => isset( $record['n'] ) ? (int) $record['n'] : 0,
			'status'     => $record['status'],
		);
	}

	/**
	 * Stores the session's verification record. A new verification drops the
	 * guard of the previous one.
	 *
	 * @param string $vid        Verification id.
	 * @param string $shipto_mac ShipTo::mac() it was created for.
	 * @param int    $n          Idempotency counter it was created with.
	 * @param string $status     STATUS_*.
	 */
	public static function set_record( string $vid, string $shipto_mac, int $n, string $status ): void {
		$session = self::wc_session();
		if ( null === $session ) {
			return;
		}
		$previous = self::record();
		if ( null !== $previous && $previous['vid'] !== $vid ) {
			$session->set( self::KEY_GUARD, null );
			$session->set( self::KEY_VID, null );
		}
		$session->set(
			self::KEY_RECORD,
			array(
				'vid'        => $vid,
				'shipto_mac' => $shipto_mac,
				'n'          => $n,
				'status'     => $status,
			)
		);
	}

	/**
	 * Stores a checkout guard and the id of the verification it admits; ''
	 * clears both.
	 *
	 * @param string $token GuardToken::mint_checkout_guard().
	 * @param string $vid   The verification id the guard carries.
	 */
	public static function set_guard( string $token, string $vid = '' ): void {
		$session = self::wc_session();
		if ( null === $session ) {
			return;
		}
		$clear = '' === $token || '' === $vid;
		$session->set( self::KEY_GUARD, $clear ? null : $token );
		$session->set( self::KEY_VID, $clear ? null : $vid );
	}

	/**
	 * The verification id of the session's checkout guard when it is valid
	 * for this session and the ship-to with MAC $shipto_mac; null otherwise.
	 *
	 * @param string $shipto_mac ShipTo::mac() of the ship-to being checked out.
	 */
	public static function guarded_verification_id( string $shipto_mac ): ?string {
		$session = self::wc_session();
		$sid     = self::sid();
		if ( null === $session || '' === $sid || '' === $shipto_mac ) {
			return null;
		}
		$token = $session->get( self::KEY_GUARD );
		return is_string( $token ) ? GuardToken::read_checkout_guard( $token, $sid, $shipto_mac ) : null;
	}

	/**
	 * Drops the verification record and the guard (the verification was bound
	 * to an order, or no longer applies). The sid and the counter stay.
	 */
	public static function forget_verification(): void {
		$session = self::wc_session();
		if ( null === $session ) {
			return;
		}
		$session->set( self::KEY_RECORD, null );
		$session->set( self::KEY_GUARD, null );
		$session->set( self::KEY_VID, null );
	}

	/**
	 * Whether the session's record is a failed check, for any ship-to: no
	 * fallback is ever set or honoured then, so an outage never opens a
	 * retry after a failure (owner decision #22). (The gate also refuses a
	 * record for another ship-to before it reads the fallback.)
	 */
	public static function has_failed(): bool {
		$record = self::record();
		return null !== $record && self::STATUS_FAILED === $record['status'];
	}

	/**
	 * Records an outage fallback for ship-to MAC $shipto_mac, valid for
	 * FALLBACK_TTL. Refused (false) while the session's record is a failed
	 * check (has_failed()).
	 *
	 * @param string   $shipto_mac ShipTo::mac().
	 * @param int|null $now        Unix time; null for time().
	 */
	public static function set_fallback( string $shipto_mac, ?int $now = null ): bool {
		$session = self::wc_session();
		if ( null === $session || '' === $shipto_mac || self::has_failed() ) {
			return false;
		}
		$session->set(
			self::KEY_FALLBACK,
			array(
				'shipto_mac' => $shipto_mac,
				'until'      => ( null === $now ? time() : $now ) + self::FALLBACK_TTL,
			)
		);
		return true;
	}

	/**
	 * Whether a fallback is valid for ship-to MAC $shipto_mac: set for it,
	 * not expired, and the session's record is not a failed check.
	 *
	 * @param string   $shipto_mac ShipTo::mac().
	 * @param int|null $now        Unix time; null for time().
	 */
	public static function fallback_for( string $shipto_mac, ?int $now = null ): bool {
		$session  = self::wc_session();
		$fallback = null === $session ? null : $session->get( self::KEY_FALLBACK );
		if ( '' === $shipto_mac || ! is_array( $fallback ) || ! isset( $fallback['shipto_mac'], $fallback['until'] ) || ! is_string( $fallback['shipto_mac'] ) ) {
			return false;
		}
		if ( (int) $fallback['until'] <= ( null === $now ? time() : $now ) || ! hash_equals( $fallback['shipto_mac'], $shipto_mac ) ) {
			return false;
		}
		return ! self::has_failed();
	}

	/**
	 * Drops the outage fallback.
	 */
	public static function forget_fallback(): void {
		$session = self::wc_session();
		if ( null !== $session ) {
			$session->set( self::KEY_FALLBACK, null );
		}
	}

	/**
	 * Drops the verification record and the guard only when they are those
	 * of verification $vid (e.g. the one an order is bound to): a check the
	 * shopper passed since, for another ship-to, stays.
	 *
	 * @param string $vid Verification id.
	 */
	public static function forget_verification_of( string $vid ): void {
		$session = self::wc_session();
		if ( null === $session || '' === $vid ) {
			return;
		}
		$record = self::record();
		if ( ( null !== $record && $record['vid'] === $vid ) || $vid === $session->get( self::KEY_VID ) ) {
			self::forget_verification();
		}
	}
}
