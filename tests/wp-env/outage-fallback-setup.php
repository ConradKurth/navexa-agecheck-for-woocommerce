<?php
/**
 * Prepares a wp-env site for tests/wp-env/outage-fallback.mjs (TAX-1841):
 *
 *   wp eval-file tests/wp-env/prepayment-setup.php
 *   wp eval-file tests/wp-env/outage-fallback-setup.php
 *
 * The store takes and holds orders while AgeCheck is down
 * (Options::OUTAGE_FALLBACK = hold). It also forgets what AgeCheck reported
 * (Mode::flush()): the flow's first passed check asks GetIntegrationInfo
 * again, against the mock with links on, so the store profile carries
 * links_enabled. Empties the rate limit counters and the cached
 * availability answer, so a run starts clean.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

use Navexa\AgeCheck\Availability;
use Navexa\AgeCheck\Client;
use Navexa\AgeCheck\Options;

update_option( Options::OUTAGE_FALLBACK, Options::OUTAGE_HOLD );
\Navexa\AgeCheck\Mode::flush();
delete_transient( Availability::TRANSIENT_PREFIX . Client::for_key( Client::KEY_START )->key_fingerprint() );
\Navexa\AgeCheck\RateLimit::install();
$GLOBALS['wpdb']->query( 'DELETE FROM ' . \Navexa\AgeCheck\RateLimit::table() );

WP_CLI::success( 'outage fallback: hold' );
