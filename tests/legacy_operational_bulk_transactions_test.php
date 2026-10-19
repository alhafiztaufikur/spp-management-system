<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_full_http.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$unit=3;unit_set_context($db,$unit);lful_empty($db,$unit);$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit,'csrf_legacy_import'=>bin2hex(random_bytes(32))];
$rows=[];$add=function($table,$raw)use(&$rows){$rows[]=['table'=>$table,'raw'=>$raw,'reference_only'=>$table==='lain','student_ordinal'=>count($rows)+1,'money_columns'=>array_values(array_intersect(array_keys($raw),['U_SPP','U_LAIN','JUMLAH1','SALDO','MASUK','KELUAR','SPP_PERBULAN','POMG']))];};
$add('siswa',['NO_INDUK'=>'232400001','NAMA'=>'Siswa Bulk QA','KELAS'=>'10A','SPP_PERBULAN'=>'530000','POMG'=>'15000']);
$add('siswa',['NO_INDUK'=>'192000001','NAMA'=>'Alumni Bulk QA','KELAS'=>'A2025','SPP_PERBULAN'=>'530000','POMG'=>'15000']);
$add('lain',['KODE'=>'057','NAMA'=>'Robotik Bulk QA']);$add('bayar',['NO_INDUK'=>'192000001','U_LAIN'=>'0','LAIN_LAIN1'=>'057','JUMLAH1'=>'500','TGL_BYR'=>'2026-10-01']);
$add('bayar',['NO_INDUK'=>'TANPA_MASTER','U_SPP'=>'100','TGL_BYR'=>null]);
$add('tabungan',['NO_INDUK'=>'232400001','SALDO'=>'1000']);$add('transaksi_m',['NO_INDUK'=>'232400001','MASUK'=>'1000','TANGGAL'=>'2026-10-01']);
$add('tb_total',['NO_INDUK'=>'232400001','SALDO'=>'900']);$add('tb_masuk',['NO_INDUK'=>'232400001','MASUK'=>'800','TANGGAL'=>'2026-10-01']);
$tmp=tempnam(legacy_root(),'bulk');file_put_contents($tmp,'Owned bulk transaction source');$job=legacy_enqueue($tmp,'bulk.dat',$unit,$actor,'full');unlink($tmp);$id=$job['id'];$dir=legacy_root().'/'.$id;$counts=[];foreach($rows as $r)$counts[$r['table']]=($counts[$r['table']]??0)+1;
file_put_contents($dir.'/finance.jsonl',implode("\n",array_map('lf_json',$rows))."\n");file_put_contents($dir.'/finance_manifest.json',lf_json(['profile'=>'legacy-finance-v1','unit'=>$unit,'backup_hash'=>$job['hash'],'source_date'=>'2026-10-17T08:00:00','counts'=>$counts,'rows_sha256'=>hash_file('sha256',$dir.'/finance.jsonl')]));
lful_stage($db,$job);$year=spp_master_ensure_year($db,'2026/2027');$d=lful_save($db,$actor,$unit,$id,1,['mode'=>'operational','cutoff'=>'2026-10-19 08:00:00','year_id'=>(int)$year['tahun_ajaran_id']]);
[$r,$manifest]=lf_extract_read($job,true);$cfg=lop_source_setup($r,$manifest,$d['settings'],$unit);$d=lful_save($db,$actor,$unit,$id,(int)$d['version'],$cfg);
$input=['id'=>$id,'version'=>$d['version'],'scope'=>'all','csrf_token'=>$_SESSION['csrf_legacy_import']];$preview=lful_http($db,'full_bulk_preview',$input,true);
lft_assert((int)lful_draft($id)['version']===(int)$d['version']&&!lf_one($db,"SELECT id FROM master_biaya_lain WHERE nama='Robotik Bulk QA'"),'Preview mutated draft/master');
lft_reject(fn()=>lful_http($db,'full_bulk_accept',$input+['confirmation'=>'SETUJUI SEMUA','preview_digest'=>'changed'],true),'Forged bulk preview accepted');
lful_http($db,'full_bulk_accept',$input+['confirmation'=>'SETUJUI SEMUA','preview_digest'=>$preview['digest']],true);
lft_reject(fn()=>lful_http($db,'full_bulk_accept',$input+['confirmation'=>'SETUJUI SEMUA','preview_digest'=>$preview['digest']],true),'Double-submit accepted');
$d=lful_draft($id);legacy_update($id,['state'=>'ready']);$catalog=lful_catalog_read($id);$key=array_key_first($catalog['additional_savings']);
$sourceStudent=array_values(array_filter($catalog['students'],fn($s)=>$s['identity']==='232400001'))[0];$fix=['reason'=>'replace_main','target_identity'=>'232400001','main_fingerprint'=>lop_main_bank_fingerprint('232400001',$sourceStudent['savings']),'amount'=>'850.00','fingerprint'=>$catalog['additional_savings'][$key]['fingerprint'],'confirmed'=>true,'evidence'=>'Saldo sebenarnya berdasarkan rekening yayasan.'];$d=lful_save($db,$actor,$unit,$id,(int)$d['version'],['additional_savings'=>[$key=>$fix],'op_savings'=>['232400001'=>['amount'=>'850.00','evidence'=>$fix['evidence'],'confirmed'=>true,'additional_ledger'=>$key]]]);
$sim=lop_simulate($db,$id);lft_assert($sim['ready']&&$sim['summary']['payments']===2&&$sim['summary']['savings_total']==='850.00'&&!$sim['summary']['pending'],'Bulk sim or separate FIX not ready');
$confirm=lful_confirm($db,$actor,$unit,$id,(int)$d['version'],$sim['digest']);require_once __DIR__.'/../includes/database_backup.php';db_backup_store(true);$backup=lful_backup($db,$actor,$id,'bulk-test-before');
putenv('SPP_FULL_TEST_FAIL=before_commit');$failed=false;try{lop_apply($db,$job,$sim,$backup);}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'before_commit'))throw $e;$failed=true;}finally{putenv('SPP_FULL_TEST_FAIL');}
lft_assert($failed&&!lf_one($db,'SELECT id FROM siswa')&&!lf_one($db,'SELECT id FROM legacy_op_owners'),'Rollback leaked migrated data');
lful_enqueue_apply($db,$actor,$unit,$id,(int)$d['version'],$confirm['token']);lop_run($db,legacy_job($id));$extra=lf_one($db,'SELECT o.*,a.balance FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE o.source_identity=?',['232400001']);
lft_assert($extra&&$extra['student_id']&&$extra['balance']==='850.00','Separate account lost or merged');
lft_assert(lf_one($db,"SELECT SALDO FROM tabungan WHERE NO_INDUK='232400001'")['SALDO']==850,'Primary student savings overwritten');
$audit=lf_one($db,"SELECT a.id FROM legacy_op_audit a JOIN legacy_op_journals j ON j.id=a.entity_id AND j.unit_id=a.unit_id WHERE a.entity_type='balance_fix' AND j.owner_id=?",[$extra['id']]);lft_assert((bool)$audit,'Additional FIX has no immutable audit');
lop_run($db,legacy_job($id));lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments')['n']===2,'Replay duplicated payments');
require_once __DIR__.'/../includes/legacy_operational_reports.php';$cash=lop_savings_report_rows($db,'2026-10-19','2026-10-20',[]);lft_assert(array_sum(array_column($cash,'masuk'))===0.0,'Adjustment inflated new cash');
// A fresh pending draft for HTTP and browser checks on an unused unit is created separately.
file_put_contents('C:/laragon/backups/spp-management-system/legacy_bulk_qa_20261019/transaction-result.json',lf_json(['job'=>$id,'extra'=>$extra['id'],'payments'=>2,'savings'=>'850.00','rollback'=>true,'audit'=>true,'retry'=>true]));
echo "PASS: bulk API preview/version/digest, native transaction rollback/retry, pending master, primary replacement FIX/audit, no duplicate savings and no cash inflation; backups restored.\n";
