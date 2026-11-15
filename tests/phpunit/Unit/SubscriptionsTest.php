<?php
/**
 * Subscriptions stub tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Subscriptions;
use Navexa\AgeCheck\Tests\TestCase;

final class SubscriptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_renewals_needing_a_check_are_marked_for_review(): void {
		Subscriptions::register();
		$this->assertNotFalse( has_filter( 'wcs_renewal_order_created', array( Subscriptions::class, 'on_renewal_created' ) ) );

		$renewal = $this->restricted_order();
		$this->assertSame( $renewal, Subscriptions::on_renewal_created( $renewal, new \stdClass() ) );
		$this->assertSame( 'yes', $renewal->meta[ Meta::RENEWAL ] );
		$this->assertArrayHasKey( Meta::EXTERNAL_ID, $renewal->meta );
		$this->assertTrue( Subscriptions::needs_review( $renewal ) );

		Meta::mark_released( $renewal, Meta::STATUS_OVERRIDE );
		$this->assertFalse( Subscriptions::needs_review( $renewal ), 'released by staff' );

		$plain = \WC_Order::make();
		Subscriptions::on_renewal_created( $plain );
		$this->assertArrayNotHasKey( Meta::RENEWAL, $plain->meta );
		$this->assertFalse( Subscriptions::needs_review( $plain ) );
		$this->assertSame( 'x', Subscriptions::on_renewal_created( 'x' ) );
	}

	public function test_a_renewal_gets_its_own_external_id_not_the_parents(): void {
		$parent = $this->restricted_order();
		\Navexa\AgeCheck\Binding::external_id( $parent );
		$parent->meta[ Meta::REQUIRED ] = 'yes';

		$renewal       = $this->restricted_order();
		$renewal->meta = $parent->meta; // Subscriptions copies the parent's meta via the subscription.
		Subscriptions::on_renewal_created( $renewal, new \stdClass() );
		$this->assertNotSame( $parent->meta[ Meta::EXTERNAL_ID ], $renewal->meta[ Meta::EXTERNAL_ID ] );
		$this->assertStringEndsWith( ':1:' . $renewal->get_id(), $renewal->meta[ Meta::EXTERNAL_ID ] );
	}

	public function test_renewals_created_before_the_hook_are_recognized(): void {
		Functions\when( 'wcs_order_contains_renewal' )->justReturn( true );
		$this->assertTrue( Subscriptions::needs_review( $this->restricted_order() ) );
	}
}
