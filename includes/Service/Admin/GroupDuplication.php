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
		$new_id = self::create_copy( $post );
		if ( is_wp_error( $new_id ) ) {
			$error_code = $new_id->get_error_code();
			$response   = 'opf_forbidden' === $error_code ? 403 : ( 'opf_save_failed' === $error_code ? 500 : 400 );
			wp_die( esc_html( $new_id->get_error_message() ), '', [ 'response' => $response ] );
		}

		$edit_url = get_edit_post_link( $new_id, 'url' ) ?: admin_url( 'edit.php?post_type=opf_field_group' );
		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Create the published copy after enforcing the same capabilities as the UI.
	 *
	 * @return int|\WP_Error New post ID or a safe error.
	 */
	public static function create_copy( \WP_Post $post ) {
		if ( 'opf_field_group' !== $post->post_type ) {
			return new \WP_Error( 'opf_wrong_post_type', __( 'Only field groups can be duplicated.', 'open-product-fields-for-woocommerce' ) );
		}
		if ( ! self::can_duplicate( $post ) ) {
			return new \WP_Error( 'opf_forbidden', __( 'You are not allowed to duplicate this field group.', 'open-product-fields-for-woocommerce' ) );
		}

		try {
			$group = FieldGroups::group_from_post( $post );
			if ( ! $group ) {
				return new \WP_Error( 'opf_invalid_group', __( 'This field group cannot be duplicated because its data is invalid.', 'open-product-fields-for-woocommerce' ) );
			}
			$data = FieldGroupDuplicator::duplicate( $group->data );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'opf_invalid_group', __( 'Could not prepare this field group for duplication.', 'open-product-fields-for-woocommerce' ) );
		}

		$new_id = FieldGroups::save(
			0,
			$data,
			[
				'title'  => sprintf( '%s - %s', $post->post_title, __( 'Copy', 'open-product-fields-for-woocommerce' ) ),
				'status' => 'publish',
			]
		);
		return $new_id ?: new \WP_Error( 'opf_save_failed', __( 'Could not save the duplicated field group.', 'open-product-fields-for-woocommerce' ) );
	}

	private static function can_duplicate( \WP_Post $post ): bool {
		return current_user_can( 'edit_post', $post->ID ) && current_user_can( 'publish_products' );
	}

	private static function nonce_action( int $post_id ): string {
		return 'opf_duplicate_field_group_' . $post_id;
	}
}
