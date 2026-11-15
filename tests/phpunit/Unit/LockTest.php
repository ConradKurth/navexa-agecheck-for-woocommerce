<?php
/**
 * Lock tests (against FakeWpdb, which runs Lock's exact statements).
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Lock;
use Navexa\AgeCheck\Tests\TestCase;

final class LockTest extends TestCase {

	public function test_only_one_holder_at_a_time(): void {
		$first = Lock::acquire( 'navexa_agecheck_lock_1', 60 );
		$this->assertNotSame( '', $first );
		$this->assertSame( '', Lock::acquire( 'navexa_agecheck_lock_1', 60 ), 'a second caller is refused while the lock is live' );
		$this->assertNotSame( '', Lock::acquire( 'navexa_agecheck_lock_2', 60 ), 'locks are per name' );

		Lock::release( 'navexa_agecheck_lock_1', 'not-the-token' );
		$this->assertSame( '', Lock::acquire( 'navexa_agecheck_lock_1', 60 ), 'a wrong token releases nothing' );

		Lock::release( 'navexa_agecheck_lock_1', $first );
		$this->assertArrayNotHasKey( 'navexa_agecheck_lock_1', $this->options );
		$this->assertNotSame( '', Lock::acquire( 'navexa_agecheck_lock_1', 60 ) );
	}

	public function test_an_expired_lock_is_taken_over_by_compare_and_set(): void {
		$this->options['navexa_agecheck_lock_9'] = ( time() - 5 ) . ':deadbeef';
		$token                                   = Lock::acquire( 'navexa_agecheck_lock_9', 60 );
		$this->assertNotSame( '', $token );
		$this->assertSame( $token, $this->options['navexa_agecheck_lock_9'] );
		$this->assertStringContainsString( "AND option_value = '", end( $this->wpdb->queries ), 'takeover only replaces the expired value it read' );

		$this->options['navexa_agecheck_lock_8'] = 'garbage';
		$this->assertNotSame( '', Lock::acquire( 'navexa_agecheck_lock_8', 60 ), 'an unreadable value counts as expired' );
	}

	public function test_release_with_an_empty_token_does_nothing(): void {
		$this->options['navexa_agecheck_lock_3'] = ( time() + 60 ) . ':x';
		Lock::release( 'navexa_agecheck_lock_3', '' );
		$this->assertArrayHasKey( 'navexa_agecheck_lock_3', $this->options );
	}

	public function test_claim_is_first_caller_only_and_purge_forgets_old_claims(): void {
		$this->assertTrue( Lock::claim( 'navexa_agecheck_seen_a' ) );
		$this->assertFalse( Lock::claim( 'navexa_agecheck_seen_a' ) );
		Lock::unclaim( 'navexa_agecheck_seen_a' );
		$this->assertTrue( Lock::claim( 'navexa_agecheck_seen_a' ) );

		$this->options['navexa_agecheck_seen_old'] = (string) ( time() - 1000 );
		$this->options['navexa_agecheck_other']    = '1';
		$this->assertSame( 1, Lock::purge_claims( 'navexa_agecheck_seen_', time() - 10 ) );
		$this->assertArrayNotHasKey( 'navexa_agecheck_seen_old', $this->options );
		$this->assertArrayHasKey( 'navexa_agecheck_seen_a', $this->options );
		$this->assertArrayHasKey( 'navexa_agecheck_other', $this->options );
	}
}
