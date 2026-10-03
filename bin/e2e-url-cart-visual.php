<?php
/** Browser cart evidence fixture; owned SQLite clone only. */
if ('1' !== getenv('OPF_URL_CART_ALLOW') || 0 !== strpos(realpath(ABSPATH), '/tmp/opf-url-cart-') || !defined('FQDB') || 0 !== strpos(realpath(FQDB), realpath(ABSPATH) . '/')) {
    throw new RuntimeException('Owned disposable URL cart SQLite clone required.');
}
$key = 'opf_url_cart_visual_state';
global $wpdb;
$out = getenv('OPF_URL_CART_OUT');
if (!$out || !is_dir($out)) { throw new RuntimeException('Existing artifact directory required.'); }
if ('cleanup' === getenv('OPF_URL_CART_PHASE')) {
    $state = get_option($key);
    if (!$state) { throw new RuntimeException('Fixture missing.'); }
    foreach ($state['posts'] as $id) { wp_delete_post($id, true); }
    foreach ($state['group_statuses'] as $id => $status) { wp_update_post(['ID' => $id, 'post_status' => $status]); }
    if (!empty($state['user'])) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($state['user']); }
    update_option('active_plugins', $state['baseline']['active_plugins']);
    delete_option($key);
    $after = ['posts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"), 'active_plugins' => get_option('active_plugins')];
    $clean = $after === $state['baseline'] && false === get_option($key, false);
    file_put_contents($out . '/cleanup.json', wp_json_encode(['baseline' => $state['baseline'], 'after' => $after, 'restored' => $clean], JSON_PRETTY_PRINT));
    if (!$clean) { throw new RuntimeException('Cleanup mismatch.'); }
    echo "ok URL cart fixtures removed, baseline restored\n";
    return;
}
if (get_option($key)) { throw new RuntimeException('Fixture already exists.'); }
$baseline = ['posts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"), 'active_plugins' => get_option('active_plugins')];
$group_statuses = [];
foreach (get_posts(['post_type' => ['opf_field_group', 'wapf_product', 'wapf'], 'post_status' => 'publish', 'numberposts' => -1]) as $group) {
    $group_statuses[$group->ID] = $group->post_status;
    wp_update_post(['ID' => $group->ID, 'post_status' => 'draft']);
}
$product = new WC_Product_Simple();
$product->set_name('Native URL cart visual fixture');
$product->set_slug('url-cart-visual');
$product->set_status('publish');
$product->set_regular_price('10');
$product->set_virtual(true);
$pid = $product->save();
$gid = OPF\Service\FieldGroups::save(0, ['fields' => [['id' => 'profile', 'type' => 'url', 'label' => 'Profile URL', 'required' => true]], 'rule_groups' => [['rules' => [['subject' => 'product', 'operator' => 'in', 'terms' => [(string)$pid]]]]]], ['title' => 'URL cart visual fixture', 'status' => 'publish']);
$raw = ['id' => 'p_' . $pid, 'type' => 'wapf_product', 'fields' => [['id' => 'profile', 'type' => 'url', 'label' => 'Profile URL', 'required' => true, 'default' => '', 'width' => 100, 'class' => '', 'description' => '', 'pricing' => ['enabled' => false, 'type' => 'fixed', 'amount' => 0], 'conditionals' => [], 'options' => []]], 'conditions' => [], 'rule_groups' => [], 'layout' => ['labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true], 'variables' => []];
update_post_meta($pid, '_wapf_fieldgroup', $raw);
$classic = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'URL classic cart', 'post_name' => 'url-classic-cart', 'post_content' => '[woocommerce_cart]']);
$uid = wp_create_user('opf_url_cart_visual', wp_generate_password(32), 'url-cart@example.invalid');
if (is_wp_error($uid)) { throw new RuntimeException('Fixture user creation failed.'); }
(new WP_User($uid))->set_role('administrator');
$state = ['product' => $pid, 'group' => $gid, 'classic_page' => $classic, 'user' => $uid, 'posts' => [$pid, $gid, $classic], 'baseline' => $baseline, 'group_statuses' => $group_statuses];
update_option($key, $state);
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$state['runtime'] = ['wp' => get_bloginfo('version'), 'wc' => WC_VERSION, 'php' => PHP_VERSION];
foreach (['advanced-product-fields-for-woocommerce/advanced-product-fields-for-woocommerce.php', 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php'] as $file) {
    $state['runtime'][$file] = ['version' => get_plugin_data(WP_PLUGIN_DIR . '/' . $file)['Version'], 'sha256' => hash_file('sha256', WP_PLUGIN_DIR . '/' . $file)];
}
file_put_contents($out . '/state.json', wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "ok OPF and WAPF fixture, classic cart page created\n";
