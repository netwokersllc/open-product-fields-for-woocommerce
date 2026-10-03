<?php
/** Real Extended informative-image reference. Run via wp eval-file on an owned disposable SQLite clone. */
if ('1' !== getenv('OPF_IMAGE_ALLOW') || 0 !== strpos(realpath(ABSPATH), '/tmp/opf-image-') || !defined('FQDB') || 0 !== strpos(realpath(FQDB), realpath(ABSPATH).'/')) {
    throw new RuntimeException('Owned disposable /tmp SQLite clone required.');
}
$out = getenv('OPF_IMAGE_OUT');
if (!$out || !is_dir($out)) { throw new RuntimeException('Existing output directory required.'); }
$key = 'opf_image_storefront_fixture';
$phase = getenv('OPF_IMAGE_PHASE');
if ('cleanup' === $phase) {
    $state = get_option($key);
    if (!$state) { throw new RuntimeException('Missing owned fixture.'); }
    foreach ($state['posts'] as $id) { wp_delete_post($id, true); }
    wp_delete_attachment($state['attachment'], true);
    delete_option($key);
    $remaining = array_filter(array_merge($state['posts'], [$state['attachment']]), static function ($id) { return null !== get_post($id); });
    $remaining_files = array_values(array_filter($state['files'], 'file_exists'));
    $after = ['posts'=>(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}"), 'active_plugins'=>get_option('active_plugins'), 'home'=>get_option('home'), 'siteurl'=>get_option('siteurl')];
    file_put_contents($out.'/cleanup.json', wp_json_encode(['remaining_posts' => array_values($remaining), 'remaining_files'=>$remaining_files, 'fixture_option' => get_option($key, false), 'baseline'=>$state['baseline'], 'after'=>$after, 'restored'=>$state['baseline']===$after], JSON_PRETTY_PRINT));
    if ($remaining || $remaining_files || get_option($key, false) || $state['baseline']!==$after) { throw new RuntimeException('Cleanup failed.'); }
    echo "ok owned posts/attachment/files removed\n";
    return;
}
if ('setup' !== $phase || get_option($key)) { throw new RuntimeException('Setup only once.'); }
if (!class_exists('SW_WAPF_PRO\\Includes\\Classes\\Field_Groups') || !class_exists('OPF\\Engine\\WapfMapper')) { throw new RuntimeException('Extended and OPF required.'); }
require_once ABSPATH.'wp-admin/includes/plugin.php';
$reference = WP_PLUGIN_DIR.'/advanced-product-fields-for-woocommerce-extended/';
if ('3.1.5' !== get_plugin_data($reference.'advanced-product-fields-for-woocommerce-extended.php')['Version']) { throw new RuntimeException('Extended 3.1.5 required.'); }
$upload = wp_upload_dir();
$baseline = ['posts'=>(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}"), 'active_plugins'=>get_option('active_plugins'), 'home'=>get_option('home'), 'siteurl'=>get_option('siteurl')];
$file = $upload['path'].'/opf-informative-image-proof.png';
if (file_exists($file)) { throw new RuntimeException('Refusing existing asset.'); }
$im = imagecreatetruecolor(800, 400);
imagefill($im, 0, 0, imagecolorallocate($im, 17, 113, 199));
imagepng($im, $file); imagedestroy($im);
$attachment = wp_insert_attachment(['post_title'=>'OPF image proof media','post_mime_type'=>'image/png','post_status'=>'inherit'], $file);
require_once ABSPATH.'wp-admin/includes/image.php';
wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $file));
$files = [$file];
foreach (wp_get_attachment_metadata($attachment)['sizes'] ?? [] as $size) { $files[]=$upload['path'].'/'.$size['file']; }
update_post_meta($attachment, '_wp_attachment_image_alt', 'Media alt & "quoted"');
$url = wp_get_attachment_url($attachment);
$product = new WC_Product_Simple();
$product->set_name('OPF informative image proof'); $product->set_regular_price('10'); $product->set_status('publish'); $product->set_virtual(true);
$pid = $product->save();
$fields = [];
foreach (['url'=>['image'=>$url.'?probe=one&second=two'], 'attachment'=>['image'=>$url,'attachment'=>$attachment], 'hostile'=>['image'=>'javascript:alert(1)" onerror="window.opfImageInjected=1'], 'quoted'=>['image'=>$url.'?probe=" onerror="window.opfImageInjected=2']] as $id=>$options) {
    $fields[] = ['id'=>'opi_'.$id, 'label'=>'Image label & "quoted" <b>text</b>', 'description'=>'', 'type'=>'img', 'required'=>false, 'class'=>'', 'width'=>100, 'parent_clone'=>[], 'options'=>$options, 'conditionals'=>[], 'clone'=>['enabled'=>false], 'pricing'=>['type'=>'fixed','amount'=>0,'enabled'=>false]];
}
$raw = ['id'=>'p_'.$pid, 'type'=>'wapf_product','layout'=>['labels_position'=>'above','instructions_position'=>'field','mark_required'=>true],'variables'=>[],'rule_groups'=>[],'fields'=>$fields];
update_post_meta($pid, '_wapf_fieldgroup', $raw);
$mapped = OPF\Engine\WapfMapper::map($raw);
$mapped['group']['rule_groups'] = [['rules'=>[['subject'=>'product','operator'=>'in','terms'=>[(string)$pid]]]]];
$gid = OPF\Service\FieldGroups::save(0, $mapped['group'], ['title'=>'OPF informative image proof', 'status'=>'publish']);
$page = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'OPF image proof host','post_content'=>'[product_page id="'.$pid.'"]']);
$state = ['posts'=>[$pid,$gid,$page], 'files'=>$files, 'baseline'=>$baseline, 'product'=>$pid, 'page'=>$page, 'group'=>$gid, 'attachment'=>$attachment, 'url'=>$url, 'source_sha256'=>hash_file('sha256',$file), 'ids'=>array_column($mapped['group']['fields'],'id'), 'mapping'=>$mapped, 'runtime'=>['wp'=>get_bloginfo('version'),'wc'=>WC_VERSION,'php'=>PHP_VERSION,'extended'=>'3.1.5','opf_renderer_sha256'=>hash_file('sha256',OPF_DIR.'includes/Service/Renderer.php'),'reference_img_sha256'=>hash_file('sha256',$reference.'views/frontend/fields/img.php')]];
$state['asset_sha256'] = [];
foreach ($files as $asset) { $state['asset_sha256'][$upload['url'].'/'.basename($asset)] = hash_file('sha256', $asset); }
update_option($key,$state);
file_put_contents($out.'/state.json', wp_json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "ok real product/attachment and side-by-side groups saved\n";
