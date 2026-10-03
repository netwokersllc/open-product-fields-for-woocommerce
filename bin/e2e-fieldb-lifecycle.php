<?php
/**
 * Consolidated OPF-side lifecycle proof for the "fieldb" residual FIELD rows.
 *
 * Covers: swatch colour/image/multi, image quantities, paragraph content,
 * URL protocol matrix, checkbox limits/choices, conditional + repeated
 * sections, number/text validation contract, WAPF import/export parity.
 *
 * OPF_FIELDB_ALLOW=1 OPF_FIELDB_PHASE=setup|render|commerce|importexport|cleanup \
 *   OPF_FIELDB_OUT=/tmp/opf-lane-fieldb-evidence \
 *   wp eval-file bin/e2e-fieldb-lifecycle.php --path=<clone>
 *
 * Never run against production. OPF must be active; WAPF must be inactive.
 */
if ( '1' !== getenv( 'OPF_FIELDB_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'This proof requires an explicitly authorized disposable /tmp WordPress site.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'OPF\\Service\\FieldGroups' ) ) {
	throw new RuntimeException( 'WooCommerce and OPF must be active.' );
}
if ( class_exists( 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups' ) ) {
	throw new RuntimeException( 'Deactivate WAPF before running the OPF-side proof.' );
}
$out = getenv( 'OPF_FIELDB_OUT' );
if ( ! $out || ! is_dir( $out ) || 0 !== strpos( realpath( $out ), '/tmp/' ) ) {
	throw new RuntimeException( 'Set OPF_FIELDB_OUT to an existing /tmp artifact directory.' );
}
$option = 'opf_fieldb_state';
$phase  = getenv( 'OPF_FIELDB_PHASE' ) ?: 'commerce';
$state  = get_option( $option, [] );
$report_file = $out . '/opf-fieldb-report.json';
$GLOBALS['fieldb_report'] = is_file( $report_file ) ? json_decode( file_get_contents( $report_file ), true ) : [ 'checks' => [], 'observations' => [] ];
if ( ! is_array( $GLOBALS['fieldb_report'] ) ) {
	$GLOBALS['fieldb_report'] = [ 'checks' => [], 'observations' => [] ];
}
$GLOBALS['fieldb_failures'] = 0;
function fieldb_report( string $label, bool $ok, $detail = null ): void {
	$GLOBALS['fieldb_report']['checks'][] = [ 'label' => $label, 'pass' => $ok, 'detail' => $detail ];
	if ( ! $ok ) {
		$GLOBALS['fieldb_failures']++;
	}
	printf( "%s %s%s\n", $ok ? 'ok' : 'FAIL', $label, null === $detail ? '' : ' :: ' . wp_json_encode( $detail ) );
}
function fieldb_observe( string $label, $detail ): void {
	$GLOBALS['fieldb_report']['observations'][ $label ] = $detail;
	printf( "note %s :: %s\n", $label, wp_json_encode( $detail ) );
}

// ------------------------------------------------------------------ setup
if ( 'setup' === $phase ) {
	if ( $state ) {
		throw new RuntimeException( 'pre-existing fixture state' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF fieldb lifecycle fixture' );
	$product->set_slug( 'opf-fieldb-lifecycle' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	if ( ! $pid ) {
		throw new RuntimeException( 'product fixture' );
	}
	// Real attachment so the image swatch exercises wp_get_attachment_image.
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAATSURBVBhXY/iPA4YMmPj//z8DAwMAlA6V+X+2Y4sAAAAASUVORK5CYII=' );
	$upload = wp_upload_bits( 'opf-fieldb-swatch.png', null, $png );
	if ( $upload['error'] ) {
		throw new RuntimeException( 'attachment upload' );
	}
	$aid = wp_insert_attachment(
		[ 'post_title' => 'fieldb swatch', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'guid' => $upload['url'] ],
		$upload['file'], $pid
	);
	wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $upload['file'] ) );
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields' => [
			[ 'id' => 'gate', 'type' => 'toggle', 'label' => 'Personalize', 'message' => 'Add engraving' ],
			[ 'id' => 'sec', 'type' => 'section', 'label' => 'Extra details',
				'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'gate', 'operator' => 'is', 'value' => '1' ] ] ] ],
				'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3, 'label' => 'Row {n}', 'add' => 'Add row', 'del' => 'Remove' ] ],
			[ 'id' => 'sectext', 'type' => 'text', 'label' => 'Section text', 'required' => true ],
			[ 'id' => 'secend', 'type' => 'section_end' ],
			[ 'id' => 'colorpick', 'type' => 'swatch', 'label' => 'Pick a colour', 'swatch_style' => 'color',
				'color_layout' => 'circle', 'color_size' => 34, 'color_label_pos' => 'tooltip',
				'choices' => [
					[ 'slug' => 'red', 'label' => 'Red', 'color' => '#ff0000', 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ] ],
					[ 'slug' => 'green', 'label' => 'Green', 'color' => '#00ff00' ],
				] ],
			[ 'id' => 'imgpick', 'type' => 'swatch', 'label' => 'Pick an image', 'swatch_style' => 'image',
				'label_pos' => 'out', 'grid_layout' => 'flexible', 'items_per_row' => 3, 'items_per_row_tablet' => 2, 'items_per_row_mobile' => 1, 'image_zoom' => true,
				'choices' => [
					[ 'slug' => 'logo', 'label' => 'Logo', 'image' => 'https://example.invalid/logo.png' ],
					[ 'slug' => 'att', 'label' => 'Attachment', 'image_id' => $aid ],
				] ],
			[ 'id' => 'multitext', 'type' => 'swatch', 'label' => 'Multi text', 'swatch_style' => 'text', 'multiple' => true,
				'min_choices' => 1, 'max_choices' => 2,
				'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'fixed', 'amount' => 1.5 ] ],
					[ 'slug' => 'b', 'label' => 'B' ],
					[ 'slug' => 'c', 'label' => 'C' ],
				] ],
			[ 'id' => 'imgqty', 'type' => 'image_quantity', 'label' => 'Image quantities',
				'min_choices' => 1, 'max_choices' => 5,
				'choices' => [
					[ 'slug' => 'red', 'label' => 'Red swatch', 'image' => 'https://example.invalid/r.png',
						'quantity' => [ 'min' => 0, 'max' => 3, 'default' => 1 ], 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
					[ 'slug' => 'blu', 'label' => 'Blue swatch', 'image' => 'https://example.invalid/b.png',
						'quantity' => [ 'min' => 1, 'max' => 2, 'default' => 1 ] ],
				] ],
			[ 'id' => 'note', 'type' => 'paragraph', 'label' => 'Note', 'content' => 'Line one & <b>not bold</b> ©' ],
			[ 'id' => 'richtxt', 'type' => 'paragraph', 'label' => 'Rich note', 'content' => '<strong>Bold ok</strong> <script>alert(1)</script>', 'content_format' => 'html' ],
			[ 'id' => 'site', 'type' => 'url', 'label' => 'Website', 'required' => true ],
			[ 'id' => 'cbx', 'type' => 'checkbox', 'label' => 'Plain checkboxes', 'min_choices' => 1, 'max_choices' => 2,
				'choices' => [
					[ 'slug' => 'x', 'label' => 'X', 'pricing' => [ 'type' => 'fixed', 'amount' => 1 ] ],
					[ 'slug' => 'y', 'label' => 'Y' ],
					[ 'slug' => 'w', 'label' => 'W' ],
					[ 'slug' => 'z', 'label' => 'Z', 'disabled' => true ],
				] ],
			[ 'id' => 'num', 'type' => 'number', 'label' => 'Plain number' ],
			[ 'id' => 'mintext', 'type' => 'text', 'label' => 'Validated text', 'minlength' => 3, 'maxlength' => 5, 'pattern' => '[a-z]+' ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
	], [ 'title' => 'OPF fieldb lifecycle', 'status' => 'publish' ] );
	$uid = wp_create_user( 'opf_fieldb', wp_generate_password( 32 ), 'fieldb@example.invalid' );
	( new WP_User( $uid ) )->set_role( 'administrator' );
	$state = [ 'product' => $pid, 'group' => $gid, 'attachment' => $aid, 'upload_file' => $upload['file'], 'user' => $uid, 'orders' => [] ];
	update_option( $option, $state, false );
	update_option( 'opf_admin_only', 'no' );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	fieldb_report( 'fixtures created', true, $state );
	file_put_contents( $report_file, wp_json_encode( $GLOBALS['fieldb_report'], JSON_PRETTY_PRINT ) );
	return;
}

if ( empty( $state['group'] ) ) {
	throw new RuntimeException( 'run setup first' );
}
$pid = (int) $state['product'];
$gid = (string) $state['group'];
$group = OPF\Service\FieldGroups::group_from_post( get_post( $state['group'] ) );

// ------------------------------------------------------------------ cleanup
if ( 'cleanup' === $phase ) {
	foreach ( wc_get_orders( [ 'limit' => -1 ] ) as $candidate ) {
		foreach ( $candidate->get_items() as $item ) {
			if ( $pid === (int) $item->get_product_id() ) {
				$state['orders'][] = $candidate->get_id();
				break;
			}
		}
	}
	foreach ( array_unique( $state['orders'] ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	wp_delete_post( $state['group'], true );
	wp_delete_post( $state['product'], true );
	if ( ! empty( $state['attachment'] ) ) {
		wp_delete_post( $state['attachment'], true );
	}
	if ( ! empty( $state['upload_file'] ) && is_file( $state['upload_file'] ) ) {
		unlink( $state['upload_file'] );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $state['user'] );
	delete_option( $option );
	fieldb_report( 'fixture cleanup', true );
	file_put_contents( $report_file, wp_json_encode( $GLOBALS['fieldb_report'], JSON_PRETTY_PRINT ) );
	return;
}

// ------------------------------------------------------------------ render
if ( 'render' === $phase ) {
	$product = wc_get_product( $pid );
	$GLOBALS['product'] = $product;
	ob_start();
	OPF\Service\Renderer::render_group( $state['group'], 'OPF fieldb lifecycle', $group, (float) $product->get_price( 'edit' ), $product );
	$html = ob_get_clean();
	file_put_contents( $out . '/opf-rendered-group.html', $html );
	fieldb_report( 'rendered group markup captured', strlen( $html ) > 500, strlen( $html ) );

	// --- sections
	fieldb_report( 'conditional section wrapper: opf-section + has-conditions', false !== strpos( $html, 'opf-section' ) && false !== strpos( $html, 'has-conditions' ) );
	fieldb_report( 'conditional section hidden at default state (gate unchecked)', (bool) preg_match( '/opf-section[^"]*opf-hide|opf-hide[^"]*opf-section/', $html ) );
	fieldb_report( 'button section repeat: data-opf-section-repeat + data-opf-repeat-max=3', false !== strpos( $html, 'data-opf-section-repeat' ) && false !== strpos( $html, 'data-opf-repeat-max="3"' ) );
	fieldb_report( 'button section repeat add label rendered', false !== strpos( $html, 'opf-field-repeat__add' ) && false !== strpos( $html, 'Add row' ) );
	fieldb_report( 'repeated row input name carries section row index', false !== strpos( $html, 'name="opf[' . $gid . '][sectext][0]"' ) );

	// --- swatch colour
	fieldb_report( 'colour swatch: data-color-layout=circle', false !== strpos( $html, 'data-color-layout="circle"' ) );
	fieldb_report( 'colour swatch: per-choice colour + size css vars', false !== strpos( $html, '--opf-swatch-color:#FF0000' ) && false !== strpos( $html, '--opf-swatch-size:34px' ) );
	fieldb_report( 'colour swatch: label position marker', false !== strpos( $html, 'data-color-label-position="tooltip"' ) );
	fieldb_report( 'colour swatch: priced choice carries data-opf-price', (bool) preg_match( '/data-opf-price="3(\.0+)?"/', $html ) );

	// --- swatch image
	fieldb_report( 'image swatch: wrapper + flexible grid cols css vars', false !== strpos( $html, 'opf-image-swatch-wrapper' ) && false !== strpos( $html, '--opf-image-swatch-cols:3' ) && false !== strpos( $html, '--opf-image-swatch-cols-tablet:2' ) && false !== strpos( $html, '--opf-image-swatch-cols-mobile:1' ) );
	fieldb_report( 'image swatch: label position out + grid attrs', false !== strpos( $html, 'data-label-position="out"' ) && false !== strpos( $html, 'data-grid-layout="flexible"' ) );
	fieldb_report( 'image swatch: attachment <img> rendered', (bool) preg_match( '/<img[^>]+opf-swatch-image/', $html ) );
	fieldb_report( 'image swatch: zoom preview rendered', false !== strpos( $html, 'opf-swatch-zoom-preview' ) );

	// --- swatch multi (text)
	fieldb_report( 'multi swatch: checkbox inputs named [] + min/max data attrs', false !== strpos( $html, 'name="opf[' . $gid . '][multitext][]"' ) && false !== strpos( $html, 'data-min-choices="1"' ) && false !== strpos( $html, 'data-max-choices="2"' ) );

	// --- image quantities
	fieldb_report( 'image quantity: per-choice number inputs min/max/step/default', (bool) preg_match( '/name="opf\[' . $gid . '\]\[imgqty\]\[red\]"[^>]*min="0"[^>]*max="3"[^>]*step="1"|min="0"[^>]*max="3"[^>]*step="1"[^>]*name="opf\[' . $gid . '\]\[imgqty\]\[red\]"/', $html ) );
	fieldb_report( 'image quantity: blu bounds 1..2', (bool) preg_match( '/imgqty\]\[blu\][^>]*min="1"[^>]*max="2"|min="1"[^>]*max="2"[^>]*imgqty\]\[blu\]/', $html ) );

	// --- paragraphs
	fieldb_report( 'paragraph plain content escaped entities and markup', false !== strpos( $html, 'Line one &amp; &lt;b&gt;not bold&lt;/b&gt; ©' ) && false === strpos( $html, '<b>not bold</b>' ) );
	fieldb_report( 'paragraph html format allowlists <strong> and strips <script> tag', false !== strpos( $html, '<strong>Bold ok</strong>' ) && false === strpos( $html, '<script' ) );

	// --- url + number + text validation contract
	fieldb_report( 'url input type=url rendered', (bool) preg_match( '/<input type="url"[^>]*name="opf\[' . $gid . '\]\[site\]"/', $html ) );
	$num_html = '';
	if ( preg_match( '/<input type="number"[^>]*name="opf\[' . $gid . '\]\[num\]"[^>]*>/', $html, $m ) ) {
		$num_html = $m[0];
	}
	fieldb_observe( 'opf number input markup (gap evidence)', $num_html );
	fieldb_report( 'number field renders no min/max/step attrs (documents Extended gap)', '' !== $num_html && ! preg_match( '/\b(min|max|step)=/', $num_html ) );
	$txt_html = '';
	if ( preg_match( '/<input type="text"[^>]*name="opf\[' . $gid . '\]\[mintext\]"[^>]*>/', $html, $m ) ) {
		$txt_html = $m[0];
	}
	fieldb_observe( 'opf text input markup', $txt_html );
	fieldb_report( 'text field renders WAPF minlength/maxlength/pattern attrs', '' !== $txt_html && false !== strpos( $txt_html, 'minlength="3"' ) && false !== strpos( $txt_html, 'maxlength="5"' ) && false !== strpos( $txt_html, 'pattern="[a-z]+"' ) );
	fieldb_report( 'checkbox wrapper emits min/max-choices attrs', false !== strpos( $html, 'data-min-choices="1"' ) && false !== strpos( $html, 'data-max-choices="2"' ) );
	fieldb_report( 'disabled checkbox choice rendered disabled', (bool) preg_match( '/value="z"[^>]*disabled|disabled[^>]*value="z"/', $html ) );
	$GLOBALS['product'] = null;
	file_put_contents( $report_file, wp_json_encode( $GLOBALS['fieldb_report'], JSON_PRETTY_PRINT ) );
	echo "DONE render\n";
	return;
}

// ------------------------------------------------------------- importexport
if ( 'importexport' === $phase ) {
	$raw_file = $out . '/wapf-ref-raw-input.json';
	if ( ! is_file( $raw_file ) ) {
		throw new RuntimeException( 'run the WAPF reference capture first' );
	}
	$raw = json_decode( file_get_contents( $raw_file ), true );
	$mapped = OPF\Engine\WapfMapper::map( $raw );
	$by_wapf = [];
	foreach ( $raw['fields'] as $index => $f ) {
		$by_wapf[ $f['id'] ?? ('i' . $index) ] = $f['type'] ?? '?';
	}
	$opf_types = [];
	foreach ( $mapped['group']['fields'] as $f ) {
		$opf_types[] = $f['id'] . ':' . $f['type'];
	}
	$summary = [
		'source_types'   => $by_wapf,
		'mapped_fields'  => $opf_types,
		'notes'          => $mapped['notes'],
		'needs_review'   => $mapped['needs_review'],
	];
	file_put_contents( $out . '/opf-import-map.json', wp_json_encode( [ 'summary' => $summary, 'group' => $mapped['group'] ], JSON_PRETTY_PRINT ) );
	fieldb_report( 'WAPF fixture import maps image-swatch-qty to image_quantity', (bool) array_filter( $mapped['group']['fields'], static fn( $f ) => 'image_quantity' === $f['type'] ) );
	fieldb_report( 'WAPF fixture import maps checkboxes to checkbox', (bool) array_filter( $mapped['group']['fields'], static fn( $f ) => 'checkbox' === $f['type'] ) );
	fieldb_report( 'WAPF calc field dropped by importer (documents gap)', ! array_filter( $mapped['group']['fields'], static fn( $f ) => false !== strpos( (string) $f['type'], 'calc' ) ) && (bool) preg_grep( '/unsupported|calc/i', $mapped['notes'] ) );
	// Which WAPF options survive on the imported checkbox field?
	$cb_imported = null;
	foreach ( $mapped['group']['fields'] as $f ) {
		if ( 'checkbox' === $f['type'] ) {
			$cb_imported = $f;
			break;
		}
	}
	fieldb_observe( 'imported checkbox keys', $cb_imported ? array_keys( $cb_imported ) : null );
	fieldb_report( 'imported checkbox maps WAPF min_choices/max_choices', $cb_imported && 1 === (int) ( $cb_imported['min_choices'] ?? 0 ) && 2 === (int) ( $cb_imported['max_choices'] ?? 0 ) );
	$num_imported = null;
	foreach ( $mapped['group']['fields'] as $f ) {
		if ( 'number' === $f['type'] ) {
			$num_imported = $f;
			break;
		}
	}
	fieldb_observe( 'imported number field keys', $num_imported ? array_keys( $num_imported ) : null );
	fieldb_report( 'imported number lost WAPF minimum/maximum/number_type/display (documents gap)', $num_imported && ! isset( $num_imported['minimum'] ) && ! isset( $num_imported['display'] ) );

	// nrq is the WAPF price type that multiplies by the entered image count.
	// OPF has no nr/nrq/char/charq choice type — the importer must drop the
	// pricing, mark needs_review and note it rather than silently repricing.
	$nrq_mapped = OPF\Engine\WapfMapper::map( [
		'fields' => [ [
			'id' => 'nq', 'type' => 'image-swatch-qty', 'label' => 'NQ',
			'required' => false, 'width' => 100,
			'options' => [ 'choices' => [ [ 'slug' => 'one', 'label' => 'One', 'pricing_type' => 'nrq', 'pricing_amount' => 2, 'image' => 'https://example.invalid/one.png', 'options' => [ 'min' => 0, 'max' => 9, 'default' => 0 ] ] ] ],
		] ],
	] );
	$nrq_choice = $nrq_mapped['group']['fields'][0]['choices'][0] ?? [];
	fieldb_report( 'WAPF nrq choice pricing maps to value-based [x] formula',
		'formula' === (string) ( $nrq_choice['pricing']['type'] ?? '' ) && '[x] * 2' === ( $nrq_choice['pricing']['formula'] ?? '' ) && true === (bool) ( $nrq_choice['pricing']['per_unit'] ?? false ) && ! preg_grep( '/nrq.*not supported/i', $nrq_mapped['notes'] ), $nrq_mapped['notes'] );

	// OPF -> WAPF export. Repeated-section clone settings cannot export at this
	// HEAD (fail-closed) — record that, then export a repeat-less variant so the
	// other rows' parity is still exercised.
	try {
		OPF\Service\WapfExporter::build_payload( $group->data );
		fieldb_report( 'export of section clone settings (documents gap)', false, 'no exception thrown' );
	} catch ( \InvalidArgumentException $e ) {
		fieldb_observe( 'export refuses repeated section (fail-closed, clone settings unexportable)', $e->getMessage() );
	}
	$export_data = $group->data;
	foreach ( $export_data['fields'] as &$f ) {
		unset( $f['repeat'] );
	}
	unset( $f );
	try {
		OPF\Service\WapfExporter::build_payload( $export_data );
		fieldb_report( 'export of fixture group incl. paragraphs', false, 'no exception thrown' );
	} catch ( \InvalidArgumentException $e ) {
		// Paragraph content and text-validation keys both fail closed in the
		// exporter (not owned by this lane) until an export-mapping lane lands.
		fieldb_observe( 'export fail-closed boundary (paragraph content / text-validation keys)', $e->getMessage() );
	}
	$export_data['fields'] = array_values( array_filter( $export_data['fields'], static fn( $f ) => ! in_array( $f['id'], [ 'note', 'richtxt', 'mintext' ], true ) ) );
	$payload = OPF\Service\WapfExporter::build_payload( $export_data );
	file_put_contents( $out . '/opf-exported-wapf-payload.json', wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
	$exported = [];
	foreach ( $payload['fields'] as $f ) {
		$exported[ $f['id'] ] = $f['type'];
	}
	fieldb_observe( 'exported field types', $exported );
	fieldb_report( 'export: image_quantity round-trips to image-swatch-qty', 'image-swatch-qty' === ( $exported['imgqty'] ?? '' ) );
	fieldb_report( 'export: colour swatch exports as color-swatch', 'color-swatch' === ( $exported['colorpick'] ?? '' ) );
	fieldb_report( 'export: multi text swatch exports as multi-text-swatch', 'multi-text-swatch' === ( $exported['multitext'] ?? '' ) );
	fieldb_report( 'export: image swatch exports as image-swatch', 'image-swatch' === ( $exported['imgpick'] ?? '' ) );
	fieldb_report( 'export: section markers export as section/sectionend', 'section' === ( $exported['sec'] ?? '' ) && 'sectionend' === ( $exported['secend'] ?? '' ) );
	fieldb_report( 'export: checkbox exports as checkboxes', 'checkboxes' === ( $exported['cbx'] ?? '' ) );
	fieldb_report( 'export: toggle exports as true-false', 'true-false' === ( $exported['gate'] ?? '' ) );
	// Clean paragraph variants prove the content/p type mapping itself.
	$para_group = OPF\Engine\FieldGroup::normalize( [
		'fields' => [
			[ 'id' => 'plainp', 'type' => 'paragraph', 'label' => 'P', 'content' => 'plain words only' ],
			[ 'id' => 'htmlp', 'type' => 'paragraph', 'label' => 'HP', 'content' => '<b>x</b>', 'content_format' => 'html', 'process_shortcodes' => true ],
		],
		'rule_groups' => [],
	] );
	$para_export = OPF\Service\WapfExporter::build_payload( $para_group );
	file_put_contents( $out . '/opf-exported-paragraph-payload.json', wp_json_encode( $para_export, JSON_PRETTY_PRINT ) );
	$para_types = array_column( $para_export['fields'], 'type', 'id' );
	fieldb_report( 'export: clean paragraphs export as content/p', 'content' === ( $para_types['plainp'] ?? '' ) && 'p' === ( $para_types['htmlp'] ?? '' ) );
	// Exported option detail for swatches + section (flat Tools keys).
	foreach ( $payload['fields'] as $f ) {
		if ( 'imgqty' === $f['id'] ) {
			fieldb_observe( 'exported image-swatch-qty field', $f );
			fieldb_report( 'export: image-swatch-qty carries min_choices/max_choices',
				1 === (int) ( $f['min_choices'] ?? -1 ) && 5 === (int) ( $f['max_choices'] ?? -1 ) );
			$qty_ok = false;
			foreach ( $f['choices'] ?? [] as $c ) {
				if ( 'red' === $c['slug'] ) {
					$qty_ok = 0 === (int) ( $c['options']['min'] ?? -1 ) && 3 === (int) ( $c['options']['max'] ?? -1 ) && 1 === (int) ( $c['options']['default'] ?? -1 );
				}
			}
			fieldb_report( 'export: image-swatch-qty carries per-choice min/max/default', $qty_ok );
		}
		if ( 'multitext' === $f['id'] ) {
			fieldb_report( 'export: multi-text-swatch carries min/max_choices', 1 === (int) ( $f['min_choices'] ?? -1 ) && 2 === (int) ( $f['max_choices'] ?? -1 ) );
		}
		if ( 'sec' === $f['id'] ) {
			fieldb_observe( 'exported section field', $f );
		}
		if ( 'colorpick' === $f['id'] ) {
			fieldb_observe( 'exported color-swatch field', $f );
			fieldb_report( 'export: color-swatch carries layout/size/label_pos + choice color',
				'circle' === ( $f['layout'] ?? '' ) && 34 === (int) ( $f['size'] ?? 0 ) && 'tooltip' === ( $f['label_pos'] ?? '' )
				&& '#FF0000' === strtoupper( (string) ( $f['choices'][0]['color'] ?? '' ) ) );
		}
		if ( 'imgpick' === $f['id'] ) {
			fieldb_observe( 'exported image-swatch field', $f );
			fieldb_report( 'export: image-swatch carries grid/label/large_image settings',
				'flexible' === ( $f['grid_layout'] ?? '' ) && 'out' === ( $f['label_pos'] ?? '' ) && ! empty( $f['large_image'] ) && 3 === (int) ( $f['items_per_row'] ?? 0 ) );
		}
	}
	file_put_contents( $report_file, wp_json_encode( $GLOBALS['fieldb_report'], JSON_PRETTY_PRINT ) );
	echo "DONE importexport\n";
	return;
}

// ------------------------------------------------------------------ commerce
if ( ! WC()->cart ) {
	wc_load_cart();
}
add_filter( 'pre_wp_mail', '__return_true' );
$cart = WC()->cart;
$cart->empty_cart();
$dispatch = static function ( string $path, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/' . $path );
	$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	return rest_get_server()->dispatch( $request );
};
$classic_validate = static function ( array $values ): bool {
	$_POST['opf'] = $values;
	wc_clear_notices();
	$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $GLOBALS['pid'], 1 );
	unset( $_POST['opf'] );
	return true === $ok;
};
$GLOBALS['pid'] = $pid;
// Required/bounded fields must be satisfied in every payload: site (required),
// sectext (required while gate=1 keeps the section visible), multitext
// (min_choices=1 floor) and imgqty (min_choices=1 total floor). Cases merge
// this base to isolate the value under test. Base addon price: multi-a +1.5
// and imgqty red1x$2 = +3.5 over the 10.00 product price.
$base = [ 'gate' => '1', 'sectext' => [ 'x' ], 'site' => 'https://example.com', 'multitext' => [ 'a' ], 'imgqty' => [ 'red' => 1, 'blu' => 1 ] ];
$val = static function ( array $field_values ) use ( $gid, $base ): array {
	return [ $gid => array_merge( $base, $field_values ) ];
};

// 1) colour swatch: classic + store api + pricing + order + order again.
fieldb_report( 'classic accepts colour swatch choice', $classic_validate( $val( [ 'colorpick' => 'red' ] ) ) );
$_POST['opf'] = $val( [ 'colorpick' => 'red' ] );
wc_clear_notices();
$key = $cart->add_to_cart( $pid, 1 );
unset( $_POST['opf'] );
fieldb_report( 'classic cart captures swatch choice', (bool) $key && 'red' === ( $cart->get_cart_item( $key )['opf_fields'][ $gid ]['colorpick'] ?? '' ) );
$cart->calculate_totals();
fieldb_report( 'colour swatch fixed price +3 applied (13.5 base+multi+imgqty -> 16.5)', abs( 16.5 - (float) $cart->get_total( 'edit' ) ) < 0.001 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'colorpick' => 'green' ] ) ] );
fieldb_report( 'Store API accepts colour swatch choice', $resp->get_status() < 400 );
$cart->calculate_totals();
fieldb_report( 'unpriced swatch choice keeps base total', abs( 13.5 - (float) $cart->get_total( 'edit' ) ) < 0.001 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'colorpick' => 'forged' ] ) ] );
fieldb_report( 'forged swatch slug silently dropped (not persisted)', $resp->get_status() < 400 && ! isset( reset( $cart->cart_contents )['opf_fields'][ $gid ]['colorpick'] ) );
$cart->empty_cart();

