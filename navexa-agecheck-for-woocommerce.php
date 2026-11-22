<?php
/**
 * Plugin Name:          Navexa AgeCheck for WooCommerce
 * Plugin URI:           https://navexa.co
 * Description:          Age verification for age-restricted WooCommerce orders, powered by Navexa AgeCheck. Verifies orders shipping to US addresses only.
 * Version:              1.0.0
 * Requires at least:    6.7
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Navexa
 * Author URI:           https://navexa.co
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          navexa-agecheck-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.9
 * WC tested up to:      11.3
 *
 * @package Navexa\AgeCheck
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'NAVEXA_AGECHECK_VERSION' ) ) {
	return; // A second copy of the plugin is active; the first one wins.
}

define( 'NAVEXA_AGECHECK_VERSION', '1.0.0' );
define( 'NAVEXA_AGECHECK_FILE', __FILE__ );

/*
 * PSR-4 autoloader for includes/ (Navexa\AgeCheck\Foo\Bar -> includes/Foo/Bar.php).
 * Composer is a dev-only dependency, so the shipped plugin carries its own.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Navexa\\AgeCheck\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9\\\\]+$/', $relative ) ) {
			return;
		}
		$path = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

\Navexa\AgeCheck\Plugin::boot( __FILE__ );
