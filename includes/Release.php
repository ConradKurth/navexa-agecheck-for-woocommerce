<?php
/**
 * Releasing held orders that AgeCheck cleared.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * One release run for one order, under a per-order lock (Lock): bind the
 * order if it is not bound yet (Binding), have AgeCheck email the shopper a
 * link when the verification waits on them (Links; a failure there never
 * stops the GetClearance that follows), then GetClearance.
 *
 * GetClearance consumes the clearance and AgeCheck replays every answer
 * under its idempotency key for 24 hours. The key is therefore stored on the
 * order before the call and reused until an answer is recorded; a recorded
 * HOLD or DENIED rotates it, so the next run asks afresh (port of Zavro's
 * release.ts). A CLEARED answer whose response was lost comes back on the
 * next key as DENIED replayed, which means this store already spent the
 * clearance: the order is released, never denied. That holds because the
 * external id asked about is always this order's own: a run first drops
 * AgeCheck state copied from another order (Binding::forget_foreign()).
 *
 * Only an answer whose livemode matches the fulfilment key's (a live key
 * needs livemode true) is acted on, and with a test key nothing is released
 * unless wp-config.php defines NAVEXA_AGECHECK_ALLOW_TEST_RELEASE (Mode).
 * GetClearance is only asked for an order this plugin holds, so a clearance
 * is never spent on an order that will not ship.
 *
 * Runs come from Action Scheduler (on entering the hold, from the webhook,
 * the sweep, retries and the shopper's return from AgeCheck's page), from the
 * admin "check now" order action, and from the shopper's "Verify now" on an
 * order that has no verification yet (OrderVerify).
 * A retryable failure reschedules with backoff and Retry-After; a refusal
 * (bad key, missing scope, paused install) is recorded on the order and left
 * to the sweep, which asks again every six hours.
 */
final class Release {

	const HOOK  = 'navexa_agecheck_release';
	const GROUP = 'navexa-agecheck';

	const TRIGGER_HOLD    = 'hold';
	const TRIGGER_WEBHOOK = 'webhook';
	const TRIGGER_SWEEP   = 'sweep';
	const TRIGGER_RETRY   = 'retry';
	const TRIGGER_ADMIN   = 'admin';
	const TRIGGER_SHOPPER = 'shopper';

	const RELEASED  = 'released';
	const CLEARED   = 'cleared';
	const PENDING   = 'pending';
	const REVIEW    = 'review';
	const DENIED    = 'denied';
	const TEST_MODE = 'test_mode';
	const SKIPPED   = 'skipped';
	const LOCKED    = 'locked';
	const NOT_DUE   = 'not_due';

	/**
	 * Seconds a run may hold an order's lock before another may take it over.
	 */
	const LOCK_TTL = 120;

	/**
	 * Seconds before the sweep asks again about an order waiting on staff.
	 */
	const REVIEW_RECHECK = 21600;

	/**
	 * Seconds before the sweep asks again about a test-mode order.
	 */
	const TEST_RECHECK = 3600;

	/**
	 * Longest retry backoff, in seconds.
	 */
	const MAX_BACKOFF = 3600;

	/**
	 * Retries per chain; the sweep takes over after that.
	 */
	const MAX_ATTEMPTS = 8;

	/**
	 * Seconds after which a held order gets the "still waiting" note and notice.
	 */
	const STALE_AFTER = 2592000;

	/**
	 * The order's reason when the current run started: a note is added only
	 * when a run ends on a different one.
	 *
	 * @var string
	 */
	private static $reason_at_start = '';

