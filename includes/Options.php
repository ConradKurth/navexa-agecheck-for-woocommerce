<?php
/**
 * Merchant settings storage.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Option names and typed getters for the merchant's settings. Kept apart from
 * Settings, which extends WC_Settings_Page and therefore loads only on
 * WooCommerce's settings screens; checkout code reads its settings from here.
 *
 * Install state (install_id, guard secret, fingerprint) lives in Install.
 */
final class Options {

	const START_KEY      = 'navexa_agecheck_start_key';
	const FULFILMENT_KEY = 'navexa_agecheck_fulfilment_key';
	const MODE           = 'navexa_agecheck_mode';

	/**
	 * Which line items need an age check: RULE_ALL, RULE_INCLUDE or RULE_EXCLUDE.
	 */
	const RULE = 'navexa_agecheck_rule';

	/**
	 * Product category term ids the include or exclude rule names.
	 */
	const RULE_CATEGORIES = 'navexa_agecheck_rule_categories';

	/**
	 * Product tag term ids the include or exclude rule names.
	 */
	const RULE_TAGS = 'navexa_agecheck_rule_tags';

	/**
	 * Roles whose own orders (the order's customer, not whoever places it)
	 * skip the age check.
	 */
	const EXEMPT_ROLES = 'navexa_agecheck_exempt_roles';

	/**
	 * Webhook endpoint secret from the Navexa dashboard. Stored with autoload off.
	 */
	const WEBHOOK_SECRET = 'navexa_agecheck_webhook_secret';

	/**
	 * Unix time of the last webhook delivery whose signature verified.
	 */
	const WEBHOOK_LAST_RECEIVED = 'navexa_agecheck_webhook_last_received';

	/**
	 * What happens to a paid order whose shopper fails the age check:
	 * DENIED_AUTO_REFUND or DENIED_MANUAL.
	 */
	const DENIED_ORDERS = 'navexa_agecheck_denied_orders';

	/**
	 * Every line item needs an age check (unless its product is exempt).
	 */
	const RULE_ALL = 'all';

	/**
	 * Only products in the chosen categories or tags (or flagged) need one.
	 */
	const RULE_INCLUDE = 'include';

	/**
	 * Every product except those in the chosen categories or tags needs one.
	 */
	const RULE_EXCLUDE = 'exclude';

	/**
	 * Refund a denied order through its payment gateway when the gateway can
	 * refund by API; otherwise list it for a manual refund. The default (Q11d).
	 */
	const DENIED_AUTO_REFUND = 'auto_refund';

	/**
	 * Always list a denied order for a manual refund.
	 */
	const DENIED_MANUAL = 'manual';

	/**
	 * Shoppers verify at checkout; an order cannot be placed until they pass.
	 */
	const MODE_PRE_PAYMENT = 'pre_payment';

	/**
	 * Orders are accepted and held until the shopper passes.
	 */
	const MODE_ACCEPT_AND_HOLD = 'accept_and_hold';

	/**
	 * Age verification off: only the entry age gate (self-attestation) runs.
	 * Nothing is verified or held, and no API key is needed. Only an explicit
	 * choice sets it: a missing key in the two verification modes still
	 * refuses and holds (fail closed).
	 */
	const MODE_GATE_ONLY = 'gate_only';

	const DEFAULT_MODE = self::MODE_PRE_PAYMENT;

	/**
	 * The $_SERVER key of the proxy header that carries the client IP
	 * (RateLimit::PROXY_HEADERS), or '' for REMOTE_ADDR.
	 */
	const TRUSTED_PROXY_HEADER = 'navexa_agecheck_trusted_proxy_header';

	/**
	 * 'yes' (default) or 'no': hide express payment buttons (Apple Pay, Google
	 * Pay, PayPal and the like) on age-restricted products and carts.
	 */
	const HIDE_EXPRESS = 'navexa_agecheck_hide_express';

	/**
	 * 'yes' or 'no' (default): ask for a Cloudflare Turnstile token before a
	 * shopper's age check starts.
	 */
	const TURNSTILE = 'navexa_agecheck_turnstile';

