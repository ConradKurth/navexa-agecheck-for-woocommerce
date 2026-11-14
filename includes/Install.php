<?php
/**
 * Activation state and clone detection.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the per-install identity: install_id, the guard secret, activated_at
 * and the site address fingerprint.
 *
 * The install_id namespaces every order_external_id
 * (wc:{install_id}:{blog_id}:{order_id}) and idempotency key the plugin sends.
 * A copied database (a staging clone, a migration) carries the same install_id
 * and API keys, so two sites would send colliding ids to one AgeCheck org.
 * When the site address (home()) no longer matches the fingerprint stored at
 * activation, every AgeCheck call is refused (Client::call) until an
 * administrator confirms the site, which rotates the install_id and the guard
 * secret. Orders bound before the rotation keep the external id stored in
 * their meta; it is never recomputed.
 */
final class Install {

	const OPTION_INSTALL_ID       = 'navexa_agecheck_install_id';
	const OPTION_GUARD_SECRET     = 'navexa_agecheck_guard_secret';
	const OPTION_ACTIVATED_AT     = 'navexa_agecheck_activated_at';
	const OPTION_HOME_FINGERPRINT = 'navexa_agecheck_home_fingerprint';
	const OPTION_VERSION          = 'navexa_agecheck_version';

	/**
	 * The admin-post action (and nonce action) of the "Confirm this site" form.
	 */
	const CONFIRM_ACTION = 'navexa_agecheck_confirm_site';

	/**
	 * Registers the lazy initializer, the paused notice and the confirm handler.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'ensure' ) );
		add_action( 'admin_notices', array( self::class, 'paused_notice' ) );
		add_action( 'admin_post_' . self::CONFIRM_ACTION, array( self::class, 'handle_confirm' ) );
	}

	/**
	 * Activation hook.
	 *
	 * On a network activation only the current site is initialized here; the
	 * others initialize on their first request through ensure().
	 *
	 * @param bool $network_wide Whether the plugin was network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		unset( $network_wide );
		self::ensure();
	}

	/**
	 * Creates the install state if it does not exist yet. Never overwrites it:
	 * re-activating the plugin keeps the install_id, the guard secret and the
	 * first activated_at, so orders created while the plugin was inactive are
	 * still checked when they change status.
	 *
	 * WordPress's add_option() is not atomic (a read, then INSERT ... ON
	 * DUPLICATE KEY UPDATE), so two concurrent first requests can both write;
	 * the last value wins. Nothing has used these values yet at that point.
	 */
	public static function ensure(): void {
		if ( '' !== self::install_id() ) {
			return;
		}
		add_option( self::OPTION_INSTALL_ID, self::random_hex( 16 ), '', true );
		add_option( self::OPTION_GUARD_SECRET, self::random_hex( 32 ), '', false );
		add_option( self::OPTION_ACTIVATED_AT, time(), '', true );
		add_option( self::OPTION_HOME_FINGERPRINT, self::home_fingerprint(), '', true );
		add_option( self::OPTION_VERSION, NAVEXA_AGECHECK_VERSION, '', true );
	}

	/**
	 * The random 128-bit install id (32 lower-case hex characters), or '' when
	 * the plugin has not been initialized.
	 */
	public static function install_id(): string {
		$id = get_option( self::OPTION_INSTALL_ID, '' );
		return is_string( $id ) && preg_match( '/^[0-9a-f]{32}$/', $id ) ? $id : '';
	}

	/**
	 * The 32-byte guard-token secret as raw bytes, or '' when missing. Callers
	 * that sign or verify with it must fail closed on ''.
	 */
	public static function guard_secret(): string {
		$hex = get_option( self::OPTION_GUARD_SECRET, '' );
		if ( ! is_string( $hex ) || ! preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
			return '';
		}
		$raw = hex2bin( $hex );
		return false === $raw ? '' : $raw;
	}

	/**
	 * Unix time of the first activation, or 0 when unknown.
	 */
	public static function activated_at(): int {
		return max( 0, (int) get_option( self::OPTION_ACTIVATED_AT, 0 ) );
	}

