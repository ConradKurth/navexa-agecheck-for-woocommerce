<?php
/**
 * The customer reference sent for per-store reuse.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * CreateVerification.customer_ref (engine E12a): "your own id of the
 * signed-in customer (an account id, never an email address or other contact
 * detail)". While per-store reuse is on for the organization (AGECHECK_REUSE,
 * off in production until counsel signs the notice copy), a customer who
 * passed a check at this store inside its reuse window passes again without
 * a new check (PASS_CLASS_REUSED, billed $0.10; owner decision Q3); while it
 * is off the engine ignores it. Cross-store reuse (E13) stays off.
 *
 * The reference is wpc1_ + hex HMAC-SHA256 of the blog id and the WordPress
 * user id, under a key derived from the install's guard secret
 * (Install::guard_secret(), purpose navexa-agecheck/customer-ref/v1): stable
 * for the customer on this site, meaningless anywhere else, and not the user
 * id itself, so it tells AgeCheck nothing about the account. Confirming a
 * copied site rotates the guard secret, so a staging copy's user 7 is never
 * prod's user 7 to AgeCheck (its customers verify afresh once).
 *
 * Only for a customer the store authenticated: the signed-in user at the
 * pre-payment start, and an order's own customer for an order-bound check
 * (WooCommerce assigns an order to an account only when that customer was
 * signed in, or staff chose it). Guests (id 0) get none.
 */
final class CustomerRef {

	const PREFIX = 'wpc1_';

	/**
	 * The reference for WordPress user $user_id, or '' for a guest or
	 * without a guard secret.
	 *
	 * @param int $user_id User id.
	 */
	public static function for_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$secret = Install::guard_secret();
		if ( '' === $secret ) {
			return '';
		}
		$key = hash_hmac( 'sha256', 'navexa-agecheck/customer-ref/v1', $secret, true );
		return self::PREFIX . hash_hmac( 'sha256', 'blog:' . get_current_blog_id() . '|user:' . $user_id, $key );
	}

	/**
	 * The reference for the order's customer, or '' for a guest order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function for_order( \WC_Order $order ): string {
		return self::for_user( (int) $order->get_customer_id() );
	}

	/**
	 * The reference for the signed-in shopper of this request, or ''.
	 */
	public static function for_current_user(): string {
		return self::for_user( (int) get_current_user_id() );
	}
}