// 2) image swatch: cart line + order meta + order again.
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'imgpick' => 'logo' ] ) ] );
fieldb_report( 'Store API accepts image swatch choice', $resp->get_status() < 400 );
$line = reset( $cart->cart_contents );
fieldb_report( 'image swatch choice persisted to cart line', 'logo' === ( $line['opf_fields'][ $gid ]['imgpick'] ?? '' ) );
$cart->empty_cart();

// 3) multi text swatch: bounds + pricing.
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'multitext' => [ 'a', 'b' ] ] ) ] );
fieldb_report( 'multi swatch 2-of-2 accepted', $resp->get_status() < 400 );
$cart->calculate_totals();
fieldb_report( 'multi swatch priced choice +1.50 applied', abs( 13.5 - (float) $cart->get_total( 'edit' ) ) < 0.001 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'multitext' => [ 'a', 'b', 'c' ] ] ) ] );
fieldb_report( 'multi swatch 3-of-max2 rejected server-side', $resp->get_status() >= 400 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'multitext' => [] ] ) ] );
fieldb_report( 'multi swatch 0-of-min1 rejected server-side', $resp->get_status() >= 400 );
$cart->empty_cart();

// 4) image quantities: aggregate + per-choice + pricing.
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'imgqty' => [ 'red' => 2, 'blu' => 1 ] ] ) ] );
fieldb_report( 'image quantities in bounds accepted', $resp->get_status() < 400 );
$cart->calculate_totals();
// WAPF do_pricing parity (class-fields.php: fixed/default returns $amount or
// $amount/qty — the entered image count does NOT multiply a fixed charge;
// nr/nrq/fx [x] rows consume the count). base10 + multi-a1.5 + imgqty-red $2.
fieldb_report( 'image quantity fixed choice is flat per line (WAPF do_pricing parity)', abs( 13.5 - (float) $cart->get_total( 'edit' ) ) < 0.001 );
// Calculator-level proof that the entered count still reaches pricing the
// WAPF way: fx/[x]/[val] consume it; fixed ignores it; qty>1 divides per unit.
$imgqty_field = null;
foreach ( $group->data['fields'] as $f ) {
	if ( 'imgqty' === $f['id'] ) {
		$imgqty_field = $f;
	}
}
$calc_ctx = [ 'price' => 10.0, 'qty' => 1, 'product_id' => $pid, 'field_values' => [], 'field_prices' => [] ];
$calc_val = [ '_opf_type' => 'image_quantity', 'quantities' => [ 'red' => 2, 'blu' => 1 ] ];
$fx_field = $imgqty_field;
$fx_field['choices'][0]['pricing'] = [ 'type' => 'formula', 'formula' => '[x] * 2', 'amount' => 0 ];
$fxv_field = $imgqty_field;
$fxv_field['choices'][0]['pricing'] = [ 'type' => 'formula', 'formula' => '[val] * 2', 'amount' => 0 ];
fieldb_report( 'image quantity entered count feeds fx [x] (red 2x$2 = 4.00)', abs( 4.0 - OPF\Engine\Calculator::field_addon( $fx_field, $calc_val, $calc_ctx ) ) < 0.001 );
fieldb_report( 'image quantity entered count feeds fx [val] (red 2x$2 = 4.00)', abs( 4.0 - OPF\Engine\Calculator::field_addon( $fxv_field, $calc_val, $calc_ctx ) ) < 0.001 );
fieldb_report( 'image quantity fixed choice ignores entered count (red 2 = $2 flat)', abs( 2.0 - OPF\Engine\Calculator::field_addon( $imgqty_field, $calc_val, $calc_ctx ) ) < 0.001 );
fieldb_report( 'image quantity fx per-unit contribution at line qty=2 (WAPF fx $x/qty)', abs( 2.0 - OPF\Engine\Calculator::field_addon( $fx_field, [ '_opf_type' => 'image_quantity', 'quantities' => [ 'red' => 2 ] ], [ 'price' => 10.0, 'qty' => 2, 'product_id' => $pid, 'field_values' => [], 'field_prices' => [] ] ) ) < 0.001 );
fieldb_report( 'image quantity fixed per-unit contribution at line qty=2 (WAPF $amount/qty)', abs( 1.0 - OPF\Engine\Calculator::field_addon( $imgqty_field, [ '_opf_type' => 'image_quantity', 'quantities' => [ 'red' => 2 ] ], [ 'price' => 10.0, 'qty' => 2, 'product_id' => $pid, 'field_values' => [], 'field_prices' => [] ] ) ) < 0.001 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'imgqty' => [ 'red' => 4, 'blu' => 2 ] ] ) ] );
fieldb_report( 'image quantities over aggregate max rejected', $resp->get_status() >= 400 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'imgqty' => [ 'red' => 5 ] ] ) ] );
fieldb_report( 'image quantity over per-choice max rejected', $resp->get_status() >= 400 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'imgqty' => [ 'red' => 'x' ] ] ) ] );
fieldb_report( 'image quantity non-numeric rejected', $resp->get_status() >= 400 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'imgqty' => [ 'red' => 0, 'blu' => 0 ] ] ) ] );
fieldb_observe( 'image quantity zero total (blu min=1) Store API status', $resp->get_status() );
$cart->empty_cart();

