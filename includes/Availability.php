<?php
/**
 * Whether AgeCheck's ID check can start right now.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * GetCheckAvailability (TAX-1841, scope agecheck:verify), read with the start
 * key when a checkout's check needs the ID step. It tells the store's own
 * daily limit (ORG_DAILY_LIMIT: refuse, the limit is the store's) from
 * AgeCheck being unable to run the ID check (SERVICE_UNAVAILABLE), which,
 * like no answer at all (OUTAGE), lets a store that chose to take and hold
 * orders do so (Options::outage_fallback()).
 *
 * - AVAILABLE, ORG_DAILY_LIMIT, SERVICE_UNAVAILABLE: AgeCheck's answer,
 *   cached store-wide for SUCCESS_TTL. ORG_DAILY_LIMIT also stores when it
 *   resets (Options::CAPPED_UNTIL) for the admin notice. An unknown value
 *   reads SERVICE_UNAVAILABLE, as the proto says.
 * - OUTAGE: AgeCheck did not answer (ConnectError::is_outage()); cached for
 *   OUTAGE_TTL only, so one blip does not send every shopper of a minute to
 *   the fallback.
 * - REFUSED: FAILED_PRECONDITION or PERMISSION_DENIED (no card, live mode
 *   paused, AgeCheck not enabled); not cached.
 * - UNKNOWN: anything else, an engine without the RPC (UNIMPLEMENTED: a
 *   plain 404) included: carry on as before this read existed; not cached.
 *
 * Logs codes only.
 */
final class Availability {

	const RPC = 'GetCheckAvailability';

	const AVAILABLE           = 'available';
	const ORG_DAILY_LIMIT     = 'org_daily_limit';
	const SERVICE_UNAVAILABLE = 'service_unavailable';
	const OUTAGE              = 'outage';
	const REFUSED             = 'refused';
	const UNKNOWN             = 'unknown';

	const SUCCESS_TTL      = 60;
	const OUTAGE_TTL       = 10;
	const TRANSIENT_PREFIX = 'navexa_agecheck_avail_';

	/**
	 * The answer for the start key's store: one of the constants above.
	 *
	 * @param Client|null $client Client to use; null for the stored start key.
	 */
	public static function read( ?Client $client = null ): string {
		$client = null === $client ? Client::for_key( Client::KEY_START ) : $client;
		if ( ! $client->has_key() ) {
			return self::UNKNOWN;
		}
		$name   = self::TRANSIENT_PREFIX . $client->key_fingerprint();
		$cached = get_transient( $name );
		if ( is_string( $cached ) && in_array( $cached, array( self::AVAILABLE, self::ORG_DAILY_LIMIT, self::SERVICE_UNAVAILABLE, self::OUTAGE ), true ) ) {
			return $cached;
		}
		try {
			$body = $client->call( self::RPC, array() );
		} catch ( ConnectError $e ) {
			if ( $e->is_outage() ) {
				set_transient( $name, self::OUTAGE, self::OUTAGE_TTL );
				Client::log( 'warning', 'agecheck availability outage code=' . $e->connect_code() );
				return self::OUTAGE;
			}
			if ( '' === $e->local_reason() && in_array( $e->connect_code(), array( 'failed_precondition', 'permission_denied' ), true ) ) {
				return self::REFUSED;
			}
			return self::UNKNOWN;
		}
		$answer = self::from_body( $body );
		set_transient( $name, $answer, self::SUCCESS_TTL );
		if ( self::ORG_DAILY_LIMIT === $answer ) {
			$resumes = isset( $body['resumesAt'] ) && is_string( $body['resumesAt'] ) ? strtotime( $body['resumesAt'] ) : false;
			$until   = false === $resumes ? self::next_utc_midnight() : (int) $resumes;
			update_option( Options::CAPPED_UNTIL, $until, false );
		}
		if ( self::AVAILABLE !== $answer ) {
			Client::log( 'warning', 'agecheck availability answer=' . $answer );
		}
		return $answer;
	}

	/**
	 * The answer in a GetCheckAvailability body. A value this plugin does
	 * not know (or none) reads SERVICE_UNAVAILABLE.
	 *
	 * @param array<string, mixed> $body Response (proto3 JSON).
	 */
	public static function from_body( array $body ): string {
		$value = isset( $body['idCheck'] ) ? $body['idCheck'] : '';
		if ( in_array( $value, array( 'ID_CHECK_AVAILABILITY_AVAILABLE', 1 ), true ) ) {
			return self::AVAILABLE;
		}
		if ( in_array( $value, array( 'ID_CHECK_AVAILABILITY_ORG_DAILY_LIMIT', 2 ), true ) ) {
			return self::ORG_DAILY_LIMIT;
		}
		return self::SERVICE_UNAVAILABLE;
	}

	/**
	 * Whether $answer lets a store that holds on outages take the order
	 * (SERVICE_UNAVAILABLE or OUTAGE).
	 *
	 * @param string $answer A read() answer.
	 */
	public static function is_down( string $answer ): bool {
		return in_array( $answer, array( self::SERVICE_UNAVAILABLE, self::OUTAGE ), true );
	}

	/**
	 * The next 00:00 UTC, when AgeCheck's daily limits reset.
	 */
	private static function next_utc_midnight(): int {
		return ( intdiv( time(), DAY_IN_SECONDS ) + 1 ) * DAY_IN_SECONDS;
	}
}
