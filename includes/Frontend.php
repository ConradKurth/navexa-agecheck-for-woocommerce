<?php
/**
 * Front-end scripts: the AgeCheck loader and the checkout glue.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the bundled loader (assets/js/agecheck-{LOADER_VERSION}.js, built
 * from assets/src/loader/ with esbuild) and the two pieces of checkout glue
 * that use it:
 *
 * - classic checkout: assets/js/checkout-classic.js (assets/src/loader/classic.ts);
 * - block checkout: the Verify age inner block, assets/build/verify-age/
 *   (assets/src/blocks/verify-age/, built with wp-scripts).
 *
 * Minified files load unless SCRIPT_DEBUG is on. Nothing is loaded outside
 * checkout except the entry age gate (EntryGate, assets/js/entry-gate.js,
 * only when the merchant turns it on), and nothing from a third-party host
 * except Cloudflare's Turnstile script when the merchant turns Turnstile on
 * (disclosed in readme.txt).
 */
final class Frontend {

	/**
	 * The loader's own version: the loader contract (plan §2.7) is shared by
	 * every platform, so it is versioned apart from the plugin.
	 */
	const LOADER_VERSION = '1.0.0';

	const HANDLE_LOADER  = 'navexa-agecheck-loader';
	const HANDLE_CLASSIC = 'navexa-agecheck-classic';
	const HANDLE_BLOCK   = 'navexa-agecheck-verify-age';

	/**
	 * Cloudflare's Turnstile API and the plugin's Turnstile glue
	 * (assets/js/turnstile.js), registered and depended on only while the
	 * merchant has Turnstile on.
	 */
	const HANDLE_TURNSTILE_API = 'navexa-agecheck-turnstile-api';
	const HANDLE_TURNSTILE     = 'navexa-agecheck-turnstile';

	/**
	 * Registers the scripts (idempotent).
	 */
	public static function register_scripts(): void {
		if ( wp_script_is( self::HANDLE_LOADER, 'registered' ) ) {
			return;
		}
		$min       = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$turnstile = array();
		if ( Turnstile::enabled() ) {
			// Version null: Cloudflare's script URL must not carry a ver query.
			wp_register_script( self::HANDLE_TURNSTILE_API, Turnstile::SCRIPT_URL, array(), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare's own script, loaded as documented.
			wp_register_script(
				self::HANDLE_TURNSTILE,
				self::asset_url( 'assets/js/turnstile' . $min . '.js' ),
				array( self::HANDLE_TURNSTILE_API ),
				NAVEXA_AGECHECK_VERSION,
				array( 'in_footer' => true )
			);
			$turnstile = array( self::HANDLE_TURNSTILE );
		}
		wp_register_script(
			self::HANDLE_LOADER,
			self::asset_url( 'assets/js/agecheck-' . self::LOADER_VERSION . $min . '.js' ),
			array(),
			self::LOADER_VERSION,
			array( 'in_footer' => true )
		);
		wp_register_script(
			self::HANDLE_CLASSIC,
			self::asset_url( 'assets/js/checkout-classic' . $min . '.js' ),
			array_merge( array( self::HANDLE_LOADER ), $turnstile ),
			NAVEXA_AGECHECK_VERSION,
			array( 'in_footer' => true )
		);
		$asset = self::block_asset( 'frontend' );
		wp_register_script(
			self::HANDLE_BLOCK,
			self::asset_url( 'assets/build/verify-age/frontend.js' ),
			array_values( array_unique( array_merge( $asset['dependencies'], array( self::HANDLE_LOADER, 'wc-blocks-checkout', 'wc-blocks-data-store', 'wc-settings' ), $turnstile ) ) ),
			$asset['version'],
			array( 'in_footer' => true )
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE_BLOCK, 'navexa-agecheck-for-woocommerce' );
		}
	}

