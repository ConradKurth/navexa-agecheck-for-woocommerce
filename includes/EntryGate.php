<?php
/**
 * The entry age gate (self-attestation, not verification).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * A free self-attestation gate shown when a visitor enters the store: "I am
 * 21 or older" or "I am under 21" (plan §5 W3 PR 13, gap G20). It is NOT an
 * age verification: anyone can answer yes. It never replaces the AgeCheck
 * check at checkout or the order hold, which apply whatever the visitor
 * answered. Off by default (Options::ENTRY_GATE).
 *
 * - No API key, no personal data, no network call: the page carries the
 *   minimum age and display name AgeCheck last reported
 *   (Options::STORE_PROFILE, stored by Mode; 21 and the WordPress site title
 *   until then), the remember-days, the exit URL and the copy. The script is
 *   bundled (assets/js/entry-gate.js, from assets/src/loader/gate.ts).
 * - Page caching: the server decides only whether a page carries the gate
 *   (the merchant's scope, the same for every visitor of a URL). Whether it
 *   shows is decided in the browser from the visitor's own remembered answer
 *   (localStorage, or for 0 days sessionStorage, one tab only), which never
 *   reaches the server, so a cached page serves every visitor correctly.
 * - Script optimizers: the script and its inline configuration must run
 *   synchronously in the head, so both tags carry the opt-out attributes
 *   common optimizers honour (NO_OPTIMIZE); the readme also asks merchants
 *   to exclude them from "delay" and "defer" settings.
 * - Scope: every front-end page (Options::GATE_SCOPE_SITE), or only
 *   age-restricted pages (Options::GATE_SCOPE_RESTRICTED): a product page
 *   whose product needs an age check (Requirement::product_requires(); a
 *   variable or grouped product when any of its children does) and a
 *   product category or tag page whose products need one by the store rule
 *   (Requirement::term_requires()).
 * - Never on wp-admin, the login page, REST, AJAX, cron, feeds, embeds,
 *   robots.txt, the favicon, trackbacks, the Customizer preview, or a
 *   WooCommerce API request such as the AgeCheck return page
 *   (?wc-api=navexa_agecheck_return); the script also skips the page the
 *   exit URL points to.
 */
final class EntryGate {

	const HANDLE = 'navexa-agecheck-entry-gate';

	/**
	 * Attributes that keep script optimizers from deferring, delaying,
	 * combining or minifying the gate's tags: Cloudflare Rocket Loader
	 * (data-cfasync, first, before src), LiteSpeed Cache (data-no-optimize,
	 * data-no-defer, data-no-minify) and WP Rocket's delay JavaScript
	 * execution (nowprocket). Browsers ignore them.
	 */
	const NO_OPTIMIZE = array(
		'data-cfasync'     => 'false',
		'data-no-optimize' => '1',
		'data-no-defer'    => '1',
		'data-no-minify'   => '1',
		'nowprocket'       => true,
	);

	/**
	 * Registers the front-end hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'wp_script_attributes', array( self::class, 'script_attributes' ) );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'script_attributes' ) );
	}

	/**
	 * Adds NO_OPTIMIZE to the gate's script tag and its inline configuration
	 * (ids HANDLE-js and HANDLE-js-before, as WP_Scripts prints them); every
	 * other tag is left as it is.
	 *
	 * @param mixed $attributes Script tag attributes.
	 * @return mixed
	 */
	public static function script_attributes( $attributes ) {
		if ( ! is_array( $attributes ) || ! isset( $attributes['id'] ) || ! in_array( $attributes['id'], array( self::HANDLE . '-js', self::HANDLE . '-js-before' ), true ) ) {
			return $attributes;
		}
		return self::NO_OPTIMIZE + $attributes;
	}

