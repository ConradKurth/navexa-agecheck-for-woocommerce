<?php
/**
 * Order queries with meta conditions on both order storages.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Runs wc_get_orders() with a meta_query. HPOS (OrdersTableQuery) supports
 * meta_query; the posts-table data store silently drops it
 * (WC_Data_Store_WP::get_wp_query_args() skips the key), which would turn a
 * "denied orders" query into "every order". The condition therefore also
 * travels in QUERY_VAR instead (and meta_query is left out, which
 * WooCommerce 9.2+ warns about there), and cpt_meta_query() puts it back
 * into the WP_Query arguments through
 * woocommerce_order_data_store_cpt_get_orders_query.
 */
final class OrderQuery {

	const QUERY_VAR = 'navexa_agecheck_meta_query';

	/**
	 * Registers the posts-table bridge.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( self::class, 'cpt_meta_query' ), 10, 2 );
	}

	/**
	 * Order ids matching $args and the meta conditions in $args['meta_query'].
	 *
	 * @param array<string, mixed> $args wc_get_orders() arguments, meta_query included.
	 * @return int[]
	 */
	public static function ids( array $args ): array {
		if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) && ! self::hpos() ) {
			// WooCommerce 9.2+ warns about meta_query on the posts-table store.
			$args[ self::QUERY_VAR ] = $args['meta_query'];
			unset( $args['meta_query'] );
		}
		$args['return'] = 'ids';
		$found          = wc_get_orders( $args );
		if ( ! is_array( $found ) ) {
			return array();
		}
		$ids = array();
		foreach ( $found as $order ) {
			$ids[] = $order instanceof \WC_Order ? (int) $order->get_id() : (int) $order;
		}
		return $ids;
	}

	/**
	 * Whether orders live in the HPOS tables.
	 */
	public static function hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Puts QUERY_VAR back into a posts-table query as a meta_query clause.
	 *
	 * @param mixed $wp_query_args WP_Query arguments.
	 * @param mixed $query_vars    wc_get_orders() arguments.
	 * @return mixed
	 */
	public static function cpt_meta_query( $wp_query_args, $query_vars ) {
		if ( ! is_array( $wp_query_args ) ) {
			return $wp_query_args;
		}
		unset( $wp_query_args[ self::QUERY_VAR ] );
		if ( is_array( $query_vars ) && ! empty( $query_vars[ self::QUERY_VAR ] ) && is_array( $query_vars[ self::QUERY_VAR ] ) ) {
			$existing                    = isset( $wp_query_args['meta_query'] ) && is_array( $wp_query_args['meta_query'] ) ? $wp_query_args['meta_query'] : array();
			$existing[]                  = $query_vars[ self::QUERY_VAR ];
			$wp_query_args['meta_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the caller's own condition.
		}
		return $wp_query_args;
	}
}
