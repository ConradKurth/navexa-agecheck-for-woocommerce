<?php
/**
 * AddressGuard and order-pay tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\AddressGuard;
use Navexa\AgeCheck\Checkout\OrderPay;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Tests\TestCase;

final class AddressGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
	}

	public function test_register(): void {
		AddressGuard::register();
		$this->assertSame( 20, has_action( 'woocommerce_before_order_object_save', array( AddressGuard::class, 'guard' ) ) );
		OrderPay::register();
		$this->assertNotFalse( has_action( 'woocommerce_before_pay_action', array( OrderPay::class, 'before_pay' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_validate_order_before_payment', array( OrderPay::class, 'validate_existing_order' ) ) );
	}

	public function test_a_bound_orders_ship_to_change_is_reverted(): void {
		$this->is_admin     = true;
		$this->current_user = 2;
		$order              = $this->bound_order();
		$order->set_shipping_address_1( '99 Elsewhere Rd' );
		$order->set_shipping_postcode( '10001' );
		$order->set_shipping_first_name( 'Augusta' );
		$order->save();
		$this->assertSame( '1 Main St', $order->get_shipping_address_1() );
		$this->assertSame( '62701-1234', $order->get_shipping_postcode() );
		$this->assertSame( 'Augusta', $order->get_shipping_first_name(), 'names stay editable' );
		$this->assertStringContainsString( 'tied to its age check', end( $order->notes ) );
		$this->assertArrayHasKey( 'navexa_agecheck_flash_2', $this->transients );
		$this->assertStringContainsString( 'ship-to change reverted order=' . $order->get_id(), $this->all_logs() );
	}

	public function test_case_and_spacing_edits_are_not_changes(): void {
		$order = $this->bound_order();
		$order->set_shipping_address_1( '1  MAIN st' );
		$order->save();
		$this->assertSame( '1  MAIN st', $order->get_shipping_address_1() );
	}

	public function test_unbound_new_and_mismatch_orders_may_change(): void {
		$free = $this->restricted_order();
		$free->set_shipping_city( 'Chicago' );
		$free->save();
		$this->assertSame( 'Chicago', $free->get_shipping_city() );

		$pending                               = $this->restricted_order();
		$pending->meta[ Meta::ATTACH_PENDING ] = 'yes';
		$this->assertTrue( AddressGuard::locked( $pending ), 'a checkout verification waiting to bind locks too' );

		$mismatch                       = $this->bound_order();
		$mismatch->meta[ Meta::REASON ] = 'ship_to_mismatch';
		$mismatch->set_shipping_address_1( '1 Main Street' );
		$mismatch->save();
		$this->assertSame( '1 Main Street', $mismatch->get_shipping_address_1(), 'restoring the verified address is the fix' );

		$new = new \WC_Order();
		$new->meta[ Meta::VERIFICATION_ID ] = 'acv_X';
		$new->set_shipping_city( 'Chicago' );
		AddressGuard::guard( $new );
		$this->assertSame( 'Chicago', $new->get_shipping_city() );
		AddressGuard::guard( 'not an order' );
	}

	public function test_store_api_order_pay_refuses_a_ship_to_change_on_a_bound_order(): void {
		$errors = $this->errors();

		// WooCommerce (CheckoutOrder::update_billing_address()) writes the
		// request's addresses and saves the order, then validates it.
		$order = $this->bound_order();
		$order->set_shipping_address_1( '99 Elsewhere Rd' );
		$order->save();
		$this->assertSame( '1 Main St', $order->get_shipping_address_1(), 'the guard undid it during the save' );
		$this->assertSame( array(), $order->get_changes() );
		OrderPay::validate_existing_order( $order, $errors );
		$this->assertArrayHasKey( 'navexa_agecheck_ship_to_locked', $errors->added, 'refused, not silently shipped to the old address' );

		// A change still unsaved at validation is refused too.
		$unsaved = $this->bound_order();
		$unsaved->set_shipping_address_1( '99 Elsewhere Rd' );
		$errors2 = $this->errors();
		OrderPay::validate_existing_order( $unsaved, $errors2 );
		$this->assertArrayHasKey( 'navexa_agecheck_ship_to_locked', $errors2->added );

		$same = $this->bound_order();
		$same->set_billing_phone( '555' );
		$same->save();
		$errors3 = $this->errors();
		OrderPay::validate_existing_order( $same, $errors3 );
		$this->assertSame( array(), $errors3->added );

		$draft = $this->bound_order();
		$draft->data['status'] = 'checkout-draft';
		$draft->set_shipping_address_1( '99 Elsewhere Rd' );
		OrderPay::validate_existing_order( $draft, $errors3 );
		$this->assertSame( array(), $errors3->added, 'new checkouts are W2\'s' );
		OrderPay::validate_existing_order( 'x', $errors3 );
	}

	public function test_ship_to_change_requested(): void {
		$order = $this->bound_order();
		$this->assertFalse( AddressGuard::ship_to_change_requested( $order ) );
		$order->set_shipping_postcode( '10001' );
		$this->assertTrue( AddressGuard::ship_to_change_requested( $order ) );
		$order->save();
		$this->assertTrue( AddressGuard::ship_to_change_requested( $order ), 'remembered after the guard reverted it' );
		$this->assertFalse( AddressGuard::ship_to_change_requested( $this->bound_order() ) );
	}

	public function test_classic_order_pay_records_the_requirement_and_never_blocks(): void {
		$order = $this->restricted_order();
		OrderPay::before_pay( $order );
		$this->assertSame( 'yes', $order->meta[ Meta::REQUIRED ] );
		$this->assertSame( 1, $order->meta_saves );
		OrderPay::before_pay( null );
	}

	/** A WP_Error that records add() calls. */
	private function errors(): \WP_Error {
		return new class() extends \WP_Error {
			public $added = array();
			public function add( $code, $message ) {
				$this->added[ $code ] = $message;
			}
		};
	}

	private function bound_order(): \WC_Order {
		$order                                = $this->restricted_order( 'agecheck-hold' );
		$order->meta[ Meta::VERIFICATION_ID ] = 'acv_BOUND';
		$order->meta[ Meta::REQUIRED ]        = 'yes';
		$order->meta[ Meta::HELD ]            = 'yes';
		return $order;
	}
}
