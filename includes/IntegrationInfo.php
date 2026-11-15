<?php
/**
 * GetIntegrationInfo answer.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * What GetIntegrationInfo (E10a, in production since tax v0.0.164) says
 * about one API key and the store's origin: {livemode, scopes,
 * origin_allowed, minimum_age, display_name, organization_id}.
 *
 * Proto3 JSON omits zero values, so a missing field reads as false, [], 0 or
 * ''. A missing livemode therefore reads as test mode, which the live gate
 * refuses (fail closed).
 */
final class IntegrationInfo {

	const SCOPE_VERIFY    = 'agecheck:verify';
	const SCOPE_CLEARANCE = 'agecheck:clearance';

	/**
	 * Minimum ages an org can be set to.
	 */
	const MINIMUM_AGES = array( 18, 19, 21 );

	/**
	 * Whether the key is a live key.
	 *
	 * @var bool
	 */
	public $livemode = false;

	/**
	 * The key's scopes.
	 *
	 * @var string[]
	 */
	public $scopes = array();

	/**
	 * Whether the store's origin is in the org's AllowedOrigins.
	 *
	 * @var bool
	 */
	public $origin_allowed = false;

	/**
	 * The org's minimum age (18, 19 or 21), or null when absent or unexpected.
	 *
	 * @var int|null
	 */
	public $minimum_age = null;

	/**
	 * The org's display name, or ''.
	 *
	 * @var string
	 */
	public $display_name = '';

	/**
	 * The key's Navexa organization (the organization_id every webhook payload
	 * carries), or ''. Two keys of one organization answer the same id.
	 *
	 * @var string
	 */
	public $organization_id = '';

	/**
	 * Builds the value from a decoded response. Accepts proto3 JSON names
	 * (originAllowed) and the original field names (origin_allowed).
	 *
	 * @param array<string, mixed> $body Decoded GetIntegrationInfo response.
	 */
	public static function from_response( array $body ): self {
		$info           = new self();
		$info->livemode = true === self::field( $body, 'livemode', 'livemode' );

		$scopes = self::field( $body, 'scopes', 'scopes' );
		if ( is_array( $scopes ) ) {
			$info->scopes = array_values( array_unique( array_filter( $scopes, 'is_string' ) ) );
		}

		$info->origin_allowed = true === self::field( $body, 'originAllowed', 'origin_allowed' );

		$age = self::field( $body, 'minimumAge', 'minimum_age' );
		if ( is_int( $age ) || ( is_string( $age ) && ctype_digit( $age ) ) ) {
			$age = (int) $age;
			if ( in_array( $age, self::MINIMUM_AGES, true ) ) {
				$info->minimum_age = $age;
			}
		}

		$name = self::field( $body, 'displayName', 'display_name' );
		if ( is_string( $name ) ) {
			$info->display_name = $name;
		}

		$org = self::field( $body, 'organizationId', 'organization_id' );
		if ( is_string( $org ) ) {
			$info->organization_id = $org;
		}
		return $info;
	}

	/**
	 * Rebuilds the value from to_array() output (the transient cache).
	 *
	 * @param array<string, mixed> $data to_array() output.
	 */
	public static function from_array( array $data ): self {
		return self::from_response(
			array(
				'livemode'        => isset( $data['livemode'] ) ? $data['livemode'] : false,
				'scopes'          => isset( $data['scopes'] ) ? $data['scopes'] : array(),
				'origin_allowed'  => isset( $data['origin_allowed'] ) ? $data['origin_allowed'] : false,
				'minimum_age'     => isset( $data['minimum_age'] ) ? $data['minimum_age'] : null,
				'display_name'    => isset( $data['display_name'] ) ? $data['display_name'] : '',
				'organization_id' => isset( $data['organization_id'] ) ? $data['organization_id'] : '',
			)
		);
	}

	/**
	 * Plain array for caching.
	 *
	 * @return array{livemode: bool, scopes: string[], origin_allowed: bool, minimum_age: int|null, display_name: string, organization_id: string}
	 */
	public function to_array(): array {
		return array(
			'livemode'        => $this->livemode,
			'scopes'          => $this->scopes,
			'origin_allowed'  => $this->origin_allowed,
			'minimum_age'     => $this->minimum_age,
			'display_name'    => $this->display_name,
			'organization_id' => $this->organization_id,
		);
	}

	/**
	 * Whether the key holds $scope.
	 *
	 * @param string $scope Scope, e.g. agecheck:clearance.
	 */
	public function has_scope( string $scope ): bool {
		return in_array( $scope, $this->scopes, true );
	}

	/**
	 * Scopes a key of $key_kind needs that this key lacks.
	 *
	 * @param string $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 * @return string[]
	 */
	public function missing_scopes( string $key_kind ): array {
		$needed = Client::KEY_START === $key_kind
			? array( self::SCOPE_VERIFY )
			: array( self::SCOPE_VERIFY, self::SCOPE_CLEARANCE );
		return array_values( array_diff( $needed, $this->scopes ) );
	}

	/**
	 * Reads $json_name, falling back to $proto_name.
	 *
	 * @param array<string, mixed> $body       Decoded response.
	 * @param string               $json_name  Proto3 JSON (lowerCamelCase) name.
	 * @param string               $proto_name Proto field name.
	 * @return mixed
	 */
	private static function field( array $body, string $json_name, string $proto_name ) {
		if ( array_key_exists( $json_name, $body ) ) {
			return $body[ $json_name ];
		}
		return array_key_exists( $proto_name, $body ) ? $body[ $proto_name ] : null;
	}
}
