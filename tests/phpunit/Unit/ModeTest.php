<?php
/**
 * Mode and IntegrationInfo tests. GetIntegrationInfo (engine PR E10a) is not
 * deployed yet, so these pin the plugin to the contract in the plan
 * (design-final.md §2.1, §2.7): {origin} -> {livemode, scopes,
 * origin_allowed, minimum_age, display_name}.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\IntegrationInfo;
use Navexa\AgeCheck\Mode;
use Navexa\AgeCheck\Tests\TestCase;

final class ModeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->install_with_keys();
	}

	public function test_info_sends_the_store_origin_and_parses_the_answer(): void {
		$this->home = 'https://Shop.Example:8443/store/';
		\Navexa\AgeCheck\Install::confirm_site();
		$this->respond( 200, $this->live_info() );

		$info = Mode::info( Client::KEY_FULFILMENT );

		$this->assertNotNull( $info );
		$this->assertTrue( $info->livemode );
		$this->assertTrue( $info->origin_allowed );
		$this->assertSame( 21, $info->minimum_age );
		$this->assertSame( 'Shop Example', $info->display_name );
		$this->assertSame( 'org_example', $info->organization_id, 'E10a names the key\'s organization' );
		$this->assertSame( array(), $info->missing_scopes( Client::KEY_FULFILMENT ) );
		$this->assertSame( '{"origin":"https://shop.example:8443"}', $this->http_requests[0]['args']['body'] );
		$this->assertStringEndsWith( '/GetIntegrationInfo', $this->http_requests[0]['url'] );
	}

	public function test_answer_is_cached_for_an_hour_per_key(): void {
		$this->respond( 200, $this->live_info() );
		Mode::info( Client::KEY_FULFILMENT );
		Mode::info( Client::KEY_FULFILMENT );
		$this->assertCount( 1, $this->http_requests );

		$cached = array_values( $this->transients );
		$this->assertCount( 1, $cached );
		$this->assertSame( 3600, $cached[0]['ttl'] );
		foreach ( array_keys( $this->transients ) as $name ) {
			$this->assertStringNotContainsString( substr( self::LIVE_FULFILMENT_KEY, 4, 12 ), $name, 'cache names never hold the key' );
			$this->assertLessThanOrEqual( 172, strlen( $name ) );
		}

		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		$this->assertFalse( Mode::info( Client::KEY_START )->livemode, 'the start key has its own entry' );
		$this->assertCount( 2, $this->http_requests );
	}

	public function test_failure_is_cached_briefly_and_fails_closed(): void {
		$this->respond( 503, array( 'code' => 'unavailable' ) );

		$result = Mode::lookup( Client::KEY_FULFILMENT );
		$this->assertNull( $result['info'] );
		$this->assertSame( 'unavailable', $result['error'] );
		$this->assertSame( Mode::FAILURE_TTL, array_values( $this->transients )[0]['ttl'] );

		$this->assertNull( Mode::info( Client::KEY_FULFILMENT ), 'served from the failure cache' );
		$this->assertCount( 1, $this->http_requests );

		$this->respond( 200, $this->live_info() );
		$this->assertNotNull( Mode::lookup( Client::KEY_FULFILMENT, null, true )['info'], 'the settings screen asks again' );
		$this->assertCount( 2, $this->http_requests );
	}

	public function test_engine_without_the_rpc_reads_as_unimplemented(): void {
		$this->respond( 404, '404 page not found' );
		$result = Mode::lookup( Client::KEY_FULFILMENT );
		$this->assertNull( $result['info'] );
		$this->assertSame( 'unimplemented', $result['error'] );
		$this->assertFalse( Mode::accepts_livemode( true ) );
		$this->assertFalse( Mode::may_auto_release() );
	}

	public function test_no_key_means_no_info_and_no_call(): void {
		unset( $this->options[ \Navexa\AgeCheck\Options::START_KEY ] );
		$this->assertSame( 'no_key', Mode::lookup( Client::KEY_START )['error'] );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_local_refusals_are_not_cached(): void {
		$this->home = 'https://copy.shop.example';
		$this->assertSame( 'install_unconfirmed', Mode::lookup( Client::KEY_FULFILMENT )['error'] );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * @dataProvider livemode_matrix
	 */
	public function test_accepts_livemode( ?bool $key_livemode, bool $answer_livemode, bool $accepted ): void {
		$info = null;
		if ( null !== $key_livemode ) {
			$info           = new IntegrationInfo();
			$info->livemode = $key_livemode;
		}
		$this->assertSame( $accepted, Mode::accepts_livemode( $answer_livemode, Client::KEY_FULFILMENT, $info ) );
	}

	public function livemode_matrix(): array {
		return array(
			'live key, live answer' => array( true, true, true ),
			'live key, test answer' => array( true, false, false ),
			'test key, test answer' => array( false, false, true ),
			'test key, live answer' => array( false, true, false ),
		);
	}

	public function test_accepts_livemode_fails_closed_when_mode_is_unknown(): void {
		$this->respond_transport_error( 'cURL error 28: Operation timed out' );
		$this->assertFalse( Mode::accepts_livemode( true ) );
		$this->assertFalse( Mode::accepts_livemode( false ) );
	}

	public function test_test_keys_never_auto_release_without_the_constant(): void {
		$live           = new IntegrationInfo();
		$live->livemode = true;
		$test           = new IntegrationInfo();
		$this->assertTrue( Mode::may_auto_release( $live ) );
		$this->assertFalse( Mode::may_auto_release( $test ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_test_keys_auto_release_with_the_constant(): void {
		define( 'NAVEXA_AGECHECK_ALLOW_TEST_RELEASE', true );
		$this->assertTrue( Mode::may_auto_release( new IntegrationInfo() ) );
	}

	public function test_is_test_mode_makes_no_call(): void {
		$this->assertFalse( Mode::is_test_mode() );
		$this->assertCount( 0, $this->http_requests );

		$this->respond( 200, array( 'scopes' => array( 'agecheck:verify' ) ) );
		Mode::info( Client::KEY_START );
		$this->assertTrue( Mode::is_test_mode(), 'a missing livemode is test mode' );
		$this->assertCount( 1, $this->http_requests );
	}

	public function test_test_mode_outlives_the_cache_and_outages(): void {
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		Mode::info( Client::KEY_FULFILMENT );
		$name = Mode::OPTION_LAST_MODE_PREFIX . Client::KEY_FULFILMENT;
		$this->assertTrue( $this->autoload[ $name ], 'read on every admin screen' );
		$this->assertStringNotContainsString( substr( self::LIVE_FULFILMENT_KEY, 4, 12 ), serialize( $this->options[ $name ] ), 'never the key itself' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$this->transients = array(); // The hour is up.
		$this->assertTrue( Mode::is_test_mode() );

		$this->respond( 503, array( 'code' => 'unavailable' ) );
		$this->assertNull( Mode::info( Client::KEY_FULFILMENT ) );
		$this->assertTrue( Mode::is_test_mode(), 'an outage does not hide the banner' );

		$this->respond( 200, $this->live_info() );
		Mode::lookup( Client::KEY_FULFILMENT, null, true );
		$this->assertFalse( Mode::is_test_mode(), 'the key now answers as live' );
	}

	public function test_test_mode_of_a_replaced_key_is_ignored(): void {
		$this->respond( 200, $this->live_info( array( 'livemode' => false ) ) );
		Mode::info( Client::KEY_FULFILMENT );
		$this->options[ \Navexa\AgeCheck\Options::FULFILMENT_KEY ] = 'txk_' . str_repeat( 'cd', 32 );
		$this->assertFalse( Mode::is_test_mode() );
	}

	public function test_refresh_asks_again_even_when_a_success_is_cached(): void {
		$this->respond( 200, $this->live_info( array( 'originAllowed' => false ) ) );
		$this->assertFalse( Mode::info( Client::KEY_FULFILMENT )->origin_allowed );
		$this->respond( 200, $this->live_info() );
		$this->assertTrue( Mode::lookup( Client::KEY_FULFILMENT, null, true )['info']->origin_allowed );
		$this->assertTrue( Mode::info( Client::KEY_FULFILMENT )->origin_allowed, 'the fresh answer replaced the cache' );
		$this->assertCount( 2, $this->http_requests );
	}

	public function test_test_mode_notice_is_shown_to_store_managers_only(): void {
		$this->respond( 200, array( 'livemode' => false ) );
		Mode::info( Client::KEY_FULFILMENT );

		ob_start();
		Mode::test_mode_notice();
		$this->assertStringContainsString( 'AgeCheck TEST MODE', ob_get_clean() );

		$this->caps = array();
		ob_start();
		Mode::test_mode_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_flush_drops_cached_answers(): void {
		$this->respond( 200, $this->live_info() );
		$this->respond( 200, $this->live_info() );
		Mode::info( Client::KEY_FULFILMENT );
		Mode::info( Client::KEY_START );
		$this->assertCount( 2, $this->transients );
		Mode::flush();
		$this->assertCount( 0, $this->transients );
		$this->assertArrayNotHasKey( Mode::OPTION_LAST_MODE_PREFIX . Client::KEY_START, $this->options );
		$this->assertArrayNotHasKey( Mode::OPTION_LAST_MODE_PREFIX . Client::KEY_FULFILMENT, $this->options );
	}

	public function test_store_origin(): void {
		$this->home = 'http://Shop.Example/';
		$this->assertSame( 'http://shop.example', Mode::store_origin() );
		$this->home = 'https://shop.example:8443/sub/dir';
		$this->assertSame( 'https://shop.example:8443', Mode::store_origin() );
		$this->home = '/relative';
		$this->assertSame( '', Mode::store_origin() );
	}

	public function test_integration_info_defaults_for_omitted_proto3_fields(): void {
		$info = IntegrationInfo::from_response( array() );
		$this->assertFalse( $info->livemode );
		$this->assertSame( array(), $info->scopes );
		$this->assertFalse( $info->origin_allowed );
		$this->assertNull( $info->minimum_age );
		$this->assertSame( '', $info->display_name );
		$this->assertSame( '', $info->organization_id );
		$this->assertSame( array( 'agecheck:verify' ), $info->missing_scopes( Client::KEY_START ) );
		$this->assertSame( array( 'agecheck:verify', 'agecheck:clearance' ), $info->missing_scopes( Client::KEY_FULFILMENT ) );
	}

	public function test_integration_info_accepts_proto_names_and_rejects_odd_values(): void {
		$info = IntegrationInfo::from_response(
			array(
				'livemode'        => 'true',
				'scopes'          => array( 'agecheck:verify', 7, 'agecheck:verify' ),
				'origin_allowed'  => true,
				'minimum_age'     => '19',
				'display_name'    => 'Snake',
				'organization_id' => 'org_snake',
			)
		);
		$this->assertFalse( $info->livemode, 'only a JSON true is live' );
		$this->assertSame( array( 'agecheck:verify' ), $info->scopes );
		$this->assertTrue( $info->origin_allowed );
		$this->assertSame( 19, $info->minimum_age );
		$this->assertSame( 'Snake', $info->display_name );
		$this->assertSame( 'org_snake', $info->organization_id );
		$this->assertSame( '', IntegrationInfo::from_response( array( 'organizationId' => 7 ) )->organization_id );

		$this->assertNull( IntegrationInfo::from_response( array( 'minimumAge' => 20 ) )->minimum_age );
		$this->assertNull( IntegrationInfo::from_response( array( 'minimumAge' => 21.5 ) )->minimum_age );
	}

	public function test_integration_info_round_trips_through_the_cache_array(): void {
		$info = IntegrationInfo::from_response( $this->live_info() );
		$this->assertEquals( $info, IntegrationInfo::from_array( $info->to_array() ) );
	}
}
