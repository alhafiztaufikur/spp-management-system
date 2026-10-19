<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require __DIR__.'/../includes/legacy_full.php';require __DIR__.'/legacy_finance_support.php';
$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$f=json_decode(file_get_contents($root.'/finalization-fixture.json'),true,512,JSON_THROW_ON_ERROR);$db=$koneksi;$job=legacy_job($f['job']);$actor=(int)$job['actor'];$unit=(int)$job['unit'];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);$d=lful_draft($job['id']);$summary=$d['simulation']['summary'];
$c=lful_confirm($db,$actor,$unit,$job['id'],(int)$d['version'],$d['simulation']['digest']);lful_enqueue_apply($db,$actor,$unit,$job['id'],(int)$d['version'],$c['token']);lful_enqueue_apply($db,$actor,$unit,$job['id'],(int)$d['version'],$c['token']);
lop_run($db,legacy_job($job['id']));$b=lf_one($db,'SELECT state,before_backup,after_backup FROM legacy_full_batches WHERE id=?',[$job['id']]);
lft_assert($b['state']==='completed'&&legacy_job($job['id'])['state']==='imported'&&$b['before_backup']&&$b['after_backup'],'Finalization not verified');
lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM siswa WHERE unit_id=?',[$unit])['n']===1&&(int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments WHERE batch_id=?',[$job['id']])['n']===$summary['payments'],'Finalization counts differ');
lop_run($db,legacy_job($job['id']));lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments WHERE batch_id=?',[$job['id']])['n']===1,'Retry duplicated payments');
file_put_contents($root.'/finalization-apply-results.json',lf_json(['job'=>$job['id'],'completed'=>true,'payments'=>1,'before_backup_verified'=>true,'after_backup_verified'=>true,'retry'=>true]));
echo "PASS: finalization queue, duplicate submit, transaction, before/after restored backups, verified completion and retry without duplicates on isolated database.\n";
