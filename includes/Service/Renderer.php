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

		echo '<div class="opf-fields" data-opf-fields="' . esc_attr( (string) count( $groups ) ) . '"><div class="opf" id="opf_' . esc_attr( (string) $product->get_id() ) . '"><div class="opf-wrapper">';

		foreach ( $groups as $entry ) {
			$gids[] = (string) $entry['id'];
			self::render_group( $entry['id'], $entry['title'], $entry['group'], $base_price );
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
					'repeat'       => $field['repeat'] ?? null,
					'multiple'     => ! empty( $field['multiple'] ),
					'min_choices'  => $field['min_choices'] ?? null,
					'max_choices'  => $field['max_choices'] ?? null,
					'conditionals' => $field['conditionals'],
				'choices'      => array_map( static function ( $c ) {
					return [
						'slug'     => $c['slug'],
						'label'    => $c['label'],
						'pricing'  => [
								'type'       => $c['pricing']['type'],
								'amount'     => (float) $c['pricing']['amount'],
								'formula'    => (string) $c['pricing']['formula'],
								'formula_raw' => (string) ( $c['pricing']['formula_raw'] ?? '' ),
							],
						];
					}, (array) ( $field['choices'] ?? [] ) ),
					'pricing'      => [
						'type'    => $field['pricing']['type'],
						'amount'  => (float) $field['pricing']['amount'],
						'formula' => (string) ( $field['pricing']['formula'] ?? '' ),
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
	public static function render_group( $gid, string $title, FieldGroup $group, float $base_price ): void {
		// Seed conditionals with default selections.
		$values = [];
		foreach ( $group->data['fields'] as $field ) {
			$values[ $field['id'] ] = self::default_value( $field );
		}

		echo '<div class="opf-field-group label-' . esc_attr( 'above' === $group->data['labels_position'] ? 'above' : 'below' ) . '" data-group="' . esc_attr( (string) $gid ) . '" data-variables="[]" data-opf-group="' . esc_attr( (string) $gid ) . '">';

		$open_sections = 0;
		foreach ( $group->data['fields'] as $field ) {
			if ( 'section' === $field['type'] ) {
				self::render_section( $field, $values );
				$open_sections++;
				continue;
			}
			if ( 'section_end' === $field['type'] ) {
				if ( $open_sections > 0 ) {
					echo '</div>';
					$open_sections--;
				}
				continue;
			}
			if ( ! empty( $field['repeat']['enabled'] ) ) {
				self::render_repeated_field( $gid, $field, $values, $base_price );
			} else {
				self::render_field( $gid, $field, $values, $base_price );
			}
		}
		while ( $open_sections > 0 ) {
			echo '</div>';
			$open_sections--;
		}

		echo '</div>';
	}

	/** Render the opening wrapper for a WAPF-compatible section marker. */
	private static function render_section( array $field, array $values ): void {
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
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $field['id'] ) . '" style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
	}

	/**
	 * Render a single field — legacy DOM contract.
	 *
	 * @param string              $gid        Group id.
	 * @param array<string,mixed> $field      Normalized field.
	 * @param array<string,mixed> $values     Seeded values (for conditional state).
	 * @param float               $base_price Base unit price.
	 */
	private static function render_repeated_field( string $gid, array $field, array $values, float $base_price ): void {
		$fid = (string) $field['id'];
		$repeat = $field['repeat'];
		if ( 'button' !== ( $repeat['mode'] ?? '' ) || ! in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
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
		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-opf-field="' . esc_attr( $fid ) . '" data-opf-repeat="button" data-opf-repeat-max="' . esc_attr( (string) ( $repeat['max'] ?? 10000 ) ) . '" style="width:' . esc_attr( (string) $field['width'] ) . '%;">';
		echo '<div class="opf-field-repeat__rows">';
		$instance = $field;
		$instance['_opf_source_id'] = $fid;
		$instance['_opf_repeat_index'] = 0;
		$instance['id'] = $fid . '-repeat-0';
		self::render_field( $gid, $instance, $values, $base_price, true );
		echo '</div><button type="button" class="opf-field-repeat__add">' . esc_html__( 'Add another', 'open-product-fields-for-woocommerce' ) . '</button>';
		echo '<span class="screen-reader-text opf-field-repeat__status" aria-live="polite"></span></div>';
	}

	private static function render_field( string $gid, array $field, array $values, float $base_price, bool $repeat_instance = false ): void {
		$fid      = $field['id'];
		$source_fid = (string) ( $field['_opf_source_id'] ?? $fid );
		$name     = sprintf( 'opf[%s][%s]', $gid, $source_fid );
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
					'img' => [ 'src' => [], 'class' => [], 'style' => [], 'id' => [] ],
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

		echo '<div class="opf-field-label"><label';
		if ( ! in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			echo ' for="opf-' . esc_attr( $gid . '-' . $fid ) . '"';
		}
		echo '><span>' . esc_html( $field['label'] ) . '</span> ';
		if ( $field['required'] ) {
			echo '<abbr class="required" title="' . esc_attr( self::required_title() ) . '">*</abbr>';
		}
		echo '</label></div>';

		if ( '' !== $field['description'] ) {
			echo '<div class="opf-field-description">' . esc_html( $field['description'] ) . '</div>';
		}

		echo '<div class="opf-field-input">';

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
			echo '<select name="' . esc_attr( $name ) . '" id="opf-' . esc_attr( $gid . '-' . $fid ) . '" class="opf-input input-' . esc_attr( $fid ) . '" autocomplete="off">';
			foreach ( $field['choices'] as $choice ) {
				echo '<option value="' . esc_attr( $choice['slug'] ) . '"' . selected( $choice['selected'], true, false ) . '>'
					. esc_html( $choice['label'] )
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
			if ( $choice['selected'] ) {
				$swatch_classes[] = 'opf-checked';
			}
			if ( 'none' !== $choice['pricing']['type'] ) {
				$swatch_classes[] = 'has-pricing';
			}

			$attrs = sprintf(
				'autocomplete="off" id="opf-%1$s-%2$s-%3$s" name="%4$s" class="opf-input input-%2$s" data-field-id="%2$s" value="%5$s" data-opf-label="%6$s" data-wapf-label="%6$s"%7$s%8$s%9$s',
				esc_attr( $gid ),
				esc_attr( $fid ),
				esc_attr( $choice['slug'] ),
				esc_attr( $name . ( $multi ? '[]' : '' ) ),
				esc_attr( $choice['slug'] ),
				esc_attr( $choice['label'] ),
				$field['required'] && ( ! $multi || ! isset( $field['_opf_repeat_index'] ) ) ? ' required' : '',
				$choice['selected'] ? ' checked' : '',
				self::pricing_attrs( $choice['pricing'] )
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
				$image_html = (string) wp_get_attachment_image(
					(int) $choice['image_id'],
					'medium',
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
			echo '<span' . $label_class . '>' . esc_html( $choice['label'] ) . ' </span>';
			echo '<input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
			echo '</label>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Legacy data attributes for the theme's live-total math, verbatim:
	 *  - percent : data-opf-price = percent amount
	 *  - fixed   : data-opf-price = amount
	 *  - formula : data-opf-price = raw legacy expression (theme evaluates it)
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
		return sprintf( ' data-opf-pricetype="%s" data-opf-price="%s"', esc_attr( $type ), esc_attr( $price ) );
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
			'data-field-id="%1$s" id="opf-%2$s-%1$s"%3$s name="%5$s" class="opf-input input-%1$s" placeholder="%4$s" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"',
			esc_attr( $fid ),
			esc_attr( $gid ),
			$field['required'] ? ' required' : '',
			esc_attr( $field['placeholder'] ),
			esc_attr( $name )
		);

		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea ' . $shared . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped.
				break;
			case 'url':
				echo '<input type="url" value="" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'email':
				echo '<input type="email" value="" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<input type="number" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'date':
				$date_attrs = ' data-opf-date-format="' . esc_attr( \OPF\Engine\DateFormat::normalize( get_option( 'opf_date_format', get_option( 'wapf_date_format', \OPF\Engine\DateFormat::DEFAULT_FORMAT ) ) ) ) . '"';
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
				echo '<input type="checkbox" value="1" ' . $shared . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
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
		$hidden = self::show_totals() ? '' : ' opf-totals-hidden';
		$i18n = self::i18n();
		$data_tax = 1;
		if (
			function_exists( 'wc_prices_include_tax' )
			&& ! wc_prices_include_tax()
			&& get_option( 'woocommerce_tax_display_shop' ) === 'excl'
		) {
			$data_tax = 1;
		}
		echo '<div class="opf-product-totals' . esc_attr( $hidden ) . '" style="' . ( self::show_totals() ? '' : 'display:none;' ) . '" data-product-id="' . esc_attr( (string) $product->get_id() ) . '" data-product-type="' . esc_attr( $product->get_type() ) . '" data-product-price="' . esc_attr( (string) $product->get_price() ) . '" data-tax="' . esc_attr( (string) $data_tax ) . '"><div class="opf--inner">';
		echo '<div><span>' . esc_html( $i18n['product_total'] ) . '</span> <span class="opf-total opf-product-total price amount"></span></div>';
		echo '<div><span>' . esc_html( $i18n['options_total'] ) . '</span> <span class="opf-total opf-options-total price amount"></span></div>';
		echo '<div><span>' . esc_html( $i18n['grand_total'] ) . '</span> <span class="opf-total opf-grand-total price amount"></span></div>';
		echo '</div></div>';
	}

	/**
	 * Default submitted value for a field (seeds conditional evaluation).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @return string|array
	 */
	private static function default_value( array $field ) {
		if ( 'toggle' === $field['type'] ) {
			return '0';
		}
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
