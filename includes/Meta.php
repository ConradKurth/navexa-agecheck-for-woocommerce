<?php
/**
 * Order meta keys and the AgeCheck state they record.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Every order meta key the plugin writes, read and written only through
 * order CRUD (HPOS-safe). Values hold ids, codes and timestamps, never
 * shopper identity.
 */
final class Meta {

	/**
	 * 'yes' once the order needs an age check (sticky), 'no' while it does not.
	 */
	const REQUIRED = '_navexa_agecheck_required';

	/**
	 * Why the order does not need one: Requirement::REASON_*.
	 */
	const REQUIRED_REASON = '_navexa_agecheck_required_reason';

	/**
	 * The AgeCheck verification id (acv_...) this order is bound to.
	 */
	const VERIFICATION_ID = '_navexa_agecheck_vid';

	/**
	 * The verification's last known status (needs_doc, passed, failed, ...), for
	 * the admin panel only: release decisions come from GetClearance.
	 */
	const VERIFICATION_STATUS = '_navexa_agecheck_vstatus';

	/**
	 * Verification ids the order was bound to before (comma-separated), kept
	 * for support when a new verification replaced one.
	 */
	const PREVIOUS_VERIFICATION_IDS = '_navexa_agecheck_previous_vids';

	/**
	 * The order_external_id sent to AgeCheck, wc:{install_id}:{blog_id}:{order_id}.
	 * Stored once and never recomputed: confirming a copied site rotates the
	 * install_id.
	 */
	const EXTERNAL_ID = '_navexa_agecheck_ext_id';

	/**
	 * 'yes' while a checkout verification still has to be bound with AttachOrder.
	 */
	const ATTACH_PENDING = '_navexa_agecheck_attach_pending';

	/**
	 * Unix time the order was bound to its verification.
	 */
	const BOUND_AT = '_navexa_agecheck_bound_at';

	/**
	 * How it was bound: attach (checkout verification) or create (order-bound).
	 */
	const BOUND_BY = '_navexa_agecheck_bound_by';

	/**
	 * SHA-256 of the canonical ship-to bound with the order (OrderAddress::hash).
	 */
	const SHIP_TO_HASH = '_navexa_agecheck_ship_to';

	/**
	 * 'yes' while this plugin holds the order in Hold::STATUS.
	 */
	const HELD = '_navexa_agecheck_held';

	/**
	 * Unix time the order was first held.
	 */
	const HELD_AT = '_navexa_agecheck_held_at';

	/**
	 * The status the order was on its way to when it was held (processing,
	 * completed, ...): release restores exactly this.
	 */
	const INTERCEPTED_STATUS = '_navexa_agecheck_intercepted_status';

	/**
	 * STATUS_* below.
	 */
	const STATUS = '_navexa_agecheck_status';

	/**
	 * Machine reason for the current state (Release::REASON_*, a clearance reason).
	 */
	const REASON = '_navexa_agecheck_reason';

	/**
	 * GetClearance idempotency key, stored before the call and reused until an
	 * answer is recorded.
	 */
	const CLEARANCE_KEY = '_navexa_agecheck_clearance_key';

	/**
	 * Unix time AgeCheck cleared the order.
	 */
	const CLEARED_AT = '_navexa_agecheck_cleared_at';

	/**
	 * Counter in the order-bound CreateVerification idempotency key, bumped
	 * after a refusal so a corrected request is not a replay.
	 */
	const CREATE_ATTEMPT = '_navexa_agecheck_create_attempt';

	/**
	 * The verification's pass class once it passed (DOC_ONLY, REUSED, ...,
	 * without the PASS_CLASS_ prefix), for the admin panel only.
	 */
	const PASS_CLASS = '_navexa_agecheck_pass_class';

	/**
	 * Verification-link email state: a Links::STATE_* plus ':' and the
	 * verification id it was for.
	 */
	const LINK = '_navexa_agecheck_link';

