<?php
/**
 * Live or test mode, read from GetIntegrationInfo.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Navexa\AgeCheck\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Every AgeCheck key looks like txk_..., so the prefix cannot tell a test key
 * from a live one. GetIntegrationInfo (E10a, in production since tax
 * v0.0.164; any API key with scope agecheck:verify) answers {livemode,
 * scopes, origin_allowed, minimum_age, display_name, organization_id} for
 * the key and the store's origin. The answer is cached for an hour per key;
 * a failure is cached for a minute so an outage does not cost every request
 * an 8-second timeout. The last livemode seen for each key is also kept in a
 * non-expiring option, for the TEST MODE banner only: the gate never reads it.
 * The minimum age and display name are kept the same way
 * (Options::STORE_PROFILE) for storefront copy: the entry gate's front-end
 * pages never call AgeCheck.
 *
 * Fail closed: when the answer is unavailable, info() is null, the gate
 * accepts no verification and nothing is auto-released.
 *
 * A live key admits only livemode=true verifications and clearances. A test
 * key admits only livemode=false ones, shows a TEST MODE banner, and never
 * auto-releases a held order unless wp-config.php defines
 * NAVEXA_AGECHECK_ALLOW_TEST_RELEASE (CI and staging), so a simulator pass
 * can never ship a real paid order.
 */
final class Mode {

	const RPC              = 'GetIntegrationInfo';
	const CACHE_TTL        = 3600;
	const FAILURE_TTL      = 60;
	const TRANSIENT_PREFIX = 'navexa_agecheck_info_';

	/**
	 * Prefix of the per-key-kind option holding the last livemode AgeCheck
	 * reported: {key: Client::key_fingerprint(), livemode: bool}.
	 */
	const OPTION_LAST_MODE_PREFIX = 'navexa_agecheck_last_mode_';

	/**
	 * Registers the TEST MODE admin banner.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'test_mode_notice' ) );
	}

	/**
	 * GetIntegrationInfo for the $key_kind key, or null when there is no key or
	 * the answer is unavailable.
	 *
	 * @param string      $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 * @param Client|null $client   Client to use; null for the stored key.
	 */
	public static function info( string $key_kind = Client::KEY_FULFILMENT, ?Client $client = null ): ?IntegrationInfo {
		return self::lookup( $key_kind, $client )['info'];
	}

	/**
	 * GetIntegrationInfo for the $key_kind key with the reason when it is
	 * missing: 'no_key', a Connect code, or a ConnectError::REASON_* constant.
	 *
	 * @param string      $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 * @param Client|null $client   Client to use; null for the stored key.
	 * @param bool        $refresh  Ask AgeCheck even when an answer is cached, and
	 *                              cache the new one (settings screen, so a fix
	 *                              made in the Navexa dashboard shows at once).
	 * @return array{info: IntegrationInfo|null, error: string}
	 */
	public static function lookup( string $key_kind, ?Client $client = null, bool $refresh = false ): array {
		$client = null === $client ? Client::for_key( $key_kind ) : $client;
		if ( ! $client->has_key() ) {
			return array(
				'info'  => null,
				'error' => 'no_key',
			);
		}

		$name   = self::transient_name( $client );
		$cached = $refresh ? false : get_transient( $name );
		if ( is_array( $cached ) ) {
			if ( ! empty( $cached['ok'] ) && isset( $cached['info'] ) && is_array( $cached['info'] ) ) {
				return array(
					'info'  => IntegrationInfo::from_array( $cached['info'] ),
					'error' => '',
				);
			}
			return array(
				'info'  => null,
				'error' => isset( $cached['error'] ) && is_string( $cached['error'] ) ? $cached['error'] : 'unknown',
			);
		}

		try {
			$body = $client->call( self::RPC, array( 'origin' => self::store_origin() ) );
		} catch ( ConnectError $e ) {
			$error = '' === $e->local_reason() ? $e->connect_code() : $e->local_reason();
			if ( '' === $e->local_reason() || ConnectError::REASON_UNREADABLE_RESPONSE === $e->local_reason() ) {
				set_transient(
					$name,
					array(
						'ok'    => false,
						'error' => $error,
					),
					self::FAILURE_TTL
				);
			}
			return array(
				'info'  => null,
				'error' => $error,
			);
		}

		$info = IntegrationInfo::from_response( $body );
		set_transient(
			$name,
			array(
				'ok'   => true,
				'info' => $info->to_array(),
			),
			self::CACHE_TTL
		);
		$last = array(
			'key'      => $client->key_fingerprint(),
			'livemode' => $info->livemode,
		);
		// Autoloaded: the banner reads it on every admin screen. update_option()
		// skips the write when the value did not change.
		update_option( self::OPTION_LAST_MODE_PREFIX . $client->key_kind(), $last, true );
		// The storefront's copy (the entry gate) reads these on front-end pages,
		// which never ask AgeCheck themselves.
		update_option(
			Options::STORE_PROFILE,
			array(
				'minimum_age'   => $info->minimum_age,
				'display_name'  => $info->display_name,
				'links_enabled' => $info->links_enabled,
			),
			true
		);
		return array(
			'info'  => $info,
			'error' => '',
		);
	}

