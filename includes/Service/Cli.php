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
	 * Import one JSON payload copied from WAPF Tools.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a WAPF Tools JSON payload (maximum 5 MiB).
	 *
	 * --title=<title>
	 * : Title for the new OPF draft.
	 *
	 * [--product=<id>]
	 * : Attach the draft to one existing WooCommerce product.
	 *
	 * [--commit]
	 * : Create a draft. Without this flag, only report the mapping.
	 *
	 * [--format=<format>]
	 * : Output format: table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf import-wapf-json /tmp/wapf-fields.json --title="Imported fields"
	 *     wp opf import-wapf-json /tmp/wapf-fields.json --title="Imported fields" --product=123 --commit
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import_wapf_json( array $args, array $assoc_args = [] ): void {
		if ( 1 !== count( $args ) ) {
			\WP_CLI::error( 'Provide exactly one WAPF JSON file path.' );
		}
		if ( empty( $assoc_args['title'] ) ) {
			\WP_CLI::error( 'Provide --title for the new OPF draft.' );
		}
		$product_id = 0;
		if ( isset( $assoc_args['product'] ) ) {
			$product_id = filter_var( $assoc_args['product'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
			if ( false === $product_id ) {
				\WP_CLI::error( '--product must be a positive integer.' );
			}
		}
		try {
			$prepared = WapfJsonFileImporter::inspect_file( $args[0] );
			$report = WapfJsonFileImporter::run( $prepared, $assoc_args['title'], isset( $assoc_args['commit'] ), (int) $product_id );
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI::line( sprintf( 'Result: %s', $report['result'] ) );
			\WP_CLI::line( sprintf( 'Fields: %d', $report['fields'] ?? 0 ) );
			\WP_CLI::line( 'Status: draft; review required before publishing.' );
			foreach ( $report['notes'] as $note ) {
				\WP_CLI::line( '  Review: ' . $note );
			}
		}
		\WP_CLI::success( 'WAPF JSON import inspection complete.' );
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
}
