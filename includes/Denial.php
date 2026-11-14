<?php
/**
 * Orders whose shopper failed the age check.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * A denied order stays held and never ships. What happens to the money is
 * the Options::DENIED_ORDERS setting (owner decision Q11d):
 *
 * - DENIED_AUTO_REFUND (default): refund the remaining amount through the
 *   order's gateway with wc_create_refund(), restocking the items, when the
 *   gateway supports API refunds and the denial came from a live key. A fully
 *   refunded order moves to Refunded (an allowed exit from the hold).
 * - Otherwise, or when the refund fails: list it under "AgeCheck: denied,
 *   refund required" for staff to refund or cancel. Refunding or cancelling
 *   it takes it off the list.
 *
 * A test-mode denial is never refunded automatically: a test key with a live
 * gateway must not move real money.
 */
final class Denial {

	/**
	 * Registers the hooks that take orders off the refund list.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'resolved' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'resolved' ), 10, 1 );
	}

	/**
	 * Records the denial and handles the refund. Idempotent: a second denial
	 * of a denied order changes nothing.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $reason   not_verified, not_us, ...
	 * @param bool      $livemode Whether the fulfilment key is live.
	 * @return string Meta::REFUND_* state, or '' when the order was not denied.
	 */
	public static function deny( \WC_Order $order, string $reason, bool $livemode ): string {
		if ( Meta::is_released( $order ) ) {
			return '';
		}
		$refund = Meta::get( $order, Meta::REFUND );
		if ( Meta::STATUS_DENIED === Meta::get( $order, Meta::STATUS ) && '' !== $refund ) {
			return $refund;
		}
		Meta::set( $order, Meta::STATUS, Meta::STATUS_DENIED );
		Meta::set( $order, Meta::REASON, $reason );
		Meta::set( $order, Meta::NEXT_CHECK_AT, '' );
		if ( 'not_verified' === $reason ) {
			Meta::set( $order, Meta::VERIFICATION_STATUS, 'failed' );
		}
		$order->save_meta_data();
		Meta::note(
			$order,
			'not_us' === $reason
				? __( 'AgeCheck: this order ships outside the US, and AgeCheck verifies US addresses only. It stays held and will not ship.', 'navexa-agecheck-for-woocommerce' )
				: __( 'AgeCheck: the shopper did not pass the age check. The order stays held and will not ship.', 'navexa-agecheck-for-woocommerce' )
		);
		Client::log( 'warning', sprintf( 'agecheck denied order=%d reason=%s', $order->get_id(), sanitize_key( $reason ) ) );

		if ( Options::DENIED_AUTO_REFUND === Options::denied_orders() && $livemode && self::gateway_refunds( $order ) ) {
			$refunded = self::auto_refund( $order );
			if ( '' !== $refunded ) {
				return $refunded;
			}
		}
		return self::list_for_manual_refund( $order );
	}

	/**
	 * Takes a refunded or cancelled order off the refund list.
	 *
	 * @param int|string $order_id Order id.
	 */
	public static function resolved( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || Meta::REFUND_MANUAL !== Meta::get( $order, Meta::REFUND ) ) {
			return;
		}
		Meta::set( $order, Meta::REFUND, Meta::REFUND_RESOLVED );
		$order->save_meta_data();
		Notices::flush_counts();
	}

	/**
	 * Whether the order's gateway can refund it by API.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function gateway_refunds( \WC_Order $order ): bool {
		$gateway = wc_get_payment_gateway_by_order( $order );
		if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'supports' ) ) || ! $gateway->supports( 'refunds' ) ) {
			return false;
		}
		return ! is_callable( array( $gateway, 'can_refund_order' ) ) || (bool) $gateway->can_refund_order( $order );
	}

	/**
	 * Refunds the remaining amount through the gateway and restocks the items.
	 *
	 * @param \WC_Order $order Order.
	 * @return string Meta::REFUND_REFUNDED, or '' when nothing was refunded.
	 */
	private static function auto_refund( \WC_Order $order ): string {
		$amount = (float) $order->get_remaining_refund_amount();
		if ( $amount <= 0 ) {
			return '';
		}
		$order_id = (int) $order->get_id();
		$result   = wc_create_refund(
			array(
				'amount'         => wc_format_decimal( $amount, wc_get_price_decimals() ),
				'reason'         => __( 'Age check failed (Navexa AgeCheck).', 'navexa-agecheck-for-woocommerce' ),
				'order_id'       => $order_id,
				'line_items'     => self::refund_lines( $order ),
				'refund_payment' => true,
				'restock_items'  => true,
			)
		);
		// wc_create_refund() works on its own copy of the order.
		$fresh = wc_get_order( $order_id );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		if ( is_wp_error( $result ) ) {
			Client::log( 'warning', sprintf( 'agecheck auto refund failed order=%d', $order_id ) );
			Meta::note( $order, __( 'AgeCheck: the automatic refund through the payment gateway failed. Refund or cancel this order yourself.', 'navexa-agecheck-for-woocommerce' ) );
			return '';
		}
		Meta::set( $order, Meta::REFUND, Meta::REFUND_REFUNDED );
		$order->save_meta_data();
		Meta::note( $order, __( 'AgeCheck: refunded through the payment gateway and restocked because the age check failed.', 'navexa-agecheck-for-woocommerce' ) );
		Client::log( 'info', sprintf( 'agecheck auto refunded order=%d', $order_id ) );
		return Meta::REFUND_REFUNDED;
	}

	/**
	 * Lists the order for staff to refund or cancel.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function list_for_manual_refund( \WC_Order $order ): string {
		Meta::set( $order, Meta::REFUND, Meta::REFUND_MANUAL );
		$order->save_meta_data();
		Meta::note( $order, __( 'AgeCheck: refund or cancel this order. It is listed under "AgeCheck: denied, refund required" on the Orders screen.', 'navexa-agecheck-for-woocommerce' ) );
		Notices::flush_counts();
		return Meta::REFUND_MANUAL;
	}

	/**
	 * Every line, fee and shipping item with what is left to refund of it, so
	 * the refund restocks products and records its lines like a full refund
	 * from the order screen.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{qty: int|float, refund_total: float, refund_tax: array<int|string, float>}>
	 */
	private static function refund_lines( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product && ! $item instanceof \WC_Order_Item_Fee && ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$item_id = (int) $item_id;
			$qty     = 0;
			if ( $item instanceof \WC_Order_Item_Product ) {
				$qty = max( 0, $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id ) );
			}
			$taxes = $item->get_taxes();
			$tax   = array();
			foreach ( isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : array() as $rate_id => $amount ) {
				$tax[ $rate_id ] = max( 0.0, (float) $amount - (float) $order->get_tax_refunded_for_item( $item_id, (int) $rate_id, $item->get_type() ) );
			}
			$lines[ $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => max( 0.0, (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id, $item->get_type() ) ),
				'refund_tax'   => $tax,
			);
		}
		return $lines;
	}
}
