<?php
/**
 * Maps legacy WAPF field group data to OPF group data.
 *
 * Behavioral notes preserved from production data analysis (Sept 2026):
 *  - WAPF choice pricing types in the wild: none, fixed, percent, fx (formula).
 *  - WAPF formula amounts end in "* [qty]" because WAPF normalized per-unit
 *    results by dividing by quantity; OPF pricing is already per-unit, so the
 *    mapper strips a trailing quantity multiplication.
 *  - WAPF groups whose placement rule had an empty condition evaluated FALSE
 *    in WAPF (dead groups). The mapper flags those rather than silently
 *    turning them into "show everywhere".
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class WapfMapper {

	/**
	 * WAPF field type → OPF field type.
	 */
	private const TYPE_MAP = [
		'text'          => 'text',
		'textarea'      => 'textarea',
		'email'         => 'email',
		'url'           => 'url',
		'number'        => 'number',
		'date'          => 'date',
		'true-false'   => 'toggle',
		'select'        => 'select',
		'radio'         => 'radio',
		'checkbox'      => 'checkbox',
		'checkboxes'    => 'checkbox',
		'image-swatch-qty' => 'image_quantity',
		'text-swatch'   => 'swatch',
		'multi-text-swatch' => 'swatch',
		'image-swatch'  => 'swatch',
		'multi-image-swatch' => 'swatch',
		'color-swatch' => 'swatch',
		'multi-color-swatch' => 'swatch',
		'content'       => 'paragraph',
		'paragraph'     => 'paragraph',
		'p'             => 'paragraph',
		'img'            => 'content_image',
		'section'        => 'section',
		'sectionend'     => 'section_end',
	];

	/**
	 * WAPF condition → OPF operator.
	 */
	private const CONDITION_MAP = [
		'is'        => 'is',
		'=='        => 'is',
		'is_not'    => 'is_not',
		'not_is'    => 'is_not',
		'!='        => 'is_not',
		'contains'  => 'contains',
		'==contains' => 'contains',
		'!=contains' => 'not_contains',
		'gt'        => 'greater',
		'lt'        => 'less',
		'greater'   => 'greater',
		'less'      => 'less',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
		'!empty'    => 'not_empty',
	];

	/**
	 * Map a parsed WAPF group array to an OPF group data array.
	 *
	 * @param array<string,mixed> $wapf      Parsed WAPF group payload.
	 * @param array<string,mixed> $overrides Optional overrides: ['attach_product_ids' => int[]].
	 * @return array{group:array<string,mixed>,notes:string[],needs_review:bool}
	 */
	public static function map( array $wapf, array $overrides = [] ): array {
		$notes        = [];
		$needs_review = false;

		$fields        = [];
		$unsupported   = [];
		$seen_ids      = [];
		$opf_ids_by_index = [];
		$opf_ids_by_wapf_id = [];
		$source_order_by_wapf_id = [];
		$source_fields = is_array( $wapf['fields'] ?? null ) ? $wapf['fields'] : [];

		// Generate every destination ID first so conditional references can point
		// forward or backward in the source field order.
		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) ) {
				$notes[] = sprintf( 'field at index %s is malformed and was skipped.', (string) $index );
				$needs_review = true;
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );
			if ( ! isset( self::TYPE_MAP[ $wapf_type ] ) ) {
				$unsupported[] = $wapf_type . ':' . ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				continue;
			}
			$field_id = self::field_id( (string) ( $wapf_field['label'] ?? '' ), (string) ( $wapf_field['id'] ?? '' ), $seen_ids );
			$seen_ids[ $field_id ] = true;
			$opf_ids_by_index[ $index ] = $field_id;
			$source_id = is_scalar( $wapf_field['id'] ?? null ) ? (string) $wapf_field['id'] : '';
			if ( '' !== $source_id ) {
				if ( array_key_exists( $source_id, $opf_ids_by_wapf_id ) ) {
					$opf_ids_by_wapf_id[ $source_id ] = null;
					$source_order_by_wapf_id[ $source_id ] = null;
					$notes[] = sprintf( 'WAPF field ID "%s" is duplicated; conditions referencing it need review.', $source_id );
					$needs_review = true;
				} else {
					$opf_ids_by_wapf_id[ $source_id ] = $field_id;
					$source_order_by_wapf_id[ $source_id ] = (int) $index;
				}
			}
		}

		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) || ! isset( $opf_ids_by_index[ $index ] ) ) {
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );
			$field_id = $opf_ids_by_index[ $index ];

			$has_choices = in_array( self::TYPE_MAP[ $wapf_type ], [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox' ], true );
			$image_swatch_settings = in_array( $wapf_type, [ 'image-swatch', 'multi-image-swatch' ], true ) ? self::map_image_swatch_settings( $wapf_field, $notes, $needs_review ) : [];
			$color_swatch_settings = in_array( $wapf_type, [ 'color-swatch', 'multi-color-swatch' ], true ) ? self::map_color_swatch_settings( $wapf_field, $notes, $needs_review ) : [];
			$selection_limits = in_array( $wapf_type, [ 'multi-text-swatch', 'multi-image-swatch', 'multi-color-swatch' ], true ) ? self::map_swatch_selection_limits( $wapf_field, $notes, $needs_review ) : [];
			$quantity_limits = 'image-swatch-qty' === $wapf_type ? self::map_image_quantity_limits( $wapf_field, $notes, $needs_review ) : [];
			$content = '';
			$image_url = '';
			$image_id = 0;
			$content_format = 'plain';
			$process_shortcodes = false;
			if ( 'paragraph' === self::TYPE_MAP[ $wapf_type ] ) {
				$content = (string) ( $wapf_field['options']['p_content'] ?? $wapf_field['p_content'] ?? '' );
				if ( 'p' === $wapf_type ) {
					$content_format = 'html';
					$process_shortcodes = true;
				} elseif ( preg_match( '/<\/?[a-z][^>]*>/i', $content ) ) {
					$notes[] = sprintf( 'field "%s" contains HTML; the plain-text paragraph was imported with markup removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
					$content = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $content ) : strip_tags( $content );
				}
			}
			if ( 'img' === $wapf_type ) {
				$raw_image_url = $wapf_field['options']['image'] ?? $wapf_field['image'] ?? null;
				$raw_image_id = $wapf_field['options']['attachment'] ?? $wapf_field['attachment'] ?? null;
				$image_url = is_scalar( $raw_image_url ) ? (string) $raw_image_url : '';
				$image_id = is_scalar( $raw_image_id ) ? (int) $raw_image_id : 0;
			}
			$repeat = self::map_repeat_settings( $wapf_field, $notes, $needs_review );
			$date_settings = 'date' === $wapf_type ? self::map_date_settings( $wapf_field, $notes, $needs_review ) : [];

			$field = FieldGroup::normalize_field(
				array_merge( [
					'id'           => $field_id,
					'label'        => (string) ( $wapf_field['label'] ?? '' ),
					'description'  => (string) ( $wapf_field['description'] ?? '' ),
					'type'         => self::TYPE_MAP[ $wapf_type ],
					'required'     => (bool) ( $wapf_field['required'] ?? false ),
					'width'        => (int) ( $wapf_field['width'] ?? 100 ),
					'css_class'    => (string) ( $wapf_field['class'] ?? '' ),
					'placeholder'  => (string) ( $wapf_field['options']['placeholder'] ?? '' ),
					'swatch_style' => in_array( $wapf_type, [ 'image-swatch', 'multi-image-swatch' ], true ) ? 'image' : ( in_array( $wapf_type, [ 'color-swatch', 'multi-color-swatch' ], true ) ? 'color' : 'text' ),
					'multiple'     => in_array( $wapf_type, [ 'multi-text-swatch', 'multi-image-swatch', 'multi-color-swatch' ], true ),
					'choices'      => $has_choices ? self::map_choices( $wapf_field, $notes, $needs_review, $opf_ids_by_wapf_id, $source_order_by_wapf_id, (int) $index ) : [],
					'pricing'      => self::map_field_pricing( $wapf_field, $notes, $needs_review, $opf_ids_by_wapf_id, $source_order_by_wapf_id, (int) $index ),
					'conditionals' => self::map_conditionals( $wapf_field, $notes, $opf_ids_by_wapf_id, $needs_review ),
					'content'      => $content,
					'image_url'    => $image_url,
					'image_id'     => $image_id,
					'content_format' => $content_format,
					'process_shortcodes' => $process_shortcodes,
					'repeat' => $repeat,
				], $image_swatch_settings, $color_swatch_settings, $selection_limits, $quantity_limits, $date_settings )
			);
			if ( 'paragraph' === $field['type'] ) {
				if ( ! empty( $wapf_field['required'] ) ) {
					$notes[] = sprintf( 'field "%s" is static content; its required setting was removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
				if ( ! empty( $wapf_field['pricing']['enabled'] ) ) {
					$notes[] = sprintf( 'field "%s" is static content; its field pricing was removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
			}

			if ( in_array( $wapf_type, [ 'image-swatch', 'multi-image-swatch', 'image-swatch-qty' ], true ) ) {
				$notes[] = sprintf( 'field "%s" is an image swatch; choice media references are imported, but image files are not bundled and attachment IDs may need remapping on the destination site.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				$needs_review = true;
			}
			if ( 'image-swatch-qty' === $wapf_type ) {
				$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
				$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				if ( ! empty( $options['large_image'] ) ) {
					$notes[] = sprintf( 'image quantity field "%s" uses WAPF enlarged-image zoom; OPF does not preserve that zoom behavior.', $label );
					$needs_review = true;
				}
				if ( isset( $options['label_pos'] ) && 'default' !== $options['label_pos'] ) {
					$notes[] = sprintf( 'image quantity field "%s" uses label position "%s"; OPF renders the label with its quantity input.', $label, (string) $options['label_pos'] );
					$needs_review = true;
				}
				foreach ( [ 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile' ] as $layout_key ) {
					if ( array_key_exists( $layout_key, $options ) && (int) $options[ $layout_key ] !== 3 ) {
						$notes[] = sprintf( 'image quantity field "%s" has custom %s=%s; OPF does not preserve this WAPF column setting.', $label, $layout_key, (string) $options[ $layout_key ] );
						$needs_review = true;
					}
				}
				if ( ! empty( $wapf_field['required'] ) ) {
					$notes[] = sprintf( 'image quantity field "%s" is required in WAPF; OPF quantity choices remain optional.', $label );
					$needs_review = true;
				}
			}
			if ( 'img' === $wapf_type && ! empty( $field['image_id'] ) ) {
				$notes[] = sprintf( 'field "%s" uses a site-local image attachment ID; verify or remap the attachment on the destination site.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				$needs_review = true;
			}

			$fields[] = $field;
		}

		$section_depth = 0;
		foreach ( $fields as $mapped_field ) {
			if ( 'section' === $mapped_field['type'] ) {
				$section_depth++;
			} elseif ( 'section_end' === $mapped_field['type'] ) {
				if ( 0 === $section_depth ) {
					$notes[] = sprintf( 'section-end field "%s" has no matching section and needs review.', $mapped_field['id'] );
					$needs_review = true;
				} else {
					$section_depth--;
				}
			}
		}
		if ( $section_depth > 0 ) {
			$notes[]      = sprintf( '%d section field(s) have no matching section-end marker and need review.', $section_depth );
			$needs_review = true;
		}

		if ( $unsupported ) {
			$notes[]      = 'unsupported field types dropped: ' . implode( ', ', $unsupported );
			$needs_review = true;
		}

		$placement = self::map_placement( $wapf, $overrides, $notes, $needs_review );

		$group = FieldGroup::normalize(
			[
				'fields'          => $fields,
				'rule_groups'     => $placement,
				'mark_required'   => (bool) ( $wapf['layout']['mark_required'] ?? true ),
				'labels_position' => ( $wapf['layout']['labels_position'] ?? 'above' ),
			]
		);

		return [
			'group'        => $group,
			'notes'        => $notes,
			'needs_review' => $needs_review,
		];
	}

	/** Map WAPF Extended date constraints supported by the OPF date schema. */
	private static function map_date_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$settings = [];
		foreach ( [ 'disable_past' => 'allow_past', 'disable_future' => 'allow_future' ] as $source => $target ) {
			if ( array_key_exists( $source, $options ) && in_array( $options[ $source ], [ true, false, 0, 1, '0', '1' ], true ) ) {
				$settings[ $target ] = ! in_array( $options[ $source ], [ true, 1, '1' ], true );
			} elseif ( array_key_exists( $source, $options ) ) {
				$notes[] = sprintf( 'date field "%s" has invalid %s value %s; date selection needs review.', $label, $source, self::review_value( $options[ $source ] ) );
				$needs_review = true;
			}
		}
		foreach ( [ 'min_date' => 'min_date', 'max_date' => 'max_date' ] as $source => $target ) {
			if ( ! isset( $options[ $source ] ) || '' === $options[ $source ] ) {
				continue;
			}
			$value = is_scalar( $options[ $source ] ) ? trim( (string) $options[ $source ] ) : '';
			$boundary = self::wapf_date_boundary( $value );
			if ( null !== $boundary ) {
				$settings[ $target ] = $boundary;
			} else {
				$notes[] = sprintf( 'date field "%s" has unsupported %s value "%s" (including WAPF field-relative dates); the original value was not mapped and needs manual review.', $label, $source, $value );
				$needs_review = true;
			}
		}
		if ( isset( $options['disabled_days'] ) && is_array( $options['disabled_days'] ) ) {
			$weekdays = [];
			foreach ( $options['disabled_days'] as $day ) {
				if ( is_scalar( $day ) && preg_match( '/^[0-6]$/', (string) $day ) ) {
					$weekdays[] = (int) $day;
				} else {
					$notes[] = sprintf( 'date field "%s" has an unrecognized disabled weekday "%s"; weekday rules need review.', $label, is_scalar( $day ) ? (string) $day : '[complex value]' );
					$needs_review = true;
				}
			}
			$settings['disabled_weekdays'] = array_values( array_unique( $weekdays ) );
		} elseif ( array_key_exists( 'disabled_days', $options ) ) {
			$notes[] = sprintf( 'date field "%s" has malformed disabled_days; weekday rules need review.', $label );
			$needs_review = true;
		}
		if ( isset( $options['disabled_dates'] ) && '' !== $options['disabled_dates'] ) {
			$raw_dates = is_scalar( $options['disabled_dates'] ) ? (string) $options['disabled_dates'] : '';
			$dates = [];
			foreach ( preg_split( '/\s*,\s*/', trim( $raw_dates ) ) as $raw_rule ) {
				$parts = preg_split( '/\s+/', trim( $raw_rule ) );
				$converted = [];
				foreach ( $parts as $part ) {
					$date = self::wapf_date_boundary( $part );
					if ( null === $date && preg_match( '/^\d{2}-\d{2}$/', $part ) && FieldValue::is_disabled_date( $part ) ) {
						$date = $part;
					}
					if ( null === $date ) {
						$converted = [];
						break;
					}
					$converted[] = $date;
				}
				if ( count( $converted ) === count( $parts ) && in_array( count( $converted ), [ 1, 2 ], true ) ) {
					$dates[] = implode( ' ', $converted );
				} else {
					$notes[] = sprintf( 'date field "%s" has unsupported disabled date rule "%s"; original disabled_dates value "%s" needs manual review.', $label, $raw_rule, $raw_dates );
					$needs_review = true;
				}
			}
			if ( $dates ) {
				$settings['disabled_dates'] = $dates;
			}
		}
		if ( ! empty( $options['disable_today'] ) ) {
			$notes[] = sprintf( 'date field "%s" has unsupported disable_today value %s; OPF has no equivalent and the source value needs manual review.', $label, self::review_value( $options['disable_today'] ) );
			$needs_review = true;
		}
		if ( isset( $options['default'] ) && '' !== $options['default'] ) {
			$default = is_scalar( $options['default'] ) ? (string) $options['default'] : '[complex value]';
			$notes[] = sprintf( 'date field "%s" has WAPF default "%s"; OPF date fields do not store a default value, so it needs manual review.', $label, $default );
			$needs_review = true;
		}
		$known_options = [ 'placeholder', 'default', 'disable_past', 'disable_future', 'disable_today', 'disable_today_after', 'disabled_days', 'disabled_dates', 'min_date', 'max_date' ];
		$unknown_options = array_diff( array_keys( $options ), $known_options );
		if ( $unknown_options ) {
			$unknown_values = [];
			foreach ( $unknown_options as $key ) {
				$unknown_values[] = (string) $key . '=' . self::review_value( $options[ $key ] );
			}
			$notes[] = sprintf( 'date field "%s" has unrecognized WAPF options (%s); original values need manual review.', $label, implode( ', ', $unknown_values ) );
			$needs_review = true;
		}
		if ( ! empty( $options['disable_today_after'] ) ) {
			$value = is_scalar( $options['disable_today_after'] ) ? (string) $options['disable_today_after'] : '';
			if ( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
				$settings['cutoff_time'] = $value;
			} else {
				$notes[] = sprintf( 'date field "%s" has unsupported disable_today_after value "%s"; cutoff needs manual review.', $label, $value );
				$needs_review = true;
			}
		}
		return $settings;
	}

	/** Convert WAPF's mm-dd-yyyy literal to OPF's ISO boundary, preserving relative periods. */
	private static function wapf_date_boundary( string $value ): ?string {
		if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $value, $match ) ) {
			$date = sprintf( '%04d-%02d-%02d', (int) $match[3], (int) $match[1], (int) $match[2] );
			return FieldValue::is_date_boundary( $date ) ? $date : null;
		}
		return FieldValue::is_date_boundary( $value ) ? $value : null;
	}

	/** Format a source option value for an actionable migration review note. */
	private static function review_value( $value ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		return false === $encoded ? '[unserializable value]' : (string) $encoded;
	}

	/** Map WAPF clone settings and flag only settings without an OPF equivalent. */
	private static function map_repeat_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$clone = $wapf_field['clone'] ?? [];
		if ( ! is_array( $clone ) || [] === $clone ) {
			return [];
		}
		$enabled = $clone['enabled'] ?? false;
		if ( in_array( $enabled, [ false, 0, '0', 'false', null ], true ) ) {
			return [];
		}
		if ( ! in_array( $enabled, [ true, 1, '1', 'true' ], true ) ) {
			$notes[] = sprintf( 'field "%s" has an invalid WAPF clone enabled flag; its repeat settings need manual review.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
			$needs_review = true;
			return [];
		}

		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$unknown_clone_keys = array_diff( array_keys( $clone ), [ 'enabled', 'type', 'max', 'add', 'del', 'label', 'field' ] );
		if ( $unknown_clone_keys ) {
			$notes[] = sprintf( 'field "%s" has unsupported WAPF clone settings (%s); they need manual review.', $label, implode( ', ', array_map( 'strval', $unknown_clone_keys ) ) );
			$needs_review = true;
		}
		if ( 'sectionend' === ( $wapf_field['type'] ?? '' ) ) {
			$notes[] = sprintf( 'field "%s" is a WAPF section-end marker with clone settings; the marker was imported without repeat settings and needs review.', $label );
			$needs_review = true;
			return [];
		}
		$type = (string) ( $clone['type'] ?? '' );
		if ( ! in_array( $type, [ 'button', 'qty' ], true ) ) {
			$notes[] = sprintf( 'field "%s" uses unsupported WAPF clone type "%s"; its repeat settings need manual review.', $label, $type );
			$needs_review = true;
			return [];
		}

		$repeat = [ 'enabled' => true, 'mode' => 'qty' === $type ? 'quantity' : 'button' ];
		$can_map_repeat = true;
		if ( array_key_exists( 'label', $clone ) ) {
			$repeat['label'] = $clone['label'];
		}
		if ( 'button' === $type ) {
			$max = $clone['max'] ?? RepeaterField::DEFAULT_BUTTON_ROWS;
			if ( '' === $max ) {
				$max = RepeaterField::DEFAULT_BUTTON_ROWS;
			}
			foreach ( [ 'add', 'del' ] as $key ) {
				if ( array_key_exists( $key, $clone ) && '' !== $clone[ $key ] ) {
					$repeat[ $key ] = $clone[ $key ];
				}
			}
			$repeat['max'] = $max;
		}
		try {
			$repeat = RepeaterField::normalize( $repeat );
		} catch ( \InvalidArgumentException $exception ) {
			$notes[] = sprintf( 'field "%s" has invalid or unrepresentable repeater settings; the repeat settings need manual review.', $label );
			$needs_review = true;
			$can_map_repeat = false;
		}
		$mapped_type = self::TYPE_MAP[ (string) ( $wapf_field['type'] ?? '' ) ] ?? '';
		$repeatable_types = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ];
		if ( 'section' === ( $wapf_field['type'] ?? '' ) || ( 'qty' === $type && ! in_array( $mapped_type, $repeatable_types, true ) ) ) {
			$notes[] = sprintf( 'field "%s" uses quantity or section repeat behavior that OPF does not implement yet.', $label );
			$needs_review = true;
		}
		if ( ! empty( $clone['field'] ) ) {
			$notes[] = sprintf( 'field "%s" uses a WAPF clone field reference that OPF does not preserve yet.', $label );
			$needs_review = true;
		}
		if ( ! $can_map_repeat ) {
			return [];
		}

		return $repeat;
	}

	/**
	 * Map choices with pricing.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @return array<int,array>
	 */
	private static function map_choices( array $wapf_field, array &$notes, bool &$needs_review, array $opf_ids_by_wapf_id, array $source_order_by_wapf_id, int $current_order ): array {
		$choices = [];
		foreach ( ( $wapf_field['options']['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}
			$slug  = (string) ( $choice['slug'] ?? '' );
			$ptype = (string) ( $choice['pricing_type'] ?? 'none' );
			$amt   = $choice['pricing_amount'] ?? 0;

			$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];

			switch ( $ptype ) {
				case 'fixed':
					// WAPF fixed = flat fee per line (qty_based is an opt-in).
					$pricing = [ 'type' => 'fixed', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => false ];
					break;
				case 'qt':
					// qt: amount*qty total → per-unit fixed.
					$pricing = [ 'type' => 'fixed', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => true ];
					break;
				case 'percent':
				case 'p':
					$pricing = [ 'type' => 'percent', 'amount' => (float) $amt, 'formula' => '' ];
					break;
				case 'fx':
					$formula_raw = self::map_formula_references( (string) $amt, $opf_ids_by_wapf_id, $notes, $needs_review, (string) ( $choice['label'] ?? $slug ), $source_order_by_wapf_id, $current_order );
					$formula = null === $formula_raw ? null : self::normalize_formula( $formula_raw );
					if ( null === $formula ) {
						$notes[] = sprintf( 'choice "%s" formula could not be translated: %s', $choice['label'] ?? $slug, (string) $amt );
						$needs_review = true;
						$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
					} else {
						// formula_raw keeps the legacy expression (incl. its qty
						// factor) for the theme's live-total display math.
						$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula, 'formula_raw' => $formula_raw, 'per_unit' => true ];
					}
					break;
				case 'none':
					break;
				default:
					$notes[] = sprintf( 'choice "%s" uses pricing type "%s" which is not supported; imported without pricing.', $choice['label'] ?? $slug, $ptype );
					$needs_review = true;
					break;
			}

			$mapped_choice = [
				'slug'     => $slug,
				'label'    => (string) ( $choice['label'] ?? '' ),
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => $pricing,
			];
			if ( 'image-swatch-qty' === ( $wapf_field['type'] ?? '' ) ) {
				$choice_options = is_array( $choice['options'] ?? null ) ? $choice['options'] : [];
				$minimum = 0;
				$maximum = 999999;
				$default = 0;
				foreach ( [ 'min', 'max', 'default' ] as $key ) {
					if ( ! array_key_exists( $key, $choice_options ) || '' === $choice_options[ $key ] || null === $choice_options[ $key ] ) {
						continue;
					}
					$value = $choice_options[ $key ];
					if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
						if ( 'min' === $key ) {
							$minimum = (int) $value;
						} elseif ( 'max' === $key ) {
							$maximum = (int) $value;
						} else {
							$default = (int) $value;
						}
					} else {
						$notes[] = sprintf( 'image quantity choice "%s" has an invalid %s setting; WAPF integer conversion needs review.', $choice['label'] ?? $slug, $key );
						$needs_review = true;
					}
				}
				if ( $minimum < 0 || $minimum > 999999 || $maximum < $minimum || $maximum > 999999 || $default < $minimum || $default > $maximum ) {
					$notes[] = sprintf( 'image quantity choice "%s" has bounds/default that OPF normalizes; verify the imported quantity behavior.', $choice['label'] ?? $slug );
					$needs_review = true;
				}
				$mapped_choice['quantity'] = [ 'default' => max( $minimum, min( $maximum, $default ) ), 'min' => max( 0, min( 999999, $minimum ) ), 'max' => max( max( 0, min( 999999, $minimum ) ), min( 999999, $maximum ) ) ];
				if ( isset( $choice_options['weight'] ) && '' !== $choice_options['weight'] && 0.0 !== (float) $choice_options['weight'] ) {
					$notes[] = sprintf( 'image quantity choice "%s" has WAPF weight metadata; OPF does not preserve choice-driven product weight.', $choice['label'] ?? $slug );
					$needs_review = true;
				}
			}
			if ( is_string( $choice['image'] ?? null ) ) {
				$mapped_choice['image'] = $choice['image'];
			}
			if ( is_string( $choice['color'] ?? null ) ) {
				if ( preg_match( '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{5})?$/', $choice['color'] ) ) {
					$mapped_choice['color'] = strtoupper( $choice['color'] );
				} else {
					$notes[] = sprintf( 'choice "%s" has an unsupported color value; color swatch needs review.', $choice['label'] ?? $slug );
					$needs_review = true;
				}
			} elseif ( in_array( $wapf_field['type'] ?? '', [ 'color-swatch', 'multi-color-swatch' ], true ) ) {
				$notes[] = sprintf( 'choice "%s" has no color value; color swatch needs review.', $choice['label'] ?? $slug );
				$needs_review = true;
			}
			$attachment_id = $choice['attachment'] ?? null;
			if ( ( is_int( $attachment_id ) || ( is_string( $attachment_id ) && ctype_digit( $attachment_id ) ) ) && (int) $attachment_id > 0 ) {
				$mapped_choice['image_id'] = (int) $attachment_id;
			}
			$choices[] = $mapped_choice;
		}
		return $choices;
	}

	/** Map the installed WAPF Extended image-swatch display settings. */
	private static function map_image_swatch_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$allowed = [
			'label_pos' => [ 'default', 'out', 'hide', 'tooltip' ],
			'grid_layout' => [ 'fixed', 'flexible' ],
		];
		foreach ( $allowed as $key => $values ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}
			if ( is_string( $options[ $key ] ) && in_array( $options[ $key ], $values, true ) ) {
				$settings[ $key ] = $options[ $key ];
			} else {
				$notes[] = sprintf( 'image swatch "%s" has an unsupported %s setting; WAPF default applies.', $label, $key );
				$needs_review = true;
			}
		}
		$integer_settings = [
			'item_width' => [ 20, 300 ],
			'items_per_row' => [ 1, 15 ],
			'items_per_row_tablet' => [ 1, 10 ],
			'items_per_row_mobile' => [ 1, 10 ],
		];
		foreach ( $integer_settings as $key => $range ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}
			$value = $options[ $key ];
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= $range[0] && (int) $value <= $range[1] ) {
				$settings[ $key ] = (int) $value;
			} else {
				$notes[] = sprintf( 'image swatch "%s" has an invalid %s setting; WAPF default applies.', $label, $key );
				$needs_review = true;
			}
		}
		if ( array_key_exists( 'large_image', $options ) ) {
			$value = $options['large_image'];
			if ( in_array( $value, [ true, false, 0, 1, '0', '1' ], true ) ) {
				$settings['image_zoom'] = in_array( $value, [ true, 1, '1' ], true );
			} else {
				$notes[] = sprintf( 'image swatch "%s" has an invalid large_image setting; zoom setting needs review.', $label );
				$needs_review = true;
			}
		}
		return $settings;
	}

	/** Map WAPF Extended color swatch layout, size, and selection limits. */
	private static function map_color_swatch_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		if ( isset( $options['layout'] ) ) {
			if ( in_array( $options['layout'], [ 'square', 'rounded', 'circle' ], true ) ) {
				$settings['color_layout'] = $options['layout'];
			} else {
				$notes[] = sprintf( 'color swatch "%s" has an unsupported layout; WAPF default applies.', $label );
				$needs_review = true;
			}
		}
		if ( isset( $options['size'] ) ) {
			$value = $options['size'];
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 5 && (int) $value <= 500 ) {
				$settings['color_size'] = (int) $value;
			} else {
				$notes[] = sprintf( 'color swatch "%s" has an invalid size; WAPF default applies.', $label );
				$needs_review = true;
			}
		}
		if ( isset( $options['label_pos'] ) ) {
			if ( in_array( $options['label_pos'], [ 'default', 'hide', 'tooltip' ], true ) ) {
				$settings['color_label_pos'] = $options['label_pos'];
			} else {
				$notes[] = sprintf( 'color swatch "%s" has an unsupported label position; WAPF default applies.', $label );
				$needs_review = true;
			}
		}
		return $settings;
	}

	/** Map cardinality options shared by WAPF's three multi-swatch types. */
	private static function map_swatch_selection_limits( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
			if ( ! array_key_exists( $key, $options ) || '' === $options[ $key ] || null === $options[ $key ] ) {
				continue;
			}
			$value = $options[ $key ];
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 1 && (int) $value <= 10000 ) {
				$settings[ $key ] = (int) $value;
			} else {
				$notes[] = sprintf( 'multi swatch "%s" has an invalid %s value; selection limit needs review.', $label, $key );
				$needs_review = true;
			}
		}
		if ( isset( $settings['min_choices'], $settings['max_choices'] ) && $settings['min_choices'] > $settings['max_choices'] ) {
			$notes[] = sprintf( 'multi swatch "%s" has min_choices greater than max_choices; selection limits need review.', $label );
			unset( $settings['min_choices'], $settings['max_choices'] );
			$needs_review = true;
		}
		return $settings;
	}

	/**
	 * Map WAPF image quantity aggregate limits without changing per-choice bounds.
	 *
	 * @param array<string,mixed> $wapf_field Source field.
	 * @param string[]            $notes      Import notes.
	 * @param bool                $needs_review Review flag.
	 * @return array<string,int>
	 */
	private static function map_image_quantity_limits( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
			if ( ! array_key_exists( $key, $options ) || '' === $options[ $key ] || null === $options[ $key ] ) {
				continue;
			}
			$value = $options[ $key ];
			if ( ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) || (int) $value < 0 || (int) $value > 999999 ) {
				$notes[] = sprintf( 'image quantity field "%s" has an invalid %s setting; the aggregate limit was not imported.', $label, $key );
				$needs_review = true;
				continue;
			}
			$settings[ $key ] = (int) $value;
		}
		if ( isset( $settings['min_choices'], $settings['max_choices'] ) && $settings['min_choices'] > $settings['max_choices'] ) {
			$notes[] = sprintf( 'image quantity field "%s" has min_choices greater than max_choices; aggregate limits need review.', $label );
			$needs_review = true;
			unset( $settings['min_choices'], $settings['max_choices'] );
		}
		return $settings;
	}

	/**
	 * WAPF formula → OPF formula.
	 *
	 * WAPF normalized per-unit results by dividing by quantity, so formulas
	 * in the wild end with "* [qty]" to compensate. OPF is per-unit, so a
	 * trailing quantity multiplication is stripped. Variables map 1:1
	 * ([price], [options_total]→[addons], [qty], [val]); source field IDs
	 * are remapped to the destination IDs before the expression is stored.
	 */
	public static function normalize_formula( string $formula ): ?string {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return null;
		}
		$formula = str_replace( '[options_total]', '[addons]', $formula );
		// Strip compensating quantity factor (repeat, e.g. "* [qty]" or "[qty]*").
		$changed = true;
		while ( $changed ) {
			$changed = false;
			if ( preg_match( '/\*\s*\[\s*qty\s*\]\s*$/i', $formula ) ) {
				$formula = substr( $formula, 0, strrpos( $formula, '*' ) );
				$formula = trim( $formula );
				$changed = true;
			} elseif ( preg_match( '/^\[\s*qty\s*\]\s*\*\s*/i', $formula ) ) {
				$formula = preg_replace( '/^\[\s*qty\s*\]\s*\*\s*/i', '', $formula );
				$formula = trim( (string) $formula );
				$changed = true;
			}
		}
		if ( '' === $formula ) {
			return null;
		}
		// Validate supported arithmetic after replacing known dynamic inputs with
		// numeric probes; the runtime still evaluates the saved expression safely.
		$probe = str_replace( [ '[price]', '[qty]', '[addons]', '[val]' ], '1', $formula );
		$probe = preg_replace( '/\[(?:field|price)\.[a-zA-Z0-9_-]+\]/i', '1', $probe );
		$probe = preg_replace( '/\b(?:checked|files|sumQty)\s*\(\s*[a-zA-Z0-9_-]+\s*\)/i', '1', $probe );
		if ( ! self::is_math_formula_probe( $probe ) ) {
			return null;
		}
		return $formula;
	}

	/**
	 * Allow documented numeric functions while keeping other calls and text out.
	 * Arguments may be nested arithmetic; separators belong to function calls.
	 * Runtime evaluation remains the sandboxed Calculator's responsibility.
	 */
	private static function is_math_formula_probe( string $probe ): bool {
		$frames = [];
		$length = strlen( $probe );
		for ( $offset = 0; $offset < $length; $offset++ ) {
			$char = $probe[ $offset ];
			if ( preg_match( '/[a-z_]/i', $char ) ) {
				if ( $offset > 0 && preg_match( '/[a-z0-9_.]/i', $probe[ $offset - 1 ] ) ) {
					return false;
				}
				// Installed WAPF Extended 3.1.5 extend/formulas.php and the
				// public formula-functions-reference define these numeric calls.
				if ( ! preg_match( '/^(?:min|max|round|abs|floor|ceil|sqrt|pow|sin|cos|tan)\s*\(/i', substr( $probe, $offset ), $match ) ) {
					return false;
				}
				$frames[] = true;
				$offset += strlen( $match[0] ) - 1;
			} elseif ( '(' === $char ) {
				$frames[] = false;
			} elseif ( ')' === $char ) {
				if ( ! $frames ) {
					return false;
				}
				array_pop( $frames );
			} elseif ( ';' === $char || ',' === $char ) {
				if ( ! $frames || true !== end( $frames ) ) {
					return false;
				}
			} elseif ( ! preg_match( '/[0-9+\-*\/.\s]/', $char ) ) {
				return false;
			}
		}
		return ! $frames;
	}

	/**
	 * Field-level pricing (text-like fields).
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_field_pricing( array $wapf_field, array &$notes, bool &$needs_review, array $opf_ids_by_wapf_id, array $source_order_by_wapf_id, int $current_order ): array {
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$pricing = $wapf_field['pricing'] ?? [];
		if ( ! is_array( $pricing ) || empty( $pricing['enabled'] ) ) {
			return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
		}
		$type = (string) ( $pricing['type'] ?? 'none' );
		$amt  = (float) ( $pricing['amount'] ?? 0 );
		switch ( $type ) {
			case 'fixed':
			case 'qt':
				return [ 'type' => 'fixed', 'amount' => $amt, 'formula' => '' ];
			case 'percent':
			case 'p':
				return [ 'type' => 'percent', 'amount' => $amt, 'formula' => '' ];
			case 'fx':
				$formula_raw = self::map_formula_references( (string) ( $pricing['amount'] ?? '' ), $opf_ids_by_wapf_id, $notes, $needs_review, $label, $source_order_by_wapf_id, $current_order );
				$formula = null === $formula_raw ? null : self::normalize_formula( $formula_raw );
				if ( null !== $formula ) {
					return [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula ];
				}
				$notes[] = sprintf( 'field "%s" formula could not be translated: %s', $label, (string) ( $pricing['amount'] ?? '' ) );
				$needs_review = true;
				return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
		}
		$notes[] = sprintf( 'field "%s" uses pricing type "%s" which is not supported.', (string) ( $wapf_field['label'] ?? '?' ), $type );
		$needs_review = true;
		return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
	}

	/** Remap field IDs in WAPF formula variables without changing unrelated text. */
	private static function map_formula_references( string $formula, array $opf_ids_by_wapf_id, array &$notes, bool &$needs_review, string $label, array $source_order_by_wapf_id, int $current_order ): ?string {
		$unmapped = [];
		$review_references = [];
		$formula = preg_replace_callback(
			'/\[(field|price)\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $opf_ids_by_wapf_id, $source_order_by_wapf_id, $current_order, &$unmapped, &$review_references ): string {
				$source_id = $match[2];
				if ( ! isset( $opf_ids_by_wapf_id[ $source_id ] ) || ! is_string( $opf_ids_by_wapf_id[ $source_id ] ) ) {
					$unmapped[] = $source_id;
					return $match[0];
				}
				if ( 'price' === strtolower( $match[1] ) ) {
					if ( ! isset( $source_order_by_wapf_id[ $source_id ] ) || $source_order_by_wapf_id[ $source_id ] >= $current_order ) {
						$review_references[] = '[price.' . $opf_ids_by_wapf_id[ $source_id ] . ']';
					}
				}
				return '[' . strtolower( $match[1] ) . '.' . $opf_ids_by_wapf_id[ $source_id ] . ']';
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/\b(checked|files|sumQty)\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $opf_ids_by_wapf_id, &$unmapped, &$review_references ): string {
				$source_id = $match[2];
				if ( ! isset( $opf_ids_by_wapf_id[ $source_id ] ) || ! is_string( $opf_ids_by_wapf_id[ $source_id ] ) ) {
					$unmapped[] = $source_id;
					return $match[0];
				}
				if ( ! in_array( strtolower( $match[1] ), [ 'checked' ], true ) ) {
					$review_references[] = strtolower( $match[1] ) . '(' . $opf_ids_by_wapf_id[ $source_id ] . ')';
				}
				return $match[1] . '(' . $opf_ids_by_wapf_id[ $source_id ] . ')';
			},
			$formula
		);
		if ( $unmapped ) {
			$unmapped = array_values( array_unique( $unmapped ) );
			$notes[] = sprintf( 'field "%s" formula references unavailable or ambiguous WAPF field IDs (%s); pricing needs review.', $label, implode( ', ', $unmapped ) );
			$needs_review = true;
			return null;
		}
		if ( $review_references ) {
			$review_references = array_values( array_unique( $review_references ) );
			$notes[] = sprintf( 'field "%s" formula contains references whose runtime behavior is not implemented yet (%s); pricing needs review.', $label, implode( ', ', $review_references ) );
			$needs_review = true;
		}
		return is_string( $formula ) ? $formula : null;
	}

	/**
	 * Field conditionals.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @param array<string,bool>  $seen_ids   Known field ids (incl. later ones skipped below).
	 * @return array<int,array>
	 */
	private static function map_conditionals( array $wapf_field, array &$notes, array $opf_ids_by_wapf_id, bool &$needs_review ): array {
		$out = [];
		$conditionals = $wapf_field['conditionals'] ?? [];
		if ( ! is_array( $conditionals ) ) {
			$notes[] = sprintf( 'field "%s" has malformed conditional data.', (string) ( $wapf_field['label'] ?? '?' ) );
			$needs_review = true;
			return $out;
		}
		foreach ( $conditionals as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				$notes[] = sprintf( 'field "%s" has a malformed conditional block.', (string) ( $wapf_field['label'] ?? '?' ) );
				$needs_review = true;
				continue;
			}
			$rules = [];
			$source_rules = $conditional['rules'] ?? [];
			if ( ! is_array( $source_rules ) ) {
				$notes[] = sprintf( 'field "%s" has a malformed conditional rule list.', (string) ( $wapf_field['label'] ?? '?' ) );
				$needs_review = true;
				continue;
			}
			foreach ( $source_rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					$notes[] = sprintf( 'field "%s" has a malformed conditional rule.', (string) ( $wapf_field['label'] ?? '?' ) );
					$needs_review = true;
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				$operator  = self::CONDITION_MAP[ $condition ] ?? null;
				$source_field_id = is_scalar( $rule['field'] ?? $rule['subject'] ?? null ) ? (string) ( $rule['field'] ?? $rule['subject'] ) : '';
				$subject = isset( $opf_ids_by_wapf_id[ $source_field_id ] ) && is_string( $opf_ids_by_wapf_id[ $source_field_id ] )
					? $opf_ids_by_wapf_id[ $source_field_id ]
					: '';
				if ( 'check' === $condition ) {
					$operator = 'is';
				} elseif ( '!check' === $condition ) {
					$operator = 'is_not';
				}
				if ( null === $operator || '' === $subject ) {
					$notes[] = '' === $subject
						? sprintf( 'conditional rule references unavailable or ambiguous field ID "%s".', $source_field_id )
						: sprintf( 'conditional rule with condition "%s" dropped.', $condition );
					$needs_review = true;
					continue;
				}
				$value = in_array( $condition, [ 'check', '!check' ], true ) ? '1' : ( $rule['value'] ?? '' );
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
				$rules[] = [
					'field'    => $subject,
					'operator' => $operator,
					'value'    => (string) $value,
				];
			}
			if ( $rules ) {
				$out[] = [
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => $rules,
				];
			}
		}
		return $out;
	}

	/**
	 * Placement rule groups.
	 *
	 * @param array<string,mixed> $wapf      WAPF group.
	 * @param array<string,mixed> $overrides attach_product_ids.
	 * @param string[]            $notes     Collector.
	 * @param bool                $needs_review Flag ref.
	 * @return array<int,array>
	 */
	private static function map_placement( array $wapf, array $overrides, array &$notes, bool &$needs_review ): array {
		if ( ! empty( $overrides['attach_product_ids'] ) ) {
			$product_rule = [
				'subject'  => 'product',
				'operator' => 'in',
				'terms'    => array_map( 'strval', (array) $overrides['attach_product_ids'] ),
			];
			$source_groups = $wapf['rule_groups'] ?? [];
			if ( ! $source_groups ) {
				return [ [ 'rules' => [ $product_rule ] ] ];
			}
			$attached_groups = [];
			foreach ( $source_groups as $source_group ) {
				$has_user_condition = false;
				$user_rules = [];
				foreach ( ( $source_group['rules'] ?? [] ) as $source_rule ) {
					if ( ! is_array( $source_rule ) ) {
						continue;
					}
					$condition = ltrim( (string) ( $source_rule['condition'] ?? '' ), '!' );
					if ( ! in_array( $condition, [ 'auth', 'role', 'lang' ], true ) ) {
						continue;
					}
					$has_user_condition = true;
					$mapped = self::map_user_placement_rule( $source_rule, $notes, $needs_review );
					if ( null !== $mapped ) {
						$user_rules[] = $mapped;
					}
				}
				// A source OR group with no user restriction makes the local group
				// available to every visitor; preserve that by returning host-only.
				if ( ! $has_user_condition ) {
					return [ [ 'rules' => [ $product_rule ] ] ];
				}
				$attached_groups[] = [ 'rules' => array_merge( [ $product_rule ], $user_rules ) ];
			}
			return $attached_groups ?: [ [ 'rules' => [ $product_rule ] ] ];
		}

		$out = [];
		foreach ( ( $wapf['rule_groups'] ?? [] ) as $rule_group ) {
			$rules = [];
			foreach ( ( $rule_group['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				$subject   = (string) ( $rule['subject'] ?? '' );
				$value     = $rule['value'] ?? null;
				$cond = ltrim( $condition, '!' );
				if ( in_array( $cond, [ 'auth', 'role', 'lang' ], true ) ) {
					$mapped = self::map_user_placement_rule( $rule, $notes, $needs_review );
					if ( null !== $mapped ) {
						$rules[] = $mapped;
					}
					continue;
				}
				// WAPF evaluated empty conditions as FALSE (dead rule). Flag,
				// don't silently broaden scope.
				if ( '' === $condition ) {
					$notes[]      = 'group had a WAPF rule with empty condition which WAPF evaluated as never-matching; imported as review-needed.';
					$needs_review = true;
					continue;
				}

				$negate = isset( $condition[0] ) && '!' === $condition[0];
				$cond   = ltrim( $condition, '!' );

				$map = [
					'product'      => 'product',
					'products'     => 'product',
					'product_cat'  => 'product_cat',
					'product_cats' => 'product_cat',
					'p_tags'       => 'product_tag',
					'product_tag'  => 'product_tag',
				];
				if ( ! isset( $map[ $cond ] ) ) {
					$notes[]      = sprintf( 'placement condition "%s" has no OPF equivalent; rule dropped.', $condition );
					$needs_review = true;
					continue;
				}

				$terms = [];
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						if ( is_array( $v ) && isset( $v['id'] ) ) {
							$terms[] = (string) $v['id'];
						} elseif ( is_scalar( $v ) ) {
							$terms[] = (string) $v;
						}
					}
				} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
					$terms[] = (string) $value;
				}
				$rules[] = [
					'subject'  => $map[ $cond ],
					'operator' => ( $negate ? 'not_in' : 'in' ),
					'terms'    => $terms,
				];
			}
			if ( $rules ) {
				$out[] = [ 'rules' => $rules ];
			}
		}
		return $out;
	}

	/**
	 * Convert one WAPF user-context group rule without losing its target.
	 *
	 * @param array<string,mixed> $rule WAPF placement rule.
	 * @param string[]            $notes Collector.
	 */
	private static function map_user_placement_rule( array $rule, array &$notes, bool &$needs_review ): ?array {
		$condition = (string) ( $rule['condition'] ?? '' );
		$negate = isset( $condition[0] ) && '!' === $condition[0];
		$cond = ltrim( $condition, '!' );
		$value = $rule['value'] ?? null;
		if ( 'auth' === $cond ) {
			if ( ! empty( $value ) ) {
				$notes[] = sprintf( 'login visibility rule "%s" unexpectedly has a value and needs review.', $condition );
				$needs_review = true;
				return null;
			}
			return [ 'subject' => 'user_auth', 'operator' => $negate ? 'not_in' : 'in', 'terms' => [ 'logged_in' ] ];
		}
		$terms = [];
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$terms[] = (string) $entry['id'];
				} elseif ( is_scalar( $entry ) ) {
					$terms[] = (string) $entry;
				}
			}
		} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
			$terms[] = (string) $value;
		}
		if ( ! in_array( $cond, [ 'role', 'lang' ], true ) || 1 !== count( $terms ) ) {
			$notes[] = sprintf( 'placement condition "%s" must have exactly one selected value; rule dropped.', $condition );
			$needs_review = true;
			return null;
		}
		return [
			'subject'  => 'role' === $cond ? 'user_role' : 'user_language',
			'operator' => $negate ? 'not_in' : 'in',
			'terms'    => $terms,
		];
	}

	/**
	 * Derive a stable, human-readable field id.
	 *
	 * @param string             $label    Field label.
	 * @param string             $fallback WAPF hex id.
	 * @param array<string,bool> $seen     Already-used ids.
	 */
	private static function field_id( string $label, string $fallback, array $seen ): string {
		$slug = self::slugify( $label );
		if ( '' === $slug ) {
			$slug = 'field';
		}
		$candidate = $slug;
		$i         = 2;
		while ( isset( $seen[ $candidate ] ) ) {
			$candidate = $slug . '-' . $i;
			$i++;
		}
		return $candidate;
	}

	/**
	 * WP-independent slugify (mirrors sanitize_title for latin/extended-latin).
	 *
	 * @param string $text Text to slugify.
	 */
	public static function slugify( string $text ): string {
		$text = mb_strtolower( $text, 'UTF-8' );
		$translit = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
		if ( false !== $translit && '' !== trim( $translit ) ) {
			$text = strtolower( $translit );
		}
		$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
		$text = trim( (string) $text, '-' );
		return substr( $text, 0, 40 );
	}
}
