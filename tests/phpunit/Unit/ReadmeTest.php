<?php
/**
 * Readme tests: the merchant and developer docs say what shoppers and the
 * store see when AgeCheck cannot be reached, in the copy the code shows.
 *
 * @package Navexa\AgeCheck\Tests
 */

namespace Navexa\AgeCheck\Tests\Unit;

use Navexa\AgeCheck\Checkout\Gate;
use Navexa\AgeCheck\Tests\TestCase;

final class ReadmeTest extends TestCase {

	/**
	 * The opening words of every shopper-facing outage message.
	 */
	const OUTAGE_COPY = 'Age verification is temporarily unavailable';

	private static function read( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $file );
	}

	public function test_readme_txt_says_what_happens_when_agecheck_cannot_be_reached(): void {
		$readme = self::read( 'readme.txt' );
		$this->assertStringContainsString( "= What happens when AgeCheck can't be reached? =", $readme );
		$this->assertStringContainsString( '* If AgeCheck cannot be reached, the plugin fails closed', $readme );
		$this->assertStringContainsString( 'There is no setting that lets orders through during an outage.', $readme );
	}

	public function test_readme_txt_quotes_the_shoppers_outage_message(): void {
		// A copy change in the gate must not leave the readme quoting old words.
		$this->assertStringStartsWith( self::OUTAGE_COPY, Gate::message( Gate::REFUSE_UNAVAILABLE ) );
		$this->assertStringContainsString( '"' . self::OUTAGE_COPY . '"', self::read( 'readme.txt' ) );
	}

	public function test_readme_md_documents_the_outage_path(): void {
		$readme = self::read( 'README.md' );
		$this->assertStringContainsString( "## When AgeCheck can't be reached", $readme );
		$this->assertStringContainsString( 'Gate::REFUSE_UNAVAILABLE', $readme );
	}
}
