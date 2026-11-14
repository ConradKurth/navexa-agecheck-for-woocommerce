<?php
/**
 * Per-product age check setting.
 *
 * @package Navexa\AgeCheck
 */

namespace Navexa\AgeCheck\Admin;

use Navexa\AgeCheck\Requirement;

defined( 'ABSPATH' ) || exit;

/**
 * An "Age check" select in the product editor's General tab: follow the
 * store rule (default), always require, or never require. Variations follow
 * their parent. Saved with the product form, whose nonce and capability
 * WooCommerce checks before woocommerce_admin_process_product_object.
 */
final class ProductField {

	/**
	 * Registers the field.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ), 10, 1 );
	}

	/**
	 * Prints the select.
	 */
	public static function render(): void {
		if ( ! function_exists( 'woocommerce_wp_select' ) ) {
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => Requirement::PRODUCT_META,
				'label'       => __( 'Age check', 'navexa-agecheck-for-woocommerce' ),
				'options'     => self::options(),
				'desc_tip'    => true,
				'description' => __( 'Whether orders with this product need a Navexa AgeCheck. "Follow the store rule" uses WooCommerce > Settings > AgeCheck.', 'navexa-agecheck-for-woocommerce' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the select.
	 *
	 * @param mixed $product Product being saved.
	 */
	public static function save( $product ): void {
		if ( ! $product instanceof \WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Admin_Meta_Boxes::save_meta_boxes() checked woocommerce_meta_nonce.
		$value = isset( $_POST[ Requirement::PRODUCT_META ] ) ? sanitize_key( wp_unslash( $_POST[ Requirement::PRODUCT_META ] ) ) : '';
		if ( ! array_key_exists( $value, self::options() ) ) {
			return;
		}
		if ( '' === $value ) {
			$product->delete_meta_data( Requirement::PRODUCT_META );
		} else {
			$product->update_meta_data( Requirement::PRODUCT_META, $value );
		}
	}

	/**
	 * Select options.
	 *
	 * @return array<string, string>
	 */
	private static function options(): array {
		return array(
			''                           => __( 'Follow the store rule', 'navexa-agecheck-for-woocommerce' ),
			Requirement::PRODUCT_REQUIRE => __( 'Always needs an age check', 'navexa-agecheck-for-woocommerce' ),
			Requirement::PRODUCT_EXEMPT  => __( 'Never needs an age check', 'navexa-agecheck-for-woocommerce' ),
		);
	}
}