// 5) url protocol matrix through the real FieldValue validator.
$url_field = null;
foreach ( $group->data['fields'] as $f ) {
	if ( 'site' === $f['id'] ) {
		$url_field = $f;
	}
}
$schemes = array_unique( array_merge( wp_allowed_protocols(), [ 'javascript', 'data', 'file', 'vbscript' ] ) );
$matrix = [];
foreach ( $schemes as $scheme ) {
	$value = $scheme . '://example.com/path';
	$matrix[ $scheme ] = empty( OPF\Engine\FieldValue::validate( $url_field, $value, true ) ) ? 'accept' : 'reject';
}
foreach ( [ 'javascript:alert(1)', 'data:text/html;base64,PGI+', 'vbscript:x', 'file:///etc/passwd', 'notaurl', '//example.com', 'https://example.com/ok path' ] as $v ) {
	$matrix[ $v ] = empty( OPF\Engine\FieldValue::validate( $url_field, $v, true ) ) ? 'accept' : 'reject';
}
fieldb_observe( 'OPF url validation matrix', $matrix );
fieldb_report( 'url matrix rejects javascript:/data:/vbscript:/file:', 'reject' === $matrix['javascript'] && 'reject' === $matrix['data'] && 'reject' === $matrix['vbscript'] && 'reject' === $matrix['file'] );
$allowed = wp_allowed_protocols();
$rejected_allowed = array_filter( $allowed, static fn( $s ) => 'reject' === ( $matrix[ $s ] ?? '' ) );
fieldb_report( 'url matrix accepts every wp_allowed_protocols scheme', [] === $rejected_allowed, $rejected_allowed );
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'site' => 'javascript:alert(1)' ] ) ] );
fieldb_report( 'Store API rejects javascript: url', $resp->get_status() >= 400 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'site' => 'ftps://example.com/x' ] ) ] );
fieldb_report( 'Store API accepts allowed non-http scheme', $resp->get_status() < 400 );
$cart->empty_cart();

