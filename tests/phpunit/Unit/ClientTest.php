<?php
/**
 * Client tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Tests\TestCase;

final class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_posts_connect_unary_json_with_bearer_key(): void {
		$this->respond( 200, array( 'verification' => array( 'id' => 'acv_1' ) ) );

		$out = $this->fulfilment_client()->call( 'GetVerification', array( 'id' => 'acv_1' ) );

		$this->assertSame( array( 'verification' => array( 'id' => 'acv_1' ) ), $out );
		$this->assertCount( 1, $this->http_requests );
		$request = $this->http_requests[0];
		$this->assertSame( 'https://verify.navexa.co/agecheck.v1.VerificationService/GetVerification', $request['url'] );
		$this->assertSame( 8, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'], 'a redirect would resend the Bearer key' );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertSame( '1', $request['args']['headers']['Connect-Protocol-Version'] );
		$this->assertSame( '8000', $request['args']['headers']['Connect-Timeout-Ms'] );
		$this->assertSame( 'Bearer ' . self::LIVE_FULFILMENT_KEY, $request['args']['headers']['Authorization'] );
		$this->assertSame( '{"id":"acv_1"}', $request['args']['body'] );
	}

	public function test_each_key_kind_sends_its_own_key(): void {
		$this->respond( 200, array() );
		Client::for_key( Client::KEY_START )->call( 'GetVerification', array( 'id' => 'acv_1' ) );
		$this->assertSame( 'Bearer ' . self::LIVE_START_KEY, $this->http_requests[0]['args']['headers']['Authorization'] );
	}

	public function test_empty_request_is_sent_as_a_json_object(): void {
		$this->respond( 200, '' );
		$out = $this->fulfilment_client()->call( 'GetIntegrationInfo' );
		$this->assertSame( '{}', $this->http_requests[0]['args']['body'] );
		$this->assertSame( array(), $out, 'an empty 200 body is an empty message' );
	}

	public function test_connect_error_body_sets_code_and_message_but_logs_neither_message_nor_key(): void {
		$this->respond(
			400,
			array(
				'code'    => 'invalid_argument',
				'message' => 'AgeCheck verifies US addresses only',
			)
		);

		$error = $this->call_expecting_error( 'CreateVerification', array( 'identity' => array( 'email' => 'shopper@example.com' ) ) );

		$this->assertSame( 'invalid_argument', $error->connect_code() );
		$this->assertSame( 400, $error->http_status() );
		$this->assertSame( 'AgeCheck verifies US addresses only', $error->server_message() );
		$this->assertFalse( $error->is_retryable() );
		$this->assertStringNotContainsString( 'US addresses', $error->getMessage() );

		$logs = $this->all_logs();
		$this->assertStringContainsString( 'rpc=CreateVerification', $logs );
		$this->assertStringContainsString( 'code=invalid_argument', $logs );
		$this->assertStringContainsString( 'key=fulfilment', $logs );
		$this->assertStringNotContainsString( 'US addresses', $logs );
		$this->assertStringNotContainsString( 'shopper@example.com', $logs );
		$this->assertStringNotContainsString( self::LIVE_FULFILMENT_KEY, $logs );
		$this->assertSame( 'navexa-agecheck', $this->logger->lines[0]['context']['source'] );
	}

	/**
	 * @dataProvider status_without_connect_body
	 */
	public function test_http_status_without_a_connect_body_maps_like_connect( int $status, string $code, bool $retryable ): void {
		$this->respond( $status, '<html>bad gateway</html>' );
		$error = $this->call_expecting_error( 'GetVerification', array( 'id' => 'acv_1' ) );
		$this->assertSame( $code, $error->connect_code() );
		$this->assertSame( $retryable, $error->is_retryable() );
	}

	public function status_without_connect_body(): array {
		return array(
			'400'                          => array( 400, 'internal', false ),
			'401'                          => array( 401, 'unauthenticated', false ),
			'403'                          => array( 403, 'permission_denied', false ),
			'404 (RPC not deployed yet)'   => array( 404, 'unimplemented', false ),
			'429'                          => array( 429, 'unavailable', true ),
			'502'                          => array( 502, 'unavailable', true ),
			'503'                          => array( 503, 'unavailable', true ),
			'504'                          => array( 504, 'unavailable', true ),
			'500 from the edge or ingress' => array( 500, 'unavailable', true ),
			'Cloudflare 520'               => array( 520, 'unavailable', true ),
			'Cloudflare 522'               => array( 522, 'unavailable', true ),
			'Cloudflare 524'               => array( 524, 'unavailable', true ),
			'Cloudflare 526'               => array( 526, 'unavailable', true ),
			'501'                          => array( 501, 'unknown', false ),
			'527'                          => array( 527, 'unknown', false ),
			'418'                          => array( 418, 'unknown', false ),
		);
	}

	public function test_engine_internal_error_on_500_stays_internal_and_final(): void {
		$this->respond( 500, array( 'code' => 'internal' ) );
		$error = $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) );
		$this->assertSame( 'internal', $error->connect_code(), 'a Connect body wins over the HTTP status' );
		$this->assertFalse( $error->is_retryable() );
	}

	public function test_unknown_code_in_body_falls_back_to_http_status(): void {
		$this->respond( 503, array( 'code' => 'not_a_code' ) );
		$this->assertSame( 'unavailable', $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) )->connect_code() );
	}

	public function test_retry_after_seconds_are_reported_and_not_slept_on(): void {
		$this->respond( 429, array( 'code' => 'resource_exhausted' ), array( 'Retry-After' => '30' ) );
		$started = microtime( true );
		$error   = $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) );
		$this->assertLessThan( 1.0, microtime( true ) - $started );
		$this->assertSame( 'resource_exhausted', $error->connect_code() );
		$this->assertSame( 30, $error->retry_after() );
		$this->assertTrue( $error->is_retryable() );
		$this->assertCount( 1, $this->http_requests, 'one attempt only' );
	}

	public function test_parse_retry_after(): void {
		$this->assertNull( Client::parse_retry_after( '' ) );
		$this->assertNull( Client::parse_retry_after( null ) );
		$this->assertNull( Client::parse_retry_after( 'soon' ) );
		$this->assertSame( 5, Client::parse_retry_after( array( '5', '9' ) ) );
		$this->assertSame( Client::MAX_RETRY_AFTER, Client::parse_retry_after( '999999999' ) );
		$this->assertSame( 0, Client::parse_retry_after( gmdate( 'D, d M Y H:i:s', time() - 60 ) . ' GMT' ) );
		$in_two_minutes = Client::parse_retry_after( gmdate( 'D, d M Y H:i:s', time() + 120 ) . ' GMT' );
		$this->assertGreaterThanOrEqual( 118, $in_two_minutes );
		$this->assertLessThanOrEqual( 120, $in_two_minutes );
	}

	public function test_timeout_is_deadline_exceeded(): void {
		$this->respond_transport_error( 'cURL error 28: Operation timed out after 8001 milliseconds with 0 bytes received' );
		$error = $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) );
		$this->assertSame( 'deadline_exceeded', $error->connect_code() );
		$this->assertSame( 0, $error->http_status() );
		$this->assertTrue( $error->is_retryable() );
	}

	public function test_other_transport_errors_are_unavailable(): void {
		$this->respond_transport_error( 'cURL error 6: Could not resolve host: verify.navexa.co' );
		$this->assertSame( 'unavailable', $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) )->connect_code() );
	}

	public function test_unreadable_success_body_is_internal(): void {
		$this->respond( 200, 'not json' );
		$error = $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) );
		$this->assertSame( 'internal', $error->connect_code() );
		$this->assertSame( ConnectError::REASON_UNREADABLE_RESPONSE, $error->local_reason() );
	}

	public function test_paused_install_refuses_without_a_network_call(): void {
		$this->home = 'https://staging.shop.example';
		$error      = $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) );
		$this->assertSame( 'failed_precondition', $error->connect_code() );
		$this->assertSame( ConnectError::REASON_INSTALL_UNCONFIRMED, $error->local_reason() );
		$this->assertFalse( $error->is_retryable(), 'a paused site needs an administrator, not a retry' );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_missing_key_refuses_without_a_network_call(): void {
		unset( $this->options[ Options::FULFILMENT_KEY ] );
		$error = $this->call_expecting_error( 'GetVerification', array( 'id' => 'a' ) );
		$this->assertSame( 'unauthenticated', $error->connect_code() );
		$this->assertSame( ConnectError::REASON_NO_API_KEY, $error->local_reason() );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_uninitialized_install_refuses(): void {
		unset( $this->options[ Install::OPTION_INSTALL_ID ] );
		$this->assertSame( ConnectError::REASON_INSTALL_UNCONFIRMED, $this->call_expecting_error( 'GetVerification', array() )->local_reason() );
	}

	public function test_invalid_rpc_name_is_refused(): void {
		$error = $this->call_expecting_error( '../admin', array() );
		$this->assertSame( ConnectError::REASON_INVALID_RPC, $error->local_reason() );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_unencodable_request_is_refused_before_sending(): void {
		$error = $this->call_expecting_error( 'GetVerification', array( 'id' => "\xB1\x31" ) );
		$this->assertSame( ConnectError::REASON_UNENCODABLE_REQUEST, $error->local_reason() );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_explicit_base_url_is_used_without_trailing_slash(): void {
		$this->respond( 200, array() );
		( new Client( self::LIVE_FULFILMENT_KEY, Client::KEY_FULFILMENT, 'http://127.0.0.1:8787/' ) )->call( 'GetIntegrationInfo' );
		$this->assertSame( 'http://127.0.0.1:8787/agecheck.v1.VerificationService/GetIntegrationInfo', $this->http_requests[0]['url'] );
	}

	public function test_default_base_url(): void {
		$this->assertSame( 'https://verify.navexa.co', Client::base_url() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_base_url_constant_overrides_the_default(): void {
		define( 'NAVEXA_AGECHECK_API_URL', 'http://host.docker.internal:8787/' );
		$this->assertSame( 'http://host.docker.internal:8787', Client::base_url() );
	}

	public function test_key_fingerprint_does_not_contain_the_key(): void {
		$fingerprint = $this->fulfilment_client()->key_fingerprint();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $fingerprint );
		$this->assertStringNotContainsString( substr( self::LIVE_FULFILMENT_KEY, 4, 16 ), $fingerprint );
		$this->assertNotSame( $fingerprint, Client::for_key( Client::KEY_START )->key_fingerprint() );
	}

	private function call_expecting_error( string $rpc, array $request ): ConnectError {
		try {
			$this->fulfilment_client()->call( $rpc, $request );
		} catch ( ConnectError $e ) {
			return $e;
		}
		$this->fail( 'expected a ConnectError' );
	}
}
