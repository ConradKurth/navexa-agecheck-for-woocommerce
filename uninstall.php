<?php
/**
 * Uninstall: deletes the plugin's options, secrets, locks, webhook markers,
 * cached connection info, scheduled actions, the rate-limit table and per-user
 * notice state, and moves orders still awaiting an age check to On hold.
 * Order meta (the audit trail) and product age-check settings are kept unless
 * "Delete data on uninstall" is on. See includes/Uninstall.php.
 *
 * @package Navexa\AgeCheck
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin is not loaded here, so neither is its autoloader.
require_once __DIR__ . '/includes/Uninstall.php';

\Navexa\AgeCheck\Uninstall::run();
