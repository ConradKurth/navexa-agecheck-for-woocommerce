<?php
/**
 * Webhook receiver tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;
use Navexa\AgeCheck\Webhook;

final class WebhookTest extends TestCase {

	const SECRET = 'whsec_test_0123456789abcdef';
	const VID    = 'acv_WEBHOOK1';

	/** @var \WC_Order */
	private $order;

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->options[ Options::WEBHOOK_SECRET ] = self::SECRET;
		$this->order                              = $this->restricted_order( Hold::STATUS );
		$this->order->meta[ Meta::VERIFICATION_ID ] = self::VID;
		Binding::external_id( $this->order );
	}

	public function test_route_is_public_and_signature_checked_in_the_callback(): void {
		Functions\expect( 'register_rest_route' )->once()->with(
			'navexa-agecheck/v1',
			'/webhook',
			\Mockery::on(
				static function ( $args ) {
					return 'POST' === $args['methods'] && '__return_true' === $args['permission_callback'] && array( Webhook::class, 'handle' ) === $args['callback'];
				}
			)
		);
		Webhook::register_route();
		Webhook::register();
		$this->assertNotFalse( has_action( 'rest_api_init', array( Webhook::class, 'register_route' ) ) );
		$this->assertSame( 'https://shop.example/wp-json/navexa-agecheck/v1/webhook', Webhook::url() );
	}

	public function test_engine_signature_vector(): void {
		// tax webhookdelivery/delivery_test.go fixture (computed with openssl there).
		$secret = '4f1c2b7e9a0d3c5e8f6a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6a';
		$body   = '{"state": "passed", "event_id": "ace_1", "occurred_at": "2026-10-15T12:00:00Z"}';
		$sig    = '81f9a82ae6d514cd9a811a04ba63f71ed3faaec05a29110bdc27846d8ddc711f';
		$this->assertSame( '', Webhook::signature_problem( $body, '1792065600', $sig, $secret, 1792065600 ) );
		$this->assertSame( '', Webhook::signature_problem( $body, '1792065600', strtoupper( $sig ) . ' ', $secret, 1792065900 ), 'case and padding; 300 s is inside the window' );
		$this->assertSame( 'stale', Webhook::signature_problem( $body, '1792065600', $sig, $secret, 1792065901 ) );
		$this->assertSame( 'stale', Webhook::signature_problem( $body, '1792065600', $sig, $secret, 1792065299 ) );
		$this->assertSame( 'mismatch', Webhook::signature_problem( $body . ' ', '1792065600', $sig, $secret, 1792065600 ) );
		$this->assertSame( 'mismatch', Webhook::signature_problem( $body, '1792065601', $sig, $secret, 1792065600 ), 're-dated' );
		$this->assertSame( 'unsigned', Webhook::signature_problem( $body, null, $sig, $secret, 1792065600 ) );
		$this->assertSame( 'unsigned', Webhook::signature_problem( $body, '17920656.0', $sig, $secret, 1792065600 ) );
		$this->assertSame( 'unsigned', Webhook::signature_problem( $body, '1792065600', '', $secret, 1792065600 ) );
		$this->assertSame( 'unsigned', Webhook::signature_problem( $body, '1792065600', $sig, '', 1792065600 ) );
	}

	public function test_passed_event_queues_a_release_once(): void {
		$body     = $this->event( 'passed' );
		$response = Webhook::handle( $this->signed( $body ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'queued' ), $response->get_data() );
		$jobs = $this->scheduled_for( Release::HOOK );
		$this->assertSame( array( $this->order->get_id(), 0, Release::TRIGGER_WEBHOOK ), $jobs[0]['args'] );
		$this->assertGreaterThan( 0, $this->options[ Options::WEBHOOK_LAST_RECEIVED ] );

		$again = Webhook::handle( $this->signed( $body ) );
		$this->assertSame( array( 'result' => 'duplicate' ), $again->get_data(), 'deduped on the signed event_id' );
		$this->assertCount( 1, $this->scheduled_for( Release::HOOK ) );
	}

	public function test_failed_event_queues_a_release_too(): void {
		$response = Webhook::handle( $this->signed( $this->event( 'failed', array( 'event_id' => 'ace_2' ) ) ) );
		$this->assertSame( array( 'result' => 'queued' ), $response->get_data() );
	}

	public function test_refusals(): void {
		$body = $this->event( 'passed' );
		$this->assertSame( 401, Webhook::handle( new \WP_REST_Request( $body, array() ) )->get_status() );
		$this->assertSame( 401, Webhook::handle( $this->signed( $body, time() - 301 ) )->get_status() );
		$this->assertSame( 401, Webhook::handle( $this->signed( $body, null, 'wrong-secret-0123456789' ) )->get_status() );
		$this->assertStringContainsString( 'agecheck webhook refused: mismatch', $this->all_logs() );
		$this->assertSame( 413, Webhook::handle( $this->signed( str_repeat( 'x', 65537 ) ) )->get_status() );
		$this->assertSame( array(), $this->scheduled );

		unset( $this->options[ Options::WEBHOOK_SECRET ] );
		$this->assertSame( 503, Webhook::handle( $this->signed( $body ) )->get_status() );
		$this->assertArrayNotHasKey( Options::WEBHOOK_LAST_RECEIVED, $this->options );
	}

	public function test_events_that_change_nothing_answer_200(): void {
		$cases = array(
			'not json'       => 'not json',
			'no event id'    => wp_json_encode( array( 'state' => 'passed' ) ),
			'needs_doc'      => $this->event( 'needs_doc', array( 'event_id' => 'ace_3' ) ),
			'unknown event'  => wp_json_encode( array( 'event' => 'verification.other', 'verification_id' => self::VID ) ),
			'other site'     => $this->event( 'passed', array( 'event_id' => 'ace_4', 'order_external_id' => 'wc:' . str_repeat( 'a', 32 ) . ':2:' . $this->order->get_id() ) ),
			'unbound'        => $this->event( 'passed', array( 'event_id' => 'ace_5', 'order_external_id' => '' ) ),
			'other vid'      => $this->event( 'passed', array( 'event_id' => 'ace_6', 'verification_id' => 'acv_OTHER' ) ),
			'external drift' => $this->event( 'passed', array( 'event_id' => 'ace_7', 'order_external_id' => 'wc:' . str_repeat( 'c', 32 ) . ':1:' . $this->order->get_id() ) ),
			'no order'       => $this->event( 'passed', array( 'event_id' => 'ace_8', 'order_external_id' => 'wc:' . str_repeat( 'c', 32 ) . ':1:4040' ) ),
		);
		foreach ( $cases as $name => $body ) {
			$this->assertSame( 200, Webhook::handle( $this->signed( $body ) )->get_status(), $name );
		}
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_hold_stale_is_noted_once(): void {
		$body = wp_json_encode(
			array(
				'event'             => 'verification.hold_stale',
				'organization_id'   => 'org_1',
				'verification_id'   => self::VID,
				'order_external_id' => $this->order->meta[ Meta::EXTERNAL_ID ],
				'state'             => 'needs_doc',
				'livemode'          => true,
				'pending_hours'     => 48,
				'occurred_at'       => '2026-11-14T00:00:00Z',
			)
		);
		$this->assertSame( array( 'result' => 'noted' ), Webhook::handle( $this->signed( $body ) )->get_data() );
		$this->assertStringContainsString( 'waited 48 hours', end( $this->order->notes ) );
		$this->assertSame( array( 'result' => 'duplicate' ), Webhook::handle( $this->signed( $body ) )->get_data() );
		$this->assertSame( 'hold_stale:' . self::VID, Webhook::dedupe_key( json_decode( $body, true ) ) );

		unset( $this->options[ 'navexa_agecheck_seen_' . substr( hash( 'sha256', 'hold_stale:' . self::VID ), 0, 40 ) ] );
		$notes = count( $this->order->notes );
		Webhook::handle( $this->signed( $body ) );
		$this->assertCount( $notes, $this->order->notes, 'HOLD_STALE_AT stops a second note' );
		$this->assertSame( '', Webhook::dedupe_key( array( 'event' => 'verification.hold_stale' ) ) );
	}

	public function test_a_failure_while_acting_forgets_the_delivery_so_the_retry_runs(): void {
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function () {
				throw new \RuntimeException( 'database gone' );
			}
		);
		$body     = $this->event( 'passed', array( 'event_id' => 'ace_9' ) );
		$response = Webhook::handle( $this->signed( $body ) );
		$this->assertSame( 500, $response->get_status() );
		$this->assertArrayNotHasKey( 'navexa_agecheck_seen_' . substr( hash( 'sha256', 'event:ace_9' ), 0, 40 ), $this->options );
	}

	public function test_a_run_action_scheduler_did_not_queue_is_redelivered(): void {
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );
		$body     = $this->event( 'passed', array( 'event_id' => 'ace_10' ) );
		$response = Webhook::handle( $this->signed( $body ) );
		$this->assertSame( 500, $response->get_status(), 'never "queued" when nothing was' );
		$this->assertArrayNotHasKey( 'navexa_agecheck_seen_' . substr( hash( 'sha256', 'event:ace_10' ), 0, 40 ), $this->options );
	}

	public function test_dedupe_keys_are_sanitized(): void {
		$this->assertSame( 'event:ace_1DROPabc', Webhook::dedupe_key( array( 'event_id' => "ace_1'; DROP<abc>" ) ) );
		$this->assertSame( '', Webhook::dedupe_key( array( 'event_id' => 5 ) ) );
	}

	private function event( string $state, array $overrides = array() ): string {
		return wp_json_encode(
			array_merge(
				array(
					'event_id'          => 'ace_1',
					'organization_id'   => 'org_1',
					'verification_id'   => self::VID,
					'state'             => $state,
					'pass_class'        => 'passed' === $state ? 'doc_only' : '',
					'policy_version'    => '2026-10-01.1',
					'order_external_id' => $this->order->meta[ Meta::EXTERNAL_ID ],
					'livemode'          => true,
					'occurred_at'       => '2026-11-14T00:00:00Z',
				),
				$overrides
			)
		);
	}

	private function signed( string $body, ?int $at = null, string $secret = self::SECRET ): \WP_REST_Request {
		$timestamp = (string) ( null === $at ? time() : $at );
		return new \WP_REST_Request(
			$body,
			array(
				'X-Webhook-Timestamp'    => $timestamp,
				'X-Webhook-Signature-V2' => hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
				'X-Webhook-Signature'    => 'ignored',
				'X-Webhook-Event-Id'     => 'unsigned-and-unused',
			)
		);
	}
}
