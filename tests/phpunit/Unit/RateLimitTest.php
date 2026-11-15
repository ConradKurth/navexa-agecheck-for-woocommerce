<?php
/**
 * RateLimit tests.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Brain\Monkey\Functions;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Options;
use Navexa\AgeCheck\RateLimit;
use Navexa\AgeCheck\Tests\TestCase;

final class RateLimitTest extends TestCase {

	const NOW = 1700000000;

	protected function setUp(): void {
		parent::setUp();
		Install::ensure();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_TRUE_CLIENT_IP'] );
		parent::tearDown();
	}

	public function test_counts_up_to_the_limit_per_subject_and_window(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertTrue( RateLimit::hit( 'start_session', 'sid-a', 3, 3600, self::NOW ), "hit {$i}" );
		}
		$this->assertFalse( RateLimit::hit( 'start_session', 'sid-a', 3, 3600, self::NOW ) );
		$this->assertTrue( RateLimit::hit( 'start_session', 'sid-b', 3, 3600, self::NOW ), 'another subject' );
		$this->assertTrue( RateLimit::hit( 'start_ip', 'sid-a', 3, 3600, self::NOW ), 'another bucket' );
		$next_window = ( intdiv( self::NOW, 3600 ) + 1 ) * 3600;
		$this->assertTrue( RateLimit::hit( 'start_session', 'sid-a', 3, 3600, $next_window ), 'the next window starts fresh' );
	}

	public function test_rows_hold_keyed_hashes_and_expire_with_their_window(): void {
		RateLimit::hit( 'start_ip', '203.0.113.7', 5, 3600, self::NOW );
		$this->assertCount( 1, $this->wpdb->rows );
		$key   = array_keys( $this->wpdb->rows )[0];
		$start = intdiv( self::NOW, 3600 ) * 3600;
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
		$this->assertStringNotContainsString( '203.0.113.7', implode( "\n", $this->wpdb->queries ) );
		$this->assertNotSame( hash( 'sha256', 'navexa-agecheck-rate-v1|start_ip|203.0.113.7|3600|' . $start ), $key, 'not an unkeyed hash anyone can brute-force' );
		$this->assertSame( $start + 3600, $this->wpdb->rows[ $key ]['expires_at'] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE hits = hits + 1', $this->wpdb->queries[0] );

		// Another install's secret gives another key for the same hit.
		$this->options[ Install::OPTION_GUARD_SECRET ] = str_repeat( 'ab', 32 );
		RateLimit::hit( 'start_ip', '203.0.113.7', 5, 3600, self::NOW );
		$this->assertCount( 2, $this->wpdb->rows );
	}

	public function test_prunes_expired_rows_now_and_then(): void {
		RateLimit::hit( 'a', 'x', 5, 60, self::NOW );
		Functions\when( 'wp_rand' )->justReturn( 0 );
		RateLimit::hit( 'a', 'y', 5, 60, self::NOW + 3600 );
		$this->assertCount( 1, $this->wpdb->rows, 'the expired row went' );
	}

	public function test_fails_closed_as_unavailable_not_as_over_the_limit(): void {
		$this->assertSame( RateLimit::OK, RateLimit::check( 'start_session', 'sid', 1, 3600, self::NOW ) );
		$this->assertSame( RateLimit::LIMITED, RateLimit::check( 'start_session', 'sid', 1, 3600, self::NOW ) );
		$this->assertSame( RateLimit::LIMITED, RateLimit::check( 'start_session', 'other', 0, 3600, self::NOW ), 'a limit of 0 allows nothing' );

		$this->wpdb->fail = true;
		$this->assertSame( RateLimit::UNAVAILABLE, RateLimit::check( 'start_session', 'sid', 10, 3600, self::NOW ) );
		$this->assertFalse( RateLimit::hit( 'start_session', 'sid', 10, 3600, self::NOW ) );
		$this->assertStringContainsString( 'rate limit unavailable bucket=start_session', $this->all_logs() );
		$this->wpdb->fail = false;

		$this->assertSame( RateLimit::UNAVAILABLE, RateLimit::check( 'start_session', '', 10, 3600, self::NOW ), 'no subject' );
		$this->assertSame( RateLimit::UNAVAILABLE, RateLimit::check( 'start_session', 'sid', 10, 0, self::NOW ), 'no window' );
		$this->assertStringContainsString( 'reason=bad_window', $this->all_logs() );

		unset( $this->options[ Install::OPTION_GUARD_SECRET ] );
		$queries = count( $this->wpdb->queries );
		$this->assertSame( RateLimit::UNAVAILABLE, RateLimit::check( 'start_session', 'sid', 10, 3600, self::NOW ) );
		$this->assertCount( $queries, $this->wpdb->queries, 'nothing counted without the key' );
		$this->assertStringContainsString( 'reason=no_guard_secret', $this->all_logs() );
	}

	public function test_install_creates_the_table_once_per_version(): void {
		$created = array();
		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( &$created ) {
				$created[] = $sql;
				return array();
			}
		);
		RateLimit::maybe_install();
		RateLimit::maybe_install();
		$this->assertCount( 1, $created );
		$this->assertStringContainsString( 'CREATE TABLE wp_navexa_agecheck_rate_limits', $created[0] );
		$this->assertStringContainsString( 'PRIMARY KEY  (bucket_key)', $created[0] );
		$this->assertSame( RateLimit::DB_VERSION, $this->options[ RateLimit::OPTION_DB_VERSION ] );
		$this->assertTrue( RateLimit::table_exists() );
	}

	public function test_a_table_that_was_not_created_is_retried_not_recorded(): void {
		$created = 0;
		Functions\when( 'dbDelta' )->alias(
			static function () use ( &$created ) {
				++$created;
				return array();
			}
		);
		$this->wpdb->table_exists = false;
		RateLimit::maybe_install();
		$this->assertArrayNotHasKey( RateLimit::OPTION_DB_VERSION, $this->options, 'no version for a missing table' );
		$this->assertFalse( RateLimit::table_exists() );
		$this->assertStringContainsString( 'rate limit table missing after install', $this->all_logs() );
		$this->assertSame( 300, $this->transients[ RateLimit::TRANSIENT_INSTALL_RETRY ]['ttl'] );

		RateLimit::maybe_install();
		$this->assertSame( 1, $created, 'not retried on every request' );

		unset( $this->transients[ RateLimit::TRANSIENT_INSTALL_RETRY ] );
		$this->wpdb->table_exists = true;
		RateLimit::maybe_install();
		$this->assertSame( 2, $created );
		$this->assertSame( RateLimit::DB_VERSION, $this->options[ RateLimit::OPTION_DB_VERSION ] );
	}

	public function test_client_ip_is_remote_addr_unless_a_header_is_trusted(): void {
		$_SERVER['REMOTE_ADDR']           = '198.51.100.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
		$this->assertSame( '198.51.100.1', RateLimit::client_ip(), 'headers are ignored by default' );

		$this->options[ Options::TRUSTED_PROXY_HEADER ] = 'HTTP_CF_CONNECTING_IP';
		$this->assertSame( '203.0.113.9', RateLimit::client_ip() );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$this->assertSame( '198.51.100.1', RateLimit::client_ip(), 'an unreadable header falls back' );

		$this->options[ Options::TRUSTED_PROXY_HEADER ] = 'HTTP_X_FORWARDED_FOR';
		$_SERVER['HTTP_X_FORWARDED_FOR']                = '6.6.6.6, 203.0.113.5';
		$this->assertSame( '203.0.113.5', RateLimit::client_ip(), 'the right-most address, added by the proxy' );

		$this->options[ Options::TRUSTED_PROXY_HEADER ] = 'HTTP_X_EVIL';
		$this->assertSame( '', Options::trusted_proxy_header(), 'only listed headers' );
		$this->assertSame( '198.51.100.1', RateLimit::client_ip() );

		$_SERVER['REMOTE_ADDR'] = 'garbage';
		$this->assertSame( '', RateLimit::client_ip() );
	}

	public function test_ipv6_counts_per_slash_64(): void {
		$this->assertSame( '20010db8000000ab::/64', RateLimit::normalize_ip( '2001:db8:0:ab::1' ) );
		$this->assertSame( RateLimit::normalize_ip( '2001:db8:0:ab::1' ), RateLimit::normalize_ip( '2001:db8:0:ab:ffff::2' ) );
		$this->assertSame( '192.0.2.1', RateLimit::normalize_ip( '::ffff:192.0.2.1' ), 'IPv4-mapped' );
		$this->assertSame( '192.0.2.1', RateLimit::normalize_ip( '192.0.2.1' ) );
		$this->assertSame( '', RateLimit::normalize_ip( '' ) );
	}

	/** Records $count starts, the i-th from REMOTE_ADDR $remote( i ). */
	private function starts( int $count, callable $remote ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = $remote( $i );
			RateLimit::record_start();
		}
	}

	public function test_warns_when_recent_starts_share_one_ip(): void {
		$one = static function () {
			return '198.51.100.1';
		};
		$this->starts( RateLimit::RECENT_START_MIN - 1, $one );
		$this->assertNull( RateLimit::suspected_proxy(), 'not enough starts yet' );
		$this->starts( 1, $one );
		$this->assertSame( '', RateLimit::suspected_proxy() );
		$this->assertFalse( $this->autoload[ RateLimit::OPTION_RECENT_START_IPS ] );
		$stored = (string) wp_json_encode( $this->options[ RateLimit::OPTION_RECENT_START_IPS ] );
		$this->assertStringNotContainsString( '198.51.100.1', $stored );
		$this->assertStringNotContainsString( substr( hash( 'sha256', 'navexa-agecheck-ip-v1|198.51.100.1' ), 0, 12 ), $stored, 'keyed, not a plain hash' );

		$this->starts(
			1,
			static function () {
				return '198.51.100.2';
			}
		);
		$this->assertNull( RateLimit::suspected_proxy() );
		$this->starts( RateLimit::RECENT_START_IPS, $one );
		$this->assertCount( RateLimit::RECENT_START_IPS, $this->options[ RateLimit::OPTION_RECENT_START_IPS ] );
		$this->assertSame( '', RateLimit::suspected_proxy() );
	}

	public function test_warns_behind_a_load_balancer_with_several_private_addresses(): void {
		$this->starts(
			RateLimit::RECENT_START_MIN,
			static function ( $i ) {
				return '10.0.0.' . ( 1 + $i % 2 );
			}
		);
		$this->assertSame( '', RateLimit::suspected_proxy(), 'two ALB nodes are still a proxy' );
	}

	public function test_names_the_proxy_header_the_starts_carried(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';
		$this->starts(
			RateLimit::RECENT_START_MIN,
			static function ( $i ) {
				return '198.51.100.' . ( 10 + $i );
			}
		);
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', RateLimit::suspected_proxy(), 'a pool of Cloudflare edge addresses' );

		// Once the header is trusted, its addresses are the clients.
		$this->options[ RateLimit::OPTION_RECENT_START_IPS ] = array();
		$this->options[ Options::TRUSTED_PROXY_HEADER ]      = 'HTTP_CF_CONNECTING_IP';
		for ( $i = 0; $i < RateLimit::RECENT_START_MIN; $i++ ) {
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.' . ( 60 + $i );
			RateLimit::record_start();
		}
		$this->assertNull( RateLimit::suspected_proxy() );
	}

	public function test_a_few_header_carrying_starts_among_many_do_not_warn(): void {
		$this->starts(
			RateLimit::RECENT_START_MIN,
			static function ( $i ) {
				return '198.51.100.' . ( 10 + $i );
			}
		);
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
		$this->starts(
			RateLimit::RECENT_START_MIN - 1,
			static function ( $i ) {
				return '198.51.100.' . ( 40 + $i );
			}
		);
		$this->assertNull( RateLimit::suspected_proxy(), 'fewer than half' );
	}

	public function test_nothing_is_recorded_without_a_guard_secret_and_junk_is_ignored(): void {
		unset( $this->options[ Install::OPTION_GUARD_SECRET ] );
		RateLimit::record_start();
		$this->assertArrayNotHasKey( RateLimit::OPTION_RECENT_START_IPS, $this->options );

		$this->options[ RateLimit::OPTION_RECENT_START_IPS ] = array_merge( array_fill( 0, 12, 'abcdefabcdef' ), array( array( 'ip' => 1 ) ) );
		$this->assertNull( RateLimit::suspected_proxy() );
	}
}