	/**
	 * This site's address as stored in the home option (WP_HOME when
	 * wp-config.php defines it). Never home_url(): multilingual plugins
	 * (TranslatePress, WPML) filter it per request to add a language path or
	 * domain on front-end requests only, which would pause checkout while the
	 * admin screens look fine. A copied site changes the option itself.
	 */
	public static function home(): string {
		$home = get_option( 'home', '' );
		return is_string( $home ) ? $home : '';
	}

	/**
	 * Fingerprint of this site's address: SHA-256 over the blog id and home()'s
	 * host, port and path, lower-cased. The scheme is left out so an http to
	 * https move does not pause the plugin.
	 */
	public static function home_fingerprint(): string {
		$parts = wp_parse_url( self::home() );
		$parts = is_array( $parts ) ? $parts : array();
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path  = isset( $parts['path'] ) ? strtolower( rtrim( (string) $parts['path'], '/' ) ) : '';
		return hash( 'sha256', 'navexa-agecheck-home-v1|' . get_current_blog_id() . '|' . $host . $port . $path );
	}

	/**
	 * Whether AgeCheck calls are paused: the install state is missing, or the
	 * site address changed since activation (or since the last confirmation).
	 * Fails closed: a missing or unreadable fingerprint pauses.
	 */
	public static function is_paused(): bool {
		$stored = get_option( self::OPTION_HOME_FINGERPRINT, '' );
		if ( '' === self::install_id() || ! is_string( $stored ) || '' === $stored ) {
			return true;
		}
		return ! hash_equals( $stored, self::home_fingerprint() );
	}

	/**
	 * Confirms this site after an address change: rotates the install_id and
	 * the guard secret, stores the new fingerprint and drops cached connection
	 * info. Does nothing when the plugin is not paused, so a replayed form
	 * cannot rotate a live site's install_id.
	 *
	 * @return bool Whether the site was confirmed (and the ids rotated).
	 */
	public static function confirm_site(): bool {
		if ( ! self::is_paused() ) {
			return false;
		}
		update_option( self::OPTION_INSTALL_ID, self::random_hex( 16 ), true );
		update_option( self::OPTION_GUARD_SECRET, self::random_hex( 32 ), false );
		add_option( self::OPTION_ACTIVATED_AT, time(), '', true );
		update_option( self::OPTION_HOME_FINGERPRINT, self::home_fingerprint(), true );
		Mode::flush();
		return true;
	}

	/**
	 * Handles the "Confirm this site" form (admin-post.php).
	 */
	public static function handle_confirm(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change AgeCheck settings.', 'navexa-agecheck-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CONFIRM_ACTION );
		self::confirm_site();
		wp_safe_redirect( Plugin::settings_url() );
		exit;
	}

	/**
	 * Blocking notice while paused. It is not dismissible because AgeCheck
	 * refuses every check until it is resolved, and it disappears as soon as
	 * the site is confirmed.
	 */
	public static function paused_notice(): void {
		if ( ! self::is_paused() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>';
		esc_html_e( 'Navexa AgeCheck is paused: confirm this site.', 'navexa-agecheck-for-woocommerce' );
		echo '</strong></p><p>';
		esc_html_e( 'This site\'s address changed since AgeCheck was set up, which usually means the site was copied or moved. Until you confirm it, every age check is refused and age-restricted orders cannot be completed.', 'navexa-agecheck-for-woocommerce' );
		echo '</p><p>';
		esc_html_e( 'If this is a staging copy, replace the API keys with test keys before you confirm. Confirming gives this site a new install id; orders already linked to AgeCheck keep theirs.', 'navexa-agecheck-for-woocommerce' );
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CONFIRM_ACTION ) . '" />';
		wp_nonce_field( self::CONFIRM_ACTION );
		echo '<p><button type="submit" class="button button-primary">';
		esc_html_e( 'Confirm this site', 'navexa-agecheck-for-woocommerce' );
		echo '</button></p></form></div>';
	}

	/**
	 * Lower-case hex of $bytes cryptographically random bytes.
	 *
	 * @param int $bytes Number of random bytes.
	 */
	private static function random_hex( int $bytes ): string {
		return bin2hex( random_bytes( $bytes ) );
	}
}
