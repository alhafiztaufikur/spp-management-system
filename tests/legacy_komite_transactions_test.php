<?php
/** New/old enqueue rules and actual migration publication, isolated MySQL only. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
if((int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Owned isolated server required.');
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if($koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Server UUID differs.');
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;unit_set_context($db,0);$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin'];$jobs=[];
foreach([1=>'operational',2=>'full',3=>'old_queued'] as $unit=>$kind){
    $_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);lful_empty($db,$unit);$grade=[1=>'1A',2=>'7A',3=>'10A'][$unit];$nis='21220000'.$unit;
    $records=[['table'=>'siswa','raw'=>['NO_INDUK'=>$nis,'NAMA'=>'Siswa Uji Komite '.$unit,'KELAS'=>$grade,'SPP_PERBULAN'=>'530000','POMG'=>'15000'],'reference_only'=>false,'student_ordinal'=>1,'money_columns'=>['SPP_PERBULAN','POMG']],['table'=>'bayar','raw'=>['NO_INDUK'=>$nis,'KELAS'=>$grade,'U_SPP'=>'530000','U_SORGA'=>'15000','BULAN'=>'10','TAHUN'=>'2026','TGL_BYR'=>'2026-10-14T08:25:09'],'reference_only'=>false,'money_columns'=>['U_SPP','U_SORGA']]];
    $tmp=tempnam(legacy_root(),'komite-');file_put_contents($tmp,'Owned Komite fixture '.$kind);$job=legacy_enqueue($tmp,'komite-'.$kind.'.dat',$unit,$actor,'full');unlink($tmp);$id=$job['id'];$dir=legacy_root().'/'.$id;
    lft_assert(is_file($dir.'/migration-rules.json'),'New enqueue did not capture its rules');if($kind==='old_queued')unlink($dir.'/migration-rules.json');
    file_put_contents($dir.'/finance.jsonl',implode("\n",array_map('lf_json',$records))."\n");file_put_contents($dir.'/finance_manifest.json',lf_json(['profile'=>'legacy-finance-v1','unit'=>$unit,'backup_hash'=>$job['hash'],'source_date'=>'2026-10-17T08:00:00','counts'=>['siswa'=>1,'bayar'=>1],'rows_sha256'=>hash_file('sha256',$dir.'/finance.jsonl')]));
    if($kind!=='full')file_put_contents($dir.'/operational-request.json','{"mode":"operational"}');lful_stage($db,$job);$draft=lful_draft($id);$catalog=lful_catalog_read($id);
    lft_assert(lcs_komite_enabled($draft['settings'])===($kind!=='old_queued'),'New rule retroactively enabled on an old queued job');
    $profile=lful_profile_path($unit,$catalog['manifest']);file_put_contents($profile,lf_json(['rules_version'=>LFUL_RULES_VERSION,'unit'=>$unit,'components'=>['bayar.U_SORGA'=>['category'=>lcs_sorga_history_key()]]]));
    $suggestions=lful_suggestions($db,$id);lft_assert($suggestions['components']['bayar.U_SORGA']['suggested_category']===($kind==='old_queued'?lcs_sorga_history_key():'komite'),'Old automatic profile overrode new meaning');
    if($kind!=='old_queued')lft_assert($draft['settings']['components']['bayar.U_SORGA']['category']==='komite'&&$draft['settings']['components']['bayar.U_SORGA']['migration_rule']['source_column']==='U_SORGA','Staged mapping metadata missing');
    else lft_assert($draft['settings']['components']['bayar.U_SORGA']['category']===lcs_sorga_history_key(),'Old queued mapping changed');
    $jobs[$unit]=['job'=>$job,'draft'=>$draft,'nis'=>$nis];
}
// Publish one new-format operational fixture and prove both atomic rollback and retry.
$unit=1;$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);['job'=>$job,'draft'=>$draft,'nis'=>$nis]=$jobs[$unit];$id=$job['id'];$year=spp_master_ensure_year($db,'2026/2027');
$draft=lful_save($db,$actor,$unit,$id,(int)$draft['version'],['cutoff'=>'2026-10-19 11:00:00','year_id'=>(int)$year['tahun_ajaran_id'],'students'=>[$nis=>['coverage'=>[['category'=>'komite','period'=>'2026-10','principal'=>'15000.00','disposition'=>'settled','evidence'=>'Komite bulan Oktober terbukti pada kolom U_SORGA.']]]]]);
[$records]=lf_extract_read($job,true,true);$bulk=lop_bulk_patch($records,lful_catalog_read($id),$draft['settings'],$unit,'all');$draft=lful_save($db,$actor,$unit,$id,(int)$draft['version'],$bulk['patch']);$sim=lop_simulate($db,$id);lft_assert($sim['ready']&&$sim['summary']['payment_total']==='545000.00'&&$sim['summary']['new_masters']===0,'New Komite simulation failed: '.lf_json($sim['issues']));$confirmation=lful_confirm($db,$actor,$unit,$id,(int)$draft['version'],$sim['digest']);
require_once __DIR__.'/../includes/database_backup.php';db_backup_store(true);$backup=lful_backup($db,$actor,$id,'komite-test-before');putenv('SPP_FULL_TEST_FAIL=before_commit');$failed=false;
try{lop_apply($db,$job,$sim,$backup);}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'before_commit'))throw $e;$failed=true;}finally{putenv('SPP_FULL_TEST_FAIL');}
lft_assert($failed&&!lf_one($db,'SELECT id FROM siswa')&&!lf_one($db,'SELECT id FROM legacy_op_payments'),'Interrupted Komite migration leaked data');
lful_enqueue_apply($db,$actor,$unit,$id,(int)$draft['version'],$confirmation['token']);lop_run($db,legacy_job($id));$digest=lop_working_digest($db,$id);lop_run($db,legacy_job($id));lft_assert(lop_working_digest($db,$id)===$digest&&(int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments')['n']===1,'Retry duplicated or changed migrated data');
$payment=lf_one($db,'SELECT items FROM legacy_op_payments WHERE batch_id=?',[$id]);$items=json_decode($payment['items'],true);$komite=array_values(array_filter($items,fn($i)=>$i['column']==='U_SORGA'))[0];lft_assert($komite['category']==='komite'&&$komite['label']==='Komite'&&$komite['migration']['source_code']==='U_SORGA'&&!empty($komite['obligation_id']),'Stored Komite metadata or monthly binding lost');
lop_check_result($db,$id);file_put_contents(getenv('SPP_QA_DIR').'/migration-result.json',lf_json(['job'=>$id,'rules'=>LOP_VERSION,'payment_total'=>'545000.00','rollback'=>true,'retry'=>true]));
echo "PASS: new/full/old enqueue rules, source-bound metadata, automatic-profile precedence, Komite coverage, atomic publication and idempotent retry on isolated MySQL.\n";
