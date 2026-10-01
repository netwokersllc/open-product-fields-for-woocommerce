<?php
/** Invoke the installed WAPF product predicate in an inactive-plugin disposable site. */
if ( '1' !== getenv( 'OPF_PRODUCT_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
 throw new RuntimeException( 'Explicit disposable /tmp authorization required.' );
}
$source = getenv( 'OPF_WAPF_SOURCE_PATH' );
if ( ! $source || 0 !== strpos( realpath( $source ), '/tmp/' ) ) { throw new RuntimeException( 'Disposable source path required.' ); }
require_once $source . '/includes/classes/class-conditions.php';
if ( '1' === getenv( 'OPF_WAPF_EXTENDED' ) ) { class_alias( 'SW_WAPF_PRO\Includes\Classes\Conditions', 'SW_WAPF\Includes\Classes\Conditions' ); }
$method = new ReflectionMethod( 'SW_WAPF\Includes\Classes\Conditions', 'check' );
$method->setAccessible( true );
$s = get_option( 'opf_product_e2e_state' );
$extended = '1' === getenv( 'OPF_WAPF_EXTENDED' );
foreach ( [ 'selected' => true, 'unselected' => false, 'parent' => true, 'otherparent' => false, 'variation' => $extended, 'othervariation' => false ] as $key => $expected ) {
 foreach ( [ 'product', 'products', '!product', '!products' ] as $condition ) {
  $pass = $method->invoke( null, $condition, [ $s['selected'], $s['parent'] ], wc_get_product( $s[$key] ) );
  if ( $pass !== ( '!' === $condition[0] ? ! $expected : $expected ) ) { throw new RuntimeException( "$key $condition unexpected source result" ); }
  echo 'ok ' . ( $extended ? 'Extended' : 'Free' ) . " $key $condition\n";
 }
}
echo "SUCCESS installed WAPF source product predicates\n";
