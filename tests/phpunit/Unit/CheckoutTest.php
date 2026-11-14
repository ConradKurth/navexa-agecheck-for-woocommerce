<?php
/**
 * Classic and block checkout hooks.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Checkout\Blocks;
use Navexa\AgeCheck\Checkout\BlocksIntegration;
use Navexa\AgeCheck\Checkout\Classic;
use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Checkout\Session;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Frontend;
use Navexa\AgeCheck\GuardToken;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\ShipTo;
use Navexa\AgeCheck\Tests\TestCase;

final class CheckoutTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->respond( 200, $this->live_info() );
		Mode::info( Client::KEY_FULFILMENT );
		$this->http_requests = array();
	}

	/** Classic checkout's posted data for an address. */
	private function posted( array $address, array $extra = array() ): array {
		$data = array();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
				$data[ $type . '_' . $field ] = $address[ $field ];
			}
		}
		return array_merge( $data, $extra );
	}

	private function guard_for( array $address, string $vid = 'acv_1' ): void {
		$mac = ShipTo::from_wc_address( $address )->mac();
		Session::set_record( $vid, $mac, 0, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( Session::sid( true ), $vid, $mac ), $vid );
	}

	private function order( array $address, string $method = 'flat_rate' ): \WC_Order {
		$order                   = new \WC_Order();
		$order->id               = 77;
		$order->billing          = $address;
		$order->shipping         = $address;
		$order->shipping_methods = array( new \WC_Order_Item_Shipping( $method ) );
		return $order;
	}

	// Classic.

	public function test_classic_refuses_an_unverified_checkout(): void {
		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address() ), $errors );
		$this->assertSame( array( 'navexa_agecheck' ), $errors->codes );
		$this->assertSame( 'Verify your age on the checkout page before placing this order.', $errors->messages[0] );
	}

	public function test_classic_admits_a_guarded_pass(): void {
		$this->guard_for( $this->us_address() );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED' ) ) );
		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address( array( 'postcode' => '62701' ) ) ), $errors );
		$this->assertSame( array(), $errors->codes, 'ZIP vs ZIP+4 is the same ship-to' );
		$this->assertSame( 'acv_1', Gate::admitted_verification_id() );
	}

	public function test_classic_refuses_non_us_and_a_changed_address(): void {
		$this->guard_for( $this->us_address() );
		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address( array( 'country' => 'GB' ) ) ), $errors );
		$this->assertStringContainsString( 'only to US addresses', $errors->messages[0] );

		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address( array( 'address_1' => '9 Elm St' ) ) ), $errors );
		$this->assertStringContainsString( 'shipping address changed', $errors->messages[0] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_classic_skips_update_totals_accept_and_hold_unrequired_and_pickup(): void {
		$skips = array(
			'update totals'   => function () {
				return array( 'woocommerce_checkout_update_totals' => '1' );
			},
			'accept and hold' => function () {
				$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
				return array();
			},
			'not required'    => function () {
				unset( $this->options[ Options::MODE ] );
				\Brain\Monkey\Filters\expectApplied( 'navexa_agecheck_cart_required' )->andReturn( false );
				return array();
			},
		);
		foreach ( $skips as $name => $setup ) {
			$errors = new \WP_Error();
			Classic::validate( $this->posted( $this->us_address(), $setup() ), $errors );
			$this->assertSame( array(), $errors->codes, $name );
		}
	}

	public function test_classic_skips_local_pickup(): void {
		$this->chosen_methods = array( 'local_pickup' );
		$errors               = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address( array( 'country' => 'CA' ) ) ), $errors );
		$this->assertSame( array(), $errors->codes, 'owner: pickup orders skip AgeCheck' );
	}

	public function test_classic_resumed_bound_order_must_keep_its_ship_to(): void {
		$order       = $this->order( $this->us_address() );
		$order->id   = 55;
		$order->meta = array(
			Gate::META_VID        => 'acv_bound',
			Gate::META_SHIPTO_MAC => ShipTo::from_wc_address( $this->us_address() )->mac(),
		);
		$order->status         = 'failed';
		$this->orders[55]      = $order;
		$this->session()->set( 'order_awaiting_payment', 55 );

		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address() ), $errors );
		$this->assertSame( array(), $errors->codes, 'a retried payment at the bound ship-to needs no new check' );
		$this->assertSame( array(), $this->http_requests );

		Classic::validate( $this->posted( $this->us_address( array( 'address_1' => '9 Elm St' ) ) ), $errors );
		$this->assertStringContainsString( 'shipping address changed', $errors->messages[0] );
		$this->assertNull( $this->session()->get( 'order_awaiting_payment' ), 'the next attempt makes a new order' );
	}

	public function test_classic_resumed_bound_order_at_a_new_ship_to_keeps_a_check_passed_for_it(): void {
		$order       = $this->order( $this->us_address() );
		$order->id   = 55;
		$order->meta = array(
			Gate::META_VID        => 'acv_bound',
			Gate::META_SHIPTO_MAC => ShipTo::from_wc_address( $this->us_address() )->mac(),
		);
		$order->status    = 'failed';
		$this->orders[55] = $order;
		$this->session()->set( 'order_awaiting_payment', 55 );
		$moved = $this->us_address( array( 'address_1' => '9 Elm St' ) );
		$this->guard_for( $moved, 'acv_new' );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_new', 'PASSED' ) ) );

		$errors = new \WP_Error();
		Classic::validate( $this->posted( $moved ), $errors );
		$this->assertSame( array(), $errors->codes, 'the check passed for the new ship-to admits the new order' );
		$this->assertNull( $this->session()->get( 'order_awaiting_payment' ), 'WooCommerce creates a new order' );
		$this->assertSame( 'acv_new', Gate::admitted_verification_id() );
		$this->assertSame( 'acv_new', Session::guarded_verification_id( ShipTo::from_wc_address( $moved )->mac() ), 'the guard survives' );
		$this->assertSame( array( 'GetVerification' ), array_map( array( $this, 'request_rpc' ), array_keys( $this->http_requests ) ) );
		$this->assertSame( array( 'id' => 'acv_new' ), $this->request_body( 0 ) );
	}

	public function test_classic_drops_the_bound_verification_still_in_the_session(): void {
		$order       = $this->order( $this->us_address() );
		$order->id   = 55;
		$order->meta = array(
			Gate::META_VID        => 'acv_bound',
			Gate::META_SHIPTO_MAC => ShipTo::from_wc_address( $this->us_address() )->mac(),
		);
		$order->status    = 'pending';
		$this->orders[55] = $order;
		$this->session()->set( 'order_awaiting_payment', 55 );
		$this->guard_for( $this->us_address(), 'acv_bound' );

		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address( array( 'address_1' => '9 Elm St' ) ) ), $errors );
		$this->assertStringContainsString( 'shipping address changed', $errors->messages[0] );
		$this->assertNull( Session::record(), 'the bound verification is forgotten' );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_classic_ignores_an_awaiting_order_woocommerce_will_not_resume(): void {
		$order            = $this->order( $this->us_address() );
		$order->meta      = array(
			Gate::META_VID        => 'acv_bound',
			Gate::META_SHIPTO_MAC => ShipTo::from_wc_address( $this->us_address() )->mac(),
		);
		$order->status    = 'pending';
		$order->cart_hash = 'another-cart';
		$this->orders[55] = $order;
		$this->session()->set( 'order_awaiting_payment', 55 );
		$errors = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address() ), $errors );
		$this->assertSame( Gate::message( Gate::REFUSE_VERIFY_FIRST ), $errors->messages[0], 'another cart: a new order, which needs its own check' );
		$this->assertSame( 55, $this->session()->get( 'order_awaiting_payment' ) );

		$order->cart_hash = 'cart-hash';
		$order->status    = 'processing';
		$errors           = new \WP_Error();
		Classic::validate( $this->posted( $this->us_address() ), $errors );
		$this->assertSame( Gate::message( Gate::REFUSE_VERIFY_FIRST ), $errors->messages[0], 'a paid order is never resumed' );
	}

	public function test_classic_ignores_junk_arguments(): void {
		Classic::validate( 'junk', new \WP_Error() );
		Classic::validate( array(), 'junk' );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_classic_button_shows_the_sessions_state_escaped(): void {
		$this->customer_at( $this->us_address() );
		ob_start();
		Classic::render_button();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-status="none"', $html );
		$this->assertStringContainsString( 'data-navexa-agecheck-verify="1"', $html );
		$this->assertStringContainsString( 'Verify age</button>', $html );

		$this->guard_for( $this->us_address() );
		ob_start();
		Classic::render_button();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-status="passed"', $html );
		$this->assertStringContainsString( 'Age verified.', $html );
		$this->assertStringNotContainsString( '<button', $html );

		$this->chosen_methods = array( 'local_pickup' );
		ob_start();
		Classic::render_button();
		$this->assertSame( '', ob_get_clean(), 'nothing for pickup' );
	}

	public function test_classic_enqueues_only_on_the_classic_checkout(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);
		Functions\stubs(
			array(
				'is_checkout'        => true,
				'is_wc_endpoint_url' => false,
				'has_block'          => false,
				'wp_script_is'       => true,
				'wp_add_inline_script' => true,
			)
		);
		Classic::enqueue();
		$this->assertSame( array( Frontend::HANDLE_CLASSIC ), $enqueued );

		Functions\when( 'has_block' )->justReturn( true );
		Classic::enqueue();
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( true );
		Classic::enqueue();
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		Classic::enqueue();
		$this->assertCount( 1, $enqueued );
	}

	// Blocks.

	public function test_blocks_refuses_an_unverified_cart_checkout(): void {
		$order = $this->order( $this->us_address() );
		Blocks::mark_cart_checkout( $order );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array( Gate::message( Gate::REFUSE_VERIFY_FIRST ) ), $errors->messages );
	}

	public function test_blocks_admits_a_guarded_pass_for_the_orders_ship_to(): void {
		$this->guard_for( $this->us_address() );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED' ) ) );
		$order = $this->order( $this->us_address() );
		Blocks::mark_cart_checkout( $order );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes );
	}

	public function test_blocks_api_error_refuses(): void {
		$this->guard_for( $this->us_address() );
		$this->respond( 502, '' );
		$order = $this->order( $this->us_address() );
		Blocks::mark_cart_checkout( $order );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array( Gate::message( Gate::REFUSE_UNAVAILABLE ) ), $errors->messages );
	}

	public function test_blocks_accepts_order_pay_and_skips_pickup_and_unrequired(): void {
		$order  = $this->order( $this->us_address() );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes, 'an existing order (order-pay) takes the accept-and-hold path' );

		$other     = $this->order( $this->us_address() );
		$other->id = 78;
		Blocks::mark_cart_checkout( $other );
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes, 'only the order the cart checkout is placing' );

		$pickup = $this->order( $this->us_address( array( 'country' => 'CA' ) ), 'pickup_location' );
		Blocks::mark_cart_checkout( $pickup );
		Blocks::validate( $pickup, $errors );
		$this->assertSame( array(), $errors->codes, 'pickup' );

		$unrequired        = $this->order( $this->us_address() );
		$unrequired->items = array();
		Blocks::mark_cart_checkout( $unrequired );
		Blocks::validate( $unrequired, $errors );
		$this->assertSame( array(), $errors->codes, 'no line items' );

		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		Blocks::mark_cart_checkout( $order );
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes, 'accept and hold' );
		Blocks::validate( 'junk', $errors );
		Blocks::mark_cart_checkout( 'junk' );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_blocks_refuses_non_us_and_express_orders_without_a_guard(): void {
		$order = $this->order( $this->us_address( array( 'country' => 'PW' ) ) );
		Blocks::mark_cart_checkout( $order );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertStringContainsString( 'only to US addresses', $errors->messages[0] );

		// A product-page express button: its own session, no guard.
		$this->guard_for( $this->us_address() );
		$this->session()->data = array();
		$order                 = $this->order( $this->us_address() );
		Blocks::mark_cart_checkout( $order );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertStringContainsString( 'on the checkout page', $errors->messages[0] );
	}

	/** A reused draft bound to the default US address, now showing $on_order. */
	private function bound_draft( array $on_order ): \WC_Order {
		$order         = $this->order( $on_order );
		$order->status = 'failed';
		$order->meta   = array(
			Gate::META_VID        => 'acv_bound',
			Gate::META_SHIPTO_MAC => ShipTo::from_wc_address( $this->us_address() )->mac(),
		);
		$this->session()->set( 'store_api_draft_order', 77 );
		Blocks::mark_cart_checkout( $order );
		return $order;
	}

	public function test_blocks_bound_draft_with_a_new_ship_to_is_dropped(): void {
		$moved = $this->us_address( array( 'address_1' => '9 Elm St' ) );
		$this->customer_at( $moved );
		$order  = $this->bound_draft( $moved );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertStringContainsString( 'shipping address changed', $errors->messages[0] );
		$this->assertNull( $this->session()->get( 'store_api_draft_order' ) );

		$this->customer_at( $this->us_address() );
		$order  = $this->bound_draft( $this->us_address() );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes );
		$this->assertSame( 77, $this->session()->get( 'store_api_draft_order' ) );
		$this->assertSame( 'acv_bound', Gate::admitted_verification_id() );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_blocks_bound_draft_whose_new_ship_to_was_reverted_is_still_dropped(): void {
		// W1's AddressGuard put the bound address back on the order during the
		// Store API's save; the session customer still holds the new one.
		$moved = $this->us_address( array( 'address_1' => '9 Elm St' ) );
		$this->customer_at( $moved );
		$order  = $this->bound_draft( $this->us_address() );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array( Gate::message( Gate::REFUSE_SHIP_TO_CHANGED ) ), $errors->messages );
		$this->assertNull( $this->session()->get( 'store_api_draft_order' ), 'the next attempt creates a new draft' );
		$this->assertNull( Gate::admitted_verification_id() );
	}

	public function test_blocks_bound_draft_drop_keeps_a_check_passed_for_the_new_ship_to(): void {
		$moved = $this->us_address( array( 'address_1' => '9 Elm St' ) );
		$this->customer_at( $moved );
		$this->guard_for( $moved, 'acv_new' );
		$order  = $this->bound_draft( $this->us_address() );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array( Gate::message( Gate::REFUSE_PLACE_AGAIN ) ), $errors->messages );
		$this->assertNull( $this->session()->get( 'store_api_draft_order' ) );
		$this->assertSame( 'acv_new', Session::guarded_verification_id( ShipTo::from_wc_address( $moved )->mac() ), 'the guard survives' );
		$this->assertSame( array(), $this->http_requests );

		// The next attempt: a new draft at the new ship-to, admitted by that guard.
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_new', 'PASSED' ) ) );
		$draft     = $this->order( $moved );
		$draft->id = 78;
		Blocks::mark_cart_checkout( $draft );
		$errors = new \WP_Error();
		Blocks::validate( $draft, $errors );
		$this->assertSame( array(), $errors->codes );
		$this->assertSame( 'acv_new', Gate::admitted_verification_id() );
	}

	public function test_blocks_bound_draft_is_admitted_only_when_order_and_request_both_keep_the_ship_to(): void {
		// The request asks for the bound address, but the order now shows
		// another one (a plugin changed it): fail closed.
		$this->customer_at( $this->us_address() );
		$order  = $this->bound_draft( $this->us_address( array( 'address_1' => '9 Elm St' ) ) );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertStringContainsString( 'shipping address changed', $errors->messages[0] );
		$this->assertNull( $this->session()->get( 'store_api_draft_order' ) );

		// The request's address is incomplete: refused, not admitted at the order's.
		$this->customer_at( $this->us_address( array( 'address_1' => '' ) ) );
		$order  = $this->bound_draft( $this->us_address() );
		$errors = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array( Gate::message( Gate::REFUSE_NO_ADDRESS ) ), $errors->messages );

		// Outside a shopper session, the order's address decides.
		self::$wc->customer = null;
		$order              = $this->bound_draft( $this->us_address() );
		$errors             = new \WP_Error();
		Blocks::validate( $order, $errors );
		$this->assertSame( array(), $errors->codes );
	}

	public function test_blocks_is_cart_checkout(): void {
		$order = $this->order( $this->us_address() );
		$this->assertFalse( Blocks::is_cart_checkout( $order ) );
		Blocks::mark_cart_checkout( $order );
		$this->assertTrue( Blocks::is_cart_checkout( $order ), 'what order-pay rules skip' );
		$other     = $this->order( $this->us_address() );
		$other->id = 78;
		$this->assertFalse( Blocks::is_cart_checkout( $other ) );
		Blocks::reset();
		$this->assertFalse( Blocks::is_cart_checkout( $order ) );
	}

	public function test_blocks_integration(): void {
		$registry = new class() {
			public $registered = array();

			public function register( $integration ) {
				$this->registered[] = $integration;
			}
		};
		Blocks::register_integration( $registry );
		Blocks::register_integration( 'junk' );
		$this->assertCount( 1, $registry->registered );
		$integration = $registry->registered[0];
		$this->assertInstanceOf( BlocksIntegration::class, $integration );
		$this->assertSame( 'navexa-agecheck', $integration->get_name() );
		$this->assertSame( array( Frontend::HANDLE_BLOCK ), $integration->get_script_handles() );
		$this->assertSame( array(), $integration->get_editor_script_handles() );
		$this->assertSame( 'navexa-agecheck', $integration->get_script_data()['namespace'] );
		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		$this->assertSame( array(), $integration->get_script_handles() );
	}
}
