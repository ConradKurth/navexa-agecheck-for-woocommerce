<?php
/**
 * Release tests: one run per order under its lock, GetClearance with a
 * persisted key, and every answer.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;

final class ReleaseTest extends TestCase {

	const VID = 'acv_RELEASETEST';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
		$this->gateway          = new \Navexa\AgeCheck\Tests\FakeGateway();
		$this->gateway->refunds = false;
	}

	public function test_register(): void {
		Release::register();
		$this->assertNotFalse( has_action( Release::HOOK, array( Release::class, 'run_job' ) ) );
	}

	public function test_cleared_order_is_released(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );

		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );

		$this->assertSame( array( 'GetIntegrationInfo', 'GetClearance' ), $this->rpcs() );
		$body = $this->request_body( 1 );
		$this->assertMatchesRegularExpression( '/^clr:wc:[0-9a-f]{32}:1:' . $order->get_id() . ':[0-9a-f]{16}$/', $body['idempotencyKey'] );
		$this->assertSame( $order->meta[ Meta::EXTERNAL_ID ], $body['orderExternalId'] );
		$this->assertSame( '62701', $body['shipTo']['postalCode'] );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( Meta::STATUS_CLEARED, $order->meta[ Meta::STATUS ] );
		$this->assertArrayNotHasKey( Meta::CLEARANCE_KEY, $order->meta );
		$this->assertStringContainsString( 'AgeCheck: age check passed. Order released.', implode( "\n", $order->notes ) );
		$this->assertSame( array( $order->get_id() ), self::$wc->mailer->emails['WC_Email_Customer_Processing_Order']->triggered );
		$this->assertArrayNotHasKey( 'navexa_agecheck_lock_' . $order->get_id(), $this->options, 'the lock is released' );
		$this->assertStringContainsString( 'trigger=webhook outcome=released', $this->all_logs() );
	}

	public function test_the_clearance_key_is_stored_before_the_call_and_reused_after_a_lost_answer(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond_transport_error( 'cURL error 28: Operation timed out' );
		try {
			Release::run( $order->get_id(), Release::TRIGGER_HOLD );
			$this->fail( 'a timeout is rethrown' );
		} catch ( ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
		$key = $order->meta[ Meta::CLEARANCE_KEY ];
		$this->assertSame( $key, $this->request_body( 1 )['idempotencyKey'] );
		$this->assertGreaterThan( 0, $order->meta_saves );

		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		Release::run( $order->get_id(), Release::TRIGGER_RETRY );
		$this->assertSame( $key, $this->request_body( 2 )['idempotencyKey'], 'the retry replays the same key' );
	}

	public function test_a_lost_cleared_answer_comes_back_as_replayed_and_releases(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'DENIED', 'replayed' ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_SWEEP ) );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( Meta::STATUS_CLEARED, $order->meta[ Meta::STATUS ] );
		$this->assertStringContainsString( 'confirmed on a repeated clearance answer', implode( "\n", $order->notes ) );
	}

	public function test_the_clearance_key_is_persisted_before_get_clearance_is_called(): void {
		$order     = $this->held_bound_order();
		$persisted = null;
		$this->before_http = static function ( $url ) use ( $order, &$persisted ) {
			if ( '/GetClearance' === substr( $url, -13 ) ) {
				$persisted = isset( $order->saved_meta[ Meta::CLEARANCE_KEY ] ) ? $order->saved_meta[ Meta::CLEARANCE_KEY ] : null;
			}
		};
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		Release::run( $order->get_id(), Release::TRIGGER_HOLD );
		$this->assertNotNull( $persisted, 'a process that dies after a consumed CLEARED still has the key' );
		$this->assertSame( $this->request_body( 1 )['idempotencyKey'], $persisted );
	}

	public function test_a_run_never_asks_about_another_orders_external_id(): void {
		$source = $this->held_bound_order();
		$copy   = $this->restricted_order( Hold::STATUS );
		$copy->meta = $source->meta; // Copied straight into the hold: mark_held never ran.
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'verification' => array( 'id' => 'acv_OWNCHECK', 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		$this->respond( 200, array( 'linkId' => 'acl_OWN', 'emailQueued' => true ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'replayed' ) );
		$outcome = Release::run( $copy->get_id(), Release::TRIGGER_SWEEP );
		$this->assertSame( array( 'GetIntegrationInfo', 'CreateVerification', 'SendVerificationLink', 'GetClearance' ), $this->rpcs() );
		$own = 'wc:' . \Navexa\AgeCheck\Install::install_id() . ':1:' . $copy->get_id();
		$this->assertSame( $own, $this->request_body( 1 )['orderExternalId'] );
		$this->assertSame( 'acv_OWNCHECK', $this->request_body( 2 )['verificationId'], 'the link is for its own check' );
		$this->assertSame( $own, $this->request_body( 3 )['orderExternalId'], 'GetClearance asks about this order, not the source' );
		$this->assertSame( 'acv_OWNCHECK', $copy->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( Release::RELEASED, $outcome, 'replayed on its own id means its own clearance was spent' );
	}

	public function test_hold_pending_rotates_the_key(): void {
		$order                              = $this->held_bound_order();
		$order->meta[ Meta::CLEARANCE_KEY ] = 'clr:old';
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'clr:old', $this->request_body( 1 )['idempotencyKey'] );
		$this->assertNotSame( 'clr:old', $order->meta[ Meta::CLEARANCE_KEY ], 'the next poll asks afresh' );
		$this->assertSame( 'pending', $order->meta[ Meta::REASON ] );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertArrayNotHasKey( Meta::NEXT_CHECK_AT, $order->meta, 'polled every sweep' );
	}

	public function test_unattested_waits_for_staff(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'HOLD', 'unattested' ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'unattested', $order->meta[ Meta::REASON ] );
		$this->assertGreaterThan( time() + 21000, $order->meta[ Meta::NEXT_CHECK_AT ] );
		$this->assertStringContainsString( 'cannot clear orders', end( $order->notes ) );
	}

	public function test_denied_not_verified_after_a_failed_check_denies(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_FAILED' ) ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Meta::STATUS_DENIED, $order->meta[ Meta::STATUS ] );
		$this->assertSame( Meta::REFUND_MANUAL, $order->meta[ Meta::REFUND ], 'the gateway cannot refund' );
		$this->assertSame( Hold::STATUS, $order->get_status(), 'a denied order stays held' );
	}

	public function test_denied_not_verified_after_a_purged_pass_verifies_again(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_PASSED' ) ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $order->meta );
		$this->assertSame( 'verification_purged', $order->meta[ Meta::REASON ] );
		$jobs = $this->scheduled_for( Release::HOOK );
		$this->assertSame( array( $order->get_id(), 0, Release::TRIGGER_RETRY ), end( $jobs )['args'] );

		$still = $this->held_bound_order();
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_DOC_PENDING' ) ) );
		$this->assertSame( Release::PENDING, Release::run( $still->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 'pending', $still->meta[ Meta::REASON ] );
	}

	public function test_other_denials_wait_for_staff(): void {
		foreach ( array( 'ship_to_mismatch', 'no_verification', '' ) as $reason ) {
			$order                              = $this->held_bound_order();
			$order->meta[ Meta::CLEARANCE_KEY ] = 'clr:k';
			$this->respond( 200, $this->live_info() );
			$this->respond( 200, $this->clearance( 'DENIED', $reason ) );
			$this->transients = array();
			$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
			$this->assertSame( '' === $reason ? 'denied' : $reason, $order->meta[ Meta::REASON ] );
			$this->assertNotSame( 'clr:k', $order->meta[ Meta::CLEARANCE_KEY ] );
			$this->assertArrayNotHasKey( Meta::REFUND, $order->meta, 'no refund without a failed verification' );
		}
	}

	public function test_an_answer_from_the_other_mode_is_never_acted_on(): void {
		$order                              = $this->held_bound_order();
		$order->meta[ Meta::CLEARANCE_KEY ] = 'clr:k';
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'decision' => 'CLEARANCE_DECISION_CLEARED', 'reason' => 'verified' ) ); // livemode omitted: false.
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'livemode_mismatch', $order->meta[ Meta::REASON ] );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( 'clr:k', $order->meta[ Meta::CLEARANCE_KEY ], 'no rotation on an answer from the other mode' );
	}

	public function test_a_test_key_never_asks_for_a_clearance_without_the_constant(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetVerification' ), $this->rpcs() );
		$this->assertSame( 'test_mode', $order->meta[ Meta::REASON ] );
		$this->assertSame( 'needs_doc', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertGreaterThan( time(), $order->meta[ Meta::NEXT_CHECK_AT ] );
		$this->assertSame( Hold::STATUS, $order->get_status() );
	}

	public function test_a_test_key_records_a_pass_once_and_never_releases(): void {
		$order                                    = $this->held_bound_order();
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'awaiting_identity';
		$passed                                   = array(
			'verification' => array(
				'id'        => self::VID,
				'status'    => 'VERIFICATION_STATUS_PASSED',
				'passClass' => 'PASS_CLASS_DATA_VERIFIED',
			),
		);
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->respond( 200, $passed );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 'passed', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertSame( 'DATA_VERIFIED', $order->meta[ Meta::PASS_CLASS ] );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertNotSame( Meta::STATUS_CLEARED, $order->meta[ Meta::STATUS ] ?? '' );
		$this->assertCount( 1, preg_grep( '/age check passed\. Test keys never release/', $order->notes ) );

		$this->respond( 200, $passed );
		Release::run( $order->get_id(), Release::TRIGGER_SHOPPER );
		$this->assertCount( 1, preg_grep( '/age check passed\. Test keys never release/', $order->notes ), 'no second note for the same status' );
	}

	public function test_a_test_key_records_a_failure_and_never_denies(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_FAILED' ) ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 'failed', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertArrayNotHasKey( Meta::REFUND, $order->meta );
		$this->assertCount( 1, preg_grep( '/age check failed\. Test keys never deny/', $order->notes ) );
	}

	public function test_a_test_key_run_goes_on_when_the_verification_read_fails(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->respond( 503, array( 'code' => 'unavailable', 'message' => 'down' ) );
		$this->assertSame( Release::TEST_MODE, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'test_mode', $order->meta[ Meta::REASON ] );
		$this->assertSame( Hold::STATUS, $order->get_status() );
	}

	public function test_clearance_refusals(): void {
		$order                              = $this->held_bound_order();
		$order->meta[ Meta::CLEARANCE_KEY ] = 'clr:k';
		$this->respond( 200, $this->live_info() );
		$this->respond( 400, array( 'code' => 'invalid_argument', 'message' => 'same key, different request' ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'clearance_rejected', $order->meta[ Meta::REASON ] );
		$this->assertSame( 'clr:k', $order->meta[ Meta::CLEARANCE_KEY ] );

		$this->respond( 403, array( 'code' => 'permission_denied', 'message' => 'missing scope' ) );
		try {
			Release::run( $order->get_id(), Release::TRIGGER_HOLD );
			$this->fail( 'refusals are rethrown' );
		} catch ( ConnectError $e ) {
			$this->assertFalse( $e->is_retryable() );
		}
		$this->assertSame( 'release_error_permission_denied', $order->meta[ Meta::REASON ] );
		$this->assertStringContainsString( 'AgeCheck refused the request (permission_denied)', end( $order->notes ) );
		$this->assertArrayNotHasKey( 'navexa_agecheck_lock_' . $order->get_id(), $this->options, 'the lock is released on failure too' );

		$this->respond( 200, array( 'decision' => 'CLEARANCE_DECISION_SOMETHING_NEW', 'livemode' => true ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'unexpected_decision', $order->meta[ Meta::REASON ] );

		$this->respond( 200, array( 'decision' => 1, 'livemode' => true ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_HOLD ), 'an enum number is read too' );
	}

	public function test_an_unbound_order_gets_an_order_bound_verification_and_a_link(): void {
		$order = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::HELD ] = 'yes';
		Binding::external_id( $order );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC', 'livemode' => true ) ) );
		$this->respond( 200, array( 'linkId' => 'acl_RELEASE', 'expiresAt' => '2026-11-22T00:00:00Z', 'emailQueued' => true ) );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'CreateVerification', 'SendVerificationLink', 'GetClearance' ), $this->rpcs() );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertSame( 'acl_RELEASE', $order->meta[ Meta::LINK_ID ] );
		$this->assertStringContainsString( 'https://shop.example/?wc-api=navexa_agecheck_order&step=return', $this->request_body( 2 )['returnUrl'] );
		$this->assertArrayNotHasKey( Meta::NEXT_CHECK_AT, $order->meta, 'an emailed shopper is polled every sweep' );

		// The next run asks for no second link.
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		Release::run( $order->get_id(), Release::TRIGGER_SWEEP );
		$this->assertSame( 'GetClearance', $this->request_rpc( 4 ) );
		$this->assertCount( 5, $this->http_requests );
	}

	public function test_links_off_keeps_the_order_held_and_polls_it_every_sweep(): void {
		$order = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::HELD ] = 'yes';
		Binding::external_id( $order );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC', 'livemode' => true ) ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: verification links are not enabled' ) );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( 'links_off:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertArrayNotHasKey( Meta::NEXT_CHECK_AT, $order->meta, 'a shopper who was not emailed can still pass from Verify now: polled every sweep' );
		$this->assertSame( \Navexa\AgeCheck\Links::STATE_LINKS_OFF, \Navexa\AgeCheck\Links::store_block()['reason'] );
		foreach ( array( 'not_eligible:', 'no_display_name:', 'no_email:', 'refused:', 'withdrawn:', 'disabled:' ) as $state ) {
			$order->meta[ Meta::LINK ]          = $state . self::VID;
			$order->meta[ Meta::NEXT_CHECK_AT ] = time() + 21600;
			$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
			$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_ADMIN ), $state );
			$this->assertArrayNotHasKey( Meta::NEXT_CHECK_AT, $order->meta, $state );
		}
	}

	public function test_a_link_failure_never_stops_the_clearance(): void {
		$order                                    = $this->held_bound_order();
		$order->meta[ Meta::BOUND_BY ]            = 'create';
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$this->respond( 200, $this->live_info() );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );

		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ), 'the shopper passed from the order page' );
		$this->assertSame( array( 'GetIntegrationInfo', 'SendVerificationLink', 'GetClearance' ), $this->rpcs() );
		$this->assertSame( 'processing', $order->get_status() );
	}

	public function test_a_link_failure_still_counts_while_the_order_waits(): void {
		$order                                    = $this->held_bound_order();
		$order->meta[ Meta::BOUND_BY ]            = 'create';
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$this->respond( 200, $this->live_info() );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		try {
			Release::run( $order->get_id(), Release::TRIGGER_HOLD );
			$this->fail( 'a retryable link failure is rethrown after the clearance' );
		} catch ( ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
			$this->assertSame( 'SendVerificationLink', $e->rpc() );
		}
		$this->assertSame( array( 'GetIntegrationInfo', 'SendVerificationLink', 'GetClearance' ), $this->rpcs() );
		$key = $order->meta[ Meta::LINK_KEY ];
		$this->assertStringStartsWith( 'vl:' . self::VID . ':', $key, 'kept: the retry replays it' );
		$this->assertSame( 'pending', $order->meta[ Meta::REASON ], 'the HOLD answer is recorded' );

		$this->respond( 200, array( 'linkId' => 'acl_RETRY', 'emailQueued' => true ) );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_RETRY ) );
		$this->assertSame( $key, $this->request_body( 3 )['idempotencyKey'], 'the same key: one email at most' );
		$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ] );

		$other                                    = $this->held_bound_order();
		$other->meta[ Meta::BOUND_BY ]            = 'create';
		$other->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$this->respond( 403, array( 'code' => 'permission_denied', 'message' => 'agecheck: AgeCheck is not enabled for this organization' ) );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		try {
			Release::run( $other->get_id(), Release::TRIGGER_HOLD );
			$this->fail( 'a refusal is rethrown after the clearance' );
		} catch ( ConnectError $e ) {
			$this->assertFalse( $e->is_retryable() );
		}
		$this->assertSame( 'release_error_permission_denied', $other->meta[ Meta::REASON ], 'staff look, as for any refusal' );
	}

	public function test_no_link_is_asked_for_a_verification_not_waiting_at_agecheck_page(): void {
		foreach ( array( 'created', 'held', 'db_checking', 'passed' ) as $status ) {
			$order                                    = $this->held_bound_order();
			$order->meta[ Meta::BOUND_BY ]            = 'create';
			$order->meta[ Meta::VERIFICATION_STATUS ] = $status;
			$this->http_requests                      = array();
			$this->respond( 200, $this->live_info() );
			$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
			Release::run( $order->get_id(), Release::TRIGGER_HOLD );
			$this->assertNotContains( 'SendVerificationLink', $this->rpcs(), $status );
			$this->assertArrayNotHasKey( Meta::LINK, $order->meta, $status );
		}
	}

	public function test_a_pending_checkout_verification_is_attached_before_the_clearance(): void {
		$order                                = $this->held_bound_order();
		$order->meta[ Meta::ATTACH_PENDING ]  = 'yes';
		unset( $order->meta[ Meta::BOUND_AT ] );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_PASSED' ) ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'AttachOrder', 'GetClearance' ), $this->rpcs() );
	}

	public function test_binding_outcomes_short_of_a_clearance(): void {
		$abroad = $this->held_bound_order();
		$abroad->meta[ Meta::ATTACH_PENDING ] = 'yes';
		$abroad->data['shipping']['country'] = 'DE'; // AddressGuard would revert an edit.
		$this->respond( 200, $this->live_info() );
		$this->assertSame( Release::DENIED, Release::run( $abroad->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'not_us', $abroad->meta[ Meta::REASON ] );

		$nameless = $this->restricted_order( Hold::STATUS );
		$nameless->meta[ Meta::HELD ] = 'yes';
		$nameless->set_billing_first_name( '' );
		$nameless->set_billing_last_name( '' );
		$this->assertSame( Release::REVIEW, Release::run( $nameless->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'no_billing_name', $nameless->meta[ Meta::REASON ] );

		$mexico = $this->restricted_order( Hold::STATUS );
		$mexico->meta[ Meta::HELD ] = 'yes';
		$mexico->set_shipping_country( 'MX' );
		$this->assertSame( Release::DENIED, Release::run( $mexico->get_id(), Release::TRIGGER_HOLD ) );

		$lost = $this->held_bound_order();
		$lost->meta[ Meta::ATTACH_PENDING ] = 'yes';
		$this->respond( 404, array( 'code' => 'not_found' ) );
		$this->assertSame( Release::REVIEW, Release::run( $lost->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'verification_not_found', $lost->meta[ Meta::REASON ] );
	}

	public function test_orders_not_held_only_get_their_checkout_verification_bound(): void {
		$pending                                = $this->restricted_order( 'pending' );
		$pending->meta[ Meta::VERIFICATION_ID ] = self::VID;
		$pending->meta[ Meta::ATTACH_PENDING ]  = 'yes';
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID ) ) );
		$this->assertSame( Release::PENDING, Release::run( $pending->get_id(), Release::TRIGGER_SWEEP ) );
		$this->assertSame( array( 'AttachOrder' ), $this->rpcs(), 'no clearance is spent on an unpaid order' );

		$abroad                                = $this->restricted_order( 'on-hold' );
		$abroad->meta[ Meta::VERIFICATION_ID ] = self::VID;
		$abroad->meta[ Meta::ATTACH_PENDING ]  = 'yes';
		$abroad->set_shipping_country( 'FR' );
		$this->assertSame( Release::REVIEW, Release::run( $abroad->get_id(), Release::TRIGGER_SWEEP ) );

		$this->assertSame( Release::SKIPPED, Release::run( $this->restricted_order( 'processing' )->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Release::SKIPPED, Release::run( 4040, Release::TRIGGER_WEBHOOK ) );
		$refund       = $this->held_bound_order();
		$refund->type = 'shop_order_refund';
		$this->assertSame( Release::SKIPPED, Release::run( $refund->get_id(), Release::TRIGGER_WEBHOOK ) );
	}

	public function test_sweep_skips_orders_not_due_and_denied_ones(): void {
		$later                              = $this->held_bound_order();
		$later->meta[ Meta::NEXT_CHECK_AT ] = time() + 600;
		$this->assertSame( Release::NOT_DUE, Release::run( $later->get_id(), Release::TRIGGER_SWEEP ) );

		$denied                       = $this->held_bound_order();
		$denied->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$this->assertSame( Release::NOT_DUE, Release::run( $denied->get_id(), Release::TRIGGER_SWEEP ) );
		$this->assertSame( array(), $this->http_requests );

		// A webhook after a dashboard override still clears a denied order.
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->assertSame( Release::RELEASED, Release::run( $denied->get_id(), Release::TRIGGER_WEBHOOK ) );
	}

	public function test_already_cleared_or_renewal_orders_make_no_call(): void {
		$cleared                       = $this->held_bound_order();
		Meta::mark_released( $cleared, Meta::STATUS_CLEARED );
		$this->assertSame( Release::RELEASED, Release::run( $cleared->get_id(), Release::TRIGGER_SWEEP ) );
		$this->assertSame( 'processing', $cleared->get_status() );

		$renewal                        = $this->held_bound_order();
		$renewal->meta[ Meta::RENEWAL ] = 'yes';
		$this->assertSame( Release::REVIEW, Release::run( $renewal->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'subscription_renewal', $renewal->meta[ Meta::REASON ] );
		$this->assertStringContainsString( 'Subscription renewal', end( $renewal->notes ) );
		$notes = count( $renewal->notes );
		Release::run( $renewal->get_id(), Release::TRIGGER_ADMIN );
		$this->assertCount( $notes, $renewal->notes, 'the same reason is noted once' );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_without_integration_info(): void {
		$order = $this->held_bound_order();
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		try {
			Release::run( $order->get_id(), Release::TRIGGER_HOLD );
			$this->fail( 'an outage is retryable' );
		} catch ( ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
			$this->assertSame( 'GetIntegrationInfo', $e->rpc() );
		}

		unset( $this->options[ Options::FULFILMENT_KEY ] );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_HOLD ) );
		$this->assertSame( 'not_configured', $order->meta[ Meta::REASON ] );
	}

	public function test_a_busy_lock_reschedules_shortly(): void {
		$order                                                  = $this->held_bound_order();
		$this->options[ 'navexa_agecheck_lock_' . $order->get_id() ] = ( time() + 100 ) . ':other';
		$this->assertSame( Release::LOCKED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		Release::run_job( $order->get_id(), 0, Release::TRIGGER_WEBHOOK );
		$retry = $this->scheduled_for( Release::HOOK );
		$this->assertCount( 1, $retry );
		$this->assertSame( array( $order->get_id(), 1, Release::TRIGGER_RETRY ), $retry[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + 60, $retry[0]['when'] );
		$this->assertFalse( $retry[0]['unique'], 'a running action would block a unique reschedule' );
	}

	public function test_run_job_backs_off_and_gives_up_to_the_sweep(): void {
		$order = $this->held_bound_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 429, array( 'code' => 'resource_exhausted' ), array( 'Retry-After' => '900' ) );
		Release::run_job( (string) $order->get_id(), '2', Release::TRIGGER_RETRY );
		$retry = $this->scheduled_for( Release::HOOK );
		$this->assertSame( array( $order->get_id(), 3, Release::TRIGGER_RETRY ), $retry[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + 900, $retry[0]['when'], 'Retry-After wins over the 240 s backoff' );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		Release::run_job( $order->get_id(), Release::MAX_ATTEMPTS, Release::TRIGGER_RETRY );
		$this->assertCount( 1, $this->scheduled_for( Release::HOOK ) );

		$this->respond( 403, array( 'code' => 'permission_denied' ) );
		Release::run_job( $order->get_id(), 0, 42 );
		$this->assertCount( 1, $this->scheduled_for( Release::HOOK ), 'refusals are not retried' );
	}

	public function test_enqueue_keeps_one_pending_run_per_order_and_trigger(): void {
		$this->assertTrue( Release::enqueue( 5, Release::TRIGGER_WEBHOOK ) );
		$this->assertFalse( Release::enqueue( 0, Release::TRIGGER_WEBHOOK ) );
		$this->assertSame(
			array(
				array(
					'hook'   => Release::HOOK,
					'args'   => array( 5, 0, Release::TRIGGER_WEBHOOK ),
					'group'  => Release::GROUP,
					'unique' => false,
					'when'   => 0,
					'status' => 'pending',
				),
			),
			$this->scheduled
		);

		// Action Scheduler 3.9's $unique matches hook and group only: another
		// order's pending run must not block this one (or the sweep's).
		$this->assertTrue( Release::enqueue( 6, Release::TRIGGER_WEBHOOK ) );
		$this->assertTrue( Release::enqueue( 5, Release::TRIGGER_SWEEP ) );
		$this->assertTrue( Release::enqueue( 5, Release::TRIGGER_WEBHOOK ), 'already pending' );
		$this->assertCount( 3, $this->scheduled_for( Release::HOOK ) );

		// A run in progress may have asked AgeCheck already: queue another.
		$this->scheduled[0]['status'] = 'in-progress';
		$this->assertTrue( Release::enqueue( 5, Release::TRIGGER_WEBHOOK ) );
		$this->assertCount( 4, $this->scheduled_for( Release::HOOK ) );
	}

	public function test_enqueue_reports_a_run_action_scheduler_did_not_queue(): void {
		\Brain\Monkey\Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );
		$this->assertFalse( Release::enqueue( 5, Release::TRIGGER_WEBHOOK ) );
	}

	public function test_sweep_queues_every_held_order_while_another_run_is_pending(): void {
		$first  = $this->held_bound_order();
		$second = $this->held_bound_order();
		Release::enqueue( $first->get_id(), Release::TRIGGER_HOLD );
		$this->assertSame( 2, \Navexa\AgeCheck\Sweep::run() );
		$args = array_column( $this->scheduled_for( Release::HOOK ), 'args' );
		$this->assertContains( array( $first->get_id(), 0, Release::TRIGGER_SWEEP ), $args );
		$this->assertContains( array( $second->get_id(), 0, Release::TRIGGER_SWEEP ), $args );
	}

	public function test_a_month_old_hold_is_noted_once(): void {
		$order                        = $this->held_bound_order();
		$order->meta[ Meta::HELD_AT ] = time() - 31 * 86400;
		$order->meta[ Meta::RENEWAL ] = 'yes';
		Release::run( $order->get_id(), Release::TRIGGER_SWEEP );
		Release::run( $order->get_id(), Release::TRIGGER_SWEEP );
		$stale = array_filter(
			$order->notes,
			static function ( $note ) {
				return false !== strpos( $note, 'more than 30 days' );
			}
		);
		$this->assertCount( 1, $stale );
		$this->assertGreaterThan( 0, $order->meta[ Meta::STALE_NOTED_AT ] );
	}

	public function test_reason_texts(): void {
		$this->assertStringContainsString( 'shipping address differs', Release::reason_text( 'ship_to_mismatch' ) );
		$this->assertStringContainsString( '(unauthenticated)', Release::reason_text( 'release_error_unauthenticated' ) );
		$this->assertSame( 'Reason: brand_new.', Release::reason_text( 'brand_new' ) );
	}

	private function held_bound_order(): \WC_Order {
		$order                                = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ]           = 'yes';
		$order->meta[ Meta::HELD ]               = 'yes';
		$order->meta[ Meta::INTERCEPTED_STATUS ] = 'processing';
		$order->meta[ Meta::STATUS ]             = Meta::STATUS_PENDING;
		$order->meta[ Meta::VERIFICATION_ID ]    = self::VID;
		$order->meta[ Meta::BOUND_AT ]           = time();
		Binding::external_id( $order );
		return $order;
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