	/**
	 * The emailed link's id (acl_...), from SendVerificationLink.
	 */
	const LINK_ID = '_navexa_agecheck_link_id';

	/**
	 * Unix time the emailed link stops opening AgeCheck's page (7 days after the send).
	 */
	const LINK_EXPIRES_AT = '_navexa_agecheck_link_expires_at';

	/**
	 * Unix time of the last SendVerificationLink answer that sent a link.
	 */
	const LINK_SENT_AT = '_navexa_agecheck_link_sent_at';

	/**
	 * SendVerificationLink idempotency key, stored before the call and reused
	 * until an answer is recorded (a retry after a lost answer sends no
	 * second email).
	 */
	const LINK_KEY = '_navexa_agecheck_link_key';

	/**
	 * Withdrawal of the verification's emailed links once the order will not
	 * ship (LinkRevoke): a LinkRevoke::STATE_* plus ':' and the verification
	 * id it is for.
	 */
	const REVOKE = '_navexa_agecheck_revoke';

	/**
	 * RevokeVerificationLinks idempotency key, stored before the call and
	 * reused until an answer is recorded.
	 */
	const REVOKE_KEY = '_navexa_agecheck_revoke_key';

	/**
	 * The recorded withdrawal's detail: how many links were still open
	 * (LinkRevoke::STATE_REVOKED), or the error code (STATE_FAILED).
	 */
	const REVOKE_DETAIL = '_navexa_agecheck_revoke_detail';

	/**
	 * Unix time the withdrawal's result was recorded; while it is pending,
	 * since when (renewed each time the sweep queues it again).
	 */
	const REVOKE_AT = '_navexa_agecheck_revoke_at';

	/**
	 * Denied-order refund state: REFUND_*.
	 */
	const REFUND = '_navexa_agecheck_refund';

	/**
	 * Unix time ReportShipment was acknowledged.
	 */
	const SHIPMENT_REPORTED = '_navexa_agecheck_shipment_reported';

	/**
	 * Unix time before which the sweep does not ask AgeCheck about this order again.
	 */
	const NEXT_CHECK_AT = '_navexa_agecheck_next_check_at';

	/**
	 * Unix time of the verification.hold_stale webhook.
	 */
	const HOLD_STALE_AT = '_navexa_agecheck_hold_stale_at';

	/**
	 * 'yes' when the order's verification was created with allowDocRetry
	 * (DocRetry, TAX-1838).
	 */
	const DOC_RETRY = '_navexa_agecheck_doc_retry';

	/**
	 * Unix time the shopper's chance to try the ID check again ends: the
	 * earliest failure the plugin learned of + DocRetry::WINDOW, never past
	 * the verification's creation + DocRetry::CREATED_BOUND, only ever moved
	 * earlier (DocRetry::anchor()).
	 */
	const RETRY_UNTIL = '_navexa_agecheck_retry_until';

	/**
	 * 'yes' while AgeCheck last confirmed that the failed verification may be
	 * retried (DocRetry::defer()'s CreateHostedSession answer); cleared on
	 * every new failure.
	 */
	const RETRY_OPEN = '_navexa_agecheck_retry_open';

	/**
	 * Unix time the 30-day "still held" note was added.
	 */
	const STALE_NOTED_AT = '_navexa_agecheck_stale_noted_at';

	/**
	 * User id of the staff member who released the order without AgeCheck.
	 */
	const OVERRIDE_BY = '_navexa_agecheck_override_by';

	/**
	 * Unix time of that release.
	 */
	const OVERRIDE_AT = '_navexa_agecheck_override_at';

	/**
	 * 'yes' on a WooCommerce Subscriptions renewal order.
	 */
	const RENEWAL = '_navexa_agecheck_renewal';

