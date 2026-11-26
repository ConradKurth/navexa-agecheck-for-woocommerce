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
 * Meta::DOC_RETRY), and only while GetIntegrationInfo's docRetry is on.
 *
 * - The release run defers the denial (defer()): on GetVerification FAILED
 *   for such an order, with docRetry on and a live key, it records
 *   Meta::RETRY_UNTIL once (the failure's decidedAt + WINDOW, never
 *   extended), leaves Meta::STATUS empty (no refund, no "will not ship" page)
 *   and asks again at least hourly. At or after RETRY_UNTIL GetClearance
 *   decides as before: CLEARED releases, HOLD keeps polling (AgeCheck fails
 *   an unfinished retry two days after its own window), DENIED denies.
 * - The engine exposes no per-verification signal that a failure may be
 *   retried, so an under-age failure is deferred too: its refund also waits
 *   for the window. Its shopper is never offered a retry (AgeCheck refuses
 *   one, and the order page then says so).
 * - The shopper's order page offers "Try again" (OrderVerify), staff can
 *   email a new link (Links::can_resend()), and the needs_doc webhook that
 *   follows a failure queues a release run (Webhook).
 */
final class DocRetry {

	/**
	 * Seconds after the failure the refund waits (#22: seven days).
	 */
	const WINDOW = 604800;

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
	 * waiting() with the verification still failed.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function open( \WC_Order $order ): bool {
		return self::waiting( $order ) && 'failed' === Meta::get( $order, Meta::VERIFICATION_STATUS );
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
	 * The release run's DENIED not_verified with GetVerification FAILED:
	 * whether to wait for a retry instead of denying (unsaved meta changes
	 * otherwise none). Records the window once, with a note.
	 *
	 * @param \WC_Order            $order        Order.
	 * @param array<string, mixed> $verification GetVerification's verification.
	 * @param IntegrationInfo      $info         Fulfilment key info.
	 */
	public static function defer( \WC_Order $order, array $verification, IntegrationInfo $info ): bool {
		if ( ! self::opted_in( $order ) || ! $info->doc_retry || ! $info->livemode ) {
			return false;
		}
		$until = self::until( $order );
		if ( 0 === $until ) {
			$decided = isset( $verification['decidedAt'] ) && is_string( $verification['decidedAt'] ) ? strtotime( $verification['decidedAt'] ) : false;
			$until   = ( false === $decided || $decided <= 0 ? time() : (int) $decided ) + self::WINDOW;
			Meta::set( $order, Meta::RETRY_UNTIL, $until );
			Meta::note(
				$order,
				sprintf(
					/* translators: %s: a date and time. */
					__( 'AgeCheck: the shopper\'s age check did not pass. They may try again until %s; a refund waits until then. Do not ship.', 'navexa-agecheck-for-woocommerce' ),
					self::date( $until )
				)
			);
		}
		if ( time() >= $until ) {
			return false;
		}
		Meta::set( $order, Meta::REASON, self::REASON );
		Meta::set( $order, Meta::NEXT_CHECK_AT, min( time() + self::RECHECK, $until ) );
		return true;
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
