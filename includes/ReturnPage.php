<?php
/**
 * Where AgeCheck's hosted page sends the shopper back to.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The return page on the store's own origin: {home}/?wc-api=navexa_agecheck_return
 * (a WooCommerce API request, not REST, so it works with any permalink
 * setting and needs no nonce: it reads and changes nothing).
 *
 * The hosted page's "Return to checkout" and the provider's redirect land
 * here. Didit sends COOP: same-origin, so a popup loses window.opener once it
 * visits Didit; the checkout tab learns the outcome by polling AgeCheck's
 * status route, and this page only nudges it:
 *
 * - it posts {type: "navexa-agecheck", v: 1, event: "return"} on the
 *   BroadcastChannel "navexa-agecheck", and writes localStorage
 *   "navexa-agecheck-return" (a storage event for browsers without
 *   BroadcastChannel); the loader then calls refresh;
 * - with popup=1 it tries window.close(), and otherwise shows "You can close
 *   this window" with a link back to checkout;
 * - without popup=1, or when the loader marked this tab as a full-page
 *   redirect (sessionStorage "navexa-agecheck-redirect"), it goes to
 *   checkout instead.
 *
 * The page carries no verification id, token or status.
 */
final class ReturnPage {

	const API_ACTION = 'navexa_agecheck_return';

	/**
	 * Registers the WooCommerce API handler.
	 */
	public static function register(): void {
		add_action( 'woocommerce_api_' . self::API_ACTION, array( self::class, 'render' ) );
	}

	/**
	 * The return page URL on the store's address (Install::home(), never the
	 * filtered home_url(), so the origin is the one checked against the
	 * organization's allowed origins).
	 *
	 * @param bool $popup Add popup=1 (what CreateVerification and
	 *                    CreateHostedSession are given).
	 */
	public static function url( bool $popup = false ): string {
		$url = trailingslashit( Install::home() ) . '?wc-api=' . self::API_ACTION;
		return $popup ? $url . '&popup=1' : $url;
	}

	/**
	 * Serves the page and exits (WooCommerce would print -1 after the hook).
	 */
	public static function render(): void {
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : Install::home();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page; popup only picks the layout.
		$popup = isset( $_GET['popup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['popup'] ) );
		if ( ! $popup ) {
			wp_safe_redirect( $checkout );
			exit;
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo self::html( $checkout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
		exit;
	}

	/**
	 * The page.
	 *
	 * @param string $checkout_url Checkout URL.
	 */
	public static function html( string $checkout_url ): string {
		$lang   = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'language' ) : 'en';
		$script = '(function(){'
			. 'var checkout=' . wp_json_encode( esc_url_raw( $checkout_url ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ) . ';'
			. 'var redirected=false;'
			. 'try{redirected=window.sessionStorage.getItem("navexa-agecheck-redirect")==="1";window.sessionStorage.removeItem("navexa-agecheck-redirect");}catch(e){}'
			. 'try{var c=new BroadcastChannel("navexa-agecheck");c.postMessage({type:"navexa-agecheck",v:1,event:"return"});c.close();}catch(e){}'
			. 'try{window.localStorage.setItem("navexa-agecheck-return",String(Date.now()));}catch(e){}'
			. 'if(redirected){window.location.replace(checkout);return;}'
			. 'try{window.close();}catch(e){}'
			. '})();';

		return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html__( 'Age check', 'navexa-agecheck-for-woocommerce' ) . '</title>'
			. '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1.5rem;line-height:1.5;color:#1e1e1e;text-align:center}a{color:inherit}</style>'
			. '</head><body>'
			. '<h1 style="font-size:1.4rem">' . esc_html__( 'You can close this window', 'navexa-agecheck-for-woocommerce' ) . '</h1>'
			. '<p>' . esc_html__( 'Your age check is finished here. Go back to your checkout to place your order.', 'navexa-agecheck-for-woocommerce' ) . '</p>'
			. '<p><a href="' . esc_url( $checkout_url ) . '">' . esc_html__( 'Return to checkout', 'navexa-agecheck-for-woocommerce' ) . '</a></p>'
			. '<script>' . $script . '</script>'
			. '</body></html>';
	}
}