	/**
	 * "{blog_id}:{order_id}" of the order a cleared or override STATUS was
	 * recorded for (mark_released()). Order meta is copyable: duplicate-order
	 * plugins, imports and WooCommerce Subscriptions copy it to other orders,
	 * so is_released() trusts a release only on the order it was recorded for.
	 */
	const RELEASED_FOR = '_navexa_agecheck_released_for';

	const STATUS_PENDING  = 'pending';
	const STATUS_CLEARED  = 'cleared';
	const STATUS_DENIED   = 'denied';
	const STATUS_OVERRIDE = 'override';

	/**
	 * Refunded through the gateway automatically.
	 */
	const REFUND_REFUNDED = 'refunded';

	/**
	 * Waiting for staff to refund or cancel it (the "Denied: refund required" list).
	 */
	const REFUND_MANUAL = 'manual_required';

	/**
	 * Staff refunded or cancelled it.
	 */
	const REFUND_RESOLVED = 'resolved';

	/**
	 * A string meta value of $order, or ''.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 */
	public static function get( \WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true, 'edit' );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * An integer meta value of $order, or 0.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 */
	public static function get_int( \WC_Order $order, string $key ): int {
		$value = $order->get_meta( $key, true, 'edit' );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Sets $key on $order (unsaved), or deletes it when $value is ''.
	 *
	 * @param \WC_Order  $order Order.
	 * @param string     $key   Meta key.
	 * @param string|int $value Value; '' deletes.
	 */
	public static function set( \WC_Order $order, string $key, $value ): void {
		if ( '' === $value ) {
			$order->delete_meta_data( $key );
			return;
		}
		$order->update_meta_data( $key, $value );
	}

	/**
	 * Adds an order note, prefixed "TEST" while AgeCheck last reported a test
	 * key (Mode::is_test_mode(), stored state only). Returns the note id (0
	 * for an order without an id).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $text  Note text (no shopper identity).
	 */
	public static function note( \WC_Order $order, string $text ): int {
		if ( $order->get_id() <= 0 ) {
			return 0;
		}
		if ( Mode::is_test_mode() ) {
			/* translators: %s: an AgeCheck order note. */
			$text = sprintf( __( 'TEST %s', 'navexa-agecheck-for-woocommerce' ), $text );
		}
		return (int) $order->add_order_note( $text );
	}

	/**
	 * Whether the order may leave the hold: AgeCheck cleared it, or staff
	 * released it with the logged override, on this very order. A release
	 * state copied from another order (RELEASED_FOR names that order) is not
	 * a release.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_released( \WC_Order $order ): bool {
		return in_array( self::get( $order, self::STATUS ), array( self::STATUS_CLEARED, self::STATUS_OVERRIDE ), true )
			&& $order->get_id() > 0
			&& self::get( $order, self::RELEASED_FOR ) === self::owner_tag( $order );
	}

	/**
	 * Whether STATUS says cleared or override but that release was not
	 * recorded for this order (copied from another order, or written without
	 * mark_released()).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function has_foreign_release( \WC_Order $order ): bool {
		return in_array( self::get( $order, self::STATUS ), array( self::STATUS_CLEARED, self::STATUS_OVERRIDE ), true )
			&& ! self::is_released( $order );
	}

	/**
	 * Records that the order may leave the hold (unsaved): STATUS_CLEARED or
	 * STATUS_OVERRIDE, tied to this order.
	 *
	 * @param \WC_Order $order Order (with an id).
	 * @param string    $state STATUS_CLEARED or STATUS_OVERRIDE.
	 */
	public static function mark_released( \WC_Order $order, string $state ): void {
		self::set( $order, self::STATUS, $state );
		self::set( $order, self::RELEASED_FOR, self::owner_tag( $order ) );
	}

	/**
	 * "{blog_id}:{order_id}" of $order, as RELEASED_FOR stores it.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function owner_tag( \WC_Order $order ): string {
		return get_current_blog_id() . ':' . (int) $order->get_id();
	}
}
