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
 * origin_allowed, minimum_age, display_name, organization_id,
 * links_enabled}, and (TAX-1839) the store's reuse: {reuse_window_days,
 * guest_reuse_code}.
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
	 * The longest reuse window AgeCheck takes, in days (MaxReuseWindowDays).
	 */
	const MAX_REUSE_WINDOW_DAYS = 365;

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
	 * Whether AgeCheck offers the document retry to a verification created
	 * with allowDocRetry (TAX-1838): a store-level read.
	 *
	 * @var bool
	 */
	public $doc_retry = false;

	/**
	 * The store's per-store reuse window in days while AgeCheck has reuse on
	 * for it, else 0 (also for a missing or unexpected value). For the
	 * settings status line only: whether a guest's email is sent is
	 * $guest_reuse_code.
	 *
	 * @var int
	 */
	public $reuse_window_days = 0;

	/**
	 * Whether AgeCheck offers a returning guest an emailed code (reuse on and
	 * a store name set; the engine decides). Only while it is true does a
	 * guest start send the billing email. False when missing, as in a 1.0.3
	 * cache entry.
	 *
	 * @var bool
	 */
	public $guest_reuse_code = false;

	/**
	 * Whether AgeCheck would email the store's shoppers a verification link
	 * now (TAX-1841): links are on at AgeCheck and the org has a display
	 * name. False when absent, so an engine before the field reads off.
	 *
	 * @var bool
	 */
	public $links_enabled = false;

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

		$info->doc_retry = true === self::field( $body, 'docRetry', 'doc_retry' );

		$days = self::field( $body, 'reuseWindowDays', 'reuse_window_days' );
		if ( is_int( $days ) || ( is_string( $days ) && ctype_digit( $days ) ) ) {
			$days = (int) $days;
			if ( $days >= 0 && $days <= self::MAX_REUSE_WINDOW_DAYS ) {
				$info->reuse_window_days = $days;
			}
		}

		$info->guest_reuse_code = true === self::field( $body, 'guestReuseCode', 'guest_reuse_code' );

		$info->links_enabled = true === self::field( $body, 'linksEnabled', 'links_enabled' );
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
				'livemode'          => isset( $data['livemode'] ) ? $data['livemode'] : false,
				'scopes'            => isset( $data['scopes'] ) ? $data['scopes'] : array(),
				'origin_allowed'    => isset( $data['origin_allowed'] ) ? $data['origin_allowed'] : false,
				'minimum_age'       => isset( $data['minimum_age'] ) ? $data['minimum_age'] : null,
				'display_name'      => isset( $data['display_name'] ) ? $data['display_name'] : '',
				'organization_id'   => isset( $data['organization_id'] ) ? $data['organization_id'] : '',
				'doc_retry'         => isset( $data['doc_retry'] ) ? $data['doc_retry'] : false,
				'reuse_window_days' => isset( $data['reuse_window_days'] ) ? $data['reuse_window_days'] : 0,
				'guest_reuse_code'  => isset( $data['guest_reuse_code'] ) ? $data['guest_reuse_code'] : false,
				'links_enabled'     => isset( $data['links_enabled'] ) ? $data['links_enabled'] : false,
			)
		);
	}

	/**
	 * Plain array for caching.
	 *
	 * @return array{livemode: bool, scopes: string[], origin_allowed: bool, minimum_age: int|null, display_name: string, organization_id: string, doc_retry: bool, reuse_window_days: int, guest_reuse_code: bool, links_enabled: bool}
	 */
	public function to_array(): array {
		return array(
			'livemode'          => $this->livemode,
			'scopes'            => $this->scopes,
			'origin_allowed'    => $this->origin_allowed,
			'minimum_age'       => $this->minimum_age,
			'display_name'      => $this->display_name,
			'organization_id'   => $this->organization_id,
			'doc_retry'         => $this->doc_retry,
			'reuse_window_days' => $this->reuse_window_days,
			'guest_reuse_code'  => $this->guest_reuse_code,
			'links_enabled'     => $this->links_enabled,
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