	/**
	 * Enqueues the gate script in the page head, with its configuration,
	 * when this page carries the gate. In the head so the page is hidden
	 * before its content renders; the script is a few kilobytes and loads
	 * from the store itself.
	 */
	public static function enqueue(): void {
		if ( ! self::applies() ) {
			return;
		}
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/entry-gate' . $min . '.js', NAVEXA_AGECHECK_FILE ),
			array(),
			NAVEXA_AGECHECK_VERSION,
			array( 'in_footer' => false )
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.navexaAgeCheckGate = ' . wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	/**
	 * Whether this request's page carries the gate.
	 */
	public static function applies(): bool {
		if ( ! Options::entry_gate_enabled() || self::excluded_request() ) {
			return false;
		}
		return Options::GATE_SCOPE_SITE === Options::entry_gate_scope() || self::restricted_page();
	}

	/**
	 * The script's configuration: the same for every visitor (page caching).
	 * No key, no personal data.
	 *
	 * @return array{v: int, minimumAge: int, days: int, exitUrl: string, i18n: array<string, string>}
	 */
	public static function config(): array {
		$age   = Options::store_minimum_age();
		$name  = Options::store_display_name();
		$title = __( 'Welcome', 'navexa-agecheck-for-woocommerce' );
		if ( '' !== $name ) {
			/* translators: %s: the store's name. */
			$title = sprintf( __( 'Welcome to %s', 'navexa-agecheck-for-woocommerce' ), $name );
		}
		return array(
			'v'          => 1,
			'minimumAge' => $age,
			'days'       => Options::entry_gate_days(),
			'exitUrl'    => Options::entry_gate_exit_url(),
			'i18n'       => array(
				'title'   => $title,
				/* translators: %d: minimum age, e.g. 21. */
				'body'    => sprintf( __( 'You must be %d or older to enter this store. Please confirm your age.', 'navexa-agecheck-for-woocommerce' ), $age ),
				/* translators: %d: minimum age, e.g. 21. */
				'yes'     => sprintf( __( 'I am %d or older', 'navexa-agecheck-for-woocommerce' ), $age ),
				/* translators: %d: minimum age, e.g. 21. */
				'no'      => sprintf( __( 'I am under %d', 'navexa-agecheck-for-woocommerce' ), $age ),
				/* translators: %d: minimum age, e.g. 21. */
				'denied'  => sprintf( __( 'Sorry, you must be %d or older to visit this store.', 'navexa-agecheck-for-woocommerce' ), $age ),
				'leaving' => __( 'Leaving this store…', 'navexa-agecheck-for-woocommerce' ),
			),
		);
	}

	/**
	 * Requests that never carry the gate: not a storefront page.
	 */
	private static function excluded_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || self::is_rest() || self::is_login() ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks that the parameter is there; nothing is read or changed.
		if ( isset( $_GET['wc-api'] ) ) {
			return true; // WooCommerce API requests, the AgeCheck return page among them.
		}
		return is_feed() || is_embed() || is_robots() || is_favicon() || is_trackback() || is_customize_preview();
	}

	/**
	 * Whether this is a REST request.
	 */
	private static function is_rest(): bool {
		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return true;
		}
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Whether this is the login page (wp-login.php or a custom one WordPress knows).
	 */
	private static function is_login(): bool {
		if ( function_exists( 'is_login' ) && is_login() ) {
			return true;
		}
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
	}

	/**
	 * Whether this page is age-restricted: a product page whose product (or
	 * any child of a variable or grouped product) needs an age check, or a
	 * product category or tag page whose products need one.
	 */
	private static function restricted_page(): bool {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			return $product instanceof \WC_Product && self::product_restricted( $product );
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return Requirement::term_requires( 'product_cat', get_queried_object_id() );
		}
		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return Requirement::term_requires( 'product_tag', get_queried_object_id() );
		}
		return false;
	}

	/**
	 * Whether $product, or any of its children, needs an age check.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function product_restricted( \WC_Product $product ): bool {
		if ( Requirement::product_requires( $product ) ) {
			return true;
		}
		foreach ( (array) $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof \WC_Product && Requirement::product_requires( $child ) ) {
				return true;
			}
		}
		return false;
	}
}
