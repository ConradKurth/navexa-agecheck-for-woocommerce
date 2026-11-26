<?php
/**
 * OrderVerify tests: "Verify now" on a held order's pages, the start step
 * (order key, nonce, account, rate limits, hosted session) and the return
 * step (tag, release run, where the shopper lands).
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Lock;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderVerify;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Tests\TestCase;

final class OrderVerifyTest extends TestCase {

	const VID    = 'acv_ORDERVERIFY';
	const HOSTED = 'https://verify.navexa.co/v/eyJ2IjoxfQ.c2lnbmF0dXJl';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->wire_order_hooks();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'nonce-' . $action === $nonce ? 1 : false;
			}
		);
	}

	public function test_register(): void {
		OrderVerify::register();
		$this->assertNotFalse( has_action( 'woocommerce_thankyou', array( OrderVerify::class, 'render_for' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_view_order', array( OrderVerify::class, 'render_for' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_api_navexa_agecheck_order', array( OrderVerify::class, 'handle' ) ) );
	}

	public function test_which_orders_wait_on_their_shopper(): void {
		$this->assertTrue( OrderVerify::needs_shopper( $this->held_order() ), 'held, not bound yet' );
		$this->assertTrue( OrderVerify::needs_shopper( $this->waiting_order() ), 'bound, waiting at AgeCheck\'s page' );
		foreach ( array( 'created', 'held', 'db_checking', 'passed', 'failed', '' ) as $status ) {
			$order                                    = $this->waiting_order();
			$order->meta[ Meta::VERIFICATION_STATUS ] = $status;
			$this->assertFalse( OrderVerify::needs_shopper( $order ), $status );
		}
		$review                       = $this->held_order();
		$review->meta[ Meta::REASON ] = 'no_billing_name';
		$this->assertFalse( OrderVerify::needs_shopper( $review ), 'waiting on staff' );
		$released = $this->waiting_order();
		Meta::mark_released( $released, Meta::STATUS_CLEARED );
		$this->assertFalse( OrderVerify::needs_shopper( $released ) );
		$denied                       = $this->waiting_order();
		$denied->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$this->assertFalse( OrderVerify::needs_shopper( $denied ) );
		$renewal                        = $this->held_order();
		$renewal->meta[ Meta::RENEWAL ] = 'yes';
		$this->assertFalse( OrderVerify::needs_shopper( $renewal ), 'renewals wait for staff' );
		$this->assertFalse( OrderVerify::needs_shopper( $this->restricted_order( 'processing' ) ) );
		$unrequired                        = $this->held_order();
		$unrequired->meta[ Meta::REQUIRED ] = 'no';
		$this->assertFalse( OrderVerify::needs_shopper( $unrequired ) );
	}

	public function test_the_box_posts_the_order_key_and_a_nonce_and_escapes(): void {
		$order                      = $this->waiting_order();
		$order->data['order_key']   = 'wc_order_"><b>';
		$order->meta[ Meta::LINK ]  = 'sent:' . self::VID;
		ob_start();
		OrderVerify::render_for( $order->get_id() );
		$html = ob_get_clean();
		$this->assertStringContainsString( '<form method="post" action="https://shop.example/?wc-api=navexa_agecheck_order">', $html );
		$this->assertStringContainsString( 'name="step" value="start"', $html );
		$this->assertStringContainsString( 'name="order" value="' . $order->get_id() . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-woocommerce-navexa-agecheck-order-' . $order->get_id() . '"', $html );
		$this->assertStringNotContainsString( '"><b>', $html );
		$this->assertStringContainsString( 'Verify now', $html );
		$this->assertStringContainsString( 'We have also emailed you a link', $html );
		$this->assertStringNotContainsString( self::VID, $html, 'never the verification id' );
		$this->assertStringNotContainsString( 'txk_', $html, 'never a key' );

		foreach ( array( $this->restricted_order( 'processing' ), 0, 'junk' ) as $nothing ) {
			ob_start();
			OrderVerify::render_for( $nothing instanceof \WC_Order ? $nothing->get_id() : $nothing );
			$this->assertSame( '', ob_get_clean() );
		}
		$this->home = 'https://copy.example';
		ob_start();
		OrderVerify::render_for( $order );
		$this->assertSame( '', ob_get_clean(), 'nothing while the install is paused' );
	}

	/** Renders the order page note for an order. */
	private function rendered( \WC_Order $order ): string {
		ob_start();
		OrderVerify::render_for( $order );
		return (string) ob_get_clean();
	}

	public function test_a_denied_order_says_it_will_not_ship(): void {
		$order                       = $this->waiting_order();
		$order->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$html                        = $this->rendered( $order );
		$this->assertStringContainsString( 'This order will not ship', $html );
		$this->assertStringContainsString( 'could not verify your age', $html );
		$this->assertStringContainsString( 'If you have paid', $html );
		$this->assertStringNotContainsString( 'Verify now', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( self::VID, $html );

		$order->meta[ Meta::REASON ] = 'not_us';
		$html                        = $this->rendered( $order );
		$this->assertStringContainsString( 'ship only to US addresses', $html );
		$this->assertStringNotContainsString( 'could not verify your age', $html );
	}

	public function test_a_denied_order_says_what_became_of_the_payment(): void {
		$order                       = $this->held_order();
		$order->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$order->data['status']       = 'refunded';
		$this->assertStringContainsString( 'has been refunded', $this->rendered( $order ) );

		$order->data['status']        = Hold::STATUS;
		$order->meta[ Meta::REFUND ] = Meta::REFUND_REFUNDED;
		$this->assertStringContainsString( 'has been refunded', $this->rendered( $order ) );

		unset( $order->meta[ Meta::REFUND ] );
		$order->data['status'] = 'cancelled';
		$html                  = $this->rendered( $order );
		$this->assertStringContainsString( 'has been cancelled', $html );
		$this->assertStringNotContainsString( 'If you have paid', $html );
	}

	public function test_a_held_order_with_a_passed_check_says_it_is_finishing(): void {
		$order                                    = $this->waiting_order();
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'passed';
		$html                                     = $this->rendered( $order );
		$this->assertStringContainsString( 'finishing your age check', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'Verify now', $html );
	}

	public function test_the_order_page_note_stays_quiet_when_it_should(): void {
		$denied                       = $this->waiting_order();
		$denied->meta[ Meta::STATUS ] = Meta::STATUS_DENIED;
		$released                     = $this->waiting_order();
		Meta::mark_released( $released, Meta::STATUS_CLEARED );
		$this->assertSame( '', $this->rendered( $released ) );
		$unrequired                          = $this->held_order();
		$unrequired->meta[ Meta::REQUIRED ] = 'no';
		$unrequired->meta[ Meta::STATUS ]   = Meta::STATUS_DENIED;
		$this->assertSame( '', $this->rendered( $unrequired ) );
		$this->home = 'https://copy.example';
		$this->assertSame( '', $this->rendered( $denied ), 'nothing while the install is paused' );
	}

	public function test_start_redirects_to_a_fresh_hosted_session_with_the_start_key(): void {
		$order = $this->waiting_order();
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );

		$answer = OrderVerify::respond( 'POST', $this->start_fields( $order ) );

		$this->assertSame( self::HOSTED, $answer['redirect'] );
		$this->assertSame( 303, $answer['status'] );
		$this->assertSame( array( 'GetIntegrationInfo', 'CreateHostedSession' ), $this->rpcs() );
		$this->assertSame( self::LIVE_START_KEY, $this->request_key( 1 ), 'a shopper\'s call uses the start key' );
		$body = $this->request_body( 1 );
		$this->assertSame( self::VID, $body['verificationId'] );
		$this->assertSame( OrderVerify::return_url( $order, true ), $body['returnUrl'] );
		$this->assertStringContainsString( '&n=nonce-woocommerce-navexa-agecheck-order-' . $order->get_id(), $body['returnUrl'] );
		$this->assertStringNotContainsString( 'wc_order_', $body['returnUrl'], 'never the order key' );
		$this->assertStringContainsString( 'agecheck order verify started order=' . $order->get_id() . ' vid=' . self::VID, $this->all_logs() );
	}

	public function test_start_leaves_the_return_url_out_unless_the_origin_is_allowed(): void {
		$order = $this->waiting_order();
		$this->respond( 200, $this->live_info( array( 'originAllowed' => false ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$this->assertSame( array( 'verificationId' => self::VID ), $this->request_body( 1 ) );
	}

	public function test_start_refuses_without_the_key_the_nonce_or_the_account(): void {
		$order = $this->waiting_order();
		$this->assertSame( 400, OrderVerify::respond( 'GET', $this->start_fields( $order ) )['status'], 'POST only' );
		$this->assertSame( 403, OrderVerify::respond( 'POST', array( 'key' => 'wc_order_other' ) + $this->start_fields( $order ) )['status'] );
		$this->assertSame( 403, OrderVerify::respond( 'POST', array( '_wpnonce' => 'stale' ) + $this->start_fields( $order ) )['status'] );
		$this->assertSame( 403, OrderVerify::respond( 'POST', array( 'order' => '999999' ) + $this->start_fields( $order ) )['status'] );
		$this->assertSame( 400, OrderVerify::respond( 'POST', array( 'step' => 'other' ) + $this->start_fields( $order ) )['status'] );

		$account                      = $this->waiting_order();
		$account->data['customer_id'] = 9;
		$this->current_user           = 3;
		$this->assertSame( 403, OrderVerify::respond( 'POST', $this->start_fields( $account ) )['status'], 'an account\'s order needs that customer signed in' );
		$this->assertSame( array(), $this->http_requests );

		$this->current_user = 9;
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( self::HOSTED, OrderVerify::respond( 'POST', $this->start_fields( $account ) )['redirect'] );
	}

	public function test_start_is_rate_limited_per_order(): void {
		$order = $this->restricted_order( 'processing' ); // Nothing to verify: no AgeCheck call.
		Binding::external_id( $order );
		for ( $i = 0; $i < OrderVerify::LIMITS['start_order']; $i++ ) {
			$this->assertArrayHasKey( 'redirect', OrderVerify::respond( 'POST', $this->start_fields( $order ) ) );
		}
		$answer = OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$this->assertSame( 429, $answer['status'] );
		$this->assertStringContainsString( 'Too many age check attempts', $answer['message'] );
	}

	public function test_an_order_with_no_verification_yet_gets_one_in_the_request(): void {
		$order = $this->held_order();
		Binding::external_id( $order );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'verification' => array( 'id' => self::VID, 'status' => 'VERIFICATION_STATUS_NEEDS_DOC', 'livemode' => true ) ) );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'agecheck: verification links are not enabled' ) );
		$this->respond( 200, array( 'decision' => 'CLEARANCE_DECISION_HOLD', 'reason' => 'pending', 'livemode' => true ) );
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );

		$answer = OrderVerify::respond( 'POST', $this->start_fields( $order ) );

		$this->assertSame( self::HOSTED, $answer['redirect'] );
		$this->assertSame( array( 'GetIntegrationInfo', 'CreateVerification', 'SendVerificationLink', 'GetClearance', 'GetIntegrationInfo', 'CreateHostedSession' ), $this->rpcs() );
		$this->assertSame( self::VID, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( Hold::STATUS, $order->get_status(), 'still held' );
		$this->assertStringContainsString( 'trigger=shopper outcome=pending', $this->all_logs() );
	}

	public function test_start_fails_closed(): void {
		$cannot = $this->held_order();
		$cannot->set_billing_first_name( '' );
		$cannot->set_billing_last_name( '' );
		$this->respond( 200, $this->live_info() );
		$answer = OrderVerify::respond( 'POST', $this->start_fields( $cannot ) );
		$this->assertSame( 200, $answer['status'] );
		$this->assertStringContainsString( 'could not start your age check online', $answer['message'] );

		$down = $this->held_order();
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertSame( 503, OrderVerify::respond( 'POST', $this->start_fields( $down ) )['status'] );

		$locked = $this->held_order();
		$token  = Lock::acquire( Release::lock_name( $locked->get_id() ), 120 );
		$this->assertSame( 503, OrderVerify::respond( 'POST', $this->start_fields( $locked ) )['status'] );
		Lock::release( Release::lock_name( $locked->get_id() ), $token );

		$order = $this->waiting_order();
		$this->respond( 200, $this->live_info() ); // The start key's info.
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'not waiting' ) );
		$this->respond( 200, array( 'verification' => $this->verification( self::VID, 'HELD' ) ) );
		$this->assertSame( $order->get_checkout_order_received_url(), OrderVerify::respond( 'POST', $this->start_fields( $order ) )['redirect'], 'no longer waiting: back to the order page' );
		$this->assertSame( 'GetVerification', $this->request_rpc( count( $this->http_requests ) - 1 ) );
		$this->assertSame( 'held', $order->meta[ Meta::VERIFICATION_STATUS ], 'recorded, so the page stops offering the button' );
		$this->assertFalse( OrderVerify::needs_shopper( $order ) );
		$this->assertSame( array( $order->get_id(), 0, Release::TRIGGER_SHOPPER ), $this->scheduled_for( Release::HOOK )[0]['args'], 'a release run asks GetClearance' );
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertSame( 503, OrderVerify::respond( 'POST', $this->start_fields( $order ) )['status'] );
		$this->respond( 200, array( 'hostedUrl' => 'http://evil.example/v/a.b' ) );
		$answer = OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$this->assertSame( 503, $answer['status'], 'an unusable link is no link' );
		$this->assertStringContainsString( 'temporarily unavailable', $answer['message'] );

		$this->home = 'https://copy.example';
		$this->assertSame( 503, OrderVerify::respond( 'POST', $this->start_fields( $order ) )['status'], 'paused' );
	}

	public function test_a_refusal_the_status_cannot_explain_fails_closed(): void {
		$order = $this->waiting_order();
		$this->respond( 200, $this->live_info() );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'not waiting' ) );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertSame( 503, OrderVerify::respond( 'POST', $this->start_fields( $order ) )['status'], 'the status could not be read' );
		$this->assertSame( 'needs_doc', $order->meta[ Meta::VERIFICATION_STATUS ], 'unchanged: the run records it later' );

		$token = Lock::acquire( Release::lock_name( $order->get_id() ), 120 );
		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'not waiting' ) );
		$this->assertSame( 503, OrderVerify::respond( 'POST', $this->start_fields( $order ) )['status'] );
		$this->assertSame( 'CreateHostedSession', $this->request_rpc( count( $this->http_requests ) - 1 ), 'a run holds the lock: it records the status itself' );
		Lock::release( Release::lock_name( $order->get_id() ), $token );
		$this->assertSame( array(), $this->scheduled_for( Release::HOOK ) );
	}

	public function test_an_access_refusal_is_not_read_as_a_check_that_moved_on(): void {
		$order = $this->waiting_order();
		$this->respond( 200, $this->live_info() );
		foreach (
			array(
				'agecheck: add a card under Usage & billing to use live mode',
				'agecheck: live mode is paused until the AgeCheck invoice is paid; update the card under Usage & billing',
				'agecheck: live mode is not available to this organization yet; use test mode',
			) as $refusal
		) {
			$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => $refusal ) );
			$this->respond( 200, array( 'verification' => $this->verification( self::VID, 'NEEDS_DOC' ) ) );
			$answer = OrderVerify::respond( 'POST', $this->start_fields( $order ) );
			$this->assertSame( 503, $answer['status'], $refusal );
			$this->assertStringContainsString( 'temporarily unavailable', $answer['message'] );
			$this->assertSame( 'GetVerification', $this->request_rpc( count( $this->http_requests ) - 1 ) );
		}
		$this->assertTrue( OrderVerify::needs_shopper( $order ), 'still waiting on the shopper' );
		$this->assertSame( array(), $this->scheduled_for( Release::HOOK ), 'no release run per click' );
		$this->assertStringContainsString( 'agecheck order verify refused order=' . $order->get_id() . ' vid=' . self::VID . ' code=failed_precondition', $this->all_logs() );
		$this->assertStringNotContainsString( 'Usage & billing', $this->all_logs(), 'never the engine\'s text' );
	}

	public function test_the_return_nonce_is_added_only_while_it_is_bound_to_the_visitor(): void {
		$order                          = $this->waiting_order();
		$this->session()->has_session   = false;
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$this->assertSame( 1, $this->session()->cookie_set, 'a guest without a session gets one' );
		$this->assertStringContainsString( '&n=', $this->request_body( 1 )['returnUrl'] );

		$this->session()->has_session = false;
		$this->session()->can_start   = false;
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$url = $this->request_body( 2 )['returnUrl'];
		$this->assertStringContainsString( '&t=', $url );
		$this->assertStringNotContainsString( '&n=', $url, 'no session: a nonce every session-less visitor shares is never sent' );
		$this->assertArrayNotHasKey( 'redirect', OrderVerify::respond( 'GET', $this->return_fields( $url ) ), 'and its return never opens the order page' );

		$this->logged_in = true;
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$this->assertStringContainsString( '&n=', $this->request_body( 3 )['returnUrl'], 'signed in: WordPress binds it to the login session' );
	}

	public function test_start_is_rate_limited_per_client_ip_across_orders(): void {
		for ( $i = 0; $i < OrderVerify::LIMITS['start_ip']; $i++ ) {
			$order = $this->restricted_order( 'processing' ); // Nothing to verify: no AgeCheck call.
			Binding::external_id( $order );
			$this->assertArrayHasKey( 'redirect', OrderVerify::respond( 'POST', $this->start_fields( $order ) ), (string) $i );
		}
		$order = $this->restricted_order( 'processing' );
		Binding::external_id( $order );
		$this->assertSame( 429, OrderVerify::respond( 'POST', $this->start_fields( $order ) )['status'], 'a fresh order, the same IP' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertArrayHasKey( 'redirect', OrderVerify::respond( 'POST', $this->start_fields( $order ) ), 'another IP' );
	}

	public function test_start_shares_the_store_wide_cap_with_the_checkout(): void {
		\Brain\Monkey\Filters\expectApplied( 'navexa_agecheck_rate_limits' )->andReturn( array( 'start_store' => 1 ) );
		$order = $this->restricted_order( 'processing' ); // Nothing to verify: no AgeCheck call.
		Binding::external_id( $order );
		$this->assertArrayHasKey( 'redirect', OrderVerify::respond( 'POST', $this->start_fields( $order ) ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$order                  = $this->restricted_order( 'processing' );
		Binding::external_id( $order );
		$answer = OrderVerify::respond( 'POST', $this->start_fields( $order ) );
		$this->assertSame( 429, $answer['status'], 'another order and IP, but the store\'s hour is spent' );
		$this->assertStringContainsString( 'Too many age check attempts', $answer['message'] );
		$this->assertStringContainsString( 'agecheck start store limit reached limit=1', $this->all_logs() );
	}

	public function test_the_box_says_a_link_was_emailed_only_when_one_was(): void {
		foreach ( array( 'links_off', 'no_email', 'refused', 'not_eligible', 'withdrawn', '' ) as $state ) {
			$order = $this->waiting_order();
			if ( '' !== $state ) {
				$order->meta[ Meta::LINK ] = $state . ':' . self::VID;
			}
			ob_start();
			OrderVerify::render_for( $order );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'Verify now', $html, $state );
			$this->assertStringNotContainsString( 'emailed you', $html, $state );
		}
		$stale                     = $this->waiting_order();
		$stale->meta[ Meta::LINK ] = 'sent:acv_EARLIER';
		ob_start();
		OrderVerify::render_for( $stale );
		$this->assertStringNotContainsString( 'emailed you', ob_get_clean(), 'a link for an earlier verification' );
	}

	public function test_return_queues_a_release_run_and_sends_the_shopper_to_their_order(): void {
		$order  = $this->waiting_order();
		$fields = $this->return_fields( OrderVerify::return_url( $order, true ) );
		$this->respond( 200, array( 'verification' => $this->verification( self::VID, 'PASSED' ) ) );

		$answer = OrderVerify::respond( 'GET', $fields );

		$this->assertSame( $order->get_checkout_order_received_url(), $answer['redirect'], 'this browser started it' );
		$queued = $this->scheduled_for( Release::HOOK );
		$this->assertCount( 1, $queued );
		$this->assertSame( array( $order->get_id(), 0, Release::TRIGGER_SHOPPER ), $queued[0]['args'] );
		$this->assertSame( array( 'GetVerification' ), $this->rpcs(), 'the status is read for the order page; the run asks GetClearance' );
		$this->assertSame( self::LIVE_START_KEY, $this->request_key( 0 ), 'with the start key' );
		$this->assertSame( 'passed', $order->meta[ Meta::VERIFICATION_STATUS ] );
		$this->assertFalse( OrderVerify::needs_shopper( $order ), 'the order page no longer offers Verify now' );
		$this->assertArrayNotHasKey( Meta::RELEASED_FOR, $order->meta, 'nothing released: GetClearance decides' );
		$this->assertSame( Hold::STATUS, $order->get_status() );

		$emailed = OrderVerify::respond( 'GET', $this->return_fields( OrderVerify::return_url( $order ) ) );
		$this->assertArrayNotHasKey( 'redirect', $emailed, 'an emailed link\'s return never reveals the order page' );
		$this->assertStringContainsString( 'ships your order once AgeCheck confirms', $emailed['message'] );
		$this->assertCount( 1, $this->http_requests, 'and asks AgeCheck nothing' );

		$account                      = $this->waiting_order();
		$account->data['customer_id'] = 9;
		$this->current_user           = 9;
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertArrayHasKey( 'redirect', OrderVerify::respond( 'GET', $this->return_fields( OrderVerify::return_url( $account ) ) ), 'the signed-in customer goes to their order, AgeCheck down or not' );
		$this->assertSame( 'needs_doc', $account->meta[ Meta::VERIFICATION_STATUS ] );
		$this->current_user = 0;
		$this->assertArrayNotHasKey( 'redirect', OrderVerify::respond( 'GET', $this->return_fields( OrderVerify::return_url( $account, true ) ) ), 'anyone else does not' );
		$this->assertCount( 2, $this->http_requests );
	}

	public function test_return_with_a_bad_tag_does_nothing_and_says_nothing(): void {
		$order  = $this->waiting_order();
		$fields = $this->return_fields( OrderVerify::return_url( $order, true ) );
		$good   = OrderVerify::respond( 'GET', array( 'order' => '999999' ) + $fields );
		$this->assertSame( 200, $good['status'] );
		foreach ( array( array( 't' => str_repeat( '0', 32 ) ), array( 't' => '' ) ) as $bad ) {
			$answer = OrderVerify::respond( 'GET', $bad + $fields );
			$this->assertSame( $good, $answer );
		}
		$this->assertSame( array(), $this->scheduled_for( Release::HOOK ) );
		$this->assertSame( 400, OrderVerify::respond( 'POST', $fields )['status'], 'GET only' );

		$other = $this->waiting_order();
		$this->assertNotSame( OrderVerify::return_url( $order ), OrderVerify::return_url( $other ) );
		$this->assertSame( '', OrderVerify::return_url( \WC_Order::make( Hold::STATUS ) ), 'no external id, no return' );
	}

	public function test_return_runs_are_rate_limited_and_only_for_held_orders(): void {
		$order  = $this->waiting_order();
		$fields = $this->return_fields( OrderVerify::return_url( $order ) );
		$queued = 0;
		for ( $i = 0; $i < OrderVerify::LIMITS['return_order'] + 2; $i++ ) {
			OrderVerify::respond( 'GET', $fields );
			$queued         += count( $this->scheduled_for( Release::HOOK ) );
			$this->scheduled = array(); // Each run done before the next return.
		}
		$this->assertSame( OrderVerify::LIMITS['return_order'], $queued );

		$released = $this->waiting_order();
		$released->set_status( 'processing' );
		OrderVerify::respond( 'GET', $this->return_fields( OrderVerify::return_url( $released ) ) );
		$this->assertSame( array(), $this->scheduled_for( Release::HOOK ), 'a released order needs no run' );
	}

	public function test_page_html_escapes(): void {
		$html = OrderVerify::html( '<t>', '<m>' );
		$this->assertStringNotContainsString( '<t>', $html );
		$this->assertStringNotContainsString( '<m>', $html );
		$this->assertStringContainsString( 'noindex', $html );
		$this->assertStringContainsString( 'href="https://shop.example/"', $html );
	}

	/** The fields of a return URL. */
	private function return_fields( string $url ): array {
		$this->assertNotSame( '', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $fields ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$this->assertSame( OrderVerify::API_ACTION, $fields['wc-api'] );
		return $fields;
	}

	/** What the box posts for $order. */
	private function start_fields( \WC_Order $order ): array {
		return array(
			'step'     => 'start',
			'order'    => (string) $order->get_id(),
			'key'      => $order->get_order_key(),
			'_wpnonce' => 'nonce-' . OrderVerify::NONCE_PREFIX . $order->get_id(),
		);
	}

	/** A held order, not bound yet. */
	private function held_order(): \WC_Order {
		$order                                   = $this->restricted_order( Hold::STATUS );
		$order->meta[ Meta::REQUIRED ]           = 'yes';
		$order->meta[ Meta::HELD ]               = 'yes';
		$order->meta[ Meta::INTERCEPTED_STATUS ] = 'processing';
		$order->meta[ Meta::STATUS ]             = Meta::STATUS_PENDING;
		return $order;
	}

	/** A held order whose own check waits at AgeCheck's page. */
	private function waiting_order(): \WC_Order {
		$order                                    = $this->held_order();
		$order->meta[ Meta::VERIFICATION_ID ]     = self::VID;
		$order->meta[ Meta::VERIFICATION_STATUS ] = 'needs_doc';
		$order->meta[ Meta::BOUND_BY ]            = 'create';
		$order->meta[ Meta::BOUND_AT ]            = time();
		Binding::external_id( $order );
		return $order;
	}
}
