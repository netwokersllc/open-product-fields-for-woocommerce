<?php
/**
 * WPML String Translation packages for OPF's JSON-backed field groups.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class WpmlIntegration {

	private const KIND = 'Open Product Fields';
	private const SLUG = 'open-product-fields';

	/** Wire after WordPress and WPML have loaded. No WPML dependency when absent. */
	public static function init(): void {
		add_filter( 'wpml_active_string_package_kinds', [ __CLASS__, 'package_kinds' ] );
		add_action( 'save_post_opf_field_group', [ __CLASS__, 'register_post' ], 20, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_post' ], 10, 2 );
		add_filter( 'opf_groups_for_product', [ __CLASS__, 'translate_groups' ] );
		add_action( 'wpml_switch_language', [ FieldGroups::class, 'flush_cache' ], 100 );
	}

	public static function package_kinds( array $kinds ): array {
		$kinds[ self::SLUG ] = [ 'title' => self::KIND, 'slug' => self::SLUG, 'plural' => self::KIND ];
		return $kinds;
	}

	private static function package( int $id, string $title = '' ): array {
		return [
			'kind' => self::KIND,
			'kind_slug' => self::SLUG,
			'name' => (string) $id,
			'title' => $title . ' (#' . $id . ')',
		];
	}

	/** Saving an existing group also makes pre-WPML groups available to translators. */
	public static function register_post( int $id, $post ): void {
		if ( ! is_object( $post ) || 'opf_field_group' !== ( $post->post_type ?? '' ) || 'auto-draft' === ( $post->post_status ?? '' ) ) {
			return;
		}
		$data = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $data ) || ! is_array( $data['fields'] ?? null ) ) {
			return;
		}
		$package = self::package( $id, (string) $post->post_title );
		do_action( 'wpml_start_string_package_registration', $package );
		self::map_text( $data, static function ( string $text, string $name, string $type ) use ( $package ): string {
			do_action( 'wpml_register_string', $text, $name, $package, $name, $type );
			return $text;
		} );
		do_action( 'wpml_delete_unused_package_strings', $package );
	}

	public static function delete_post( int $id, $post ): void {
		if ( is_object( $post ) && 'opf_field_group' === ( $post->post_type ?? '' ) ) {
			do_action( 'wpml_delete_package', (string) $id, self::SLUG );
		}
	}

	/**
	 * Translate runtime copies only. Never mutate stored JSON, exports or editor data.
	 * Product placement also covers variation parent IDs used by FieldGroups.
	 */
	public static function translate_groups( array $entries ): array {
		$language = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $language ) || '' === $language || 'all' === $language ) {
			return $entries;
		}
		foreach ( $entries as $key => $entry ) {
			if ( ! ( $entry['group'] ?? null ) instanceof FieldGroup ) {
				continue;
			}
			// Imports can contain separate groups for translated source products.
			// Until their language ownership is migrated, remapping all of them
			// would render duplicate options. Preserve these entries verbatim.
			if ( '' !== (string) get_post_meta( (int) $entry['id'], '_opf_imported_from', true ) || '' !== (string) get_post_meta( (int) $entry['id'], '_opf_archive_import_key', true ) ) {
				continue;
			}
			$package = self::package( (int) $entry['id'], (string) ( $entry['title'] ?? '' ) );
			$data = self::map_text( $entry['group']->data, static function ( string $text, string $name ) use ( $package ): string {
				// WPML's package filter uses its current language; do not switch it here.
				$value = apply_filters( 'wpml_translate_string', $text, $name, $package );
				return is_string( $value ) ? $value : $text;
			} );
			foreach ( $data['rule_groups'] ?? [] as $group_index => $rule_group ) {
				foreach ( $rule_group['rules'] ?? [] as $rule_index => $rule ) {
					$type = $rule['subject'] ?? '';
					if ( ! in_array( $type, [ 'product', 'product_cat', 'product_tag' ], true ) ) {
						continue;
					}
					foreach ( $rule['terms'] ?? [] as $term_index => $term ) {
						if ( ! ctype_digit( (string) $term ) || (int) $term < 1 ) {
							continue;
						}
						$translated = apply_filters( 'wpml_object_id', (int) $term, $type, true, $language );
						if ( is_numeric( $translated ) && (int) $translated > 0 ) {
							$data['rule_groups'][ $group_index ]['rules'][ $rule_index ]['terms'][ $term_index ] = (string) (int) $translated;
						}
					}
				}
			}
			// A translated label must never alter IDs, choice slugs or pricing data.
			$group = clone $entry['group'];
			$group->data = $data;
			$entries[ $key ]['group'] = $group;
		}
		return $entries;
	}

	/** Transform only display strings; names survive field/choice reordering. */
	public static function map_text( array $data, callable $map ): array {
		foreach ( $data['fields'] ?? [] as $index => $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['id'] ?? null ) ) {
				continue;
			}
			$prefix = 'field:' . rawurlencode( $field['id'] );
			foreach ( [ 'label', 'description', 'placeholder', 'content' ] as $key ) {
				if ( is_string( $field[ $key ] ?? null ) && '' !== $field[ $key ] ) {
					$type = in_array( $key, [ 'description', 'content' ], true ) ? 'AREA' : 'LINE';
					if ( 'content' === $key && 'html' === ( $field['content_format'] ?? '' ) ) {
						$type = 'VISUAL';
					}
					$data['fields'][ $index ][ $key ] = $map( $field[ $key ], $prefix . ':' . $key, $type );
				}
			}
			foreach ( $field['choices'] ?? [] as $choice_index => $choice ) {
				if ( is_string( $choice['label'] ?? null ) && '' !== $choice['label'] && is_string( $choice['slug'] ?? null ) ) {
					$data['fields'][ $index ]['choices'][ $choice_index ]['label'] = $map( $choice['label'], $prefix . ':choice:' . rawurlencode( $choice['slug'] ), 'LINE' );
				}
			}
			foreach ( [ 'add', 'del', 'label' ] as $key ) {
				if ( is_string( $field['repeat'][ $key ] ?? null ) && '' !== $field['repeat'][ $key ] ) {
					$data['fields'][ $index ]['repeat'][ $key ] = $map( $field['repeat'][ $key ], $prefix . ':repeat:' . $key, 'LINE' );
				}
			}
		}
		return $data;
	}
}
