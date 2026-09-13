<?php
/**
 * Renders field groups on the product page.
 *
 * Compat mode emits the legacy WAPF DOM contract 1:1 (classes, ids, data
 * attributes, totals block) so the production theme's CSS and JS behave
 * identically to the legacy plugin. Functional inputs keep OPF names
 * (`opf[gid][fid]`) and the `data-opf-*` hooks for the frontend module.
 *
 * Reference markup: legacy plugin's product-page output for url / textarea /
 * text / text-swatch fields (captured from production, 2026-09).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Legacy field-type names emitted in compat mode (theme CSS hooks).
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
	 * Theme compat mode: emit the legacy wapf-* skeleton.
	 */
	public static function compat(): bool {
		return (bool) apply_filters( 'opf_theme_compat', get_option( 'opf_theme_compat', 'yes' ) === 'yes' );
	}

	/**
	 * Transition gate: when `opf_admin_only` is "yes", fields render — and the
	 * whole OPF cart layer engages — only for shop admins and E2E traffic.
	 * Customers keep seeing the legacy plugin's fields until cutover.
	 */
	public static function visible_to_viewer(): bool {
		if ( 'yes' !== get_option( 'opf_admin_only', 'no' ) ) {
			return true;
		}
		return self::viewer_bypasses_gate();
	}

	/**
	 * Admins and E2E tooling (matching OPF_E2E_TOKEN) bypass the gate.
	 */
	public static function viewer_bypasses_gate(): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$token = defined( 'OPF_E2E_TOKEN' ) ? (string) OPF_E2E_TOKEN : '';
		if ( '' === $token ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$given = isset( $_GET['opf_e2e'] ) ? (string) $_GET['opf_e2e'] : (string) ( $_SERVER['HTTP_X_OPF_E2E'] ?? '' );
		return '' !== $given && hash_equals( $token, $given );
	}

	/**
	 * Render all matching groups for the current product.
	 */
	public static function render(): void {
		global $product;
		if ( ! self::visible_to_viewer() ) {
			return;
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$groups = FieldGroups::for_product( $product );
		if ( empty( $groups ) ) {
			return;
		}

		Assets::enqueue_frontend( self::registry( $groups ) );

		$base_price = (float) $product->get_price( 'edit' );
		$gids       = [];

		echo '<div class="wapf" id="wapf_' . esc_attr( (string) $product->get_id() ) . '"><div class="wapf-wrapper">';

		foreach ( $groups as $entry ) {
			$gids[] = (string) $entry['id'];
			self::render_group( $entry['id'], $entry['title'], $entry['group'], $base_price );
		}

		echo '<input type="hidden" value="' . esc_attr( implode( ',', $gids ) ) . '" name="wapf_field_groups"/>';
		echo '</div></div>';

		self::render_totals( $product );
	}

	/**
	 * Client registry: gid => fid => {type, conditionals}.
	 *
	 * @param array<int,array{id:int,title:string,lang:string,group:FieldGroup}> $groups Groups.
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
	 * @param float      $base_price Base unit price.
	 */
	public static function render_group( $gid, string $title, FieldGroup $group, float $base_price ): void {
		// Seed conditionals with default selections.
		$values = [];
		foreach ( $group->data['fields'] as $field ) {
			$values[ $field['id'] ] = self::default_value( $field );
		}

		echo '<div class="wapf-field-group label-' . esc_attr( 'above' === $group->data['labels_position'] ? 'above' : 'below' ) . '" data-group="' . esc_attr( (string) $gid ) . '" data-variables="[]" data-opf-group="' . esc_attr( (string) $gid ) . '">';

		foreach ( $group->data['fields'] as $field ) {
			self::render_field( $gid, $field, $values, $base_price );
		}

		echo '</div>';
	}

	/**
	 * Render a single field — legacy DOM contract.
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
		$compat_t = self::COMPAT_TYPE_NAMES[ $field['type'] ] ?? 'text';

		$classes = [ 'wapf-field-container', 'wapf-field-' . $compat_t, 'field-' . $fid ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( $field['required'] ) {
			$classes[] = 'wapf-required';
		}
		if ( $hidden ) {
			$classes[] = 'wapf-hide';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $fid ) . '" style="width:' . esc_attr( (string) $field['width'] ) . '%;" for="' . esc_attr( $fid ) . '">';

		echo '<div class="wapf-field-label"><label';
		if ( ! in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			echo ' for="wapf-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		echo '><span>' . esc_html( $field['label'] ) . '</span> ';
		if ( $field['required'] ) {
			echo '<abbr class="required" title="' . esc_attr( self::required_title() ) . '">*</abbr>';
		}
		echo '</label></div>';

		if ( '' !== $field['description'] ) {
			echo '<div class="wapf-field-description">' . esc_html( $field['description'] ) . '</div>';
		}

		echo '<div class="wapf-field-input">';

		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			self::render_choices( $gid, $name, $field, $base_price );
		} else {
			self::render_input( $name, $gid, $field );
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Choice-based controls (swatch / select / radio / checkbox).
	 *
	 * @param string              $gid        Group id.
	 * @param string              $name       Input base name.
	 * @param array<string,mixed> $field      Field data.
	 * @param float               $base_price Base unit price.
	 */
	private static function render_choices( string $gid, string $name, array $field, float $base_price ): void {
		$fid = $field['id'];

		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( $name ) . '" id="wapf-' . esc_attr( $gid . '-' . $fid ) . '" class="wapf-input input-' . esc_attr( $fid ) . '" autocomplete="off">';
			foreach ( $field['choices'] as $choice ) {
				echo '<option value="' . esc_attr( $choice['slug'] ) . '"' . selected( $choice['selected'], true, false ) . '>'
					. esc_html( $choice['label'] )
					. '</option>';
			}
			echo '</select>';
			return;
		}

		$multi = 'checkbox' === $field['type'];

		echo '<div class="wapf-swatch-wrapper">';
		echo '<input type="hidden" class="wapf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';

		foreach ( $field['choices'] as $choice ) {
			$swatch_classes = [ 'wapf-swatch', 'wapf-swatch--text' ];
			if ( ! $multi ) {
				$swatch_classes[] = 'wapf-single-select';
			}
			if ( $choice['selected'] ) {
				$swatch_classes[] = 'wapf-checked';
			}
			if ( 'none' !== $choice['pricing']['type'] ) {
				$swatch_classes[] = 'has-pricing';
			}

			$attrs = sprintf(
				'autocomplete="off" id="wapf-%1$s-%2$s-%3$s" name="%4$s" class="wapf-input input-%2$s" data-field-id="%2$s" value="%5$s" data-wapf-label="%6$s"%7$s%8$s%9$s',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $choice['slug'] ),
				esc_attr( $choice['label'] ),
				$field['required'] ? ' required' : '',
				$choice['selected'] ? ' checked' : '',
				self::pricing_attrs( $choice['pricing'] )
			);

			echo '<div class="' . esc_attr( implode( ' ', $swatch_classes ) ) . '">';
			echo '<label><span>' . esc_html( $choice['label'] ) . ' </span>';
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			echo '</label>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Legacy data attributes for the theme's live-total math, verbatim:
	 *  - percent : data-wapf-price = percent amount
	 *  - fixed   : data-wapf-price = amount
	 *  - formula : data-wapf-price = raw legacy expression (theme evaluates it)
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function pricing_attrs( array $pricing ): string {
		if ( 'none' === $pricing['type'] ) {
			return '';
		}
		$price = 'formula' === $pricing['type']
			? (string) ( $pricing['formula_raw'] ?? $pricing['formula'] )
			: (string) (float) $pricing['amount'];
		$type  = 'formula' === $pricing['type'] ? 'fx' : $pricing['type'];
		return sprintf( ' data-wapf-pricetype="%s" data-wapf-price="%s"', esc_attr( $type ), esc_attr( $price ) );
	}

	/**
	 * Text-like inputs.
	 *
	 * @param string              $name  Input name.
	 * @param string              $gid   Group id.
	 * @param array<string,mixed> $field Field data.
	 */
	private static function render_input( string $name, string $gid, array $field ): void {
		$fid = $field['id'];
		$shared = sprintf(
			'data-field-id="%1$s" id="wapf-%2$s-%1$s"%3$s name="opf[%2$s][%1$s]" class="wapf-input input-%1$s" placeholder="%4$s" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"',
			esc_attr( $fid ),
			esc_attr( $gid ),
			$field['required'] ? ' required' : '',
			esc_attr( $field['placeholder'] )
		);

		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea ' . $shared . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
				break;
			case 'url':
				echo '<input type="url" value="" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<input type="number" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				echo '<input type="text" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
		}
	}

	/**
	 * Localized totals labels for the legacy totals block.
	 *
	 * @return array{product_total:string,options_total:string,grand_total:string,required_title:string}
	 */
	private static function i18n(): array {
		$default = [
			'product_total'  => __( 'Product total', 'open-product-fields-for-woocommerce' ),
			'options_total'  => __( 'Options total', 'open-product-fields-for-woocommerce' ),
			'grand_total'    => __( 'Grand total', 'open-product-fields-for-woocommerce' ),
			'required_title' => __( 'required', 'open-product-fields-for-woocommerce' ),
		];
		$map = get_option( 'opf_compat_i18n', [] );
		if ( ! is_array( $map ) ) {
			return $default;
		}
		$lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
		return isset( $map[ $lang ] ) && is_array( $map[ $lang ] ) ? array_merge( $default, $map[ $lang ] ) : $default;
	}

	/**
	 * Required-marker tooltip text for the current locale.
	 */
	private static function required_title(): string {
		$i18n = self::i18n();
		return '' !== $i18n['required_title'] ? $i18n['required_title'] : __( 'required', 'open-product-fields-for-woocommerce' );
	}

	/**
	 * The legacy totals block the theme's quantity module reads.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function render_totals( \WC_Product $product ): void {
		if ( ! self::compat() ) {
			return;
		}
		$i18n = self::i18n();
		$data_tax = 1;
		if (
			function_exists( 'wc_prices_include_tax' )
			&& ! wc_prices_include_tax()
			&& get_option( 'woocommerce_tax_display_shop' ) === 'excl'
		) {
			$data_tax = 1;
		}
		echo '<div class="wapf-product-totals" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-product-type="' . esc_attr( $product->get_type() ) . '" data-product-price="' . esc_attr( (string) $product->get_price() ) . '" data-tax="' . esc_attr( (string) $data_tax ) . '"><div class="wapf--inner">';
		echo '<div><span>' . esc_html( $i18n['product_total'] ) . '</span><span class="wapf-total wapf-product-total price amount"></span></div>';
		echo '<div><span>' . esc_html( $i18n['options_total'] ) . '</span><span class="wapf-total wapf-options-total price amount"></span></div>';
		echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span><span class="wapf-total wapf-grand-total price amount"></span></div>';
		echo '</div></div>';
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