	/**
	 * Cloudflare Turnstile site key (public; printed into the checkout page).
	 */
	const TURNSTILE_SITE_KEY = 'navexa_agecheck_turnstile_site_key';

	/**
	 * Cloudflare Turnstile secret key. Stored with autoload off, never printed.
	 */
	const TURNSTILE_SECRET = 'navexa_agecheck_turnstile_secret';

	/**
	 * 'yes' or 'no' (default): uninstalling the plugin also deletes its order
	 * and product meta. Off, that meta stays as an audit trail.
	 */
	const DELETE_DATA = 'navexa_agecheck_delete_data_on_uninstall';

	/**
	 * 'yes' or 'no' (default): show the entry age gate, a self-attestation
	 * prompt ("Are you 21 or older?"), not an age verification.
	 */
	const ENTRY_GATE = 'navexa_agecheck_entry_gate';

	/**
	 * Where the entry gate shows: GATE_SCOPE_SITE (default) or GATE_SCOPE_RESTRICTED.
	 */
	const ENTRY_GATE_SCOPE = 'navexa_agecheck_entry_gate_scope';

	/**
	 * Days the visitor's answer is remembered in their browser (0: until the
	 * browser is closed).
	 */
	const ENTRY_GATE_DAYS = 'navexa_agecheck_entry_gate_days';

	/**
	 * Where a visitor who says they are under age is sent, or '' to keep them
	 * on the gate.
	 */
	const ENTRY_GATE_EXIT_URL = 'navexa_agecheck_entry_gate_exit_url';

	/**
	 * The store's AgeCheck minimum age and display name from the last
	 * GetIntegrationInfo answer ({minimum_age, display_name}), kept for
	 * storefront copy (the entry gate) so a page never waits on the network.
	 */
	const STORE_PROFILE = 'navexa_agecheck_store_profile';

	/**
	 * What pre-payment checkout does while AgeCheck cannot run the ID check
	 * (owner decision #21, TAX-1841): OUTAGE_REFUSE (default) or
	 * OUTAGE_HOLD.
	 */
	const OUTAGE_FALLBACK = 'navexa_agecheck_outage_fallback';

	/**
	 * Refuse the order, as when AgeCheck answers nothing (the default).
	 */
	const OUTAGE_REFUSE = 'refuse';

	/**
	 * Take the order and hold it; the shopper verifies by link once AgeCheck
	 * answers again (the order-bound path).
	 */
	const OUTAGE_HOLD = 'hold';

	/**
	 * Unix time the store's daily ID check limit resets, from the last
	 * GetCheckAvailability ORG_DAILY_LIMIT answer (Availability); 0 or
	 * unset when not capped.
	 */
	const CAPPED_UNTIL = 'navexa_agecheck_capped_until';

	/**
	 * The entry gate shows on every front-end page.
	 */
	const GATE_SCOPE_SITE = 'site';

	/**
	 * The entry gate shows only on age-restricted product pages and their
	 * category and tag pages.
	 */
	const GATE_SCOPE_RESTRICTED = 'restricted';

	const DEFAULT_GATE_DAYS = 30;
	const MAX_GATE_DAYS     = 365;

	/**
	 * Minimum age the entry gate asks about when AgeCheck has not said.
	 */
	const DEFAULT_MINIMUM_AGE = 21;

	/**
	 * How visitors answer the entry gate: GATE_MODE_ATTEST (default) or
	 * GATE_MODE_DOB. Both are self-attestation.
	 */
	const ENTRY_GATE_MODE = 'navexa_agecheck_entry_gate_mode';

	/**
	 * The entry gate's own minimum age (18, 19 or 21), used while age
	 * verification is off or AgeCheck has not reported one
	 * (entry_gate_minimum_age()).
	 */
	const ENTRY_GATE_MIN_AGE = 'navexa_agecheck_entry_gate_min_age';

	/**
	 * Attachment id of the entry gate's logo (the merchant's media library),
	 * or 0 for none.
	 */
	const ENTRY_GATE_LOGO = 'navexa_agecheck_entry_gate_logo';

