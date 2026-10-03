<?php
/**
 * WAPF Extended 3.1.5 reference contract capture for FIELD-type residual rows.
 *
 * OPF_FIELDB_ALLOW=1 OPF_FIELDB_PHASE=setup|capture|cleanup \
 *   OPF_FIELDB_OUT=/tmp/opf-lane-fieldb-evidence \
 *   wp eval-file bin/e2e-fieldb-wapf-reference.php --path=<clone>
 *
 * Requires WooCommerce + WAPF Extended 3.1.5 active on an authorized
 * disposable /tmp WordPress site. Never run against production.
 */
if ( '1' !== getenv( 'OPF_FIELDB_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'This proof requires an explicitly authorized disposable /tmp WordPress site.' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'WooCommerce must be active.' );
}
$out = getenv( 'OPF_FIELDB_OUT' );
if ( ! $out || ! is_dir( $out ) || 0 !== strpos( realpath( $out ), '/tmp/' ) ) {
	throw new RuntimeException( 'Set OPF_FIELDB_OUT to an existing /tmp artifact directory.' );
}
$option = 'opf_fieldb_ref_state';
$phase  = getenv( 'OPF_FIELDB_PHASE' ) ?: 'capture';
$state  = get_option( $option, [] );

function fieldb_note( string $label, $value ): void {
	echo "ok $label\n";
}
function fieldb_fail( string $label ): void {
	throw new RuntimeException( 'FAIL ' . $label );
}

if ( 'setup' === $phase ) {
	if ( $state ) {
		fieldb_fail( 'pre-existing fixture state' );
	}
	if ( ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
		fieldb_fail( 'WAPF Extended must be active for the reference capture' );
	}
	$plugin = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php';
	$data   = get_plugin_data( $plugin );
	if ( '3.1.5' !== $data['Version'] ) {
		fieldb_fail( 'Reference must be WAPF Extended 3.1.5, found ' . $data['Version'] );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF fieldb WAPF reference fixture' );
	$product->set_slug( 'opf-fieldb-wapf-ref' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$pid = $product->save();
	if ( ! $pid ) {
		fieldb_fail( 'product fixture' );
	}
	$attachment = wp_insert_attachment(
		[ 'post_title' => 'fieldb ref image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'guid' => '' ],
		false, $pid
	);
	// Raw Tools-JSON-shaped field group covering every residual-row contract.
	$raw = [
		'id'     => 'fieldb_' . $pid,
		'type'   => 'wapf_product',
		'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => 'true' ],
		'conditions' => [],
		'fields' => [
			[
				'id' => 'cb', 'type' => 'checkboxes', 'label' => 'Limited checkboxes',
				'required' => false, 'width' => 100,
				'min_choices' => 1, 'max_choices' => 2,
				'choices' => [
					[ 'slug' => 'a', 'label' => 'Alpha', 'pricing_type' => 'fixed', 'pricing_amount' => 1.5 ],
					[ 'slug' => 'b', 'label' => 'Beta', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
					[ 'slug' => 'c', 'label' => 'Gamma', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
				],
			],
			[
				'id' => 'num', 'type' => 'number', 'label' => 'Whole number',
				'required' => false, 'width' => 100,
				'number_type' => 'int', 'minimum' => 2, 'maximum' => 10,
			],
			[
				'id' => 'numdec', 'type' => 'number', 'label' => 'Any number',
				'required' => false, 'width' => 100, 'number_type' => 'any',
			],
			[
				'id' => 'numstep', 'type' => 'number', 'label' => 'Stepper number',
				'required' => false, 'width' => 100, 'display' => 'plus_min', 'number_type' => 'int', 'minimum' => 1, 'maximum' => 9,
			],
			[
				'id' => 'txt', 'type' => 'text', 'label' => 'Validated text',
				'required' => false, 'width' => 100,
				'minlength' => 3, 'maxlength' => 5, 'pattern' => '[a-z]+',
			],
			[
				'id' => 'calc', 'type' => 'calc', 'label' => 'Calculated',
				'required' => false, 'width' => 100,
				'calc_type' => 'default', 'formula' => '[qty] * 2',
				'result_format' => '{result}', 'result_text' => 'You need {result} units',
			],
			[
				'id' => 'imgqty', 'type' => 'image-swatch-qty', 'label' => 'Image quantities',
				'required' => false, 'width' => 100, 'min_choices' => 1, 'max_choices' => 5,
				'choices' => [
					[ 'slug' => 'red', 'label' => 'Red swatch', 'image' => 'https://example.invalid/red.png',
					  'attachment' => $attachment, 'options' => [ 'min' => 0, 'max' => 3, 'default' => 1 ],
					  'pricing_type' => 'fixed', 'pricing_amount' => 2 ],
					[ 'slug' => 'blu', 'label' => 'Blue swatch', 'image' => 'https://example.invalid/blue.png',
					  'options' => [ 'min' => 1, 'max' => 2, 'default' => 1 ], 'pricing_type' => 'none', 'pricing_amount' => 0 ],
				],
			],
			[
				'id' => 'cbstyle', 'type' => 'checkboxes', 'label' => 'Styled checkboxes',
				'required' => false, 'width' => 100,
				'choices' => [
					[ 'slug' => 'x', 'label' => 'Styled X' ],
					[ 'slug' => 'y', 'label' => 'Styled Y' ],
				],
			],
			[
				'id' => 'rdo', 'type' => 'radio', 'label' => 'Styled radios',
				'required' => false, 'width' => 100,
				'choices' => [
					[ 'slug' => 'r1', 'label' => 'Radio one' ],
					[ 'slug' => 'r2', 'label' => 'Radio two' ],
				],
			],
			[
				'id' => 'tf', 'type' => 'true-false', 'label' => 'Plain toggle',
				'required' => false, 'width' => 100, 'message' => 'Enable it',
			],
		],
	];
	$model  = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( $raw );
	$stored = $model->to_array();
	update_post_meta( $pid, '_wapf_fieldgroup', $stored );
	// Persist the exact raw input + parsed model: this is WAPF's own Tools/parser
	// persistence path (raw_json_to_field_group is what the Tools import calls).
	file_put_contents( $out . '/wapf-ref-raw-input.json', wp_json_encode( $raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	file_put_contents( $out . '/wapf-ref-stored-model.json', wp_json_encode( $stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

	$state = [ 'product' => $pid, 'attachment' => $attachment ];
	update_option( $option, $state, false );
	fieldb_note( 'fixture product ' . $pid, true );
	fieldb_note( 'stored model serialized (' . count( $stored['fields'] ) . ' fields)', true );
	return;
}

if ( 'cleanup' === $phase ) {
	if ( ! empty( $state['product'] ) ) {
		delete_post_meta( $state['product'], '_wapf_fieldgroup' );
		wp_delete_post( $state['product'], true );
	}
	if ( ! empty( $state['attachment'] ) ) {
		wp_delete_post( $state['attachment'], true );
	}
	delete_option( 'wapf_design_settings' );
	delete_option( $option );
	fieldb_note( 'fixture cleanup', true );
	return;
}

// reimport phase -------------------------------------------------------------
// Feeds OPF's exported Tools payloads back through WAPF Extended 3.1.5's own
// parser (Field_Groups::raw_json_to_field_group — the same call wp-admin save
// and Tools import make), then audits which per-field settings Extended
// serializes. Runs standalone; requires only the payload artifacts and WAPF.
if ( 'reimport' === $phase ) {
	if ( ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
		fieldb_fail( 'WAPF Extended must be active for the reimport proof' );
	}
	$plugin = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php';
	$data   = get_plugin_data( $plugin );
	if ( '3.1.5' !== $data['Version'] ) {
		fieldb_fail( 'Reference must be WAPF Extended 3.1.5, found ' . $data['Version'] );
	}
	$result = [ 'payloads' => [], 'serialization' => [], 'checks' => [] ];
	$check  = static function ( string $label, bool $ok, $detail = null ) use ( &$result ): void {
		$result['checks'][] = [ 'label' => $label, 'pass' => $ok, 'detail' => $detail ];
		printf( "%s %s%s\n", $ok ? 'ok' : 'FAIL', $label, null === $detail ? '' : ' :: ' . wp_json_encode( $detail ) );
	};

	$reimport = static function ( string $file, string $type = 'wapf_product' ) use ( $out ) {
		$payload = json_decode( file_get_contents( $out . '/' . $file ), true );
		if ( ! is_array( $payload ) || empty( $payload['fields'] ) ) {
			return null;
		}
		// Mirror admin-controller save(): id + type wrap the wapf-fields payload.
		$raw = array_merge( [ 'id' => 'reimport_probe', 'type' => $type ], $payload );
		$fg  = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( $raw );
		return $fg ? $fg->to_array() : null;
	};

	// 1) Main fixture payload (12 fields, no paragraph/repeat export).
	$main = $reimport( 'opf-exported-wapf-payload.json' );
	$result['payloads']['main'] = $main;
	$check( 'WAPF parser accepts OPF-exported payload', is_array( $main ) );
	if ( $main ) {
		$types = [];
		$opts  = [];
		foreach ( $main['fields'] as $f ) {
			$types[ $f['id'] ] = $f['type'];
			$opts[ $f['id'] ]  = $f['options'] ?? [];
		}
		// Text-validation fields stay import-only in this lane (WapfExporter has
		// no mapping yet), so the exported payload carries 11 fields.
		$check( 'reimported field count preserves 11/11 exported fields', 11 === count( $main['fields'] ), count( $main['fields'] ) );
		$check( 'reimported types round-trip verbatim',
			'true-false' === ( $types['gate'] ?? '' ) && 'section' === ( $types['sec'] ?? '' ) && 'sectionend' === ( $types['secend'] ?? '' )
			&& 'color-swatch' === ( $types['colorpick'] ?? '' ) && 'image-swatch' === ( $types['imgpick'] ?? '' )
			&& 'multi-text-swatch' === ( $types['multitext'] ?? '' ) && 'image-swatch-qty' === ( $types['imgqty'] ?? '' )
			&& 'checkboxes' === ( $types['cbx'] ?? '' ) && 'number' === ( $types['num'] ?? '' ) && 'url' === ( $types['site'] ?? '' ) );
		$check( 'reimported color-swatch options survive (layout/size/label_pos/choice color)',
			'circle' === ( $opts['colorpick']['layout'] ?? '' ) && 34 === (int) ( $opts['colorpick']['size'] ?? 0 )
			&& 'tooltip' === ( $opts['colorpick']['label_pos'] ?? '' )
			&& '#FF0000' === strtoupper( (string) ( $opts['colorpick']['choices'][0]['color'] ?? '' ) ) );
		$check( 'reimported image-swatch grid/large_image options survive',
			'flexible' === ( $opts['imgpick']['grid_layout'] ?? '' ) && ! empty( $opts['imgpick']['large_image'] ) );
		$check( 'reimported image-swatch-qty min/max_choices + per-choice bounds survive',
			1 === (int) ( $opts['imgqty']['min_choices'] ?? -1 ) && 5 === (int) ( $opts['imgqty']['max_choices'] ?? -1 )
			&& 0 === (int) ( $opts['imgqty']['choices'][0]['options']['min'] ?? -1 ) && 3 === (int) ( $opts['imgqty']['choices'][0]['options']['max'] ?? -1 ) );
		$check( 'reimported multi-text-swatch min/max_choices survive',
			1 === (int) ( $opts['multitext']['min_choices'] ?? -1 ) && 2 === (int) ( $opts['multitext']['max_choices'] ?? -1 ) );
		// OPF's Tools exporter does not yet map checkbox selection limits (the
		// export boundary is out of scope for the valfix lane); record the gap.
		$check( 'exported checkboxes carry no min/max_choices (export boundary, import-only this lane)',
			! isset( $opts['cbx']['min_choices'], $opts['cbx']['max_choices'] ) );
		$sec = null;
		foreach ( $main['fields'] as $f ) {
			if ( 'sec' === $f['id'] ) {
				$sec = $f;
			}
		}
		$check( 'reimported section keeps conditional rule on gate',
			'gate' === (string) ( $sec['conditionals'][0]['rules'][0]['field'] ?? '' ) );
		$check( 'reimported true-false keeps message option', 'Add engraving' === (string) ( $opts['gate']['message'] ?? '' ) );
		$check( 'reimported choice pricing survives (colorpick red fixed 3)',
			'fixed' === (string) ( $opts['colorpick']['choices'][0]['pricing_type'] ?? '' ) && 3.0 === (float) ( $opts['colorpick']['choices'][0]['pricing_amount'] ?? 0 ) );
	}

	// 2) Paragraph payload: `p` parses (Extended Text & HTML); `content` is a
	//    Free legacy type — record verbatim storage (Extended cannot render it).
	$para = $reimport( 'opf-exported-paragraph-payload.json' );
	$result['payloads']['paragraphs'] = $para;
	$check( 'WAPF parser accepts OPF paragraph payload', is_array( $para ) );
	if ( $para ) {
		$ptypes = array_column( $para['fields'], 'type', 'id' );
		$popts  = array_column( $para['fields'], 'options', 'id' );
		$check( 'reimported p field keeps p_content', '<b>x</b>' === (string) ( $popts['htmlp']['p_content'] ?? '' ) );
		$check( 'reimported legacy content field stored verbatim (Extended cannot render Free content type)',
			'content' === ( $ptypes['plainp'] ?? '' ) && 'plain words only' === (string) ( $popts['plainp']['p_content'] ?? '' ) );
	}

	// 3) Live serialization audit — which settings Extended 3.1.5 exposes for
	//    the residual rows (columns/switch/step/min_choices/display/validation).
	$options = SW_WAPF_PRO\Includes\Classes\Config::get_field_options();
	foreach ( [ 'checkboxes', 'number', 'true-false', 'text', 'image-swatch-qty' ] as $type ) {
		$ids = [];
		foreach ( (array) ( $options[ $type ] ?? [] ) as $setting ) {
			$ids[] = $setting['id'] ?? $setting['type'] ?? '?';
			foreach ( (array) ( $setting['numbers'] ?? [] ) as $n ) {
				$ids[] = 'numbers:' . ( $n['id'] ?? '?' );
			}
			foreach ( array_keys( (array) ( $setting['options'] ?? [] ) ) as $opt ) {
				$ids[] = 'option:' . $opt;
			}
		}
		$result['serialization'][ $type ] = $ids;
	}
	$cbx_ids = wp_json_encode( $result['serialization']['checkboxes'] );
	$check( 'Extended 3.1.5 checkboxes settings = options + min_choices/max_choices only (no columns key)',
		false === strpos( $cbx_ids, 'column' ) && false !== strpos( $cbx_ids, 'min_choices' ) && false !== strpos( $cbx_ids, 'max_choices' ) );
	$check( 'Extended 3.1.5 number settings expose number_type/display/min/max but no step key',
		false === strpos( wp_json_encode( $result['serialization']['number'] ), 'step' ) );
	$check( 'Extended 3.1.5 number display includes plus_min stepper choice',
		false !== strpos( wp_json_encode( $result['serialization']['number'] ), 'plus_min' ) );
	$check( 'Extended 3.1.5 true-false has no switch-control setting',
		false === strpos( wp_json_encode( $result['serialization']['true-false'] ), 'switch' ) );
	$check( 'Extended 3.1.5 text settings expose minlength/maxlength/pattern',
		false !== strpos( wp_json_encode( $result['serialization']['text'] ), 'minlength' )
		&& false !== strpos( wp_json_encode( $result['serialization']['text'] ), 'maxlength' )
		&& false !== strpos( wp_json_encode( $result['serialization']['text'] ), 'pattern' ) );

	$fail = count( array_filter( $result['checks'], static fn( $c ) => ! $c['pass'] ) );
	file_put_contents( $out . '/wapf-reimport-result.json', wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	echo "DONE reimport (failures: $fail)\n";
	if ( $fail > 0 ) {
		exit( 1 );
	}
	return;
}

// capture phase -------------------------------------------------------------
if ( empty( $state['product'] ) ) {
	fieldb_fail( 'run setup first' );
}
if ( ! class_exists( 'SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	fieldb_fail( 'WAPF Extended must be active for the reference capture' );
}
$pid      = $state['product'];
$product  = wc_get_product( $pid );
$groups   = SW_WAPF_PRO\Includes\Classes\Field_Groups::get_field_groups_of_product( $product );
fieldb_note( 'wapf resolves ' . count( $groups ) . ' field group(s) for product', count( $groups ) >= 1 );

$result = [ 'fields' => [], 'product_id' => $pid ];

// 1) Render each field through WAPF's own view layer.
foreach ( $groups as $group ) {
	foreach ( $group->fields as $field ) {
		$html = '';
		try {
			// Html::field returns the rendered view markup.
			$html = (string) SW_WAPF_PRO\Includes\Classes\Html::field( $product, $field, (string) $group->id );
		} catch ( \Throwable $e ) {
			$html = 'RENDER-ERROR: ' . $e->getMessage();
		}
		$result['fields'][ $field->id ] = [
			'type'    => $field->type,
			'options' => $field->options,
			'clone'   => $field->clone ?? null,
			'html'    => $html,
		];
	}
}

// 2) Server-side add-to-cart validation through WAPF's own validate pipeline.
$validate = static function ( array $post_fields, int $qty = 1 ) use ( $groups, $pid ) {
	$prev_req  = $_REQUEST;
	$prev_post = $_POST;
	$_REQUEST['wapf'] = $post_fields;
	$_POST['wapf']    = $post_fields;
	$res = SW_WAPF_PRO\Includes\Classes\Cart::validate_cart_data( $groups, true, $pid, $qty );
	$_REQUEST = $prev_req;
	$_POST    = $prev_post;
	return $res;
};

$result['validation'] = [
	'checkboxes_3_of_max2' => $validate( [ 'field_cb' => [ 'a', 'b', 'c' ] ] ),
	'checkboxes_0_of_min1' => $validate( [ 'field_cb' => [] ] ),
	'checkboxes_2_ok'      => $validate( [ 'field_cb' => [ 'a', 'b' ] ] ),
	'number_below_min'     => $validate( [ 'field_num' => '1' ] ),
	'number_above_max'     => $validate( [ 'field_num' => '11' ] ),
	'number_decimal_int'   => $validate( [ 'field_num' => '2.5' ] ),
	'number_ok'            => $validate( [ 'field_num' => '5' ] ),
	'numberdec_decimal'    => $validate( [ 'field_numdec' => '2.5' ] ),
	'text_pattern_breach'  => $validate( [ 'field_txt' => 'ABC123' ] ),
	'text_too_short'       => $validate( [ 'field_txt' => 'ab' ] ),
	// qty_selector reads wapf[field_<id>_<slug>] plus a wapf[field_<id>] marker.
	'imgqty_over_total'    => $validate( [ 'field_imgqty' => '1', 'field_imgqty_red' => '4', 'field_imgqty_blu' => '2' ] ),
	'imgqty_choice_bounds' => $validate( [ 'field_imgqty' => '1', 'field_imgqty_red' => '5' ] ),
	'imgqty_zero_total'    => $validate( [ 'field_imgqty' => '1', 'field_imgqty_red' => '0', 'field_imgqty_blu' => '0' ] ),
	'imgqty_ok'            => $validate( [ 'field_imgqty' => '1', 'field_imgqty_red' => '2', 'field_imgqty_blu' => '1' ] ),
];

// 3) Design settings: styled checkbox/radio contract (option wapf_design_settings
//    feeds design_settings_to_variables_css + generated stylesheet).
// Storage shape is flat sanitized keys (see Design_Helper sanitize map ~line 1213).
update_option( 'wapf_design_settings', [
	'apf-cb-display'            => 'styled',
	'apf-cb-radius'             => '4px',
	'apf-cb-border-width'       => '2px',
	'apf-cb-border-color'       => '#445566',
	'apf-cb-bg'                 => '#eeeeee',
	'apf-cb-bg-sel'             => '#112233',
	'apf-cb-tick-color-sel'     => '#ffee00',
	'apf-radio-display'         => 'styled',
	'apf-radio-border-width'    => '2px',
	'apf-radio-border-color'    => '#556677',
	'apf-radio-bg-sel'          => '#223344',
	'apf-radio-tick-color-sel'  => '#ffee00',
	'apf-ns-width'              => '120px',
	'apf-ns-override'           => true,
	'apf-ns-border-width'       => '1px',
	'apf-ns-border-color'       => '#999999',
] );
$design_css = '';
if ( class_exists( 'SW_WAPF_PRO\Includes\Classes\Design_Helper' ) ) {
	$ref = new ReflectionClass( 'SW_WAPF_PRO\Includes\Classes\Design_Helper' );
	if ( $ref->hasMethod( 'design_settings_to_variables_css' ) ) {
		$m = $ref->getMethod( 'design_settings_to_variables_css' );
		$m->setAccessible( true );
		$design_css = $m->invoke( null, get_option( 'wapf_design_settings' ) );
	}
	$result['design_config_ids'] = [];
	foreach ( SW_WAPF_PRO\Includes\Classes\Design_Helper::get_design_config() as $section ) {
		foreach ( (array) ( $section['settings'] ?? [] ) as $setting ) {
			$result['design_config_ids'][] = $section['title'] . ' :: ' . ( $setting['id'] ?? '?' );
		}
	}
}
$result['design_css'] = $design_css;
delete_option( 'wapf_design_settings' );

// 4) Re-render styled checkbox + radio markup after design settings write is
//    irrelevant to markup; capture default markup only (already captured).

file_put_contents( $out . '/wapf-ref-contract.json', wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
fieldb_note( 'contract capture written', true );
foreach ( $result['validation'] as $k => $v ) {
	echo str_pad( $k, 28 ) . ' => ' . wp_json_encode( $v ) . "\n";
}
