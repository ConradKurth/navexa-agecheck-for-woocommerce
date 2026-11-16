<?php
/**
 * Uninstall: what deleting the plugin removes, and what it keeps.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Run from uninstall.php, where the plugin is not loaded: this class names
 * every option, table, hook and meta key literally and uses no other plugin
 * class.
 *
 * Always removed, on every site of a network:
 * - every navexa_agecheck_* option (keys, secrets, install state, settings,
 *   locks, webhook dedupe markers, sweep state, cached connection mode) and
 *   navexa_agecheck_* transients (connection info, notice counts, flashes);
 * - the rate-limit table {prefix}navexa_agecheck_rate_limits;
 * - the plugin's Action Scheduler actions (group navexa-agecheck: release,
 *   ReportShipment and sweep runs; only when Action Scheduler is loaded) and
 *   the WP-Cron sweep hook;
 * - every user's dismissed-notice state (user meta, network-wide).
 *
 * Kept unless "Delete data on uninstall" (navexa_agecheck_delete_data_on_uninstall)
 * is on: the plugin's order meta (_navexa_agecheck_*, the audit trail of
 * each order's age check, in the HPOS meta table and in post meta) and each
 * product's age check setting (_navexa_agecheck_rule). Order notes are
 * always kept.
 *
 * Orders still "Awaiting age check" would vanish from the Orders screen once
 * the status is gone, so they are moved to On hold with an order note: never
 * released. That needs WooCommerce active; without it they keep the status.
 */
final class Uninstall {

	const OPTION_PREFIX   = 'navexa_agecheck_';
	const META_PREFIX     = '_navexa_agecheck_';
	const DELETE_DATA     = 'navexa_agecheck_delete_data_on_uninstall';
	const AS_GROUP        = 'navexa-agecheck';
	const CRON_HOOK       = 'navexa_agecheck_sweep_cron';
	const USER_META       = 'navexa_agecheck_dismissed_counts';
	const USER_META_LINKS = 'navexa_agecheck_dismissed_links';
	const HOLD_STATUS     = 'wc-agecheck-hold';
	const HELD_BATCH      = 100;
	const HELD_MAX_ORDERS = 5000;

	/**
	 * Uninstalls from this site, or from every site of a network.
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::site();
				restore_current_blog();
			}
		} else {
			self::site();
		}
		// User meta is network-wide.
		delete_metadata( 'user', 0, self::USER_META, '', true );
		delete_metadata( 'user', 0, self::USER_META_LINKS, '', true );
	}

	/**
	 * Uninstalls from the current site.
	 */
	public static function site(): void {
		global $wpdb;
		$delete_data = 'yes' === get_option( self::DELETE_DATA, 'no' );

		self::release_hold_status();
		if ( $delete_data ) {
			self::delete_meta();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: one-off scan of this plugin's own option names.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_' . self::OPTION_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::OPTION_PREFIX ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_option( (string) $name );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall: drop the plugin's own rate-limit table.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}navexa_agecheck_rate_limits" );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Moves this site's "Awaiting age check" orders to On hold, with a note.
	 * Ids come from the authoritative order table directly: once the plugin
	 * is inactive its status is not registered, and a wc_get_orders() status
	 * query for it would match other orders.
	 */
	public static function release_hold_status(): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		// With the plugin inactive, WooCommerce would read the unknown
		// status as "pending": pending to on-hold sends the shopper the
		// "Order on hold" email and the store a second "New order" email.
		// Known for the move, the transition is agecheck-hold to on-hold,
		// which sends nothing; the two emails are also switched off for it.
		$known    = static function ( $statuses ) {
			if ( is_array( $statuses ) && ! isset( $statuses[ self::HOLD_STATUS ] ) ) {
				$statuses[ self::HOLD_STATUS ] = _x( 'Awaiting age check', 'Order status', 'navexa-agecheck-for-woocommerce' );
			}
			return $statuses;
		};
		$no_email = static function () {
			return false;
		};
		add_filter( 'wc_order_statuses', $known );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', $no_email );
		add_filter( 'woocommerce_email_enabled_new_order', $no_email );
		try {
			self::move_held_orders();
		} finally {
			remove_filter( 'wc_order_statuses', $known );
			remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', $no_email );
			remove_filter( 'woocommerce_email_enabled_new_order', $no_email );
		}
	}

	/**
	 * The batches of release_hold_status().
	 */
	private static function move_held_orders(): void {
		global $wpdb;
		$hpos  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$after = 0;
		$moved = 0;
		while ( $moved < self::HELD_MAX_ORDERS ) {
			if ( $hpos ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: ids of orders in the plugin's own status.
				$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE status = %s AND id > %d ORDER BY id LIMIT %d", self::HOLD_STATUS, $after, self::HELD_BATCH ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: ids of orders in the plugin's own status.
				$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type = 'shop_order' AND ID > %d ORDER BY ID LIMIT %d", self::HOLD_STATUS, $after, self::HELD_BATCH ) );
			}
			if ( empty( $ids ) ) {
				return;
			}
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$after = max( $after, $id ); // Never ask twice: an order that cannot be moved stays where it is.
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order ) {
					$order->update_status( 'on-hold', __( 'Navexa AgeCheck was uninstalled while this order was awaiting its age check. It was not verified: check the customer\'s age before you ship it.', 'navexa-agecheck-for-woocommerce' ) );
				}
				++$moved;
			}
		}
	}

	/**
	 * Deletes the plugin's order meta (HPOS meta table and post meta) and
	 * product meta (post meta).
	 */
	public static function delete_meta(): void {
		global $wpdb;
		$like = $wpdb->esc_like( self::META_PREFIX ) . '%';
		if ( self::table_exists( $wpdb->prefix . 'wc_orders_meta' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall with "delete data" on: the plugin's own order meta.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE %s", $like ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall with "delete data" on: the plugin's own order (posts storage) and product meta.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) );
		wp_cache_flush();
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: one-off check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
