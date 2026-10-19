<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$id=$argv[1]??'';$d=lful_draft($id);$unit=(int)$d['job_info']['unit'];unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
$before=lful_draft($id);$s=lful_suggestions($koneksi,$id);$ids=[];$expected=[];
foreach($s['students'] as $key=>$student){if($student['source_count']!==1)continue;$fee=legacy_source_komite($student['source_fees']);if($fee===null)continue;$ids[]=(string)$key;$expected[(string)$key]=$fee;lft_assert($student['suggested']['komite']===$fee,'Individual Komite suggestion lost source POMG');if(count($ids)===3)break;}
lft_assert(count($ids)===3,'Actual backup missing POMG fixtures');
$input=['type'=>'students','identities'=>$ids,'replace'=>true,'settings'=>['kind'=>'alumni','komite'=>'999','komite_from_source'=>true],'evidence'=>'Source POMG confirmed as monthly Komite by foundation.'];
$p=lful_group_preview($koneksi,$id,(int)$d['version'],$input);lft_assert($p['count']===3,'Source group preview count');foreach($p['samples'] as $row)lft_assert($row['after']['komite']===$expected[$row['identity']],'Group replaced per-student POMG with a shared zero/value');
$input['settings']['komite_from_source']=false;$manual=lful_group_preview($koneksi,$id,(int)$d['version'],$input);foreach($manual['samples'] as $row)lft_assert($row['after']['komite']==='999','Explicit group override ignored');
lft_assert($manual['digest']!==$p['digest'],'Confirmation did not bind source/default policy');
lft_reject(fn()=>lful_group_preview($koneksi,$id,(int)$d['version']-1,$input),'Stale review accepted');
lft_assert(lful_draft($id)===$before,'Read-only POMG preview modified draft');
echo "PASS: real backup POMG individual/group proposals, explicit override, version/digest binding and unchanged draft.\n";
