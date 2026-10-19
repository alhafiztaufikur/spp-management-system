<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_operational_corrections.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$unit=2;unit_set_context($db,$unit);lful_empty($db,$unit);
$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];
$records=[];$add=function($table,$raw)use(&$records){$records[]=['table'=>$table,'raw'=>$raw,'money_columns'=>array_values(array_intersect(array_keys($raw),['SPP_PERBULAN','POMG','U_SPP','U_LAIN','JUMLAH1','JUMLAH2','SALDO','MASUK','KELUAR'])),'reference_only'=>$table==='lain','student_ordinal'=>count($records)+1];};
$add('siswa',['NO_INDUK'=>'212200001','NAMA'=>'Siswa FIX','KELAS'=>'7A','SPP_PERBULAN'=>'530000','POMG'=>'15000']);
for($i=1;$i<=30;$i++)$add('siswa',['NO_INDUK'=>(string)(151600000+$i),'NAMA'=>'Alumni '.$i,'KELAS'=>$i<=26?'A2025':'B2025','SPP_PERBULAN'=>'345000','POMG'=>'15000']);
$add('siswa',['NO_INDUK'=>'test','NAMA'=>'test','KELAS'=>'OUT','SPP_PERBULAN'=>null,'POMG'=>null]);
$add('lain',['KODE'=>'057','NAMA'=>'Robotik Legacy QA']);$add('lain',['KODE'=>'099','NAMA'=>'Belum Digunakan QA']);
$add('bayar',['NO_INDUK'=>'212200001','U_LAIN'=>'700','LAIN_LAIN1'=>'057','JUMLAH1'=>'500','LAIN_LAIN2'=>'057','JUMLAH2'=>'200','TGL_BYR'=>'2026-10-01T12:00:00']);
$add('bayar',['NO_INDUK'=>'test','U_SPP'=>'100','TGL_BYR'=>null]);
foreach([['212200001','1000','800'],['test','500','500']] as [$nis,$balance,$incoming]){$add('tabungan',['NO_INDUK'=>$nis,'SALDO'=>$balance]);$add('transaksi_m',['NO_INDUK'=>$nis,'MASUK'=>$incoming,'KELUAR'=>null,'TANGGAL'=>'2026-10-01T10:00:00']);}
$source=tempnam(legacy_root(),'review');file_put_contents($source,'Owned review fixture');$job=legacy_enqueue($source,'review.dat',$unit,$actor,'full');unlink($source);$id=$job['id'];$dir=legacy_root().'/'.$id;
$counts=[];foreach($records as $r)$counts[$r['table']]=($counts[$r['table']]??0)+1;
file_put_contents($dir.'/finance.jsonl',implode("\n",array_map('lf_json',$records))."\n");file_put_contents($dir.'/finance_manifest.json',lf_json(['profile'=>'legacy-finance-v1','unit'=>$unit,'backup_hash'=>$job['hash'],'source_date'=>'2026-10-01T18:00:00','counts'=>$counts,'rows_sha256'=>hash_file('sha256',$dir.'/finance.jsonl')]));
lful_stage($db,$job);[$rows,$manifest]=lf_extract_read($job,true);$year=spp_master_ensure_year($db,'2026/2027');$cfg=lop_source_setup($rows,$manifest,['cutoff'=>'2026-10-17 18:00:00','year_id'=>(int)$year['tahun_ajaran_id']],$unit);$plan=lop_prepare($rows,$cfg,$unit,$manifest['source_date']);
foreach(['A2025','B2025'] as $code)$cfg['op_groups'][$code]=['kind'=>'alumni','graduation_year'=>2025,'confirmed'=>true,'source_accepted'=>true,'fingerprint'=>$plan['groups'][$code]['fingerprint'],'evidence'=>'Daftar alumni disahkan oleh yayasan.'];
$cfg['students']['TEST']['testing_confirmed']=true;$cfg['op_savings']['212200001']=['amount'=>'900','confirmed'=>true,'evidence'=>'Bukti saldo akhir rekening yayasan.'];
foreach(lful_catalog($rows) as $key=>$c)if(($c['source_code']??'')==='057'){$cfg['components'][$key]=['category'=>'biaya_baru:'.$c['proposal_key'],'confirmed'=>true,'reason'=>'Mengikuti master sumber yang disahkan.'];$cfg['new_masters'][$c['proposal_key']]=['name'=>$c['name'],'nominal'=>'','tariff_pending'=>true,'confirmed'=>true,'reason'=>'Tarif belum tersedia pada sumber Legacy.'];}
$d=lful_save($db,$actor,$unit,$id,1,$cfg);$sim=lful_simulate($db,$id);lft_assert($sim['ready']&&$sim['summary']['adjustments']===1&&$sim['summary']['alumni']===30,'Review simulation not ready');
lft_assert(!lf_one($db,"SELECT id FROM master_biaya_lain WHERE nama='Robotik Legacy QA'")&&!lf_one($db,"SELECT id FROM master_kelas WHERE kode_rombel='Alumni2025'"),'Draft created operational masters');
$confirm=lful_confirm($db,$actor,$unit,$id,(int)$d['version'],$sim['digest']);require_once __DIR__.'/../includes/database_backup.php';db_backup_store(true);$backup=lful_backup($db,$actor,$id,'test-before');
putenv('SPP_FULL_TEST_FAIL=before_commit');$failed=false;try{lop_apply($db,$job,$sim,$backup);}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'before_commit'))throw $e;$failed=true;}finally{putenv('SPP_FULL_TEST_FAIL');}
lft_assert($failed&&!lf_one($db,'SELECT id FROM siswa')&&!lf_one($db,"SELECT id FROM master_kelas WHERE kode_rombel='Alumni2025'"),'Rollback leaked student/class');
lful_enqueue_apply($db,$actor,$unit,$id,(int)$d['version'],$confirm['token']);lop_run($db,legacy_job($id));
$class=lf_one($db,"SELECT * FROM master_kelas WHERE kode_rombel='Alumni2025'");lft_assert($class&&$class['legacy_kind']==='alumni'&&!$class['is_active']&&class_label($class)==='Alumni2025','Alumni class is PSB/active');
lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM siswa WHERE master_kelas_id=?',[$class['id']])['n']===30,'Alumni source groups did not share class');
$test=lf_one($db,"SELECT o.*,g.label FROM legacy_op_owners o JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id WHERE o.source_identity='TEST'");lft_assert($test&&!$test['student_id']&&$test['label']==='Rekening Uji Legacy','Test became fake student');
$master=lf_one($db,"SELECT * FROM master_biaya_lain WHERE nama='Robotik Legacy QA'");lft_assert($master['legacy_tariff_pending']&&!$master['is_active']&&(float)$master['nominal']===0.0,'Pending tariff not inactive');
lft_reject(fn()=>lf_query($db,'UPDATE master_biaya_lain SET is_active=1 WHERE id=?',[$master['id']]),'DB allowed tariffless activation');
$o=lf_one($db,"SELECT * FROM legacy_op_owners WHERE source_identity='212200001'");$account=lf_one($db,'SELECT * FROM legacy_op_accounts WHERE owner_id=?',[$o['id']]);lft_assert($account['source_balance']==='1000.00'&&$account['opening']==='900.00','Source/FIX balances lost');
lop_check_result($db,$id);$journalCount=lf_one($db,'SELECT COUNT(*) n FROM legacy_op_journals')['n'];lop_run($db,legacy_job($id));lft_assert($journalCount===lf_one($db,'SELECT COUNT(*) n FROM legacy_op_journals')['n'],'Retry duplicated adjustment');
require_once __DIR__.'/../includes/legacy_operational_reports.php';$report=lop_savings_report_rows($db,'2026-10-01','2026-11-01',[]);lft_assert(count($report)===3&&array_sum(array_column($report,'masuk'))===1300.0&&array_sum(array_column($report,'delta_opening'))===100.0,'Adjustment counted as savings cash or missing from balances');
$payment=lf_one($db,'SELECT * FROM legacy_op_payments WHERE owner_id=?',[$o['id']]);lft_assert($payment['total']==='700.00','Fee counted twice');
$session=bin2hex(random_bytes(16));session_save_path(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/sessions');session_id($session);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit,'csrf_legacy_import'=>bin2hex(random_bytes(24))];session_write_close();
file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/review-fixture.json',lf_json(['job'=>$id,'actor'=>$actor,'session'=>$session,'base'=>getenv('SPP_TEST_BASE_URL'),'owner'=>$o['id']]));
echo "PASS: rollback, shared alumni classes, nonstudent test account, pending master tariff, exact FIX adjustment, no cash inflation, backup restore and idempotent replay.\n";
