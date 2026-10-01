<?php
/**
 * Prove static paragraph rendering, escaping, and non-submitted cart data.
 * Run only on a disposable WordPress/WooCommerce clone with OPF active:
 * OPF_PARAGRAPH_E2E_ALLOW=1 wp eval-file bin/e2e-paragraph-lifecycle.php
 *
 * @package open-product-fields-for-woocommerce
 */

if ( '1' !== getenv( 'OPF_PARAGRAPH_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_PARAGRAPH_E2E_ALLOW=1 only on a disposable WordPress clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( OPF\Service\CartIntegration::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}

$title = 'OPF static paragraph lifecycle fixture';
if ( get_page_by_title( $title, OBJECT, 'opf_field_group' ) ) {
	throw new RuntimeException( 'A fixture with this title already exists; refusing to modify it.' );
}

$product_id = 0;
$group_id = 0;
try {
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF static paragraph lifecycle fixture' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product_id = $product->save();
	if ( ! $product_id ) {
		throw new RuntimeException( 'Could not create the temporary WooCommerce product.' );
	}

	$data = OPF\Engine\FieldGroup::normalize( [
		'fields' => [
			[ 'id' => 'source', 'label' => 'Show note', 'type' => 'toggle' ],
			[
				'id' => 'care-note',
				'label' => '',
				'type' => 'paragraph',
				'content' => 'Keep <care> items cool.',
				'required' => true,
				'pricing' => [ 'type' => 'fixed', 'amount' => 50 ],
				'conditionals' => [ [ 'rules' => [ [ 'field' => 'source', 'operator' => 'is', 'value' => '1' ] ] ] ],
			],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $data, [ 'title' => $title, 'status' => 'publish' ] );
	if ( ! $group_id ) {
		throw new RuntimeException( 'Could not create the temporary OPF field group.' );
	}

	$group = OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) );
	ob_start();
	OPF\Service\Renderer::render_group( $group_id, $title, $group, 10.0 );
	$html = (string) ob_get_clean();
	if ( false === strpos( $html, 'Keep &lt;care&gt; items cool.' ) || false !== strpos( $html, 'name="opf[' . $group_id . '][care-note]"' ) || false !== strpos( $html, 'data-opf-price="50"' ) ) {
		throw new RuntimeException( 'Paragraph output was not escaped static content without an input or price.' );
	}

	$method = new ReflectionMethod( OPF\Service\CartIntegration::class, 'sanitize_submitted' );
	$method->setAccessible( true );
	$values = $method->invoke( null, wc_get_product( $product_id ), [ (string) $group_id => [ 'source' => '1', 'care-note' => 'forged value' ] ] );
	if ( [ (string) $group_id => [ 'source' => '1' ] ] !== $values ) {
		throw new RuntimeException( 'Submitted paragraph data was not excluded from cart values.' );
	}
	echo "ok escaped paragraph rendering, conditional field visibility, no price/input, and forged-value exclusion\n";
} finally {
	if ( $group_id ) {
		wp_delete_post( (int) $group_id, true );
	}
	if ( $product_id ) {
		wp_delete_post( (int) $product_id, true );
	}
}
