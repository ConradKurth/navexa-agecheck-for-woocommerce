<?php
/**
 * Gate tests: what a pre-payment checkout needs to be admitted.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Checkout\Session;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\GuardToken;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\OrderAddress;
use Navexa\AgeCheck\Requirement;
use Navexa\AgeCheck\ShipTo;
use Navexa\AgeCheck\Tests\TestCase;

final class GateTest extends TestCase {

	/** @var ShipTo */
	private $ship_to;

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->respond( 200, $this->live_info() );
		Mode::info( Client::KEY_FULFILMENT );
		$this->http_requests = array();
		$this->ship_to       = ShipTo::from_wc_address( $this->us_address() );
	}

	private function guard( string $vid = 'acv_1', ?ShipTo $ship_to = null ): void {
		$ship_to = null === $ship_to ? $this->ship_to : $ship_to;
		Session::set_record( $vid, $ship_to->mac(), 0, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( Session::sid( true ), $vid, $ship_to->mac() ), $vid );
	}

	public function test_admits_a_guarded_passed_verification_read_with_the_fulfilment_key(): void {
		$this->guard();
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED' ) ) );

		$this->assertNull( Gate::check( $this->ship_to, 'US' ) );
		$this->assertSame( 'acv_1', Gate::admitted_verification_id() );
		$this->assertSame( 'GetVerification', $this->request_rpc( 0 ) );
		$this->assertSame( array( 'id' => 'acv_1' ), $this->request_body( 0 ) );
		$this->assertSame( self::LIVE_FULFILMENT_KEY, $this->request_key( 0 ) );
	}

	public function test_address_problems_refuse_before_any_call(): void {
		$this->assertSame( Gate::REFUSE_NO_ADDRESS, Gate::check( null, '' )['code'] );
		$this->assertSame( Gate::REFUSE_NO_ADDRESS, Gate::check( null, 'US' )['code'] );
		$this->assertSame( Gate::REFUSE_US_ONLY, Gate::check( null, 'CA' )['code'] );
		$this->assertSame( Gate::REFUSE_US_ONLY, Gate::check( ShipTo::from_wc_address( $this->us_address( array( 'country' => 'FM' ) ) ), 'FM' )['code'] );
		$this->assertNull( Gate::admitted_verification_id() );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_without_a_guard_the_shopper_must_verify(): void {
		$refusal = Gate::check( $this->ship_to, 'US' );
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, $refusal['code'] );
		$this->assertSame( 'Verify your age on the checkout page before placing this order.', $refusal['message'] );
		$this->assertFalse( $refusal['clear_resumable'] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_a_guard_for_another_ship_to_asks_to_verify_again(): void {
		$this->guard();
		$moved = ShipTo::from_wc_address( $this->us_address( array( 'address_1' => '9 Elm St' ) ) );
		$this->assertSame( Gate::REFUSE_SHIP_TO_CHANGED, Gate::check( $moved, 'US' )['code'] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_a_failed_record_refuses_as_failed(): void {
		Session::sid( true );
		Session::set_record( 'acv_1', $this->ship_to->mac(), 0, Session::STATUS_FAILED );
		$this->assertSame( Gate::REFUSE_FAILED, Gate::check( $this->ship_to, 'US' )['code'] );
	}

	public function test_a_guard_from_another_session_does_not_count(): void {
		$this->guard();
		$this->session()->set( Session::KEY_SID, 'ffffffffffffffffffffffffffffffff' );
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $this->ship_to, 'US' )['code'] );
	}

	public function test_the_server_decides_not_the_guard(): void {
		$cases = array(
			'failed'          => array( $this->verification( 'acv_1', 'FAILED' ), Gate::REFUSE_FAILED ),
			'needs doc'       => array( $this->verification( 'acv_1', 'NEEDS_DOC' ), Gate::REFUSE_VERIFY_FIRST ),
			'db unattested'   => array( $this->verification( 'acv_1', 'PASSED', array( 'passClass' => 'PASS_CLASS_DB_UNATTESTED' ) ), Gate::REFUSE_VERIFY_FIRST ),
			'no pass class'   => array( array_diff_key( $this->verification( 'acv_1', 'PASSED' ), array( 'passClass' => 1 ) ), Gate::REFUSE_VERIFY_FIRST ),
			'test under live' => array( $this->verification( 'acv_1', 'PASSED', array( 'livemode' => false ) ), Gate::REFUSE_UNAVAILABLE ),
			'bound elsewhere' => array( $this->verification( 'acv_1', 'PASSED', array( 'orderExternalId' => 'wc:x:1:9' ) ), Gate::REFUSE_VERIFY_FIRST ),
		);
		foreach ( $cases as $name => $case ) {
			$this->guard();
			$this->respond( 200, array( 'verification' => $case[0] ) );
			$this->assertSame( $case[1], Gate::check( $this->ship_to, 'US' )['code'], $name );
			$this->assertNull( Gate::admitted_verification_id(), $name );
		}
		$this->assertNull( Session::record(), 'a verification bound elsewhere is forgotten' );
	}

	public function test_errors_refuse_as_unavailable(): void {
		$this->guard();
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertSame( Gate::REFUSE_UNAVAILABLE, Gate::check( $this->ship_to, 'US' )['code'] );

		$this->respond_transport_error( 'cURL error 28: Operation timed out' );
		$this->assertSame( Gate::REFUSE_UNAVAILABLE, Gate::check( $this->ship_to, 'US' )['code'] );

		$this->respond( 200, '' );
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $this->ship_to, 'US' )['code'], 'an empty answer is no pass' );

		$this->respond( 404, array( 'code' => 'not_found' ) );
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $this->ship_to, 'US' )['code'] );
		$this->assertNull( Session::record(), 'an unknown verification is forgotten' );
	}

	public function test_unknown_mode_refuses(): void {
		Mode::flush();
		$this->guard();
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED' ) ) );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertSame( Gate::REFUSE_UNAVAILABLE, Gate::check( $this->ship_to, 'US' )['code'] );
	}

	public function test_paused_or_secretless_installs_refuse(): void {
		$this->guard();
		$this->home = 'https://copy.example';
		$this->assertSame( Gate::REFUSE_UNAVAILABLE, Gate::check( $this->ship_to, 'US' )['code'] );
		$this->home = 'https://shop.example';
		unset( $this->options[ Install::OPTION_GUARD_SECRET ] );
		$this->assertSame( Gate::REFUSE_UNAVAILABLE, Gate::check( $this->ship_to, 'US' )['code'] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_a_bound_order_resumes_only_at_its_bound_ship_to(): void {
		$order       = \WC_Order::make( 'pending', 42 );
		$order->meta = array(
			Meta::VERIFICATION_ID => 'acv_bound',
			Meta::SHIP_TO_HASH    => $this->ship_to->hash(),
		);
		$this->assertNull( Gate::check( $this->ship_to, 'US', $order ), 'same ship-to: admitted with no call' );
		$this->assertSame( 'acv_bound', Gate::admitted_verification_id() );
		$this->assertSame( array(), $this->http_requests );

		$moved   = ShipTo::from_wc_address( $this->us_address( array( 'postcode' => '10001' ) ) );
		$refusal = Gate::check( $moved, 'US', $order );
		$this->assertSame( Gate::REFUSE_SHIP_TO_CHANGED, $refusal['code'] );
		$this->assertTrue( $refusal['clear_resumable'] );

		// A check already passed for the new ship-to: "place your order again".
		$this->guard( 'acv_new', $moved );
		$this->assertSame( Gate::REFUSE_PLACE_AGAIN, Gate::check( $moved, 'US', $order )['code'] );
		$this->guard( 'acv_bound', $moved );
		$this->assertSame( Gate::REFUSE_SHIP_TO_CHANGED, Gate::check( $moved, 'US', $order )['code'], 'never the bound verification itself' );
		Session::forget_verification();

		$this->assertSame( OrderAddress::hash( $this->ship_to->engine_address() ), $this->ship_to->hash(), 'the hash Binding stores' );
		$this->assertStringContainsString( 'reason=bound_ship_to_changed order=42', $this->all_logs() );

		unset( $order->meta[ Meta::SHIP_TO_HASH ] );
		$this->assertTrue( Gate::check( $this->ship_to, 'US', $order )['clear_resumable'], 'no bound ship-to: fail closed' );
		$order->meta['_navexa_agecheck_shipto_mac'] = $this->ship_to->mac();
		$this->assertTrue( Gate::check( $this->ship_to, 'US', $order )['clear_resumable'], 'a guard MAC is not a binding' );
	}

	public function test_verdict_reads_enum_names_and_numbers(): void {
		$this->assertSame( 'passed', Gate::verdict( array( 'status' => 3, 'passClass' => 3, 'livemode' => true ), Client::KEY_FULFILMENT ) );
		$this->assertSame( 'not_clearing', Gate::verdict( array( 'status' => 3, 'passClass' => 2, 'livemode' => true ), Client::KEY_FULFILMENT ) );
		$this->assertSame( 'failed', Gate::verdict( array( 'status' => 7 ), Client::KEY_FULFILMENT ) );
		$this->assertSame( 'pending', Gate::verdict( array(), Client::KEY_FULFILMENT ) );
		$this->assertSame( 'pending', Gate::verdict( array( 'status' => array() ), Client::KEY_FULFILMENT ) );
		$this->assertSame( 'passed', Gate::verdict( $this->verification( 'acv_1', 'PASSED', array( 'passClass' => 'PASS_CLASS_DATA_VERIFIED' ) ), Client::KEY_FULFILMENT ), 'new clearing classes clear' );
		$this->assertSame( 'passed', Gate::verdict( $this->verification( 'acv_1', 'PASSED', array( 'passClass' => 'PASS_CLASS_REUSED' ) ), Client::KEY_FULFILMENT ), 'a returning customer\'s reused pass clears (E12a)' );
		$this->assertSame( 'passed', Gate::verdict( array( 'status' => 3, 'passClass' => 6, 'livemode' => true ), Client::KEY_FULFILMENT ), 'PASS_CLASS_REUSED by number' );
		$this->assertSame( 'mode_mismatch', Gate::verdict( $this->verification( 'acv_1', 'PASSED', array( 'livemode' => false ) ), Client::KEY_FULFILMENT ) );
	}

	public function test_mode_and_requirement_helpers(): void {
		$this->assertTrue( Gate::enforces() );
		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		$this->assertFalse( Gate::enforces() );

		$this->assertTrue( Gate::cart_required() );
		self::$wc->cart->empty = true;
		$this->assertFalse( Gate::cart_required() );
		self::$wc->cart->empty = false;
		self::$wc->cart->items = array( 'item-1' => array( 'data' => new \WC_Product( 8, 0, array( Requirement::PRODUCT_META => Requirement::PRODUCT_EXEMPT ) ) ) );
		$this->assertFalse( Gate::cart_required(), 'the Requirement rules decide, as for the order' );
		self::$wc->cart = null;
		$this->assertFalse( Gate::cart_required() );
	}

	// The one pickup list (Requirement), read by the gate and the hold path.

	public function test_pickup_is_every_chosen_method_being_pickup(): void {
		$this->chosen_methods = array( 'local_pickup' );
		$this->assertTrue( Requirement::cart_is_pickup() );
		$this->chosen_methods = array( 'pickup_location', 'legacy_local_pickup' );
		$this->assertTrue( Requirement::cart_is_pickup() );
		$this->chosen_methods = array( 'local_pickup', 'flat_rate' );
		$this->assertFalse( Requirement::cart_is_pickup(), 'one package ships' );
		$this->chosen_methods = array();
		$this->assertFalse( Requirement::cart_is_pickup(), 'nothing chosen is not pickup' );

		$order = \WC_Order::make();
		$order->add_test_item( new \WC_Order_Item_Shipping( 'pickup_location' ) );
		$this->assertTrue( Requirement::is_pickup( $order ) );
		$order->add_test_item( new \WC_Order_Item_Shipping( 'free_shipping' ) );
		$this->assertFalse( Requirement::is_pickup( $order ) );
		$order->clear_test_items( 'shipping' );
		$this->assertFalse( Requirement::is_pickup( $order ) );
	}

	public function test_one_pickup_list_filtered_for_carts_and_orders(): void {
		$seen = array();
		Filters\expectApplied( 'navexa_agecheck_pickup_methods' )->twice()->andReturnUsing(
			static function ( $methods, $order ) use ( &$seen ) {
				$seen[] = null === $order ? 'cart' : 'order';
				return array_merge( $methods, array( 'curbside' ) );
			}
		);
		$this->chosen_methods = array( 'curbside' );
		$this->assertTrue( Requirement::cart_is_pickup(), 'a method the merchant lists counts for the cart' );

		$order = \WC_Order::make();
		$order->add_test_item( new \WC_Order_Item_Shipping( 'curbside' ) );
		$this->assertTrue( Requirement::is_pickup( $order ), 'and for the order (the hold path reads the same list)' );
		$this->assertSame( array( 'cart', 'order' ), $seen );
	}

	public function test_pickup_list_keeps_the_defaults_and_drops_junk(): void {
		Filters\expectApplied( 'woocommerce_local_pickup_methods' )->twice()->andReturn( array( 'my_pickup', 7 ) );
		$ids = Requirement::pickup_method_ids();
		$this->assertSame( array( 'legacy_local_pickup', 'local_pickup', 'pickup_location', 'my_pickup' ), $ids );

		Filters\expectApplied( 'navexa_agecheck_pickup_methods' )->once()->andReturn( 'junk' );
		$this->assertSame( Requirement::PICKUP_METHODS, Requirement::pickup_method_ids() );
	}

	public function test_messages_are_plain_text(): void {
		foreach ( array( Gate::REFUSE_VERIFY_FIRST, Gate::REFUSE_SHIP_TO_CHANGED, Gate::REFUSE_US_ONLY, Gate::REFUSE_NO_ADDRESS, Gate::REFUSE_UNAVAILABLE, Gate::REFUSE_FAILED, Gate::REFUSE_PLACE_AGAIN ) as $code ) {
			$message = Gate::message( $code );
			$this->assertNotSame( '', $message );
			$this->assertSame( strip_tags( $message ), $message );
		}
		$this->assertStringContainsString( 'US territories', Gate::message( Gate::REFUSE_US_ONLY ) );
	}
}
