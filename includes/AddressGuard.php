<?php
/**
 * Keeps a bound order's ship-to address.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * AgeCheck clears an order for the ship-to bound with it and never re-checks
 * the address after release, so changing the shipping address of a bound
 * order would ship a verified order somewhere nobody cleared (port of
 * Zavro's order-shipping-edit.ts).
 *
 * Before every save of an order bound to a verification (or waiting to be
 * bound), a change to the address fields AgeCheck matches (line 1, line 2,
 * postcode, country; city and state too, so the label stays consistent) is
 * reverted, with an order note and an admin notice. Name, company and phone
 * stay editable. Exception: an order AgeCheck held for ship_to_mismatch may be
 * edited, since restoring the verified address is the fix.
 */
final class AddressGuard {

	/**
	 * Address fields that are restored.
	 */
	const FIELDS = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * Orders whose ship-to change was reverted in this request, by id. The
	 * Store API saves the request's addresses before it validates a payment
	 * (CheckoutOrder::update_billing_address() then
	 * validate_existing_order_before_payment()), so by validation time the
	 * change is gone and only this record says it was asked for.
	 *
	 * @var array<int, bool>
	 */
	private static $reverted = array();

	/**
	 * Registers the guard after Hold's backstop.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'guard' ), 20, 1 );
	}

	/**
	 * Reverts a ship-to change on a locked order.
	 *
	 * @param mixed $order Order being saved.
	 */
	public static function guard( $order ): void {
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || $order->get_id() <= 0 ) {
			return;
		}
		if ( ! self::locked( $order ) || ! self::ship_to_changed( $order ) ) {
			return;
		}
		self::$reverted[ (int) $order->get_id() ] = true;

		$original = self::original( $order );
		foreach ( self::FIELDS as $field ) {
			$setter = 'set_shipping_' . $field;
			$order->{$setter}( $original[ $field ] );
		}
		$message = __( 'AgeCheck: this order\'s shipping address is tied to its age check, so the change to the street, city, state, ZIP or country was undone. Name, company and phone can still be edited. To ship elsewhere, cancel the order and place a new one.', 'navexa-agecheck-for-woocommerce' );
		Meta::note( $order, $message );
		Notices::flash( $message );
		Client::log( 'warning', sprintf( 'agecheck ship-to change reverted order=%d', $order->get_id() ) );
	}

	/**
	 * Whether this request tried to move $order to another ship-to (and the
	 * guard undid it), or is about to: unsaved changes still do.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function ship_to_change_requested( \WC_Order $order ): bool {
		return isset( self::$reverted[ (int) $order->get_id() ] ) || self::ship_to_changed( $order );
	}

	/**
	 * Whether the order's ship-to is tied to a verification.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function locked( \WC_Order $order ): bool {
		if ( '' === Meta::get( $order, Meta::VERIFICATION_ID ) && 'yes' !== Meta::get( $order, Meta::ATTACH_PENDING ) ) {
			return false;
		}
		return 'ship_to_mismatch' !== Meta::get( $order, Meta::REASON );
	}

	/**
	 * Whether unsaved changes move the order to another ship-to.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function ship_to_changed( \WC_Order $order ): bool {
		$changes = $order->get_changes();
		if ( empty( $changes['shipping'] ) || ! is_array( $changes['shipping'] ) ) {
			return false;
		}
		$before = self::original( $order );
		$after  = OrderAddress::parts( $order, 'shipping' );
		foreach ( self::FIELDS as $field ) {
			if ( ShipTo::fold( $before[ $field ] ) !== ShipTo::fold( $after[ $field ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The saved shipping address fields.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	private static function original( \WC_Order $order ): array {
		$base     = $order->get_base_data();
		$shipping = isset( $base['shipping'] ) && is_array( $base['shipping'] ) ? $base['shipping'] : array();
		$out      = array();
		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = isset( $shipping[ $field ] ) && is_scalar( $shipping[ $field ] ) ? (string) $shipping[ $field ] : '';
		}
		return $out;
	}
}
