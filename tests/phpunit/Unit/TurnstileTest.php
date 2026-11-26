<?php
/**
 * Cloudflare Turnstile on the start endpoint (W4): off by default, fail
 * closed when on.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Turnstile;
use Navexa\AgeCheck\Tests\TestCase;

final class TurnstileTest extends TestCase {

	const SITE_KEY = '0x4AAAAAAABkMYinukE8nzYS';
	const SECRET   = '0x4AAAAAAAsecretsecretsecretsecretAA';
	const TOKEN    = 'XXXX.DUMMY.TOKEN.XXXX';
	const HOSTED   = 'https://verify.navexa.co/v/eyJ2IjoxfQ.c2lnbmF0dXJl';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		Mode::info( Client::KEY_START );
		$this->http_requests = array();
		$this->customer_at( $this->us_address() );
		$this->cache_availability();
	}

	private function turn_on( bool $with_keys = true ): void {
		$this->options[ Options::TURNSTILE ] = 'yes';
		if ( $with_keys ) {
			$this->options[ Options::TURNSTILE_SITE_KEY ] = self::SITE_KEY;
			$this->options[ Options::TURNSTILE_SECRET ]   = self::SECRET;
		}
	}

	private function start( $token = null, string $action = 'start' ): array {
		$data = array(
			'v'      => 1,
			'action' => $action,
		);
		if ( null !== $token ) {
			$data['turnstile'] = $token;
		}
		return Start::answer( $data );
	}

	private function respond_created(): void {
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'NEEDS_DOC' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
	}

	public function test_off_by_default_makes_no_cloudflare_call(): void {
		$this->assertFalse( Turnstile::enabled() );
		$this->assertSame( Turnstile::OK, Turnstile::verify( null ) );
		$this->assertNull( Turnstile::script_data() );
		$this->respond_created();
		$this->assertSame( 'pending', $this->start()['status'] );
		$this->assertSame( array( 'CreateVerification', 'CreateHostedSession' ), $this->rpcs() );
	}

	public function test_a_valid_token_lets_the_start_through(): void {
		$this->turn_on();
		$this->respond( 200, array( 'success' => true, 'action' => Turnstile::ACTION, 'hostname' => 'shop.example' ) );
		$this->respond_created();

		$answer = $this->start( self::TOKEN );

		$this->assertSame( 'pending', $answer['status'] );
		$this->assertSame( Turnstile::VERIFY_URL, $this->http_requests[0]['url'] );
		$body = $this->http_requests[0]['args']['body'];
		$this->assertSame( self::SECRET, $body['secret'] );
		$this->assertSame( self::TOKEN, $body['response'] );
		$this->assertSame( '203.0.113.7', $body['remoteip'] );
		$this->assertSame( 0, $this->http_requests[0]['args']['redirection'] );
		$this->assertSame( 'CreateVerification', $this->request_rpc( 1 ) );
		$this->assertStringNotContainsString( self::TOKEN, $this->all_logs() );
		$this->assertStringNotContainsString( self::SECRET, $this->all_logs() );
	}

	public function test_a_refused_token_refuses_without_any_agecheck_call(): void {
		$this->turn_on();
		$this->respond( 200, array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );

		$answer = $this->start( self::TOKEN );

		$this->assertSame( 'refused', $answer['status'] );
		$this->assertSame( Start::turnstile_message(), $answer['message'] );
		$this->assertCount( 1, $this->http_requests, 'no CreateVerification' );
		$this->assertStringContainsString( 'codes=invalid-input-response', $this->all_logs() );
	}

	public function test_no_token_refuses_without_asking_cloudflare(): void {
		$this->turn_on();
		foreach ( array( null, '', 42, str_repeat( 'a', Turnstile::MAX_TOKEN + 1 ) ) as $token ) {
			$answer = $this->start( $token );
			$this->assertSame( 'refused', $answer['status'] );
		}
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_an_unexpected_action_is_refused(): void {
		$this->turn_on();
		$this->respond( 200, array( 'success' => true, 'action' => 'login' ) );
		$this->assertSame( 'refused', $this->start( self::TOKEN )['status'] );
		$this->assertStringContainsString( 'action_mismatch', $this->all_logs() );
	}

	public function test_cloudflare_errors_fail_closed_as_unavailable(): void {
		$this->turn_on();
		$this->respond_transport_error( 'timeout' );
		$this->assertSame( 'unavailable', $this->start( self::TOKEN )['status'] );
		$this->respond( 500, 'oops' );
		$this->assertSame( 'unavailable', $this->start( self::TOKEN )['status'] );
		$this->respond( 200, 'not json' );
		$this->assertSame( 'unavailable', $this->start( self::TOKEN )['status'] );
		$this->assertSame( array( Turnstile::VERIFY_URL, Turnstile::VERIFY_URL, Turnstile::VERIFY_URL ), array_column( $this->http_requests, 'url' ) );
	}

	public function test_turned_on_without_keys_fails_closed(): void {
		$this->turn_on( false );
		$this->assertFalse( Turnstile::configured() );
		$this->assertSame( 'unavailable', $this->start( self::TOKEN )['status'] );
		$this->options[ Options::TURNSTILE_SITE_KEY ] = self::SITE_KEY;
		$this->assertSame( 'unavailable', $this->start( self::TOKEN )['status'], 'a site key alone is not enough' );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_refresh_needs_no_token(): void {
		$this->turn_on();
		$answer = $this->start( null, 'refresh' );
		$this->assertSame( 'none', $answer['status'] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_starts_turnstile_refuses_do_not_spend_the_store_wide_cap(): void {
		$this->turn_on();
		\Brain\Monkey\Filters\expectApplied( 'navexa_agecheck_rate_limits' )->andReturn( array( 'start_store' => 1 ) );
		$this->respond( 200, array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );
		$this->assertSame( 'refused', $this->start( self::TOKEN )['status'] );
		$this->respond( 200, array( 'success' => true, 'action' => Turnstile::ACTION, 'hostname' => 'shop.example' ) );
		$this->respond_created();
		$this->assertSame( 'pending', $this->start( self::TOKEN )['status'], 'the refused start was not counted' );
		$this->respond( 200, array( 'success' => true, 'action' => Turnstile::ACTION, 'hostname' => 'shop.example' ) );
		$this->session()->customer_id = 't_another';
		$answer                       = $this->start( self::TOKEN );
		$this->assertSame( Start::CODE_RATE_LIMITED, $answer['code'], 'the store\'s one start is spent' );
	}

	public function test_the_rate_limit_comes_first(): void {
		$this->turn_on();
		$this->wpdb->fail = true;
		$this->assertSame( 'unavailable', $this->start( self::TOKEN )['status'] );
		$this->assertSame( array(), $this->http_requests, 'no Cloudflare call when the counters are unreadable' );
	}

	public function test_script_data_only_when_on(): void {
		$this->turn_on();
		$this->assertSame(
			array(
				'siteKey' => self::SITE_KEY,
				'action'  => Turnstile::ACTION,
			),
			Turnstile::script_data()
		);
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( Turnstile::script_data() ) );
	}
}
