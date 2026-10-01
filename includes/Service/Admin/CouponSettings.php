<?php
/** Per-coupon percentage discount scope controls. */

namespace OPF\Service\Admin;

defined( 'ABSPATH' ) || exit;

final class CouponSettings {
	public static function init(): void {
		add_action( 'woocommerce_coupon_options', [ __CLASS__, 'render_field' ], 10, 2 );
		add_action( 'woocommerce_coupon_options_save', [ __CLASS__, 'save_field' ], 10, 2 );
	}

	/** Render WAPF-compatible metadata so existing coupon imports keep working. */
	public static function render_field( $coupon_id, $coupon = null ): void {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}

		$enabled = self::coupon_excludes_addons( $coupon );
		echo '<div class="opf-discount-option" style="display:none">';
		woocommerce_wp_checkbox(
			[
				'id'          => 'opf_excl_addons',
				'value'       => $enabled ? 'yes' : 'no',
				'cbvalue'     => 'yes',
				'label'       => __( 'Calculate only on base price', 'open-product-fields-for-woocommerce' ),
				'description' => __( 'Calculate percentage discounts on the base product price and exclude Open Product Fields add-on pricing.', 'open-product-fields-for-woocommerce' ),
			]
		);
		echo '</div><script>document.addEventListener("DOMContentLoaded",function(){var wrap=document.querySelector(".opf-discount-option"),type=document.getElementById("discount_type");if(!wrap||!type)return;function toggle(){wrap.style.display=type.value==="percent"?"block":"none";}toggle();type.addEventListener("change",toggle);});</script>';
	}

	/** Save the checkbox to WAPF's established coupon meta key. */
	public static function save_field( $coupon_id, $coupon = null ): void {
		$value = isset( $_POST['opf_excl_addons'] ) && function_exists( 'wp_unslash' ) && function_exists( 'sanitize_text_field' )
			? sanitize_text_field( wp_unslash( $_POST['opf_excl_addons'] ) )
			: '';
		$enabled = 'yes' === $value;

		if ( is_object( $coupon ) && method_exists( $coupon, 'update_meta_data' ) && method_exists( $coupon, 'delete_meta_data' ) && method_exists( $coupon, 'save' ) ) {
			if ( $enabled ) {
				$coupon->update_meta_data( 'wapf_excl_addons', 'yes' );
			} else {
				$coupon->delete_meta_data( 'wapf_excl_addons' );
			}
			$coupon->save();
			return;
		}

		if ( $enabled ) {
			update_post_meta( (int) $coupon_id, 'wapf_excl_addons', 'yes' );
		} else {
			delete_post_meta( (int) $coupon_id, 'wapf_excl_addons' );
		}
	}

	/** WAPF coupon imports use an absent meta value as unchecked/default-off. */
	public static function coupon_excludes_addons( $coupon ): bool {
		return is_object( $coupon ) && method_exists( $coupon, 'get_meta' ) && 'yes' === $coupon->get_meta( 'wapf_excl_addons' );
	}
}
