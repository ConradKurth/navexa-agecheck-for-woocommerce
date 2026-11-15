<?php
/**
 * Atomic rate-limit counters.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters in the plugin's own table, so a shopper (or a bot)
 * cannot drain the start key's AgeCheck rate limit or run up verifications.
 *
 * Each hit is one INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1, which
 * MySQL and MariaDB apply atomically per row, then a read of the row. Two
 * racing requests can each read the other's increment, so a burst may be
 * refused one hit early; it is never admitted past the limit.
 *
 * Rows and the recent-start list hold HMAC-SHA256 values keyed from the
 * install's guard secret (Install::guard_secret()), never an IP address or a
 * plain hash of one: the IPv4 space is small enough to invert an unkeyed hash
 * by brute force. Confirming a copied site rotates the secret, which restarts
 * every counter.
 *
 * Fail closed: when the table cannot be written or read, or the guard secret
 * is missing, the hit is refused as unavailable (not as over the limit).
 *
 * Client IP: REMOTE_ADDR, unless the merchant names a trusted proxy header
 * (Options::TRUSTED_PROXY_HEADER). IPv6 addresses count per /64, the block a
 * single client usually holds.
 */
final class RateLimit {

	const TABLE             = 'navexa_agecheck_rate_limits';
	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'navexa_agecheck_db_version';

	/**
	 * Transient set when creating the table failed, so maybe_install() retries
	 * every few minutes instead of on every request.
	 */
	const TRANSIENT_INSTALL_RETRY = 'navexa_agecheck_rate_table_retry';

	/**
	 * What check() answers.
	 */
	const OK          = 'ok';
	const LIMITED     = 'limited';
	const UNAVAILABLE = 'unavailable';

	/**
	 * Option holding the latest starts, newest last, as {ip: short keyed hash
	 * of the client IP, via: proxy sign}, for the settings screen's proxy
	 * warning.
	 */
	const OPTION_RECENT_START_IPS = 'navexa_agecheck_recent_start_ips';

	/**
	 * A start's proxy sign when REMOTE_ADDR is a private, loopback or reserved
	 * address (a load balancer or reverse proxy in front of the store).
	 */
	const VIA_PRIVATE = 'private';

	/**
	 * How many recent start IPs are kept, and how many must be seen before
	 * the settings screen draws a conclusion from them.
	 */
	const RECENT_START_IPS = 20;
	const RECENT_START_MIN = 10;

