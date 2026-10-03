<?php
/**
 * Create a canonical OPF field group for archive export/import e2e proof.
 * Saves through FieldGroups::save() so post_content is normalized JSON that
 * survives ArchiveImporter::decode's canonical round-trip check.
 *
 * @package open-product-fields-for-woocommerce
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$data = [
	'schema' => 2,
	'fields' => [
		[
			'id'           => 'src',
			'label'        => 'Src',
			'type'         => 'text',
			'required'     => false,
			'width'        => 100,
			'choices'      => [],
			'pricing'      => [ 'type' => 'none', 'amount' => 0, 'formula' => '', 'per_unit' => false ],
			'conditionals' => [],
		],
		[
			'id'           => 'drv',
			'label'        => 'Drv',
			'type'         => 'text',
			'required'     => false,
			'width'        => 100,
			'choices'      => [],
			'pricing'      => [ 'type' => 'formula', 'amount' => 0, 'formula' => '[field.src] + [price.src]', 'per_unit' => true ],
			'conditionals' => [
				[ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'src', 'operator' => 'is', 'value' => 'yes' ] ] ],
			],
		],
	],
	'rule_groups'     => [],
	'mark_required'   => true,
	'labels_position' => 'above',
];

$id = \OPF\Service\FieldGroups::save( 0, $data, [ 'title' => 'LANE OPF ARCHIVE SRC', 'status' => 'publish' ] );
if ( ! $id ) {
	WP_CLI::error( 'FieldGroups::save failed' );
}
WP_CLI::line( (string) $id );
