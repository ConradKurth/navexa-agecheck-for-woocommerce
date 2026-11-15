<?php
/**
 * Block checkout, the Store API and express payment buttons.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Checkout;

use Navexa\AgeCheck\Frontend;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\ShipTo;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-payment enforcement for every Store API checkout: the checkout block,
 * and express payment buttons (WooPayments and Stripe ECE) that place orders
 * through the Store API.
 *
 * The hook woocommerce_checkout_validate_order_before_payment (WooCommerce
 * 9.9+) fires for two routes:
 *
 * - a new checkout (POST /wc/store/v1/checkout), recognised by
 *   woocommerce_store_api_checkout_update_order_meta, which only that route
 *   fires, for the same order. Gate decides on the order's ship-to
 *   (OrderAddress::ship_to_type(): the shipping address, which the Store
 *   API fills with a copy of billing when the cart ships nothing).
 *   A reused draft that is already bound (a retried payment) must still ship
 *   where it was bound to ship, both on the order and in the shopper's
 *   request; otherwise the order is refused and the session's
 *   store_api_draft_order dropped, so the next attempt creates a new order
 *   that needs its own check (one already passed for the new ship-to is
 *   kept, and admits it). An express button on a product page
 *   runs in a separate tokenized-cart session that holds no guard, so it is
 *   refused with "verify on the checkout page".
 * - an existing order (POST /wc/store/v1/checkout/{id}, order-pay): not
 *   decided here. Checkout\OrderPay owns it: pay-for-order takes the
 *   accept-and-hold path (owner decision 2026-11-14), so the payment is
 *   taken, the order is held and the shopper is emailed a verification link,
 *   and only a ship-to change on a bound order is refused. OrderPay skips
 *   every order is_cart_checkout() names, so each Store API order is decided
 *   by exactly one of the two.
 *
 * The refusal is plain text: WooCommerce escapes it.
 *
 * The Verify age button is a checkout inner block (assets/src/blocks/),
 * registered for the editor and forced into the checkout's fields area on
 * the front end (lock.remove), with the loader as a dependency. Its client
 * check (a validation-store error until the shopper passes) only spares a
 * round trip; this hook is the enforcement.
 */
final class Blocks {

	/**
	 * Draft order id the current Store API checkout request is placing.
	 *
	 * @var int
	 */
	private static $cart_checkout_order = 0;

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'mark_cart_checkout' ) );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( self::class, 'validate' ), 10, 2 );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( self::class, 'register_integration' ) );
		add_action( 'init', array( Frontend::class, 'register_block_type' ) );
	}

	/**
	 * Remembers that $order is being placed through the cart checkout route.
	 *
	 * @param mixed $order Draft order.
	 */
	public static function mark_cart_checkout( $order ): void {
		self::$cart_checkout_order = $order instanceof \WC_Order ? $order->get_id() : 0;
	}

	/**
	 * Whether $order is the order the current Store API cart checkout
	 * (POST /checkout) is placing, even when it is a reused pending or failed
	 * order rather than a checkout-draft: Checkout\OrderPay leaves it to
	 * validate().
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_cart_checkout( \WC_Order $order ): bool {
		return 0 !== self::$cart_checkout_order && $order->get_id() === self::$cart_checkout_order;
	}

	/**
	 * Forgets the request's cart checkout (tests).
	 */
	public static function reset(): void {
		self::$cart_checkout_order = 0;
	}

	/**
	 * Refuses a Store API checkout that has not passed.
	 *
	 * @param mixed $order  Order about to be paid.
	 * @param mixed $errors WP_Error to add refusals to.
	 */
	public static function validate( $order, $errors ): void {
		if ( ! $order instanceof \WC_Order || ! $errors instanceof \WP_Error || ! Gate::enforces() ) {
			return;
		}
		if ( ! self::is_cart_checkout( $order ) ) {
			return; // An existing order (order-pay): accept and hold.
		}
		$vid   = Gate::bound_verification_id( $order );
		$bound = '' !== $vid ? $order : null;
		// The rules alone decide, never the stored answer, and nothing is
		// recorded: WooCommerce saves a refused draft and reuses it whatever
		// the cart holds next, so a sticky REQUIRED written here would still
		// gate it after the shopper removed the restricted items or chose
		// local pickup. Binding and Hold record the answer once the order is
		// placed. A reused order that is already bound keeps its bound ship-to.
		if ( null === $bound && ! Requirement::evaluate( $order )['required'] ) {
			return; // No restricted items, an exempt customer, or local pickup.
		}
		$decided = null === $bound
			? array( ShipTo::from_order( $order ), ShipTo::country_of_order( $order ) )
			: self::bound_order_ship_to( $order );
		$refusal = Gate::check( $decided[0], $decided[1], $bound );
		if ( null === $refusal ) {
			return;
		}
		if ( $refusal['clear_resumable'] ) {
			// The next attempt creates a new draft. A check the shopper already
			// passed for the new ship-to stays and admits it.
			$session = Session::wc_session();
			if ( null !== $session ) {
				$session->set( 'store_api_draft_order', null );
			}
			Session::forget_verification_of( $vid );
		}
		$errors->add( 'navexa_agecheck', $refusal['message'] );
	}

	/**
	 * The ship-to (and its country) to decide a reused bound order on.
	 *
	 * The Store API writes the request's addresses onto the reused order and
	 * saves it before this hook, and AddressGuard reverts a bound order's
	 * ship-to during that save, so the order can still show its bound address
	 * while the shopper asked for another one; the session customer holds
	 * what they asked for. The order is admitted only when both are its bound
	 * ship-to: when they differ, the one that is not decides (and refuses).
	 *
	 * @param \WC_Order $order Bound order.
	 * @return array{0: ShipTo|null, 1: string}
	 */
	private static function bound_order_ship_to( \WC_Order $order ): array {
		$on_order = array( ShipTo::from_order( $order ), ShipTo::country_of_order( $order ) );
		$customer = Session::customer();
		if ( null === $customer ) {
			return $on_order;
		}
		$needs_shipping = $order->needs_shipping_address();
		$asked          = ShipTo::from_customer( $customer, $needs_shipping );
		$same           = null === $asked ? null === $on_order[0] : ( null !== $on_order[0] && $asked->equals( $on_order[0] ) );
		if ( $same || ( null !== $asked && Gate::is_bound_ship_to( $order, $asked ) ) ) {
			return $on_order;
		}
		return array( $asked, ShipTo::country_of_customer( $customer, $needs_shipping ) );
	}

	/**
	 * Registers the checkout block integration (script handles and data).
	 *
	 * @param mixed $registry WooCommerce's IntegrationRegistry.
	 */
	public static function register_integration( $registry ): void {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) && interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			$registry->register( new BlocksIntegration() );
		}
	}
}
