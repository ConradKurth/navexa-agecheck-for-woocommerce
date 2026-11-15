<?php
/**
 * Checkout guard token (HS256 JWT).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Port of Zavro's scoped capability tokens (lib/scoped-token.ts) over its
 * dependency-free HS256 JWT (lib/verify-jwt.ts):
 *
 * - the algorithm is allow-listed to HS256 and the header's own alg is never
 *   trusted beyond that check, so "alg: none" and alg confusion fail;
 * - the signature is compared in constant time before anything is decoded;
 * - exp and nbf are enforced;
 * - each token kind is scope-locked and bound to one id.
 *
 * Three things are stricter than the TypeScript: base64url is decoded
 * strictly (no padding, no stray characters), exp and iat are required, and
 * the signing key is derived per purpose from the install's guard secret
 * instead of being a shared secret.
 *
 * The checkout guard is {scope: "checkout-guard", sid, vid, shipto_mac, iat,
 * exp: iat + 1800}. Start mints it only after GetVerification answered
 * PASSED with a clearing pass class; checkout re-reads the verification
 * anyway. It lives in the shopper's WooCommerce session, never in the page.
 */
final class GuardToken {

	const SCOPE_CHECKOUT_GUARD = 'checkout-guard';

	/**
	 * Lifetime of a checkout guard: 30 minutes, which covers a payment
	 * redirect (Zavro's checkoutGuardToken).
	 */
	const CHECKOUT_GUARD_TTL = 1800;

	/**
	 * Longest token verify() reads.
	 */
	const MAX_TOKEN_LENGTH = 4096;

	/**
	 * Signs $payload plus iat and exp (iat + $ttl) as an HS256 JWT.
	 *
	 * @param array<string, mixed> $payload Claims; iat and exp are overwritten.
	 * @param string               $key     Signing key (raw bytes, not empty).
	 * @param int                  $ttl     Lifetime in seconds (> 0).
	 * @param int|null             $now     Unix time; null for time().
	 * @throws \InvalidArgumentException Without a key or a positive lifetime.
	 */
	public static function sign( array $payload, string $key, int $ttl, ?int $now = null ): string {
		if ( '' === $key || $ttl <= 0 ) {
			throw new \InvalidArgumentException( 'guard token: a key and a positive lifetime are required' );
		}
		$iat            = null === $now ? time() : $now;
		$payload['iat'] = $iat;
		$payload['exp'] = $iat + $ttl;

		$header = self::b64url_encode( '{"alg":"HS256","typ":"JWT"}' );
		$body   = self::b64url_encode( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$mac    = hash_hmac( 'sha256', $header . '.' . $body, $key, true );
		return $header . '.' . $body . '.' . self::b64url_encode( $mac );
	}

	/**
	 * The claims of a token signed with $key: HS256, a matching signature,
	 * an integer exp not in the past and no nbf in the future. Null otherwise.
	 *
	 * @param string   $token Token.
	 * @param string   $key   Signing key ('' verifies nothing).
	 * @param int|null $now   Unix time; null for time().
	 * @return array<string, mixed>|null
	 */
	public static function verify( string $token, string $key, ?int $now = null ): ?array {
		if ( '' === $token || '' === $key || strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			return null;
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

		$provided = self::b64url_decode( $signature_b64 );
		$expected = hash_hmac( 'sha256', $header_b64 . '.' . $payload_b64, $key, true );
		if ( null === $provided || ! hash_equals( $expected, $provided ) ) {
			return null;
		}

		$header_json  = self::b64url_decode( $header_b64 );
		$payload_json = self::b64url_decode( $payload_b64 );
		if ( null === $header_json || null === $payload_json ) {
			return null;
		}
		$header  = json_decode( $header_json, true );
		$payload = json_decode( $payload_json, true );
		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			return null;
		}
		// Only HS256: never trust the token's self-declared algorithm beyond this.
		if ( ! isset( $header['alg'] ) || 'HS256' !== $header['alg'] ) {
			return null;
		}

		$now = null === $now ? time() : $now;
		if ( ! isset( $payload['exp'], $payload['iat'] ) || ! is_int( $payload['exp'] ) || ! is_int( $payload['iat'] ) ) {
			return null;
		}
		if ( $now > $payload['exp'] ) {
			return null;
		}
		if ( isset( $payload['nbf'] ) && ( ! is_int( $payload['nbf'] ) || $now < $payload['nbf'] ) ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Mints the checkout guard for session $sid, verification $vid and the
	 * ship-to with MAC $shipto_mac. '' when the install has no guard secret.
	 *
	 * @param string   $sid        The session's AgeCheck id.
	 * @param string   $vid        Verification id (acv_...).
	 * @param string   $shipto_mac ShipTo::mac() of the verified ship-to.
	 * @param int|null $now        Unix time; null for time().
	 */
	public static function mint_checkout_guard( string $sid, string $vid, string $shipto_mac, ?int $now = null ): string {
		$key = self::checkout_guard_key();
		if ( '' === $key || '' === $sid || '' === $vid || '' === $shipto_mac ) {
			return '';
		}
		return self::sign(
			array(
				'scope'      => self::SCOPE_CHECKOUT_GUARD,
				'sid'        => $sid,
				'vid'        => $vid,
				'shipto_mac' => $shipto_mac,
			),
			$key,
			self::CHECKOUT_GUARD_TTL,
			$now
		);
	}

	/**
	 * The verification id of a valid, unexpired checkout guard minted for
	 * session $sid and the ship-to with MAC $shipto_mac; null otherwise.
	 *
	 * @param string   $token      Token.
	 * @param string   $sid        The session's AgeCheck id.
	 * @param string   $shipto_mac ShipTo::mac() of the ship-to being checked out.
	 * @param int|null $now        Unix time; null for time().
	 */
	public static function read_checkout_guard( string $token, string $sid, string $shipto_mac, ?int $now = null ): ?string {
		if ( '' === $sid || '' === $shipto_mac ) {
			return null;
		}
		$claims = self::verify( $token, self::checkout_guard_key(), $now );
		if (
			null === $claims
			|| ! isset( $claims['scope'], $claims['sid'], $claims['vid'], $claims['shipto_mac'] )
			|| self::SCOPE_CHECKOUT_GUARD !== $claims['scope']
			|| ! is_string( $claims['sid'] ) || ! hash_equals( $sid, $claims['sid'] )
			|| ! is_string( $claims['shipto_mac'] ) || ! hash_equals( $shipto_mac, $claims['shipto_mac'] )
			|| ! is_string( $claims['vid'] ) || 0 !== strpos( $claims['vid'], 'acv_' )
		) {
			return null;
		}
		return $claims['vid'];
	}

	/**
	 * The checkout guard's signing key, derived from the install's guard
	 * secret; '' when there is none.
	 */
	private static function checkout_guard_key(): string {
		$secret = Install::guard_secret();
		return '' === $secret ? '' : hash_hmac( 'sha256', 'navexa-agecheck/checkout-guard/v1', $secret, true );
	}

	/**
	 * Unpadded base64url.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function b64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
	}

	/**
	 * Strict unpadded base64url decode; null on any other input.
	 *
	 * @param string $text Encoded text.
	 */
	private static function b64url_decode( string $text ): ?string {
		if ( '' === $text || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $text ) || 1 === strlen( $text ) % 4 ) {
			return null;
		}
		$bytes = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT decoding.
		if ( false === $bytes || self::b64url_encode( $bytes ) !== $text ) {
			return null; // Non-canonical trailing bits.
		}
		return $bytes;
	}
}
