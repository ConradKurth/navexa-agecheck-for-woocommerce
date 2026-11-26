<?php
/**
 * Document retry tests (TAX-1838): the denial waits while the shopper may try
 * the ID check again, and every surface offers it only then.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\DocRetry;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\IntegrationInfo;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\OrderVerify;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;
use Navexa\AgeCheck\Webhook;

final class DocRetryTest extends TestCase {

	const VID    = 'acv_DOCRETRYTEST';
	const SECRET = 'whsec_test_0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
		$this->gateway          = new \Navexa\AgeCheck\Tests\FakeGateway();
		$this->gateway->refunds = false;
	}

	public function test_create_sends_allow_doc_retry_and_records_it(): void {
		$order                         = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ] = 'yes';
		$order->meta[ Meta::HELD ]     = 'yes';
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC' ) ) );
		$this->assertSame( Binding::BOUND, Binding::create( $order, $this->fulfilment_client() ) );
		$this->assertTrue( $this->request_body( 0 )['allowDocRetry'] );
		$this->assertSame( 'yes', $order->meta[ Meta::DOC_RETRY ] );
		$this->assertTrue( DocRetry::opted_in( $order ) );
	}

	public function test_integration_info_reads_doc_retry(): void {
		$this->assertTrue( IntegrationInfo::from_response( array( 'docRetry' => true ) )->doc_retry );
		$this->assertFalse( IntegrationInfo::from_response( array() )->doc_retry );
		$cached = IntegrationInfo::from_array( IntegrationInfo::from_response( array( 'docRetry' => true ) )->to_array() );
		$this->assertTrue( $cached->doc_retry );
	}

	public function test_a_failure_waits_for_the_retry_and_never_re_arms(): void {
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$decided = time() - 3600;
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z', $decided ) ) );
		$this->respond( 200, $this->hosted() );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetClearance', 'GetVerification', 'CreateHostedSession' ), $this->rpcs() );
		$this->assertSame( array( 'verificationId' => self::VID ), $this->request_body( 3 ) );
		$until = $decided + DocRetry::WINDOW;
		$this->assertSame( $until, (int) $order->meta[ Meta::RETRY_UNTIL ] );
		$this->assertSame( Meta::STATUS_PENDING, $order->meta[ Meta::STATUS ], 'not denied' );
		$this->assertArrayNotHasKey( Meta::REFUND, $order->meta, 'no refund while the retry is possible' );
		$this->assertSame( DocRetry::REASON, $order->meta[ Meta::REASON ] );
		$this->assertLessThanOrEqual( time() + DocRetry::RECHECK, (int) $order->meta[ Meta::NEXT_CHECK_AT ] );
		$this->assertStringContainsString( 'may try again until', implode( "\n", $order->notes ) );
		$this->assertTrue( DocRetry::open( $order ) );

		// A second failure (another retry declined) keeps the first window.
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
		$this->respond( 200, $this->hosted() );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( $until, (int) $order->meta[ Meta::RETRY_UNTIL ], 'never extended' );
	}

	public function test_after_the_window_the_failure_denies(): void {
		$order                            = $this->opted_in_order();
		$order->meta[ Meta::RETRY_UNTIL ] = time() - 1;
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( '2026-11-01T10:00:00Z' ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Meta::STATUS_DENIED, $order->meta[ Meta::STATUS ] );
	}

	public function test_no_deferral_without_the_opt_in_or_the_engine_offer(): void {
		// AgeCheck takes no retry for it (the retry off, or a failure it does
		// not cover): deny as before.
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );

		// A verification created before 1.1 (no opt-in recorded): deny.
		$old = $this->opted_in_order();
		unset( $old->meta[ Meta::DOC_RETRY ] );
		$this->assertFalse( DocRetry::opted_in( $old ) );
		$this->assertFalse( DocRetry::defer( $old, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) )['verification'], $this->fulfilment_client() ) );
	}

	public function test_a_hold_in_the_window_records_the_retry_and_a_clear_releases(): void {
		$order                                    = $this->opted_in_order();
		$order->meta[ Meta::RETRY_UNTIL ]         = time() + 3600;
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'failed';
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'HOLD', 'pending' ) );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_DOC_PENDING' ) ) );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( array( 'GetIntegrationInfo', 'GetClearance', 'GetVerification' ), $this->rpcs() );
		$this->assertSame( 'doc_pending', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertTrue( DocRetry::retrying( $order ) );
		$this->assertStringContainsString( 'trying the age check again', end( $order->notes ) );

		// Back on the ID check: the run's one link ask for the verification (a
		// link already recorded for it is never asked again), then CLEARED.
		$this->respond( 200, array( 'linkId' => 'acl_RETRY', 'emailQueued' => true ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 'processing', $order->get_status() );
	}

	public function test_needs_doc_webhook_queues_a_run_only_while_a_retry_waits(): void {
		$this->options[ Options::WEBHOOK_SECRET ] = self::SECRET;
		$order                                    = $this->opted_in_order();
		$response = Webhook::handle( $this->signed( $this->event( $order, 'needs_doc', 'ace_n1' ) ) );
		$this->assertSame( array( 'result' => 'ignored' ), $response->get_data(), 'the check\'s start' );

		$order->meta[ Meta::RETRY_UNTIL ] = time() + 3600;
		$response                         = Webhook::handle( $this->signed( $this->event( $order, 'needs_doc', 'ace_n2' ) ) );
		$this->assertSame( array( 'result' => 'queued' ), $response->get_data() );
		$this->assertStringContainsString( 'trying the age check again', end( $order->notes ) );
	}

	public function test_staff_may_email_a_new_link_while_the_retry_is_open(): void {
		$order                                    = $this->opted_in_order();
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'failed';
		$this->assertFalse( Links::can_resend( $order ), 'no window recorded' );
		$order->meta[ Meta::RETRY_UNTIL ] = time() + 3600;
		$this->assertFalse( Links::can_resend( $order ), 'AgeCheck has not confirmed the retry' );
		$order->meta[ Meta::RETRY_OPEN ] = 'yes';
		$this->assertTrue( Links::can_resend( $order ) );
		$order->meta[ Meta::RETRY_UNTIL ] = time() - 1;
		$this->assertFalse( Links::can_resend( $order ), 'the window is over' );
		$this->assertNotContains( 'failed', Links::HOSTED_STATES, 'a release run never emails a failed check' );
	}

	public function test_the_order_page_offers_try_again(): void {
		$order                                    = $this->opted_in_order();
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'failed';
		$order->meta[ Meta::RETRY_UNTIL ]         = time() + 3600;
		$this->assertFalse( OrderVerify::needs_shopper( $order ), 'not confirmed by AgeCheck: no button' );
		$order->meta[ Meta::RETRY_OPEN ] = 'yes';
		$this->assertTrue( OrderVerify::needs_shopper( $order ) );
		ob_start();
		OrderVerify::render_for( $order );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Your ID check didn&#039;t pass', $html );
		$this->assertStringContainsString( 'You can try again until', $html );
		$this->assertStringContainsString( '>Try again</button>', $html );
		$this->assertStringNotContainsString( 'will not ship', $html );
		$this->assertStringNotContainsString( self::VID, $html );

		$order->meta[ Meta::RETRY_UNTIL ] = time() - 1;
		$this->assertFalse( OrderVerify::needs_shopper( $order ), 'over: no button' );
	}

	public function test_an_under_age_failure_is_denied_at_once_and_never_offered_a_retry(): void {
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a hosted session is not allowed' ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Meta::STATUS_DENIED, $order->meta[ Meta::STATUS ] );
		$this->assertFalse( DocRetry::open( $order ) );
		$this->assertFalse( Links::can_resend( $order ) );
		$this->assertFalse( OrderVerify::needs_shopper( $order ) );
	}

	public function test_an_outage_on_the_retry_check_is_retried_not_denied(): void {
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) ) );
		$this->respond( 503, array( 'code' => 'unavailable', 'message' => 'down' ) );
		try {
			Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK );
			$this->fail( 'the outage was not rethrown' );
		} catch ( \Navexa\AgeCheck\ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
		$this->assertSame( Meta::STATUS_PENDING, $order->meta[ Meta::STATUS ], 'not denied' );
	}

	public function test_the_window_runs_from_the_failure_webhook_when_the_shopper_retried_first(): void {
		// The shopper pressed Try again before the release run read the
		// failure: only the verification.failed webhook saw it.
		$this->options[ Options::WEBHOOK_SECRET ] = self::SECRET;
		$order                                    = $this->opted_in_order();
		$first                                    = time() - 5 * DAY_IN_SECONDS;
		$response = Webhook::handle( $this->signed( $this->event( $order, 'failed', 'ace_f1', gmdate( 'Y-m-d\TH:i:s\Z', $first ) ) ) );
		$this->assertSame( array( 'result' => 'queued' ), $response->get_data() );
		$this->assertSame( $first + DocRetry::WINDOW, (int) $order->meta[ Meta::RETRY_UNTIL ] );
		$this->assertFalse( DocRetry::open( $order ), 'not yet confirmed by AgeCheck' );

		// The retry fails again today: the window keeps the first failure.
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
		$this->respond( 200, $this->hosted() );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( $first + DocRetry::WINDOW, (int) $order->meta[ Meta::RETRY_UNTIL ], 'never moved later' );
		$this->assertTrue( DocRetry::open( $order ) );

		// A later failure webhook (AgeCheck's own lapse) moves nothing later,
		// and drops the confirmation until the next run asks again.
		Webhook::handle( $this->signed( $this->event( $order, 'failed', 'ace_f2', gmdate( 'Y-m-d\TH:i:s\Z' ) ) ) );
		$this->assertSame( $first + DocRetry::WINDOW, (int) $order->meta[ Meta::RETRY_UNTIL ] );
		$this->assertFalse( DocRetry::open( $order ) );
	}

	public function test_the_window_never_ends_after_the_creation_bound(): void {
		$order   = $this->opted_in_order();
		$created = time() - 25 * DAY_IN_SECONDS;
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$failed                      = $this->failed( gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) );
		$failed['verification']['createdAt'] = gmdate( 'Y-m-d\TH:i:s\Z', $created );
		$this->respond( 200, $failed );
		$this->respond( 200, $this->hosted() );
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( $created + DocRetry::CREATED_BOUND, (int) $order->meta[ Meta::RETRY_UNTIL ] );
	}

	public function test_a_denied_order_that_passes_later_is_left_to_staff(): void {
		// Denied (listed for a manual refund), then the shopper passes a
		// retry AgeCheck still took: never released on its own.
		$order                             = $this->opted_in_order();
		$order->meta[ Meta::STATUS ]       = Meta::STATUS_DENIED;
		$order->meta[ Meta::REFUND ]       = Meta::REFUND_MANUAL;
		$order->meta[ Meta::REASON ]       = 'not_verified';
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Hold::STATUS, $order->get_status() );
		$this->assertSame( Meta::STATUS_DENIED, $order->meta[ Meta::STATUS ] );
		$this->assertSame( 'passed_after_denial', $order->meta[ Meta::REASON ] );
		$this->assertFalse( Meta::is_released( $order ) );

		// A later run reads DENIED replayed: still staff's.
		$this->respond( 200, $this->clearance( 'DENIED', 'replayed' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_DOC_ONLY' ) );
		$this->assertSame( Release::REVIEW, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( Hold::STATUS, $order->get_status() );

		// A merchant override in AgeCheck's dashboard is staff's decision:
		// it releases, as for an order without the retry.
		$this->respond( 200, $this->clearance( 'DENIED', 'replayed' ) );
		$this->respond( 200, $this->passed( 'PASS_CLASS_MERCHANT_OVERRIDE' ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 'processing', $order->get_status() );
		// TAX-1887: released, so it leaves the manual refund list.
		$this->assertArrayNotHasKey( Meta::REFUND, $order->saved_meta );
	}

	public function test_a_release_clears_the_retry_flag(): void {
		$order                                    = $this->opted_in_order();
		$order->meta[ Meta::RETRY_UNTIL ]         = time() + 3600;
		$order->meta[ Meta::RETRY_OPEN ]          = 'yes';
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'failed';
		$order->meta[ Meta::REASON ]              = DocRetry::REASON;
		$this->respond( 200, $this->live_info( array( 'docRetry' => true ) ) );
		$this->respond( 200, $this->clearance( 'CLEARED', 'verified' ) );
		$this->assertSame( Release::RELEASED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertArrayNotHasKey( Meta::RETRY_OPEN, $order->saved_meta );
		$this->assertArrayNotHasKey( Meta::REASON, $order->saved_meta );
	}

	public function test_a_test_verification_window_ends_at_the_test_bound(): void {
		// AgeCheck caps a test verification's retry at its creation + 3 days
		// (domain.DocRetryCreatedBound(true)); a live one at + 26 days.
		$this->assertSame( 3 * DAY_IN_SECONDS, DocRetry::CREATED_BOUND_TEST );
		$this->assertSame( 26 * DAY_IN_SECONDS, DocRetry::CREATED_BOUND );
		$this->assertSame( DocRetry::CREATED_BOUND_TEST, DocRetry::created_bound( true ) );
		$this->assertSame( DocRetry::CREATED_BOUND, DocRetry::created_bound( false ) );

		$created  = time() - DAY_IN_SECONDS;
		$failed   = time() - 600;
		$test     = $this->opted_in_order();
		$live     = $this->opted_in_order();
		$test->meta[ Meta::BOUND_AT ] = $created;
		$live->meta[ Meta::BOUND_AT ] = $created;
		$this->assertSame( $created + DocRetry::CREATED_BOUND_TEST, DocRetry::anchor( $test, $failed, $created, true ) );
		$this->assertSame( $failed + DocRetry::WINDOW, DocRetry::anchor( $live, $failed, $created ) );

		// A test window already recorded at 7 days moves to the test bound.
		$old                             = $this->opted_in_order();
		$old->meta[ Meta::BOUND_AT ]     = $created;
		$old->meta[ Meta::RETRY_UNTIL ]  = $failed + DocRetry::WINDOW;
		$this->respond( 200, $this->hosted() );
		$verification              = $this->failed( gmdate( 'Y-m-d\TH:i:s\Z', $failed ) )['verification'];
		$verification['createdAt'] = gmdate( 'Y-m-d\TH:i:s\Z', $created );
		$this->assertTrue( DocRetry::defer( $old, $verification, $this->fulfilment_client(), DocRetry::MODE_TEST ) );
		$this->assertSame( $created + DocRetry::CREATED_BOUND_TEST, (int) $old->meta[ Meta::RETRY_UNTIL ] );
		$this->assertSame( DocRetry::REASON_TEST, $old->meta[ Meta::REASON ] );
		$this->assertTrue( DocRetry::is_reason( DocRetry::REASON_TEST ) );
		$this->assertTrue( DocRetry::is_reason( DocRetry::REASON ) );
		$this->assertFalse( DocRetry::is_reason( 'test_mode' ) );
	}

	public function test_a_test_failure_webhook_anchors_at_the_test_bound(): void {
		$this->options[ Options::WEBHOOK_SECRET ] = self::SECRET;
		$order                                    = $this->opted_in_order();
		$order->meta[ Meta::BOUND_AT ]            = time() - DAY_IN_SECONDS;
		$failed                                   = time() - 600;
		$body = json_decode( $this->event( $order, 'failed', 'ace_t1', gmdate( 'Y-m-d\TH:i:s\Z', $failed ) ), true );
		$body['livemode'] = false;
		Webhook::handle( $this->signed( wp_json_encode( $body ) ) );
		$this->assertSame( (int) $order->meta[ Meta::BOUND_AT ] + DocRetry::CREATED_BOUND_TEST, (int) $order->meta[ Meta::RETRY_UNTIL ] );

		// A live event keeps the 7-day window.
		$live                         = $this->opted_in_order();
		$live->meta[ Meta::BOUND_AT ] = time() - DAY_IN_SECONDS;
		Webhook::handle( $this->signed( $this->event( $live, 'failed', 'ace_l1', gmdate( 'Y-m-d\TH:i:s\Z', $failed ) ) ) );
		$this->assertSame( $failed + DocRetry::WINDOW, (int) $live->meta[ Meta::RETRY_UNTIL ] );
	}

	/** A held order bound to a verification this plugin created with the opt-in. */
	private function opted_in_order(): \WC_Order {
		$order                                = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ]           = 'yes';
		$order->meta[ Meta::HELD ]               = 'yes';
		$order->meta[ Meta::INTERCEPTED_STATUS ] = 'processing';
		$order->meta[ Meta::STATUS ]             = Meta::STATUS_PENDING;
		$order->meta[ Meta::VERIFICATION_ID ]    = self::VID;
		$order->meta[ Meta::BOUND_AT ]           = time();
		$order->meta[ Meta::BOUND_BY ]           = 'create';
		$order->meta[ Meta::DOC_RETRY ]          = 'yes';
		Binding::external_id( $order );
		return $order;
	}

	private function failed( string $decided_at ): array {
		return array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_FAILED', 'decidedAt' => $decided_at ) );
	}

	private function passed( string $class ): array {
		return array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_PASSED', 'passClass' => $class ) );
	}

	private function hosted(): array {
		return array( 'hostedUrl' => 'https://verify.navexa.co/v/tok' );
	}

	private function clearance( string $decision, string $reason ): array {
		return array(
			'decision'       => 'CLEARANCE_DECISION_' . $decision,
			'verificationId' => self::VID,
			'reason'         => $reason,
			'livemode'       => true,
		);
	}

	private function event( \WC_Order $order, string $state, string $event_id, string $occurred_at = '2026-11-25T00:00:00Z' ): string {
		return wp_json_encode(
			array(
				'event_id'          => $event_id,
				'organization_id'   => 'org_1',
				'verification_id'   => self::VID,
				'state'             => $state,
				'policy_version'    => '2026-10-30.1',
				'order_external_id' => $order->meta[ Meta::EXTERNAL_ID ],
				'livemode'          => true,
				'occurred_at'       => $occurred_at,
			)
		);
	}

	private function signed( string $body ): \WP_REST_Request {
		$timestamp = (string) time();
		return new \WP_REST_Request(
			$body,
			array(
				'X-Webhook-Timestamp'    => $timestamp,
				'X-Webhook-Signature-V2' => hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET ),
			)
		);
	}
}
