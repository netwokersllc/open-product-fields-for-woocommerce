<?php
/** Guarded real admin/conditional fixture. Every mutation is in a fresh owned SQLite clone. */
if ('1' !== getenv('OPF_IMAGE_ADMIN_ALLOW') || '/tmp/opf-image-admin-wp-20261003/' !== realpath(ABSPATH).'/' || !defined('FQDB') || 0 !== strpos(realpath(FQDB), realpath(ABSPATH).'/')) {
    throw new RuntimeException('Dedicated owned SQLite clone required.');
}
$out = getenv('OPF_IMAGE_ADMIN_OUT');
if (!$out || !is_dir($out)) { throw new RuntimeException('Existing evidence directory required.'); }
$key = 'opf_image_admin_fixture';
$phase = getenv('OPF_IMAGE_ADMIN_PHASE');
$state = get_option($key);
if ('setup' === $phase) {
    if ($state) { throw new RuntimeException('Fixture already exists.'); }
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $reference = WP_PLUGIN_DIR.'/advanced-product-fields-for-woocommerce-extended/';
    if ('3.1.5' !== get_plugin_data($reference.'advanced-product-fields-for-woocommerce-extended.php')['Version']) { throw new RuntimeException('Extended 3.1.5 required.'); }
    $state = ['baseline'=>['posts'=>(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}"),'users'=>(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}"),'plugins'=>get_option('active_plugins'),'home'=>get_option('home'),'siteurl'=>get_option('siteurl')], 'posts'=>[], 'attachments'=>[], 'files'=>[]];
    $upload = wp_upload_dir();
    require_once ABSPATH.'wp-admin/includes/image.php';
    foreach (['first'=>[17,113,199], 'second'=>[171,71,33]] as $name=>$rgb) {
        $file=$upload['path'].'/opf-admin-image-'.$name.'.png';
        if (file_exists($file)) { throw new RuntimeException('Existing fixture image refused.'); }
        $im=imagecreatetruecolor(800,400); imagefill($im,0,0,imagecolorallocate($im,...$rgb)); imagepng($im,$file); imagedestroy($im);
        $id=wp_insert_attachment(['post_title'=>'OPF admin image '.$name,'post_mime_type'=>'image/png','post_status'=>'inherit'],$file);
        wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file));
        update_post_meta($id,'_wp_attachment_image_alt','Media '.$name.' alt & "quoted"');
        $state['attachments'][$name]=['id'=>$id,'url'=>wp_get_attachment_url($id),'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true)];
        $state['files'][]=$file;
        foreach (wp_get_attachment_metadata($id)['sizes']??[] as $size) { $state['files'][]=$upload['path'].'/'.$size['file']; }
    }
    $product=new WC_Product_Simple(); $product->set_name('OPF image admin conditional proof'); $product->set_regular_price('10'); $product->set_status('publish'); $product->set_virtual(true); $pid=$product->save();
    $state['product']=$pid; $state['posts'][]=$pid;
    $raw=['id'=>'p_'.$pid,'type'=>'wapf_product','layout'=>['labels_position'=>'above','instructions_position'=>'field','mark_required'=>true],'variables'=>[],'rule_groups'=>[],'fields'=>[['id'=>'image_gate','label'=>'Image visibility switch','description'=>'','type'=>'text','required'=>false,'class'=>'','width'=>100,'parent_clone'=>[],'options'=>['default'=>'off'],'conditionals'=>[],'clone'=>['enabled'=>false],'pricing'=>['type'=>'fixed','amount'=>0,'enabled'=>false]]]];
    update_post_meta($pid,'_wapf_fieldgroup',$raw);
    $mapped=OPF\Engine\WapfMapper::map($raw); $mapped['group']['rule_groups']=[['rules'=>[['subject'=>'product','operator'=>'in','terms'=>[(string)$pid]]]]];
    $gid=OPF\Service\FieldGroups::save(0,$mapped['group'],['title'=>'OPF image admin proof','status'=>'publish']);
    $state['group']=$gid; $state['posts'][]=$gid;
    $page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'OPF image admin storefront','post_content'=>'[product_page id="'.$pid.'"]']); $state['page']=$page; $state['posts'][]=$page;
    $password=wp_generate_password(40,false); $uid=wp_insert_user(['user_login'=>'opf-image-owned-admin','user_pass'=>$password,'role'=>'administrator']);
    if (is_wp_error($uid)) { throw new RuntimeException($uid->get_error_message()); }
    $state['user']=$uid; file_put_contents($out.'/private-login.json',wp_json_encode(['user'=>'opf-image-owned-admin','password'=>$password])); chmod($out.'/private-login.json',0600);
    $state['runtime']=['wp'=>get_bloginfo('version'),'wc'=>WC_VERSION,'php'=>PHP_VERSION,'extended'=>'3.1.5','reference_img_sha256'=>hash_file('sha256',$reference.'views/frontend/fields/img.php'),'opf_renderer_sha256'=>hash_file('sha256',OPF_DIR.'includes/Service/Renderer.php')];
    foreach ($state['files'] as $file) { $state['asset_sha256'][$upload['url'].'/'.basename($file)]=hash_file('sha256',$file); }
    update_option($key,$state); file_put_contents($out.'/state.json',wp_json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo "ok fixture created\n"; return;
}
if (!$state) { throw new RuntimeException('Missing owned fixture.'); }
if ('snapshot' === $phase) {
    $group=OPF\Service\FieldGroups::group_from_post(get_post($state['group']));
    $data=['wapf'=>get_post_meta($state['product'],'_wapf_fieldgroup',true),'opf'=>$group->data];
    file_put_contents($out.'/persisted-'.getenv('OPF_IMAGE_ADMIN_STAGE').'.json',wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); echo "ok stored snapshot\n"; return;
}
if ('native-conditional' === $phase) {
    $raw=get_post_meta($state['product'],'_wapf_fieldgroup',true);
    if (1!==count($raw['fields'])) { throw new RuntimeException('Unexpected reference fixture.'); }
    $raw['fields'][]=['id'=>'native_image','label'=>'Conditional image & "quoted"','description'=>'','type'=>'img','required'=>false,'class'=>'','width'=>100,'parent_clone'=>[],'options'=>['image'=>$state['attachments']['second']['url'],'attachment'=>$state['attachments']['second']['id']],'conditionals'=>[['rules'=>[['field'=>'image_gate','condition'=>'==','value'=>'on']]]],'clone'=>['enabled'=>false],'pricing'=>['type'=>'fixed','amount'=>0,'enabled'=>false]];
    update_post_meta($state['product'],'_wapf_fieldgroup',$raw); echo "ok independent native conditional image fixture saved\n"; return;
}
if ('alt-update' === $phase) { update_post_meta($state['attachments']['second']['id'],'_wp_attachment_image_alt','Updated Media alt & "quoted"'); echo "ok clone media alt changed\n"; return; }
if ('cleanup' !== $phase) { throw new RuntimeException('Unknown phase.'); }
foreach ($state['posts'] as $id) { foreach (wp_get_post_revisions($id) as $rev) { wp_delete_post($rev->ID,true); } wp_delete_post($id,true); }
foreach ($state['attachments'] as $item) { wp_delete_attachment($item['id'],true); }
// The real admin session can leave wp-admin auto-drafts (+revisions) owned by
// the fixture user; they are run pollution, not baseline content.
foreach (get_posts(['post_type'=>'any','post_status'=>'any','author'=>$state['user'],'numberposts'=>-1,'fields'=>'ids']) as $polluted) { wp_delete_post($polluted,true); }
require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($state['user']); delete_option($key); @unlink($out.'/private-login.json');
$after=['posts'=>(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}"),'users'=>(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}"),'plugins'=>get_option('active_plugins'),'home'=>get_option('home'),'siteurl'=>get_option('siteurl')];
$remaining=array_values(array_filter($state['files'],'file_exists'));
$result=['remaining_files'=>$remaining,'fixture_option'=>get_option($key,false),'baseline'=>$state['baseline'],'after'=>$after,'restored'=>$state['baseline']===$after];
file_put_contents($out.'/cleanup.json',wp_json_encode($result,JSON_PRETTY_PRINT));
if ($remaining || !$result['restored'] || $result['fixture_option']) { throw new RuntimeException('Cleanup verification failed.'); }
echo "ok owned records/media/user removed and baseline restored\n";
