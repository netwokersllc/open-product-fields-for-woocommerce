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

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * WAPF field-type names emitted in compat mode (theme CSS hooks).
	 */
	private const COMPAT_TYPE_NAMES = [
		'text'     => 'text',
		'textarea' => 'textarea',
		'url'      => 'url',
		'number'   => 'number',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'swatch'   => 'text-swatch',
	];

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render' ], 10 );
	}

	/**
	 * Theme compat mode: emit the legacy wapf-* class skeleton and data
	 * attributes so existing theme CSS/JS keeps working after cutover.
	 */
	public static function compat(): bool {
		return (bool) apply_filters( 'opf_theme_compat', get_option( 'opf_theme_compat', 'yes' ) === 'yes' );
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

		echo '<div class="opf-fields' . ( self::compat() ? ' wapf' : '' ) . '" data-opf-fields="' . esc_attr( (string) count( $groups ) ) . '">';

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
		$compat   = self::compat();

		$classes = [ 'opf-field', $width ];
		if ( $compat ) {
			$classes[] = 'wapf-field-container';
			$classes[] = 'wapf-field-' . ( self::COMPAT_TYPE_NAMES[ $field['type'] ] ?? 'text' );
		}
		if ( $hidden ) {
			$classes[] = 'opf-field--hidden';
			if ( $compat ) {
				$classes[] = 'wapf-hide';
			}
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $fid ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';

		$label_id = 'opf-input-' . esc_attr( $gid . '-' . $fid );
		$compat   = self::compat();

		echo '<label class="opf-field__label" id="opf-label-' . esc_attr( $gid . '-' . $fid ) . '" for="' . esc_attr( $label_id ) . '">';
		echo '<span class="opf-field__label-text' . ( $compat ? ' wapf-field-label' : '' ) . '">' . esc_html( $field['label'] );
		if ( $field['required'] ) {
			echo ' <abbr class="required" title="' . esc_attr__( 'required', 'open-product-fields-for-woocommerce' ) . '">*</abbr>';
		}
		echo '</span>';
		if ( '' !== $field['description'] ) {
			echo '<span class="opf-field__description' . ( $compat ? ' wapf-field-description' : '' ) . '">' . esc_html( $field['description'] ) . '</span>';
		}
		echo '</label>';

		echo '<div class="opf-field__control' . ( $compat ? ' wapf-field-input' : '' ) . '">';

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
		$fid    = $field['id'];
		$compat = self::compat();

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
		if ( $compat ) {
			$classes .= ' wapf-field-input';
		}

		echo '<div class="' . esc_attr( $classes ) . '" role="' . ( $multi ? 'group' : 'radiogroup' ) . '" aria-labelledby="' . esc_attr( 'opf-label-' . $gid . '-' . $fid ) . '">';

		foreach ( $field['choices'] as $index => $choice ) {
			$input_id = sprintf( 'opf-%s-%s-%d', $gid, $fid, $index );
			$hint     = self::price_hint( $choice['pricing'] );

			$choice_classes = [ 'opf-choice' ];
			if ( $compat && $swatch ) {
				$choice_classes[] = 'wapf-swatch';
				$choice_classes[] = 'wapf-swatch--text';
			}
			if ( $compat && $choice['selected'] ) {
				$choice_classes[] = 'wapf-checked';
			}

			$data_attrs = '';
			if ( $compat && 'none' !== $choice['pricing']['type'] ) {
				$data_attrs = sprintf(
					' data-wapf-price="%s" data-wapf-pricetype="%s"',
					esc_attr( (string) self::compat_price_amount( $choice['pricing'], $base_price ) ),
					esc_attr( self::compat_price_type( $choice['pricing'] ) )
				);
			}

			$attrs = sprintf(
				'name="%1$s" id="%2$s" value="%3$s"%4$s%5$s%6$s',
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $input_id ),
				esc_attr( $choice['slug'] ),
				$choice['selected'] ? ' checked="checked"' : '',
				$choice['disabled'] ? ' disabled="disabled"' : '',
				$data_attrs
			);

			echo '<label class="' . esc_attr( implode( ' ', $choice_classes ) ) . '" for="' . esc_attr( $input_id ) . '">';
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			echo '<span class="opf-choice__label' . ( $compat ? ' wapf-label' : '' ) . '">' . esc_html( $choice['label'] ) . '</span>';
			if ( '' !== $hint ) {
				echo '<span class="opf-choice__hint">' . wp_kses_post( $hint ) . '</span>';
			}
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * Per-unit numeric amount for the compat data attribute. Formulas are
	 * pre-resolved against the current product price with fixed semantics —
	 * the theme's live total keeps working for qty-independent formulas.
	 *
	 * @param array<string,mixed> $pricing    Pricing block.
	 * @param float               $base_price Base unit price.
	 */
	private static function compat_price_amount( array $pricing, float $base_price ): float {
		switch ( $pricing['type'] ) {
			case 'percent':
				return (float) $pricing['amount'];
			case 'formula':
				return Calculator::evaluate_formula( $pricing['formula'], $base_price, 1, 0.0 );
			default:
				return (float) $pricing['amount'];
		}
	}

	/**
	 * Price type name for the compat data attribute.
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function compat_price_type( array $pricing ): string {
		switch ( $pricing['type'] ) {
			case 'percent':
				return 'percent';
			default:
				return 'fixed';
		}
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
			return esc_html( (string) (float) $pricing['amount'] ) . '%';
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
