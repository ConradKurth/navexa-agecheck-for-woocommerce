<?php
/**
 * Withdrawing the emailed verification links of orders that will not ship.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * When an order this plugin held, or any order with an emailed verification
 * link that may still open, is cancelled, refunded in full, failed, trashed
 * or deleted, AgeCheck is asked to withdraw its verification's links
 * (RevokeVerificationLinks, engine E8c): nobody should verify, and the store
 * pay for, an order that will not ship. A partial refund leaves the order
 * able to ship, so it withdraws nothing. A checkout verification (BOUND_BY
 * attach) passed before the order existed, and AgeCheck emails no link for
 * one, so holding such an order alone is no reason to ask.
 *
 * - Never in the status change: the hooks record STATE_PENDING on the order
 *   (Meta::REVOKE_AT: since when) and queue an Action Scheduler action
 *   (Release::queue_once()), and nothing they do throws into WooCommerce's
 *   save.
 * - Deleted for good (woocommerce_before_delete_order on both order stores,
 *   before_delete_post for the posts table's own screens): the order and its
 *   meta are gone before a run could read them, so the action carries the
 *   verification id and the key ([order, attempt, vid, key]); the run then
 *   only asks and logs the answer.
 * - The run takes the order's lock (Release::lock_name(), shared with
 *   release runs and staff link actions) and re-reads the order. It asks
 *   only while the order is still closed (CLOSED, or refunded in full), or
 *   when a call was already made under the stored key.
 * - Idempotency: the key (rvk:{vid}:{random}) is stored on the order before
 *   the call and reused until an answer is recorded, so a retry after a lost
 *   answer replays it.
 * - Failures: a retryable one retries with backoff and Retry-After up to
 *   MAX_ATTEMPTS, then is recorded; any other is recorded at once
 *   (STATE_FAILED, with the code). Fail closed: the order never says the
 *   link stopped working unless AgeCheck answered so. Staff can still use
 *   "AgeCheck: withdraw verification link" while a link is on record as open.
 *   A withdrawal still pending REQUEUE_AFTER after it was asked for (an
 *   action that died, or was never queued) is queued again by the sweep
 *   (requeue_stale()).
 * - Only the order's own verification: one copied from another order (its
 *   external id names that order, Binding::owned_external_id()) is never
 *   withdrawn, and the copied state is dropped. Nor, by itself, one bound
 *   under another install_id (same_install()): a copied site that was
 *   confirmed would otherwise withdraw the original site's shoppers' links.
 * - The answer: Meta::REVOKE "{state}:{vid}", Meta::REVOKE_DETAIL (links
 *   still open, or the error code), Meta::REVOKE_AT, and an order note. A
 *   link on record as open becomes Links::STATE_REVOKED: a release run sends
 *   that verification no new one while the order will not ship (staff may
 *   resend); held again (paid after failing, restored), it gets a new one.
 */
final class LinkRevoke {

	const HOOK = 'navexa_agecheck_revoke_links';

	const STATE_PENDING = 'pending';
	const STATE_REVOKED = 'revoked';
	const STATE_FAILED  = 'failed';

	/**
	 * Run outcomes (not states).
	 */
	const REVOKED = 'revoked';
	const FAILED  = 'failed';
	const SKIPPED = 'skipped';
	const LOCKED  = 'locked';

	/**
	 * Statuses of an order that will not ship.
	 */
	const CLOSED = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Retries of a retryable failure before it is recorded.
	 */
	const MAX_ATTEMPTS = 8;

	/**
	 * Seconds before a run that found the order locked tries again.
	 */
	const LOCKED_RETRY = 30;

	/**
	 * Seconds a withdrawal may stay pending before the sweep queues it again
	 * (a run's own retries take about three hours).
	 */
	const REQUEUE_AFTER = 21600;

