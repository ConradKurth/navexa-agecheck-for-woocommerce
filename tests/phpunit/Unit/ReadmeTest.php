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

	public function test_readme_md_states_the_outage_numbers_the_code_uses(): void {
		$readme = self::read( 'README.md' );
		$this->assertStringContainsString( sprintf( 'the %d s timeout (`Client::TIMEOUT_SECONDS`)', Client::TIMEOUT_SECONDS ), $readme );
		$this->assertStringContainsString( sprintf( 'capped at %d h by `Release::MAX_BACKOFF`', intdiv( Release::MAX_BACKOFF, 3600 ) ), $readme );
		$this->assertStringContainsString( sprintf( '`MAX_ATTEMPTS` %d', Release::MAX_ATTEMPTS ), $readme );
		$this->assertStringContainsString( sprintf( 'the %d-minute sweep (`Sweep::INTERVAL`) queues held orders %d per run (`Sweep::BATCH`)', intdiv( Sweep::INTERVAL, 60 ), Sweep::BATCH ), $readme );
		$this->assertStringContainsString( sprintf( 'waits %d h (`Release::REVIEW_RECHECK`)', intdiv( Release::REVIEW_RECHECK, 3600 ) ), $readme );
	}
}
