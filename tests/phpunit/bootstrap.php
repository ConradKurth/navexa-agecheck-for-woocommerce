<?php
/**
 * PHPUnit bootstrap for the unit suite (Brain Monkey; no WordPress).
 *
 * @package Navexa\AgeCheck\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'NAVEXA_AGECHECK_VERSION', '0.1.0-test' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'NAVEXA_AGECHECK_FILE', dirname( __DIR__, 2 ) . '/navexa-agecheck-for-woocommerce.php' );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require __DIR__ . '/stubs.php';
