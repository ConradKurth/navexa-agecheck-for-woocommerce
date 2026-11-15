<?php
/**
 * Runs uninstall.php the way WordPress does (the plugin not loaded,
 * WooCommerce loaded) against the fixtures tests/wp-env/w4.php left, twice:
 * first with "Delete data on uninstall" off, then on.
 *
 *   wp --skip-plugins=navexa-agecheck-for-woocommerce eval-file tests/wp-env/w4-uninstall.php
 *
 * Destroys the plugin's state: run it last, then destroy the environment.
 * Never use `wp plugin uninstall` or `wp plugin delete` here: the plugin
 * directory is the repository, mapped into the container.
 *
 * @package Navexa\AgeCheck\Tests
 */

// phpcs:ignoreFile -- test script.

$GLOBALS['navexa_w4u_failures'] = 0;

function navexa_w4u_check( $ok, $what ) {
	if ( $ok ) {
		WP_CLI::log( 'ok   ' . $what );
		return;
	}
	++$GLOBALS['navexa_w4u_failures'];
	WP_CLI::log( 'FAIL ' . $what );
}

function navexa_w4u_plugin_options() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'navexa_agecheck_' ) . '%', $wpdb->esc_like( '_transient_navexa_agecheck_' ) . '%' ) );
}

function navexa_w4u_order_meta( $order_id ) {
	global $wpdb;
	$table = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	$col   = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'order_id' : 'post_id';
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} = %d AND meta_key LIKE %s", $order_id, $wpdb->esc_like( '_navexa_agecheck_' ) . '%' ) );
}

function navexa_w4u_run() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php' );
	}
	include WP_PLUGIN_DIR . '/navexa-agecheck-for-woocommerce/uninstall.php';
}

navexa_w4u_check( ! class_exists( 'Navexa\AgeCheck\Plugin', false ), 'the plugin is not loaded, as at uninstall' );
$fixtures = get_option( 'w4test_fixtures' );
navexa_w4u_check( is_array( $fixtures ) && ! empty( $fixtures['held'] ), 'fixtures from w4.php' );
if ( ! is_array( $fixtures ) ) {
	WP_CLI::error( 'run tests/wp-env/w4.php first' );
}
global $wpdb;
navexa_w4u_check( navexa_w4u_plugin_options() > 0, 'the plugin has options before uninstall' );
navexa_w4u_check( navexa_w4u_order_meta( $fixtures['meta_order'] ) > 0, 'the fixture order has AgeCheck meta' );

// Phase 1: "Delete data on uninstall" off (the default).
delete_option( 'navexa_agecheck_delete_data_on_uninstall' );
navexa_w4u_run();

navexa_w4u_check( 0 === navexa_w4u_plugin_options(), 'every navexa_agecheck_ option and transient is gone' );
navexa_w4u_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'navexa_agecheck_rate_limits' ) ) ), 'the rate-limit table is dropped' );
navexa_w4u_check( array() === as_get_scheduled_actions( array( 'group' => 'navexa-agecheck', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' ), 'no pending Action Scheduler actions in the plugin\'s group' );
navexa_w4u_check( false === wp_next_scheduled( 'navexa_agecheck_sweep_cron' ), 'the WP-Cron sweep is unscheduled' );
foreach ( $fixtures['held'] as $id ) {
	$order = wc_get_order( $id );
	$notes = array_map(
		static function ( $note ) {
			return $note->content;
		},
		wc_get_order_notes( array( 'order_id' => $id, 'limit' => 20 ) )
	);
	$moved = array_values( array_filter( $notes, static function ( $text ) {
		return false !== strpos( $text, 'It was not verified' );
	} ) );
	$email = array_values( array_filter( $notes, static function ( $text ) {
		return false !== strpos( $text, 'Order on-hold' );
	} ) );
	navexa_w4u_check( $order && 'on-hold' === $order->get_status(), "held order {$id} moved to On hold, not released" );
	navexa_w4u_check( 1 === count( $moved ) && false !== strpos( $moved[0], 'Awaiting age check' ), "held order {$id} has the uninstall note, from Awaiting age check (not Pending payment)" );
	navexa_w4u_check( array() === $email, "held order {$id}: no \"Order on hold\" email to the shopper" );
}
navexa_w4u_check( navexa_w4u_order_meta( $fixtures['meta_order'] ) > 0, 'order meta is kept as the audit trail by default' );
navexa_w4u_check( '' !== (string) get_post_meta( $fixtures['product'], '_navexa_agecheck_rule', true ), 'product age check settings are kept by default' );

// Phase 2: "Delete data on uninstall" on.
update_option( 'navexa_agecheck_delete_data_on_uninstall', 'yes' );
navexa_w4u_run();
navexa_w4u_check( 0 === navexa_w4u_order_meta( $fixtures['meta_order'] ), 'with Delete data on, order meta is deleted' );
navexa_w4u_check( 0 === navexa_w4u_order_meta( $fixtures['held'][0] ), 'with Delete data on, the held order\'s meta is deleted too' );
navexa_w4u_check( '' === (string) get_post_meta( $fixtures['product'], '_navexa_agecheck_rule', true ), 'with Delete data on, product settings are deleted' );
navexa_w4u_check( 0 === navexa_w4u_plugin_options(), 'the setting itself is gone' );

delete_option( 'w4test_fixtures' );
if ( $GLOBALS['navexa_w4u_failures'] > 0 ) {
	WP_CLI::error( $GLOBALS['navexa_w4u_failures'] . ' uninstall check(s) failed' );
}
WP_CLI::success( 'uninstall checks passed' );
