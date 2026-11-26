<?php
/**
 * The document retry (AgeCheck TAX-1838, owner decision 2026-11-25 #22).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * A shopper whose ID check was declined, or whose ID's name did not match the
 * order's, may try the ID check again on the same verification (never after
 * an under-age result; at most five ID checks in all). AgeCheck offers it only
 * for a verification created with allowDocRetry, which this plugin sends on
 * every verification it creates for an order (Binding::create(), recorded as
 * Meta::DOC_RETRY).
 *
 * - AgeCheck decides whether one verification may still be retried: its
 *   CreateHostedSession accepts a failed verification only then (and answers
 *   FAILED_PRECONDITION once it may not, an under-age failure included). The
 *   release run asks it on every DENIED not_verified with GetVerification
 *   FAILED for such an order (defer()); a live key only. While AgeCheck
 *   accepts, Meta::RETRY_OPEN is 'yes', Meta::STATUS stays empty (no refund,
 *   no "will not ship" page) and the run asks again at least hourly. Once it
 *   refuses (or Meta::RETRY_UNTIL is past), GetClearance decides as before:
 *   DENIED denies. An under-age failure is therefore denied at once.
 * - Meta::RETRY_UNTIL is the date the order page, the panel and the notes
 *   show, and a local backstop: the earliest failure the plugin learned of
 *   (the verification.failed webhook's occurred_at, or the failure's
 *   decidedAt) + WINDOW, never later than the verification's creation +
 *   CREATED_BOUND, and only ever moved earlier (anchor()).
 * - The shopper's order page offers "Try again" (OrderVerify), staff can
 *   email a new link (Links::can_resend()), and the needs_doc webhook that
 *   follows a failure queues a release run (Webhook), all only while open().
 * - An order denied meanwhile (a stale answer, a clock ahead of AgeCheck's)
 *   whose shopper still passes is never released on its own: the release run
 *   leaves it to staff (Release, passed_after_denial).
 */
final class DocRetry {

	/**
	 * Seconds after the first failure the refund waits (#22: seven days).
	 */
	const WINDOW = 604800;

	/**
	 * Latest a retry window ends, in seconds after the verification was
	 * created (AgeCheck's own bound: 26 days, its data purge less four days).
	 */
	const CREATED_BOUND = 2246400;

	/**
	 * Most seconds between two release runs while the retry is possible.
	 */
	const RECHECK = 3600;

	/**
	 * Reason recorded while the denial waits.
	 */
	const REASON = 'retry_possible';

	/**
	 * Whether the order's verification was created with allowDocRetry by this
	 * plugin.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function opted_in( \WC_Order $order ): bool {
		return 'yes' === Meta::get( $order, Meta::DOC_RETRY ) && 'create' === Meta::get( $order, Meta::BOUND_BY );
	}

	/**
	 * Unix time the retry window ends (0: none recorded).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function until( \WC_Order $order ): int {
		return Meta::get_int( $order, Meta::RETRY_UNTIL );
	}

	/**
	 * Whether the order waits on a possible retry: opted in, held, not
	 * released or denied, its window recorded and not over.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function waiting( \WC_Order $order ): bool {
		return self::opted_in( $order )
			&& Hold::STATUS === $order->get_status( 'edit' )
			&& ! Meta::is_released( $order )
			&& Meta::STATUS_DENIED !== Meta::get( $order, Meta::STATUS )
			&& self::until( $order ) > time();
	}

	/**
	 * Whether the shopper may be offered "Try again" (or staff a new link):
	 * waiting() with the verification still failed and AgeCheck's last answer
	 * that it may be retried.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function open( \WC_Order $order ): bool {
		return self::waiting( $order )
			&& 'failed' === Meta::get( $order, Meta::VERIFICATION_STATUS )
			&& 'yes' === Meta::get( $order, Meta::RETRY_OPEN );
	}

	/**
	 * Whether the shopper is trying again: waiting() with the verification
	 * back on the ID check.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function retrying( \WC_Order $order ): bool {
		return self::waiting( $order ) && in_array( Meta::get( $order, Meta::VERIFICATION_STATUS ), Links::HOSTED_STATES, true );
	}

	/**
	 * Moves the window's end to at most $failed_at + WINDOW and $created_at +
	 * CREATED_BOUND (each ignored when not positive), never later than one
	 * already recorded (unsaved meta changes). Answers the end.
	 *
	 * @param \WC_Order $order      Order.
	 * @param int       $failed_at  Unix time of a failure.
	 * @param int       $created_at Unix time the verification was created.
	 */
	public static function anchor( \WC_Order $order, int $failed_at, int $created_at ): int {
		$ends = array();
		if ( self::until( $order ) > 0 ) {
			$ends[] = self::until( $order );
		}
		if ( $failed_at > 0 ) {
			$ends[] = $failed_at + self::WINDOW;
		}
		if ( $created_at > 0 ) {
			$ends[] = $created_at + self::CREATED_BOUND;
		}
		$bound = Meta::get_int( $order, Meta::BOUND_AT );
		if ( $bound > 0 ) {
			// Bound right after its creation: the same cap, a few seconds late.
			$ends[] = $bound + self::CREATED_BOUND;
		}
		if ( array() === $ends ) {
			return 0;
		}
		$until = min( $ends );
		if ( self::until( $order ) !== $until ) {
			Meta::set( $order, Meta::RETRY_UNTIL, $until );
		}
		return $until;
	}

