<?php
/**
 * CustomerRef tests: the per-store reuse reference (E12a).
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\CustomerRef;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Tests\TestCase;

final class CustomerRefTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_a_stable_keyed_reference_per_customer(): void {
		$ref = CustomerRef::for_user( 7 );
		$this->assertMatchesRegularExpression( '/^wpc1_[0-9a-f]{64}$/', $ref );
		$this->assertSame( $ref, CustomerRef::for_user( 7 ), 'stable' );
		$this->assertNotSame( $ref, CustomerRef::for_user( 8 ) );
		$key = hash_hmac( 'sha256', 'navexa-agecheck/customer-ref/v1', Install::guard_secret(), true );
		$this->assertSame( 'wpc1_' . hash_hmac( 'sha256', 'blog:1|user:7', $key ), $ref, 'pinned derivation' );
		$this->assertNotSame( 'wpc1_' . hash( 'sha256', '7' ), $ref, 'never a plain hash of the id' );
	}

	public function test_guests_and_installs_without_a_secret_get_none(): void {
		$this->assertSame( '', CustomerRef::for_user( 0 ) );
		$this->assertSame( '', CustomerRef::for_user( -3 ) );
		$this->assertSame( '', CustomerRef::for_order( \WC_Order::make() ), 'a guest order' );
		$this->current_user = 0;
		$this->assertSame( '', CustomerRef::for_current_user() );
		$this->options[ Install::OPTION_GUARD_SECRET ] = 'not-hex';
		$this->assertSame( '', CustomerRef::for_user( 7 ) );
	}

	public function test_orders_and_the_current_user_and_the_site(): void {
		$order                      = \WC_Order::make();
		$order->data['customer_id'] = 7;
		$this->assertSame( CustomerRef::for_user( 7 ), CustomerRef::for_order( $order ) );
		$this->current_user = 7;
		$this->assertSame( CustomerRef::for_user( 7 ), CustomerRef::for_current_user() );

		$before = CustomerRef::for_user( 7 );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->assertNotSame( $before, CustomerRef::for_user( 7 ), 'another site of a network is another store' );
	}

	public function test_confirming_a_copied_site_changes_every_reference(): void {
		$before     = CustomerRef::for_user( 7 );
		$this->home = 'https://staging.example';
		$this->assertTrue( Install::confirm_site() );
		$this->assertNotSame( $before, CustomerRef::for_user( 7 ), 'a staging copy\'s user 7 is not prod\'s user 7 to AgeCheck' );
	}
}
