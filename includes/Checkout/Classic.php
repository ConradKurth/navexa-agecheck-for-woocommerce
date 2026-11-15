<?php
/**
 * Classic (shortcode) checkout.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Checkout;

use Navexa\AgeCheck\Frontend;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\ShipTo;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Turnstile;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-payment enforcement and the Verify age button on the classic checkout.
 *
 * - woocommerce_after_checkout_validation refuses the order unless Gate
 *   admits it, with the ship-to taken from the posted data (shipping_*, or
 *   billing_* when WooCommerce posts no shipping address). Skipped for the
 *   no-JavaScript "Update totals" post, for carts that need no check and for
 *   local pickup.
 * - A resumed order_awaiting_payment that is already bound (a retried
 *   payment) must still ship where it was bound to ship. Otherwise it is
 *   dropped from the session, so WooCommerce creates a new order, and that
 *   order is decided like any new checkout (a check already passed for the
 *   new ship-to admits it).
 * - The button renders at woocommerce_review_order_before_submit, inside the
 *   fragment WooCommerce replaces on every checkout update, so it always
 *   shows the session's current state.
 */
final class Classic {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate' ), 10, 2 );
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_button' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Refuses a classic checkout that has not passed.
	 *
	 * @param mixed $data   WC_Checkout::get_posted_data().
	 * @param mixed $errors WP_Error to add refusals to.
	 */
	public static function validate( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error || ! Gate::enforces() ) {
			return;
		}
		if ( ! empty( $data['woocommerce_checkout_update_totals'] ) ) {
			return; // Not an order: the no-JavaScript "Update totals" button.
		}
		if ( ! Gate::cart_required() || Requirement::cart_is_pickup() ) {
			return;
		}
		$ship_to = ShipTo::from_checkout_data( $data );
		$country = ShipTo::country_from_checkout_data( $data );
		$resumed = self::resumable_bound_order();
		$refusal = Gate::check( $ship_to, $country, $resumed );
		if ( null !== $refusal && $refusal['clear_resumable'] && null !== $resumed ) {
			// The resumed order was bound for another ship-to. Stop resuming it,
			// so create_order() (which runs after this hook) makes a new order,
			// and decide that order on the session's own check: the shopper may
			// already have passed one for the new ship-to, which must survive.
			$session = Session::wc_session();
			if ( null !== $session ) {
				$session->set( 'order_awaiting_payment', null );
			}
			Session::forget_verification_of( Gate::bound_verification_id( $resumed ) );
			$refusal = Gate::check( $ship_to, $country );
			if ( null !== $refusal && Gate::REFUSE_VERIFY_FIRST === $refusal['code'] ) {
				$refusal['message'] = Gate::message( Gate::REFUSE_SHIP_TO_CHANGED );
			}
		}
		if ( null === $refusal ) {
			return;
		}
		$errors->add( 'navexa_agecheck', $refusal['message'] );
	}

	/**
	 * The order WooCommerce's create_order() will resume (same cart hash,
	 * pending or failed), when it is already bound to a verification.
	 */
	private static function resumable_bound_order(): ?\WC_Order {
		$session = Session::wc_session();
		$cart    = Session::cart();
		if ( null === $session || null === $cart ) {
			return null;
		}
		$order_id = absint( $session->get( 'order_awaiting_payment' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || ! $order->has_cart_hash( $cart->get_cart_hash() ) || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return null;
		}
		return '' !== Gate::bound_verification_id( $order ) ? $order : null;
	}

	/**
	 * The Verify age block above the Place order button.
	 */
	public static function render_button(): void {
		if ( ! Gate::enforces() ) {
			return;
		}
		$state = Start::state();
		if ( empty( $state['required'] ) ) {
			return;
		}
		$status  = isset( $state['status'] ) && is_string( $state['status'] ) ? $state['status'] : Start::STATUS_NONE;
		$message = isset( $state['message'] ) && is_string( $state['message'] ) ? $state['message'] : '';
		$passed  = Start::STATUS_PASSED === $status;
		if ( '' === $message ) {
			$message = $passed
				? __( 'Age verified.', 'navexa-agecheck-for-woocommerce' )
				: __( 'This order contains age-restricted items. Verify your age to place it.', 'navexa-agecheck-for-woocommerce' );
		}
		// The status line is not a live region here: WooCommerce replaces this
		// fragment on every update, so the classic glue announces changes
		// through one region outside it. tabindex -1 lets the glue move the
		// focus to the status once the button is gone (after a pass).
		printf(
			'<div class="navexa-agecheck" data-navexa-agecheck="1" data-status="%1$s" aria-busy="false" style="margin:0 0 1em">'
			. '<p class="navexa-agecheck__message" id="navexa-agecheck-message" tabindex="-1" style="margin:0 0 .5em">%2$s</p>',
			esc_attr( $status ),
			esc_html( $message )
		);
		if ( ! $passed ) {
			if ( Turnstile::enabled() ) {
				// A group the glue can focus when Cloudflare shows a challenge in it.
				printf(
					'<div class="navexa-agecheck__turnstile" data-navexa-agecheck-turnstile="1" role="group" aria-label="%s" tabindex="-1"></div>',
					esc_attr__( 'Security check', 'navexa-agecheck-for-woocommerce' )
				);
			}
			printf(
				'<button type="button" class="button alt navexa-agecheck__verify" data-navexa-agecheck-verify="1" aria-describedby="navexa-agecheck-message" aria-disabled="false"%2$s>%1$s</button>',
				esc_html__( 'Verify age', 'navexa-agecheck-for-woocommerce' ),
				Start::STATUS_PENDING === $status ? ' data-pending="1"' : ''
			);
		}
		echo '</div>';
	}

	/**
	 * Loads the loader and the classic glue on a classic checkout page.
	 */
	public static function enqueue(): void {
		if ( ! Gate::enforces() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return; // Block checkout: Blocks handles it.
		}
		Frontend::enqueue_classic();
	}
}