	/**
	 * Orders whose deletion queued a withdrawal in this request, by id (the
	 * posts table fires both delete hooks for one order).
	 *
	 * @var array<int, bool>
	 */
	private static $deleting = array();

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status_changed' ), 10, 4 );
		// A full refund whose status a filter kept (woocommerce_order_fully_refunded_status).
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'on_fully_refunded' ), 10, 1 );
		// Trashing changes the status without a transition: WC_Order::delete() on both order stores ...
		add_action( 'woocommerce_trash_order', array( self::class, 'on_trashed' ), 10, 1 );
		// ... and wp_trash_post() on the posts table (its Orders screen).
		add_action( 'trashed_post', array( self::class, 'on_trashed_post' ), 10, 1 );
		// Deleted for good, while the order can still be read: WC_Order::delete( true ) on both order stores ...
		add_action( 'woocommerce_before_delete_order', array( self::class, 'on_before_delete' ), 10, 1 );
		// ... and wp_delete_post() on the posts table (its Orders screen, "Empty Trash").
		add_action( 'before_delete_post', array( self::class, 'on_before_delete_post' ), 10, 1 );
		add_action( self::HOOK, array( self::class, 'run_job' ), 10, 4 );
	}

	/**
	 * An order moved to cancelled, refunded or failed.
	 *
	 * @param int|string $order_id Order id.
	 * @param mixed      $from     Previous status.
	 * @param mixed      $to       New status.
	 * @param mixed      $order    Order (not used: read afresh).
	 */
	public static function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		if ( ! is_string( $to ) || ! in_array( $to, self::CLOSED, true ) ) {
			return;
		}
		unset( $order ); // Read afresh (queue_safely()).
		self::queue_safely( $order_id, $to, is_string( $from ) ? $from : '' );
	}

	/**
	 * An order was refunded in full.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function on_fully_refunded( $order_id ): void {
		self::queue_safely( $order_id, 'refunded', '' );
	}

	/**
	 * An order was trashed through its data store.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function on_trashed( $order_id ): void {
		self::queue_safely( $order_id, 'trash', '' );
	}

	/**
	 * A post was trashed: an order on the posts table (the HPOS placeholder
	 * post is another type).
	 *
	 * @param int|string $post_id Post id.
	 */
	public static function on_trashed_post( $post_id ): void {
		if ( 'shop_order' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		self::queue_safely( $post_id, 'trash', '' );
	}

	/**
	 * An order is about to be deleted for good through its data store.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function on_before_delete( $order_id ): void {
		self::queue_deleted( $order_id );
	}

	/**
	 * A post is about to be deleted: an order on the posts table (the HPOS
	 * placeholder post is another type, and its order is gone by then).
	 *
	 * @param int|string $post_id Post id.
	 */
	public static function on_before_delete_post( $post_id ): void {
		if ( 'shop_order' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		self::queue_deleted( $post_id );
	}

	/**
	 * Forgets the deletions queued in this request (tests, and long-running
	 * processes).
	 */
	public static function reset(): void {
		self::$deleting = array();
	}

	/**
	 * Whether the order's verification links are to be withdrawn now that it
	 * will not ship: its own verification, with a link on record as possibly
	 * open, or held by this plugin (or leaving the hold) on a verification
	 * AgeCheck may have emailed (any but a checkout one), and not already
	 * withdrawn with nothing sent since.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $from  The status it left ('' when unknown).
	 */
	public static function wanted( \WC_Order $order, string $from = '' ): bool {
		if ( 'shop_order' !== $order->get_type() ) {
			return false;
		}
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		if ( ! Binding::is_verification_id( $vid ) || '' === Binding::owned_external_id( $order ) ) {
			return false;
		}
		$link    = Links::state( $order );
		$live    = in_array( $link, Links::LIVE_STATES, true ) || 0 === strpos( Meta::get( $order, Meta::LINK_KEY ), 'vl:' . $vid . ':' );
		$revoked = Meta::get( $order, Meta::REVOKE ) === self::STATE_REVOKED . ':' . $vid || Links::STATE_WITHDRAWN === $link;
		if ( $revoked && ! $live ) {
			return false; // Withdrawn already (by this or by staff), and nothing sent since.
		}
		if ( $live ) {
			return true;
		}
		// A checkout verification (attach) had passed when the order was
		// placed, and AgeCheck emails no link for one (document name check
		// NONE): no link of it can be open.
		return 'attach' !== Meta::get( $order, Meta::BOUND_BY ) && ( Hold::STATUS === $from || 'yes' === Meta::get( $order, Meta::HELD ) );
	}

	/**
	 * Whether the order's external id was minted under this site's
	 * install_id. A copied site that was confirmed has a new one, so the
	 * orders it copied do not: their links are the original site's shoppers'.
	 * An order bound before this site's own last confirmation (a domain move)
	 * looks the same; staff can still withdraw its link from Order actions.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function same_install( \WC_Order $order ): bool {
		$install = Install::install_id();
		return '' !== $install && 0 === strpos( Binding::owned_external_id( $order ), 'wc:' . $install . ':' );
	}

	/**
	 * Records STATE_PENDING (saved) and queues the run. Never throws.
	 *
	 * The order is read afresh, never the object WooCommerce passed: that can
	 * predate this request's own writes (wc_create_refund() updates the status
	 * of a copy it loaded before woocommerce_order_fully_refunded), and its
	 * stale meta would add a second row for the same key.
	 *
	 * @param int|string $order_id Order id.
	 * @param string     $trigger  What happened (a CLOSED status), for the log.
	 * @param string     $from     The status the order left ('' when unknown).
	 */
	private static function queue_safely( $order_id, string $trigger, string $from ): void {
		try {
			$order = wc_get_order( absint( $order_id ) );
			if ( ! $order instanceof \WC_Order || ! self::wanted( $order, $from ) ) {
				return;
			}
			$vid = Meta::get( $order, Meta::VERIFICATION_ID );
			if ( ! self::same_install( $order ) ) {
				Client::log( 'info', sprintf( 'agecheck link revoke skipped order=%d vid=%s trigger=%s reason=other_install', $order->get_id(), $vid, sanitize_key( $trigger ) ) );
				return;
			}
			$pending = self::STATE_PENDING . ':' . $vid;
			if ( Meta::get( $order, Meta::REVOKE ) !== $pending ) {
				Meta::set( $order, Meta::REVOKE, $pending );
				Meta::set( $order, Meta::REVOKE_DETAIL, '' );
				Meta::set( $order, Meta::REVOKE_AT, time() ); // Pending since (requeue_stale()).
				$order->save_meta_data();
			}
			$queued = Release::queue_once( self::HOOK, array( (int) $order->get_id(), 0 ) );
			Client::log( $queued ? 'info' : 'error', sprintf( 'agecheck link revoke queued order=%d vid=%s trigger=%s queued=%s', $order->get_id(), $vid, sanitize_key( $trigger ), $queued ? 'yes' : 'no' ) );
		} catch ( \Throwable $e ) {
			// The status change must go through whatever happens here.
			Client::log( 'error', sprintf( 'agecheck link revoke not queued order=%d error=%s', absint( $order_id ), get_class( $e ) ) );
		}
	}

	/**
	 * Queues the withdrawal of an order about to be deleted, with the
	 * verification id and the key in the action's arguments: the run will
	 * not find the order. A withdrawal already pending (trashed first) is
	 * carried over with its stored key. Never throws.
	 *
	 * @param int|string $order_id Order id.
	 */
	private static function queue_deleted( $order_id ): void {
		$order_id = absint( $order_id );
		if ( isset( self::$deleting[ $order_id ] ) ) {
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
				return;
			}
			$vid = '' === Binding::owned_external_id( $order ) ? '' : self::pending_vid( $order );
			if ( '' === $vid ) {
				if ( ! self::wanted( $order ) ) {
					return;
				}
				$vid = Meta::get( $order, Meta::VERIFICATION_ID );
			}
			if ( ! self::same_install( $order ) ) {
				Client::log( 'info', sprintf( 'agecheck link revoke skipped order=%d vid=%s trigger=deleted reason=other_install', $order_id, $vid ) );
				return;
			}
			$key = Meta::get( $order, Meta::REVOKE_KEY );
			if ( 0 !== strpos( $key, 'rvk:' . $vid . ':' ) ) {
				$key = 'rvk:' . $vid . ':' . bin2hex( random_bytes( 8 ) );
			}
			self::$deleting[ $order_id ] = true;
			$queued                      = Release::queue_once( self::HOOK, array( $order_id, 0, $vid, $key ) );
			Client::log( $queued ? 'info' : 'error', sprintf( 'agecheck link revoke queued order=%d vid=%s trigger=deleted queued=%s', $order_id, $vid, $queued ? 'yes' : 'no' ) );
		} catch ( \Throwable $e ) {
			// The deletion must go through whatever happens here.
			Client::log( 'error', sprintf( 'agecheck link revoke not queued order=%d trigger=deleted error=%s', $order_id, get_class( $e ) ) );
		}
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int|string $order_id Order id.
	 * @param int|string $attempt  Retries so far.
	 * @param mixed      $vid      Verification id, for an order deleted since ('' otherwise).
	 * @param mixed      $key      Its idempotency key, likewise.
	 */
	public static function run_job( $order_id, $attempt = 0, $vid = '', $key = '' ): void {
		$order_id = absint( $order_id );
		$attempt  = absint( $attempt );
		$vid      = is_string( $vid ) ? $vid : '';
		$key      = is_string( $key ) ? $key : '';
		$deleted  = '' === $vid ? array() : array( $vid, $key );
		$backoff  = (int) min( Release::MAX_BACKOFF, 60 * ( 2 ** min( $attempt, 16 ) ) );
		try {
			$outcome = self::run( $order_id, $attempt >= self::MAX_ATTEMPTS, null, $vid, $key );
		} catch ( ConnectError $e ) {
			// Only a retryable failure before the last attempt gets here.
			self::schedule( $order_id, $attempt + 1, (int) max( null === $e->retry_after() ? 0 : $e->retry_after(), $backoff ), $deleted );
			return;
		} catch ( \Throwable $e ) {
			// Anything else (the order store, another plugin's hook): never let
			// the action die with the withdrawal pending. Past the last
			// attempt, the sweep queues it again (requeue_stale()).
			Client::log( 'error', sprintf( 'agecheck link revoke run failed order=%d attempt=%d error=%s', $order_id, $attempt, get_class( $e ) ) );
			if ( $attempt < self::MAX_ATTEMPTS ) {
				self::schedule( $order_id, $attempt + 1, $backoff, $deleted );
			}
			return;
		}
		if ( self::LOCKED === $outcome ) {
			// A release run or a staff action has the order for a moment (its
			// lock expires after Release::LOCK_TTL): not a failure.
			self::schedule( $order_id, min( $attempt + 1, self::MAX_ATTEMPTS ), self::LOCKED_RETRY, $deleted );
		}
	}

	/**
	 * One run: withdraws the links of the verification recorded as pending,
	 * under the order's lock. For an order deleted since, those of $vid under
	 * $key (the action's arguments), with nothing to record them on.
	 *
	 * @param int         $order_id Order id.
	 * @param bool        $last_try Whether a retryable failure is recorded instead of rethrown.
	 * @param Client|null $client   Fulfilment-key client; null for the stored key.
	 * @param string      $vid      Verification id queued with a deleted order ('' otherwise).
	 * @param string      $key      Its idempotency key.
	 * @return string REVOKED, FAILED, SKIPPED or LOCKED.
	 * @throws ConnectError A retryable failure, unless $last_try.
	 */
	public static function run( int $order_id, bool $last_try = true, ?Client $client = null, string $vid = '', string $key = '' ): string {
		$lock  = Release::lock_name( $order_id );
		$token = Lock::acquire( $lock, Release::LOCK_TTL );
		if ( '' === $token ) {
			return self::LOCKED;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return self::run_deleted( $order_id, $vid, $key, $last_try, $client );
			}
			if ( 'shop_order' !== $order->get_type() ) {
				return self::SKIPPED;
			}
			$vid = self::pending_vid( $order );
			if ( '' === $vid ) {
				return self::SKIPPED; // Answered already.
			}
			if ( '' === Binding::owned_external_id( $order ) ) {
				// Copied from another order, whose links are its own: drop the copy.
				if ( ! Binding::forget_foreign( $order ) ) {
					Meta::set( $order, Meta::REVOKE, '' );
					Meta::set( $order, Meta::REVOKE_KEY, '' );
				}
				$order->save_meta_data();
				Client::log( 'info', sprintf( 'agecheck link revoke skipped order=%d vid=%s reason=copied', $order_id, $vid ) );
				return self::SKIPPED;
			}
			$key = Meta::get( $order, Meta::REVOKE_KEY );
			if ( 0 !== strpos( $key, 'rvk:' . $vid . ':' ) ) {
				// Nothing asked yet. It can ship again (restored from the
				// trash, or back to "Awaiting age check"): its link is wanted
				// again. Bound under another install_id: see same_install().
				$reason = ! self::is_closed( $order ) ? 'reopened' : ( self::same_install( $order ) ? '' : 'other_install' );
				if ( '' !== $reason ) {
					Meta::set( $order, Meta::REVOKE, '' );
					Meta::set( $order, Meta::REVOKE_AT, '' );
					$order->save_meta_data();
					Client::log( 'info', sprintf( 'agecheck link revoke skipped order=%d vid=%s reason=%s', $order_id, $vid, $reason ) );
					return self::SKIPPED;
				}
				$key = 'rvk:' . $vid . ':' . bin2hex( random_bytes( 8 ) );
				Meta::set( $order, Meta::REVOKE_KEY, $key );
				$order->save_meta_data();
			}
			$client = null === $client ? Client::for_key( Client::KEY_FULFILMENT ) : $client;
			try {
				$answer = self::ask( $client, $vid, $key );
			} catch ( ConnectError $e ) {
				if ( $e->is_retryable() && ! $last_try ) {
					throw $e;
				}
				self::record_failure( $order, $vid, $e );
				return self::FAILED;
			}
			self::record_revoked( $order, $vid, self::revoked_count( $answer ) );
			return self::REVOKED;
		} finally {
			Lock::release( $lock, $token );
		}
	}

	/**
	 * The run for an order that no longer exists: asks with the verification
	 * id and key its deletion queued, and logs the answer (nothing else is
	 * left to record it on). Without them (a run queued before the order was
	 * deleted) there is nothing to ask.
	 *
	 * @param int         $order_id Order id.
	 * @param string      $vid      Verification id.
	 * @param string      $key      Idempotency key.
	 * @param bool        $last_try Whether a retryable failure is logged instead of rethrown.
	 * @param Client|null $client   Fulfilment-key client; null for the stored key.
	 * @return string REVOKED, FAILED or SKIPPED.
	 * @throws ConnectError A retryable failure, unless $last_try.
	 */
	private static function run_deleted( int $order_id, string $vid, string $key, bool $last_try, ?Client $client ): string {
		if ( ! Binding::is_verification_id( $vid ) || 0 !== strpos( $key, 'rvk:' . $vid . ':' ) ) {
			Client::log( 'info', sprintf( 'agecheck link revoke skipped order=%d reason=no_order', $order_id ) );
			return self::SKIPPED;
		}
		$client = null === $client ? Client::for_key( Client::KEY_FULFILMENT ) : $client;
		try {
			$answer = self::ask( $client, $vid, $key );
		} catch ( ConnectError $e ) {
			if ( $e->is_retryable() && ! $last_try ) {
				throw $e;
			}
			Client::log( 'warning', sprintf( 'agecheck link revoke failed order=%d vid=%s code=%s deleted=yes', $order_id, $vid, self::error_code( $e ) ) );
			return self::FAILED;
		}
		Client::log( 'info', sprintf( 'agecheck link revoked order=%d vid=%s revoked=%d deleted=yes', $order_id, $vid, self::revoked_count( $answer ) ) );
		return self::REVOKED;
	}

	/**
	 * RevokeVerificationLinks.
	 *
	 * @param Client $client Fulfilment-key client.
	 * @param string $vid    Verification id.
	 * @param string $key    Idempotency key.
	 * @return array<string, mixed> The answer.
	 * @throws ConnectError When AgeCheck did not answer it.
	 */
	private static function ask( Client $client, string $vid, string $key ): array {
		return $client->call(
			Links::RPC_REVOKE,
			array(
				'idempotencyKey' => $key,
				'verificationId' => $vid,
			)
		);
	}

	/**
	 * How many links the answer says were still open (proto3 JSON omits 0).
	 *
	 * @param array<string, mixed> $answer RevokeVerificationLinks answer.
	 */
	private static function revoked_count( array $answer ): int {
		return isset( $answer['revokedCount'] ) && is_numeric( $answer['revokedCount'] ) ? max( 0, (int) $answer['revokedCount'] ) : 0;
	}

	/**
	 * A failure's code for the order and the log: the local reason, else the
	 * Connect code.
	 *
	 * @param ConnectError $error Failure.
	 */
	private static function error_code( ConnectError $error ): string {
		return sanitize_key( '' === $error->local_reason() ? $error->connect_code() : $error->local_reason() );
	}

	/**
	 * The sweep's part (Sweep::run()): queues a run again for each withdrawal
	 * still pending REQUEUE_AFTER after it was asked for, Sweep::BATCH at a
	 * time: its action died (a fatal error or timeout, which Action Scheduler
	 * marks failed and never retries), was lost, or was never queued. The
	 * stamp is renewed, so an order is queued again at most once per
	 * REQUEUE_AFTER. Never throws.
	 *
	 * @param int|null $now Unix time; null for time().
	 * @return int Runs queued.
	 */
	public static function requeue_stale( ?int $now = null ): int {
		$now = null === $now ? time() : $now;
		try {
			$ids = OrderQuery::ids(
				array(
					'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
					'limit'      => Sweep::BATCH,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small set: withdrawals left pending.
						array(
							'key'     => Meta::REVOKE,
							'value'   => self::STATE_PENDING . ':',
							'compare' => 'LIKE',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => Meta::REVOKE_AT,
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => Meta::REVOKE_AT,
								'value'   => $now - self::REQUEUE_AFTER,
								'compare' => '<',
								'type'    => 'NUMERIC',
							),
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			Client::log( 'error', sprintf( 'agecheck link revoke sweep failed error=%s', get_class( $e ) ) );
			return 0;
		}
		$queued = 0;
		foreach ( $ids as $order_id ) {
			try {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order || 0 !== strpos( Meta::get( $order, Meta::REVOKE ), self::STATE_PENDING . ':' ) ) {
					continue;
				}
				Meta::set( $order, Meta::REVOKE_AT, $now );
				$order->save_meta_data();
				$ok = Release::queue_once( self::HOOK, array( (int) $order_id, 0 ) );
				Client::log( $ok ? 'info' : 'error', sprintf( 'agecheck link revoke requeued order=%d queued=%s', $order_id, $ok ? 'yes' : 'no' ) );
				$queued += $ok ? 1 : 0;
			} catch ( \Throwable $e ) {
				Client::log( 'error', sprintf( 'agecheck link revoke not requeued order=%d error=%s', $order_id, get_class( $e ) ) );
			}
		}
		return $queued;
	}

	/**
	 * Whether the order will not ship: a CLOSED status, or refunded in full
	 * (whatever status a filter kept).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_closed( \WC_Order $order ): bool {
		if ( in_array( $order->get_status( 'edit' ), self::CLOSED, true ) ) {
			return true;
		}
		return (float) $order->get_total_refunded() > 0 && (float) $order->get_remaining_refund_amount() <= 0;
	}

	/**
	 * The withdrawal's state in words, for the admin panel: only while it is
	 * pending or after it failed ('' otherwise; Links::describe() covers a
	 * withdrawn link).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function describe( \WC_Order $order ): string {
		$stored = explode( ':', Meta::get( $order, Meta::REVOKE ), 2 );
		if ( 2 !== count( $stored ) ) {
			return '';
		}
		switch ( $stored[0] ) {
			case self::STATE_PENDING:
				return __( 'The order will not ship: AgeCheck is being asked to withdraw its emailed verification link.', 'navexa-agecheck-for-woocommerce' );
			case self::STATE_FAILED:
				return sprintf(
					/* translators: %s: an error code, e.g. unavailable. */
					__( 'AgeCheck could not withdraw the emailed verification link (%s). See the order notes.', 'navexa-agecheck-for-woocommerce' ),
					Meta::get( $order, Meta::REVOKE_DETAIL )
				);
			default:
				return '';
		}
	}

	/**
	 * The verification recorded as pending, or '' (the caller checks that the
	 * state is the order's own, Binding::owned_external_id()).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function pending_vid( \WC_Order $order ): string {
		$stored = explode( ':', Meta::get( $order, Meta::REVOKE ), 2 );
		if ( 2 !== count( $stored ) || self::STATE_PENDING !== $stored[0] || ! Binding::is_verification_id( $stored[1] ) ) {
			return '';
		}
		return $stored[1];
	}

	/**
	 * Records AgeCheck's answer (saved), with an order note.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $vid   Verification id.
	 * @param int       $count Links that were still open.
	 */
	private static function record_revoked( \WC_Order $order, string $vid, int $count ): void {
		Meta::set( $order, Meta::REVOKE, self::STATE_REVOKED . ':' . $vid );
		Meta::set( $order, Meta::REVOKE_DETAIL, $count );
		Meta::set( $order, Meta::REVOKE_AT, time() );
		Meta::set( $order, Meta::REVOKE_KEY, '' );
		$current = Meta::get( $order, Meta::VERIFICATION_ID ) === $vid;
		if ( $current && ( in_array( Links::state( $order ), Links::LIVE_STATES, true ) || 0 === strpos( Meta::get( $order, Meta::LINK_KEY ), 'vl:' . $vid . ':' ) ) ) {
			foreach ( array( Meta::LINK_ID, Meta::LINK_EXPIRES_AT, Meta::LINK_KEY ) as $key ) {
				Meta::set( $order, $key, '' );
			}
			Meta::set( $order, Meta::LINK, Links::STATE_REVOKED . ':' . $vid );
		}
		$order->save_meta_data();
		Meta::note(
			$order,
			$count > 0
				? sprintf(
					/* translators: %d: number of links that could still be opened. */
					_n( 'AgeCheck: the order will not ship, so AgeCheck withdrew its emailed verification link (%d was still open). The shopper can no longer use it.', 'AgeCheck: the order will not ship, so AgeCheck withdrew its emailed verification links (%d were still open). The shopper can no longer use them.', $count, 'navexa-agecheck-for-woocommerce' ),
					$count
				)
				: __( 'AgeCheck: the order will not ship, so AgeCheck was asked to withdraw its emailed verification links. None was still open.', 'navexa-agecheck-for-woocommerce' )
		);
		Client::log( 'info', sprintf( 'agecheck link revoked order=%d vid=%s revoked=%d', $order->get_id(), $vid, $count ) );
	}

	/**
	 * Records a failure (saved), with an order note.
	 *
	 * @param \WC_Order    $order Order.
	 * @param string       $vid   Verification id.
	 * @param ConnectError $error Failure.
	 */
	private static function record_failure( \WC_Order $order, string $vid, ConnectError $error ): void {
		$code = self::error_code( $error );
		Meta::set( $order, Meta::REVOKE, self::STATE_FAILED . ':' . $vid );
		Meta::set( $order, Meta::REVOKE_DETAIL, $code );
		Meta::set( $order, Meta::REVOKE_AT, time() );
		Meta::set( $order, Meta::REVOKE_KEY, '' );
		$order->save_meta_data();
		Meta::note(
			$order,
			sprintf(
				/* translators: %s: an error code, e.g. unavailable or no_api_key. */
				__( 'AgeCheck: the order will not ship, but AgeCheck could not withdraw its emailed verification link (%s). A link already emailed keeps opening AgeCheck\'s page until it expires. If Order actions offer "AgeCheck: withdraw verification link", choose it to try again.', 'navexa-agecheck-for-woocommerce' ),
				$code
			)
		);
		Client::log( 'warning', sprintf( 'agecheck link revoke failed order=%d vid=%s code=%s', $order->get_id(), $vid, $code ) );
	}

	/**
	 * Schedules a run.
	 *
	 * @param int      $order_id Order id.
	 * @param int      $attempt  Attempt number of the run.
	 * @param int      $delay    Seconds from now.
	 * @param string[] $deleted  A deleted order's verification id and key (run_job()), or none.
	 */
	private static function schedule( int $order_id, int $attempt, int $delay, array $deleted = array() ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, array_merge( array( $order_id, $attempt ), $deleted ), Release::GROUP, false );
	}
}
