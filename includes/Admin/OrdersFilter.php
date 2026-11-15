<?php
/**
 * AgeCheck filters on the Orders screen.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Admin;

use Navexa\AgeCheck\Hold;
use Navexa\AgeCheck\Meta;
use Navexa\AgeCheck\OrderQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "AgeCheck" dropdown to the Orders list, on both the HPOS screen and
 * the posts-table screen:
 *
 * - AWAITING: held, not denied (the "Awaiting age check" status tab also
 *   lists denied ones);
 * - DENIED: denied and waiting for staff to refund or cancel.
 *
 * The posts-table screen is WordPress's own WP_Query, so its filter adds a
 * meta_query to the request vars; the plugin itself never queries orders
 * that way.
 */
final class OrdersFilter {

	const PARAM    = 'navexa_agecheck';
	const AWAITING = 'awaiting';
	const DENIED   = 'denied_refund';

	/**
	 * Registers the dropdown and the query filters.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( self::class, 'render_hpos' ), 20, 1 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( self::class, 'filter_hpos_args' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'render_posts' ), 20, 1 );
		add_filter( 'request', array( self::class, 'filter_posts_request' ) );
	}

	/**
	 * The Orders list filtered by $filter.
	 *
	 * @param string $filter AWAITING or DENIED.
	 */
	public static function url( string $filter ): string {
		$base = OrderQuery::hpos()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );
		return add_query_arg( self::PARAM, $filter, $base );
	}

	/**
	 * The dropdown on the HPOS screen.
	 *
	 * @param mixed $order_type Order type of the list.
	 */
	public static function render_hpos( $order_type = 'shop_order' ): void {
		if ( 'shop_order' === $order_type ) {
			self::render_select();
		}
	}

	/**
	 * The dropdown on the posts-table screen.
	 *
	 * @param mixed $post_type Post type of the list.
	 */
	public static function render_posts( $post_type = '' ): void {
		if ( 'shop_order' === $post_type ) {
			self::render_select();
		}
	}

	/**
	 * The query args of the HPOS list.
	 *
	 * @param mixed $args Query args.
	 * @return mixed
	 */
	public static function filter_hpos_args( $args ) {
		$filter = self::current();
		if ( '' === $filter || ! is_array( $args ) ) {
			return $args;
		}
		return self::apply( $args, $filter, 'status' );
	}

	/**
	 * The request vars of the posts-table list.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public static function filter_posts_request( $vars ) {
		global $typenow;
		$filter = self::current();
		if ( '' === $filter || ! is_array( $vars ) || ! is_admin() || 'shop_order' !== $typenow ) {
			return $vars;
		}
		return self::apply( $vars, $filter, 'post_status' );
	}

	/**
	 * Adds the filter's status and meta conditions to $args.
	 *
	 * @param array<string, mixed> $args       Query args.
	 * @param string               $filter     AWAITING or DENIED.
	 * @param string               $status_key status (wc_get_orders) or post_status (WP_Query).
	 * @return array<string, mixed>
	 */
	public static function apply( array $args, string $filter, string $status_key ): array {
		$meta = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		if ( self::AWAITING === $filter ) {
			$args[ $status_key ] = array( 'wc-' . Hold::STATUS );
			$meta[]              = array(
				'relation' => 'OR',
				array(
					'key'     => Meta::STATUS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Meta::STATUS,
					'value'   => Meta::STATUS_DENIED,
					'compare' => '!=',
				),
			);
		} else {
			$meta[] = array(
				'key'   => Meta::REFUND,
				'value' => Meta::REFUND_MANUAL,
			);
		}
		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list filter.
		return $args;
	}

	/**
	 * Ids of the orders a filter lists (any storage), at most $limit.
	 *
	 * @param string $filter AWAITING or DENIED.
	 * @param int    $limit  Most ids.
	 * @return int[]
	 */
	public static function order_ids( string $filter, int $limit ): array {
		return OrderQuery::ids( self::apply( array( 'limit' => $limit ), $filter, 'status' ) );
	}

	/**
	 * The selected filter from the list's GET parameters, or ''.
	 */
	public static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter, like WooCommerce's own.
		$value = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		return in_array( $value, array( self::AWAITING, self::DENIED ), true ) ? $value : '';
	}

	/**
	 * Prints the dropdown.
	 */
	private static function render_select(): void {
		$current = self::current();
		$options = array(
			''             => __( 'AgeCheck: any', 'navexa-agecheck-for-woocommerce' ),
			self::AWAITING => __( 'AgeCheck: awaiting', 'navexa-agecheck-for-woocommerce' ),
			self::DENIED   => __( 'AgeCheck: denied, refund required', 'navexa-agecheck-for-woocommerce' ),
		);
		printf( '<select name="%s" id="navexa-agecheck-filter">', esc_attr( self::PARAM ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}
