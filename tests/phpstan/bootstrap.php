<?php
/**
 * Constants PHPStan cannot see: the plugin defines them at load time, and the
 * optional ones come from wp-config.php.
 *
 * @package Navexa\AgeCheck\Tests
 */

define( 'NAVEXA_AGECHECK_VERSION', '0.0.0' );
define( 'NAVEXA_AGECHECK_FILE', __FILE__ );
define( 'NAVEXA_AGECHECK_API_URL', 'https://verify.navexa.co' );
define( 'NAVEXA_AGECHECK_ALLOW_TEST_RELEASE', false );
define( 'WC_VERSION', '11.3.0' );