	/**
	 * The entry gate's colours: an array keyed by GATE_THEME_DEFAULTS' keys,
	 * saved through WooCommerce's opt[key] fields.
	 */
	const ENTRY_GATE_THEME = 'navexa_agecheck_entry_gate_theme';

	/**
	 * The merchant's entry gate text: an array keyed by GATE_TEXT_LIMITS'
	 * keys; blank means the translated default.
	 */
	const ENTRY_GATE_TEXT = 'navexa_agecheck_entry_gate_text';

	/**
	 * Visitors click "I am 21 or older" or "I am under 21".
	 */
	const GATE_MODE_ATTEST = 'attest';

	/**
	 * Visitors type their date of birth, judged in their browser and never
	 * stored or sent (the shared loader's gate).
	 */
	const GATE_MODE_DOB = 'dob';

	/**
	 * Minimum ages the gate accepts (the loader's GateOptions schema).
	 */
	const GATE_MINIMUM_AGES = array( 18, 19, 21 );

	/**
	 * The entry gate's default colours, which reproduce the 1.0.x gate and
	 * equal the loader's defaults (schema/gate-options.schema.json theme).
	 */
	const GATE_THEME_DEFAULTS = array(
		'backdrop'    => '#1e1e1e',
		'card'        => '#ffffff',
		'text'        => '#1e1e1e',
		'accent'      => '#1e1e1e',
		'accent_text' => '#ffffff',
	);

	/**
	 * Colour pairs that carry text, as [foreground, background]: each must
	 * reach MIN_CONTRAST (the schema's x-contrast; the backdrop carries none).
	 */
	const GATE_CONTRAST_PAIRS = array(
		array( 'text', 'card' ),
		array( 'accent_text', 'accent' ),
	);

	/**
	 * Least WCAG 2.x contrast ratio of a text colour pair (AA, normal text).
	 */
	const MIN_CONTRAST = 4.5;

	/**
	 * The merchant's gate text fields and their longest length in Unicode code
	 * points (the schema's maxLength for title, message and the labels).
	 */
	const GATE_TEXT_LIMITS = array(
		'title'   => 80,
		'message' => 300,
		'yes'     => 40,
		'no'      => 40,
		'submit'  => 40,
	);

	/**
	 * Whether the merchant turned the entry gate on. Only a stored 'yes' does.
	 */
	public static function entry_gate_enabled(): bool {
		return 'yes' === get_option( self::ENTRY_GATE, 'no' );
	}

	/**
	 * GATE_MODE_ATTEST (default, also for an unknown value) or GATE_MODE_DOB.
	 */
	public static function entry_gate_mode(): string {
		return self::GATE_MODE_DOB === get_option( self::ENTRY_GATE_MODE, self::GATE_MODE_ATTEST ) ? self::GATE_MODE_DOB : self::GATE_MODE_ATTEST;
	}

	/**
	 * The gate's own minimum age: 18, 19 or 21, DEFAULT_MINIMUM_AGE otherwise.
	 */
	public static function entry_gate_local_minimum_age(): int {
		$age = get_option( self::ENTRY_GATE_MIN_AGE, self::DEFAULT_MINIMUM_AGE );
		$age = is_numeric( $age ) ? (int) $age : 0;
		return in_array( $age, self::GATE_MINIMUM_AGES, true ) ? $age : self::DEFAULT_MINIMUM_AGE;
	}

	/**
	 * The minimum age the entry gate asks about: with age verification on,
	 * the one AgeCheck reported for the store (so the gate asks the age
	 * checkout verifies); otherwise, or before AgeCheck has answered, the
	 * gate's own (entry_gate_local_minimum_age()). Never asks AgeCheck.
	 */
	public static function entry_gate_minimum_age(): int {
		$reported = self::reported_minimum_age();
		return self::verification_enabled() && null !== $reported ? $reported : self::entry_gate_local_minimum_age();
	}

