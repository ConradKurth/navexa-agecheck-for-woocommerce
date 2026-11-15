<?php
/**
 * Start tests: the merchant side of the loader contract (plan §2.7).
 *
 * CreateVerification's optional identity, intended_ship_to, return_url and
 * document_name_check are engine PR E4a, not in production yet; these pin the
 * plugin to the documented contract.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Navexa\AgeCheck\Checkout\Session;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\OrderAddress;
use Navexa\AgeCheck\RateLimit;
use Navexa\AgeCheck\ShipTo;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Tests\TestCase;

final class StartTest extends TestCase {

	const HOSTED = 'https://verify.navexa.co/v/eyJ2IjoxfQ.c2lnbmF0dXJl';

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		Mode::info( Client::KEY_START );
		$this->http_requests = array();
		$this->customer_at( $this->us_address() );
	}

	private function start(): array {
		return Start::answer(
			array(
				'v'      => 1,
				'action' => 'start',
			)
		);
	}

	private function refresh(): array {
		return Start::answer(
			array(
				'v'      => 1,
				'action' => 'refresh',
			)
		);
	}

	private function mac(): string {
		return ShipTo::from_wc_address( $this->us_address() )->mac();
	}

	private function respond_created( string $id = 'acv_1', string $status = 'NEEDS_DOC', array $extra = array() ): void {
		$this->respond( 200, array( 'verification' => $this->verification( $id, $status, $extra ) ) );
	}

	public function test_start_creates_one_verification_and_answers_a_hosted_link(): void {
		$this->respond_created( 'acv_1', 'NEEDS_DOC', array( 'hostedUrl' => 'https://verify.navexa.co/v/old.link' ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );

		$answer = $this->start();

		$this->assertSame(
			array(
				'v'          => 1,
				'status'     => 'pending',
				'hosted_url' => self::HOSTED,
			),
			$answer
		);
		$this->assertSame( 'CreateVerification', $this->request_rpc( 0 ) );
		$this->assertSame( self::LIVE_START_KEY, $this->request_key( 0 ), 'only the start key' );
		$create = $this->request_body( 0 );
		$sid    = Session::sid();
		$this->assertSame( Install::install_id() . ':' . $sid . ':' . $this->mac() . ':0', $create['idempotencyKey'] );
		$this->assertSame(
			array(
				'line1'      => '123 Main St',
				'line2'      => 'Apt 4',
				'city'       => 'Springfield',
				'region'     => 'IL',
				'postalCode' => '62701',
				'country'    => 'US',
			),
			$create['intendedShipTo']
		);
		$this->assertSame( 'DOCUMENT_NAME_CHECK_NONE', $create['documentNameCheck'] );
		$this->assertSame( 'https://shop.example/?wc-api=navexa_agecheck_return&popup=1', $create['returnUrl'] );
		$this->assertSame(
			array(
				'name'               => 'Pat Doe',
				'residentialAddress' => $create['intendedShipTo'],
			),
			$create['identity'],
			'the data match pair: billing name and the billing address in the intended ship-to\'s form; never a DOB, no email'
		);
		$this->assertArrayNotHasKey( 'orderExternalId', $create );

		$this->assertSame( 'CreateHostedSession', $this->request_rpc( 1 ) );
		$this->assertSame(
			array(
				'verificationId' => 'acv_1',
				'returnUrl'      => 'https://shop.example/?wc-api=navexa_agecheck_return&popup=1',
			),
			$this->request_body( 1 )
		);
		$this->assertSame(
			array(
				'vid'        => 'acv_1',
				'shipto_mac' => $this->mac(),
				'n'          => 0,
				'status'     => 'pending',
			),
			Session::record()
		);
		$this->assertStringNotContainsString( 'acv_1', (string) wp_json_encode( $answer ), 'the browser never sees the verification id' );
		$this->assertStringNotContainsString( 'eyJ2', $this->all_logs(), 'the hosted URL is never logged' );
		$this->assertStringNotContainsString( 'Main St', $this->all_logs() );
	}

	public function test_start_reuses_the_sessions_verification_for_the_same_ship_to(): void {
		$this->respond_created();
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();

		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'DOC_PENDING' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( 'pending', $this->start()['status'] );
		$this->assertSame( array( 'CreateVerification', 'CreateHostedSession', 'GetVerification', 'CreateHostedSession' ), array_map( array( $this, 'request_rpc' ), array_keys( $this->http_requests ) ) );
	}

	public function test_a_new_ship_to_failure_or_binding_creates_a_new_verification_with_the_next_n(): void {
		$this->respond_created();
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();

		// New ship-to: a new verification, n = 1.
		$this->customer_at( $this->us_address( array( 'address_1' => '9 Elm St' ) ) );
		$this->respond_created( 'acv_2' );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();
		$this->assertStringEndsWith( ':1', $this->request_body( 2 )['idempotencyKey'] );
		$this->assertSame( '9 Elm St', $this->request_body( 2 )['intendedShipTo']['line1'] );

		// Failed: a new one, n = 2.
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_2', 'FAILED' ) ) );
		$this->respond_created( 'acv_3' );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();
		$this->assertSame( 'GetVerification', $this->request_rpc( 4 ) );
		$this->assertStringEndsWith( ':2', $this->request_body( 5 )['idempotencyKey'] );

		// Bound to an order since: a new one, n = 3.
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_3', 'PASSED', array( 'orderExternalId' => 'wc:x:1:5' ) ) ) );
		$this->respond_created( 'acv_4' );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();
		$this->assertStringEndsWith( ':3', $this->request_body( 8 )['idempotencyKey'] );
		$this->assertSame( 'acv_4', Session::record()['vid'] );
	}

	public function test_a_replayed_create_that_an_order_took_moves_to_the_next_n(): void {
		$this->respond_created( 'acv_1', 'PASSED', array( 'orderExternalId' => 'wc:x:1:5' ) );
		$this->respond_created( 'acv_2' );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( 'pending', $this->start()['status'] );
		$this->assertStringEndsWith( ':0', $this->request_body( 0 )['idempotencyKey'] );
		$this->assertStringEndsWith( ':1', $this->request_body( 1 )['idempotencyKey'] );
		$this->assertSame( 'acv_2', Session::record()['vid'] );
	}

	public function test_a_created_verification_is_resumed_by_replaying_its_key(): void {
		$this->respond_created( 'acv_1', 'CREATED' );
		$answer = $this->start();
		$this->assertSame( 'pending', $answer['status'], 'no hosted step yet' );
		$this->assertArrayNotHasKey( 'hosted_url', $answer );
		$this->assertStringContainsString( 'being processed', $answer['message'] );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'CREATED' ) ) );
		$this->respond_created( 'acv_1', 'NEEDS_DOC' );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( self::HOSTED, $this->start()['hosted_url'] );
		$this->assertSame( $this->request_body( 0 )['idempotencyKey'], $this->request_body( 2 )['idempotencyKey'] );
	}

	public function test_hosted_session_failure_falls_back_to_the_create_link_or_fails_closed(): void {
		$this->respond_created( 'acv_1', 'NEEDS_DOC', array( 'hostedUrl' => self::HOSTED ) );
		$this->respond( 400, array( 'code' => 'invalid_argument' ) );
		$this->assertSame( self::HOSTED, $this->start()['hosted_url'] );

		Session::forget_verification();
		$this->respond_created( 'acv_2' );
		$this->respond( 200, array( 'hostedUrl' => 'http://evil.example/v/a.b' ) );
		$this->assertSame( 'unavailable', $this->start()['status'], 'an unusable link is no link' );
	}

	public function test_a_pass_at_start_mints_the_guard(): void {
		$this->respond_created( 'acv_1', 'PASSED' );
		$this->assertSame(
			array(
				'v'      => 1,
				'status' => 'passed',
			),
			$this->start()
		);
		$this->assertSame( 'acv_1', Session::guarded_verification_id( $this->mac() ) );
		$this->assertSame( 'acv_1', $this->session()->get( Session::KEY_VID ), 'what order binding reads' );
		$this->assertSame( 'passed', Start::state()['status'] );
	}

	public function test_refresh_reads_the_verification_and_mints_the_guard_on_pass(): void {
		$this->respond_created();
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();

		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'DOC_PENDING' ) ) );
		$this->assertSame(
			array(
				'v'      => 1,
				'status' => 'pending',
			),
			$this->refresh(),
			'refresh never mints a link'
		);
		$this->assertNull( Session::guarded_verification_id( $this->mac() ) );

		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED' ) ) );
		$this->assertSame( 'passed', $this->refresh()['status'] );
		$this->assertSame( 'acv_1', Session::guarded_verification_id( $this->mac() ) );
		$this->assertSame( 'passed', Session::record()['status'] );
		$this->assertSame( 'GetVerification', $this->request_rpc( 3 ) );
		$this->assertSame( self::LIVE_START_KEY, $this->request_key( 3 ) );
	}

	public function test_refresh_refuses_passes_that_cannot_clear_or_are_in_the_wrong_mode(): void {
		Session::sid( true );
		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );

		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED', array( 'passClass' => 'PASS_CLASS_DB_UNATTESTED' ) ) ) );
		$this->assertSame( 'failed', $this->refresh()['status'] );
		$this->assertNull( Session::guarded_verification_id( $this->mac() ) );

		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED', array( 'livemode' => false ) ) ) );
		$this->assertSame( 'unavailable', $this->refresh()['status'], 'a test pass never counts under a live key' );
		$this->assertNull( Session::guarded_verification_id( $this->mac() ) );

		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'FAILED' ) ) );
		$answer = $this->refresh();
		$this->assertSame( 'failed', $answer['status'] );
		$this->assertSame( 'We could not verify your age, so this order cannot be placed.', $answer['message'] );
		$this->assertSame( 'failed', Start::state()['status'] );
	}

	public function test_refresh_without_a_verification_or_after_a_ship_to_change(): void {
		$this->assertSame( 'none', $this->refresh()['status'] );
		$this->assertSame( array(), $this->http_requests );

		Session::sid( true );
		Session::set_record( 'acv_1', str_repeat( 'b', 64 ), 0, Session::STATUS_PENDING );
		$answer = $this->refresh();
		$this->assertSame( 'none', $answer['status'] );
		$this->assertStringContainsString( 'shipping address changed', $answer['message'] );

		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );
		$this->respond( 404, array( 'code' => 'not_found' ) );
		$this->assertSame( 'none', $this->refresh()['status'] );
		$this->assertNull( Session::record() );
	}

	public function test_refresh_of_a_verification_an_order_took_mints_no_guard(): void {
		Session::sid( true );
		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'PASSED', array( 'orderExternalId' => 'wc:abc:1:55' ) ) ) );
		$answer = $this->refresh();
		$this->assertSame( 'none', $answer['status'] );
		$this->assertNull( Session::record(), 'forgotten' );
		$this->assertNull( Session::guarded_verification_id( $this->mac() ) );
		$this->assertNull( $this->session()->get( Session::KEY_VID ) );
		$this->assertSame( 'none', Start::state()['status'] );
	}

	public function test_errors_answer_unavailable(): void {
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertSame( 'unavailable', $this->start()['status'] );

		$this->respond( 400, array( 'code' => 'failed_precondition', 'message' => 'add a card' ) );
		$answer = $this->start();
		$this->assertSame( 'unavailable', $answer['status'] );
		$this->assertStringNotContainsString( 'card', $answer['message'], 'the engine message is not shown' );

		Session::sid( true );
		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );
		$this->respond_transport_error( 'cURL error 28: Operation timed out' );
		$this->assertSame( 'unavailable', $this->refresh()['status'] );
	}

	public function test_the_engines_us_only_refusal_is_shown_as_such(): void {
		$this->customer_at( $this->us_address( array( 'state' => '', 'postcode' => '96910', 'country' => 'GU' ) ) );
		$this->respond( 400, array( 'code' => 'invalid_argument', 'message' => 'AgeCheck verifies US addresses only' ) );
		$answer = $this->start();
		$this->assertSame( 'refused', $answer['status'] );
		$this->assertStringContainsString( 'only to US addresses', $answer['message'] );
		$this->assertSame( 'GU', $this->request_body( 0 )['intendedShipTo']['region'] );

		$this->respond( 400, array( 'code' => 'invalid_argument', 'message' => 'identity.email: bad' ) );
		$this->assertSame( 'unavailable', $this->start()['status'] );
	}

	public function test_preconditions_refuse_without_calling_agecheck(): void {
		$cases = array(
			'bad version' => function () {
				return Start::answer(
					array(
						'v'      => 2,
						'action' => 'start',
					)
				);
			},
			'bad action'  => function () {
				return Start::answer(
					array(
						'v'      => 1,
						'action' => 'pay',
					)
				);
			},
		);
		foreach ( $cases as $name => $call ) {
			$this->assertSame( 'refused', $call()['status'], $name );
		}

		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		$this->assertSame( 'Nothing in your cart needs an age check.', $this->start()['message'] );
		unset( $this->options[ Options::MODE ] );

		self::$wc->cart->empty = true;
		$this->assertSame( 'refused', $this->start()['status'] );
		self::$wc->cart->empty = false;

		$this->chosen_methods = array( 'local_pickup' );
		$this->assertSame( 'Pickup orders are age-checked in store.', $this->start()['message'] );
		$this->chosen_methods = array( 'flat_rate' );

		$this->customer_at( $this->us_address( array( 'country' => 'CA', 'state' => 'ON', 'postcode' => 'M5V 2T6' ) ) );
		$answer = $this->start();
		$this->assertSame( 'refused', $answer['status'] );
		$this->assertStringContainsString( 'only to US addresses', $answer['message'] );
		$this->assertArrayNotHasKey( '_required', $answer );

		$this->customer_at( $this->us_address( array( 'address_1' => '' ) ) );
		$this->assertSame( 'Enter your full shipping address, then verify your age.', $this->start()['message'] );

		$this->customer_at( $this->us_address( array( 'address_1' => '' ) ), $this->us_address( array( 'country' => 'MX' ) ) );
		self::$wc->cart->needs_shipping = false;
		$this->assertStringContainsString( 'only to US addresses', $this->start()['message'], 'no shipping needed: the billing address is the ship-to' );

		$this->assertSame( array(), $this->http_requests );
	}

	public function test_paused_or_keyless_installs_answer_unavailable(): void {
		$this->home = 'https://copy.example';
		$this->assertSame( 'unavailable', $this->start()['status'] );
		$this->home = 'https://shop.example';
		unset( $this->options[ Options::START_KEY ] );
		$this->assertSame( 'unavailable', $this->start()['status'] );
		$this->assertSame( array(), $this->http_requests );
		$this->assertStringContainsString( 'reason=no_start_key', $this->all_logs() );
	}

	public function test_starts_are_rate_limited_per_session_and_per_ip(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->respond( 503, array( 'code' => 'unavailable' ) );
			$this->start();
		}
		$answer = $this->start();
		$this->assertSame( 'unavailable', $answer['status'] );
		$this->assertStringContainsString( 'Too many age check attempts', $answer['message'] );
		$this->assertCount( 10, $this->http_requests, 'the 11th start in an hour never reached AgeCheck' );

		// A fresh sid in the same WooCommerce session is the same session.
		$this->session()->data = array();
		$this->assertStringContainsString( 'Too many', $this->start()['message'] );

		// Another session from the same IP: 20 per IP per hour.
		$this->session()->customer_id = 't_another';
		for ( $i = 0; $i < 10; $i++ ) {
			$this->respond( 503, array( 'code' => 'unavailable' ) );
			$this->start();
		}
		$this->session()->customer_id = 't_third';
		$this->assertStringContainsString( 'Too many', $this->start()['message'] );
		$this->assertStringContainsString( 'agecheck start rate limited', $this->all_logs() );
	}

	public function test_limits_are_filterable_and_the_table_failing_refuses(): void {
		Filters\expectApplied( 'navexa_agecheck_rate_limits' )->andReturn( array( 'start_session' => 1 ) );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->start();
		$this->assertStringContainsString( 'Too many', $this->start()['message'] );

		$this->wpdb->fail             = true;
		$this->session()->customer_id = 't_another';
		$answer                       = $this->start();
		$this->assertSame( 'unavailable', $answer['status'] );
		$this->assertStringContainsString( 'temporarily unavailable', $answer['message'], 'a broken counter is not "too many attempts"' );
		$this->assertStringNotContainsString( 'Too many', $answer['message'] );
	}

	public function test_each_start_is_recorded_for_the_proxy_warning(): void {
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->start();
		$this->assertCount( 1, $this->options[ RateLimit::OPTION_RECENT_START_IPS ] );
		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->start();
		$this->assertCount( 2, $this->options[ RateLimit::OPTION_RECENT_START_IPS ] );

		Session::sid( true );
		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'DOC_PENDING' ) ) );
		$this->refresh();
		$this->assertCount( 2, $this->options[ RateLimit::OPTION_RECENT_START_IPS ], 'refreshes are not starts' );
	}

	/**
	 * Starts a check for a session whose billing address is $billing (the
	 * ship-to is $ship_to, us_address() by default) and answers the
	 * CreateVerification body.
	 *
	 * @param array<string, string>      $billing Billing address and name.
	 * @param array<string, string>|null $ship_to Shipping address.
	 * @return array<string, mixed>
	 */
	private function create_body_for_billing( array $billing, ?array $ship_to = null ): array {
		static $n = 0;
		Session::forget_verification();
		$this->session()->customer_id = 't_billing_' . $n; // A fresh rate-limit subject per start.
		$this->customer_at( null === $ship_to ? $this->us_address() : $ship_to, $billing );
		$this->http_requests = array();
		$this->respond_created( 'acv_' . ( ++$n ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( 'pending', $this->start()['status'] );
		$this->assertSame( 'CreateVerification', $this->request_rpc( 0 ) );
		return $this->request_body( 0 );
	}

	public function test_identity_is_the_billing_name_and_us_address_or_nothing(): void {
		$none = array(
			'no first name'          => array( 'first_name' => '' ),
			'no last name'           => array( 'last_name' => '  ' ),
			'Unicode-space names'    => array(
				'first_name' => "\u{00A0}",
				'last_name'  => "\u{3000}\u{2003}",
			),
			'incomplete address'     => array( 'address_1' => '' ),
			'no billing country'     => array( 'country' => '' ),
			'non-US billing address' => array(
				'country'  => 'CA',
				'state'    => 'ON',
				'postcode' => 'M5V 2T6',
			),
			'freely associated'      => array( 'state' => 'PW' ),
			'overlong name'          => array( 'last_name' => str_repeat( 'x', 256 ) ),
			'invalid UTF-8'          => array( 'first_name' => "\xC3\x28" ),
		);
		foreach ( $none as $case => $overrides ) {
			$body = $this->create_body_for_billing( $this->us_address( $overrides ) );
			$this->assertArrayNotHasKey( 'identity', $body, $case . ': no identity, the ID + selfie path' );
			$this->assertSame( 'DOCUMENT_NAME_CHECK_NONE', $body['documentNameCheck'], $case );
			$this->assertSame( '123 Main St', $body['intendedShipTo']['line1'], $case . ': the ship-to as before' );
		}

		$body = $this->create_body_for_billing( $this->us_address( array( 'email' => 'not-an-email' ) ) );
		$this->assertSame( array( 'name', 'residentialAddress' ), array_keys( $body['identity'] ), 'the email is never sent' );

		$body = $this->create_body_for_billing(
			$this->us_address(
				array(
					'first_name' => " Ana\u{00A0}\u{00A0}María\u{3000}",
					'last_name'  => 'de la Cruz',
					'address_1'  => " 123   MAIN st\t",
					'address_2'  => ' APT   4 ',
					'postcode'   => '62701 1234',
				)
			)
		);
		$this->assertSame( 'Ana María de la Cruz', $body['identity']['name'], 'first and last name, whitespace (Unicode too) collapsed as the engine trims it' );
		$this->assertSame(
			array(
				'line1'      => '123 MAIN st',
				'line2'      => 'APT 4',
				'city'       => 'Springfield',
				'region'     => 'IL',
				'postalCode' => '62701',
				'country'    => 'US',
			),
			$body['identity']['residentialAddress'],
			'normalized like the intended ship-to: whitespace collapsed, ZIP+4 cut to the ZIP'
		);
		$this->assertTrue( OrderAddress::same_residence( $body['identity']['residentialAddress'], $body['intendedShipTo'] ) );

		$territory = $this->us_address(
			array(
				'country'  => 'PR',
				'state'    => '',
				'postcode' => '00901',
			)
		);
		$body      = $this->create_body_for_billing( $territory, $territory );
		$this->assertSame( 'PR', $body['identity']['residentialAddress']['region'], 'a territory is a US address' );
	}

	public function test_identity_only_when_the_order_ships_to_the_billing_address(): void {
		foreach (
			array(
				'another street' => array( 'address_1' => '9 Elm St' ),
				'another line 2' => array( 'address_2' => 'Apt 5' ),
				'no line 2'      => array( 'address_2' => '' ),
				'another ZIP'    => array( 'postcode' => '62702' ),
			) as $case => $overrides
		) {
			$body = $this->create_body_for_billing( $this->us_address( $overrides ) );
			$this->assertArrayNotHasKey( 'identity', $body, $case . ': never data-eligible, so no name or billing address is sent' );
			$this->assertSame( '123 Main St', $body['intendedShipTo']['line1'], $case . ': the ship-to is the shipping address' );
			$this->assertSame( 'DOCUMENT_NAME_CHECK_NONE', $body['documentNameCheck'], $case );
			$this->assertSame( $this->mac(), Session::record()['shipto_mac'], $case . ': the check is bound to the ship-to, not the billing address' );
		}

		// The engine's comparison, never stricter: city and region are not
		// compared, case and whitespace are folded, and a postal code that
		// starts with five digits is read as that ZIP.
		foreach (
			array(
				'another city and state' => array(
					'city'  => 'Chicago',
					'state' => 'CA',
				),
				'case and spacing'       => array(
					'address_1' => '123  MAIN ST',
					'address_2' => 'apt 4',
				),
				'other ZIP+4'            => array( 'postcode' => '62701-9999' ),
				'malformed ZIP+4'        => array( 'postcode' => '62701-12' ),
			) as $case => $overrides
		) {
			$body = $this->create_body_for_billing( $this->us_address( $overrides ) );
			$this->assertArrayHasKey( 'identity', $body, $case . ': one residence by the engine\'s rule' );
		}
	}

	public function test_endpoint_data_carries_the_answer_only_in_the_same_request(): void {
		$this->respond_created();
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		Start::handle(
			array(
				'v'      => 1,
				'action' => 'start',
			)
		);
		$this->assertSame(
			array(
				'v'          => 1,
				'required'   => true,
				'status'     => 'pending',
				'hosted_url' => self::HOSTED,
			),
			Start::endpoint_data()
		);

		Start::reset();
		$this->assertSame(
			array(
				'v'        => 1,
				'required' => true,
				'status'   => 'pending',
			),
			Start::endpoint_data(),
			'later cart responses carry no link'
		);
		Start::handle( 'junk' );
		$this->assertSame( 'refused', Start::endpoint_data()['status'] );
	}

	public function test_state_follows_the_session_and_the_ship_to(): void {
		$this->assertSame(
			array(
				'v'        => 1,
				'required' => true,
				'status'   => 'none',
			),
			Start::state()
		);

		Session::sid( true );
		Session::set_record( 'acv_1', $this->mac(), 0, Session::STATUS_PENDING );
		$this->assertSame( 'pending', Start::state()['status'] );

		$this->customer_at( $this->us_address( array( 'address_1' => '9 Elm St' ) ) );
		$state = Start::state();
		$this->assertSame( 'none', $state['status'] );
		$this->assertStringContainsString( 'shipping address changed', $state['message'] );

		$this->customer_at( $this->us_address( array( 'country' => 'DE' ) ) );
		$state = Start::state();
		$this->assertTrue( $state['required'] );
		$this->assertStringContainsString( 'only to US addresses', $state['message'] );

		$this->chosen_methods = array( 'local_pickup' );
		$this->assertSame(
			array(
				'v'        => 1,
				'required' => false,
				'status'   => 'none',
			),
			Start::state(),
			'pickup needs no check'
		);

		self::$wc->customer = null;
		$this->assertFalse( Start::state()['required'] );
	}

	public function test_store_api_registration(): void {
		$registered = array();
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->alias(
			static function ( $args ) use ( &$registered ) {
				$registered['data'] = $args;
			}
		);
		Functions\when( 'woocommerce_store_api_register_update_callback' )->alias(
			static function ( $args ) use ( &$registered ) {
				$registered['update'] = $args;
			}
		);
		Start::register_store_api();
		$this->assertSame( 'cart', $registered['data']['endpoint'] );
		$this->assertSame( 'navexa-agecheck', $registered['data']['namespace'] );
		$this->assertSame( array( Start::class, 'endpoint_data' ), $registered['data']['data_callback'] );
		$this->assertSame( array( Start::class, 'handle' ), $registered['update']['callback'] );
		$this->assertSame( array( 'v', 'required', 'status', 'hosted_url', 'message' ), array_keys( Start::endpoint_schema() ) );
	}

	public function test_is_hosted_url(): void {
		$this->assertTrue( Start::is_hosted_url( self::HOSTED ) );
		$this->assertTrue( Start::is_hosted_url( 'http://localhost:8787/v/a.b' ) );
		$this->assertFalse( Start::is_hosted_url( 'http://shop.example/v/a.b' ) );
		$this->assertFalse( Start::is_hosted_url( 'https://verify.navexa.co/v/a' ) );
		$this->assertFalse( Start::is_hosted_url( 'https://verify.navexa.co/v/a.b/c' ) );
		$this->assertFalse( Start::is_hosted_url( 'javascript:alert(1)' ) );
		$this->assertFalse( Start::is_hosted_url( '' ) );
	}
}
