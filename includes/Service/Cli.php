<?php
/**
 * WP-CLI commands.
 *
 *   wp opf import-wapf [--commit] [--format=json|table]
 *   wp opf report
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Register commands.
	 */
	public static function init(): void {
		\WP_CLI::add_command( 'opf', self::class );
	}

	/**
	 * Import WAPF field groups.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Write the imported groups. Without this flag the import is a dry run.
	 *
	 * [--format=<format>]
	 * : Output format: table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf import-wapf
	 *     wp opf import-wapf --commit --format=json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import_wapf( array $args, array $assoc_args = [] ): void {
		$commit = isset( $assoc_args['commit'] );
		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI::log( ( $commit ? 'Committing' : 'Dry-running' ) . ' WAPF → OPF import…' );

		$report = Importer::run( $commit );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI::line( sprintf( 'Imported: %d', $report['imported'] ) );
			\WP_CLI::line( sprintf( 'Skipped:  %d', $report['skipped'] ) );
			\WP_CLI::line( sprintf( 'Repaired: %d', $report['repaired'] ) );
			\WP_CLI::line( sprintf( 'Needs review: %d', count( $report['needs_review'] ) ) );
			foreach ( $report['groups'] as $group ) {
				$flag = ! empty( $group['needs_review'] ) ? ' [REVIEW]' : '';
				\WP_CLI::line( sprintf( '  %s → %s (%s)%s', $group['source'] ?? '?', $group['result'] ?? '?', $group['title'] ?? '', $flag ) );
			}
		}

		\WP_CLI::success( sprintf( '%s %d group(s).', $commit ? 'Imported' : 'Would import', $report['imported'] ) );
	}

	/**
	 * Import a versioned OPF archive.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON file created by `wp opf export`.
	 *
	 * [--commit]
	 * : Persist imported groups. Without this flag the command only reports the result.
	 *
	 * [--format=<format>]
	 * : Output format: table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf import-archive /tmp/opf-groups.json
	 *     wp opf import-archive /tmp/opf-groups.json --commit --format=json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import_archive( array $args, array $assoc_args = [] ): void {
		if ( 1 !== count( $args ) ) {
			\WP_CLI::error( 'Provide exactly one OPF archive JSON file.' );
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			\WP_CLI::error( 'Archive import format must be table or json.' );
		}
		$path = realpath( (string) $args[0] );
		if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( 'OPF archive must be a readable regular file.' );
		}
		$size = filesize( $path );
		if ( false === $size || $size > ArchiveImporter::MAX_BYTES ) {
			\WP_CLI::error( 'OPF archive exceeds the 5 MiB import limit.' );
		}
		$json = file_get_contents( $path );
		if ( false === $json ) {
			\WP_CLI::error( 'Could not read the OPF archive.' );
		}
		try {
			$package = ArchiveImporter::decode( $json );
			$report = ArchiveImporter::import( $package, isset( $assoc_args['commit'] ) );
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI::line( sprintf( 'Imported: %d', $report['imported'] ) );
			\WP_CLI::line( sprintf( 'Skipped: %d', $report['skipped'] ) );
			foreach ( $report['groups'] as $group ) {
				$review = ! empty( $group['needs_review'] ) ? ' [REVIEW → DRAFT]' : '';
				\WP_CLI::line( sprintf( '  #%d → %s%s', $group['source_id'] ?? 0, $group['result'] ?? '?', $review ) );
			}
		}
		\WP_CLI::success( sprintf( '%s %d group(s).', isset( $assoc_args['commit'] ) ? 'Imported' : 'Would import', $report['imported'] ) );
	}

	/**
	 * Summarize OPF field groups.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function report( array $args, array $assoc_args = [] ): void {
		$groups = FieldGroups::all();
		\WP_CLI::line( sprintf( '%d OPF field groups:', count( $groups ) ) );
		foreach ( $groups as $entry ) {
			\WP_CLI::line( sprintf( '  #%d %s (%d fields)', $entry['id'], $entry['title'], count( $entry['group']->data['fields'] ) ) );
		}
	}

	/**
	 * Export field groups as a versioned OPF archive.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Export all non-trashed field groups, including drafts.
	 *
	 * [--group=<id>]
	 * : Export one field group by post ID.
	 *
	 * [--product=<id>]
	 * : Export the published groups currently matched to a WooCommerce product.
	 *
	 * [--output=<file>]
	 * : Write atomically to an absolute file path. Without this option, print JSON to stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf export --all --output=/tmp/opf-groups.json
	 *     wp opf export --group=123
	 *     wp opf export --product=456 --output=/tmp/opf-product.json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function export( array $args, array $assoc_args = [] ): void {
		$selectors = array_values( array_filter( [
			array_key_exists( 'all', $assoc_args ) ? 'all' : null,
			array_key_exists( 'group', $assoc_args ) ? 'group' : null,
			array_key_exists( 'product', $assoc_args ) ? 'product' : null,
		] ) );
		if ( 1 !== count( $selectors ) ) {
			\WP_CLI::error( 'Choose exactly one selector: --all, --group=<id>, or --product=<id>.' );
		}
		$type = $selectors[0];
		$scope = [ 'type' => $type ];
		$posts = [];
		if ( 'all' === $type ) {
			$posts = get_posts( [
				'post_type' => 'opf_field_group',
				'post_status' => 'any',
				'posts_per_page' => ArchiveImporter::MAX_GROUPS + 1,
				'no_found_rows' => true,
				'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ],
				'suppress_filters' => false,
				'lang' => '',
			] );
		} elseif ( 'group' === $type ) {
			$id = self::positive_id( $assoc_args['group'] ?? '' );
			$post = get_post( $id );
			if ( ! $post || 'opf_field_group' !== $post->post_type || 'trash' === $post->post_status ) {
				\WP_CLI::error( 'The requested OPF field group does not exist.' );
			}
			$scope['id'] = $id;
			$posts = [ $post ];
		} else {
			$id = self::positive_id( $assoc_args['product'] ?? '' );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
			if ( ! $product ) {
				\WP_CLI::error( 'The requested WooCommerce product does not exist.' );
			}
			$scope['id'] = $id;
			foreach ( FieldGroups::for_product( $product ) as $entry ) {
				$post = get_post( (int) $entry['id'] );
				if ( $post && 'opf_field_group' === $post->post_type ) {
					$posts[] = $post;
				}
			}
		}
		if ( count( $posts ) > ArchiveImporter::MAX_GROUPS ) {
			\WP_CLI::error( 'OPF archive export is limited to 500 field groups.' );
		}

		$groups = [];
		foreach ( $posts as $post ) {
			$data = json_decode( (string) $post->post_content, true );
			if ( ! is_array( $data ) ) {
				\WP_CLI::error( sprintf( 'Field group #%d has invalid JSON; export stopped.', (int) $post->ID ) );
			}
			$groups[] = [
				'id' => (int) $post->ID,
				'title' => (string) $post->post_title,
				'status' => (string) $post->post_status,
				'menu_order' => (int) $post->menu_order,
				'language' => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( (int) $post->ID, 'slug' ) : '',
				'data' => $data,
			];
		}
		$package = Exporter::build_package( $groups, $scope );
		$json = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			\WP_CLI::error( 'Could not encode the OPF export package.' );
		}
		if ( strlen( $json ) > ArchiveImporter::MAX_BYTES ) {
			\WP_CLI::error( 'OPF archive export exceeds the 5 MiB transfer limit.' );
		}
		try {
			ArchiveImporter::decode( $json );
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( isset( $assoc_args['output'] ) ) {
			$path = (string) $assoc_args['output'];
			$directory = dirname( $path );
			if ( '/' !== substr( $path, 0, 1 ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
				\WP_CLI::error( 'Export path must be absolute and its parent directory must be writable.' );
			}
			$temp = tempnam( $directory, '.opf-export-' );
			if ( false === $temp || false === file_put_contents( $temp, $json . "\n", LOCK_EX ) || ! rename( $temp, $path ) ) {
				if ( is_string( $temp ) && file_exists( $temp ) ) {
					unlink( $temp );
				}
				\WP_CLI::error( 'Could not write the OPF export package.' );
			}
			\WP_CLI::success( sprintf( 'Exported %d field group(s) to %s.', count( $groups ), $path ) );
			return;
		}
		\WP_CLI::line( $json );
	}

	private static function positive_id( $value ): int {
		if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $value ) ) {
			\WP_CLI::error( 'The selected ID must be a positive integer.' );
		}
		return (int) $value;
	}
}
