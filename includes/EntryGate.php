<?php
/**
 * The entry age gate (self-attestation, not verification).
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * A free self-attestation gate shown when a visitor enters the store: Yes/No
 * buttons ("I am 21 or older" / "I am under 21") or a date of birth (plan §5
 * W3 PR 13, gap G20; TAX-1874 for the date of birth and branding). It is NOT
 * an age verification: anyone can answer. It never replaces the AgeCheck
 * check at checkout or the order hold, which apply whatever the visitor
 * answered. Off by default (Options::ENTRY_GATE). With age verification off
 * (Options::MODE_GATE_ONLY) it is the plugin's only storefront feature.
 *
 * - The gate is the shared loader's (NavexaAgeCheck.gate(), @navexa/agecheck-js,
 *   vendored byte for byte in assets/vendor/agecheck-js/): its gate-only
 *   build loads in the head, then assets/js/gate-boot.js (from
 *   assets/src/gate/boot.ts) moves a 1.0.x remembered answer to the loader's
 *   storage key and calls gate() with config(). The loader owns the dialog,
 *   the date-of-birth check (in the browser, never stored or sent), the
 *   remember rule (localStorage navexa_agecheck_gate_v1, sessionStorage for 0
 *   days), the crawler exemption, the exit page and accessibility.
 * - No API key, no personal data, no network call: the page carries the
 *   minimum age (Options::entry_gate_minimum_age(): the one AgeCheck last
 *   reported while age verification is on, else the gate's own), the store
 *   name, the remember-days, the exit URL, the merchant's logo URL (their own
 *   media library), colours and copy. Nothing is fetched from another site.
 * - The configuration travels in a data-config attribute on the boot
 *   script's tag (no inline script, so a store with a script-src 'self' CSP
 *   works). It is the same for every visitor of a URL, so page caching is
 *   safe: whether the gate shows is decided in the browser from the
 *   visitor's own remembered answer, which never reaches the server.
 * - Script optimizers: both tags must run synchronously in the head, so they
 *   carry the opt-out attributes common optimizers honour (NO_OPTIMIZE); the
 *   readme also asks merchants to exclude them from "delay" and "defer".
 * - Scope: every front-end page (Options::GATE_SCOPE_SITE), or only
 *   age-restricted pages (Options::GATE_SCOPE_RESTRICTED): a product page
 *   whose product needs an age check (Requirement::product_requires(); a
 *   variable or grouped product when any of its children does) and a
 *   product category or tag page whose products need one by the store rule
 *   (Requirement::term_requires()). The rules only, never the mode.
 * - Never on wp-admin, the login page, REST, AJAX, cron, feeds, embeds,
 *   robots.txt, the favicon, trackbacks, the Customizer preview, or a
 *   WooCommerce API request such as the AgeCheck return page
 *   (?wc-api=navexa_agecheck_return); the loader also skips the page the
 *   exit URL points to.
 * - assets/js/entry-gate.js (the 1.0.x gate) is kept in 1.1.x but not
 *   enqueued: a page cached by 1.0.x still refers to it. It goes in 1.2.0.
 */
final class EntryGate {

	/**
	 * The boot script (assets/js/gate-boot.js), which carries data-config.
	 */
	const HANDLE = 'navexa-agecheck-entry-gate';

	/**
	 * The shared loader's gate-only build (assets/vendor/agecheck-js/).
	 */
	const HANDLE_LOADER = 'navexa-agecheck-gate-loader';

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
	 * The loader's text limits (schema/gate-options.schema.json maxLength):
	 * longer text is ignored by the loader, so translated defaults are cut to
	 * these.
	 */
	const TEXT_LIMITS = array(
		'siteName' => 60,
		'title'    => 80,
		'message'  => 300,
		'yes'      => 40,
		'no'       => 40,
		'submit'   => 40,
		'dob'      => 40,
		'month'    => 40,
		'day'      => 40,
		'year'     => 40,
		'invalid'  => 160,
		'denied'   => 160,
		'leaving'  => 160,
	);

