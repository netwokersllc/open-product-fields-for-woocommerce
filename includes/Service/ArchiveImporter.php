<?php
/**
 * Import versioned OPF field-group archives.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class ArchiveImporter {

	public const MAX_BYTES = 5242880;
	public const MAX_GROUPS = 500;

	/** Decode and validate an OPF export before any database writes occur. */
	public static function decode( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'OPF archive exceeds the 5 MiB import limit.' );
		}
		try {
			$package = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new \InvalidArgumentException( 'OPF archive is not valid JSON.', 0, $exception );
		}
		if ( ! is_array( $package ) || 'opf-field-groups' !== ( $package['format'] ?? null ) || 1 !== ( $package['format_version'] ?? null ) ) {
			throw new \InvalidArgumentException( 'OPF archive format or version is unsupported.' );
		}
		if ( ! is_array( $package['scope'] ?? null ) ) {
			throw new \InvalidArgumentException( 'OPF archive scope metadata is malformed.' );
		}
		if ( ! is_array( $package['groups'] ?? null ) || ! self::is_list( $package['groups'] ) || count( $package['groups'] ) > self::MAX_GROUPS ) {
			throw new \InvalidArgumentException( 'OPF archive must contain at most 500 field groups.' );
		}

		$source_ids = [];
		foreach ( $package['groups'] as $index => &$entry ) {
			if ( ! is_array( $entry ) || ! self::valid_integer( $entry['source_id'] ?? null, false ) || (int) $entry['source_id'] < 1 || ! is_array( $entry['data'] ?? null ) ) {
				throw new \InvalidArgumentException( sprintf( 'OPF archive group %d is malformed.', $index + 1 ) );
			}
			$source_id = (int) $entry['source_id'];
			if ( isset( $source_ids[ $source_id ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'OPF archive contains duplicate source group ID %d.', $source_id ) );
			}
			$source_ids[ $source_id ] = true;
			try {
				$normalized = new FieldGroup( $entry['data'] );
			} catch ( \InvalidArgumentException $exception ) {
				throw new \InvalidArgumentException( sprintf( 'OPF archive group %d is invalid: %s', $index + 1, $exception->getMessage() ), 0, $exception );
			}
			if ( self::canonical_json( $entry['data'] ) !== self::canonical_json( $normalized->data ) ) {
				throw new \InvalidArgumentException( sprintf( 'OPF archive group %d contains fields or settings this OPF version cannot preserve.', $index + 1 ) );
			}
			if ( ! is_string( $entry['title'] ?? '' ) || ! is_string( $entry['language'] ?? '' ) || ! self::valid_integer( $entry['menu_order'] ?? 0, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'OPF archive group %d has malformed metadata.', $index + 1 ) );
			}
			if ( isset( $entry['warnings'] ) && ( ! is_array( $entry['warnings'] ) || count( array_filter( $entry['warnings'], 'is_string' ) ) !== count( $entry['warnings'] ) ) ) {
				throw new \InvalidArgumentException( sprintf( 'OPF archive group %d has malformed warnings.', $index + 1 ) );
			}
			$entry['source_id'] = $source_id;
			$source_status = $entry['status'] ?? '';
			if ( ! in_array( $source_status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
				$entry['warnings'][] = 'Source post status is unsupported and was changed to draft.';
				$source_status = 'draft';
			}
			$entry['status'] = $source_status;
			$entry['menu_order'] = (int) ( $entry['menu_order'] ?? 0 );
			$entry['warnings'] = is_array( $entry['warnings'] ?? null ) ? array_values( array_filter( $entry['warnings'], 'is_string' ) ) : [];
			$entry['data'] = $normalized->data;
		}
		unset( $entry );

		return $package;
	}

	/** Persist validated groups, defaulting portability-warning groups to drafts. */
	public static function import( array $package, bool $commit = false ): array {
		$json = wp_json_encode( $package );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'OPF archive could not be encoded for validation.' );
		}
		$package = self::decode( $json );
		$report = [ 'mode' => $commit ? 'commit' : 'dry-run', 'imported' => 0, 'skipped' => 0, 'groups' => [] ];
		$scope = self::canonical_json( $package['scope'] ?? [] );
		foreach ( $package['groups'] as $entry ) {
			$checksum = hash( 'sha256', self::canonical_json( [ $entry['title'], $entry['status'], $entry['menu_order'], $entry['language'], $entry['data'], $entry['warnings'] ] ) );
			$source_key = hash( 'sha256', $scope . ':' . $entry['source_id'] . ':' . $checksum );
			$existing = get_posts( [
				'post_type' => 'opf_field_group',
				'post_status' => 'any',
				'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_key' => '_opf_archive_import_key', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $source_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			] );
			if ( ! empty( $existing ) ) {
				$existing_id = (int) $existing[0];
				$report['skipped']++;
				$report['groups'][] = [ 'source_id' => $entry['source_id'], 'result' => 'already-imported', 'opf_id' => $existing_id ];
				continue;
			}

			$review_notes = $entry['warnings'];
			if ( self::has_site_local_targets( $entry['data'] ) ) {
				$review_notes[] = 'Site-local product, category, or tag targets may need remapping.';
			}
			if ( self::has_media_reference( $entry['data'] ) ) {
				$review_notes[] = 'Media files are not included in the archive.';
			}
			if ( '' !== $entry['language'] && ! function_exists( 'pll_set_post_language' ) ) {
				$review_notes[] = 'Language assignment could not be restored because Polylang is unavailable.';
			}
			$needs_review = ! empty( $review_notes );
			$status = $needs_review ? 'draft' : $entry['status'];
			$opf_id = 0;
			if ( $commit ) {
				$opf_id = FieldGroups::save( 0, new FieldGroup( $entry['data'] ), [
					'title' => $entry['title'],
					'status' => $status,
					'menu_order' => $entry['menu_order'],
				] );
				if ( ! $opf_id ) {
					$report['skipped']++;
					$report['groups'][] = [ 'source_id' => $entry['source_id'], 'result' => 'save-failed' ];
					continue;
				}
				update_post_meta( $opf_id, '_opf_archive_import_key', $source_key );
				update_post_meta( $opf_id, '_opf_archive_import_checksum', $checksum );
				if ( $needs_review ) {
					update_post_meta( $opf_id, '_opf_needs_review', array_slice( array_values( array_unique( $review_notes ) ), 0, 20 ) );
				}
				if ( '' !== $entry['language'] && function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $opf_id, $entry['language'] );
				}
			}
			$report['imported']++;
			$report['groups'][] = [ 'source_id' => $entry['source_id'], 'result' => 'imported', 'opf_id' => $opf_id, 'status' => $status, 'needs_review' => $needs_review ];
		}
		return $report;
	}

	private static function has_site_local_targets( array $data ): bool {
		foreach ( (array) ( $data['rule_groups'] ?? [] ) as $rule_group ) {
			foreach ( (array) ( $rule_group['rules'] ?? [] ) as $rule ) {
				if ( is_array( $rule ) && in_array( $rule['subject'] ?? '', [ 'product', 'product_cat', 'product_tag' ], true ) && ! empty( $rule['terms'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function has_media_reference( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, [ 'image', 'image_url' ], true ) && is_string( $item ) && '' !== $item ) {
				return true;
			}
			if ( 'image_id' === $key && is_numeric( $item ) && (int) $item > 0 ) {
				return true;
			}
			if ( self::has_media_reference( $item ) ) {
				return true;
			}
		}
		return false;
	}

	private static function valid_integer( $value, bool $allow_negative ): bool {
		if ( is_int( $value ) ) {
			return $allow_negative || $value > 0;
		}
		$pattern = $allow_negative ? '/^-?[0-9]+$/' : '/^[1-9][0-9]*$/';
		return is_string( $value ) && 1 === preg_match( $pattern, $value ) && false !== filter_var( $value, FILTER_VALIDATE_INT );
	}

	private static function is_list( array $value ): bool {
		$expected_key = 0;
		foreach ( $value as $key => $_ ) {
			if ( $expected_key !== $key ) {
				return false;
			}
			++$expected_key;
		}
		return true;
	}

	private static function canonical_json( $value ): string {
		$value = self::sort_keys( $value );
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_keys( $item );
		}
		return $value;
	}
}
