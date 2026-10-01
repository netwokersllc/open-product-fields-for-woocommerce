<?php
/** Read-only WordPress runtime proof for image-swatch attachment rendering. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run with WP-CLI.' );
}
if ( '1' !== getenv( 'OPF_IMAGE_SWATCH_RENDERER_E2E' ) ) {
	WP_CLI::error( 'Set OPF_IMAGE_SWATCH_RENDERER_E2E=1 to run this proof.' );
}
if ( ! defined( 'OPF_DIR' ) ) {
	define( 'OPF_DIR', dirname( __DIR__ ) . '/' );
}
require_once OPF_DIR . 'includes/Autoloader.php';

$attachments = get_posts( [
	'post_type' => 'attachment',
	'post_status' => 'inherit',
	'posts_per_page' => 50,
	'fields' => 'ids',
] );
$attachment_id = 0;
foreach ( $attachments as $candidate_id ) {
	if ( wp_get_attachment_image_src( (int) $candidate_id, 'medium' ) ) {
		$attachment_id = (int) $candidate_id;
		break;
	}
}
if ( ! $attachment_id ) {
	WP_CLI::error( 'No image attachment is available for the read-only renderer proof.' );
}

$group = new OPF\Engine\FieldGroup( [ 'fields' => [ [
	'id' => 'finish',
	'label' => 'Finish',
	'type' => 'swatch',
	'swatch_style' => 'image',
	'image_zoom' => true,
	'grid_layout' => 'flexible',
	'items_per_row' => 4,
	'items_per_row_tablet' => 2,
	'items_per_row_mobile' => 1,
	'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image_id' => $attachment_id ] ],
] ] ] );
ob_start();
OPF\Service\Renderer::render_group( 'image-test', 'Finish', $group, 10.0 );
$html = (string) ob_get_clean();
$url = wp_get_attachment_image_url( $attachment_id, 'medium' );
$full_url = wp_get_attachment_image_url( $attachment_id, 'full' );
$checks = [
	'attachment markup uses the selected Media Library image' => false !== strpos( $html, esc_url( $url ) ),
	'zoom markup uses the full-size Media Library image' => false !== strpos( $html, 'opf-swatch-zoom-preview' ) && false !== strpos( $html, esc_url( $full_url ) ),
	'image markup has accessible alt text' => false !== strpos( $html, 'alt="Oak"' ),
	'image swatch keeps selection input' => false !== strpos( $html, 'value="oak"' ),
	'zoom class and responsive grid settings render' => false !== strpos( $html, 'opf-swatch--image-zoom' ) && false !== strpos( $html, '--opf-image-swatch-cols:4;' ),
];
foreach ( $checks as $label => $passed ) {
	WP_CLI::log( ( $passed ? 'PASS ' : 'FAIL ' ) . $label );
}
if ( in_array( false, $checks, true ) ) {
	WP_CLI::error( 'Image swatch renderer runtime proof failed.' );
}
WP_CLI::success( 'Image swatch attachment renderer runtime proof passed.' );