	/**
	 * Registers the front-end hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'wp_script_attributes', array( self::class, 'script_attributes' ) );
	}

	/**
	 * Adds NO_OPTIMIZE to the gate's two script tags (ids HANDLE_LOADER-js
	 * and HANDLE-js, as WP_Scripts prints them), and the configuration as
	 * data-config to the boot script's tag; every other tag is left as it
	 * is. WordPress escapes the attribute; the JSON carries no &, <, > or
	 * quote inside its strings (hex escapes), so escaping never alters it.
	 *
	 * @param mixed $attributes Script tag attributes.
	 * @return mixed
	 */
	public static function script_attributes( $attributes ) {
		if ( ! is_array( $attributes ) || ! isset( $attributes['id'] ) || ! in_array( $attributes['id'], array( self::HANDLE . '-js', self::HANDLE_LOADER . '-js' ), true ) ) {
			return $attributes;
		}
		$attributes = self::NO_OPTIMIZE + $attributes;
		if ( self::HANDLE . '-js' === $attributes['id'] ) {
			$attributes['data-config'] = (string) wp_json_encode(
				array(
					'v'       => 2,
					'options' => self::config(),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}
		return $attributes;
	}

	/**
	 * Enqueues the gate in the page head when this page carries it: the
	 * vendored gate-only loader, then the boot script. In the head so the
	 * page is hidden before its content renders; both load from the store.
	 */
	public static function enqueue(): void {
		if ( ! self::applies() ) {
			return;
		}
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			self::HANDLE_LOADER,
			Frontend::loader_url( 'agecheck-gate' ),
			array(),
			Frontend::LOADER_VERSION,
			array( 'in_footer' => false )
		);
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/gate-boot' . $min . '.js', NAVEXA_AGECHECK_FILE ),
			array( self::HANDLE_LOADER ),
			NAVEXA_AGECHECK_VERSION,
			array( 'in_footer' => false )
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
	 * The gate's options (the loader's GateOptions, the vendored
	 * schema/<LOADER_VERSION>/): the same for every visitor (page caching).
	 * No key, no personal data. The merchant's text is passed as typed ({age}
	 * and {site} are the loader's placeholders); blank text is the translated
	 * default, with the age and the store name already filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$age      = Options::entry_gate_minimum_age();
		$defaults = self::default_text();
		$text     = Options::entry_gate_text();
		$theme    = Options::entry_gate_theme();
		$mode     = Options::entry_gate_mode();
		$labels   = array(
			'yes'     => '' !== $text['yes'] ? $text['yes'] : $defaults['yes'],
			'no'      => '' !== $text['no'] ? $text['no'] : $defaults['no'],
			'submit'  => '' !== $text['submit'] ? $text['submit'] : $defaults['submit'],
			'dob'     => __( 'Date of birth', 'navexa-agecheck-for-woocommerce' ),
			'month'   => __( 'Month', 'navexa-agecheck-for-woocommerce' ),
			'day'     => __( 'Day', 'navexa-agecheck-for-woocommerce' ),
			'year'    => __( 'Year', 'navexa-agecheck-for-woocommerce' ),
			'invalid' => __( 'Enter a real date of birth: month, day and year.', 'navexa-agecheck-for-woocommerce' ),
			/* translators: %d: minimum age, e.g. 21. */
			'denied'  => sprintf( __( 'Sorry, you must be %d or older to visit this store.', 'navexa-agecheck-for-woocommerce' ), $age ),
			'leaving' => __( 'Leaving this store…', 'navexa-agecheck-for-woocommerce' ),
		);
		$config   = array(
			'mode'         => $mode,
			'minAge'       => $age,
			'rememberDays' => Options::entry_gate_days(),
			'siteName'     => self::clip( Options::store_display_name(), 'siteName' ),
			'title'        => '' !== $text['title'] ? $text['title'] : self::clip( $defaults['title'], 'title' ),
			'message'      => '' !== $text['message'] ? $text['message'] : self::clip( $defaults['message'], 'message' ),
			'theme'        => array(
				'backdrop'   => $theme['backdrop'],
				'card'       => $theme['card'],
				'text'       => $theme['text'],
				'accent'     => $theme['accent'],
				'accentText' => $theme['accent_text'],
			),
			'labels'       => array(),
		);
		foreach ( $labels as $key => $label ) {
			$config['labels'][ $key ] = self::clip( $label, $key );
		}
		$exit = Options::entry_gate_exit_url();
		if ( '' !== $exit ) {
			$config['exitUrl'] = $exit;
		}
		$logo = self::logo_url();
		if ( '' !== $logo ) {
			$config['logoUrl'] = $logo;
		}
		return $config;
	}

	/**
	 * The translated default text of the merchant's fields (title, message,
	 * yes, no, submit), with the minimum age and the store name filled in:
	 * the 1.0.x gate's copy, and the date-of-birth message in that mode.
	 *
	 * @return array<string, string>
	 */
	public static function default_text(): array {
		$age   = Options::entry_gate_minimum_age();
		$name  = Options::store_display_name();
		$title = __( 'Welcome', 'navexa-agecheck-for-woocommerce' );
		if ( '' !== $name ) {
			/* translators: %s: the store's name. */
			$title = sprintf( __( 'Welcome to %s', 'navexa-agecheck-for-woocommerce' ), $name );
		}
		return array(
			'title'   => $title,
			'message' => Options::GATE_MODE_DOB === Options::entry_gate_mode()
				/* translators: %d: minimum age, e.g. 21. */
				? sprintf( __( 'You must be %d or older to enter this store. Please enter your date of birth.', 'navexa-agecheck-for-woocommerce' ), $age )
				/* translators: %d: minimum age, e.g. 21. */
				: sprintf( __( 'You must be %d or older to enter this store. Please confirm your age.', 'navexa-agecheck-for-woocommerce' ), $age ),
			/* translators: %d: minimum age, e.g. 21. */
			'yes'     => sprintf( __( 'I am %d or older', 'navexa-agecheck-for-woocommerce' ), $age ),
			/* translators: %d: minimum age, e.g. 21. */
			'no'      => sprintf( __( 'I am under %d', 'navexa-agecheck-for-woocommerce' ), $age ),
			'submit'  => __( 'Enter', 'navexa-agecheck-for-woocommerce' ),
		);
	}

	/**
	 * The logo's URL (a medium-size rendition) when the merchant chose an
	 * image and the URL is https (the loader shows only an https logo: an
	 * http image is mixed content on an https store); else ''.
	 */
	public static function logo_url(): string {
		$id = Options::entry_gate_logo_id();
		if ( 0 === $id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'medium' );
		return is_string( $url ) && 0 === stripos( $url, 'https://' ) && strlen( $url ) <= 2048 ? $url : '';
	}

	/**
	 * $text cut to the loader's limit for $key (Unicode code points), ends trimmed.
	 *
	 * @param string $text Text.
	 * @param string $key  TEXT_LIMITS key.
	 */
	private static function clip( string $text, string $key ): string {
		$text = Options::gate_text( $text );
		return isset( self::TEXT_LIMITS[ $key ] ) && Options::text_length( $text ) > self::TEXT_LIMITS[ $key ] ? trim( mb_substr( $text, 0, self::TEXT_LIMITS[ $key ], 'UTF-8' ) ) : $text;
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
