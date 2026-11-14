<?php
/**
 * WooCommerce Subscriptions renewals (W1 spike: safe stub, not full support).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal orders that need an age check are held for staff to review.
 *
 * How renewals behave with this plugin (spike findings, W1; not exercised
 * against WooCommerce Subscriptions yet, which is not in the wp-env matrix):
 *
 * - WooCommerce Subscriptions creates a renewal order (pending) and filters
 *   it through wcs_renewal_order_created; on_renewal_created() marks it.
 * - An automatic renewal payment calls payment_complete(), so Hold's filter
 *   answers "Awaiting age check" instead of processing. A manual renewal paid
 *   on order-pay takes the same path. Renewals whose gateway sets the status
 *   itself are caught by the backstop.
 * - Subscriptions activates the subscription when the renewal reaches a
 *   paid status (processing or completed) and puts it on hold for on-hold or
 *   failed. "Awaiting age check" is neither, so the subscription stays as
 *   Subscriptions left it before payment (normally on-hold) until the
 *   renewal is released. Releasing it to processing then activates the
 *   subscription the usual way. (Behaviour inferred from Subscriptions'
 *   documented status handling; unverified.)
 * - AgeCheck cannot reuse the parent order's pass yet (engine PR E12a), and a
 *   new check every billing period would email the shopper each time. Until
 *   reuse ships, a renewal is held with reason subscription_renewal, the
 *   release run makes no AgeCheck call for it, and staff release it with the
 *   logged "release without age check" action after checking (for example
 *   that the parent order passed). The admin panel says so.
 * - When reuse ships, needs_review() changes to: renewal of a parent whose
 *   verification passed -> order-bound CreateVerification with customer_ref
 *   (a $0.10 reuse pass), otherwise the normal accept-and-hold path.
 */
final class Subscriptions {

	/**
	 * Registers the renewal filter. It only fires when Subscriptions is active.
	 */
	public static function register(): void {
		add_filter( 'wcs_renewal_order_created', array( self::class, 'on_renewal_created' ), 10, 2 );
	}

	/**
	 * Marks a renewal order that needs an age check.
	 *
	 * @param mixed $renewal      Renewal order.
	 * @param mixed $subscription Subscription (unused).
	 * @return mixed The renewal order, unchanged otherwise.
	 */
	public static function on_renewal_created( $renewal, $subscription = null ) {
		unset( $subscription );
		if ( ! $renewal instanceof \WC_Order ) {
			return $renewal;
		}
		if ( Requirement::is_required( $renewal ) ) {
			Meta::set( $renewal, Meta::RENEWAL, 'yes' );
			Binding::external_id( $renewal );
		}
		$renewal->save_meta_data();
		return $renewal;
	}

	/**
	 * Whether a held order is a renewal staff must review.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function needs_review( \WC_Order $order ): bool {
		if ( Meta::is_released( $order ) ) {
			return false;
		}
		if ( 'yes' === Meta::get( $order, Meta::RENEWAL ) ) {
			return true;
		}
		return function_exists( 'wcs_order_contains_renewal' ) && (bool) wcs_order_contains_renewal( $order );
	}
}
