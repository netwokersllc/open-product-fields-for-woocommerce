<?php
/**
 * Import one WAPF Tools JSON payload as a reviewable OPF draft.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;

defined( 'ABSPATH' ) || exit;

final class WapfJsonFileImporter {
	private const MAX_FILE_BYTES = 5242880;
	private const MAX_FIELDS = 500;

	/** Read and validate a WAPF Tools JSON file. */
	public static function inspect_file( string $path ): array {
		$real_path = realpath( $path );
		if ( false === $real_path || ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			throw new \InvalidArgumentException( 'WAPF JSON file must be a readable regular file.' );
		}
		$size = filesize( $real_path );
		if ( false === $size || $size < 2 || $size > self::MAX_FILE_BYTES ) {
			throw new \InvalidArgumentException( 'WAPF JSON file must be between 2 bytes and 5 MiB.' );
		}
		$json = file_get_contents( $real_path );
		if ( ! is_string( $json ) || strlen( $json ) !== $size ) {
			throw new \RuntimeException( 'Could not read the complete WAPF JSON file.' );
		}
		try {
			$decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new \InvalidArgumentException( 'WAPF JSON file is malformed.', 0, $exception );
		}
		if ( ! is_array( $decoded ) ) {
			throw new \InvalidArgumentException( 'WAPF JSON must contain one object payload.' );
		}
		return self::prepare_payload( $decoded );
	}

	/**
	 * Normalize the four-part Tools payload to the WAPF shape understood by WapfMapper.
	 *
	 * @param array<string,mixed> $raw Exported fields, conditions, layout, and variables.
	 * @return array{payload:array<string,mixed>,notes:string[]}
	 */
	public static function prepare_payload( array $raw ): array {
		if ( ! isset( $raw['fields'] ) || ! is_array( $raw['fields'] ) || ! $raw['fields'] ) {
			throw new \InvalidArgumentException( 'WAPF JSON must contain a non-empty fields list.' );
		}
		if ( count( $raw['fields'] ) > self::MAX_FIELDS ) {
			throw new \InvalidArgumentException( 'WAPF JSON exceeds the 500-field import limit.' );
		}
		if ( isset( $raw['layout'] ) && ! is_array( $raw['layout'] ) ) {
			throw new \InvalidArgumentException( 'WAPF layout must be an object.' );
		}
		if ( isset( $raw['variables'] ) && ! is_array( $raw['variables'] ) ) {
			throw new \InvalidArgumentException( 'WAPF variables must be an object.' );
		}
		if ( isset( $raw['conditions'] ) && ! is_array( $raw['conditions'] ) ) {
			throw new \InvalidArgumentException( 'WAPF conditions must be an array.' );
		}

		$notes = [ 'WAPF Tools JSON omits the source group ID and title; verify mapped placement and choose a title before publishing.' ];
		$conditions = [];
		foreach ( $raw['conditions'] ?? [] as $group_index => $condition_group ) {
			if ( ! is_array( $condition_group ) || ! isset( $condition_group['rules'] ) || ! is_array( $condition_group['rules'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF placement group at index %d must contain a rules array.', (int) $group_index ) );
			}
			$rules = [];
			foreach ( $condition_group['rules'] as $rule_index => $rule ) {
				if ( ! is_array( $rule ) ) {
					throw new \InvalidArgumentException( sprintf( 'WAPF placement rule %d in group %d must be an object.', (int) $rule_index, (int) $group_index ) );
				}
				foreach ( [ 'condition', 'subject' ] as $key ) {
					if ( isset( $rule[ $key ] ) && ! is_scalar( $rule[ $key ] ) ) {
						throw new \InvalidArgumentException( sprintf( 'WAPF placement rule %d in group %d has an invalid %s value.', (int) $rule_index, (int) $group_index, $key ) );
					}
				}
				$rules[] = $rule;
			}
			if ( $rules ) {
				$condition_group['rules'] = $rules;
				$conditions[] = $condition_group;
			}
		}
		$fields = [];
		$structural = [ 'id', 'label', 'description', 'type', 'required', 'width', 'class', 'conditionals', 'pricing', 'clone', 'options' ];
		foreach ( $raw['fields'] as $index => $field ) {
			if ( ! is_array( $field ) ) {
				$notes[] = sprintf( 'WAPF field at index %d is malformed and was omitted.', (int) $index );
				continue;
			}
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : [];
			foreach ( $field as $key => $value ) {
				if ( ! is_string( $key ) || in_array( $key, $structural, true ) ) {
					continue;
				}
				$options[ $key ] = $value;
				if ( ! in_array( $key, [ 'placeholder', 'choices' ], true ) ) {
					$safe_key = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\\-]/', '', $key ) );
					$notes[] = sprintf( 'WAPF field option "%s" needs review; its value is not mapped by the current importer.', substr( $safe_key, 0, 80 ) );
				}
			}
			$field['options'] = $options;
			$fields[] = $field;
		}
		if ( ! $fields ) {
			throw new \InvalidArgumentException( 'WAPF JSON contains no usable field entries.' );
		}
		if ( ! empty( $raw['variables'] ) ) {
			$notes[] = 'WAPF variable definitions need review; their values and condition scope are not mapped by the current importer.';
		}

		return [
			'payload' => [
				'type' => 'wapf_product',
				'fields' => $fields,
				'layout' => is_array( $raw['layout'] ?? null ) ? $raw['layout'] : [],
				'rule_groups' => $conditions,
				'variables' => is_array( $raw['variables'] ?? null ) ? $raw['variables'] : [],
			],
			'notes' => array_values( array_unique( $notes ) ),
		];
	}

	/** Dry-run or create a reviewable draft group from an inspected payload. */
	public static function run( array $prepared, string $title, bool $commit = false, int $product_id = 0 ): array {
		$title = sanitize_text_field( $title );
		if ( '' === $title || strlen( $title ) > 200 ) {
			throw new \InvalidArgumentException( 'Provide a title between 1 and 200 characters.' );
		}
		$payload = is_array( $prepared['payload'] ?? null ) ? $prepared['payload'] : [];
		$notes = is_array( $prepared['notes'] ?? null ) ? array_values( array_filter( $prepared['notes'], 'is_string' ) ) : [];
		$overrides = [];
		if ( $product_id > 0 ) {
			if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
				throw new \InvalidArgumentException( 'The requested WooCommerce product does not exist.' );
			}
			$overrides['attach_product_ids'] = [ $product_id ];
		}
		$mapped = WapfMapper::map( $payload, $overrides );
		$notes = array_values( array_unique( array_merge( $notes, $mapped['notes'] ) ) );
		$fingerprint_source = wp_json_encode( [ $payload, $title, $product_id ] );
		if ( ! is_string( $fingerprint_source ) ) {
			throw new \RuntimeException( 'Could not create an import fingerprint for this payload.' );
		}
		$key = 'wapf-json:' . hash( 'sha256', $fingerprint_source );
		$existing = get_posts( [
			'post_type' => 'opf_field_group',
			'post_status' => 'any',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => '_opf_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		if ( $existing ) {
			return [ 'result' => 'already-imported', 'opf_id' => (int) $existing[0], 'title' => $title, 'needs_review' => true, 'notes' => array_slice( $notes, 0, 20 ) ];
		}

		$opf_id = 0;
		if ( $commit ) {
			$opf_id = FieldGroups::save( 0, new FieldGroup( $mapped['group'] ), [ 'title' => $title, 'status' => 'draft' ] );
			if ( ! $opf_id ) {
				return [ 'result' => 'save-failed', 'title' => $title, 'needs_review' => true, 'notes' => array_slice( $notes, 0, 20 ) ];
			}
			update_post_meta( $opf_id, '_opf_imported_from', $key );
			update_post_meta( $opf_id, '_opf_needs_review', array_slice( $notes, 0, 20 ) );
		}
		return [
			'result' => $commit ? 'draft-created' : 'dry-run',
			'opf_id' => $opf_id,
			'title' => $title,
			'fields' => count( $mapped['group']['fields'] ),
			'needs_review' => true,
			'notes' => array_slice( $notes, 0, 20 ),
		];
	}
}
