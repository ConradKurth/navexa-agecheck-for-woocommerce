<?php
/**
 * Which orders need an age check.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Decides from an order's line items whether it needs an age check.
 *
 * - The store rule (Options::rule()): every product, only products in chosen
 *   categories or tags, or every product except those.
 * - A per-product setting (PRODUCT_META) overrides the store rule:
 *   'require' or 'exempt'. A variation follows its parent product.
 * - The order's customer (customer_id, not whoever placed it) in an exempt
 *   role skips the check.
 * - Local pickup orders skip it (owner decision Q11c: staff check ID at the
 *   counter). An order is pickup when every shipping line is a pickup method.
 * - Orders created before the plugin was first activated are left alone.
 *
 * There is no minimum-total, payment-method or other shipping-method
 * exemption, and no fail-open switch. A line item whose product cannot be
 * loaded counts as needing the check. Once an order needs a check it keeps
 * needing one (REQUIRED is sticky), so changing the rules later never
 * releases a held order.
 */
final class Requirement {

	/**
	 * Product meta: '' (follow the store rule), 'require' or 'exempt'.
	 */
	const PRODUCT_META = '_navexa_agecheck_rule';

	const PRODUCT_REQUIRE = 'require';
	const PRODUCT_EXEMPT  = 'exempt';

	/**
	 * Shipping method ids that mean local pickup: the classic Local pickup
	 * method and the checkout block's pickup locations.
	 */
	const PICKUP_METHODS = array( 'local_pickup', 'pickup_location' );

	const REASON_REQUIRED          = 'restricted_items';
	const REASON_NO_RESTRICTED     = 'no_restricted_items';
	const REASON_PICKUP            = 'pickup';
	const REASON_EXEMPT_ROLE       = 'exempt_role';
	const REASON_BEFORE_ACTIVATION = 'before_activation';

	/**
	 * Whether $order needs an age check. A stored 'yes' is final; otherwise the
	 * rules are evaluated and the answer is recorded on the order (unsaved: the
	 * caller's save persists it).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_required( \WC_Order $order ): bool {
		if ( 'yes' === Meta::get( $order, Meta::REQUIRED ) ) {
			return true;
		}
		$result = self::evaluate( $order );
		if ( $result['required'] ) {
			Meta::set( $order, Meta::REQUIRED, 'yes' );
			Meta::set( $order, Meta::REQUIRED_REASON, '' );
			return true;
		}
		if ( 'no' !== Meta::get( $order, Meta::REQUIRED ) || Meta::get( $order, Meta::REQUIRED_REASON ) !== $result['reason'] ) {
			Meta::set( $order, Meta::REQUIRED, 'no' );
			Meta::set( $order, Meta::REQUIRED_REASON, $result['reason'] );
		}
		return false;
	}

	/**
	 * Evaluates the rules without reading or writing the stored answer.
	 * Anything that throws while reading the order counts as required.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{required: bool, reason: string}
	 */
	public static function evaluate( \WC_Order $order ): array {
		try {
			$created   = $order->get_date_created();
			$activated = Install::activated_at();
			if ( $created instanceof \DateTimeInterface && $activated > 0 && $created->getTimestamp() < $activated ) {
				return self::answer( false, self::REASON_BEFORE_ACTIVATION );
			}
			if ( self::is_pickup( $order ) ) {
				return self::answer( false, self::REASON_PICKUP );
			}
			if ( self::customer_exempt( $order ) ) {
				return self::answer( false, self::REASON_EXEMPT_ROLE );
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
				if ( self::product_requires( $product instanceof \WC_Product ? $product : null ) ) {
					return self::answer( true, self::REASON_REQUIRED );
				}
			}
			return self::answer( false, self::REASON_NO_RESTRICTED );
		} catch ( \Throwable $e ) {
			Client::log( 'error', sprintf( 'agecheck requirement unreadable order=%d; treated as required', $order->get_id() ) );
			return self::answer( true, self::REASON_REQUIRED );
		}
	}