	/**
	 * Registers the Verify age block types: the main one, forced into the
	 * payment step, and the fallback in the fields area for carts that need
	 * no payment (WooCommerce hides the payment step then). Each block.json
	 * registers its editor script (editorScript); the front end, one script
	 * for both, comes from BlocksIntegration.
	 */
	public static function register_block_type(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		foreach ( array( 'verify-age', 'verify-age-no-payment' ) as $name ) {
			$dir = dirname( NAVEXA_AGECHECK_FILE ) . '/assets/build/' . $name;
			if ( is_readable( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}

	/**
	 * Enqueues the loader and the classic checkout glue with their data.
	 */
	public static function enqueue_classic(): void {
		self::register_scripts();
		wp_enqueue_script( self::HANDLE_CLASSIC );
		wp_add_inline_script(
			self::HANDLE_CLASSIC,
			'window.navexaAgeCheckClassic = ' . wp_json_encode( self::classic_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	/**
	 * Data both checkouts' scripts read: the copy, and the Turnstile site key
	 * and action while Turnstile is on.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data(): array {
		$data      = array(
			'namespace' => Start::STORE_NAMESPACE,
			'i18n'      => array(
				'verify'      => __( 'Verify age', 'navexa-agecheck-for-woocommerce' ),
				'verifying'   => __( 'Verifying…', 'navexa-agecheck-for-woocommerce' ),
				'checking'    => __( 'Checking your age verification…', 'navexa-agecheck-for-woocommerce' ),
				'prompt'      => __( 'This order contains age-restricted items. Verify your age to place it.', 'navexa-agecheck-for-woocommerce' ),
				'pending'     => __( 'Finish your age check in the window that opened. This page updates when you are done. If you closed that window, select Verify age to start again.', 'navexa-agecheck-for-woocommerce' ),
				'passed'      => __( 'Age verified.', 'navexa-agecheck-for-woocommerce' ),
				'failed'      => __( 'We could not verify your age, so this order cannot be placed.', 'navexa-agecheck-for-woocommerce' ),
				'unavailable' => __( 'Age verification is temporarily unavailable. Please try again in a few minutes.', 'navexa-agecheck-for-woocommerce' ),
				'hold'        => Start::hold_message(),
				'required'    => __( 'Verify your age before placing this order.', 'navexa-agecheck-for-woocommerce' ),
				'opening'     => __( 'Opening the secure age check…', 'navexa-agecheck-for-woocommerce' ),
				'closed'      => __( 'The age check window was closed. Select Verify age to try again.', 'navexa-agecheck-for-woocommerce' ),
				'turnstile'   => __( 'The security check could not be completed. Reload the page, then select Verify age again.', 'navexa-agecheck-for-woocommerce' ),
				'challenge'   => __( 'Complete the security check shown below, then select Verify age again.', 'navexa-agecheck-for-woocommerce' ),
				'security'    => __( 'Security check', 'navexa-agecheck-for-woocommerce' ),
			),
		);
		$turnstile = Turnstile::script_data();
		if ( null !== $turnstile ) {
			$data['turnstile'] = $turnstile;
		}
		return $data;
	}

	/**
	 * Data for the classic glue: script_data() plus the Store API base and,
	 * for a signed-in shopper, a REST nonce. Signed-in pages are not cached,
	 * so that nonce is fresh; guests need none, and the Store API's own nonce
	 * is fetched at click time (GET /cart answers it in the Nonce header), so
	 * a cached checkout page never carries a stale one.
	 *
	 * @return array<string, mixed>
	 */
	public static function classic_data(): array {
		$data             = self::script_data();
		$data['storeApi'] = esc_url_raw( rest_url( 'wc/store/v1/' ) );
		if ( is_user_logged_in() ) {
			$data['restNonce'] = wp_create_nonce( 'wp_rest' );
		}
		return $data;
	}

	/**
	 * URL of a file in the plugin.
	 *
	 * @param string $path Path relative to the plugin root.
	 */
	private static function asset_url( string $path ): string {
		return plugins_url( $path, NAVEXA_AGECHECK_FILE );
	}

	/**
	 * The wp-scripts asset file of a block script: {dependencies, version}.
	 *
	 * @param string $name Script name (frontend, index).
	 * @return array{dependencies: string[], version: string}
	 */
	private static function block_asset( string $name ): array {
		$file  = dirname( NAVEXA_AGECHECK_FILE ) . '/assets/build/verify-age/' . $name . '.asset.php';
		$asset = is_readable( $file ) ? include $file : array();
		$deps  = is_array( $asset ) && isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? array_values( array_filter( $asset['dependencies'], 'is_string' ) ) : array();
		$ver   = is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : NAVEXA_AGECHECK_VERSION;
		return array(
			'dependencies' => $deps,
			'version'      => $ver,
		);
	}
}