// 6) checkboxes: WAPF min/max limits + disabled choice rejection.
fieldb_report( 'classic accepts 2 checkbox selections', $classic_validate( $val( [ 'cbx' => [ 'x', 'y' ] ] ) ) );
fieldb_report( 'classic accepts empty optional checkbox below min=1 (WAPF optional-min)', $classic_validate( $val( [] ) ) );
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'cbx' => [ 'x', 'y', 'w' ] ] ) ] );
fieldb_report( 'checkbox over max=2 rejected server-side', $resp->get_status() >= 400 );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'cbx' => [ 'z' ] ] ) ] );
fieldb_report( 'forged disabled checkbox choice rejected', $resp->get_status() >= 400 );
$cart->empty_cart();

// 7) conditional + repeated section.
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'sectext' => [ 'Alpha', 'Beta' ] ] ) ] );
fieldb_report( 'visible conditional section accepts repeated rows', $resp->get_status() < 400 );
$line = reset( $cart->cart_contents );
fieldb_report( 'repeated section rows persisted as row array', [ 'Alpha', 'Beta' ] === array_values( $line['opf_fields'][ $gid ]['sectext'] ?? [] ) );
$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'sectext' => [] ] ) ] );
fieldb_report( 'required field inside visible section enforced', $resp->get_status() >= 400 );
$cart->empty_cart();
	$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'gate' => '0', 'sectext' => [] ] ) ] );
	fieldb_report( 'required child of hidden section skipped (section condition propagated)', $resp->get_status() < 400 );
	$cart->empty_cart();
	$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'gate' => '0', 'sectext' => [ 'forged' ] ] ) ] );
	$line = reset( $cart->cart_contents );
	fieldb_report( 'forged value inside hidden section accepted but not persisted', $resp->get_status() < 400 && ! isset( $line['opf_fields'][ $gid ]['sectext'] ) );
	$cart->empty_cart();
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'sectext' => [ 'a', 'b', 'c', 'd' ] ] ) ] );
fieldb_report( 'button section repeat over max=3 rejected', $resp->get_status() >= 400 );
$cart->empty_cart();

