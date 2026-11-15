<?php
/**
 * Sweep tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Sweep;
use Navexa\AgeCheck\Tests\TestCase;
use Navexa\AgeCheck\Webhook;

final class SweepTest extends TestCase {

	public function test_register(): void {
		Sweep::register();
		$this->assertNotFalse( has_action( 'action_scheduler_init', array( Sweep::class, 'schedule' ) ) );
		$this->assertNotFalse( has_action( 'init', array( Sweep::class, 'schedule_fallback' ) ) );
		$this->assertNotFalse( has_action( Sweep::HOOK, array( Sweep::class, 'run_job' ) ) );
		$this->assertNotFalse( has_action( Sweep::CRON_HOOK, array( Sweep::class, 'run_job' ) ) );
		$this->assertSame( 900, Sweep::cron_schedules( null )['navexa_agecheck_15min']['interval'] );
	}

	public function test_schedules_one_recurring_action_and_drops_the_cron_fallback(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_schedule_recurring_action' )->once()->with( \Mockery::type( 'int' ), 900, Sweep::HOOK, array(), Release::GROUP, true );
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Functions\expect( 'wp_unschedule_event' )->once()->with( 123, Sweep::CRON_HOOK );
		Sweep::schedule();
	}

	public function test_does_not_reschedule(): void {
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\expect( 'as_schedule_recurring_action' )->never();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_unschedule_event' )->never();
		Sweep::schedule();
	}

	public function test_cron_fallback_is_scheduled_once(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 99 );
		Functions\expect( 'wp_schedule_event' )->never();
		Sweep::schedule_fallback();
	}

	public function test_unschedule(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( Sweep::HOOK, array(), Release::GROUP );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Sweep::CRON_HOOK );
		Sweep::unschedule();
	}

	public function test_queues_held_undenied_orders_and_unbound_checkouts(): void {
		$held                      = \WC_Order::make( Hold::STATUS );
		$fresh                     = \WC_Order::make( Hold::STATUS );
		$fresh->meta[ Meta::STATUS ] = Meta::STATUS_PENDING;
		$denied                    = \WC_Order::make( Hold::STATUS );
		$denied->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$unbound                   = \WC_Order::make( 'pending' );
		$unbound->meta[ Meta::ATTACH_PENDING ] = 'yes';
		\WC_Order::make( 'processing' );

		$this->options[ Webhook::SEEN_PREFIX . 'old' ] = (string) ( time() - 8 * 86400 );
		$this->options[ Webhook::SEEN_PREFIX . 'new' ] = (string) time();

		$this->assertSame( 3, Sweep::run() );
		$queued = array_map(
			static function ( $call ) {
				return $call['args'][0];
			},
			$this->scheduled_for( Release::HOOK )
		);
		$this->assertSame( array( $held->get_id(), $fresh->get_id(), $unbound->get_id() ), $queued );
		$this->assertSame( Release::TRIGGER_SWEEP, $this->scheduled[0]['args'][2] );
		$this->assertArrayNotHasKey( Webhook::SEEN_PREFIX . 'old', $this->options );
		$this->assertArrayHasKey( Webhook::SEEN_PREFIX . 'new', $this->options );
		$this->assertTrue( Sweep::healthy() );
		$this->assertSame( 1, $this->options[ Sweep::OPTION_PAGE ] );
	}

	public function test_pages_through_large_backlogs(): void {
		for ( $i = 0; $i < Sweep::BATCH + 5; $i++ ) {
			\WC_Order::make( Hold::STATUS );
		}
		$this->assertSame( Sweep::BATCH, Sweep::run() );
		$this->assertSame( 2, $this->options[ Sweep::OPTION_PAGE ] );
		$this->assertSame( 5, Sweep::run() );
		$this->assertSame( 1, $this->options[ Sweep::OPTION_PAGE ], 'wraps around' );
	}

	public function test_fallback_cron_when_action_scheduler_is_missing(): void {
		// The ActionScheduler class is not loaded in the unit suite.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'navexa_agecheck_15min', Sweep::CRON_HOOK );
		Sweep::schedule_fallback();
		$this->assertFalse( Sweep::healthy() );
		Sweep::run_job();
		$this->assertTrue( Sweep::healthy() );
	}
}
