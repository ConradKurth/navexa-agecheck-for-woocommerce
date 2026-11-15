<?php
/**
 * GuardToken tests: the HS256 port of Zavro's verify-jwt.ts and
 * scoped-token.ts, and the checkout guard on top of it.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\GuardToken;
use Navexa\AgeCheck\Install;
use Navexa\AgeCheck\Tests\TestCase;

final class GuardTokenTest extends TestCase {

	/**
	 * Zavro's signHs256Jwt({cart_id: "cart_01", scope: "checkout-guard",
	 * agecheck_verification_id: "acv_123"}, "zavro-secret", 1800) at iat
	 * 1700000000, computed with the TypeScript implementation.
	 */
	const ZAVRO_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJjYXJ0X2lkIjoiY2FydF8wMSIsInNjb3BlIjoiY2hlY2tvdXQtZ3VhcmQiLCJhZ2VjaGVja192ZXJpZmljYXRpb25faWQiOiJhY3ZfMTIzIiwiaWF0IjoxNzAwMDAwMDAwLCJleHAiOjE3MDAwMDE4MDB9.8054b8KausUpi5T2i1XrALw29_AZQ-q_TuUTjQfk78Q';

	const NOW = 1700000000;
	const SID = '0123456789abcdef0123456789abcdef';
	const MAC = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		parent::setUp();
		Install::ensure();
	}

	public function test_signs_exactly_like_zavro(): void {
		$token = GuardToken::sign(
			array(
				'cart_id'                  => 'cart_01',
				'scope'                    => 'checkout-guard',
				'agecheck_verification_id' => 'acv_123',
			),
			'zavro-secret',
			1800,
			self::NOW
		);
		$this->assertSame( self::ZAVRO_TOKEN, $token );
	}

	public function test_verifies_a_zavro_token_and_enforces_exp_and_nbf(): void {
		$claims = GuardToken::verify( self::ZAVRO_TOKEN, 'zavro-secret', self::NOW + 1800 );
		$this->assertSame( 'acv_123', $claims['agecheck_verification_id'] );
		$this->assertNull( GuardToken::verify( self::ZAVRO_TOKEN, 'zavro-secret', self::NOW + 1801 ), 'expired' );
		$this->assertNull( GuardToken::verify( self::ZAVRO_TOKEN, 'other-secret', self::NOW ), 'wrong key' );
		$this->assertNull( GuardToken::verify( self::ZAVRO_TOKEN, '', self::NOW ), 'no key verifies nothing' );

		$future = GuardToken::sign( array( 'nbf' => self::NOW + 60 ), 'k', 600, self::NOW );
		$this->assertNull( GuardToken::verify( $future, 'k', self::NOW ) );
		$this->assertNotNull( GuardToken::verify( $future, 'k', self::NOW + 60 ) );
	}

	public function test_refuses_alg_none_other_algs_and_tampering(): void {
		list( $header, $payload, $signature ) = explode( '.', self::ZAVRO_TOKEN );
		$b64 = static function ( $text ) {
			return rtrim( strtr( base64_encode( $text ), '+/', '-_' ), '=' );
		};

		// alg none, with and without a signature.
		$none = $b64( '{"alg":"none","typ":"JWT"}' );
		$this->assertNull( GuardToken::verify( $none . '.' . $payload . '.', 'zavro-secret', self::NOW ) );
		$this->assertNull( GuardToken::verify( $none . '.' . $payload . '.' . $signature, 'zavro-secret', self::NOW ) );

		// HS512 header correctly HMAC-SHA256 signed: still refused (alg allow-list).
		$hs512 = $b64( '{"alg":"HS512","typ":"JWT"}' );
		$sig   = $b64( hash_hmac( 'sha256', $hs512 . '.' . $payload, 'zavro-secret', true ) );
		$this->assertNull( GuardToken::verify( $hs512 . '.' . $payload . '.' . $sig, 'zavro-secret', self::NOW ) );

		// A changed payload.
		$forged = $b64( '{"cart_id":"cart_02","scope":"checkout-guard","iat":1700000000,"exp":1700001800}' );
		$this->assertNull( GuardToken::verify( $header . '.' . $forged . '.' . $signature, 'zavro-secret', self::NOW ) );

		// Shapes.
		foreach ( array( '', 'a.b', 'a.b.c.d', $header . '.' . $payload . '.' . $signature . '=', $header . '.' . $payload . '.' . substr( $signature, 0, -1 ) . 'R', str_repeat( 'a', 5000 ) ) as $bad ) {
			$this->assertNull( GuardToken::verify( $bad, 'zavro-secret', self::NOW ), $bad );
		}
	}

	public function test_requires_integer_exp_and_iat(): void {
		$b64     = static function ( $text ) {
			return rtrim( strtr( base64_encode( $text ), '+/', '-_' ), '=' );
		};
		$header  = $b64( '{"alg":"HS256","typ":"JWT"}' );
		$payload = $b64( '{"scope":"checkout-guard"}' );
		$sig     = $b64( hash_hmac( 'sha256', $header . '.' . $payload, 'k', true ) );
		$this->assertNull( GuardToken::verify( $header . '.' . $payload . '.' . $sig, 'k', self::NOW ), 'Zavro accepts a token without exp; the port does not' );
	}

	public function test_sign_refuses_without_a_key_or_lifetime(): void {
		$this->expectException( \InvalidArgumentException::class );
		GuardToken::sign( array(), '', 60 );
	}

	public function test_checkout_guard_is_bound_to_scope_session_and_ship_to(): void {
		$token = GuardToken::mint_checkout_guard( self::SID, 'acv_1', self::MAC, self::NOW );
		$this->assertSame( 'acv_1', GuardToken::read_checkout_guard( $token, self::SID, self::MAC, self::NOW ) );
		$this->assertSame( 'acv_1', GuardToken::read_checkout_guard( $token, self::SID, self::MAC, self::NOW + 1800 ) );
		$this->assertNull( GuardToken::read_checkout_guard( $token, self::SID, self::MAC, self::NOW + 1801 ), '30 minutes' );
		$this->assertNull( GuardToken::read_checkout_guard( $token, 'ffffffffffffffffffffffffffffffff', self::MAC, self::NOW ), 'another session' );
		$this->assertNull( GuardToken::read_checkout_guard( $token, self::SID, str_repeat( 'b', 64 ), self::NOW ), 'another ship-to' );
		$this->assertNull( GuardToken::read_checkout_guard( $token, '', self::MAC, self::NOW ) );

		$claims = GuardToken::verify( $token, hash_hmac( 'sha256', 'navexa-agecheck/checkout-guard/v1', Install::guard_secret(), true ), self::NOW );
		$this->assertSame( array( 'scope', 'sid', 'vid', 'shipto_mac', 'iat', 'exp' ), array_keys( $claims ) );
		$this->assertSame( self::NOW + 1800, $claims['exp'] );
	}

	public function test_a_token_of_another_scope_or_key_is_not_a_guard(): void {
		$key   = hash_hmac( 'sha256', 'navexa-agecheck/checkout-guard/v1', Install::guard_secret(), true );
		$other = GuardToken::sign(
			array(
				'scope'      => 'order-retrieve',
				'sid'        => self::SID,
				'vid'        => 'acv_1',
				'shipto_mac' => self::MAC,
			),
			$key,
			600,
			self::NOW
		);
		$this->assertNull( GuardToken::read_checkout_guard( $other, self::SID, self::MAC, self::NOW ) );

		$raw = GuardToken::sign(
			array(
				'scope'      => 'checkout-guard',
				'sid'        => self::SID,
				'vid'        => 'acv_1',
				'shipto_mac' => self::MAC,
			),
			Install::guard_secret(),
			600,
			self::NOW
		);
		$this->assertNull( GuardToken::read_checkout_guard( $raw, self::SID, self::MAC, self::NOW ), 'signed with the raw secret, not the derived key' );

		$bad_vid = GuardToken::sign(
			array(
				'scope'      => 'checkout-guard',
				'sid'        => self::SID,
				'vid'        => 'x_1',
				'shipto_mac' => self::MAC,
			),
			$key,
			600,
			self::NOW
		);
		$this->assertNull( GuardToken::read_checkout_guard( $bad_vid, self::SID, self::MAC, self::NOW ) );
	}

	public function test_no_secret_mints_nothing_and_a_rotated_secret_voids_guards(): void {
		$token = GuardToken::mint_checkout_guard( self::SID, 'acv_1', self::MAC, self::NOW );
		$this->home = 'https://moved.example';
		Install::confirm_site();
		$this->assertNull( GuardToken::read_checkout_guard( $token, self::SID, self::MAC, self::NOW ) );

		unset( $this->options[ Install::OPTION_GUARD_SECRET ] );
		$this->assertSame( '', GuardToken::mint_checkout_guard( self::SID, 'acv_1', self::MAC, self::NOW ) );
		$this->assertNull( GuardToken::read_checkout_guard( $token, self::SID, self::MAC, self::NOW ) );
		$this->assertSame( '', GuardToken::mint_checkout_guard( self::SID, '', self::MAC, self::NOW ) );
	}
}