	/**
	 * A verification.failed webhook for the order: anchors the window on its
	 * occurred_at (the failure's own time, whenever the release run reads
	 * it) and drops AgeCheck's earlier "may retry" answer, which this new
	 * failure may have ended. Saves the order's meta. Opted-in orders only.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $occurred_at The event's occurred_at.
	 */
	public static function observe_failure( \WC_Order $order, string $occurred_at ): void {
		if ( ! self::opted_in( $order ) || Meta::is_released( $order ) ) {
			return;
		}
		$time = strtotime( $occurred_at );
		if ( false === $time || $time <= 0 || $time > time() + 300 ) {
			return;
		}
		self::anchor( $order, (int) $time, 0 );
		Meta::set( $order, Meta::RETRY_OPEN, '' );
		$order->save_meta_data();
	}

	/**
	 * The release run's DENIED not_verified with GetVerification FAILED:
	 * whether to wait for a retry instead of denying (unsaved meta changes
	 * otherwise none but the window). Asks AgeCheck with CreateHostedSession
	 * (side-effect free; its link is dropped unread): accepted means the
	 * verification may be retried now.
	 *
	 * @param \WC_Order            $order        Order.
	 * @param array<string, mixed> $verification GetVerification's verification.
	 * @param IntegrationInfo      $info         Fulfilment key info.
	 * @param Client               $client       Fulfilment-key client.
	 * @throws ConnectError When AgeCheck cannot answer now (retryable).
	 */
	public static function defer( \WC_Order $order, array $verification, IntegrationInfo $info, Client $client ): bool {
		if ( ! self::opted_in( $order ) || ! $info->livemode ) {
			return false;
		}
		$was_open = 'yes' === Meta::get( $order, Meta::RETRY_OPEN );
		$failed   = self::time_of( $verification, 'decidedAt' );
		$until    = self::anchor( $order, $failed > 0 ? $failed : time(), self::time_of( $verification, 'createdAt' ) );
		if ( time() >= $until ) {
			Meta::set( $order, Meta::RETRY_OPEN, '' );
			return false;
		}
		$vid = Meta::get( $order, Meta::VERIFICATION_ID );
		try {
			$client->call( 'CreateHostedSession', array( 'verificationId' => $vid ) );
		} catch ( ConnectError $e ) {
			if ( $e->is_retryable() ) {
				throw $e;
			}
			// AgeCheck takes no retry for it (an under-age failure, its window
			// or its ID checks spent, the retry not offered, the store's own
			// access): deny as before.
			Meta::set( $order, Meta::RETRY_OPEN, '' );
			Client::log( 'info', sprintf( 'agecheck release order=%d vid=%s retry not open code=%s', $order->get_id(), $vid, $e->connect_code() ) );
			return false;
		}
		Meta::set( $order, Meta::RETRY_OPEN, 'yes' );
		if ( ! $was_open ) {
			Meta::note(
				$order,
				sprintf(
					/* translators: %s: a date and time. */
					__( 'AgeCheck: the shopper\'s age check did not pass. They may try again until %s; a refund waits until then. Do not ship.', 'navexa-agecheck-for-woocommerce' ),
					self::date( $until )
				)
			);
		}
		Meta::set( $order, Meta::REASON, self::REASON );
		Meta::set( $order, Meta::NEXT_CHECK_AT, min( time() + self::RECHECK, $until ) );
		return true;
	}

	/**
	 * A timestamp field of a verification as Unix time (0 when absent or
	 * unreadable).
	 *
	 * @param array<string, mixed> $verification GetVerification's verification.
	 * @param string               $field        createdAt or decidedAt.
	 */
	private static function time_of( array $verification, string $field ): int {
		$value = isset( $verification[ $field ] ) && is_string( $verification[ $field ] ) ? strtotime( $verification[ $field ] ) : false;
		return false === $value || $value <= 0 ? 0 : (int) $value;
	}

	/**
	 * A date and time in the site's format.
	 *
	 * @param int $time Unix time.
	 */
	public static function date( int $time ): string {
		$format = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $time ) : gmdate( 'F j, Y H:i', $time );
	}
}
