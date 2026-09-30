<?php
/**
 * Field group builder: metaboxes on the opf_field_group edit screen.
 *
 * The builder UI is dependency-free vanilla JS (no jQuery, no build step)
 * talking to the REST API. The field-group model is a JSON document edited
 * through the UI and persisted via POST /opf/v1/groups.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service\Admin;

defined( 'ABSPATH' ) || exit;

final class Builder {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'disable_block_editor' ], 10, 2 );
		GroupDuplication::init();
	}

	/**
	 * Keep the builder out of the block editor.
	 *
	 * @param bool   $use       Whether block editor is used.
	 * @param string $post_type Post type.
	 */
	public static function disable_block_editor( bool $use, string $post_type ): bool {
		return 'opf_field_group' === $post_type ? false : $use;
	}

	/**
	 * Add metaboxes.
	 */
	public static function add_meta_boxes(): void {
		add_meta_box( 'opf-builder', __( 'Fields', 'open-product-fields-for-woocommerce' ), [ __CLASS__, 'render_builder' ], 'opf_field_group', 'normal', 'high' );
		add_meta_box( 'opf-placement', __( 'Placement', 'open-product-fields-for-woocommerce' ), [ __CLASS__, 'render_placement' ], 'opf_field_group', 'side', 'high' );
	}

	/**
	 * Builder metabox.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_builder( \WP_Post $post ): void {
		$group = \OPF\Service\FieldGroups::group_from_post( $post );
		$model = $group ? $group->data : [ 'schema' => 1, 'fields' => [], 'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above' ];
		?>
		<div id="opf-builder-app"
			data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-model="<?php echo esc_attr( (string) wp_json_encode( $model, JSON_UNESCAPED_UNICODE ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-rest="<?php echo esc_url( esc_url_raw( rest_url( 'opf/v1/groups' ) ) ); ?>"
			data-preview-rest="<?php echo esc_url( esc_url_raw( rest_url( 'opf/v1/preview' ) ) ); ?>">
			<noscript><?php esc_html_e( 'The field builder requires JavaScript.', 'open-product-fields-for-woocommerce' ); ?></noscript>
		</div>
		<?php
	}

	/**
	 * Placement metabox (terms the group applies to). Server-rendered
	 * multi-selects; empty selection = every product.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_placement( \WP_Post $post ): void {
		$group      = \OPF\Service\FieldGroups::group_from_post( $post );
		$cat_terms  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
		$tag_terms  = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => 500 ] );

		$selected = [ 'product_cat' => [], 'product_tag' => [] ];
		$auth     = 'all';
		$role     = '';
		$role_op  = 'in';
		if ( $group ) {
			foreach ( $group->data['rule_groups'] as $rule_group ) {
				foreach ( $rule_group['rules'] as $rule ) {
					if ( 'in' === $rule['operator'] && isset( $selected[ $rule['subject'] ] ) ) {
						$selected[ $rule['subject'] ] = array_merge( $selected[ $rule['subject'] ], $rule['terms'] );
					} elseif ( 'auth' === $rule['subject'] ) {
						$auth = 'not_in' === $rule['operator'] ? 'logged_out' : 'logged_in';
					} elseif ( 'user_role' === $rule['subject'] && ! empty( $rule['terms'][0] ) ) {
						$role    = (string) $rule['terms'][0];
						$role_op = 'not_in' === $rule['operator'] ? 'not_in' : 'in';
					}
				}
			}
		}
		$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : ( function_exists( 'wp_roles' ) ? wp_roles()->roles : [] );
		?>
		<p class="description"><?php esc_html_e( 'Leave category and tag filters empty to apply this group to every product. Visitor and role conditions below still apply.', 'open-product-fields-for-woocommerce' ); ?></p>
		<p><strong><?php esc_html_e( 'Product categories', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="8" id="opf-placement-cats" style="width:100%">
			<?php foreach ( (array) $cat_terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, $selected['product_cat'], true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><strong><?php esc_html_e( 'Product tags', 'open-product-fields-for-woocommerce' ); ?></strong></p>
		<select multiple size="8" id="opf-placement-tags" style="width:100%">
			<?php foreach ( (array) $tag_terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (string) $term->term_id, $selected['product_tag'], true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p><label for="opf-placement-auth"><strong><?php esc_html_e( 'Visitor authentication', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
		<select id="opf-placement-auth" style="width:100%">
			<option value="all" <?php selected( 'all', $auth ); ?>><?php esc_html_e( 'All visitors', 'open-product-fields-for-woocommerce' ); ?></option>
			<option value="logged_in" <?php selected( 'logged_in', $auth ); ?>><?php esc_html_e( 'Logged in', 'open-product-fields-for-woocommerce' ); ?></option>
			<option value="logged_out" <?php selected( 'logged_out', $auth ); ?>><?php esc_html_e( 'Logged out', 'open-product-fields-for-woocommerce' ); ?></option>
		</select>
		<p><label for="opf-placement-role"><strong><?php esc_html_e( 'User role', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
		<select id="opf-placement-role" style="width:100%">
			<option value=""><?php esc_html_e( 'Any role', 'open-product-fields-for-woocommerce' ); ?></option>
			<?php foreach ( (array) $roles as $role_id => $role_data ) : ?>
				<option value="<?php echo esc_attr( (string) $role_id ); ?>" <?php selected( (string) $role_id, $role ); ?>><?php echo esc_html( translate_user_role( (string) ( $role_data['name'] ?? $role_id ) ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<p><label for="opf-placement-role-operator"><?php esc_html_e( 'Role condition', 'open-product-fields-for-woocommerce' ); ?></label></p>
		<select id="opf-placement-role-operator" style="width:100%">
			<option value="in" <?php selected( 'in', $role_op ); ?>><?php esc_html_e( 'Has role', 'open-product-fields-for-woocommerce' ); ?></option>
			<option value="not_in" <?php selected( 'not_in', $role_op ); ?>><?php esc_html_e( 'Does not have role', 'open-product-fields-for-woocommerce' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Placement changes are saved together with the fields.', 'open-product-fields-for-woocommerce' ); ?></p>
		<?php
	}
}