	/**
	 * Registers the Action Scheduler callback.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_job' ), 10, 3 );
	}

	/**
	 * Queues a release run for $order_id (one pending per order and trigger).
	 *
	 * @param int    $order_id Order id.
	 * @param string $trigger  TRIGGER_*.
	 * @return bool Whether a run is queued (now or already).
	 */
	public static function enqueue( int $order_id, string $trigger ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}
		return self::queue_once( self::HOOK, array( $order_id, 0, $trigger ) );
	}

	/**
	 * Queues an async action in GROUP unless one with the same hook and args
	 * is still pending.
	 *
	 * Not as_enqueue_async_action()'s $unique flag: before Action Scheduler
	 * 4.0 (WooCommerce 9.9 bundles 3.9.2) it compares only the hook and group,
	 * so one order's pending run would silently drop every other order's. An
	 * action already running does not count: it may have asked AgeCheck
	 * before whatever queues this one. Two racing callers may both queue; the
	 * per-order lock and the stored clearance key make a second run harmless.
	 *
	 * @param string            $hook Action hook.
	 * @param array<int, mixed> $args Action args.
	 * @return bool Whether the action is queued (now or already).
	 */
	public static function queue_once( string $hook, array $args ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => self::GROUP,
					'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
					'per_page' => 1,
				),
				'ids'
			);
			if ( ! empty( $pending ) ) {
				return true;
			}
		}
		return 0 < (int) as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int|string $order_id Order id.
	 * @param int|string $attempt  Retry count of this chain.
	 * @param string     $trigger  TRIGGER_*.
	 */
	public static function run_job( $order_id, $attempt = 0, $trigger = self::TRIGGER_HOLD ): void {
		$order_id = absint( $order_id );
		$attempt  = absint( $attempt );
		try {
			$outcome = self::run( $order_id, is_string( $trigger ) ? $trigger : self::TRIGGER_HOLD );
		} catch ( ConnectError $e ) {
			if ( $e->is_retryable() ) {
				self::retry_later( $order_id, $attempt, $e->retry_after() );
			}
			return;
		}
		if ( self::LOCKED === $outcome ) {
			self::retry_later( $order_id, $attempt, 30 );
		}
	}

	/**
	 * One release run.
	 *
	 * @param int         $order_id Order id.
	 * @param string      $trigger  TRIGGER_*.
	 * @param Client|null $client   Fulfilment-key client; null for the stored key.
	 * @return string An outcome constant.
	 * @throws ConnectError A failure; is_retryable() says whether to retry.
	 */
	public static function run( int $order_id, string $trigger, ?Client $client = null ): string {
		$lock  = self::lock_name( $order_id );
		$token = Lock::acquire( $lock, self::LOCK_TTL );
		if ( '' === $token ) {
			return self::LOCKED;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
				return self::SKIPPED;
			}
			$client = null === $client ? Client::for_key( Client::KEY_FULFILMENT ) : $client;
			try {
				$outcome = self::run_locked( $order, $trigger, $client );
			} catch ( ConnectError $e ) {
				self::record_error( $order, $e );
				throw $e;
			}
			Client::log( 'info', sprintf( 'agecheck release order=%d trigger=%s outcome=%s reason=%s', $order_id, sanitize_key( $trigger ), $outcome, sanitize_key( Meta::get( $order, Meta::REASON ) ) ) );
			return $outcome;
		} finally {
			Lock::release( $lock, $token );
		}
	}

	/**
	 * The per-order lock's name (Lock), shared by everything that changes the
	 * order's AgeCheck state from outside a run (Links' staff actions).
	 *
	 * @param int $order_id Order id.
	 */
	public static function lock_name( int $order_id ): string {
		return 'navexa_agecheck_lock_' . $order_id;
	}

	/**
	 * The run, under the lock.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $trigger TRIGGER_*.
	 * @param Client    $client  Fulfilment-key client.
	 * @throws ConnectError A failure.
	 */
	private static function run_locked( \WC_Order $order, string $trigger, Client $client ): string {
		self::$reason_at_start = Meta::get( $order, Meta::REASON );
		$status                = $order->get_status( 'edit' );
		if ( Hold::STATUS !== $status ) {
			// Not held: only a checkout verification still waiting to be bound.
			if ( 'yes' !== Meta::get( $order, Meta::ATTACH_PENDING ) || ! in_array( $status, array( 'pending', 'on-hold', 'failed' ), true ) ) {
				return self::SKIPPED;
			}
			try {
				$bound = Binding::attach( $order, $client );
			} finally {
				$order->save_meta_data();
			}
			return Binding::BOUND === $bound ? self::PENDING : self::REVIEW;
		}

		if ( Binding::forget_foreign( $order ) ) {
			// Copied from another order: never ask AgeCheck under its id.
			$order->save_meta_data();
		}
		if ( self::TRIGGER_SWEEP === $trigger && ( Meta::get_int( $order, Meta::NEXT_CHECK_AT ) > time() || Meta::STATUS_DENIED === Meta::get( $order, Meta::STATUS ) ) ) {
			return self::NOT_DUE;
		}
		self::note_if_stale( $order );

		if ( Meta::is_released( $order ) ) {
			// Cleared (or overridden) earlier, but the status change did not stick.
			Hold::release( $order, __( 'AgeCheck: releasing an order that was already cleared.', 'navexa-agecheck-for-woocommerce' ) );
			return self::RELEASED;
		}
		if ( Subscriptions::needs_review( $order ) ) {
			return self::finish_review( $order, 'subscription_renewal' );
		}

		$lookup = Mode::lookup( Client::KEY_FULFILMENT );
		$info   = $lookup['info'];
		if ( null === $info ) {
			if ( in_array( $lookup['error'], ConnectError::RETRYABLE, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- codes only, never output.
				throw new ConnectError( Mode::RPC, $lookup['error'] );
			}
			return self::finish_review( $order, 'not_configured' );
		}

		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' !== $vid && ( 'yes' === Meta::get( $order, Meta::ATTACH_PENDING ) || 0 === Meta::get_int( $order, Meta::BOUND_AT ) ) ) {
			$bound = Binding::attach( $order, $client );
			if ( Binding::REVIEW === $bound ) {
				return self::finish_review( $order, Meta::get( $order, Meta::REASON ) );
			}
			if ( Binding::DENIED === $bound ) {
				return self::deny( $order, Meta::get( $order, Meta::REASON ), $info );
			}
		}
		if ( '' === Meta::get( $order, Meta::VERIFICATION_ID ) ) {
			$created = Binding::create( $order, $client );
			$order->save_meta_data();
			if ( Binding::REVIEW === $created ) {
				return self::finish_review( $order, Meta::get( $order, Meta::REASON ) );
			}
			if ( Binding::DENIED === $created ) {
				return self::deny( $order, Meta::get( $order, Meta::REASON ), $info );
			}
		}
		$order->save_meta_data();

		$link_error = null;
		if ( in_array( Meta::get( $order, Meta::VERIFICATION_STATUS ), Links::HOSTED_STATES, true ) ) {
			try {
				Links::send( $order, $client, $info );
			} catch ( ConnectError $e ) {
				// The emailed link is a side path: it never keeps GetClearance
				// from answering (the shopper may have passed from the order
				// page). Its key stays on the order for the replay.
				$link_error = $e;
			}
			$order->save_meta_data();
		}

		if ( ! Mode::may_auto_release( $info ) ) {
			if ( ! self::record_test_verification( $order, $client ) ) {
				Meta::set( $order, Meta::REASON, 'test_mode' );
				Meta::set( $order, Meta::NEXT_CHECK_AT, time() + self::TEST_RECHECK );
			}
			$order->save_meta_data();
			$outcome = self::TEST_MODE;
		} else {
			$outcome = self::clear( $order, $client, $info );
		}
		if ( null !== $link_error && in_array( $outcome, array( self::PENDING, self::TEST_MODE ), true ) ) {
			// Still waiting on the shopper: the link's failure counts as the
			// run's (a retry for a retryable one, staff review otherwise).
			throw $link_error;
		}
		return $outcome;
	}

	/**
	 * With a test key nothing asks GetClearance, so nothing else would update
	 * the order's verification: GetVerification (a read, which spends
	 * nothing) records its status and pass class for the panel, and a note
	 * says when the shopper's check has passed or failed. It never releases or
	 * denies. A failure the shopper may still retry (DocRetry::defer(), the
	 * same window as a live key's) is recorded as such, so the order page
	 * offers Try again with a test key too; its note promises no refund. When
	 * that retry closes with the check still failed, the "cancel it yourself"
	 * note follows once. Panel-only: a failure is logged and the run goes on.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 * @return bool Whether the order now waits on a retry (reason and next
	 *              check recorded by DocRetry::defer()).
	 */
	private static function record_test_verification( \WC_Order $order, Client $client ): bool {
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( '' === $vid ) {
			return false;
		}
		$before = Meta::get( $order, Meta::VERIFICATION_STATUS );
		try {
			$answer = $client->call( 'GetVerification', array( 'id' => $vid ) );
		} catch ( ConnectError $e ) {
			Client::log( 'warning', sprintf( 'agecheck release order=%d vid=%s test verification read failed code=%s', $order->get_id(), $vid, $e->connect_code() ) );
			return false;
		}
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		if ( array() === $verification ) {
			return false;
		}
		// Waiting on a retry before this run: a reason the caller has not
		// replaced yet, or AgeCheck's last answer that it may be retried.
		$was_waiting = DocRetry::REASON === Meta::get( $order, Meta::REASON ) || 'yes' === Meta::get( $order, Meta::RETRY_OPEN );
		$status      = Binding::record_verification( $order, $verification );
		$retry       = false;
		$closed      = false;
		if ( 'failed' === $status && DocRetry::opted_in( $order ) ) {
			try {
				$retry  = DocRetry::defer( $order, $verification, $client, false );
				$closed = ! $retry && $was_waiting;
			} catch ( ConnectError $e ) {
				Client::log( 'warning', sprintf( 'agecheck release order=%d vid=%s test retry check failed code=%s', $order->get_id(), $vid, $e->connect_code() ) );
			}
		}
		$order->save_meta_data();
		// A retry that closed with the status still failed gets the failure's
		// note below, once: the caller then replaces the reason, and defer()
		// has cleared RETRY_OPEN.
		if ( $status === $before && ! $closed ) {
			return $retry;
		}
		if ( 'passed' === $status ) {
			Meta::note( $order, __( 'AgeCheck: the shopper\'s age check passed. Test keys never release orders: to try the rest of the flow, release it with "AgeCheck: release without age check".', 'navexa-agecheck-for-woocommerce' ) );
		} elseif ( 'failed' === $status && ! $retry ) {
			Meta::note( $order, __( 'AgeCheck: the shopper\'s age check failed. Test keys never deny or refund orders: cancel it yourself.', 'navexa-agecheck-for-woocommerce' ) );
		}
		return $retry;
	}

	/**
	 * GetClearance and what follows from its answer.
	 *
	 * @param \WC_Order       $order   Order.
	 * @param Client          $client  Fulfilment-key client.
	 * @param IntegrationInfo $info    The fulfilment key's info.
	 * @param bool            $retried Whether this run asked already and asks
	 *                                 again on a fresh key.
	 * @throws ConnectError A failure.
	 */
	private static function clear( \WC_Order $order, Client $client, IntegrationInfo $info, bool $retried = false ): string {
		$external = Binding::owned_external_id( $order );
		$ship_to  = OrderAddress::ship_to( $order );
		if ( '' === $external || null === $ship_to ) {
			return self::finish_review( $order, '' === $external ? 'no_binding' : 'no_ship_to' );
		}
		$key = Meta::get( $order, Meta::CLEARANCE_KEY );
		if ( '' === $key ) {
			$key = self::new_clearance_key( $external );
			Meta::set( $order, Meta::CLEARANCE_KEY, $key );
			$order->save_meta_data();
		}

		try {
			$answer = $client->call(
				'GetClearance',
				array(
					'idempotencyKey'  => $key,
					'orderExternalId' => $external,
					'shipTo'          => $ship_to,
				)
			);
		} catch ( ConnectError $e ) {
			if ( '' === $e->local_reason() && 'invalid_argument' === $e->connect_code() ) {
				// The stored key was used with another request (the ship-to
				// changed mid-poll) or AgeCheck refuses the request: staff look.
				return self::finish_review( $order, 'clearance_rejected' );
			}
			throw $e;
		}

		$decision = self::decision( $answer );
		$reason   = isset( $answer['reason'] ) && is_string( $answer['reason'] ) ? sanitize_key( $answer['reason'] ) : '';
		$livemode = isset( $answer['livemode'] ) && true === $answer['livemode'];
		if ( ! Mode::accepts_livemode( $livemode, Client::KEY_FULFILMENT, $info ) ) {
			// Never ship, deny or rotate on an answer from the other mode.
			return self::finish_review( $order, 'livemode_mismatch' );
		}

		switch ( $decision ) {
			case 'CLEARED':
				return self::cleared( $order, false, $client );
			case 'HOLD':
				self::rotate_key( $order, $external );
				if ( 'unattested' === $reason ) {
					return self::finish_review( $order, 'unattested' );
				}
				Meta::set( $order, Meta::REASON, '' === $reason ? 'pending' : $reason );
				// Polled every sweep, emailed or not: a shopper who was not
				// emailed a link can still pass from the order page (Verify now),
				// and AgeCheck's page only links back to the store.
				Meta::set( $order, Meta::NEXT_CHECK_AT, '' );
				if ( DocRetry::waiting( $order ) && 'failed' === Meta::get( $order, Meta::VERIFICATION_STATUS ) ) {
					// A failed check now on HOLD: the shopper is trying again
					// (TAX-1838). Record where it stands for the panel and the
					// order page (a read).
					self::refresh_retry( $order, $client );
				}
				$order->save_meta_data();
				return self::PENDING;
			case 'DENIED':
				if ( 'replayed' === $reason ) {
					return self::cleared( $order, true, $client );
				}
				self::rotate_key( $order, $external );
				if ( 'not_verified' === $reason ) {
					return self::not_verified( $order, $client, $info, $retried );
				}
				return self::finish_review( $order, '' === $reason ? 'denied' : $reason );
			default:
				return self::finish_review( $order, 'unexpected_decision' );
		}
	}

	/**
	 * DENIED not_verified: the verification failed (deny), or it passed but its
	 * identity data was purged before the order cleared (verify again).
	 *
	 * A pass next to DENIED not_verified is first asked about once more on the
	 * fresh key rotate_key() saved: AgeCheck replays a key's answer for 24
	 * hours, so a DENIED recorded under the stored key before the shopper
	 * passed a retry comes back unchanged (1.1.0 could leave such a key on the
	 * order). Only a DENIED not_verified on a fresh key means the pass was
	 * purged. A denied or refunded order is never verified again on its own:
	 * staff decide.
	 *
	 * @param \WC_Order       $order   Order.
	 * @param Client          $client  Client.
	 * @param IntegrationInfo $info    Fulfilment key info.
	 * @param bool            $retried Whether the answer came on a fresh key.
	 * @throws ConnectError When AgeCheck cannot answer.
	 */
	private static function not_verified( \WC_Order $order, Client $client, IntegrationInfo $info, bool $retried ): string {
		$answer       = $client->call( 'GetVerification', array( 'id' => Meta::get( $order, Meta::VERIFICATION_ID ) ) );
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		$status       = Binding::record_verification( $order, $verification );
		if ( 'failed' === $status ) {
			if ( DocRetry::defer( $order, $verification, $client ) ) {
				// TAX-1838: the shopper may still try again; the refund waits.
				$order->save_meta_data();
				return self::PENDING;
			}
			return self::deny( $order, 'not_verified', $info );
		}
		if ( 'passed' === $status ) {
			if ( ! $retried ) {
				Client::log( 'info', sprintf( 'agecheck release order=%d denied next to a pass: asking again on a fresh key', $order->get_id() ) );
				return self::clear( $order, $client, $info, true );
			}
			if ( Meta::is_denied( $order ) ) {
				return self::finish_review( $order, 'passed_after_denial' );
			}
			Binding::reverify( $order, 'verification_purged' );
			$order->save_meta_data();
			self::enqueue( $order->get_id(), self::TRIGGER_RETRY );
			return self::PENDING;
		}
		Meta::set( $order, Meta::REASON, 'pending' );
		$order->save_meta_data();
		return self::PENDING;
	}

	/**
	 * Records the status of a verification that failed and went back to the
	 * ID check (TAX-1838), with a note. A read: a failure is logged and the
	 * run goes on.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 */
	private static function refresh_retry( \WC_Order $order, Client $client ): void {
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		try {
			$answer = $client->call( 'GetVerification', array( 'id' => $vid ) );
		} catch ( ConnectError $e ) {
			Client::log( 'warning', sprintf( 'agecheck release order=%d vid=%s retry read failed code=%s', $order->get_id(), $vid, $e->connect_code() ) );
			return;
		}
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		if ( array() === $verification ) {
			return;
		}
		if ( 'failed' !== Binding::record_verification( $order, $verification ) ) {
			Meta::note( $order, __( 'AgeCheck: the shopper is trying the age check again. Do not ship yet.', 'navexa-agecheck-for-woocommerce' ) );
		}
	}

	/**
	 * Records the clearance and releases the order.
	 *
	 * @param \WC_Order $order    Order.
	 * @param bool      $replayed Whether the answer was DENIED replayed.
	 * @param Client    $client   Fulfilment-key client.
	 * @throws ConnectError When a denied order's verification cannot be read.
	 */
	private static function cleared( \WC_Order $order, bool $replayed, Client $client ): string {
		if ( Meta::is_denied( $order ) && ( self::refunded( $order ) || ! self::overridden( $order, $client ) ) ) {
			// Denied (refunded, or listed for a refund) and then passed: the
			// shopper finished a retry after the plugin stopped waiting
			// (TAX-1838), or a stale answer denied it. Never ship it on its
			// own, whatever the verification's opt-in says: staff decide. A
			// merchant override in AgeCheck's dashboard is staff's decision and
			// still releases it, unless its money went back already.
			Meta::set( $order, Meta::CLEARANCE_KEY, '' );
			Client::log( 'warning', sprintf( 'agecheck release order=%d cleared after denial', $order->get_id() ) );
			return self::finish_review( $order, 'passed_after_denial' );
		}
		Meta::mark_released( $order, Meta::STATUS_CLEARED );
		Meta::set( $order, Meta::CLEARED_AT, time() );
		Meta::set( $order, Meta::VERIFICATION_STATUS, 'passed' );
		foreach ( array( Meta::REASON, Meta::CLEARANCE_KEY, Meta::NEXT_CHECK_AT ) as $key ) {
			Meta::set( $order, $key, '' );
		}
		$order->save_meta_data();
		$note = $replayed
			? __( 'AgeCheck: age check passed (confirmed on a repeated clearance answer). Order released.', 'navexa-agecheck-for-woocommerce' )
			: __( 'AgeCheck: age check passed. Order released.', 'navexa-agecheck-for-woocommerce' );
		if ( Mode::is_test_mode() ) {
			/* translators: %s: an AgeCheck order note. */
			$note = sprintf( __( 'TEST %s', 'navexa-agecheck-for-woocommerce' ), $note );
		}
		return Hold::release( $order, $note ) ? self::RELEASED : self::CLEARED;
	}

	/**
	 * Whether the denied order's money went back (refunded through the
	 * gateway, or refunded or cancelled by staff).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function refunded( \WC_Order $order ): bool {
		return in_array( Meta::get( $order, Meta::REFUND ), array( Meta::REFUND_REFUNDED, Meta::REFUND_RESOLVED ), true );
	}

	/**
	 * Whether the order's verification passed by a merchant override in
	 * AgeCheck's dashboard (GetVerification, a read; recorded for the panel).
	 *
	 * @param \WC_Order $order  Order.
	 * @param Client    $client Fulfilment-key client.
	 * @throws ConnectError When AgeCheck cannot answer.
	 */
	private static function overridden( \WC_Order $order, Client $client ): bool {
		$answer       = $client->call( 'GetVerification', array( 'id' => Meta::get( $order, Meta::VERIFICATION_ID ) ) );
		$verification = isset( $answer['verification'] ) && is_array( $answer['verification'] ) ? $answer['verification'] : array();
		return 'passed' === Binding::record_verification( $order, $verification )
			&& 'MERCHANT_OVERRIDE' === Binding::pass_class_name( $verification );
	}

	/**
	 * The verification failed or cannot be verified: Denial takes over.
	 *
	 * @param \WC_Order       $order  Order.
	 * @param string          $reason Reason.
	 * @param IntegrationInfo $info   Fulfilment key info.
	 */
	private static function deny( \WC_Order $order, string $reason, IntegrationInfo $info ): string {
		Denial::deny( $order, '' === $reason ? 'not_verified' : $reason, $info->livemode );
		return self::DENIED;
	}

	/**
	 * Records a reason staff need to look at; the sweep asks again in six hours.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Reason.
	 */
	private static function finish_review( \WC_Order $order, string $reason ): string {
		$changed = self::$reason_at_start !== $reason;
		Meta::set( $order, Meta::REASON, $reason );
		Meta::set( $order, Meta::NEXT_CHECK_AT, time() + self::REVIEW_RECHECK );
		$order->save_meta_data();
		if ( $changed ) {
			self::$reason_at_start = $reason;
			Meta::note(
				$order,
				sprintf(
					/* translators: %s: a sentence saying why. */
					__( 'AgeCheck: the order stays held. %s', 'navexa-agecheck-for-woocommerce' ),
					self::reason_text( $reason )
				)
			);
		}
		return self::REVIEW;
	}

	/**
	 * Records an AgeCheck failure once per failing spell. A retryable one
	 * changes nothing (the retry follows).
	 *
	 * @param \WC_Order    $order Order.
	 * @param ConnectError $error Failure.
	 */
	private static function record_error( \WC_Order $order, ConnectError $error ): void {
		if ( $error->is_retryable() ) {
			$order->save_meta_data();
			return;
		}
		$code = '' === $error->local_reason() ? $error->connect_code() : $error->local_reason();
		self::finish_review( $order, 'release_error_' . sanitize_key( $code ) );
	}

	/**
	 * Replaces the stored clearance key after a recorded HOLD or DENIED, and
	 * saves it at once: a key whose answer AgeCheck replays must never stay
	 * stored because a later path returned without saving.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $external External id.
	 */
	private static function rotate_key( \WC_Order $order, string $external ): void {
		Meta::set( $order, Meta::CLEARANCE_KEY, self::new_clearance_key( $external ) );
		$order->save_meta_data();
	}

	/**
	 * A fresh GetClearance idempotency key.
	 *
	 * @param string $external External id.
	 */
	private static function new_clearance_key( string $external ): string {
		return 'clr:' . $external . ':' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * The decision of a GetClearance answer: CLEARED, HOLD, DENIED or ''.
	 * Accepts the enum name (proto3 JSON) and its number.
	 *
	 * @param array<string, mixed> $answer Decoded answer.
	 */
	private static function decision( array $answer ): string {
		$value = isset( $answer['decision'] ) ? $answer['decision'] : '';
		$names = array(
			1 => 'CLEARED',
			2 => 'HOLD',
			3 => 'DENIED',
		);
		if ( is_int( $value ) ) {
			return isset( $names[ $value ] ) ? $names[ $value ] : '';
		}
		$value = is_string( $value ) ? $value : '';
		$value = 0 === strpos( $value, 'CLEARANCE_DECISION_' ) ? substr( $value, strlen( 'CLEARANCE_DECISION_' ) ) : $value;
		return in_array( $value, $names, true ) ? $value : '';
	}

	/**
	 * Adds the 30-day "still waiting" note once and refreshes the notice count.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function note_if_stale( \WC_Order $order ): void {
		$held_at = Meta::get_int( $order, Meta::HELD_AT );
		if ( $held_at <= 0 || time() - $held_at < self::STALE_AFTER || Meta::get_int( $order, Meta::STALE_NOTED_AT ) > 0 ) {
			return;
		}
		Meta::set( $order, Meta::STALE_NOTED_AT, time() );
		$order->save_meta_data();
		Meta::note( $order, __( 'AgeCheck: this order has waited more than 30 days for its age check. Contact the shopper, or cancel and refund it.', 'navexa-agecheck-for-woocommerce' ) );
		Admin\Notices::flush_counts();
	}

	/**
	 * Schedules the next retry of a chain.
	 *
	 * @param int      $order_id    Order id.
	 * @param int      $attempt     Retries so far.
	 * @param int|null $retry_after Seconds AgeCheck asked to wait.
	 */
	private static function retry_later( int $order_id, int $attempt, ?int $retry_after ): void {
		if ( $attempt >= self::MAX_ATTEMPTS || ! function_exists( 'as_schedule_single_action' ) ) {
			return; // The sweep takes over.
		}
		$backoff = (int) min( self::MAX_BACKOFF, 60 * ( 2 ** $attempt ) );
		$delay   = max( null === $retry_after ? 0 : $retry_after, $backoff );
		as_schedule_single_action( time() + $delay, self::HOOK, array( $order_id, $attempt + 1, self::TRIGGER_RETRY ), self::GROUP, false );
	}

	/**
	 * Staff-facing text for a reason code.
	 *
	 * @param string $reason Reason code.
	 */
	public static function reason_text( string $reason ): string {
		$texts = array(
			'pending'                      => __( 'Waiting for the shopper to verify their age.', 'navexa-agecheck-for-woocommerce' ),
			'not_verified'                 => __( 'The shopper did not pass the age check.', 'navexa-agecheck-for-woocommerce' ),
			'not_us'                       => __( 'The order ships outside the US. AgeCheck verifies US addresses only (including US territories and APO/FPO).', 'navexa-agecheck-for-woocommerce' ),
			'denied'                       => __( 'AgeCheck refused to clear the order.', 'navexa-agecheck-for-woocommerce' ),
			'override'                     => __( 'Released by staff without an age check.', 'navexa-agecheck-for-woocommerce' ),
			'subscription_renewal'         => __( 'Subscription renewal: AgeCheck cannot reuse the earlier age check yet, so review the order and release it yourself.', 'navexa-agecheck-for-woocommerce' ),
			'not_configured'               => __( 'AgeCheck is not set up: add the fulfilment key in WooCommerce > Settings > AgeCheck, or confirm this site.', 'navexa-agecheck-for-woocommerce' ),
			'test_mode'                    => __( 'Test keys never release orders automatically.', 'navexa-agecheck-for-woocommerce' ),
			'no_ship_to'                   => __( 'The order has no complete shipping address.', 'navexa-agecheck-for-woocommerce' ),
			'no_billing_name'              => __( 'The order has no billing name to check the ID against.', 'navexa-agecheck-for-woocommerce' ),
			'invalid_ship_to'              => __( 'AgeCheck refused the shipping address.', 'navexa-agecheck-for-woocommerce' ),
			'verification_not_found'       => __( 'AgeCheck does not know the shopper\'s verification (were the keys changed, or switched between live and test?).', 'navexa-agecheck-for-woocommerce' ),
			'order_bound_elsewhere'        => __( 'AgeCheck has this order linked to another verification.', 'navexa-agecheck-for-woocommerce' ),
			'create_rejected'              => __( 'AgeCheck refused to start an age check for this order. It will try again later.', 'navexa-agecheck-for-woocommerce' ),
			'create_refused'               => __( 'AgeCheck cannot start age checks for this store right now (check billing and live access in the Navexa dashboard).', 'navexa-agecheck-for-woocommerce' ),
			'clearance_rejected'           => __( 'AgeCheck refused the clearance request (did the shipping address change?).', 'navexa-agecheck-for-woocommerce' ),
			'livemode_mismatch'            => __( 'AgeCheck answered in the other mode (live versus test) than this store\'s key.', 'navexa-agecheck-for-woocommerce' ),
			'unattested'                   => __( 'The shopper passed a check that cannot clear orders. Ask them to verify with their ID.', 'navexa-agecheck-for-woocommerce' ),
			'ship_to_mismatch'             => __( 'The shipping address differs from the one the age check covers. Restore it, then check again.', 'navexa-agecheck-for-woocommerce' ),
			'no_verification'              => __( 'AgeCheck has no verification for this order.', 'navexa-agecheck-for-woocommerce' ),
			'no_binding'                   => __( 'The order is not linked to an age check.', 'navexa-agecheck-for-woocommerce' ),
			'unexpected_decision'          => __( 'AgeCheck gave an answer this plugin does not know. Update the plugin.', 'navexa-agecheck-for-woocommerce' ),
			'ship_to_changed'              => __( 'The shopper verified for a different shipping address.', 'navexa-agecheck-for-woocommerce' ),
			'verification_bound_elsewhere' => __( 'The shopper\'s verification is already used by another order.', 'navexa-agecheck-for-woocommerce' ),
			'verification_purged'          => __( 'The shopper\'s age check expired before the order was released.', 'navexa-agecheck-for-woocommerce' ),
			'passed_after_denial'          => __( 'The shopper passed the age check after the order was denied. Check whether it was refunded, then release it without age check or cancel it.', 'navexa-agecheck-for-woocommerce' ),
			'retry_possible'               => __( 'The shopper did not pass the age check but may try again; a refund waits until then. Do not ship.', 'navexa-agecheck-for-woocommerce' ),
			'outage_fallback'              => __( 'Taken without an age check while age verification was unavailable at checkout. It stays held until the shopper verifies by the emailed link or from their order page.', 'navexa-agecheck-for-woocommerce' ),
		);
		if ( isset( $texts[ $reason ] ) ) {
			return $texts[ $reason ];
		}
		if ( 0 === strpos( $reason, 'release_error_' ) ) {
			/* translators: %s: an error code, e.g. permission_denied. */
			return sprintf( __( 'AgeCheck refused the request (%s). Check the fulfilment key and its scopes in the Navexa dashboard.', 'navexa-agecheck-for-woocommerce' ), substr( $reason, strlen( 'release_error_' ) ) );
		}
		/* translators: %s: a reason code. */
		return sprintf( __( 'Reason: %s.', 'navexa-agecheck-for-woocommerce' ), $reason );
	}
}
