<?php
/**
 * Checkout Session tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Checkout\Session;
use Navexa\AgeCheck\GuardToken;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Tests\TestCase;

final class SessionTest extends TestCase {

	const MAC = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		parent::setUp();
		Install::ensure();
	}

	public function test_sid_is_created_once_and_only_on_request(): void {
		$this->assertSame( '', Session::sid() );
		$this->assertArrayNotHasKey( Session::KEY_SID, $this->session()->data, 'reading creates nothing' );
		$sid = Session::sid( true );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $sid );
		$this->assertSame( $sid, Session::sid( true ) );
		$this->session()->data[ Session::KEY_SID ] = 'not-a-sid';
		$this->assertSame( '', Session::sid() );
	}

	public function test_counter_only_grows(): void {
		$this->assertSame( 0, Session::next_counter() );
		$this->assertSame( 1, Session::next_counter() );
		Session::forget_verification();
		$this->assertSame( 2, Session::next_counter(), 'forgetting a verification keeps the counter' );
	}

	public function test_record_round_trip_and_validation(): void {
		$this->assertNull( Session::record() );
		Session::set_record( 'acv_1', self::MAC, 3, Session::STATUS_PENDING );
		$this->assertSame(
			array(
				'vid'        => 'acv_1',
				'shipto_mac' => self::MAC,
				'n'          => 3,
				'status'     => 'pending',
			),
			Session::record()
		);
		$this->session()->data[ Session::KEY_RECORD ] = array(
			'vid'        => 'x_1',
			'shipto_mac' => self::MAC,
			'status'     => 'pending',
		);
		$this->assertNull( Session::record(), 'not a verification id' );
		$this->session()->data[ Session::KEY_RECORD ] = 'junk';
		$this->assertNull( Session::record() );
	}

	public function test_a_new_verification_drops_the_old_guard(): void {
		$sid = Session::sid( true );
		Session::set_record( 'acv_1', self::MAC, 0, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( $sid, 'acv_1', self::MAC ), 'acv_1' );
		$this->assertSame( 'acv_1', Session::guarded_verification_id( self::MAC ) );

		Session::set_record( 'acv_1', self::MAC, 0, Session::STATUS_PASSED );
		$this->assertSame( 'acv_1', Session::guarded_verification_id( self::MAC ), 'same verification keeps it' );

		$this->assertSame( 'acv_1', $this->session()->get( Session::KEY_VID ), 'the bare id for order binding' );

		Session::set_record( 'acv_2', self::MAC, 1, Session::STATUS_PENDING );
		$this->assertNull( Session::guarded_verification_id( self::MAC ) );
		$this->assertNull( $this->session()->get( Session::KEY_VID ) );
	}

	public function test_forget_drops_record_and_guard_but_keeps_the_sid(): void {
		$sid = Session::sid( true );
		Session::set_record( 'acv_1', self::MAC, 0, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( $sid, 'acv_1', self::MAC ), 'acv_1' );
		Session::forget_verification();
		$this->assertNull( Session::record() );
		$this->assertNull( Session::guarded_verification_id( self::MAC ) );
		$this->assertArrayNotHasKey( Session::KEY_VID, $this->session()->data );
		$this->assertSame( $sid, Session::sid() );
		Session::set_guard( 'token', 'acv_9' );
		Session::set_guard( '' );
		$this->assertArrayNotHasKey( Session::KEY_GUARD, $this->session()->data );
		$this->assertArrayNotHasKey( Session::KEY_VID, $this->session()->data );
	}

	public function test_forget_of_a_verification_keeps_another_ones_guard(): void {
		$sid = Session::sid( true );
		Session::set_record( 'acv_2', self::MAC, 1, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( $sid, 'acv_2', self::MAC ), 'acv_2' );
		Session::forget_verification_of( 'acv_1' );
		Session::forget_verification_of( '' );
		$this->assertSame( 'acv_2', Session::guarded_verification_id( self::MAC ), 'a newer check survives' );

		Session::forget_verification_of( 'acv_2' );
		$this->assertNull( Session::record() );
		$this->assertNull( Session::guarded_verification_id( self::MAC ) );

		$this->session()->set( Session::KEY_VID, 'acv_3' );
		Session::forget_verification_of( 'acv_3' );
		$this->assertArrayNotHasKey( Session::KEY_VID, $this->session()->data, 'the bare id alone also matches' );

		self::$wc->session = null;
		Session::forget_verification_of( 'acv_3' );
		$this->assertNull( Session::record() );
	}

	public function test_rate_subject_is_the_woocommerce_customer_id(): void {
		$this->assertSame( 'customer:t_0123456789abcdef', Session::rate_subject() );
		$this->session()->customer_id = '';
		$this->assertSame( '', Session::rate_subject(), 'no customer id and no sid' );
		$sid = Session::sid( true );
		$this->assertSame( 'sid:' . $sid, Session::rate_subject() );
	}

	public function test_without_a_woocommerce_session_nothing_is_stored(): void {
		self::$wc->session = null;
		$this->assertSame( '', Session::sid( true ) );
		$this->assertSame( 0, Session::next_counter() );
		Session::set_record( 'acv_1', self::MAC, 0, Session::STATUS_PENDING );
		Session::set_guard( 'x' );
		Session::forget_verification();
		$this->assertNull( Session::record() );
		$this->assertNull( Session::guarded_verification_id( self::MAC ) );
		$this->assertSame( '', Session::rate_subject() );
		self::$wc = null;
		$this->assertNull( Session::cart() );
		$this->assertNull( Session::customer() );
		$this->assertNull( Session::wc_session() );
	}
}
