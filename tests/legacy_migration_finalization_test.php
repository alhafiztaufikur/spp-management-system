<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require __DIR__.'/../includes/legacy_full.php';require __DIR__.'/../includes/legacy_full_http.php';require __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$unit=3;$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);lful_empty($db,$unit);
$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit,'csrf_legacy_import'=>str_repeat('c',64)];
$rows=[['table'=>'siswa','raw'=>['NO_INDUK'=>'262700001','NAMA'=>'Finalisasi Terisolasi','KELAS'=>'10A','SPP_PERBULAN'=>'530000','POMG'=>'15000'],'money_columns'=>['SPP_PERBULAN','POMG']],['table'=>'bayar','raw'=>['NO_INDUK'=>'262700001','U_SPP'=>'530000','TGL_BYR'=>'2026-10-01'],'money_columns'=>['U_SPP']]];
foreach($rows as $n=>&$r){$r['reference_only']=false;$r['student_ordinal']=$n+1;}unset($r);
$tmp=tempnam(legacy_root(),'final');file_put_contents($tmp,'Owned finalization source '.random_bytes(12));$job=legacy_enqueue($tmp,'finalization.dat',$unit,$actor,'full');unlink($tmp);$id=$job['id'];$dir=legacy_root().'/'.$id;
file_put_contents($dir.'/finance.jsonl',implode("\n",array_map('lf_json',$rows))."\n");file_put_contents($dir.'/finance_manifest.json',lf_json(['profile'=>'legacy-finance-v1','unit'=>$unit,'backup_hash'=>$job['hash'],'source_date'=>'2026-10-17T08:00:00','counts'=>['siswa'=>1,'bayar'=>1],'rows_sha256'=>hash_file('sha256',$dir.'/finance.jsonl')]));
lful_stage($db,$job);[$source,$manifest]=lf_extract_read($job,true);$year=spp_master_ensure_year($db,'2026/2027');$cfg=lop_source_setup($source,$manifest,['cutoff'=>'2026-10-19 08:00:00','year_id'=>(int)$year['tahun_ajaran_id']],$unit);$d=lful_save($db,$actor,$unit,$id,1,$cfg);
$input=['id'=>$id,'version'=>$d['version'],'scope'=>'all','csrf_token'=>$_SESSION['csrf_legacy_import']];$preview=lful_http($db,'full_bulk_preview',$input,true);lful_http($db,'full_bulk_accept',$input+['preview_digest'=>$preview['digest'],'confirmation'=>'SETUJUI SEMUA'],true);legacy_update($id,['state'=>'ready']);
$sim=lop_simulate($db,$id);lft_assert($sim['ready']&&$sim['summary']['payments']===1&&$sim['summary']['pending']===0,'Fixture not ready');$digest=$sim['digest'];unset($sim,$source,$rows);$d=lful_draft($id);
$before=$d['settings'];ini_set('memory_limit','128M');memory_reset_peak_usage();
$confirmation=lful_confirm($db,$actor,$unit,$id,(int)$d['version'],$digest);$peak=memory_get_peak_usage(true);
lft_assert($peak<64*1024*1024&&lful_draft($id)['settings']===$before,'Confirmation used full plan or changed saved decisions');
lft_reject(fn()=>lful_confirm($db,$actor,$unit,$id,(int)$d['version'],str_repeat('0',64)),'Changed digest accepted');
$file=$dir.'/finance.jsonl';$raw=file_get_contents($file);try{file_put_contents($file,$raw."\n");lft_reject(fn()=>lful_confirm($db,$actor,$unit,$id,(int)$d['version'],$digest),'Changed source accepted');}finally{file_put_contents($file,$raw);}
$db->begin_transaction();try{$master=lf_one($db,'SELECT id FROM master_kelas LIMIT 1');lf_query($db,'UPDATE master_kelas SET is_active=1-is_active WHERE id=?',[$master['id']]);lft_reject(fn()=>lful_confirm($db,$actor,$unit,$id,(int)$d['version'],$digest),'Changed master accepted');}finally{$db->rollback();}
// Browser tests may view/cancel a valid final confirmation, never publish user data.
$sid='final'.bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit,'csrf_legacy_import'=>str_repeat('c',64)];session_write_close();
$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);
$c=curl_init($base.'/legacy_operational_review.php?id='.$id);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_TIMEOUT=>90]);$html=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);lft_assert($code===200&&str_contains($html,'Finalisasi Migrasi')&&!str_contains($html,'Fatal error'),'Primary finalization CTA missing');
file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/finalization-fixture.json',lf_json(['base'=>$base,'job'=>$id,'session'=>$sid,'version'=>(int)$d['version'],'digest'=>$digest,'peak_confirmation'=>$peak]));
echo 'PASS: compact confirmation under 128 MiB, source/master/digest invalidation, original draft preserved and primary finalization button; no publication yet.'."\n";
