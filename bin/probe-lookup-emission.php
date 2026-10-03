<?php
// Probe: does Assets::enqueue_frontend emit window.OPF_LOOKUP_TABLES?
defined('ABSPATH') || exit;
use OPF\Service\FieldGroups;
use OPF\Service\Assets;
add_filter('wapf/lookup_tables', function () {
    return ['cutting' => ['10' => ['5' => 100]]];
});
$p = new WC_Product_Simple();
$p->set_name('lookup probe'); $p->set_regular_price('10'); $p->set_status('publish');
$pid = (int) $p->save();
$gid = FieldGroups::save(0, OPF\Engine\FieldGroup::normalize([
    'fields' => [['id' => 'q', 'type' => 'text', 'label' => 'Q', 'pricing' => ['type' => 'formula', 'formula' => 'lookuptable(cutting;10;5)', 'per_unit' => false]]],
    'rule_groups' => [['rules' => [['subject' => 'product', 'operator' => 'in', 'terms' => [(string) $pid]]]]],
]), ['title' => 'probe', 'status' => 'publish']);
FieldGroups::flush_cache();
ob_start();
Assets::enqueue_frontend(['g' => [['type' => 'text']]]);
$out = ob_get_clean();
echo (false !== strpos($out, 'OPF_LOOKUP_TABLES') ? 'EMITTED' : 'MISSING'), "\n";
if (preg_match('/OPF_LOOKUP_TABLES[^<]*/', $out, $m)) echo substr($m[0], 0, 120), "\n";
wp_delete_post($gid, true);
$p->delete(true);
FieldGroups::flush_cache();
