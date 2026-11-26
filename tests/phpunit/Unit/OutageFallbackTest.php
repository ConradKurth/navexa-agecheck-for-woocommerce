<?php
/**
 * Outage fallback tests (TAX-1841, owner decision #21): the store's "take and
 * hold" choice while AgeCheck cannot run the ID check, the availability read
 * that tells the store's own daily limit from AgeCheck being down, and the
 * rules that keep it from ever opening a retry after a failed check.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Filters;
use Navexa\AgeCheck\Admin\Notices;
use Navexa\AgeCheck\Admin\OrderPanel;
use Navexa\AgeCheck\Availability;
use Navexa\AgeCheck\Binding;
use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Checkout\Session;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\ConnectError;
use Navexa\AgeCheck\DisabledLinkSender;
use Navexa\AgeCheck\GuardToken;
use Navexa\AgeCheck\Links;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\ShipTo;
use Navexa\AgeCheck\Start;
use Navexa\AgeCheck\Tests\TestCase;

final class OutageFallbackTest extends TestCase {

	const HOSTED = 'https://verify.navexa.co/v/eyJ2IjoxfQ.c2lnbmF0dXJl';

	/** @var ShipTo */
	private $ship_to;

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
		$this->respond( 200, $this->live_info( array( 'scopes' => array( 'agecheck:verify' ) ) ) );
		Mode::info( Client::KEY_START );
		$this->respond( 200, $this->live_info() );
		Mode::info( Client::KEY_FULFILMENT );
		$this->http_requests = array();
		$this->customer_at( $this->us_address() );
		$this->ship_to = ShipTo::from_wc_address( $this->us_address() );
		$this->hold_on_outage();
	}

	/** The store takes and holds orders while AgeCheck is down, and links can reach its shoppers. */
	private function hold_on_outage(): void {
		$this->options[ Options::OUTAGE_FALLBACK ] = Options::OUTAGE_HOLD;
		$this->options[ Options::STORE_PROFILE ]   = array(
			'minimum_age'  => 21,
			'display_name' => 'Pat\'s Shop',
		);
	}

	private function availability_transient(): string {
		return Availability::TRANSIENT_PREFIX . Client::for_key( Client::KEY_START )->key_fingerprint();
	}

	private function start(): array {
		return Start::answer(
			array(
				'v'      => 1,
				'action' => 'start',
			)
		);
	}


	private function availability_body( string $value, array $extra = array() ): array {
		return array_merge(
			array(
				'idCheck'  => 'ID_CHECK_AVAILABILITY_' . $value,
				'livemode' => true,
			),
			$extra
		);
	}

	public function test_the_setting_reads_refuse_unless_chosen_before_payment_with_links_reachable(): void {
		$this->assertSame( Options::OUTAGE_HOLD, Options::outage_fallback() );
		$this->assertTrue( Options::holds_on_outage() );

		$this->options[ Options::OUTAGE_FALLBACK ] = 'sometimes';
		$this->assertSame( Options::OUTAGE_REFUSE, Options::outage_fallback(), 'an unknown value reads refuse' );
		unset( $this->options[ Options::OUTAGE_FALLBACK ] );
		$this->assertSame( Options::OUTAGE_REFUSE, Options::outage_fallback(), 'refuse by default' );

		$this->hold_on_outage();
		$this->options[ Options::MODE ] = Options::MODE_ACCEPT_AND_HOLD;
		$this->assertSame( Options::OUTAGE_REFUSE, Options::outage_fallback(), 'before payment only' );
		unset( $this->options[ Options::MODE ] );

		foreach ( Links::BLOCKING_STATES as $reason ) {
			$this->options[ Links::OPTION_BLOCK ] = array(
				'reason' => $reason,
				'at'     => time() - 30 * DAY_IN_SECONDS,
			);
			$this->assertSame( Options::OUTAGE_REFUSE, Options::outage_fallback(), $reason . ': no link could reach the shopper' );
			$this->assertSame( Options::OUTAGE_HOLD, Options::stored_outage_fallback() );
		}
		unset( $this->options[ Links::OPTION_BLOCK ] );

		$this->options[ Options::STORE_PROFILE ] = array( 'minimum_age' => 21 );
		$this->assertSame( Options::OUTAGE_REFUSE, Options::outage_fallback(), 'no display name: AgeCheck sends no link' );

		$this->hold_on_outage();
		Filters\expectApplied( 'navexa_agecheck_link_sender' )->andReturn( new DisabledLinkSender() );
		$this->assertSame( Options::OUTAGE_REFUSE, Options::outage_fallback(), 'emails turned off in wp-config.php' );
	}

	public function test_only_unavailable_and_deadline_exceeded_are_an_outage(): void {
		$this->assertTrue( ( new ConnectError( 'CreateVerification', 'unavailable' ) )->is_outage() );
		$this->assertTrue( ( new ConnectError( 'CreateVerification', 'deadline_exceeded' ) )->is_outage() );
		foreach ( array( 'resource_exhausted', 'failed_precondition', 'aborted', 'internal', 'unimplemented', 'permission_denied' ) as $code ) {
			$this->assertFalse( ( new ConnectError( 'CreateVerification', $code ) )->is_outage(), $code );
		}
		$local = new ConnectError( 'CreateVerification', 'unavailable', 0, null, '', ConnectError::REASON_NO_API_KEY );
		$this->assertFalse( $local->is_outage(), 'a local refusal is no outage' );
		$this->assertTrue( ( new ConnectError( 'CreateVerification', 'resource_exhausted' ) )->is_retryable(), 'is_retryable() unchanged' );
	}

	public function test_availability_caches_answers_briefly_and_outages_more_briefly(): void {
		$this->respond( 200, $this->availability_body( 'AVAILABLE' ) );
		$this->assertSame( Availability::AVAILABLE, Availability::read() );
		$this->assertSame( Availability::AVAILABLE, Availability::read(), 'cached' );
		$this->assertSame( array( 'GetCheckAvailability' ), $this->rpcs() );
		$this->assertSame( self::LIVE_START_KEY, $this->request_key( 0 ), 'the start key' );
		$this->assertSame( Availability::SUCCESS_TTL, $this->transients[ $this->availability_transient() ]['ttl'] );

		unset( $this->transients[ $this->availability_transient() ] );
		$this->respond( 503, '' );
		$this->assertSame( Availability::OUTAGE, Availability::read() );
		$this->assertSame( Availability::OUTAGE_TTL, $this->transients[ $this->availability_transient() ]['ttl'], 'an outage is cached for seconds only' );

		unset( $this->transients[ $this->availability_transient() ] );
		$this->respond_transport_error( 'cURL error 28: Operation timed out' );
		$this->assertSame( Availability::OUTAGE, Availability::read() );

		foreach ( array(
			'failed_precondition' => Availability::REFUSED,
			'permission_denied'   => Availability::REFUSED,
			'resource_exhausted'  => Availability::UNKNOWN,
			'internal'            => Availability::UNKNOWN,
		) as $code => $want ) {
			unset( $this->transients[ $this->availability_transient() ] );
			$this->respond( 400, array( 'code' => $code ) );
			$this->assertSame( $want, Availability::read(), $code );
			$this->assertArrayNotHasKey( $this->availability_transient(), $this->transients, $code . ' is not cached' );
		}

		// An engine without the RPC: connect-go's plain 404 reads unimplemented.
		$this->respond( 404, '404 page not found' );
		$this->assertSame( Availability::UNKNOWN, Availability::read() );
		$this->assertArrayNotHasKey( $this->availability_transient(), $this->transients );

		$this->respond( 200, array( 'idCheck' => 'ID_CHECK_AVAILABILITY_SOMETHING_NEW' ) );
		$this->assertSame( Availability::SERVICE_UNAVAILABLE, Availability::read(), 'a value this plugin does not know reads unavailable' );
		unset( $this->transients[ $this->availability_transient() ] );
		$this->respond( 200, array() );
		$this->assertSame( Availability::SERVICE_UNAVAILABLE, Availability::read(), 'no value reads unavailable' );
	}

	public function test_a_daily_limit_records_when_it_resets(): void {
		$resets = gmdate( 'Y-m-d\T00:00:00\Z', time() + DAY_IN_SECONDS );
		$this->respond( 200, $this->availability_body( 'ORG_DAILY_LIMIT', array( 'resumesAt' => $resets ) ) );
		$this->assertSame( Availability::ORG_DAILY_LIMIT, Availability::read() );
		$this->assertSame( strtotime( $resets ), $this->options[ Options::CAPPED_UNTIL ] );
		$this->assertSame( strtotime( $resets ), Options::capped_until() );
		$this->assertStringContainsString( 'used its AgeCheck ID checks for today', Notices::capped_text() );
		$this->assertSame( '', Notices::capped_text( strtotime( $resets ) + 1 ), 'gone once it reset' );
		$this->assertStringNotContainsString( 'resumesAt', $this->all_logs() );
	}

	public function test_an_outage_at_start_takes_the_fallback_only_when_chosen(): void {
		$this->respond_transport_error( 'cURL error 7: Failed to connect' );
		$answer = $this->start();
		$this->assertSame( 'hold', $answer['status'] );
		$this->assertSame( Start::hold_message(), $answer['message'] );
		$this->assertTrue( Session::fallback_for( $this->ship_to->mac() ) );
		$this->assertSame( 'hold', Start::state()['status'], 'the cart data says so while it lasts' );

		Session::forget_fallback();
		$this->options[ Options::OUTAGE_FALLBACK ] = Options::OUTAGE_REFUSE;
		$this->respond( 503, '' );
		$this->assertSame( 'unavailable', $this->start()['status'], 'refuse: as before' );
		$this->assertFalse( Session::fallback_for( $this->ship_to->mac() ) );

		$this->hold_on_outage();
		foreach ( array( 'resource_exhausted', 'failed_precondition', 'internal' ) as $code ) {
			$this->respond( 400, array( 'code' => $code ) );
			$this->assertSame( 'unavailable', $this->start()['status'], $code . ' is an answer, not an outage' );
			$this->assertFalse( Session::fallback_for( $this->ship_to->mac() ), $code );
		}
	}

	public function test_a_failed_check_never_falls_back(): void {
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'FAILED' ) ) );
		$this->assertSame( 'failed', $this->start()['status'] );

		// The next start creates a new verification; AgeCheck is down now.
		$this->respond( 503, '' );
		$this->assertSame( 'unavailable', $this->start()['status'] );
		$this->assertFalse( Session::fallback_for( $this->ship_to->mac() ) );
		$this->assertFalse( Session::set_fallback( $this->ship_to->mac() ), 'never set after a failure' );

		// Nor honoured: a fallback set before the failure.
		$this->session()->set(
			Session::KEY_FALLBACK,
			array(
				'shipto_mac' => $this->ship_to->mac(),
				'until'      => time() + 600,
			)
		);
		$this->assertFalse( Session::fallback_for( $this->ship_to->mac() ) );
		$this->assertSame( Gate::REFUSE_FAILED, Gate::check( $this->ship_to, 'US' )['code'] );
		$this->assertFalse( Gate::admitted_fallback() );
		$this->assertNotSame( 'hold', Start::state()['status'] );
	}

	public function test_needs_doc_refuses_on_the_stores_limit_and_falls_back_when_down(): void {
		// The store's own daily limit: refused with a generic line, whatever
		// the setting.
		foreach ( array( Options::OUTAGE_HOLD, Options::OUTAGE_REFUSE ) as $setting ) {
			$this->options[ Options::OUTAGE_FALLBACK ] = $setting;
			unset( $this->transients[ $this->availability_transient() ] );
			$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'NEEDS_DOC' ) ) );
			$this->respond( 200, $this->availability_body( 'ORG_DAILY_LIMIT' ) );
			$answer = $this->start();
			$this->assertSame( 'refused', $answer['status'], $setting );
			$this->assertSame( Start::capped_message(), $answer['message'] );
			$this->assertStringNotContainsString( 'limit', $answer['message'], 'no detail for the shopper' );
			$this->assertFalse( Session::fallback_for( $this->ship_to->mac() ) );
			Session::forget_verification();
			$this->http_requests = array();
		}

		// AgeCheck unable to run it: the fallback.
		$this->hold_on_outage();
		unset( $this->transients[ $this->availability_transient() ] );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_2', 'NEEDS_DOC' ) ) );
		$this->respond( 200, $this->availability_body( 'SERVICE_UNAVAILABLE' ) );
		$this->assertSame( 'hold', $this->start()['status'] );
		$this->assertSame( array( 'CreateVerification', 'GetCheckAvailability' ), $this->rpcs(), 'no hosted link' );
		$this->assertTrue( Session::fallback_for( $this->ship_to->mac() ) );

		// Without the setting: the hosted link, as before.
		Session::forget_verification();
		Session::forget_fallback();
		$this->http_requests                       = array();
		$this->options[ Options::OUTAGE_FALLBACK ] = Options::OUTAGE_REFUSE;
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_3', 'NEEDS_DOC' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( self::HOSTED, $this->start()['hosted_url'], 'the cached answer is reused' );
		$this->assertSame( array( 'CreateVerification', 'CreateHostedSession' ), $this->rpcs() );

		// An engine without the read: as before.
		Session::forget_verification();
		$this->hold_on_outage();
		$this->http_requests = array();
		unset( $this->transients[ $this->availability_transient() ] );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_4', 'NEEDS_DOC' ) ) );
		$this->respond( 404, '404 page not found' );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( self::HOSTED, $this->start()['hosted_url'] );
	}

	public function test_doc_pending_is_never_refused_on_the_limit_and_falls_back_when_down(): void {
		$this->cache_availability( Availability::AVAILABLE );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'NEEDS_DOC' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();
		$this->http_requests = array();

		$this->cache_availability( Availability::ORG_DAILY_LIMIT );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'DOC_PENDING' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( self::HOSTED, $this->start()['hosted_url'], 'an open ID session may finish' );

		$this->cache_availability( Availability::OUTAGE );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'DOC_PENDING' ) ) );
		$this->assertSame( 'hold', $this->start()['status'] );
		$this->assertSame( 'pending', Session::record()['status'], 'the abandoned check stays on record for the order' );

		// Without the setting, DOC_PENDING reads nothing.
		Session::forget_fallback();
		$this->options[ Options::OUTAGE_FALLBACK ] = Options::OUTAGE_REFUSE;
		unset( $this->transients[ $this->availability_transient() ] );
		$this->http_requests = array();
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'DOC_PENDING' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->start();
		$this->assertSame( array( 'GetVerification', 'CreateHostedSession' ), $this->rpcs() );
	}

	public function test_awaiting_identity_reads_no_availability(): void {
		unset( $this->transients[ $this->availability_transient() ] );
		$this->respond( 200, array( 'verification' => $this->verification( 'acv_1', 'AWAITING_IDENTITY' ) ) );
		$this->respond( 200, array( 'hostedUrl' => self::HOSTED ) );
		$this->assertSame( self::HOSTED, $this->start()['hosted_url'] );
		$this->assertSame( array( 'CreateVerification', 'CreateHostedSession' ), $this->rpcs() );
	}

	public function test_gate_admits_a_fallback_without_a_check_and_only_then(): void {
		$mac = $this->ship_to->mac();
		Session::sid( true );

		// No fallback: refused as before.
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $this->ship_to, 'US' )['code'] );

		$this->assertTrue( Session::set_fallback( $mac ) );
		$this->assertNull( Gate::check( $this->ship_to, 'US' ) );
		$this->assertNull( Gate::admitted_verification_id() );
		$this->assertTrue( Gate::admitted_fallback() );
		$this->assertSame( array(), $this->http_requests, 'no call' );

		// A payment retried within the window is admitted again.
		Gate::reset();
		$this->assertFalse( Gate::admitted_fallback() );
		$this->assertNull( Gate::check( $this->ship_to, 'US' ) );
		$this->assertTrue( Gate::admitted_fallback() );

		// Another ship-to, the setting off, or the window over: refused.
		$moved = ShipTo::from_wc_address( $this->us_address( array( 'address_1' => '9 Elm St' ) ) );
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $moved, 'US' )['code'] );
		$this->assertFalse( Gate::admitted_fallback() );
		$this->options[ Options::OUTAGE_FALLBACK ] = Options::OUTAGE_REFUSE;
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $this->ship_to, 'US' )['code'] );
		$this->hold_on_outage();
		$this->assertTrue( Session::set_fallback( $mac, time() - Session::FALLBACK_TTL - 1 ) );
		$this->assertSame( Gate::REFUSE_VERIFY_FIRST, Gate::check( $this->ship_to, 'US' )['code'] );

		// A record for another ship-to refuses before the fallback is read.
		Session::set_fallback( $mac );
		Session::set_record( 'acv_9', $moved->mac(), 0, Session::STATUS_PENDING );
		$this->assertSame( Gate::REFUSE_SHIP_TO_CHANGED, Gate::check( $this->ship_to, 'US' )['code'] );
	}

	public function test_a_passed_check_without_an_answer_binds_as_usual(): void {
		$vid = 'acv_01HZPASSEDBEFORE';
		Session::set_record( $vid, $this->ship_to->mac(), 0, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( Session::sid( true ), $vid, $this->ship_to->mac() ), $vid );

		$this->respond( 503, '' );
		$this->assertNull( Gate::check( $this->ship_to, 'US' ) );
		$this->assertSame( $vid, Gate::admitted_verification_id(), 'the check already passed: no second one' );
		$this->assertFalse( Gate::admitted_fallback() );

		$order = $this->restricted_order();
		$this->respond( 503, '' );
		Binding::bind_checkout( $order );
		$this->assertSame( $vid, $order->meta[ Meta::VERIFICATION_ID ] );
		$this->assertSame( 'yes', $order->meta[ Meta::ATTACH_PENDING ], 'AttachOrder is retried by the release run and the sweep' );
		$this->assertArrayNotHasKey( Meta::FALLBACK, $order->meta );

		// Without the setting: refused, as before.
		$this->options[ Options::OUTAGE_FALLBACK ] = Options::OUTAGE_REFUSE;
		Session::set_record( $vid, $this->ship_to->mac(), 0, Session::STATUS_PASSED );
		Session::set_guard( GuardToken::mint_checkout_guard( Session::sid( true ), $vid, $this->ship_to->mac() ), $vid );
		$this->respond( 503, '' );
		$this->assertSame( Gate::REFUSE_UNAVAILABLE, Gate::check( $this->ship_to, 'US' )['code'] );
	}

	public function test_binding_marks_a_fallback_order_and_binds_nothing(): void {
		$mac = $this->ship_to->mac();
		Session::sid( true );
		Session::set_record( 'acv_01HZABANDONEDCHECK', $mac, 3, Session::STATUS_PENDING );
		Session::set_fallback( $mac );
		$this->assertNull( Gate::check( $this->ship_to, 'US' ) );

		$order = $this->restricted_order();
		Binding::bind_checkout( $order );
		$this->assertSame( array(), $this->http_requests, 'nothing bound, nothing created at checkout' );
		$this->assertArrayNotHasKey( Meta::VERIFICATION_ID, $order->meta );
		$this->assertMatchesRegularExpression( '/^outage:\d+$/', $order->meta[ Meta::FALLBACK ] );
		$this->assertSame( 'acv_01HZABANDONEDCHECK', $order->meta[ Meta::PREVIOUS_VERIFICATION_IDS ] );
		$this->assertNull( Session::record(), 'the abandoned check leaves the session' );
		$this->assertTrue( Session::fallback_for( $mac ), 'the fallback stays for a payment retry' );
		$this->assertStringContainsString( 'age verification was unavailable at checkout', implode( "\n", $order->notes ) );
		$this->assertSame( $order->meta, $order->saved_meta );
		$rows = OrderPanel::rows( $order );
		$this->assertSame( Release::reason_text( 'outage_fallback' ), isset( $rows['Checkout'] ) ? $rows['Checkout'] : null );

		// The payment is retried: admitted again, marked once.
		$notes = count( $order->notes );
		$this->assertNull( Gate::check( $this->ship_to, 'US' ) );
		Binding::bind_checkout( $order );
		$this->assertCount( $notes, $order->notes );

		// Only the order the gate admitted this way.
		Gate::reset();
		$other = $this->restricted_order();
		Binding::bind_checkout( $other );
		$this->assertArrayNotHasKey( Meta::FALLBACK, $other->meta );
		$this->assertContains( Meta::FALLBACK, Binding::COPIED_STATE );
	}
}

