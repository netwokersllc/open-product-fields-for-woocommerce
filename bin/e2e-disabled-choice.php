<?php
/** Guarded fixture setup and server validation for disabled choices. */
if ( '1' !== getenv( 'OPF_DISABLED_CHOICE_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required.' );
}

function disabled_choice_check( string $name, bool $pass ): void {
	if ( ! $pass ) {
		throw new RuntimeException( $name );
	}
	echo "ok $name\n";
}

$state = get_option( 'opf_product_e2e_state', [] );
disabled_choice_check( 'product targeting fixture exists', ! empty( $state['positive'] ) && ! empty( $state['selected'] ) );
$group_id = (int) $state['positive'];
$product_id = (int) $state['selected'];
$phase = getenv( 'OPF_DISABLED_CHOICE_E2E_PHASE' ) ?: 'setup';

if ( 'setup' === $phase ) {
	$negative_id = (int) $state['negative'];
	$negative_group = OPF\Service\FieldGroups::group_from_post( get_post( $negative_id ) );
	$negative_data = $negative_group->data;
	$negative_data['rule_groups'] = [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'not_in', 'terms' => [ (string) $product_id ] ] ] ] ];
	OPF\Service\FieldGroups::save( $negative_id, $negative_data, [ 'title' => 'OPF targeting negative', 'status' => 'publish' ] );
	OPF\Service\FieldGroups::save( $group_id, [
		'fields' => [
			[
				'id' => 'finish', 'label' => 'Finish', 'type' => 'select',
				'choices' => [
					[ 'slug' => 'unavailable', 'label' => 'Unavailable finish', 'pricing' => [ 'type' => 'fixed', 'amount' => 9 ] ],
					[ 'slug' => 'available', 'label' => 'Available finish', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
				],
			],
			[
				'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox',
				'choices' => [
					[ 'slug' => 'unavailable-extra', 'label' => 'Unavailable extra', 'pricing' => [ 'type' => 'fixed', 'amount' => 7 ] ],
					[ 'slug' => 'available-extra', 'label' => 'Available extra', 'pricing' => [ 'type' => 'fixed', 'amount' => 1 ] ],
				],
			],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	], [ 'title' => 'OPF unavailable-choice E2E', 'status' => 'publish' ] );
	update_option( 'opf_product_e2e_state', $state );
	update_option( 'opf_admin_only', 'no' );
	echo "SUCCESS disabled-choice fixture setup\n";
	return;
}

if ( 'verify' === $phase ) {
	$product = wc_get_product( $product_id );
	$entries = OPF\Service\FieldGroups::for_product( $product );
	$entry = current( array_filter( $entries, static function ( $candidate ) use ( $group_id ) {
		return (int) $candidate['id'] === $group_id;
	} ) );
	disabled_choice_check( 'target group matches fixture product', is_array( $entry ) );
	$group = $entry['group'];
	ob_start();
	OPF\Service\Renderer::render_group( (string) $group_id, 'Unavailable choices', $group, (float) $product->get_price() );
	$html = (string) ob_get_clean();
	disabled_choice_check( 'select option is rendered disabled', (bool) preg_match( '/<option value="unavailable"[^>]*disabled/', $html ) );
	disabled_choice_check( 'checkbox input is rendered disabled', (bool) preg_match( '/<input[^>]*value="unavailable-extra"[^>]*disabled/', $html ) );

	$_POST['opf'] = [ (string) $group_id => [ 'finish' => 'unavailable' ] ];
	disabled_choice_check( 'forged disabled select fails cart validation', ! OPF\Service\CartIntegration::validate_add_to_cart( true, $product_id, 1 ) );
	$_POST['opf'] = [ (string) $group_id => [ 'extras' => [ 'unavailable-extra' ] ] ];
	disabled_choice_check( 'forged disabled checkbox fails cart validation', ! OPF\Service\CartIntegration::validate_add_to_cart( true, $product_id, 1 ) );
	$_POST['opf'] = [ (string) $group_id => [ 'finish' => 'available', 'extras' => [ 'available-extra' ] ] ];
	disabled_choice_check( 'available choices pass cart validation', OPF\Service\CartIntegration::validate_add_to_cart( true, $product_id, 1 ) );
	unset( $_POST['opf'] );
	echo "SUCCESS disabled-choice server proof\n";
	return;
}

throw new RuntimeException( 'Unknown disabled-choice E2E phase.' );
