<?php
/**
 * i18n (W4 PR 16): the translation template covers every PHP string and the
 * JavaScript and block.json strings, and the plugin header points at it.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase {

	const DOMAIN = 'navexa-agecheck-for-woocommerce';

	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * The template's msgids (continuation lines joined, C escapes undone).
	 *
	 * @return array<string, true>
	 */
	private static function pot_msgids(): array {
		$lines  = file( self::root() . '/languages/' . self::DOMAIN . '.pot', FILE_IGNORE_NEW_LINES );
		$ids    = array();
		$current = null;
		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
				$current = $m[1];
				continue;
			}
			if ( null !== $current && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$current .= $m[1];
				continue;
			}
			if ( null !== $current ) {
				$ids[ stripcslashes( $current ) ] = true;
				$current                          = null;
			}
		}
		return $ids;
	}

	public function test_the_plugin_header_names_the_text_domain_and_its_path(): void {
		$header = (string) file_get_contents( self::root() . '/navexa-agecheck-for-woocommerce.php' );
		$this->assertMatchesRegularExpression( '/^ \* Text Domain:\s+' . self::DOMAIN . '$/m', $header );
		$this->assertMatchesRegularExpression( '#^ \* Domain Path:\s+/languages$#m', $header );
	}

	public function test_every_php_string_is_in_the_template(): void {
		$ids     = self::pot_msgids();
		$missing = array();
		$checked = 0;
		$files   = array_merge( glob( self::root() . '/includes/*.php' ), glob( self::root() . '/includes/*/*.php' ), array( self::root() . '/uninstall.php' ) );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			// First argument of the translation functions, single-quoted literals only.
			preg_match_all( "/\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n_noop)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches );
			foreach ( $matches[1] as $literal ) {
				++$checked;
				$text = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $literal );
				if ( ! isset( $ids[ $text ] ) ) {
					$missing[] = basename( $file ) . ': ' . $text;
				}
			}
		}
		$this->assertGreaterThan( 150, $checked, 'the scan finds the strings' );
		$this->assertSame( array(), $missing, 'Regenerate languages/' . self::DOMAIN . '.pot (README: i18n)' );
	}

	public function test_javascript_and_block_json_strings_are_in_the_template(): void {
		$ids = self::pot_msgids();
		foreach ( array( 'Verify age', 'Navexa AgeCheck shows this only when the cart has age-restricted items.', 'Age verification', 'Age verification (orders without payment)' ) as $text ) {
			$this->assertArrayHasKey( $text, $ids, $text );
		}
	}

	public function test_block_json_declares_the_text_domain(): void {
		foreach ( array( 'verify-age', 'verify-age-no-payment' ) as $block ) {
			$meta = json_decode( (string) file_get_contents( self::root() . '/assets/src/blocks/' . $block . '/block.json' ), true );
			$this->assertSame( self::DOMAIN, $meta['textdomain'], $block );
		}
	}
}
