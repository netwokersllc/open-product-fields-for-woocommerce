<?php
/** Prove Extended 3.1.5 parses OPF image-swatch WXR data. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'OPF_IMAGE_SWATCH_WXR_E2E' ) ) {
	throw new RuntimeException( 'Run in a disposable WordPress clone with OPF_IMAGE_SWATCH_WXR_E2E=1.' );
}
if ( ! class_exists( 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups' ) ) {
	WP_CLI::error( 'Activate WAPF Extended before running this proof.' );
}
if ( ! defined( 'OPF_DIR' ) ) {
	define( 'OPF_DIR', dirname( __DIR__ ) . '/' );
}
require_once OPF_DIR . 'includes/Autoloader.php';

$importer_file = WP_PLUGIN_DIR . '/wordpress-importer/wordpress-importer.php';
if ( ! class_exists( 'WP_Import' ) && is_readable( $importer_file ) ) {
	$importer_dir = dirname( $importer_file );
	require_once ABSPATH . 'wp-admin/includes/import.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	require_once $importer_dir . '/compat.php';
	require_once $importer_dir . '/php-toolkit/load.php';
	require_once $importer_dir . '/parsers/class-wxr-parser.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-simplexml.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-xml.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-regex.php';
	require_once $importer_dir . '/parsers/class-wxr-parser-xml-processor.php';
	require_once $importer_dir . '/class-wp-import.php';
}
if ( ! class_exists( 'WP_Import' ) ) {
	WP_CLI::error( 'Install WordPress Importer before running this proof.' );
}

$title = 'OPF Extended image swatch WXR fixture';
if ( get_page_by_title( $title, OBJECT, 'wapf_product' ) ) {
	WP_CLI::error( 'Image-swatch fixture already exists; refusing to modify it.' );
}
$attachment_ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 50, 'fields' => 'ids' ] );
$attachment_id = 0;
foreach ( $attachment_ids as $candidate_id ) {
	if ( wp_get_attachment_image_src( (int) $candidate_id, 'medium' ) ) {
		$attachment_id = (int) $candidate_id;
		break;
	}
}
if ( ! $attachment_id ) {
	WP_CLI::error( 'An image attachment is required for this round-trip proof.' );
}

$wxr_path = '';
try {
	$site_users = get_users( [ 'number' => 1 ] );
	$author = $site_users ? reset( $site_users ) : null;
	if ( ! $author ) {
		throw new RuntimeException( 'A WordPress user is required for the WXR fixture.' );
	}
	$xml = OPF\Service\WapfWxrExporter::build_document( [ [
		'id' => 9248101,
		'title' => $title,
		'status' => 'publish',
		'menu_order' => 0,
		'author' => (string) $author->user_login,
		'data' => OPF\Engine\FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'finish',
			'label' => 'Finish',
			'type' => 'swatch',
			'swatch_style' => 'image',
			'image_zoom' => true,
			'label_pos' => 'tooltip',
			'grid_layout' => 'flexible',
			'items_per_row' => 4,
			'items_per_row_tablet' => 2,
			'items_per_row_mobile' => 1,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => $attachment_id ] ],
		], [
			'id' => 'palette',
			'label' => 'Palette',
			'type' => 'swatch',
			'swatch_style' => 'color',
			'multiple' => true,
			'min_choices' => 1,
			'max_choices' => 2,
			'color_layout' => 'rounded',
			'color_size' => 36,
			'color_label_pos' => 'default',
			'choices' => [ [ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456' ] ],
		], [
			'id' => 'offer',
			'label' => 'Offer',
			'type' => 'paragraph',
			'content_format' => 'html',
			'process_shortcodes' => true,
			'content' => '<strong>Special offer</strong> [site_name]',
		], [
			'id' => 'guide-image',
			'label' => 'Fabric guide',
			'type' => 'content_image',
			'image_url' => 'https://example.test/fabric-guide.jpg',
			'image_id' => 481,
		] ] ] ),
	] ], [ 'site_url' => home_url(), 'site_title' => get_bloginfo( 'name' ) ] );
	$wxr_path = tempnam( get_temp_dir(), 'opf-image-wxr-' );
	if ( false === $wxr_path || false === file_put_contents( $wxr_path, $xml, LOCK_EX ) ) {
		throw new RuntimeException( 'Could not write the temporary WXR fixture.' );
	}
	$importer = new WP_Import();
	$importer->fetch_attachments = false;
	$importer->import( $wxr_path );

	$matches = get_posts( [ 'post_type' => 'wapf_product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'title' => $title ] );
	if ( 1 !== count( $matches ) ) {
		throw new RuntimeException( 'WordPress Importer did not create exactly one WAPF group.' );
	}
	$target_id = (int) $matches[0];
	$target = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_by_id( $target_id );
	$field = $target->fields[0] ?? null;
	$options = $field ? (array) $field->options : [];
	$choice = $options['choices'][0] ?? [];
	$multi_color = $target->fields[1] ?? null;
	$multi_color_options = $multi_color ? (array) $multi_color->options : [];
	$multi_color_choice = $multi_color_options['choices'][0] ?? [];
	$paragraph = $target->fields[2] ?? null;
	$content_image = $target->fields[3] ?? null;
	$checks = [
		'image-swatch type parsed' => $field && 'image-swatch' === $field->type,
		'image URL and attachment reference parsed' => 'https://example.test/oak.jpg' === ( $choice['image'] ?? '' ) && $attachment_id === (int) ( $choice['attachment'] ?? 0 ),
		'label and grid options parsed' => 'tooltip' === ( $options['label_pos'] ?? '' ) && 'flexible' === ( $options['grid_layout'] ?? '' ),
		'responsive counts and full-image option parsed' => 4 === (int) ( $options['items_per_row'] ?? 0 ) && 2 === (int) ( $options['items_per_row_tablet'] ?? 0 ) && 1 === (int) ( $options['items_per_row_mobile'] ?? 0 ) && ! empty( $options['large_image'] ),
		'multi-color type, selection bounds, layout, and color parsed' => $multi_color && 'multi-color-swatch' === $multi_color->type && 1 === (int) ( $multi_color_options['min_choices'] ?? 0 ) && 2 === (int) ( $multi_color_options['max_choices'] ?? 0 ) && 'rounded' === ( $multi_color_options['layout'] ?? '' ) && 36 === (int) ( $multi_color_options['size'] ?? 0 ) && '#123456' === ( $multi_color_choice['color'] ?? '' ),
		'Extended p content type preserves basic HTML and shortcodes' => $paragraph && 'p' === $paragraph->type && '<strong>Special offer</strong> [site_name]' === ( $paragraph->options['p_content'] ?? '' ),
		'WAPF img content preserves image URL and attachment reference' => $content_image && 'img' === $content_image->type && 'https://example.test/fabric-guide.jpg' === ( $content_image->options['image'] ?? '' ) && 481 === (int) ( $content_image->options['attachment'] ?? 0 ),
	];
	foreach ( $checks as $label => $passed ) {
		WP_CLI::log( ( $passed ? 'PASS ' : 'FAIL ' ) . $label );
	}
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'WAPF Extended swatch WXR parse failed.' );
	}
	WP_CLI::success( 'WAPF Extended image and multi-color swatch WXR round trip passed.' );
} finally {
	$matches = get_posts( [ 'post_type' => 'wapf_product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'title' => $title ] );
	foreach ( $matches as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	if ( $wxr_path && is_file( $wxr_path ) ) {
		unlink( $wxr_path );
	}
}
