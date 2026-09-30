<?php
/** Admin interface for imported formula lookup tables. */

namespace OPF\Service\Admin;

use OPF\Engine\LookupTableCsv;
use OPF\Engine\LookupTables as LookupTableStore;

defined( 'ABSPATH' ) || exit;

final class LookupTables {
	private const MAX_UPLOAD_BYTES = 10485760;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ], 60 );
		add_action( 'admin_post_opf_lookup_import', [ __CLASS__, 'import' ] );
		add_action( 'admin_post_opf_lookup_delete', [ __CLASS__, 'delete' ] );
	}

	public static function register_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Lookup tables', 'open-product-fields-for-woocommerce' ),
			__( 'Lookup tables', 'open-product-fields-for-woocommerce' ),
			'manage_woocommerce',
			'opf-lookup-tables',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage lookup tables.', 'open-product-fields-for-woocommerce' ) );
		}
		$notice = isset( $_GET['opf_lookup_notice'] ) ? sanitize_key( wp_unslash( $_GET['opf_lookup_notice'] ) ) : '';
		$messages = [
			'imported' => __( 'Lookup table imported or replaced.', 'open-product-fields-for-woocommerce' ),
			'deleted'  => __( 'Lookup table deleted.', 'open-product-fields-for-woocommerce' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Open Product Fields lookup tables', 'open-product-fields-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Upload spreadsheet-exported CSV files. Two-field grids use the first row for the first formula dimension and column A for the second. Three-or-more-field lists put one field per column and price in the last column.', 'open-product-fields-for-woocommerce' ); ?></p>
			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="opf_lookup_import" />
				<?php wp_nonce_field( 'opf_lookup_import', 'opf_lookup_nonce' ); ?>
				<p><label for="opf-lookup-csv"><strong><?php esc_html_e( 'CSV file', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
				<p><input id="opf-lookup-csv" type="file" name="opf_lookup_csv" accept=".csv,text/csv" required /></p>
				<p><label for="opf-lookup-format"><strong><?php esc_html_e( 'CSV layout', 'open-product-fields-for-woocommerce' ); ?></strong></label></p>
				<p><select id="opf-lookup-format" name="opf_lookup_format"><option value="auto"><?php esc_html_e( 'Detect automatically', 'open-product-fields-for-woocommerce' ); ?></option><option value="grid"><?php esc_html_e( 'Two-field grid', 'open-product-fields-for-woocommerce' ); ?></option><option value="list"><?php esc_html_e( 'Combination list', 'open-product-fields-for-woocommerce' ); ?></option></select></p>
				<p class="description"><?php esc_html_e( 'A grid may name the table in cell A1 or leave A1 empty to use the filename. List tables always use the filename and may include a heading row ending in Price. Select Combination list if its first field value could be mistaken for a table name. Re-uploading a name replaces that table. Maximum: 10 MiB and 100,000 cells.', 'open-product-fields-for-woocommerce' ); ?></p>
				<?php submit_button( __( 'Upload lookup table', 'open-product-fields-for-woocommerce' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Imported tables', 'open-product-fields-for-woocommerce' ); ?></h2>
			<?php $tables = LookupTableStore::imported(); ?>
			<?php if ( ! $tables ) : ?>
				<p><?php esc_html_e( 'No CSV lookup tables have been imported.', 'open-product-fields-for-woocommerce' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Table', 'open-product-fields-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Dimensions', 'open-product-fields-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Formula example', 'open-product-fields-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Actions', 'open-product-fields-for-woocommerce' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $tables as $name => $table ) : $dimensions = self::dimensions( $table ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $name ); ?></th>
							<td><?php echo esc_html( (string) $dimensions ); ?></td>
							<td><code><?php echo esc_html( 'lookuptable(' . $name . str_repeat( '; field_id', $dimensions ) . ')' ); ?></code></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="opf_lookup_delete" />
									<input type="hidden" name="table" value="<?php echo esc_attr( $name ); ?>" />
									<?php wp_nonce_field( 'opf_lookup_delete_' . $name, 'opf_lookup_nonce' ); ?>
									<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'open-product-fields-for-woocommerce' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function import(): void {
		self::authorize( 'opf_lookup_import' );
		if ( ! isset( $_FILES['opf_lookup_csv'] ) || ! is_array( $_FILES['opf_lookup_csv'] ) ) {
			wp_die( esc_html__( 'Choose a CSV file to upload.', 'open-product-fields-for-woocommerce' ) );
		}
		$file = $_FILES['opf_lookup_csv'];
		$tmp_name = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$name = is_string( $file['name'] ?? null ) ? $file['name'] : '';
		$size = $tmp_name && is_file( $tmp_name ) ? filesize( $tmp_name ) : false;
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) || false === $size ) {
			wp_die( esc_html__( 'The CSV upload did not complete.', 'open-product-fields-for-woocommerce' ) );
		}
		if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'csv' || $size > self::MAX_UPLOAD_BYTES ) {
			wp_die( esc_html__( 'Upload a CSV file no larger than 10 MiB.', 'open-product-fields-for-woocommerce' ) );
		}
		$contents = file_get_contents( $tmp_name );
		if ( false === $contents ) {
			wp_die( esc_html__( 'The uploaded CSV could not be read.', 'open-product-fields-for-woocommerce' ) );
		}
		try {
			$format = isset( $_POST['opf_lookup_format'] ) ? sanitize_key( wp_unslash( $_POST['opf_lookup_format'] ) ) : 'auto';
			$parsed = LookupTableCsv::parse( $contents, sanitize_file_name( $name ), $format );
		} catch ( \InvalidArgumentException $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
		if ( ! LookupTableStore::save_imported( $parsed['name'], $parsed['table'] ) ) {
			wp_die( esc_html__( 'The lookup table could not be saved.', 'open-product-fields-for-woocommerce' ) );
		}
		self::redirect( 'imported' );
	}

	public static function delete(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage lookup tables.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
		$name = isset( $_POST['table'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['table'] ) ) ) : '';
		if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) || ! check_admin_referer( 'opf_lookup_delete_' . $name, 'opf_lookup_nonce' ) ) {
			wp_die( esc_html__( 'The lookup-table deletion request is invalid.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
		if ( ! LookupTableStore::delete_imported( $name ) ) {
			wp_die( esc_html__( 'The imported lookup table was not found.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 404 ] );
		}
		self::redirect( 'deleted' );
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( $nonce_action, 'opf_lookup_nonce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage lookup tables.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
	}

	private static function redirect( string $notice ): void {
		$url = add_query_arg( [ 'page' => 'opf-lookup-tables', 'opf_lookup_notice' => $notice ], admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private static function dimensions( array $table ): int {
		$depth = 0;
		while ( $table && is_array( reset( $table ) ) ) {
			++$depth;
			$table = reset( $table );
		}
		return $depth + 1;
	}
}
