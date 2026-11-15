<?php
/**
 * Pay-for-order (order-pay) paths.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Checkout;

use Navexa\AgeCheck\AddressGuard;
use Navexa\AgeCheck\Requirement;

defined( 'ABSPATH' ) || exit;

/**
 * Paying for an existing order (admin-created orders, failed-payment retries,
 * PayPal App Switch, express buttons through the Store API) never asks for
 * an age check before payment (owner decision Q11b: accept and hold). The
 * payment is taken, Hold holds the order, and the release run starts an
 * order-bound verification and emails the shopper a link.
 *
 * - Classic order-pay (woocommerce_before_pay_action) records whether the
 *   order needs a check; it never blocks.
 * - Store API order-pay (woocommerce_checkout_validate_order_before_payment
 *   for a non-draft order) refuses a payment that asked to move a bound order
 *   to another ship-to: AgeCheck cleared it for the bound address only.
 *   WooCommerce (9.9 and 11.3) writes the request's addresses onto the order
 *   and saves it before this validation, and AddressGuard reverts the
 *   ship-to during that save, so the refusal reads AddressGuard's record of
 *   the revert instead of unsaved changes. A new checkout is
 *   Checkout\Blocks' to decide: a checkout-draft, and any order the Store
 *   API cart checkout is placing (Blocks::is_cart_checkout(), which may be a
 *   reused pending or failed order), are skipped here.
 */
final class OrderPay {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_pay_action', array( self::class, 'before_pay' ), 10, 1 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( self::class, 'validate_existing_order' ), 10, 2 );
	}

	/**
	 * Classic order-pay: record the requirement, allow the payment.
	 *
	 * @param mixed $order Order.
	 */
	public static function before_pay( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		Requirement::is_required( $order );
		$order->save_meta_data();
	}

	/**
	 * Store API order-pay: refuse a ship-to change on a bound order.
	 *
	 * @param mixed $order  Order about to be paid.
	 * @param mixed $errors WP_Error collecting validation errors.
	 */
	public static function validate_existing_order( $order, $errors ): void {
		if ( ! $order instanceof \WC_Order || ! $errors instanceof \WP_Error || 'checkout-draft' === $order->get_status( 'edit' ) || Blocks::is_cart_checkout( $order ) ) {
			return;
		}
		if ( AddressGuard::locked( $order ) && AddressGuard::ship_to_change_requested( $order ) ) {
			$errors->add(
				'navexa_agecheck_ship_to_locked',
				__( 'This order\'s shipping address is tied to its age verification and cannot be changed. Pay with the original shipping address, or contact the store.', 'navexa-agecheck-for-woocommerce' )
			);
		}
	}
}