// 8) full checkout + order-again on a composite submission.
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => [ $gid => [
	'gate' => '1', 'sectext' => [ 'Row A' ], 'colorpick' => 'red', 'imgpick' => 'att',
	'multitext' => [ 'a', 'c' ], 'imgqty' => [ 'red' => 2, 'blu' => 1 ],
	'site' => 'https://example.com/order', 'cbx' => [ 'x' ], 'num' => '4',
] ] ] );
fieldb_report( 'composite add accepted', $resp->get_status() < 400 );
WC()->payment_gateways()->init();
$checkout = $dispatch( 'checkout', [
	'payment_method' => 'bacs',
	'billing_address' => [ 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'fieldb@example.invalid', 'address_1' => '1 Test Street', 'city' => 'Testville', 'postcode' => '90210', 'country' => 'US', 'state' => 'CA' ],
] );
fieldb_report( 'Store API checkout accepted', in_array( $checkout->get_status(), [ 200, 201 ], true ) );
$order_id = (int) ( $checkout->get_data()['order_id'] ?? 0 );
$order = wc_get_order( $order_id );
$state['orders'][] = $order_id;
update_option( $option, $state );
fieldb_report( 'order reloads', $order instanceof WC_Order );
$item = array_values( $order->get_items() )[0];
$stored = json_decode( $item->get_meta( '_opf_fields', true ), true );
fieldb_report( 'order persists all submitted selections', 'red' === ( $stored[ $gid ]['colorpick'] ?? '' ) && 'att' === ( $stored[ $gid ]['imgpick'] ?? '' ) && [ 'a', 'c' ] === ( $stored[ $gid ]['multitext'] ?? [] ) && 'https://example.com/order' === ( $stored[ $gid ]['site'] ?? '' ) && [ 'x' ] === ( $stored[ $gid ]['cbx'] ?? [] ) );
fieldb_report( 'order persists image quantities as structured map', '2' === (string) ( $stored[ $gid ]['imgqty']['quantities']['red'] ?? '' ) && '1' === (string) ( $stored[ $gid ]['imgqty']['quantities']['blu'] ?? '' ) );
fieldb_report( 'order persists section row + toggle', [ 'Row A' ] === array_values( $stored[ $gid ]['sectext'] ?? [] ) && '1' === ( $stored[ $gid ]['gate'] ?? '' ) );
fieldb_report( 'paragraphs never submit order data', ! isset( $stored[ $gid ]['note'] ) && ! isset( $stored[ $gid ]['richtxt'] ) );
fieldb_report( 'order public meta shows choice labels', '' !== (string) $item->get_meta( 'Pick a colour', true ) && '' !== (string) $item->get_meta( 'Image quantities', true ) );
// price: base10 + red3 + multi-a1.5 + imgqty-red $2 flat (WAPF parity) + cbx-x1 = 17.5
fieldb_report( 'order line priced server-side (base + swatch + multi + imgqty + checkbox)', abs( 17.5 - (float) $item->get_total() ) < 0.001 );
foreach ( [ 'get_content_html', 'get_content_plain' ] as $method ) {
	$email = new WC_Email_Customer_On_Hold_Order();
	$email->object = $order;
	$email->recipient = 'fieldb@example.invalid';
	$content = $email->$method();
	fieldb_report( "email $method shows swatch + image quantity labels", false !== strpos( $content, 'Pick a colour' ) && false !== strpos( $content, 'Red' ) && false !== strpos( $content, 'Image quantities' ) );
}
$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order );
fieldb_report( 'order again restores exact structured values', $again['opf_fields'] === $stored );
$cart->empty_cart();
$key = $cart->add_to_cart( $pid, 1, 0, [], $again );
fieldb_report( 'order again cart line preserves values', (bool) $key && $cart->get_cart_item( $key )['opf_fields'] === $stored );
$cart->calculate_totals();
fieldb_report( 'order again retains composite price', abs( 17.5 - (float) $cart->get_total( 'edit' ) ) < 0.001 );
$cart->empty_cart();

// 9) text/number constraints: WAPF renders text attrs but never enforces them
// server-side, so a wrong-length/pattern value is still accepted.
fieldb_report( 'text length/pattern values are browser-only (WAPF parity, server accepts)', $classic_validate( $val( [ 'mintext' => 'x' ] ) ) );
$resp = $dispatch( 'cart/add-item', [ 'id' => $pid, 'quantity' => 1, 'opf_fields' => $val( [ 'num' => 'not-a-number' ] ) ] );
$line = reset( $cart->cart_contents );
fieldb_observe( 'number non-numeric submission result', [ 'status' => $resp->get_status(), 'stored' => $line['opf_fields'][ $gid ]['num'] ?? null ] );
$cart->empty_cart();

file_put_contents( $report_file, wp_json_encode( $GLOBALS['fieldb_report'], JSON_PRETTY_PRINT ) );
echo "DONE commerce (failures: {$GLOBALS['fieldb_failures']})\n";
if ( $GLOBALS['fieldb_failures'] > 0 ) {
	exit( 1 );
}