	/**
	 * Attachment id of the gate's logo when it is an image, else 0.
	 */
	public static function entry_gate_logo_id(): int {
		$id = absint( get_option( self::ENTRY_GATE_LOGO, 0 ) );
		return $id > 0 && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * The gate's colours, keyed as GATE_THEME_DEFAULTS: each stored colour
	 * when it is #rgb or #rrggbb (lower-cased), else the default; and a text
	 * pair under MIN_CONTRAST falls back to its default pair, as the loader
	 * would (Settings refuses such a pair on save; this covers values written
	 * some other way).
	 *
	 * @return array<string, string>
	 */
	public static function entry_gate_theme(): array {
		$stored = get_option( self::ENTRY_GATE_THEME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$theme  = array();
		foreach ( self::GATE_THEME_DEFAULTS as $key => $default ) {
			$color         = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? self::hex_color( $stored[ $key ] ) : '';
			$theme[ $key ] = '' === $color ? $default : $color;
		}
		foreach ( self::GATE_CONTRAST_PAIRS as $pair ) {
			if ( self::contrast_ratio( $theme[ $pair[0] ], $theme[ $pair[1] ] ) < self::MIN_CONTRAST ) {
				$theme[ $pair[0] ] = self::GATE_THEME_DEFAULTS[ $pair[0] ];
				$theme[ $pair[1] ] = self::GATE_THEME_DEFAULTS[ $pair[1] ];
			}
		}
		return $theme;
	}

	/**
	 * The merchant's gate text, keyed as GATE_TEXT_LIMITS: each stored value
	 * trimmed, or '' (blank, not text, or longer than its limit).
	 *
	 * @return array<string, string>
	 */
	public static function entry_gate_text(): array {
		$stored = get_option( self::ENTRY_GATE_TEXT, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$text   = array();
		foreach ( self::GATE_TEXT_LIMITS as $key => $limit ) {
			$value        = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? self::gate_text( $stored[ $key ] ) : '';
			$text[ $key ] = self::text_length( $value ) <= $limit ? $value : '';
		}
		return $text;
	}

	/**
	 * Gate text as the loader reads it: control characters folded to spaces,
	 * the ends trimmed.
	 *
	 * @param string $value Text.
	 */
	public static function gate_text( string $value ): string {
		if ( 1 !== preg_match( '//u', $value ) ) {
			return ''; // Not valid UTF-8.
		}
		return trim( (string) preg_replace( '/[\x00-\x1f\x7f]/u', ' ', $value ) );
	}

	/**
	 * Length of $value in Unicode code points (the schema's maxLength).
	 *
	 * @param string $value UTF-8 text.
	 */
	public static function text_length( string $value ): int {
		return (int) mb_strlen( $value, 'UTF-8' );
	}

	/**
	 * $value as a lower-case #rgb or #rrggbb colour, or '' when it is not one
	 * (the schema's colour pattern).
	 *
	 * @param string $value Candidate.
	 */
	public static function hex_color( string $value ): string {
		$value = trim( $value );
		return 1 === preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? strtolower( $value ) : '';
	}

	/**
	 * WCAG 2.x contrast ratio of two #rgb / #rrggbb colours, unrounded (1 to
	 * 21); 1.0 when either is not a colour. Pinned by the loader's
	 * schema/vectors/contrast.json.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function contrast_ratio( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		if ( null === $la || null === $lb ) {
			return 1.0;
		}
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * WCAG 2.x relative luminance of a #rgb / #rrggbb colour, or null.
	 *
	 * @param string $color Colour.
	 */
	private static function luminance( string $color ): ?float {
		$hex = ltrim( self::hex_color( $color ), '#' );
		if ( '' === $hex ) {
			return null;
		}
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * GATE_SCOPE_SITE (default, also for an unknown value) or GATE_SCOPE_RESTRICTED.
	 */
	public static function entry_gate_scope(): string {
		return self::GATE_SCOPE_RESTRICTED === get_option( self::ENTRY_GATE_SCOPE, self::GATE_SCOPE_SITE ) ? self::GATE_SCOPE_RESTRICTED : self::GATE_SCOPE_SITE;
	}

	/**
	 * Days the entry gate's answer is remembered: 0 to MAX_GATE_DAYS,
	 * DEFAULT_GATE_DAYS when unset or not a whole number.
	 */
	public static function entry_gate_days(): int {
		return self::gate_days( get_option( self::ENTRY_GATE_DAYS, self::DEFAULT_GATE_DAYS ) );
	}

	/**
	 * $value as remember-days: a whole number clamped to 0..MAX_GATE_DAYS,
	 * DEFAULT_GATE_DAYS for anything else.
	 *
	 * @param mixed $value Stored or posted value.
	 */
	public static function gate_days( $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\s*[0-9]{1,6}\s*$/', $value ) ) ) {
			return max( 0, min( self::MAX_GATE_DAYS, (int) $value ) );
		}
		return self::DEFAULT_GATE_DAYS;
	}

	/**
	 * The entry gate's exit URL, or '' (none, or not an absolute http(s) URL).
	 */
	public static function entry_gate_exit_url(): string {
		$url = get_option( self::ENTRY_GATE_EXIT_URL, '' );
		return is_string( $url ) ? self::exit_url( $url ) : '';
	}

	/**
	 * $url when it is an absolute http or https URL with a host, else ''.
	 *
	 * @param string $url Candidate.
	 */
	public static function exit_url( string $url ): string {
		$url   = trim( $url );
		$parts = '' === $url || strlen( $url ) > 2048 ? false : wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * The minimum age AgeCheck last reported for the store (18, 19 or 21),
	 * DEFAULT_MINIMUM_AGE when it has not. Never asks AgeCheck.
	 */
	public static function store_minimum_age(): int {
		$age = self::reported_minimum_age();
		return null === $age ? self::DEFAULT_MINIMUM_AGE : $age;
	}

	/**
	 * The minimum age AgeCheck last reported for the store (18, 19 or 21), or
	 * null when it has not reported one. Never asks AgeCheck.
	 */
	public static function reported_minimum_age(): ?int {
		$profile = get_option( self::STORE_PROFILE, array() );
		$age     = is_array( $profile ) && isset( $profile['minimum_age'] ) ? $profile['minimum_age'] : null;
		return is_int( $age ) && in_array( $age, IntegrationInfo::MINIMUM_AGES, true ) ? $age : null;
	}

	/**
	 * The store's name for shoppers: the display name AgeCheck last reported,
	 * else the WordPress site title. Never asks AgeCheck.
	 */
	public static function store_display_name(): string {
		$name = self::reported_display_name();
		if ( '' !== $name ) {
			return $name;
		}
		return trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * The display name AgeCheck last reported for the store, '' when it
	 * reported none (or has not answered yet). Never asks AgeCheck.
	 */
	public static function reported_display_name(): string {
		$profile = get_option( self::STORE_PROFILE, array() );
		return is_array( $profile ) && isset( $profile['display_name'] ) && is_string( $profile['display_name'] ) ? trim( $profile['display_name'] ) : '';
	}

	/**
	 * Whether AgeCheck last reported that it would email the store's shoppers
	 * a verification link (GetIntegrationInfo links_enabled, TAX-1841): false
	 * until it says so, and from an engine without the field. Never asks
	 * AgeCheck.
	 */
	public static function reported_links_enabled(): bool {
		$profile = get_option( self::STORE_PROFILE, array() );
		return is_array( $profile ) && isset( $profile['links_enabled'] ) && true === $profile['links_enabled'];
	}

	/**
	 * Whether express payment buttons are hidden on age-restricted products
	 * and carts. Anything but a stored 'no' reads as on, except with age
	 * verification off (MODE_GATE_ONLY): nothing is verified then, so there
	 * is no reason to hide them.
	 */
	public static function hide_express(): bool {
		return self::verification_enabled() && 'no' !== get_option( self::HIDE_EXPRESS, 'yes' );
	}

	/**
	 * Whether the merchant turned Turnstile on. Only a stored 'yes' does.
	 */
	public static function turnstile_enabled(): bool {
		return 'yes' === get_option( self::TURNSTILE, 'no' );
	}

	/**
	 * The Turnstile site key, or ''.
	 */
	public static function turnstile_site_key(): string {
		$key = get_option( self::TURNSTILE_SITE_KEY, '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * The Turnstile secret key, or ''.
	 */
	public static function turnstile_secret(): string {
		$secret = get_option( self::TURNSTILE_SECRET, '' );
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	/**
	 * Whether $key has the shape of a Turnstile site or secret key (0x… or
	 * Cloudflare's 1x/2x/3x test keys). Only catches paste mistakes.
	 *
	 * @param string $key Candidate key.
	 */
	public static function looks_like_turnstile_key( string $key ): bool {
		return 1 === preg_match( '/^[0-3]x[A-Za-z0-9_-]{16,128}$/', $key );
	}

	/**
	 * Whether $key is one of Cloudflare's Turnstile test keys (1x…, 2x…,
	 * 3x…). Their secrets accept Cloudflare's public dummy token, so a store
	 * on them has no bot protection.
	 *
	 * @param string $key Site or secret key.
	 */
	public static function is_turnstile_test_key( string $key ): bool {
		return 1 === preg_match( '/^[1-3]x/', $key );
	}

	/**
	 * Whether uninstalling deletes the plugin's order and product meta.
	 */
	public static function delete_data_on_uninstall(): bool {
		return 'yes' === get_option( self::DELETE_DATA, 'no' );
	}

	/**
	 * The trusted proxy header's $_SERVER key, or '' for none.
	 */
	public static function trusted_proxy_header(): string {
		$header = get_option( self::TRUSTED_PROXY_HEADER, '' );
		return is_string( $header ) && in_array( $header, RateLimit::PROXY_HEADERS, true ) ? $header : '';
	}

	/**
	 * Option name of the $key_kind API key.
	 *
	 * @param string $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 */
	public static function key_option( string $key_kind ): string {
		return Client::KEY_START === $key_kind ? self::START_KEY : self::FULFILMENT_KEY;
	}

	/**
	 * The stored $key_kind API key, or ''. Stored with autoload off.
	 *
	 * @param string $key_kind Client::KEY_START or Client::KEY_FULFILMENT.
	 */
	public static function api_key( string $key_kind ): string {
		$key = get_option( self::key_option( $key_kind ), '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Whether $key has the shape of an AgeCheck API key (txk_ followed by the
	 * random part; today 64 hex characters). Only catches paste mistakes;
	 * GetIntegrationInfo decides whether the key works.
	 *
	 * @param string $key Candidate key.
	 */
	public static function looks_like_api_key( string $key ): bool {
		return 1 === preg_match( '/^txk_[A-Za-z0-9]{16,256}$/', $key );
	}

	/**
	 * Display form of a stored key: the first 8 characters, as the Navexa
	 * dashboard lists keys, never the whole key.
	 *
	 * @param string $key Stored key.
	 */
	public static function mask_api_key( string $key ): string {
		if ( strlen( $key ) < 16 ) {
			return '********';
		}
		return substr( $key, 0, 8 ) . '…';
	}

	/**
	 * The checkout mode: MODE_PRE_PAYMENT, MODE_ACCEPT_AND_HOLD or
	 * MODE_GATE_ONLY. An unknown stored value reads as MODE_PRE_PAYMENT, the
	 * strictest (a downgrade to a version without MODE_GATE_ONLY fails closed
	 * the same way).
	 */
	public static function mode(): string {
		$mode = get_option( self::MODE, self::DEFAULT_MODE );
		return in_array( $mode, array( self::MODE_PRE_PAYMENT, self::MODE_ACCEPT_AND_HOLD, self::MODE_GATE_ONLY ), true ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Whether age verification runs at all: false only when the merchant chose
	 * MODE_GATE_ONLY (the entry age gate alone). Never inferred from a
	 * missing key.
	 */
	public static function verification_enabled(): bool {
		return self::MODE_GATE_ONLY !== self::mode();
	}

	/**
	 * The line-item rule: RULE_ALL (default), RULE_INCLUDE or RULE_EXCLUDE.
	 * An unknown stored value reads as RULE_ALL, the strictest.
	 */
	public static function rule(): string {
		$rule = get_option( self::RULE, self::RULE_ALL );
		return in_array( $rule, array( self::RULE_INCLUDE, self::RULE_EXCLUDE ), true ) ? $rule : self::RULE_ALL;
	}

	/**
	 * Term ids stored under $option (RULE_CATEGORIES or RULE_TAGS).
	 *
	 * @param string $option Option name.
	 * @return int[]
	 */
	public static function term_ids( string $option ): array {
		$ids = get_option( $option, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Role slugs whose own orders are exempt.
	 *
	 * @return string[]
	 */
	public static function exempt_roles(): array {
		$roles = get_option( self::EXEMPT_ROLES, array() );
		if ( ! is_array( $roles ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'strval', $roles ),
				static function ( string $role ): bool {
					return '' !== $role;
				}
			)
		);
	}

	/**
	 * The stored webhook secret, or ''.
	 */
	public static function webhook_secret(): string {
		$secret = get_option( self::WEBHOOK_SECRET, '' );
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	/**
	 * Whether $secret looks like a webhook secret: 16 to 256 printable ASCII
	 * characters without spaces. Only catches paste mistakes.
	 *
	 * @param string $secret Candidate secret.
	 */
	public static function looks_like_webhook_secret( string $secret ): bool {
		return 1 === preg_match( '/^[\x21-\x7e]{16,256}$/', $secret );
	}

	/**
	 * The stored outage fallback: OUTAGE_HOLD only for a stored 'hold',
	 * OUTAGE_REFUSE for anything else. What the merchant chose; checkout
	 * reads outage_fallback().
	 */
	public static function stored_outage_fallback(): string {
		return self::OUTAGE_HOLD === get_option( self::OUTAGE_FALLBACK, self::OUTAGE_REFUSE ) ? self::OUTAGE_HOLD : self::OUTAGE_REFUSE;
	}

	/**
	 * What pre-payment checkout does while AgeCheck cannot run the ID check:
	 * OUTAGE_HOLD only when the merchant chose it, the store verifies before
	 * payment, and a held order's shopper can be reached by link, on positive
	 * evidence: AgeCheck reported that it would email the store's shoppers a
	 * link (reported_links_enabled(): links on at AgeCheck and a display
	 * name; an engine without the field never says so) and the store's
	 * display name, no store-wide link refusal still stands
	 * (Links::blocked(): younger than BLOCK_TTL, a no-display-name one lifted
	 * once a name is reported) and wp-config.php has not turned the emails
	 * off (NAVEXA_AGECHECK_EMAIL_LINKS false: DisabledLinkSender). Otherwise
	 * OUTAGE_REFUSE: a held order nobody can verify would only end refunded.
	 */
	public static function outage_fallback(): string {
		if ( self::OUTAGE_HOLD !== self::stored_outage_fallback() || self::MODE_PRE_PAYMENT !== self::mode() ) {
			return self::OUTAGE_REFUSE;
		}
		if ( ! self::reported_links_enabled() || '' === self::reported_display_name() || Links::sender() instanceof DisabledLinkSender ) {
			return self::OUTAGE_REFUSE;
		}
		$reported               = new IntegrationInfo();
		$reported->display_name = self::reported_display_name();
		if ( '' !== Links::blocked( $reported ) ) {
			return self::OUTAGE_REFUSE;
		}
		return self::OUTAGE_HOLD;
	}

	/**
	 * Whether checkout takes and holds orders while AgeCheck is down
	 * (outage_fallback() is OUTAGE_HOLD).
	 */
	public static function holds_on_outage(): bool {
		return self::OUTAGE_HOLD === self::outage_fallback();
	}

	/**
	 * Unix time the store's daily ID check limit resets (CAPPED_UNTIL), 0
	 * when it is not capped or the time has passed.
	 *
	 * @param int|null $now Unix time; null for time().
	 */
	public static function capped_until( ?int $now = null ): int {
		$until = (int) get_option( self::CAPPED_UNTIL, 0 );
		$now   = null === $now ? time() : $now;
		return $until > $now ? $until : 0;
	}

	/**
	 * DENIED_AUTO_REFUND (default) or DENIED_MANUAL.
	 */
	public static function denied_orders(): string {
		return self::DENIED_MANUAL === get_option( self::DENIED_ORDERS, self::DENIED_AUTO_REFUND ) ? self::DENIED_MANUAL : self::DENIED_AUTO_REFUND;
	}
}
