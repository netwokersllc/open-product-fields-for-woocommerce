<?php
/**
 * Secure global field-group duplication action.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service\Admin;

use OPF\Engine\FieldGroupDuplicator;
use OPF\Service\FieldGroups;

defined( 'ABSPATH' ) || exit;

final class GroupDuplication {

	/** Register list and admin-post hooks. */
	public static function init(): void {
		add_filter( 'post_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_action( 'admin_post_opf_duplicate_field_group', [ __CLASS__, 'duplicate' ] );
	}

	/**
	 * Add a nonce-protected duplicate action to field-group rows.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @param \WP_Post             $post    Current row.
	 * @return array<string,string>
	 */
	public static function row_action( array $actions, \WP_Post $post ): array {
		if ( 'opf_field_group' !== $post->post_type || ! self::can_duplicate( $post ) ) {
			return $actions;
		}

		$url = add_query_arg(
			[
				'action'  => 'opf_duplicate_field_group',
				'post_id' => $post->ID,
			],
			admin_url( 'admin-post.php' )
		);
		$url = wp_nonce_url( $url, self::nonce_action( $post->ID ) );
		$actions['duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'open-product-fields-for-woocommerce' ) . '</a>';
		return $actions;
	}

	/** Handle the authenticated duplicate request. */
	public static function duplicate(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'opf_field_group' !== $post->post_type ) {
			wp_die( esc_html__( 'Field group not found.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 404 ] );
		}

		check_admin_referer( self::nonce_action( $post_id ) );
		if ( ! self::can_duplicate( $post ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this field group.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 403 ] );
		}

		try {
			$group = FieldGroups::group_from_post( $post );
		} catch ( \Throwable $error ) {
			$group = null;
		}
		if ( ! $group ) {
			wp_die( esc_html__( 'This field group cannot be duplicated because its data is invalid.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 400 ] );
		}

		try {
			$data = FieldGroupDuplicator::duplicate( $group->data );
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Could not create unique field IDs for this copy.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 500 ] );
		}

		$new_id = FieldGroups::save(
			0,
			$data,
			[
				'title'  => sprintf( '%s - %s', $post->post_title, __( 'Copy', 'open-product-fields-for-woocommerce' ) ),
				'status' => 'publish',
			]
		);
		if ( ! $new_id ) {
			wp_die( esc_html__( 'Could not save the duplicated field group.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 500 ] );
		}

		wp_safe_redirect( get_edit_post_link( $new_id, 'url' ) );
		exit;
	}

	private static function can_duplicate( \WP_Post $post ): bool {
		return current_user_can( 'edit_post', $post->ID ) && current_user_can( 'publish_products' );
	}

	private static function nonce_action( int $post_id ): string {
		return 'opf_duplicate_field_group_' . $post_id;
	}
}