	/**
	 * Whether a line item for $product needs an age check. null (a deleted
	 * product) does.
	 *
	 * @param \WC_Product|null $product Product or variation.
	 */
	public static function product_requires( ?\WC_Product $product ): bool {
		if ( null === $product ) {
			return true;
		}
		$parent_id = $product->get_parent_id();
		$own       = (string) $product->get_meta( self::PRODUCT_META, true, 'edit' );
		$parent    = $parent_id > 0 ? self::parent_setting( $parent_id ) : '';
		$setting   = in_array( $own, array( self::PRODUCT_REQUIRE, self::PRODUCT_EXEMPT ), true ) ? $own : $parent;
		if ( self::PRODUCT_REQUIRE === $setting ) {
			return true;
		}
		if ( self::PRODUCT_EXEMPT === $setting ) {
			return false;
		}

		$rule = Options::rule();
		if ( Options::RULE_ALL === $rule ) {
			return true;
		}
		$term_owner = $parent_id > 0 ? $parent_id : $product->get_id();
		$matches    = self::in_terms( $term_owner, 'product_cat', Options::term_ids( Options::RULE_CATEGORIES ) )
			|| self::in_terms( $term_owner, 'product_tag', Options::term_ids( Options::RULE_TAGS ) );
		return Options::RULE_INCLUDE === $rule ? $matches : ! $matches;
	}

	/**
	 * Whether every shipping line of $order is a pickup method. An order with
	 * no shipping lines is not a pickup order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_pickup( \WC_Order $order ): bool {
		$lines = $order->get_shipping_methods();
		if ( empty( $lines ) ) {
			return false;
		}
		/**
		 * Filters the shipping method ids that count as local pickup.
		 *
		 * @param string[]  $methods Method ids.
		 * @param \WC_Order $order   Order.
		 */
		$pickup = (array) apply_filters( 'navexa_agecheck_pickup_methods', self::PICKUP_METHODS, $order );
		foreach ( $lines as $line ) {
			if ( ! is_object( $line ) || ! is_callable( array( $line, 'get_method_id' ) ) || ! in_array( (string) $line->get_method_id(), $pickup, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the order's customer holds an exempt role. Guests never do.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function customer_exempt( \WC_Order $order ): bool {
		$roles = Options::exempt_roles();
		$id    = (int) $order->get_customer_id();
		if ( empty( $roles ) || $id <= 0 ) {
			return false;
		}
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		return array() !== array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * The parent product's own setting, or ''.
	 *
	 * @param int $parent_id Parent product id.
	 */
	private static function parent_setting( int $parent_id ): string {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product ) {
			return '';
		}
		$value = (string) $parent->get_meta( self::PRODUCT_META, true, 'edit' );
		return in_array( $value, array( self::PRODUCT_REQUIRE, self::PRODUCT_EXEMPT ), true ) ? $value : '';
	}

	/**
	 * Whether product $product_id has any of $term_ids in $taxonomy. For
	 * categories a chosen parent also covers its subcategories.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @param int[]  $term_ids   Term ids.
	 */
	private static function in_terms( int $product_id, string $taxonomy, array $term_ids ): bool {
		if ( empty( $term_ids ) ) {
			return false;
		}
		$own = array_map( 'intval', (array) wc_get_product_term_ids( $product_id, $taxonomy ) );
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			foreach ( $own as $term_id ) {
				$own = array_merge( $own, array_map( 'intval', (array) get_ancestors( $term_id, $taxonomy, 'taxonomy' ) ) );
			}
		}
		return array() !== array_intersect( $term_ids, $own );
	}

	/**
	 * One answer.
	 *
	 * @param bool   $required Whether a check is needed.
	 * @param string $reason   REASON_*.
	 * @return array{required: bool, reason: string}
	 */
	private static function answer( bool $required, string $reason ): array {
		return array(
			'required' => $required,
			'reason'   => $reason,
		);
	}
}
