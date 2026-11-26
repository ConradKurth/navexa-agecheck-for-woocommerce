<?php
/**
 * Readme tests: the merchant and developer docs say what shoppers and the
 * store see when AgeCheck cannot be reached, with the copy and the numbers
 * the code uses.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Release;
use Navexa\AgeCheck\Sweep;
use Navexa\AgeCheck\Tests\TestCase;

final class ReadmeTest extends TestCase {

	/**
	 * The opening words of every shopper-facing outage message.
	 */
	const OUTAGE_COPY = 'Age verification is temporarily unavailable';

	/**
	 * The other places a shopper sees an outage message: the Verify age
	 * answer, the checkout script's copy of it and the "Verify now" page.
	 */
	const OUTAGE_SOURCES = array( 'includes/Start.php', 'includes/Frontend.php', 'includes/OrderVerify.php' );

	private static function read( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $file );
	}

	public function test_both_readmes_have_an_outage_section(): void {
		$this->assertStringContainsString( "= What happens when AgeCheck can't be reached? =", self::read( 'readme.txt' ) );
		$this->assertStringContainsString( "## When AgeCheck can't be reached", self::read( 'README.md' ) );
	}

	public function test_readme_txt_quotes_the_shoppers_outage_message(): void {
		// A copy change in any of these must not leave the readme quoting old words.
		$this->assertStringStartsWith( self::OUTAGE_COPY, Gate::message( Gate::REFUSE_UNAVAILABLE ) );
		$drifted = array();
		foreach ( self::OUTAGE_SOURCES as $source ) {
			if ( false === strpos( self::read( $source ), "__( '" . self::OUTAGE_COPY ) ) {
				$drifted[] = $source;
			}
		}
		$this->assertSame( array(), $drifted, 'These files no longer start an outage message with the words readme.txt quotes.' );
		$this->assertStringContainsString( '"' . self::OUTAGE_COPY . '"', self::read( 'readme.txt' ) );
	}

	public function test_readme_txt_states_the_release_timings_the_code_uses(): void {
		$readme = self::read( 'readme.txt' );
		$this->assertStringContainsString( sprintf( 'a check of held orders every %d minutes (%d orders at a time)', intdiv( Sweep::INTERVAL, 60 ), Sweep::BATCH ), $readme );
		$this->assertStringContainsString( sprintf( 'asks about it again after %d hours', intdiv( Release::REVIEW_RECHECK, 3600 ) ), $readme );
	}

	/**
	 * The listing's Description fits 2,500 characters, and every section
	 * fits wordpress.org's readme parser, which trims a section past its
	 * limit in words (WordPressdotorg\Plugin_Directory\Readme\Parser:
	 * trim_length( ..., 'words' ); Plugin Check bundles the same rule and
	 * reports it as "characters"): 5,000 for the FAQ and the Changelog,
	 * 2,500 for every other section, and the Description is measured with
	 * every section the parser does not know (External services, Source
	 * code and build) appended to it as other notes.
	 */
	public function test_readme_txt_sections_fit_the_directory_limits(): void {
		$sections = array();
		$current  = '';
		foreach ( preg_split( '/\R/', self::read( 'readme.txt' ) ) as $line ) {
			if ( 1 === preg_match( '/^==\s*(.+?)\s*==\s*$/', $line, $m ) ) {
				$current              = $m[1];
				$sections[ $current ] = '';
				continue;
			}
			if ( '' !== $current ) {
				$sections[ $current ] .= $line . "\n";
			}
		}
		$this->assertArrayHasKey( 'Description', $sections );
		$this->assertLessThanOrEqual( 2500, mb_strlen( trim( $sections['Description'] ) ), 'the Description fits 2,500 characters: details go in the FAQ' );

		$known  = array(
			'Description'                => 'description',
			'Installation'               => 'installation',
			'Frequently Asked Questions' => 'faq',
			'Screenshots'                => 'screenshots',
			'Changelog'                  => 'changelog',
			'Upgrade Notice'             => 'upgrade_notice',
		);
		$parsed = array( 'other_notes' => '' );
		foreach ( $sections as $title => $content ) {
			if ( isset( $known[ $title ] ) ) {
				$parsed[ $known[ $title ] ] = trim( $content );
			} else {
				$parsed['other_notes'] .= '<h3>' . $title . '</h3>' . trim( $content );
			}
		}
		$parsed['description'] .= "\n" . $parsed['other_notes'];
		unset( $parsed['other_notes'], $parsed['upgrade_notice'] );
		$limits = array(
			'faq'       => 5000,
			'changelog' => 5000,
		);
		foreach ( $parsed as $section => $content ) {
			$words = count( preg_split( '/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE ) );
			$limit = isset( $limits[ $section ] ) ? $limits[ $section ] : 2500;
			$this->assertLessThan( $limit * 2, $words, "the parser would trim {$section} ({$words} words and spaces, limit {$limit} words)" );
		}
	}

	public function test_readme_md_states_the_outage_numbers_the_code_uses(): void {
		$readme = self::read( 'README.md' );
		$this->assertStringContainsString( sprintf( 'the %d s timeout (`Client::TIMEOUT_SECONDS`)', Client::TIMEOUT_SECONDS ), $readme );
		$this->assertStringContainsString( sprintf( 'capped at %d h by `Release::MAX_BACKOFF`', intdiv( Release::MAX_BACKOFF, 3600 ) ), $readme );
		$this->assertStringContainsString( sprintf( '`MAX_ATTEMPTS` %d', Release::MAX_ATTEMPTS ), $readme );
		$this->assertStringContainsString( sprintf( 'the %d-minute sweep (`Sweep::INTERVAL`) queues held orders %d per run (`Sweep::BATCH`)', intdiv( Sweep::INTERVAL, 60 ), Sweep::BATCH ), $readme );
		$this->assertStringContainsString( sprintf( 'waits %d h (`Release::REVIEW_RECHECK`)', intdiv( Release::REVIEW_RECHECK, 3600 ) ), $readme );
	}
}
