<?php
/**
 * Renders field groups on the product page (classic + block product pages,
 * via the classic hook bridge).
 *
 * Markup contract with the frontend module:
 *  - wrapper  [data-opf-fields]
 *  - group    [data-opf-group="gid"]
 *  - field    [data-opf-field="fid"], hidden state = class + hidden attribute
 *  - inputs   name="opf[gid][fid]" (multi-choice: name="opf[gid][fid][]")
 *
 * Field metadata (type + conditionals) is embedded as window.OPF_FIELDS so
 * the client can mirror server-side visibility rules instantly. The server
 * remains the source of truth: everything is re-validated on add-to-cart.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render' ], 10 );
	}

	/**
	 * Render all matching groups for the current product.
	 */
	public static function render(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$groups = FieldGroups::for_product( $product );
		if ( empty( $groups ) ) {
			return;
		}

		Assets::enqueue_frontend( self::registry( $groups ) );

		$base_price = (float) $product->get_price( 'edit' );

		echo '<div class="opf-fields" data-opf-fields="' . esc_attr( (string) count( $groups ) ) . '">';

		foreach ( $groups as $entry ) {
			self::render_group( $entry['id'], $entry['title'], $entry['group'], $base_price );
		}

		echo '</div>';
	}

	/**
	 * Client registry: gid => fid => {type, conditionals}.
	 *
	 * @param array<int,array{id:int,title:string,group:FieldGroup}> $groups Groups.
	 */
	private static function registry( array $groups ): array {
		$registry = [];
		foreach ( $groups as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				$registry[ $gid ][ $field['id'] ] = [
					'type'         => $field['type'],
					'conditionals' => $field['conditionals'],
				];
			}
		}
		return $registry;
	}

	/**
	 * Render one group.
	 *
	 * @param int|string $gid        Group post id.
	 * @param string     $title      Group title.
	 * @param FieldGroup $group      Group data.
	 * @param float      $base_price Product base unit price.
	 */
	public static function render_group( $gid, string $title, FieldGroup $group, float $base_price ): void {
		// Seed conditionals with default selections.
		$values = [];
		foreach ( $group->data['fields'] as $field ) {
			$values[ $field['id'] ] = self::default_value( $field );
		}

		echo '<div class="opf-group" data-opf-group="' . esc_attr( (string) $gid ) . '">';

		if ( '' !== $title && apply_filters( 'opf_show_group_title', false, $gid ) ) {
			echo '<h3 class="opf-group__title">' . esc_html( $title ) . '</h3>';
		}

		foreach ( $group->data['fields'] as $field ) {
			self::render_field( $gid, $field, $values, $base_price );
		}

		echo '</div>';
	}

	/**
	 * Render a single field.
	 *
	 * @param string              $gid        Group id.
	 * @param array<string,mixed> $field      Normalized field.
	 * @param array<string,mixed> $values     Seeded values (for conditional state).
	 * @param float               $base_price Base unit price.
	 */
	private static function render_field( string $gid, array $field, array $values, float $base_price ): void {
		$fid      = $field['id'];
		$name     = sprintf( 'opf[%s][%s]', $gid, $fid );
		$hidden   = ! Evaluator::is_visible( $field, $values );
		$width    = 'opf-col-' . (string) $field['width'];

		echo '<div class="opf-field ' . esc_attr( $width ) . ( $hidden ? ' opf-field--hidden' : '' ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';

		$label_id = 'opf-input-' . esc_attr( $gid . '-' . $fid );

		echo '<label class="opf-field__label" for="' . esc_attr( $label_id ) . '">';
		echo '<span class="opf-field__label-text">' . esc_html( $field['label'] );
		if ( $field['required'] ) {
			echo ' <abbr class="required" title="' . esc_attr__( 'required', 'opf' ) . '">*</abbr>';
		}
		echo '</span>';
		if ( '' !== $field['description'] ) {
			echo '<span class="opf-field__description">' . esc_html( $field['description'] ) . '</span>';
		}
		echo '</label>';

		echo '<div class="opf-field__control">';

		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			self::render_choices( $gid, $name, $label_id, $field, $base_price );
		} else {
			self::render_input( $name, $label_id, $field );
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Choice-based controls.
	 *
	 * @param string              $gid        Group id.
	 * @param string              $name       Input base name.
	 * @param string              $label_id   Label target id.
	 * @param array<string,mixed> $field      Field data.
	 * @param float               $base_price Base unit price.
	 */
	private static function render_choices( string $gid, string $name, string $label_id, array $field, float $base_price ): void {
		$fid = $field['id'];

		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $label_id ) . '" class="opf-select">';
			foreach ( $field['choices'] as $choice ) {
				echo '<option value="' . esc_attr( $choice['slug'] ) . '"' . selected( $choice['selected'], true, false ) . '>'
					. esc_html( $choice['label'] . self::price_suffix( $choice['pricing'], $base_price ) )
					. '</option>';
			}
			echo '</select>';
			return;
		}

		$multi = 'checkbox' === $field['type'];
		$swatch = 'swatch' === $field['type'];
		$classes = 'opf-choices' . ( $swatch ? ' opf-choices--swatch' : '' );

		echo '<div class="' . esc_attr( $classes ) . '" role="' . ( $multi ? 'group' : 'radiogroup' ) . '" aria-labelledby="' . esc_attr( 'opf-label-' . $gid . '-' . $fid ) . '">';

		foreach ( $field['choices'] as $index => $choice ) {
			$input_id = sprintf( 'opf-%s-%s-%d', $gid, $fid, $index );
			$hint     = self::price_hint( $choice['pricing'] );
			$attrs    = sprintf(
				'name="%1$s" id="%2$s" value="%3$s"%4$s%5$s',
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $input_id ),
				esc_attr( $choice['slug'] ),
				$choice['selected'] ? ' checked="checked"' : '',
				$choice['disabled'] ? ' disabled="disabled"' : ''
			);

			echo '<label class="opf-choice" for="' . esc_attr( $input_id ) . '">';
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			echo '<span class="opf-choice__label">' . esc_html( $choice['label'] ) . '</span>';
			if ( '' !== $hint ) {
				echo '<span class="opf-choice__hint">' . wp_kses_post( $hint ) . '</span>';
			}
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * Text-like inputs.
	 *
	 * @param string              $name     Input name.
	 * @param string              $label_id Label target id.
	 * @param array<string,mixed> $field    Field data.
	 */
	private static function render_input( string $name, string $label_id, array $field ): void {
		$shared = sprintf( 'name="%s" id="%s"', esc_attr( $name ), esc_attr( $label_id ) );

		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea rows="3" ' . $shared . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'url':
				echo '<input type="url" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<input type="number" step="any" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				echo '<input type="text" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
		}
	}

	/**
	 * Human-readable hint for a choice pricing block.
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function price_hint( array $pricing ): string {
		if ( ! function_exists( 'wc_price' ) || 'none' === $pricing['type'] ) {
			return '';
		}
		if ( 'fixed' === $pricing['type'] ) {
			$amount = apply_filters( 'opf_fixed_price', (float) $pricing['amount'], $pricing );
			return wc_price( $amount );
		}
		if ( 'percent' === $pricing['type'] ) {
			return esc_html( (float) $pricing['amount'] ) . '%';
		}
		return '';
	}

	/**
	 * Option-label suffix for selects.
	 *
	 * @param array<string,mixed> $pricing    Pricing block.
	 * @param float               $base_price Base unit price.
	 */
	private static function price_suffix( array $pricing, float $base_price ): string {
		$hint = self::price_hint( $pricing );
		if ( '' === $hint ) {
			return '';
		}
		$plain = wp_strip_all_tags( $hint );
		return ' (+' . $plain . ')';
	}

	/**
	 * Default submitted value for a field (seeds conditional evaluation).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @return string|array
	 */
	private static function default_value( array $field ) {
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$selected = [];
			foreach ( $field['choices'] as $choice ) {
				if ( $choice['selected'] && ! $choice['disabled'] ) {
					$selected[] = $choice['slug'];
				}
			}
			return $selected;
		}
		return '';
	}
}
