<?php
/** Private WordPress fixture for the bulk choice admin browser proof. */
if ( 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/opf-bulk-wp-e2e-' ) ) {
    throw new RuntimeException( 'Use an isolated /tmp/opf-bulk-wp-e2e-* clone.' );
}
$action = $args[0] ?? 'create';
$id = (int) ( $args[1] ?? 0 );
if ( 'cleanup' === $action ) {
    if ( 'opf_field_group' !== get_post_type( $id ) || 0 !== strpos( get_the_title( $id ), 'OPF bulk choice fixture' ) ) {
        throw new RuntimeException( 'Refusing to remove a non-fixture post.' );
    }
    wp_delete_post( $id, true );
    echo wp_json_encode( [ 'removed' => ! get_post( $id ) ] );
    return;
}
if ( 'read' === $action || 'roundtrip' === $action ) {
    $data = json_decode( get_post_field( 'post_content', $id ), true );
    if ( 'roundtrip' === $action ) {
        // Quantity/image export coverage belongs to its own capability lane.
        $data['fields'] = array_values( array_filter( $data['fields'], static fn( $field ) => in_array( $field['type'], [ 'select', 'radio', 'checkbox', 'swatch' ], true ) ) );
        $html_rejected = false;
        try {
            \OPF\Service\WapfExporter::build_payload( $data );
        } catch ( InvalidArgumentException $error ) {
            $html_rejected = false !== strpos( $error->getMessage(), 'HTML is not exported' );
        }
        // Exercise ordinary labels separately from the explicit HTML export gate.
        foreach ( $data['fields'] as &$field ) {
            $field['choices'] = array_values( array_filter( $field['choices'], static fn( $choice ) => false === strpos( $choice['label'], '<' ) ) );
        }
        unset( $field );
        $payload = \OPF\Service\WapfExporter::build_payload( $data );
        if ( ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) throw new RuntimeException( 'The actual WAPF package is required for the roundtrip proof.' );
        $payload['id'] = 'bulk-reference';
        $payload['type'] = 'wapf_product';
        $wapf = \SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( $payload );
        if ( ! $wapf ) throw new RuntimeException( 'WAPF rejected the exported Tools data.' );
        $stored = $wapf->to_array();
        $parsed = \OPF\Engine\WapfMapper::map( \OPF\Engine\WapfParser::parse( serialize( $stored ) ) );
        echo wp_json_encode( [ 'html_rejected' => $html_rejected, 'original' => $data, 'wapf_stored' => $stored, 'reimported' => $parsed ] );
    } else {
        echo wp_json_encode( $data );
    }
    return;
}
if ( 'create' !== $action ) throw new RuntimeException( 'Unknown fixture action.' );
$fields = [];
foreach ( [ 'select', 'radio', 'checkbox', 'swatch', 'image_quantity' ] as $type ) {
    $fields[] = [
        'id' => 'bulk_' . $type, 'label' => 'Bulk ' . $type, 'type' => $type,
        'choices' => [ [ 'slug' => 'duplicate', 'label' => 'Existing preserved', 'selected' => true,
            'pricing' => [ 'type' => 'fixed', 'amount' => 7 ],
            'quantity' => [ 'default' => 1, 'min' => 0, 'max' => 9 ],
        ] ],
        'swatch_style' => 'swatch' === $type ? 'color' : 'text',
    ];
    if ( 'swatch' === $type ) $fields[count( $fields ) - 1]['choices'][0]['color'] = '#123456';
}
$id = \OPF\Service\FieldGroups::save( 0, [ 'fields' => $fields ], [ 'title' => 'OPF bulk choice fixture ' . wp_generate_uuid4(), 'status' => 'draft' ] );
if ( ! $id ) throw new RuntimeException( 'Could not create fixture.' );
echo wp_json_encode( [ 'id' => $id, 'data' => json_decode( get_post_field( 'post_content', $id ), true ) ] );
