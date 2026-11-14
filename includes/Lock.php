<?php
/**
 * Atomic per-order locks and claim-once markers.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Locks and dedupe markers as rows of the options table, written with plain
 * SQL so the unique option_name index makes them atomic. add_option() is not:
 * it reads first and then writes with INSERT ... ON DUPLICATE KEY UPDATE, so
 * two racing callers both "win".
 *
 * Rows are autoload off and never read through get_option(), so the options
 * cache never holds them. Names are navexa_agecheck_* (uninstall removes them).
 */
final class Lock {

	/**
	 * Takes the lock $name for $ttl seconds: a plain INSERT IGNORE, or a
	 * compare-and-set over an expired holder's value.
	 *
	 * @param string $name Option name (navexa_agecheck_lock_*).
	 * @param int    $ttl  Seconds until another caller may take it over.
	 * @return string A token for release(), or '' when someone else holds it.
	 */
	public static function acquire( string $name, int $ttl ): string {
		global $wpdb;
		$token = ( time() + max( 1, $ttl ) ) . ':' . bin2hex( random_bytes( 8 ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock row; never cached by design.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) );
		if ( 1 === $inserted ) {
			return $token;
		}
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $held ) {
			// Released between the two statements: one more insert attempt.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) );
			return 1 === $inserted ? $token : '';
		}
		if ( self::expiry( (string) $held ) > time() ) {
			return '';
		}
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, (string) $held ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 1 === $taken ? $token : '';
	}

	/**
	 * Releases the lock $name if $token still holds it.
	 *
	 * @param string $name  Option name.
	 * @param string $token Token from acquire().
	 */
	public static function release( string $name, string $token ): void {
		global $wpdb;
		if ( '' === $token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token ) );
	}

	/**
	 * Claims $name once: true for the first caller only (webhook dedupe).
	 *
	 * @param string $name Option name (navexa_agecheck_seen_*).
	 */
	public static function claim( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire().
		return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, (string) time() ) );
	}

	/**
	 * Drops a claim, so a delivery that could not be handled is handled on
	 * its retry.
	 *
	 * @param string $name Option name.
	 */
	public static function unclaim( string $name ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Deletes claims under $prefix made before $before (unix time), at most
	 * 500 per call.
	 *
	 * @param string $prefix Option name prefix.
	 * @param int    $before Unix time.
	 * @return int Rows deleted.
	 */
	public static function purge_claims( string $prefix, int $before ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see acquire().
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 500", $wpdb->esc_like( $prefix ) . '%', $before ) );
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * The expiry encoded in a lock value ("{expiry}:{random}"); 0 when unreadable.
	 *
	 * @param string $value Stored value.
	 */
	private static function expiry( string $value ): int {
		$parts = explode( ':', $value, 2 );
		return ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
	}
}