	/**
	 * Proxy headers a merchant may trust, as $_SERVER keys. X-Forwarded-For
	 * is read right-most first: the last address is the one the trusted proxy
	 * appended.
	 */
	const PROXY_HEADERS = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
	);

	/**
	 * One in this many hits also deletes expired rows.
	 */
	const PRUNE_ONE_IN = 50;

	/**
	 * The table's name with this site's prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Registers the table upgrade check.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_install' ), 5 );
	}

	/**
	 * Creates or upgrades the table when its stored version differs (at most
	 * every few minutes after a failed attempt).
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) && false === get_transient( self::TRANSIENT_INSTALL_RETRY ) ) {
			self::install();
		}
	}

	/**
	 * Creates the table (dbDelta) and records its version once the table
	 * exists: dbDelta fails silently (e.g. a database user without CREATE),
	 * and a version recorded for a missing table would never be retried. Also
	 * the activation hook.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  bucket_key char(64) NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  expires_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (bucket_key),
  KEY expires_at (expires_at)
) {$charset};"
		);
		if ( ! self::table_exists() ) {
			Client::log( 'error', 'agecheck rate limit table missing after install' );
			set_transient( self::TRANSIENT_INSTALL_RETRY, 1, 5 * 60 );
			return;
		}
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	/**
	 * Whether the counter table exists.
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check of the plugin's own table.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Counts one hit for $subject in $bucket and says whether it is within
	 * $limit hits per $window seconds. False when over the limit or when the
	 * counter is unavailable (fail closed); check() tells the two apart.
	 *
	 * @param string   $bucket  Bucket name, e.g. start_ip.
	 * @param string   $subject Who is counted (session id, client IP).
	 * @param int      $limit   Hits allowed per window.
	 * @param int      $window  Window length in seconds.
	 * @param int|null $now     Unix time; null for time().
	 */
	public static function hit( string $bucket, string $subject, int $limit, int $window, ?int $now = null ): bool {
		return self::OK === self::check( $bucket, $subject, $limit, $window, $now );
	}

	/**
	 * Counts one hit for $subject in $bucket: OK while within $limit hits per
	 * $window seconds, LIMITED past it (or when $limit is below 1), and
	 * UNAVAILABLE when the counter cannot be written or read, the guard
	 * secret is missing or there is no subject.
	 *
	 * @param string   $bucket  Bucket name, e.g. start_ip.
	 * @param string   $subject Who is counted (session id, client IP).
	 * @param int      $limit   Hits allowed per window.
	 * @param int      $window  Window length in seconds.
	 * @param int|null $now     Unix time; null for time().
	 * @return string OK, LIMITED or UNAVAILABLE.
	 */
	public static function check( string $bucket, string $subject, int $limit, int $window, ?int $now = null ): string {
		global $wpdb;
		$hmac_key = self::key();
		if ( '' === $subject || $window < 1 || '' === $hmac_key ) {
			Client::log( 'error', 'agecheck rate limit unavailable bucket=' . $bucket . ' reason=' . ( '' === $hmac_key ? 'no_guard_secret' : ( '' === $subject ? 'no_subject' : 'bad_window' ) ) );
			return self::UNAVAILABLE;
		}
		if ( $limit < 1 ) {
			return self::LIMITED;
		}
		$now   = null === $now ? time() : $now;
		$start = intdiv( $now, $window ) * $window;
		$key   = hash_hmac( 'sha256', $bucket . '|' . $subject . '|' . $window . '|' . $start, $hmac_key );
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own counter table; caching would break the count.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket_key, hits, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1",
				$key,
				$start + $window
			)
		);
		$hits    = false === $written ? null : $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE bucket_key = %s", $key ) );
		if ( 0 === wp_rand( 0, self::PRUNE_ONE_IN - 1 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d LIMIT 1000", $now ) );
		}
		// phpcs:enable

		if ( null === $hits || ! is_numeric( $hits ) ) {
			Client::log( 'error', 'agecheck rate limit unavailable bucket=' . $bucket );
			return self::UNAVAILABLE;
		}
		return (int) $hits <= $limit ? self::OK : self::LIMITED;
	}

	/**
	 * The client's IP for rate limiting: the trusted proxy header's address
	 * when one is configured and holds a valid IP, else REMOTE_ADDR. IPv6 is
	 * cut to its /64. '' when nothing valid is known.
	 */
	public static function client_ip(): string {
		$header = Options::trusted_proxy_header();
		if ( '' !== $header && isset( $_SERVER[ $header ] ) && is_string( $_SERVER[ $header ] ) ) {
			// X-Forwarded-For: only the right-most entry was added by the trusted
			// proxy; the others are whatever the client sent.
			$values = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			$ip     = self::normalize_ip( trim( (string) end( $values ) ) );
			if ( '' !== $ip ) {
				return $ip;
			}
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return self::normalize_ip( $remote );
	}

	/**
	 * $ip as a rate-limit subject: IPv4 as is, IPv6 as its /64 prefix; '' when
	 * $ip is not an IP address.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function normalize_ip( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		if ( 16 === strlen( $packed ) && 0 !== strncmp( $packed, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
			return bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}
		$v4 = 16 === strlen( $packed ) ? substr( $packed, 12 ) : $packed;
		$ip = inet_ntop( $v4 );
		return false === $ip ? '' : $ip;
	}

	/**
	 * Remembers this start for the settings screen's proxy warning: a short
	 * keyed hash of its client IP and its proxy sign (proxy_sign()). Nothing
	 * is kept without a guard secret.
	 */
	public static function record_start(): void {
		$hmac_key = self::key();
		if ( '' === $hmac_key ) {
			return;
		}
		$recent   = self::recent_starts();
		$recent[] = array(
			'ip'  => substr( hash_hmac( 'sha256', 'recent-start-ip|' . self::client_ip(), $hmac_key ), 0, 12 ),
			'via' => self::proxy_sign(),
		);
		update_option( self::OPTION_RECENT_START_IPS, array_slice( $recent, -self::RECENT_START_IPS ), false );
	}

	/**
	 * Whether the latest starts look like they reached the store through a
	 * proxy, load balancer or CDN whose client address is not being read, so
	 * the per-IP limits count every shopper as one (or a few) clients. Null
	 * when they do not (or fewer than RECENT_START_MIN starts are known).
	 * Otherwise the $_SERVER key of the proxy header most of them carried, or
	 * '' when they carried none but came from one IP address or, mostly, a
	 * private network address.
	 */
	public static function suspected_proxy(): ?string {
		$recent = self::recent_starts();
		$total  = count( $recent );
		if ( $total < self::RECENT_START_MIN ) {
			return null;
		}
		$signs = array_count_values( array_filter( array_column( $recent, 'via' ) ) );
		if ( 2 * array_sum( $signs ) > $total ) {
			arsort( $signs );
			foreach ( array_keys( $signs ) as $sign ) {
				if ( in_array( $sign, self::PROXY_HEADERS, true ) ) {
					return (string) $sign;
				}
			}
			return '';
		}
		return 1 === count( array_unique( array_column( $recent, 'ip' ) ) ) ? '' : null;
	}

	/**
	 * This request's sign of an unread proxy in front of the store, when no
	 * trusted proxy header is configured: the first PROXY_HEADERS header it
	 * carries, else VIA_PRIVATE when REMOTE_ADDR is a private, loopback or
	 * reserved address, else ''. A client can send these headers itself, so
	 * they only feed a warning, never the rate limit.
	 */
	private static function proxy_sign(): string {
		if ( '' !== Options::trusted_proxy_header() ) {
			return '';
		}
		foreach ( self::PROXY_HEADERS as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				return $header;
			}
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false !== filter_var( $remote, FILTER_VALIDATE_IP ) && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return self::VIA_PRIVATE;
		}
		return '';
	}

	/**
	 * The stored recent starts.
	 *
	 * @return array<int, array{ip: string, via: string}>
	 */
	private static function recent_starts(): array {
		$recent = get_option( self::OPTION_RECENT_START_IPS, array() );
		$out    = array();
		foreach ( is_array( $recent ) ? $recent : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['ip'], $entry['via'] ) && is_string( $entry['ip'] ) && is_string( $entry['via'] ) ) {
				$out[] = array(
					'ip'  => $entry['ip'],
					'via' => $entry['via'],
				);
			}
		}
		return $out;
	}

	/**
	 * The HMAC key for counter keys and IP hashes, derived from the guard
	 * secret; '' without one.
	 */
	private static function key(): string {
		$secret = Install::guard_secret();
		return '' === $secret ? '' : hash_hmac( 'sha256', 'navexa-agecheck/rate-limit/v1', $secret, true );
	}
}
