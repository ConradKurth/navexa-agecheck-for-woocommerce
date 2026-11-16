<?php
/**
 * Verification link tests: when a held order's shopper is emailed, every
 * SendVerificationLink answer, the store-wide refusal, and staff's resend
 * and withdraw.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\DisabledLinkSender;
use Navexa\AgeCheck\EngineLinkSender;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\IntegrationInfo;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Lock;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;

final class LinksTest extends TestCase {

	const VID = 'acv_LINKSTEST';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->options['date_format'] = 'Y-m-d';
		$this->options['time_format'] = 'H:i';
	}

	public function test_sender_is_the_engine_unless_filtered(): void {
		$this->assertInstanceOf( EngineLinkSender::class, Links::sender(), 'no opt-in constant: the engine decides' );
		Filters\expectApplied( 'navexa_agecheck_link_sender' )->andReturn( 'junk' );
		$this->assertInstanceOf( EngineLinkSender::class, Links::sender() );
	}

	public function test_a_sent_link_is_recorded_once_with_its_id_and_expiry(): void {
		$order = $this->created_order();
		$this->respond( 200, array( 'linkId' => 'acl_ONE', 'expiresAt' => '2026-11-22T10:00:00Z', 'emailQueued' => true ) );

		$this->assertSame( Links::STATE_SENT, Links::send( $order, $this->fulfilment_client(), $this->info() ) );

		$this->assertSame( array( 'SendVerificationLink' ), $this->rpcs() );
		$body = $this->request_body( 0 );
		$this->assertMatchesRegularExpression( '/^vl:' . self::VID . ':[0-9a-f]{16}$/', $body['idempotencyKey'] );
		$this->assertSame( self::VID, $body['verificationId'] );
		$this->assertStringStartsWith( 'https://shop.example/?wc-api=navexa_agecheck_order&step=return&order=' . $order->get_id() . '&t=', $body['returnUrl'] );
		$this->assertStringNotContainsString( 'key=', $body['returnUrl'], 'never the order key' );
		$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertSame( 'acl_ONE', $order->meta[ Meta::LINK_ID ] );
		$this->assertSame( strtotime( '2026-11-22T10:00:00Z' ), $order->meta[ Meta::LINK_EXPIRES_AT ] );
		$this->assertGreaterThan( time() - 5, $order->meta[ Meta::LINK_SENT_AT ] );
		$this->assertArrayNotHasKey( Meta::LINK_KEY, $order->meta, 'the key goes once an answer is recorded' );
		$this->assertStringContainsString( 'emailed the shopper a link to verify their age (acl_ONE, good until 2026-11-22)', end( $order->notes ) );
		$this->assertStringContainsString( 'agecheck link order=' . $order->get_id() . ' vid=' . self::VID . ' state=sent', $this->all_logs() );

		$notes = count( $order->notes );
		$this->assertSame( Links::STATE_SENT, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertCount( 1, $this->http_requests, 'sent once per verification' );
		$this->assertCount( $notes, $order->notes );
	}

	public function test_no_return_url_unless_the_store_origin_is_allowed(): void {
		$order = $this->created_order();
		$this->respond( 200, array( 'linkId' => 'acl_ONE', 'emailQueued' => true ) );
		Links::send( $order, $this->fulfilment_client(), $this->info( array( 'originAllowed' => false ) ) );
		$this->assertArrayNotHasKey( 'returnUrl', $this->request_body( 0 ) );
		$this->assertArrayNotHasKey( Meta::LINK_EXPIRES_AT, $order->meta );
		$this->assertSame( 'Emailed to the shopper.', Links::describe( $order ) );
	}

	public function test_the_key_is_stored_before_the_call_and_replayed_after_a_lost_answer(): void {
		$order = $this->created_order();
		$this->respond_transport_error( 'cURL error 28: Operation timed out' );
		try {
			Links::send( $order, $this->fulfilment_client(), $this->info() );
			$this->fail( 'a timeout is rethrown' );
		} catch ( ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
		$key = $order->meta[ Meta::LINK_KEY ];
		$this->assertSame( $key, $this->request_body( 0 )['idempotencyKey'] );
		$this->assertGreaterThan( 0, $order->meta_saves, 'saved before the call' );
		$this->assertArrayNotHasKey( Meta::LINK, $order->meta );

		$this->respond( 200, array( 'linkId' => 'acl_ONE', 'emailQueued' => true ) );
		Links::send( $order, $this->fulfilment_client(), $this->info() );
		$this->assertSame( $key, $this->request_body( 1 )['idempotencyKey'], 'the retry replays: no second email' );

		$other                        = $this->created_order();
		$other->meta[ Meta::LINK_KEY ] = 'vl:acv_SOMEONE_ELSE:0123456789abcdef';
		$this->respond( 200, array( 'linkId' => 'acl_TWO', 'emailQueued' => true ) );
		Links::send( $other, $this->fulfilment_client(), $this->info() );
		$this->assertStringStartsWith( 'vl:' . self::VID . ':', $this->request_body( 2 )['idempotencyKey'], 'a key for another verification is never reused' );
	}

	public function test_links_off_is_recorded_once_for_the_store_and_not_asked_again_for_an_hour(): void {
		$first = $this->created_order();
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: verification links are not enabled' ) );
		$this->assertSame( Links::STATE_LINKS_OFF, Links::send( $first, $this->fulfilment_client(), $this->info() ) );
		$this->assertStringContainsString( 'AgeCheck is not sending verification emails yet', end( $first->notes ) );
		$this->assertSame( Links::STATE_LINKS_OFF, Links::store_block()['reason'] );
		$this->assertArrayNotHasKey( Meta::LINK_KEY, $first->meta, 'a refusal drops the key: the next ask is a new request' );
		$this->assertTrue( $this->autoload[ Links::OPTION_BLOCK ] );
		$this->assertStringNotContainsString( 'not enabled', $this->all_logs(), 'never the engine\'s message' );

		$second = $this->created_order( 'acv_SECOND' );
		$this->assertSame( Links::STATE_LINKS_OFF, Links::send( $second, $this->fulfilment_client(), $this->info() ) );
		$this->assertSame( 'links_off:acv_SECOND', $second->meta[ Meta::LINK ] );
		$this->assertStringContainsString( 'not sending verification emails yet', end( $second->notes ) );
		$this->assertCount( 1, $this->http_requests, 'no retry storm: the rest of the store waits' );
		$this->assertArrayNotHasKey( Meta::LINK_KEY, $second->meta );
		$notes = count( $first->notes );
		Links::send( $first, $this->fulfilment_client(), $this->info() );
		$this->assertCount( $notes, $first->notes, 'noted once per verification' );

		$this->options[ Links::OPTION_BLOCK ]['at'] = time() - Links::BLOCK_TTL;
		$this->respond( 200, array( 'linkId' => 'acl_ON', 'emailQueued' => true ) );
		$this->assertSame( Links::STATE_SENT, Links::send( $second, $this->fulfilment_client(), $this->info() ) );
		$this->assertCount( 2, $this->http_requests, 'asked again after an hour' );
		$this->assertArrayNotHasKey( Links::OPTION_BLOCK, $this->options, 'a sent link lifts the store-wide refusal' );
	}

	public function test_a_missing_display_name_blocks_until_agecheck_reports_one(): void {
		$order = $this->created_order();
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => "agecheck: set the store's display name in the AgeCheck settings before sending verification links" ) );
		$this->assertSame( Links::STATE_NO_DISPLAY_NAME, Links::send( $order, $this->fulfilment_client(), $this->info( array( 'displayName' => '' ) ) ) );
		$this->assertStringContainsString( 'Set your store name in the AgeCheck dashboard', end( $order->notes ) );
		$this->assertSame( Links::STATE_NO_DISPLAY_NAME, Links::store_block()['reason'] );

		$next = $this->created_order( 'acv_NEXT' );
		$this->assertSame( Links::STATE_NO_DISPLAY_NAME, Links::send( $next, $this->fulfilment_client(), $this->info( array( 'displayName' => '' ) ) ) );
		$this->assertCount( 1, $this->http_requests );
		$this->respond( 200, array( 'linkId' => 'acl_NAMED', 'emailQueued' => true ) );
		$this->assertSame( Links::STATE_SENT, Links::send( $next, $this->fulfilment_client(), $this->info( array( 'displayName' => 'Shop Example' ) ) ), 'a name set since lifts the block at once' );
	}

	public function test_an_engine_without_the_rpc_is_unavailable_for_the_store(): void {
		$order = $this->created_order();
		$this->respond( 404, '404 page not found' );
		$this->assertSame( Links::STATE_UNAVAILABLE, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertSame( Links::STATE_UNAVAILABLE, Links::blocked( $this->info() ) );
		$this->assertStringContainsString( 'not available from AgeCheck yet', end( $order->notes ) );
	}

	/**
	 * @dataProvider final_refusals
	 */
	public function test_refusals_about_one_verification_are_final_and_block_nothing( int $status, array $body, string $state, string $note ): void {
		$order = $this->created_order();
		$this->respond( $status, $body );
		$this->assertSame( $state, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertSame( $state . ':' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertStringContainsString( $note, end( $order->notes ) );
		$this->assertSame( array(), Links::store_block() );
		$this->assertSame( $state, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertCount( 1, $this->http_requests, 'never asked again for that verification' );
	}

	public static function final_refusals(): array {
		return array(
			'no email'    => array( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: the verification has no email to send the link to (identity.email)' ), Links::STATE_NO_EMAIL, 'no valid billing email' ),
			'not allowed' => array( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: a verification link needs a verification waiting on the shopper, with the document name check' ), Links::STATE_NOT_ALLOWED, 'cannot be emailed' ),
			'no card'     => array( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: add a card under Usage & billing to use live mode' ), Links::STATE_REFUSED, 'check billing and live access' ),
			'bad return'  => array( 400, array( 'code' => 'invalid_argument', 'message' => 'return_url: must be ...' ), Links::STATE_REFUSED, 'refused to email' ),
			'unknown'     => array( 404, array( 'code' => 'not_found', 'message' => 'agecheck: verification not found' ), Links::STATE_REFUSED, 'refused to email' ),
		);
	}

	public function test_a_test_mode_link_that_emailed_nobody(): void {
		$order = $this->created_order();
		$this->respond( 200, array( 'linkId' => 'acl_TEST' ) );
		$this->assertSame( Links::STATE_NOT_QUEUED, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertSame( 'acl_TEST', $order->meta[ Meta::LINK_ID ] );
		$this->assertStringContainsString( 'emailed nobody', end( $order->notes ) );
		$this->assertTrue( Links::can_withdraw( $order ) );
	}

	public function test_budget_and_rate_limits(): void {
		$order = $this->created_order();
		$this->respond( 429, array( 'code' => 'resource_exhausted', 'message' => 'agecheck: too many verification links sent for this verification in the last 24 hours; retry later' ) );
		$this->assertSame( Links::STATE_SENT, Links::send( $order, $this->fulfilment_client(), $this->info() ), 'the budget is used up: the shopper has been emailed' );
		$this->assertArrayNotHasKey( Meta::LINK_KEY, $order->meta );

		$limited = $this->created_order( 'acv_RATE' );
		$this->respond( 429, array( 'code' => 'resource_exhausted', 'message' => 'rate limited' ), array( 'Retry-After' => '30' ) );
		try {
			Links::send( $limited, $this->fulfilment_client(), $this->info() );
			$this->fail( 'the key\'s rate limit is retried later' );
		} catch ( ConnectError $e ) {
			$this->assertSame( 30, $e->retry_after() );
		}
		$this->assertArrayHasKey( Meta::LINK_KEY, $limited->meta, 'kept for the retry' );

		$this->respond( 403, array( 'code' => 'permission_denied', 'message' => 'scope' ) );
		$this->expectException( ConnectError::class );
		Links::send( $this->created_order( 'acv_SCOPE' ), $this->fulfilment_client(), $this->info() );
	}

	public function test_only_verifications_this_plugin_created_are_emailed(): void {
		foreach ( array( 'attach', 'adopt', '' ) as $by ) {
			$order                         = $this->created_order();
			$order->meta[ Meta::BOUND_BY ] = $by;
			$this->assertSame( Links::STATE_NOT_ELIGIBLE, Links::send( $order, $this->fulfilment_client(), $this->info() ), $by );
			$this->assertStringContainsString( 'match the ID to the billing name, so none was sent', end( $order->notes ) );
			$this->assertFalse( Links::can_resend( $order ) );
		}
		$this->assertSame( array(), $this->http_requests, 'a checkout verification (name check NONE) is never emailed (Q7)' );
		$this->assertSame( '', Links::send( $this->restricted_order( Hold::STATUS ), $this->fulfilment_client(), $this->info() ) );
	}

	public function test_the_kill_switch_makes_no_call(): void {
		Filters\expectApplied( 'navexa_agecheck_link_sender' )->andReturn( new DisabledLinkSender() );
		$order = $this->created_order();
		$this->assertSame( Links::STATE_DISABLED, Links::send( $order, $this->fulfilment_client(), $this->info() ) );
		$this->assertSame( array(), $this->http_requests );
		$this->assertStringContainsString( 'NAVEXA_AGECHECK_EMAIL_LINKS', end( $order->notes ) );
		$this->assertSame( array(), Links::store_block(), 'the site\'s own switch is not AgeCheck refusing' );
		$this->assertStringContainsString( 'turned off on this site', Links::describe( $order ) );
	}

	public function test_blocked_window(): void {
		$now = 1800000000;
		$this->assertSame( '', Links::blocked( null, $now ) );
		$this->options[ Links::OPTION_BLOCK ] = array(
			'reason' => Links::STATE_LINKS_OFF,
			'at'     => $now - 10,
		);
		$this->assertSame( Links::STATE_LINKS_OFF, Links::blocked( null, $now ) );
		$this->assertSame( '', Links::blocked( null, $now + Links::BLOCK_TTL ), 'an hour later: ask again' );
		$this->assertSame( '', Links::blocked( null, $now - 20 ), 'a refusal from the future (clock change) stands for nothing' );
		$this->options[ Links::OPTION_BLOCK ] = array(
			'reason' => 'something_else',
			'at'     => $now,
		);
		$this->assertSame( array(), Links::store_block() );
		$this->options[ Links::OPTION_BLOCK ] = 'junk';
		$this->assertSame( '', Links::blocked( null, $now ) );
	}

	public function test_staff_resend_sends_a_new_link_whatever_was_recorded(): void {
		$order                         = $this->created_order();
		$order->meta[ Meta::LINK ]     = 'links_off:' . self::VID;
		$this->options[ Links::OPTION_BLOCK ] = array(
			'reason' => Links::STATE_LINKS_OFF,
			'at'     => time(),
		);
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'linkId' => 'acl_NEW', 'expiresAt' => '2026-11-22T10:00:00Z', 'emailQueued' => true ) );

		$this->assertSame( Links::STATE_SENT, Links::resend( $order, $this->fulfilment_client() ) );

		$this->assertSame( array( 'GetIntegrationInfo', 'SendVerificationLink' ), $this->rpcs(), 'staff resends ignore the store-wide refusal' );
		$this->assertSame( 'acl_NEW', $order->meta[ Meta::LINK_ID ] );
		$this->assertStringContainsString( 'new verification link (acl_NEW, good until 2026-11-22) at a staff member\'s request', end( $order->notes ) );
		$this->assertArrayNotHasKey( Links::OPTION_BLOCK, $this->options );
		$this->assertArrayNotHasKey( Release::lock_name( $order->get_id() ), $this->options, 'the lock is released' );

		$notes = count( $order->notes );
		$this->respond( 200, array( 'linkId' => 'acl_NEWER', 'emailQueued' => true ) );
		$this->assertSame( Links::STATE_SENT, Links::resend( $order, $this->fulfilment_client() ) );
		$this->assertCount( $notes + 1, $order->notes, 'every staff send is noted' );
		$this->assertNotSame( $this->request_body( 1 )['idempotencyKey'], $this->request_body( 2 )['idempotencyKey'], 'a new key: a new email' );
		$this->assertStringContainsString( 'by=staff', $this->all_logs() );
	}

	public function test_staff_resend_respects_the_send_budget(): void {
		$order                     = $this->created_order();
		$order->meta[ Meta::LINK ] = 'sent:' . self::VID;
		$order->meta[ Meta::LINK_ID ] = 'acl_OLD';
		$this->respond( 200, $this->live_info() );
		$this->respond( 429, array( 'code' => 'resource_exhausted', 'message' => 'agecheck: too many verification links sent for this verification in the last 24 hours; retry later' ) );
		$this->assertSame( Links::LIMITED, Links::resend( $order, $this->fulfilment_client() ) );
		$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertSame( 'acl_OLD', $order->meta[ Meta::LINK_ID ], 'nothing sent, nothing changed' );
		$this->assertArrayNotHasKey( Meta::LINK_KEY, $order->meta );
	}

	public function test_staff_resend_refusals(): void {
		$order = $this->created_order();
		$token = Lock::acquire( Release::lock_name( $order->get_id() ), 120 );
		$this->assertSame( Links::LOCKED, Links::resend( $order, $this->fulfilment_client() ) );
		Lock::release( Release::lock_name( $order->get_id() ), $token );

		$passed                                   = $this->created_order();
		$passed->meta[ Meta::VERIFICATION_STATUS ] = 'held';
		$this->assertSame( Links::INELIGIBLE, Links::resend( $passed, $this->fulfilment_client() ) );
		$released = $this->created_order();
		Meta::mark_released( $released, Meta::STATUS_CLEARED );
		$this->assertSame( Links::INELIGIBLE, Links::resend( $released, $this->fulfilment_client() ) );
		$denied                        = $this->created_order();
		$denied->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$this->assertFalse( Links::can_resend( $denied ) );
		$processing = $this->created_order();
		$processing->set_status( 'processing' );
		$this->assertFalse( Links::can_resend( $processing ) );
		$this->assertSame( array(), $this->http_requests );

		$this->respond( 200, $this->live_info() );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		try {
			Links::resend( $order, $this->fulfilment_client() );
			$this->fail( 'an outage is rethrown' );
		} catch ( ConnectError $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
		$this->assertArrayHasKey( Meta::LINK_KEY, $order->meta, 'kept: the next try replays it' );
		$this->assertArrayNotHasKey( Release::lock_name( $order->get_id() ), $this->options, 'the lock is released on failure too' );
	}

	public function test_staff_withdraw(): void {
		$order                            = $this->created_order();
		$order->meta[ Meta::LINK ]        = 'sent:' . self::VID;
		$order->meta[ Meta::LINK_ID ]     = 'acl_LIVE';
		$order->meta[ Meta::LINK_EXPIRES_AT ] = time() + 86400;
		$this->respond( 200, array( 'revokedCount' => 1 ) );

		$this->assertSame( 1, Links::withdraw( $order, $this->fulfilment_client() ) );

		$this->assertSame( array( 'RevokeVerificationLinks' ), $this->rpcs() );
		$body = $this->request_body( 0 );
		$this->assertMatchesRegularExpression( '/^rvl:' . self::VID . ':[0-9a-f]{16}$/', $body['idempotencyKey'] );
		$this->assertSame( self::VID, $body['verificationId'] );
		$this->assertSame( 'withdrawn:' . self::VID, $order->meta[ Meta::LINK ] );
		$this->assertArrayNotHasKey( Meta::LINK_ID, $order->meta );
		$this->assertStringContainsString( 'withdrew the emailed verification link (1 was still open)', end( $order->notes ) );
		$this->assertSame( 'Withdrawn by staff: the emailed link no longer works.', Links::describe( $order ) );
		$this->assertSame( Links::STATE_WITHDRAWN, Links::send( $order, $this->fulfilment_client(), $this->info() ), 'a release run never sends a new one' );
		$this->assertTrue( Links::can_resend( $order ), 'staff still may' );
		$this->assertSame( Links::INELIGIBLE, Links::withdraw( $order, $this->fulfilment_client() ), 'nothing open any more' );
		$this->assertCount( 1, $this->http_requests );

		$again                     = $this->created_order( 'acv_AGAIN' );
		$again->meta[ Meta::LINK ] = 'not_queued:acv_AGAIN';
		$this->respond( 200, array() );
		$this->assertSame( 0, Links::withdraw( $again, $this->fulfilment_client() ), 'proto3 JSON omits 0' );

		$down                     = $this->created_order( 'acv_DOWN' );
		$down->meta[ Meta::LINK ] = 'sent:acv_DOWN';
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		try {
			Links::withdraw( $down, $this->fulfilment_client() );
			$this->fail( 'an outage is rethrown' );
		} catch ( ConnectError $e ) {
			$this->assertSame( 'sent:acv_DOWN', $down->meta[ Meta::LINK ], 'nothing recorded' );
		}
	}

	public function test_describe_every_state(): void {
		$order = $this->created_order();
		$this->assertSame( '', Links::describe( $order ) );
		$now                                  = 1800000000;
		$order->meta[ Meta::LINK ]            = 'sent:' . self::VID;
		$order->meta[ Meta::LINK_SENT_AT ]    = $now - 3600;
		$order->meta[ Meta::LINK_EXPIRES_AT ] = $now + 86400;
		$this->assertSame( 'Emailed to the shopper on 2027-01-15 07:00; the link works until 2027-01-16 08:00.', Links::describe( $order, $now ) );
		$this->assertStringContainsString( 'the link expired on 2027-01-16 08:00. Resend it', Links::describe( $order, $now + 86400 ) );
		$texts = array(
			Links::STATE_NOT_QUEUED      => 'Test mode',
			Links::STATE_LINKS_OFF       => 'AgeCheck is not sending verification emails yet',
			Links::STATE_NO_DISPLAY_NAME => 'set your store name in the AgeCheck dashboard',
			Links::STATE_UNAVAILABLE     => 'does not offer verification emails yet',
			Links::STATE_NO_EMAIL        => 'without a valid billing email',
			Links::STATE_NOT_ALLOWED     => 'cannot be emailed',
			Links::STATE_REFUSED         => 'AgeCheck refused',
			Links::STATE_NOT_ELIGIBLE    => 'started at checkout is never emailed',
		);
		foreach ( $texts as $state => $text ) {
			$order->meta[ Meta::LINK ] = $state . ':' . self::VID;
			$this->assertStringContainsString( $text, Links::describe( $order ), $state );
		}
		$order->meta[ Meta::LINK ] = 'sent:acv_AN_OLDER_ONE';
		$this->assertSame( '', Links::describe( $order ), 'a state recorded for an earlier verification' );
	}

	/**
	 * @dataProvider refusal_messages
	 */
	public function test_engine_refusal_mapping( string $code, string $message, string $want ): void {
		$this->assertSame( $want, EngineLinkSender::refusal( new ConnectError( 'SendVerificationLink', $code, 400, null, $message ) ) );
	}

	public static function refusal_messages(): array {
		return array(
			array( 'failed_precondition', 'agecheck: verification links are not enabled', Links::STATE_LINKS_OFF ),
			array( 'failed_precondition', "agecheck: set the store's display name in the AgeCheck settings before sending verification links", Links::STATE_NO_DISPLAY_NAME ),
			array( 'failed_precondition', 'agecheck: the verification has no email to send the link to (identity.email)', Links::STATE_NO_EMAIL ),
			array( 'failed_precondition', 'agecheck: a verification link needs a verification waiting on the shopper, with the document name check', Links::STATE_NOT_ALLOWED ),
			array( 'failed_precondition', 'agecheck: live mode is paused', Links::STATE_REFUSED ),
			array( 'failed_precondition', '', Links::STATE_REFUSED ),
			array( 'resource_exhausted', 'agecheck: too many verification links sent for this verification in the last 24 hours; retry later', Links::LIMITED ),
			array( 'resource_exhausted', 'rate limit', '' ),
			array( 'unimplemented', '', Links::STATE_UNAVAILABLE ),
			array( 'invalid_argument', 'return_url', Links::STATE_REFUSED ),
			array( 'not_found', '', Links::STATE_REFUSED ),
			array( 'unavailable', '', '' ),
			array( 'permission_denied', '', '' ),
			array( 'unauthenticated', '', '' ),
		);
	}

	public function test_a_local_refusal_is_never_an_answer(): void {
		$this->assertSame( '', EngineLinkSender::refusal( new ConnectError( 'SendVerificationLink', 'failed_precondition', 0, null, '', ConnectError::REASON_INSTALL_UNCONFIRMED ) ) );
	}

	public function test_the_merchant_notice_says_why(): void {
		$this->assertSame( '', Notices::links_text() );
		$now = 1800000000;
		foreach (
			array(
				Links::STATE_LINKS_OFF       => 'AgeCheck is not sending verification emails yet',
				Links::STATE_NO_DISPLAY_NAME => 'set your store name in the AgeCheck dashboard',
				Links::STATE_UNAVAILABLE     => 'does not offer verification emails yet',
			) as $reason => $text
		) {
			$this->options[ Links::OPTION_BLOCK ] = array(
				'reason' => $reason,
				'at'     => $now,
			);
			$this->assertStringContainsString( $text, Notices::links_text( $now + 3 * 86400 ), $reason );
			$this->assertSame( '', Notices::links_text( $now + Links::NOTICE_TTL + 1 ), 'a week after the last refusal it goes' );
			$this->assertStringNotContainsString( 'your account', Notices::links_text( $now ), 'AGECHECK_LINKS is one switch for every store' );
			$this->assertStringNotContainsString( 'Ask Navexa', Notices::links_text( $now ) );
		}

		$this->options[ Links::OPTION_BLOCK ]           = array(
			'reason' => Links::STATE_NO_DISPLAY_NAME,
			'at'     => $now,
		);
		$this->options[ \Navexa\AgeCheck\Options::STORE_PROFILE ] = array(
			'minimum_age'  => 21,
			'display_name' => '  ',
		);
		$this->assertStringContainsString( 'set your store name', Notices::links_text( $now ) );
		$this->options[ \Navexa\AgeCheck\Options::STORE_PROFILE ]['display_name'] = 'Mock Store';
		$this->assertSame( '', Notices::links_text( $now ), 'AgeCheck reports a name now: nothing left for the merchant to do' );
		$this->options[ Links::OPTION_BLOCK ]['reason'] = Links::STATE_LINKS_OFF;
		$this->assertStringContainsString( 'not sending verification emails yet', Notices::links_text( $now ), 'a name does not lift links off' );
	}

	public function test_a_refused_resend_keeps_the_link_already_emailed(): void {
		$this->respond( 200, $this->live_info() ); // Cached for every resend below.
		foreach (
			array(
				array( 400, 'failed_precondition', 'agecheck: live mode is paused until the AgeCheck invoice is paid; update the card under Usage & billing', Links::STATE_REFUSED ),
				array( 400, 'failed_precondition', "agecheck: set the store's display name in the AgeCheck settings before sending verification links", Links::STATE_NO_DISPLAY_NAME ),
				array( 400, 'failed_precondition', 'agecheck: verification links are not enabled', Links::STATE_LINKS_OFF ),
				array( 400, 'invalid_argument', 'return_url: must be an https URL', Links::STATE_REFUSED ),
			) as $case
		) {
			list( $status, $code, $message, $state ) = $case;
			$order                                = $this->created_order();
			$order->meta[ Meta::LINK ]            = 'sent:' . self::VID;
			$order->meta[ Meta::LINK_ID ]         = 'acl_LIVE';
			$order->meta[ Meta::LINK_EXPIRES_AT ] = time() + 86400;
			$this->respond( $status, array( 'code' => $code, 'message' => $message ) );

			$this->assertSame( $state, Links::resend( $order, $this->fulfilment_client() ), $state );

			$this->assertSame( 'sent:' . self::VID, $order->meta[ Meta::LINK ], $state . ': AgeCheck changed nothing, the earlier link still opens' );
			$this->assertSame( 'acl_LIVE', $order->meta[ Meta::LINK_ID ], $state );
			$this->assertTrue( Links::can_withdraw( $order ), $state . ': staff can still withdraw it' );
			$this->assertArrayNotHasKey( Meta::LINK_KEY, $order->meta, $state . ': an answer, the key goes' );
			$this->assertStringContainsString( 'sent no new verification link (' . $state . '). The link emailed earlier still works', end( $order->notes ), $state );
			$this->assertStringContainsString( 'Emailed to the shopper', Links::describe( $order ), $state );
		}
		$this->assertSame( Links::STATE_LINKS_OFF, Links::store_block()['reason'], 'a store-wide refusal is still recorded for the store' );
		$this->assertStringContainsString( 'state=links_off kept=live by=staff', $this->all_logs() );
	}

	/** GetIntegrationInfo for the fulfilment key. */
	private function info( array $overrides = array() ): IntegrationInfo {
		return IntegrationInfo::from_response( $this->live_info( $overrides ) );
	}

	/** A held order bound to a verification this plugin created, waiting on the shopper. */
	private function created_order( string $vid = self::VID ): \WC_Order {
		$order                                    = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::HELD ]                = 'yes';
		$order->meta[ Meta::REQUIRED ]            = 'yes';
		$order->meta[ Meta::VERIFICATION_ID ]     = $vid;
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$order->meta[ Meta::BOUND_BY ]            = 'create';
		$order->meta[ Meta::BOUND_AT ]            = time();
		Binding::external_id( $order );
		return $order;
	}
}
