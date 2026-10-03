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

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Legacy field-type names emitted in compat mode (theme CSS hooks).
	 */
	private const COMPAT_TYPE_NAMES = [
		'text'     => 'text',
		'textarea' => 'textarea',
		'email'    => 'email',
		'url'      => 'url',
		'number'   => 'number',
		'toggle'   => 'toggle',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'swatch'   => 'text-swatch',
		'image_quantity' => 'image-swatch-qty',
		'products' => 'products',
		'upload' => 'file',
		'paragraph' => 'content',
		'content_image' => 'content-image',
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
	 * Totals block: hidden by default (the legacy setup also hid it). The
	 * data attributes stay in the DOM for the theme's currency converter;
	 * flip `opf_show_totals` to display the visible totals rows.
	 */
	public static function show_totals(): bool {
		return (bool) apply_filters( 'opf_show_totals', get_option( 'opf_show_totals', 'no' ) === 'yes' );
	}

	/**
	 * Summary mode: 'three' (3-line), 'grand' (grand total only), 'hidden'.
	 * New installs default to the documented 3-line mode; when the new option
	 * has never been saved, a previously saved legacy `opf_show_totals`
	 * ('yes'/'no') choice is mapped onto it.
	 */
	public static function summary_mode(): string {
		$mode = get_option( 'opf_price_summary_mode', null );
		if ( null === $mode || '' === $mode || false === $mode ) {
			$legacy = get_option( 'opf_show_totals', false );
			if ( false !== $legacy && '' !== $legacy ) {
				return 'yes' === $legacy ? 'three' : 'hidden';
			}
			return 'three';
		}
		return in_array( $mode, [ 'three', 'grand', 'hidden' ], true ) ? $mode : 'three';
	}

	/** Per-option price hints toggle. */
	public static function show_price_hints(): bool {
		return (bool) apply_filters( 'opf_show_price_hints', get_option( 'opf_show_price_hints', 'yes' ) === 'yes' );
	}

	/**
	 * Signed price hint HTML for a pricing block, e.g. "+ $5.00".
	 *
	 * @param array<string,mixed> $pricing Normalized pricing.
	 */
	public static function pricing_hint_html( array $pricing, float $base_price ): string {
		if ( ! self::show_price_hints() ) {
			return '';
		}
		if ( empty( $pricing ) || 'none' === ( $pricing['type'] ?? 'none' ) ) {
			return '';
		}
		$amount = 0.0;
		switch ( $pricing['type'] ) {
			case 'fixed':
				$amount = (float) $pricing['amount'];
				break;
			case 'percent':
				$amount = $base_price * ( (float) $pricing['amount'] / 100 );
				break;
			case 'formula':
				$amount = Calculator::evaluate_formula( (string) ( $pricing['formula'] ?? '0' ), $base_price, 1, 0.0 );
				break;
			default:
				return '';
		}
		$sign = $amount < 0 ? '-' : '+';
		return ' <span class="opf-pricing-hint">' . $sign . ' ' . wc_price( abs( $amount ) ) . '</span>';
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

		// WAPF alias bridge: wapf/pricing/product.
		$base_price = (float) \OPF\Compat\WapfHooks::pricing_product( (float) $product->get_price( 'edit' ), $product );
		$gids       = [];

		// WAPF alias bridge: legacy wapf_before_wrapper action.
		\OPF\Compat\WapfHooks::before_wrapper( $product );

		echo '<div class="opf-fields" data-opf-fields="' . esc_attr( (string) count( $groups ) ) . '"><div class="opf" id="opf_' . esc_attr( (string) $product->get_id() ) . '"><div class="opf-wrapper">';

		foreach ( $groups as $entry ) {
			$gids[] = (string) $entry['id'];
			self::render_group( $entry['id'], $entry['title'], $entry['group'], $base_price, $product );
		}

		echo '<input type="hidden" value="' . esc_attr( implode( ',', $gids ) ) . '" name="opf_field_groups"/>';
		echo '</div></div></div>';

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
					'subtype'      => $field['subtype'] ?? null,
					'qty_selector' => 'products' === $field['type'] && LinkedProducts::is_qty_subtype( $field ),
					'repeat'       => $field['repeat'] ?? null,
					'multiple'     => ! empty( $field['multiple'] ) || ( 'products' === $field['type'] && in_array( $field['subtype'] ?? '', [ 'checkbox', 'image', 'card', 'vcard' ], true ) ),
					'min_choices'  => $field['min_choices'] ?? null,
					'max_choices'  => $field['max_choices'] ?? null,
					'conditionals' => $field['conditionals'],
					'choices'      => array_map( static function ( $c ) {
					return [
						'slug'     => $c['slug'],
						'label'    => $c['label'],
						'disabled' => ! empty( $c['disabled'] ),
						'quantity' => $c['quantity'] ?? null,
						'pricing'  => [
								'type'       => $c['pricing']['type'],
								'amount'     => (float) $c['pricing']['amount'],
								'formula'    => (string) $c['pricing']['formula'],
								'formula_raw' => (string) ( $c['pricing']['formula_raw'] ?? '' ),
								'per_unit'   => ! empty( $c['pricing']['per_unit'] ),
							],
						];
					}, (array) ( $field['choices'] ?? [] ) ),
					'pricing'      => [
						'type'    => $field['pricing']['type'],
						'amount'  => (float) $field['pricing']['amount'],
						'formula' => (string) ( $field['pricing']['formula'] ?? '' ),
						'formula_raw' => (string) ( $field['pricing']['formula_raw'] ?? '' ),
						'per_unit' => ! empty( $field['pricing']['per_unit'] ),
					],
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
	public static function render_group( $gid, string $title, FieldGroup $group, float $base_price, ?\WC_Product $product = null ): void {
		// Seed conditionals with default selections.
		$values = [];
		foreach ( $group->data['fields'] as $field ) {
			$values[ $field['id'] ] = self::default_value( $field );
		}

		echo '<div class="opf-field-group label-' . esc_attr( 'above' === $group->data['labels_position'] ? 'above' : 'below' ) . '" data-group="' . esc_attr( (string) $gid ) . '" data-variables="[]" data-opf-group="' . esc_attr( (string) $gid ) . '">';

		$section_stack = [];
		$section_repeat_index = null;
		$section_repeat_mode = null;
		$mark_required = ! isset( $group->data['mark_required'] ) || ! empty( $group->data['mark_required'] );
		foreach ( $group->data['fields'] as $field ) {
			// Group layout flag (WAPF mark_required): when off, required
			// fields render without the asterisk but stay validated.
			$field['_opf_mark_required'] = $mark_required;
			if ( 'section' === $field['type'] ) {
				$previous_repeat_index = $section_repeat_index;
				$previous_repeat_mode = $section_repeat_mode;
				$repeat = $field['repeat'] ?? [];
				$repeat_mode = (string) ( $repeat['mode'] ?? '' );
				$repeats_section = ! empty( $repeat['enabled'] ) && in_array( $repeat_mode, [ 'button', 'quantity' ], true );
				if ( $repeats_section ) {
					$classes = [ 'opf-field-container', 'opf-field-repeat', 'opf-section-repeat', 'field-' . $field['id'] ];
					if ( '' !== $field['css_class'] ) {
						$classes[] = $field['css_class'];
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $field['id'] ) . '" data-opf-repeat="' . esc_attr( $repeat_mode ) . '" data-opf-section-repeat="1" data-opf-repeat-max="' . esc_attr( (string) ( $repeat['max'] ?? 10000 ) ) . '">';
					echo '<div class="opf-field-repeat__rows"><div data-opf-repeat-instance="1">';
					if ( '' !== $field['label'] ) {
						echo '<div class="opf-section-repeat__label"><span>' . esc_html( $field['label'] ) . '</span></div>';
					}
					self::render_section( $field, $values, false );
					$section_repeat_index = 0;
					$section_repeat_mode = $repeat_mode;
				} else {
					self::render_section( $field, $values );
				}
				$section_stack[] = [
					'repeats' => $repeats_section,
					'mode' => $repeat_mode,
					'options' => $repeat,
					'previous_repeat_index' => $previous_repeat_index,
					'previous_repeat_mode' => $previous_repeat_mode,
				];
				continue;
			}
			if ( 'section_end' === $field['type'] ) {
				if ( $section_stack ) {
					$section_context = array_pop( $section_stack );
					echo '</div>';
					if ( $section_context['repeats'] ) {
						echo '</div></div>';
						if ( 'button' === $section_context['mode'] ) {
							$add_label = (string) ( $section_context['options']['add'] ?? __( 'Add another', 'open-product-fields-for-woocommerce' ) );
							echo '<button type="button" class="opf-field-repeat__add">' . esc_html( $add_label ) . '</button>';
						}
						echo '<span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
					}
					$section_repeat_index = $section_context['previous_repeat_index'];
					$section_repeat_mode = $section_context['previous_repeat_mode'];
				}
				continue;
			}
			if ( ! empty( $field['repeat']['enabled'] ) ) {
				self::render_repeated_field( $gid, $field, $values, $base_price, $section_repeat_index, $product );
			} else {
				self::render_field( $gid, $field, $values, $base_price, false, $section_repeat_index, 'quantity' === $section_repeat_mode, $product );
			}
		}
		while ( $section_stack ) {
			$section_context = array_pop( $section_stack );
			echo '</div>';
			if ( $section_context['repeats'] ) {
				echo '</div></div><span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
			}
			$section_repeat_index = $section_context['previous_repeat_index'];
			$section_repeat_mode = $section_context['previous_repeat_mode'];
		}

		echo '</div>';
	}

	/** Render the opening wrapper for a WAPF-compatible section marker. */
	private static function render_section( array $field, array $values, bool $include_field_attribute = true ): void {
		$classes = [ 'opf-section', 'wapf-section', 'field-' . $field['id'] ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( ! empty( $field['conditionals'] ) ) {
			$classes[] = 'has-conditions';
		}
		if ( ! Evaluator::is_visible( $field, $values ) ) {
			$classes[] = 'opf-hide';
		}
		// WAPF alias bridge: wapf/html/section_container_classes.
		$classes = \OPF\Compat\WapfHooks::section_container_classes( $classes, $field );
		$field_attribute = $include_field_attribute ? ' data-opf-field="' . esc_attr( $field['id'] ) . '"' : '';
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $field_attribute . ' style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
	}

	/**
	 * Render a single field — legacy DOM contract.
	 *
	 * @param string              $gid        Group id.
	 * @param array<string,mixed> $field      Normalized field.
	 * @param array<string,mixed> $values     Seeded values (for conditional state).
	 * @param float               $base_price Base unit price.
	 */
	private static function render_repeated_field( string $gid, array $field, array $values, float $base_price, ?int $section_repeat_index = null, ?\WC_Product $product = null ): void {
		$fid = (string) $field['id'];
		$repeat = $field['repeat'];
		$mode = (string) ( $repeat['mode'] ?? '' );
		if ( ! in_array( $mode, [ 'button', 'quantity' ], true ) || ! in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
			echo '<div class="opf-field-container opf-field-repeat opf-field-repeat--unsupported" data-opf-field="' . esc_attr( $fid ) . '">';
			echo '<div class="opf-field-label"><span>' . esc_html( $field['label'] ) . '</span></div>';
			$message = 'button' === ( $repeat['mode'] ?? '' )
				? __( 'This repeated field type is not available yet.', 'open-product-fields-for-woocommerce' )
				: __( 'This quantity-based repeated field is not available yet.', 'open-product-fields-for-woocommerce' );
			echo '<p class="opf-field-repeat__notice">' . esc_html( $message ) . '</p></div>';
			return;
		}
		$hidden = ! Evaluator::is_visible( $field, $values );
		$classes = [ 'opf-field-container', 'opf-field-repeat', 'field-' . $fid ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( $field['required'] ) {
			$classes[] = 'opf-required';
		}
		if ( $hidden ) {
			$classes[] = 'opf-hide';
		}
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $fid ) . '" data-opf-repeat="' . esc_attr( $mode ) . '" data-opf-repeat-max="' . esc_attr( (string) ( $repeat['max'] ?? 10000 ) ) . '" style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
		echo '<div class="opf-field-repeat__rows">';
		$instance = $field;
		$instance['_opf_source_id'] = $fid;
		$instance['_opf_repeat_index'] = 0;
		$instance['id'] = $fid . '-repeat-0';
		self::render_field( $gid, $instance, $values, $base_price, true, $section_repeat_index, 'quantity' === $mode, $product );
		if ( 'button' === ( $repeat['mode'] ?? 'button' ) ) {
			$add_label = (string) ( $repeat['add'] ?? __( 'Add another', 'open-product-fields-for-woocommerce' ) );
			echo '</div><button type="button" class="opf-field-repeat__add">' . esc_html( $add_label ) . '</button>';
		} else {
			echo '</div>';
		}
		echo '<span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
	}

	private static function render_field( string $gid, array $field, array $values, float $base_price, bool $repeat_instance = false, ?int $section_repeat_index = null, bool $qty_based = false, ?\WC_Product $product = null ): void {
		$fid      = $field['id'];
		$source_fid = (string) ( $field['_opf_source_id'] ?? $fid );
		$name     = sprintf( 'opf[%s][%s]', $gid, $source_fid );
		if ( null !== $section_repeat_index ) {
			$name .= '[' . $section_repeat_index . ']';
		}
		if ( isset( $field['_opf_repeat_index'] ) ) {
			$name .= '[' . (int) $field['_opf_repeat_index'] . ']';
		}
		$hidden   = ! Evaluator::is_visible( $field, $values );
		$compat_t = self::COMPAT_TYPE_NAMES[ $field['type'] ] ?? 'text';

		$classes = [ 'opf-field-container', 'opf-field-' . $compat_t, 'field-' . $fid ];
		if ( '' !== $field['css_class'] ) {
			$classes[] = $field['css_class'];
		}
		if ( $field['required'] ) {
			$classes[] = 'opf-required';
		}
		if ( $hidden ) {
			$classes[] = 'opf-hide';
		}

		// WAPF alias bridge: wapf/html/field_container_classes.
		$classes = \OPF\Compat\WapfHooks::field_container_classes( $classes, $field );

		$repeat_attr = $repeat_instance ? ' data-opf-repeat-instance="1"' : ' data-opf-field="' . esc_attr( $fid ) . '"';
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $repeat_attr . ' style="width:' . esc_attr( (string) $field['width'] ) . '%;" for="' . esc_attr( $fid ) . '">';
		if ( 'content_image' === $field['type'] ) {
			$rendered_image = false;
			$attachment_id = (int) ( $field['image_id'] ?? 0 );
			if ( $attachment_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
				$image = wp_get_attachment_image( $attachment_id, 'full', false, [ 'alt' => (string) $field['label'], 'loading' => 'lazy', 'decoding' => 'async' ] );
				if ( is_string( $image ) && '' !== $image ) {
					echo '<div class="opf-field-content-image">' . $image . '</div>';
					$rendered_image = true;
				}
			}
			$src = (string) ( $field['image_url'] ?? '' );
			if ( ! $rendered_image && '' !== $src ) {
				echo '<div class="opf-field-content-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( (string) $field['label'] ) . '" loading="lazy" decoding="async" style="max-width:100%;height:auto;" /></div>';
			}
			echo '</div>';
			return;
		}
		if ( 'paragraph' === $field['type'] ) {
			$content = esc_html( $field['content'] );
			if ( 'html' === ( $field['content_format'] ?? 'plain' ) && function_exists( 'wp_kses' ) ) {
				$allowed_html = [
					'br' => [],
					'hr' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'a' => [ 'href' => [], 'target' => [], 'class' => [], 'style' => [], 'id' => [] ],
					'i' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'em' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'strong' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'b' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'span' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'div' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h1' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h2' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h3' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h4' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h5' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'h6' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'ul' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'ol' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'li' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'table' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'tr' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'td' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'th' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'thead' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'tbody' => [ 'class' => [], 'style' => [], 'id' => [] ],
					'img' => [ 'src' => [], 'target' => [], 'class' => [], 'alt' => [], 'style' => [], 'id' => [] ],
				];
				$content = wp_kses( $field['content'], $allowed_html );
				if ( ! empty( $field['process_shortcodes'] ) && function_exists( 'do_shortcode' ) ) {
					$content = do_shortcode( $content );
				}
			}
			echo '<div class="opf-field-content">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain content is escaped; HTML content is allow-list sanitized before optional registered shortcodes.
			echo '</div>';
			return;
		}

		// WAPF alias bridge: wapf/html/field_label (filtered content, escaped default).
		$label_content = \OPF\Compat\WapfHooks::field_label( esc_html( $field['label'] ), $field, $product );

		echo '<div class="opf-field-label"><label';
		if ( 'radio' === $field['type'] ) {
			echo ' id="opf-label-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		if ( ! in_array( $field['type'], [ 'swatch', 'image_quantity', 'radio', 'checkbox', 'products' ], true ) ) {
			echo ' for="opf-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		echo '><span>' . $label_content . '</span>' . self::pricing_hint_html( $field['pricing'] ?? [], $base_price ) . ' ';
		if ( $field['required'] && ( $field['_opf_mark_required'] ?? true ) ) {
			echo '<abbr class="required" title="' . esc_attr( self::required_title() ) . '">*</abbr>';
		}
		echo '</label>';
		// WAPF alias bridge: wapf/html/field_description. Unfiltered values keep
		// the original escape path so output stays byte-identical without listeners.
		if ( '' === $field['description'] ) {
			$description      = '';
			$description_html = '';
		} else {
			$description      = \OPF\Compat\WapfHooks::field_description( $field['description'], $field );
			$description_html = ( $description === $field['description'] )
				? esc_html( $field['description'] )
				: ( function_exists( 'wp_kses_post' ) ? wp_kses_post( $description ) : esc_html( $description ) );
		}
		// Tooltip instructions sit inside the label container, next to the
		// label, mirroring WAPF instructions_position=tooltip (the icon is
		// emitted inside .wapf-field-label).
		if ( '' !== $description && 'tooltip' === ( $field['description_presentation'] ?? 'inline' ) ) {
			$tid = 'opf-tt-' . esc_attr( $gid . '-' . $fid );
			echo '<button type="button" class="opf-tooltip-trigger" aria-describedby="' . $tid . '" aria-expanded="false"><span aria-hidden="true">?</span><span class="screen-reader-text">' . esc_html( $field['label'] ) . ' help</span></button>';
			echo '<span role="tooltip" id="' . $tid . '" class="opf-tooltip">' . $description_html . '</span>';
		}
		echo '</div>';

		if ( '' !== $description && 'tooltip' !== ( $field['description_presentation'] ?? 'inline' ) ) {
			echo '<div class="opf-field-description">' . $description_html . '</div>';
		}

		echo '<div class="opf-field-input">';

		if ( 'products' === $field['type'] ) {
			self::render_products_field( $gid, $name, $field, $product );
		} elseif ( in_array( $field['type'], [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox' ], true ) ) {
			self::render_choices( $gid, $name, $field, $base_price, $qty_based );
		} else {
			self::render_input( $name, $gid, $field, $qty_based );
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
	private static function render_choices( string $gid, string $name, array $field, float $base_price, bool $qty_based = false ): void {
		$fid = $field['id'];
		if ( 'image_quantity' === $field['type'] ) {
			echo '<div class="opf-image-quantity">';
			foreach ( $field['choices'] as $choice ) {
				$q = $choice['quantity'];
				$label = esc_html( $choice['label'] );
				echo '<label class="opf-image-quantity__choice">';
				if ( ! empty( $choice['image'] ) ) {
					echo '<img src="' . esc_url( $choice['image'] ) . '" alt="' . $label . '" loading="lazy" />';
				}
				echo '<span>' . $label . self::pricing_hint_html( $choice['pricing'] ?? [], $base_price ) . '</span><input type="number" class="opf-input opf-image-quantity__input" name="' . esc_attr( $name . '[' . $choice['slug'] . ']' ) . '" value="' . esc_attr( (string) $q['default'] ) . '" min="' . esc_attr( (string) $q['min'] ) . '" max="' . esc_attr( (string) $q['max'] ) . '" step="1" data-field-id="' . esc_attr( $fid ) . '" data-choice-slug="' . esc_attr( $choice['slug'] ) . '"' . ( ! empty( $choice['disabled'] ) ? ' disabled' : '' ) . ' /></label>';
			}
			echo '</div>';
			return;
		}

		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( $name ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" class="opf-input input-' . esc_attr( $fid ) . '" autocomplete="off"' . ( $field['required'] ? ' required' : '' ) . '>';
			$has_default = false;
			foreach ( $field['choices'] as $choice ) {
				$has_default = $has_default || ( $choice['selected'] && ! $choice['disabled'] );
			}
			if ( ! $field['required'] || ! $has_default ) {
				echo '<option value="">' . esc_html( __( 'Choose an option', 'open-product-fields-for-woocommerce' ) ) . '</option>';
			}
			foreach ( $field['choices'] as $choice ) {
				$choice_selected = $choice['selected'] && ! $choice['disabled'];
				echo '<option value="' . esc_attr( $choice['slug'] ) . '"' . selected( $choice_selected, true, false ) . self::pricing_attrs( $choice['pricing'], $qty_based ) . ( ! empty( $choice['disabled'] ) ? ' disabled' : '' ) . '>'
					. esc_html( $choice['label'] )
					. strip_tags( self::pricing_hint_html( $choice['pricing'], $base_price ) )
					. '</option>';
			}
			echo '</select>';
			return;
		}

		$multi = 'checkbox' === $field['type'] || ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) );
		$image_swatch = 'swatch' === $field['type'] && 'image' === ( $field['swatch_style'] ?? '' );
		$color_swatch = 'swatch' === $field['type'] && 'color' === ( $field['swatch_style'] ?? '' );

		$wrapper_class = $image_swatch ? 'opf-swatch-wrapper opf-image-swatch-wrapper' : 'opf-swatch-wrapper';
		if ( $color_swatch ) {
			$wrapper_class .= ' opf-color-swatch-wrapper';
		}
		$wrapper_attrs = '';
		if ( 'radio' === $field['type'] ) {
			$wrapper_attrs = ' role="radiogroup" aria-labelledby="opf-label-' . esc_attr( $gid . '-' . $fid ) . '"' . ( $field['required'] ? ' aria-required="true"' : '' );
		}
		if ( $image_swatch ) {
			$wrapper_attrs = ' data-grid-layout="' . esc_attr( $field['grid_layout'] ) . '" data-label-position="' . esc_attr( $field['label_pos'] ) . '"';
			if ( 'flexible' === $field['grid_layout'] ) {
				$wrapper_attrs .= ' style="--opf-image-swatch-cols:' . esc_attr( (string) $field['items_per_row'] ) . ';--opf-image-swatch-cols-tablet:' . esc_attr( (string) $field['items_per_row_tablet'] ) . ';--opf-image-swatch-cols-mobile:' . esc_attr( (string) $field['items_per_row_mobile'] ) . ';"';
			} else {
				$wrapper_attrs .= ' style="--opf-image-swatch-width:' . esc_attr( (string) $field['item_width'] ) . 'px;"';
			}
		}
		if ( $color_swatch ) {
			$wrapper_attrs .= ' data-color-layout="' . esc_attr( $field['color_layout'] ) . '"';
		}
		if ( 'swatch' === $field['type'] && $multi ) {
			if ( isset( $field['min_choices'] ) ) {
				$wrapper_attrs .= ' data-min-choices="' . esc_attr( (string) $field['min_choices'] ) . '"';
			}
			if ( isset( $field['max_choices'] ) ) {
				$wrapper_attrs .= ' data-max-choices="' . esc_attr( (string) $field['max_choices'] ) . '"';
			}
		}
		echo '<div class="' . esc_attr( $wrapper_class ) . '"' . $wrapper_attrs . '>';
		if ( ! ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) ) {
			echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
		}

		foreach ( $field['choices'] as $choice ) {
			$choice_selected = $choice['selected'] && ! $choice['disabled'];
			$swatch_classes = [ 'opf-swatch', $image_swatch ? 'opf-swatch--image' : ( $color_swatch ? 'opf-swatch--color' : 'opf-swatch--text' ) ];
			if ( $image_swatch && ( ! empty( $choice['image'] ) || ! empty( $choice['image_id'] ) ) ) {
				if ( ! in_array( 'opf-swatch--image', $swatch_classes, true ) ) {
					$swatch_classes[] = 'opf-swatch--image';
				}
			}
			if ( $image_swatch && ! empty( $field['image_zoom'] ) && ( ! empty( $choice['image'] ) || ! empty( $choice['image_id'] ) ) ) {
				$swatch_classes[] = 'opf-swatch--image-zoom';
			}
			if ( $image_swatch ) {
				$swatch_classes[] = 'opf-image-swatch-label--' . $field['label_pos'];
			}
			if ( ! $multi ) {
				$swatch_classes[] = 'opf-single-select';
			}
			if ( $choice_selected ) {
				$swatch_classes[] = 'opf-checked';
			}
			if ( 'none' !== $choice['pricing']['type'] ) {
				$swatch_classes[] = 'has-pricing';
			}

			// WAPF alias bridge: wapf/html/option_wrapper_classes.
			$swatch_classes = \OPF\Compat\WapfHooks::option_wrapper_classes( $swatch_classes, $field, $GLOBALS['product'] ?? null, $choice );

			$attrs = sprintf(
				'autocomplete="off" id="opf-%1$s-%2$s-%3$s" name="%4$s" class="opf-input input-%2$s" data-field-id="%2$s" value="%5$s" data-opf-label="%6$s" data-wapf-label="%6$s"%7$s%8$s%9$s',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $choice['slug'] ),
				esc_attr( $choice['label'] ),
				$field['required'] && ( ! $multi || ! isset( $field['_opf_repeat_index'] ) ) ? ' required' : '',
				$choice_selected ? ' checked' : '',
				self::pricing_attrs( $choice['pricing'], $qty_based ) . ( ! empty( $choice['disabled'] ) ? ' disabled' : '' )
			);

			$choice_label_attr = $image_swatch ? ' data-opf-swatch-label="' . esc_attr( $choice['label'] ) . '"' : '';
			if ( $color_swatch ) {
				$choice_label_attr .= ' data-opf-swatch-label="' . esc_attr( $choice['label'] ) . '" data-color-label-position="' . esc_attr( $field['color_label_pos'] ) . '"';
			}
			echo '<div class="' . esc_attr( implode( ' ', $swatch_classes ) ) . '"' . $choice_label_attr . '>';
			echo '<label>';
			if ( $color_swatch && ! empty( $choice['color'] ) ) {
				echo '<span class="opf-color-swatch" aria-hidden="true" style="--opf-swatch-color:' . esc_attr( $choice['color'] ) . ';--opf-swatch-size:' . esc_attr( (string) $field['color_size'] ) . 'px"></span>';
			}
			$image_html = '';
			if ( $image_swatch && ! empty( $choice['image_id'] ) && function_exists( 'wp_get_attachment_image' ) ) {
				// WAPF alias bridge: wapf/html/image_swatch_size.
				$image_size = \OPF\Compat\WapfHooks::image_swatch_size( 'medium', $field, $GLOBALS['product'] ?? null, $choice );
				$image_html = (string) wp_get_attachment_image(
					(int) $choice['image_id'],
					$image_size,
					false,
					[ 'class' => 'opf-swatch-image', 'alt' => (string) $choice['label'], 'loading' => 'lazy', 'decoding' => 'async' ]
				);
			}
			if ( $image_swatch && '' === $image_html && ! empty( $choice['image'] ) ) {
				$image_html = '<img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
			}
			$zoom_html = '';
			if ( $image_swatch && ! empty( $field['image_zoom'] ) ) {
				$zoom_url = ! empty( $choice['image_id'] ) && function_exists( 'wp_get_attachment_image_url' )
					? wp_get_attachment_image_url( (int) $choice['image_id'], 'full' )
					: (string) ( $choice['image'] ?? '' );
				if ( is_string( $zoom_url ) && '' !== $zoom_url ) {
					$zoom_html = '<img class="opf-swatch-zoom-preview" src="' . esc_url( $zoom_url ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
				}
			}
			$image_frame = $image_swatch && 'out' !== $field['label_pos'] && ( '' !== $image_html || '' !== $zoom_html );
			if ( $image_frame ) {
				echo '<span class="opf-image-swatch-frame">';
			}
			if ( '' !== $image_html ) {
				echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput -- attachment markup is generated by WordPress or escaped above.
			}
			if ( '' !== $zoom_html ) {
				echo $zoom_html; // phpcs:ignore WordPress.Security.EscapeOutput -- URL is escaped and attributes are fixed.
			}
			if ( $image_frame ) {
				echo '</span>';
			}
			$label_class = $image_swatch ? ' class="opf-image-swatch-label"' : '';
			echo '<span' . $label_class . '>' . esc_html( $choice['label'] ) . self::pricing_hint_html( $choice['pricing'], $base_price ) . ' </span>';
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			echo '</label>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Linked-products field (WAPF `products-*` parity). Children are real
	 * products; the rendered choice inputs submit slugs (or per-choice
	 * quantities for the *-qty subtypes) which CartIntegration + LinkedProducts
	 * turn into native cart lines.
	 *
	 * @param string              $gid     Group id.
	 * @param string              $name    Input base name.
	 * @param array<string,mixed> $field   Field data.
	 * @param \WC_Product|null    $product Parent product (for query exclusion).
	 */
	private static function render_products_field( string $gid, string $name, array $field, ?\WC_Product $product = null ): void {
		$fid      = $field['id'];
		$subtype  = (string) ( $field['subtype'] ?? 'checkbox' );
		$is_qty   = LinkedProducts::is_qty_subtype( $field );
		$multi    = in_array( $subtype, [ 'checkbox', 'image', 'card', 'vcard' ], true );
		$required = ! empty( $field['required'] );

		if ( null === $product ) {
			$product = $GLOBALS['product'] ?? null;
		}
		$choices = LinkedProducts::product_choices( $field, $product instanceof \WC_Product ? $product : null );
		if ( ! $choices ) {
			echo '<p class="opf-products-empty">' . esc_html__( 'No products are available for this option.', 'open-product-fields-for-woocommerce' ) . '</p>';
			return;
		}

		// Shared per-choice input attributes (WAPF get_option_classes_and_attributes parity).
		$input_attrs = static function ( array $choice, string $input_name, bool $checked ) use ( $gid, $fid, $field, $required ): string {
			$attrs = sprintf(
				'id="opf-%1$s-%2$s-%3$s" name="%4$s" class="opf-input opf-product-input input-%2$s" data-field-id="%2$s" value="%3$s" data-opf-label="%5$s" data-wapf-label="%5$s" data-opf-product-id="%6$s"',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $input_name ),
				esc_attr( $choice['label'] ),
				esc_attr( (string) ( $choice['product_id'] ?? '' ) )
			);
			if ( $required ) {
				$attrs .= ' required';
			}
			if ( $checked ) {
				$attrs .= ' checked';
			}
			if ( 'none' !== ( $choice['pricing_type'] ?? 'none' ) ) {
				$attrs .= sprintf( ' data-opf-pricetype="%s" data-opf-price="%s" data-wapf-pricetype="%s" data-wapf-price="%s"',
					esc_attr( $choice['pricing_type'] ),
					esc_attr( (string) (float) ( $choice['pricing_amount'] ?? 0 ) ),
					esc_attr( $choice['pricing_type'] ),
					esc_attr( (string) (float) ( $choice['pricing_amount'] ?? 0 ) ) );
			}
			if ( ! empty( $choice['disabled'] ) ) {
				$attrs .= ' disabled data-disabled="1"';
			}
			// image_zoom: hover preview + gallery swap URL (WAPF data-zoom-url parity).
			if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
				$attrs .= ' data-opf-swap-image="' . esc_attr( $choice['zoom_url'] ) . '"';
			}
			return $attrs;
		};

		$choice_classes = static function ( array $choice, array $base = [] ): array {
			if ( ! empty( $choice['selected'] ) ) {
				$base[] = 'opf-checked wapf-checked';
			}
			if ( 'none' !== ( $choice['pricing_type'] ?? 'none' ) ) {
				$base[] = 'has-pricing';
			}
			if ( ! empty( $choice['disabled'] ) || ( isset( $choice['product'] ) && ! $choice['product']->is_in_stock() ) ) {
				$base[] = 'opf-disabled wapf-disabled';
			}
			return $base;
		};

		$wrapper_attrs = static function ( array $choice ) use ( $field ): string {
			$attrs = '';
			if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
				$attrs .= ' data-zoom-url="' . esc_attr( $choice['zoom_url'] ) . '"';
			}
			return $attrs;
		};

		$hint = static function ( array $choice ): string {
			if ( 'none' === ( $choice['pricing_type'] ?? 'none' ) || empty( $choice['pricing_amount'] ) || ! function_exists( 'wc_price' ) ) {
				return '';
			}
			return ' <span class="opf-pricing-hint">(' . wc_price( (float) $choice['pricing_amount'] ) . ')</span>';
		};

		switch ( $subtype ) {
			case 'dropdown':
				echo '<select name="' . esc_attr( $name ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" class="opf-input input-' . esc_attr( $fid ) . '" autocomplete="off"' . ( $required ? ' required' : '' ) . '>';
				$has_default = (bool) array_filter( $choices, static fn ( $c ) => ! empty( $c['selected'] ) && empty( $c['disabled'] ) );
				if ( ! $required || ! $has_default ) {
					echo '<option value="">' . esc_html__( 'Choose an option', 'open-product-fields-for-woocommerce' ) . '</option>';
				}
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					echo '<option value="' . esc_attr( $choice['slug'] ) . '"'
						. selected( ! empty( $choice['selected'] ) && ! $disabled, true, false )
						. ' data-opf-label="' . esc_attr( $choice['label'] ) . '"'
						. ( 'none' !== ( $choice['pricing_type'] ?? 'none' ) ? ' data-opf-pricetype="' . esc_attr( $choice['pricing_type'] ) . '" data-opf-price="' . esc_attr( (string) (float) $choice['pricing_amount'] ) . '"' : '' )
						. ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ? ' data-opf-swap-image="' . esc_attr( $choice['zoom_url'] ) . '"' : '' )
						. ( $disabled ? ' disabled' : '' )
						. '>' . esc_html( $choice['label'] ) . '</option>';
				}
				echo '</select>';
				return;

			case 'image':
				echo '<div class="opf-swatch-wrapper opf-image-swatch-wrapper opf-products opf-products--image" data-label-position="' . esc_attr( $field['label_pos'] ?? 'tooltip' ) . '" style="--opf-image-swatch-width:' . esc_attr( (string) ( $field['item_width'] ?? 60 ) ) . 'px;">';
				echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					if ( $disabled ) {
						$choice['disabled'] = true;
					}
					$checked  = ! empty( $choice['selected'] ) && ! $disabled;
					$classes  = $choice_classes( $choice, [ 'opf-swatch', 'opf-swatch--image', 'opf-product-choice', 'opf-image-swatch-label--' . ( $field['label_pos'] ?? 'tooltip' ) ] );
					if ( ! empty( $field['image_zoom'] ) ) {
						$classes[] = 'opf-swatch--image-zoom wapf-tt-wrap';
					}
					if ( ! $multi ) {
						$classes[] = 'opf-single-select';
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $wrapper_attrs( $choice ) . ' data-opf-swatch-label="' . esc_attr( $choice['label'] ) . '">';
					echo '<label>';
					if ( 'out' !== ( $field['label_pos'] ?? 'tooltip' ) ) {
						echo '<span class="opf-image-swatch-frame">';
						echo '<img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
						if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
							echo '<img class="opf-swatch-zoom-preview" src="' . esc_url( $choice['zoom_url'] ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
						}
						echo '</span>';
					}
					echo '<span class="opf-image-swatch-label">' . esc_html( $choice['label'] ) . $hint( $choice ) . '</span>';
					echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $input_attrs( $choice, $name . ( $multi ? '[]' : '' ), $checked ) . ' />';
					echo '</label>';
					echo '</div>';
				}
				echo '</div>';
				return;

			case 'card':
			case 'vcard':
			case 'card-qty':
			case 'vcard-qty':
				$vertical = in_array( $subtype, [ 'vcard', 'vcard-qty' ], true );
				echo '<style>.field-' . esc_html( $fid ) . ' .opf-card-wrap{--opf-cols:' . esc_html( (string) ( $field['items_per_row'] ?? 2 ) ) . ';--opf-cols-t:' . esc_html( (string) ( $field['items_per_row_tablet'] ?? 1 ) ) . ';--opf-cols-m:' . esc_html( (string) ( $field['items_per_row_mobile'] ?? 1 ) ) . ';}</style>';
				echo '<div class="opf-card-wrap opf-products opf-products--' . esc_attr( $subtype ) . '">';
				if ( ! $is_qty ) {
					echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
				}
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					if ( $disabled ) {
						$choice['disabled'] = true;
					}
					$checked = ! empty( $choice['selected'] ) && ! $disabled;
					$classes = $choice_classes( $choice, [ 'opf-card', 'opf-product-choice' ] );
					if ( $is_qty ) {
						$classes[] = 'is-qty-select';
					}
					if ( $vertical ) {
						$classes[] = 'opf-card--vertical';
						$classes[] = 'opf-card--fit-' . ( $field['img_fit'] ?? 'cover' );
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $wrapper_attrs( $choice ) . '>';
					echo '<div class="opf-card-inner">';
					if ( ! $is_qty ) {
						echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $input_attrs( $choice, $name . ( $multi ? '[]' : '' ), $checked ) . ' />';
					}
					if ( ! empty( $field['incl_img'] ) ) {
						echo '<div class="opf-card-img"><img class="opf-swatch-image" src="' . esc_url( $choice['image'] ) . '" alt="' . esc_attr( $choice['label'] ) . '" loading="lazy" decoding="async" />';
						if ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ) {
							echo '<img class="opf-swatch-zoom-preview" src="' . esc_url( $choice['zoom_url'] ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />';
						}
						echo '</div>';
					}
					echo '<div class="opf-card-body">';
					echo '<div class="opf-card-row"><div class="opf-card-title"><span>' . esc_html( $choice['label'] ) . '</span></div>';
					echo self::product_slot_info( $field, $choice, 'slot_1' );
					echo '</div>';
					if ( ! empty( $field['incl_desc'] ) && '' !== (string) $choice['desc'] ) {
						echo '<div class="opf-card-row"><div class="opf-card-desc">' . esc_html( wp_trim_words( wp_strip_all_tags( $choice['desc'] ), 20 ) ) . '</div></div>';
					}
					if ( $is_qty ) {
						$q       = $choice['quantity'] ?? [ 'default' => 0, 'min' => 0, 'max' => 999999 ];
						$default = $disabled ? 0 : (int) $q['default'];
						$max     = isset( $field['max_choices'] ) ? min( (int) $q['max'], (int) $field['max_choices'] ) : (int) $q['max'];
						echo '<div class="opf-card-row"><div class="opf-card-qty opf-qty' . ( 'plus_min' === ( $field['display'] ?? '' ) ? ' apf-plusmin' : '' ) . '">';
						if ( 'plus_min' === ( $field['display'] ?? '' ) ) {
							echo '<button type="button" tabindex="-1" aria-label="' . esc_attr__( 'Reduce', 'open-product-fields-for-woocommerce' ) . '" class="button apf-minus opf-qty-minus">−</button>';
						}
						echo '<input type="number" step="1" value="' . esc_attr( (string) $default ) . '" min="' . esc_attr( (string) (int) $q['min'] ) . '" max="' . esc_attr( (string) $max ) . '" name="' . esc_attr( $name . '[' . $choice['slug'] . ']' ) . '" class="opf-input opf-qty is-qty input-' . esc_attr( $fid ) . '_' . esc_attr( $choice['slug'] ) . '" data-field-id="' . esc_attr( $fid ) . '" data-choice-slug="' . esc_attr( $choice['slug'] ) . '" data-no-zero="1"' . ( $disabled ? ' disabled data-disabled="1"' : '' ) . ( ! empty( $field['image_zoom'] ) && ! empty( $choice['zoom_url'] ) ? ' data-opf-swap-image="' . esc_attr( $choice['zoom_url'] ) . '"' : '' ) . ' />';
						if ( 'plus_min' === ( $field['display'] ?? '' ) ) {
							echo '<button type="button" tabindex="-1" aria-label="' . esc_attr__( 'Increase', 'open-product-fields-for-woocommerce' ) . '" class="button apf-plus opf-qty-plus">+</button>';
						}
						echo '</div></div>';
					}
					$slot_2 = self::product_slot_info( $field, $choice, 'slot_2' );
					$slot_3 = self::product_slot_info( $field, $choice, 'slot_3' );
					if ( '' !== $slot_2 || '' !== $slot_3 ) {
						echo '<div class="opf-card-row">' . $slot_2 . $slot_3 . '</div>';
					}
					echo '</div></div></div>';
				}
				echo '</div>';
				return;

			default: // checkbox + radio
				echo '<div class="opf-checkboxes opf-products opf-products--' . esc_attr( $subtype ) . ' wapf-checkboxes">';
				echo '<input type="hidden" class="opf-tf-h" data-fid="' . esc_attr( $fid ) . '" value="0" name="' . esc_attr( $name ) . '" />';
				foreach ( $choices as $choice ) {
					$disabled = ! empty( $choice['disabled'] ) || ! $choice['product']->is_in_stock();
					if ( $disabled ) {
						$choice['disabled'] = true;
					}
					$checked = ! empty( $choice['selected'] ) && ! $disabled;
					$classes = $choice_classes( $choice, [ 'opf-swatch', 'opf-product-choice', 'wapf-input-label-wrap' ] );
					if ( ! $multi ) {
						$classes[] = 'opf-single-select';
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $wrapper_attrs( $choice ) . '>';
					echo '<label class="opf-input-label wapf-input-label">';
					echo '<input type="' . ( 'checkbox' === $subtype ? 'checkbox' : 'radio' ) . '" ' . $input_attrs( $choice, $name . ( 'checkbox' === $subtype ? '[]' : '' ), $checked ) . ' />';
					echo '<span class="opf-label-text wapf-label-text">' . esc_html( $choice['label'] ) . $hint( $choice ) . '</span>';
					echo '</label>';
					echo '</div>';
				}
				echo '</div>';
		}
	}

	/**
	 * Card slot content (price/stock/link) — WAPF Html::get_cart_info parity.
	 */
	private static function product_slot_info( array $field, array $choice, string $key ): string {
		$piece = (string) ( $field[ $key ] ?? 'none' );
		if ( 'none' === $piece || empty( $choice[ $piece ] ) ) {
			return '';
		}
		$html = '<div class="opf-card-info wapf-card-info opf-card-' . esc_attr( $piece ) . '">';
		if ( 'link' === $piece ) {
			$html .= '<a href="' . esc_url( $choice['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Details', 'open-product-fields-for-woocommerce' ) . '</a>';
		} else {
			$html .= wp_kses_post( (string) $choice[ $piece ] );
		}
		return $html . '</div>';
	}

	/**
	 * Legacy data attributes for the theme's live-total math. The theme
	 * interprets `data-opf-pricetype` with WAPF semantics (its evaluator
	 * treats fx results as per-unit after stripping an outermost *[qty]
	 * factor, qt as a per-unit amount, fixed as a flat per-line fee), so
	 * each OPF pricing block is encoded into the equivalent WAPF shape:
	 *  - percent per-unit → "percent" amount
	 *  - percent flat     → "fx" expression ([price]*a/100)/[qty]
	 *  - fixed flat       → "fixed" amount
	 *  - fixed per-unit   → "qt" amount
	 *  - formula per-unit → "fx" (expr) — the theme evaluates per unit
	 *  - formula flat     → "fx" (expr)/[qty] — per-unit share, flat line
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function pricing_attrs( array $pricing, bool $qty_based = false ): string {
		if ( 'none' === $pricing['type'] ) {
			return '';
		}
		$per_unit = ! empty( $pricing['per_unit'] );
		if ( 'formula' === $pricing['type'] ) {
			$expr  = '' !== trim( (string) $pricing['formula'] ) ? (string) $pricing['formula'] : '0';
			$type  = 'fx';
			$price = $qty_based
				? ( $per_unit ? '(' . $expr . ') * [qty]' : '(' . $expr . ')' )
				: ( $per_unit ? '(' . $expr . ')' : '(' . $expr . ') / [qty]' );
		} elseif ( 'percent' === $pricing['type'] && $qty_based && $per_unit ) {
			$type  = 'fx';
			$price = '([price] * ' . ( (float) $pricing['amount'] / 100 ) . ') * [qty]';
		} elseif ( 'percent' === $pricing['type'] && ! $per_unit ) {
			if ( $qty_based ) {
				$type  = 'percent';
				$price = (string) (float) $pricing['amount'];
			} else {
				$type  = 'fx';
				$price = '([price] * ' . ( (float) $pricing['amount'] / 100 ) . ') / [qty]';
			}
		} else {
			if ( $qty_based ) {
				$type = 'fixed' === $pricing['type'] && $per_unit ? 'fx' : 'qt';
				$price = 'fixed' === $pricing['type'] && $per_unit
					? '(' . (string) (float) $pricing['amount'] . ') * [qty]'
					: (string) (float) $pricing['amount'];
			} else {
				$type  = 'fixed' === $pricing['type'] && $per_unit ? 'qt' : $pricing['type'];
				$price = (string) (float) $pricing['amount'];
			}
		}
		return sprintf( ' data-opf-pricetype="%s" data-opf-price="%s"', esc_attr( $type ), esc_attr( $price ) );
	}

	/**
	 * Text-like inputs.
	 *
	 * @param string              $name  Input name.
	 * @param string              $gid   Group id.
	 * @param array<string,mixed> $field Field data.
	 */
	private static function render_input( string $name, string $gid, array $field, bool $qty_based = false ): void {
		$fid = $field['id'];
		$shared = sprintf(
			'data-field-id="%1$s" id="opf-%2$s-%1$s"%3$s name="%5$s" class="opf-input input-%1$s" placeholder="%4$s" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"',
			esc_attr( $fid ),
			esc_attr( $gid ),
			$field['required'] ? ' required' : '',
			esc_attr( $field['placeholder'] ),
			esc_attr( $name )
		);
		$shared .= self::pricing_attrs( $field['pricing'] ?? [], $qty_based );

		switch ( $field['type'] ) {
			case 'upload':
				$modern = Uploads::modern();
				echo '<div class="opf-upload" data-opf-upload="' . ( $modern ? 'modern' : 'native' ) . '" data-opf-upload-name="' . esc_attr( $name ) . '" data-opf-upload-group="' . esc_attr( $gid ) . '" data-opf-upload-field="' . esc_attr( $field['id'] ) . '" data-opf-upload-limit="' . esc_attr( (string) Uploads::max_files( $field ) ) . '" data-opf-upload-url="' . esc_url( rest_url( 'opf/v1/uploads' ) ) . '">';
				echo '<input type="hidden" name="opf_upload_native_nonce" value="' . esc_attr( wp_create_nonce( 'opf_upload_native' ) ) . '" />';
				echo '<input type="file" class="opf-upload__input" id="opf-' . esc_attr( $gid . '-' . $field['id'] ) . '" name="opf_upload[' . esc_attr( $gid ) . '][' . esc_attr( $field['id'] ) . '][]"' . ( $field['accepted_types'] ? ' accept="' . esc_attr( '.' . implode( ',.', $field['accepted_types'] ) ) . '"' : '' ) . ( $field['multiple'] ? ' multiple' : '' ) . ( $field['required'] ? ' required' : '' ) . ' />';
				echo '<div class="opf-upload__files"></div><div class="opf-upload__status" role="status" aria-live="polite"></div></div>';
				break;
			case 'textarea':
				echo '<textarea ' . $shared . '>' . esc_html( (string) ( $field['default'] ?? '' ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
				break;
			case 'url':
				echo '<input type="url" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'email':
				echo '<input type="email" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<input type="number" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'date':
				$date_attrs = ' data-opf-date-format="' . esc_attr( \OPF\Engine\DateFormat::configured() ) . '"';
				$date_attrs .= ' data-opf-week-start="' . esc_attr( (string) min( 6, max( 0, (int) get_option( 'start_of_week', 0 ) ) ) ) . '"';
				$date_min = isset( $field['min_date'] ) ? FieldValue::resolve_date_boundary( (string) $field['min_date'] ) : null;
				$date_max = isset( $field['max_date'] ) ? FieldValue::resolve_date_boundary( (string) $field['max_date'] ) : null;
				$current = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
				$site_today = $current->format( 'Y-m-d' );
				if ( false === ( $field['allow_past'] ?? true ) && ( null === $date_min || $date_min < $site_today ) ) {
					$date_min = $site_today;
				}
				if ( false === ( $field['allow_future'] ?? true ) && ( null === $date_max || $date_max > $site_today ) ) {
					$date_max = $site_today;
				}
				foreach ( [ 'min_date' => 'min', 'max_date' => 'max' ] as $key => $attribute ) {
					$date = 'min_date' === $key ? $date_min : $date_max;
					if ( null !== $date ) {
						$date_attrs .= ' ' . $attribute . '="' . esc_attr( $date ) . '"';
					}
				}
				if ( ! empty( $field['disabled_weekdays'] ) ) {
					$date_attrs .= ' data-opf-disabled-weekdays="' . esc_attr( wp_json_encode( array_values( $field['disabled_weekdays'] ) ) ) . '"';
				}
				if ( ! empty( $field['disabled_dates'] ) ) {
					$date_attrs .= ' data-opf-disabled-dates="' . esc_attr( wp_json_encode( array_values( $field['disabled_dates'] ) ) ) . '"';
				}
				if ( isset( $field['cutoff_time'] ) ) {
					$current = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
					$date_attrs .= ' data-opf-date-cutoff="' . esc_attr( $field['cutoff_time'] ) . '"';
					$date_attrs .= ' data-opf-date-site-epoch="' . esc_attr( (string) $current->getTimestamp() ) . '"';
					$date_attrs .= ' data-opf-date-timezone="' . esc_attr( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' ) . '"';
				}
				echo '<input type="date" value="" ' . $shared . $date_attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'toggle':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
				echo '<input type="checkbox" value="1" ' . $shared . ( '1' === ( $field['default'] ?? '0' ) ? ' checked' : '' ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( '' !== ( $field['message'] ?? '' ) ) {
					echo '<label for="opf-' . esc_attr( $gid . '-' . $fid ) . '">' . esc_html( $field['message'] ) . '</label>';
				}
				break;
			default:
				echo '<input type="text" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
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
		// WAPF alias bridge: legacy wapf_before_product_totals action.
		\OPF\Compat\WapfHooks::before_product_totals( $product );
		$mode = self::summary_mode();
		$hidden = 'hidden' === $mode ? ' opf-totals-hidden' : '';
		$i18n = self::i18n();
		$data_tax = 1;
		if (
			function_exists( 'wc_prices_include_tax' )
			&& ! wc_prices_include_tax()
			&& get_option( 'woocommerce_tax_display_shop' ) === 'excl'
		) {
			$data_tax = 1;
		}
		echo '<div class="opf-product-totals' . esc_attr( $hidden ) . '" style="' . ( 'hidden' === $mode ? 'display:none;' : '' ) . '" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-product-type="' . esc_attr( $product->get_type() ) . '" data-product-price="' . esc_attr( (string) $product->get_price() ) . '" data-tax="' . esc_attr( (string) $data_tax ) . '"><div class="opf--inner">';
		if ( 'three' === $mode ) {
			echo '<div><span>' . esc_html( $i18n['product_total'] ) . '</span> <span class="opf-total opf-product-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['options_total'] ) . '</span> <span class="opf-total opf-options-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		} elseif ( 'grand' === $mode ) {
			echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		} else {
			echo '<div><span>' . esc_html( $i18n['product_total'] ) . '</span> <span class="opf-total opf-product-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['options_total'] ) . '</span> <span class="opf-total opf-options-total price amount"></span></div>';
			echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		}
		echo '</div></div>';
		// WAPF alias bridge: legacy wapf_after_product_totals action.
		\OPF\Compat\WapfHooks::after_product_totals( $product );
	}

	/**
	 * Default submitted value for a field (seeds conditional evaluation).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @return string|array
	 */
	private static function default_value( array $field ) {
		if ( in_array( $field['type'], [ 'text', 'url' ], true ) ) {
			return (string) ( $field['default'] ?? '' );
		}
		if ( 'toggle' === $field['type'] ) {
			return (string) ( $field['default'] ?? '0' );
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox', 'products' ], true ) ) {
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
