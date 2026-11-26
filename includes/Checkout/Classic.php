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
 * Pre-payment enforcement and the age check status on the classic checkout.
 * The check opens at Place order (assets/src/loader/classic.ts, the shared
 * loader's in-page modal); this class renders the state it starts from and
 * decides every order on the server.
 *
 * - woocommerce_after_checkout_validation refuses the order unless Gate
 *   admits it, with the ship-to taken from the posted data (shipping_*, or
 *   billing_* when WooCommerce posts no shipping address). Skipped for the
 *   no-JavaScript "Update totals" post, for carts that need no check and for
 *   local pickup. A refusal also has WooCommerce update the checkout
 *   (refresh_after_refusal()), so the status the next Place order reads is
 *   the server's.
 * - A resumed order_awaiting_payment that is already bound (a retried
 *   payment, Gate::bound_awaiting_order()) must still ship where it was bound
 *   to ship. Otherwise it is dropped from the session, so WooCommerce creates
 *   a new order, and that order is decided like any new checkout (a check
 *   already passed for the new ship-to admits it). At its bound ship-to the
 *   button shows the check as passed (Start::state() for
 *   Gate::ROUTE_CLASSIC, which asks only order_awaiting_payment: a Store API
 *   draft is not what this checkout resumes).
 * - The status renders at woocommerce_review_order_before_submit, inside the
 *   fragment WooCommerce replaces on every checkout update, so it always
 *   shows the session's current state (data-status, read by the glue). No
 *   button: Place order starts the check.
 * - A lock bar ("Your name and address are locked to your age check" and a
 *   Change details button) renders above the customer details, outside the
 *   replaced fragment, hidden until the glue locks the fields after a pass.
 *   The lock is consistency, not a control: the server binds the ship-to.
 * - Each checkout update copies the form's billing first and last name, and
 *   the billing email, into the session customer (remember_billing_name()),
 *   as the block checkout's Store API does. The glue runs that update before
 *   every start at Place order, so a start sends the name the shopper typed
 *   for the engine's data match (Start::identity()) and a guest's email for
 *   the returning-guest code (Start::guest_email(), TAX-1839).
 * - Outage fallback (TAX-1841): a start answering hold has the glue submit
 *   with no modal; validate() admits that order only on the session's own
 *   server-observed outage (Gate::check()).
 */
final class Classic {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate' ), 10, 2 );
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_status' ) );
		add_action( 'woocommerce_checkout_before_customer_details', array( self::class, 'render_lock_bar' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'remember_billing_name' ) );
	}

	/**
	 * Copies the billing first and last name, and the billing email when
	 * is_email() accepts it (TAX-1839), of the classic checkout form into the
	 * session customer. WooCommerce's checkout update (update_order_review)
	 * saves the posted billing address but not the name or the email, while
	 * the block checkout saves them through the Store API; the start reads
	 * them from the session (Start::identity(), the engine's data match, and
	 * Start::guest_email(), the returning-guest code). WooCommerce saves the
	 * customer right after this action. Only in pre-payment mode and for a
	 * cart that needs a check; the ship-to, the guard and the gate never read
	 * the name or the email.
	 *
	 * @param mixed $post_data The checkout form, URL-encoded (already unslashed).
	 */
	public static function remember_billing_name( $post_data ): void {
		if ( ! is_string( $post_data ) || '' === $post_data || ! Gate::enforces() || ! Gate::cart_required() ) {
			return;
		}
		$customer = Session::customer();
		if ( null === $customer ) {
			return;
		}
		$fields = array();
		parse_str( $post_data, $fields );
		if ( isset( $fields['billing_first_name'] ) && is_string( $fields['billing_first_name'] ) ) {
			$customer->set_billing_first_name( wc_clean( $fields['billing_first_name'] ) );
		}
		if ( isset( $fields['billing_last_name'] ) && is_string( $fields['billing_last_name'] ) ) {
			$customer->set_billing_last_name( wc_clean( $fields['billing_last_name'] ) );
		}
		if ( isset( $fields['billing_email'] ) && is_string( $fields['billing_email'] ) ) {
			$email = sanitize_email( trim( $fields['billing_email'] ) );
			if ( '' !== $email && false !== is_email( $email ) ) {
				$customer->set_billing_email( $email );
			}
		}
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
		$resumed = Gate::bound_awaiting_order();
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
		self::refresh_after_refusal();
	}

	/**
	 * Has WooCommerce run a checkout update after this refused order: with
	 * refresh_totals in the session, WC_Checkout::send_ajax_failure_response()
	 * answers refresh, and checkout.js triggers update_checkout, which
	 * renders the status fragment from the session's state after the
	 * refusal. The page can still say passed when the server refuses (a
	 * guard that expired, a ship-to changed past the lock, a check an earlier
	 * order took), and the glue answers Place order without stepping in while
	 * it says passed; since 1.1.0 there is no Verify age button, so without
	 * the update every Place order would post again into the same refusal
	 * instead of starting the check. WooCommerce clears the flag in that
	 * answer, or in the next update when the post was not AJAX.
	 */
	private static function refresh_after_refusal(): void {
		$session = Session::wc_session();
		if ( null !== $session ) {
			$session->set( 'refresh_totals', true );
		}
	}

	/**
	 * The age check status above the Place order button: no button, the
	 * check opens when the shopper places the order.
	 */
	public static function render_status(): void {
		if ( ! Gate::enforces() ) {
			return;
		}
		$state = Start::state( Gate::ROUTE_CLASSIC );
		if ( empty( $state['required'] ) ) {
			return;
		}
		$status  = isset( $state['status'] ) && is_string( $state['status'] ) ? $state['status'] : Start::STATUS_NONE;
		$message = isset( $state['message'] ) && is_string( $state['message'] ) ? $state['message'] : '';
		$passed  = Start::STATUS_PASSED === $status;
		if ( '' === $message ) {
			$message = $passed
				? __( 'Age verified.', 'navexa-agecheck-for-woocommerce' )
				: __( 'This order contains age-restricted items. When you place it, you\'ll confirm your age in a secure window on this page.', 'navexa-agecheck-for-woocommerce' );
		}
		// The status line is not a live region here: WooCommerce replaces this
		// fragment on every update, so the classic glue announces changes
		// through one region outside it. tabindex -1 lets the glue focus it.
		printf(
			'<div class="navexa-agecheck" data-navexa-agecheck="1" data-status="%1$s" aria-busy="false" style="margin:0 0 1em">'
			. '<p class="navexa-agecheck__message" id="navexa-agecheck-message" tabindex="-1" style="margin:0 0 .5em">%2$s</p>',
			esc_attr( $status ),
			esc_html( $message )
		);
		if ( ! $passed && Turnstile::enabled() ) {
			// A group the glue can focus when Cloudflare shows a challenge in it.
			printf(
				'<div class="navexa-agecheck__turnstile" data-navexa-agecheck-turnstile="1" role="group" aria-label="%s" tabindex="-1"></div>',
				esc_attr__( 'Security check', 'navexa-agecheck-for-woocommerce' )
			);
		}
		echo '</div>';
	}

	/**
	 * The lock bar above the customer details: hidden until the glue locks
	 * the name and address fields after a pass; Change details unlocks them.
	 * Only on a cart that needs a check, in pre-payment mode.
	 */
	public static function render_lock_bar(): void {
		if ( ! Gate::enforces() || ! Gate::cart_required() ) {
			return;
		}
		printf(
			'<div class="navexa-agecheck-lock woocommerce-info" data-navexa-agecheck-lock="1" hidden style="display:none">%1$s <button type="button" class="button" data-navexa-agecheck-unlock="1">%2$s</button></div>',
			esc_html__( 'Your name and address are locked to your age check.', 'navexa-agecheck-for-woocommerce' ),
			esc_html__( 'Change details', 'navexa-agecheck-for-woocommerce' )
		);
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
