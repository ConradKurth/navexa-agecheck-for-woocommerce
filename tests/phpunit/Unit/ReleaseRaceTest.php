<?php
/**
 * 1.1.1 release fixes, from the pilot store's order #30 (2026-11-26): two
 * release runs at the failed webhook left a used clearance key on the order,
 * the passed webhook's run then read AgeCheck's replayed DENIED as a purged
 * pass, verified again and released an order that was denied and listed for
 * a refund.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\DocRetry;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Lock;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderVerify;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;

final class ReleaseRaceTest extends TestCase {

	const VID = 'acv_RACETEST';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
		$this->gateway          = new \Navexa\AgeCheck\Tests\FakeGateway();
		$this->gateway->refunds = false;
	}

	public function test_a_run_started_while_another_holds_the_order_asks_nothing(): void {
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed() );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$inner             = array();
		$this->before_http = function ( $url ) use ( $order, &$inner ) {
			if ( false !== strpos( $url, '/GetClearance' ) && array() === $inner ) {
				// The webhook's run starts while the hold's run waits on AgeCheck.
				$inner[] = Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK );
			}
		};
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( array( Release::LOCKED ), $inner, 'the second run waits for the lock' );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetClearance', 'GetVerification', 'CreateHostedSession' ), $this->rpcs() );

		// run_job() puts a locked run back in the queue.
		$this->before_http = null;
		$token             = Lock::acquire( Release::lock_name( $order->get_id() ), Release::LOCK_TTL );
		Release::run_job( $order->get_id(), 0, Release::TRIGGER_WEBHOOK );
		$jobs = $this->scheduled_for( Release::HOOK );
		$this->assertSame( array( $order->get_id(), 1, Release::TRIGGER_RETRY ), end( $jobs )['args'] );
		Lock::release( Release::lock_name( $order->get_id() ), $token );
	}

	public function test_a_second_denial_saves_the_key_it_rotated(): void {
		// The hold's run denies; the webhook's run, right after it, gets the
		// same DENIED and finds the order denied already (Denial's early
		// return). The key it rotated must be the one stored.
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed() );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->reload( $order );
		$this->assertSame( Meta::REFUND_MANUAL, $order->meta[ Meta::REFUND ] );

		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed() );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->reload( $order );
		$used = $this->request_body( 4 )['idempotencyKey'];
		$this->assertSame( 'GetClearance', $this->rpcs()[4] );
		$this->assertNotSame( $used, $order->meta[ Meta::CLEARANCE_KEY ], 'a key AgeCheck answered DENIED under is never left stored' );
		$this->assertNotSame( '', (string) $order->meta[ Meta::CLEARANCE_KEY ] );
	}

	public function test_a_stale_denial_after_a_pass_asks_again_on_a_saved_key(): void {
		// Not denied yet: the stored key's DENIED predates the pass. The run
		// asks again on a fresh key (saved before the call) and releases.
		$order                              = $this->opted_in_order();
		$order->meta[ Meta::CLEARANCE_KEY ] = 'clr:stale';
		$order->save_meta_data();
		$saved_key         = array();
		$this->before_http = function ( $url, $args ) use ( $order, &$saved_key ) {
			if ( false !== strpos( $url, '/GetClearance' ) ) {
				$body        = json_decode( $args['body'], true );
				$saved_key[] = $body['idempotencyKey'] === $order->saved_meta[ Meta::CLEARANCE_KEY ];
			}
		};
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetClearance', 'GetVerification', 'GetClearance' ), $this->rpcs() );
		$this->assertSame( 'clr:stale', $this->request_body( 1 )['idempotencyKey'] );
		$this->assertNotSame( 'clr:stale', $this->request_body( 3 )['idempotencyKey'] );
		$this->assertSame( array( true, true ), $saved_key, 'every key is saved before GetClearance' );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ], 'never verified again' );
	}

	public function test_order_30_a_denied_order_is_left_to_staff_after_a_stale_denial(): void {
		// The pilot: denied and listed for a refund, stale key stored, the
		// shopper passes a retry. 1.1.0 verified again (wiping the retry's
		// opt-in) and released. Now: asked again, CLEARED, held for staff.
		$order = $this->denied_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->reload( $order );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertFalse( Meta::is_released( $order ) );
		$this->assertSame( 'passed_after_denial', $order->meta[ Meta::REASON ] );
		$this->assertSame( Meta::STATUS_DENIED, $order->meta[ Meta::STATUS ] );
		$this->assertSame( Meta::REFUND_MANUAL, $order->meta[ Meta::REFUND ] );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertTrue( DocRetry::opted_in( $order ), 'the opt-in stays' );
		$this->assertStringNotContainsString( 'cannot be used for this order', implode( "\n", $order->notes ) );
	}

	public function test_a_denied_order_whose_pass_was_purged_is_left_to_staff(): void {
		// DENIED not_verified on the fresh key too: purged. A denied order
		// keeps its verification and opt-in; staff decide.
		$order = $this->denied_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		for ( $round = 0; $round < 2; $round++ ) {
			$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
			$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		}
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->reload( $order );
		$this->assertSame( 'passed_after_denial', $order->meta[ Meta::REASON ] );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( 'yes', $order->meta[ Meta::DOC_RETRY ] );
		$this->assertSame( 'create', $order->meta[ Meta::BOUND_BY ] );
		$retries = array_filter(
			$this->scheduled_for( Release::HOOK ),
			static function ( $job ) {
				return Release::TRIGGER_RETRY === $job['args'][2];
			}
		);
		$this->assertSame( array(), $retries, 'no new age check queued' );
	}

	public function test_a_denied_order_is_never_released_without_the_retry_opt_in(): void {
		// The opt-in gone (as 1.1.0's reverify left it, or a verification
		// adopted): still never released on its own.
		$order                         = $this->denied_order();
		$order->meta[ Meta::BOUND_BY ] = 'adopt';
		$this->assertFalse( DocRetry::opted_in( $order ) );
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( 'passed_after_denial', $order->meta[ Meta::REASON ] );

		// Refund pending without a denied status (state written by hand, or a
		// partial copy): the same.
		$listed                         = $this->opted_in_order();
		$listed->meta[ Meta::REFUND ]   = Meta::REFUND_MANUAL;
		$listed->meta[ Meta::BOUND_BY ] = 'attach';
		$this->respond( 200, $this->clearance( 'DENIED', 'replayed' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->assertSame( Release::REVIEW, Release::run( $listed->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Hold::STATUS, $listed->get_status() );

		// Money already back: even a dashboard override is left to staff.
		$refunded                       = $this->denied_order();
		$refunded->meta[ Meta::REFUND ] = Meta::REFUND_REFUNDED;
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->assertSame( Release::REVIEW, Release::run( $refunded->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Hold::STATUS, $refunded->get_status() );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetClearance', 'GetVerification', 'GetClearance', 'GetVerification', 'GetClearance' ), $this->rpcs(), 'no override read once refunded' );
	}

	public function test_reverify_keeps_a_denied_orders_opt_in(): void {
		$denied = $this->denied_order();
		Binding::reverify( $denied, 'verification_purged' );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $denied->meta );
		$this->assertSame( 'yes', $denied->meta[ Meta::DOC_RETRY ] );
		$this->assertSame( 'create', $denied->meta[ Meta::BOUND_BY ] );

		$held = $this->opted_in_order();
		Binding::reverify( $held, 'verification_purged' );
		$this->assertArrayNotHasKey( Meta::DOC_RETRY, $held->meta );
		$this->assertArrayNotHasKey( Meta::BOUND_BY, $held->meta );
	}

	public function test_a_test_key_failure_waits_for_the_retry_and_offers_try_again(): void {
		// No NAVEXA_AGECHECK_ALLOW_TEST_RELEASE: nothing asks GetClearance, but
		// the failed check is offered a retry with the same window.
		$order   = $this->opted_in_order();
		$decided = time() - 600;
		$this->respond( 200, $this->live_info( array( 'livemode' => false, 'docRetry' => true ) ) );
		$this->respond( 200, $this->failed( $decided ) );
		$this->respond( 200, array( 'hostedUrl' => 'https://verify.navexa.co/v/tok' ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetVerification', 'CreateHostedSession' ), $this->rpcs() );
		$this->reload( $order );
		$this->assertSame( $decided + DocRetry::WINDOW, (int) $order->meta[ Meta::RETRY_UNTIL ] );
		$this->assertSame( 'yes', $order->meta[ Meta::RETRY_OPEN ] );
		$this->assertSame( DocRetry::REASON, $order->meta[ Meta::REASON ] );
		$this->assertTrue( DocRetry::open( $order ) );
		$this->assertTrue( OrderVerify::needs_shopper( $order ) );
		$notes = implode( "\n", $order->notes );
		$this->assertStringContainsString( 'may try again until', $notes );
		$this->assertStringContainsString( 'Test keys never deny or refund orders', $notes );
		$this->assertStringNotContainsString( 'a refund waits', $notes, 'a test key never refunds' );
		$this->assertStringNotContainsString( 'cancel it yourself', $notes );

		// AgeCheck closes it (its window, the ID checks spent): as before, and
		// the merchant is told to cancel it, since nothing will.
		$this->respond( 200, $this->failed( $decided ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertFalse( DocRetry::open( $order ) );
		$this->assertSame( 'test_mode', $order->meta[ Meta::REASON ] );
		$this->assertSame( Meta::STATUS_PENDING, $order->meta[ Meta::STATUS ], 'a test key never denies' );
		$this->assertSame( 1, substr_count( implode( "\n", $order->notes ), 'cancel it yourself' ) );

		// A later run with the check still failed and the retry still closed
		// notes nothing again.
		$this->respond( 200, $this->failed( $decided ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 1, substr_count( implode( "\n", $order->notes ), 'cancel it yourself' ) );

		// The window is the live one: past RETRY_UNTIL nothing is asked.
		$late                            = $this->opted_in_order();
		$late->meta[ Meta::RETRY_UNTIL ] = time() - 1;
		$this->assertFalse( DocRetry::defer( $late, $this->failed( $decided )['verification'], $this->fulfilment_client() ) );
		$this->assertSame( 'CreateHostedSession', $this->rpcs()[6], 'no call for the late order' );
		$this->assertCount( 7, $this->http_requests );
	}

	public function test_a_test_key_retry_that_runs_out_tells_the_merchant_to_cancel(): void {
		$order   = $this->opted_in_order();
		$decided = time() - 600;
		$this->respond( 200, $this->live_info( array( 'livemode' => false, 'docRetry' => true ) ) );
		$this->respond( 200, $this->failed( $decided ) );
		$this->respond( 200, array( 'hostedUrl' => 'https://verify.navexa.co/v/tok' ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertTrue( DocRetry::open( $order ) );

		// RETRY_UNTIL passes with the check still failed: no call, one note.
		$order->meta[ Meta::RETRY_UNTIL ]   = time() - 1;
		$order->meta[ Meta::NEXT_CHECK_AT ] = '';
		$order->save_meta_data();
		$this->respond( 200, $this->failed( $decided ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_SWEEP ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetVerification', 'CreateHostedSession', 'GetVerification' ), array_slice( $this->rpcs(), 0, 4 ) );
		$this->assertSame( 'test_mode', $order->meta[ Meta::REASON ] );
		$this->assertSame( '', Meta::get( $order, Meta::RETRY_OPEN ) );
		$this->assertSame( 1, substr_count( implode( "\n", $order->notes ), 'cancel it yourself' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_test_release_allowed_a_test_failure_waits_like_a_live_one(): void {
		define( 'NAVEXA_AGECHECK_ALLOW_TEST_RELEASE', true );
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info( array( 'livemode' => false, 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified', false ) );
		$this->respond( 200, $this->failed( time() - 60 ) );
		$this->respond( 200, array( 'hostedUrl' => 'https://verify.navexa.co/v/tok' ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Meta::STATUS_PENDING, $order->meta[ Meta::STATUS ], 'not denied while the retry is open' );
		$this->assertTrue( DocRetry::open( $order ) );
	}

	/** Emulates the next request's read: only saved meta survives. */
	private function reload( \WC_Order $order ): void {
		$order->meta = $order->saved_meta;
	}

	/** A held order bound to a verification this plugin created with the opt-in. */
	private function opted_in_order(): \WC_Order {
		$order                                   = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ]           = 'yes';
		$order->meta[ Meta::HELD ]               = 'yes';
		$order->meta[ Meta::INTERCEPTED_STATUS ] = 'processing';
		$order->meta[ Meta::STATUS ]             = Meta::STATUS_PENDING;
		$order->meta[ Meta::VERIFICATION_ID ]    = self::VID;
		$order->meta[ Meta::BOUND_AT ]           = time();
		$order->meta[ Meta::BOUND_BY ]           = 'create';
		$order->meta[ Meta::DOC_RETRY ]          = 'yes';
		Binding::external_id( $order );
		$order->save_meta_data();
		return $order;
	}

	/** The pilot's order #30 after the failed webhook: denied, listed, a stale key. */
	private function denied_order(): \WC_Order {
		$order                              = $this->opted_in_order();
		$order->meta[ Meta::STATUS ]        = Meta::STATUS_DENIED;
		$order->meta[ Meta::REFUND ]        = Meta::REFUND_MANUAL;
		$order->meta[ Meta::REASON ]        = 'not_verified';
		$order->meta[ Meta::CLEARANCE_KEY ] = 'clr:stale';
		$order->save_meta_data();
		return $order;
	}

	private function failed( int $decided_at = 0 ): array {
		return array(
			'verification' => array(
				'id'        => self::VID,
				'status'    => 'VERIFICATION_STATUS_FAILED',
				'decidedAt' => gmdate( 'Y-m-d\TH:i:s\Z', $decided_at > 0 ? $decided_at : time() - 60 ),
			),
		);
	}

	private function passed( string $class ): array {
		return array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_PASSED', 'passClass' => $class ) );
	}

	private function clearance( string $decision, string $reason, bool $livemode = true ): array {
		return array(
			'decision'       => 'CLEARANCE_DECISION_' . $decision,
			'verificationId' => self::VID,
			'reason'         => $reason,
			'livemode'       => $livemode,
		);
	}
}
