<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/legacy_finance_support.php';
$root = dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));
$fixture = json_decode(file_get_contents($root.'/operational-fixture.json'), true);
unit_set_context($koneksi, 1);
$_SESSION = ['admin_id'=>$fixture['actor'], 'admin_role'=>'super_admin', 'active_unit_id'=>1];
$id = $fixture['job'];
$d = lful_draft($id);
$before = lop_working_digest($koneksi, $id);
$group = $d['settings']['op_groups']['BTQ'];
$q = lful_store();
$statement = $q->prepare('SELECT * FROM full_drafts WHERE job=?');
$statement->execute([$id]);
$originalDraft = $statement->fetch(PDO::FETCH_ASSOC);
$statement->closeCursor();
try {
    $saved = lful_save($koneksi, $fixture['actor'], 1, $id, $d['version'], [
        'op_groups'=>['BTQ'=>['evidence'=>$group['evidence'], 'target_class_id'=>'']]
    ]);
    foreach (['kind','fingerprint','confirmed','savings_attested','obligations_attested'] as $key) {
        lft_assert(($saved['settings']['op_groups']['BTQ'][$key]??null) === ($group[$key]??null), 'Partial form discarded '.$key);
    }
    lft_assert($saved['simulation'] === null && $saved['confirmed'] === null, 'Draft retained stale confirmation');
    lft_reject(fn()=>lful_save($koneksi, $fixture['actor'], 1, $id, $d['version'], ['evidence'=>'Stale form']), 'Old version accepted');
    lft_reject(fn()=>lful_save($koneksi, $fixture['actor'], 1, $id, $saved['version'], ['op_groups'=>['BTQ'=>['kind'=>'academic']]]), 'Published group changed identity kind');
    lft_assert(lop_working_digest($koneksi, $id) === $before, 'Draft changed published data');
} finally {
    // Queue helpers use separate connections. Restore only this owned test draft explicitly.
    $q->prepare('UPDATE full_drafts SET version=?,settings=?,simulation=?,confirmed=? WHERE job=?')->execute([
        $originalDraft['version'],$originalDraft['settings'],$originalDraft['simulation'],$originalDraft['confirmed'],$id
    ]);
    $job = $d['job_info'];
    $q->prepare('UPDATE jobs SET state=?,stage=?,message=?,updated=? WHERE id=?')->execute([
        $job['state'],$job['stage'],$job['message'],$job['updated'],$id
    ]);
}
lft_assert(lful_draft($id)['version'] === $d['version'], 'Owned draft restoration failed');
echo "PASS: partial server forms preserve decisions, clear old confirmation, reject stale versions and protect published groups.\n";
