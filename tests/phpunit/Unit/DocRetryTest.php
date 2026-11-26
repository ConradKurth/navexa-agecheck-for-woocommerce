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
		$this->assertSame( Release::PENDING, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );
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
		// The engine does not offer it (docRetry off): deny as before.
		$order = $this->opted_in_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->clearance( 'DENIED', 'not_verified' ) );
		$this->respond( 200, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
		$this->assertSame( Release::DENIED, Release::run( $order->get_id(), Release::TRIGGER_WEBHOOK ) );

		// A verification created before 1.1 (no opt-in recorded): deny.
		$old = $this->opted_in_order();
		unset( $old->meta[ Meta::DOC_RETRY ] );
		$this->assertFalse( DocRetry::opted_in( $old ) );
		$info = IntegrationInfo::from_response( $this->live_info( array( 'docRetry' => true ) ) );
		$this->assertFalse( DocRetry::defer( $old, $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) )['verification'], $info ) );
		// A test key never defers (nor denies: the release path never asks).
		$test = IntegrationInfo::from_response( array( 'livemode' => false, 'docRetry' => true ) );
		$this->assertFalse( DocRetry::defer( $this->opted_in_order(), $this->failed( gmdate( 'Y-m-d\TH:i:s\Z' ) )['verification'], $test ) );
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
		$this->assertTrue( Links::can_resend( $order ) );
		$order->meta[ Meta::RETRY_UNTIL ] = time() - 1;
		$this->assertFalse( Links::can_resend( $order ), 'the window is over' );
		$this->assertNotContains( 'failed', Links::HOSTED_STATES, 'a release run never emails a failed check' );
	}

	public function test_the_order_page_offers_try_again(): void {
		$order                                    = $this->opted_in_order();
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'failed';
		$order->meta[ Meta::RETRY_UNTIL ]         = time() + 3600;
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

	private function clearance( string $decision, string $reason ): array {
		return array(
			'decision'       => 'CLEARANCE_DECISION_' . $decision,
			'verificationId' => self::VID,
			'reason'         => $reason,
			'livemode'       => true,
		);
	}

	private function event( \WC_Order $order, string $state, string $event_id ): string {
		return wp_json_encode(
			array(
				'event_id'          => $event_id,
				'organization_id'   => 'org_1',
				'verification_id'   => self::VID,
				'state'             => $state,
				'policy_version'    => '2026-10-30.1',
				'order_external_id' => $order->meta[ Meta::EXTERNAL_ID ],
				'livemode'          => true,
				'occurred_at'       => '2026-11-25T00:00:00Z',
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