	/**
	 * Whether an answer from AgeCheck (a verification or a clearance) with
	 * $answer_livemode may be acted on with the $key_kind key: a live key needs
	 * livemode=true, a test key livemode=false. False when the key's mode is
	 * unknown.
	 *
	 * @param bool                 $answer_livemode The answer's livemode.
	 * @param string               $key_kind        Key that will act on it.
	 * @param IntegrationInfo|null $info            The key's info; null to look it up.
	 */
	public static function accepts_livemode( bool $answer_livemode, string $key_kind = Client::KEY_FULFILMENT, ?IntegrationInfo $info = null ): bool {
		$info = null === $info ? self::info( $key_kind ) : $info;
		return null !== $info && $info->livemode === $answer_livemode;
	}

	/**
	 * Whether a held order may be released automatically at all: always with a
	 * live fulfilment key, with a test key only when wp-config.php defines
	 * NAVEXA_AGECHECK_ALLOW_TEST_RELEASE as true. The clearance's own livemode
	 * must still pass accepts_livemode().
	 *
	 * @param IntegrationInfo|null $info Fulfilment key info; null to look it up.
	 */
	public static function may_auto_release( ?IntegrationInfo $info = null ): bool {
		$info = null === $info ? self::info( Client::KEY_FULFILMENT ) : $info;
		if ( null === $info ) {
			return false;
		}
		if ( $info->livemode ) {
			return true;
		}
		return self::test_release_allowed();
	}

	/**
	 * Whether wp-config.php lets test keys release held orders
	 * (NAVEXA_AGECHECK_ALLOW_TEST_RELEASE defined as true).
	 */
	public static function test_release_allowed(): bool {
		return defined( 'NAVEXA_AGECHECK_ALLOW_TEST_RELEASE' ) && true === NAVEXA_AGECHECK_ALLOW_TEST_RELEASE;
	}

	/**
	 * Whether AgeCheck last reported a configured key as a test key. Reads the
	 * stored last livemode, never the network, so the banner stays up after the
	 * cache expires and during an outage. An entry recorded for another key
	 * (the key was replaced) is ignored.
	 */
	public static function is_test_mode(): bool {
		foreach ( array( Client::KEY_START, Client::KEY_FULFILMENT ) as $kind ) {
			$client = Client::for_key( $kind );
			if ( ! $client->has_key() ) {
				continue;
			}
			$last = get_option( self::OPTION_LAST_MODE_PREFIX . $kind, array() );
			if ( is_array( $last ) && isset( $last['key'] ) && $client->key_fingerprint() === $last['key'] && isset( $last['livemode'] ) && false === $last['livemode'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The store's origin as a browser sends it: scheme://host[:port] of
	 * Install::home() (never the filtered home_url()), host lower-cased. ''
	 * when the address has no host.
	 */
	public static function store_origin(): string {
		$parts = wp_parse_url( Install::home() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port;
	}

	/**
	 * Drops the cached answers, the last known modes for the stored keys and
	 * the stored minimum age and display name (the next answer stores them
	 * again; until then the storefront uses its defaults).
	 */
	public static function flush(): void {
		foreach ( array( Client::KEY_START, Client::KEY_FULFILMENT ) as $kind ) {
			delete_transient( self::transient_name( Client::for_key( $kind ) ) );
			delete_option( self::OPTION_LAST_MODE_PREFIX . $kind );
		}
		delete_option( Options::STORE_PROFILE );
	}

	/**
	 * TEST MODE notice while a key is a test key: always on this plugin's
	 * settings tab, and on other WooCommerce screens until the staff member
	 * dismisses it for these keys. Reads stored state only, so it never adds
	 * a network call to an admin page.
	 */
	public static function test_mode_notice(): void {
		if ( ! Options::verification_enabled() || ! current_user_can( 'manage_woocommerce' ) || ! self::is_test_mode() ) {
			return;
		}
		$settings = Notices::on_settings_tab();
		$reason   = Notices::signature( Client::for_key( Client::KEY_START )->key_fingerprint() . '|' . Client::for_key( Client::KEY_FULFILMENT )->key_fingerprint() );
		if ( ! $settings && ( ! Notices::on_store_screen() || Notices::is_dismissed( 'test', $reason ) ) ) {
			return;
		}
		echo '<div class="notice notice-warning' . ( $settings ? ' is-dismissible' : '' ) . '"><p><strong>';
		esc_html_e( 'AgeCheck TEST MODE', 'navexa-agecheck-for-woocommerce' );
		echo '</strong> ';
		if ( self::test_release_allowed() ) {
			esc_html_e( 'Your Navexa AgeCheck keys are test keys: checks use the simulator and no real age check happens. NAVEXA_AGECHECK_ALLOW_TEST_RELEASE is set in wp-config.php, so held orders whose test check passes are released automatically. Switch to live keys before selling age-restricted products.', 'navexa-agecheck-for-woocommerce' );
		} else {
			esc_html_e( 'Your Navexa AgeCheck keys are test keys: checks use the simulator, no real age check happens, and held orders are not released automatically. Switch to live keys before selling age-restricted products.', 'navexa-agecheck-for-woocommerce' );
		}
		echo '</p>';
		if ( ! $settings ) {
			echo Notices::dismiss_link( 'test', $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dismiss_link() escapes.
		}
		echo '</div>';
	}

	/**
	 * Transient name for $client's key, base URL and the store origin.
	 *
	 * @param Client $client Client.
	 */
	private static function transient_name( Client $client ): string {
		$origin = substr( hash( 'sha256', self::store_origin() ), 0, 8 );
		return self::TRANSIENT_PREFIX . $client->key_kind() . '_' . $client->key_fingerprint() . '_' . $origin;
	}
}
