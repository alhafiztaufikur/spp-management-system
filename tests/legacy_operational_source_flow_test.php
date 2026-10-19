<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);
unit_set_context($db,2);lful_empty($db,2);$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];
$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>2];
$rows=[];foreach([['212209001','7A'],['212209002','7B'],['GK09','GK']] as $n=>[$nis,$class])$rows[]=['table'=>'siswa','raw'=>['NO_INDUK'=>$nis,'NAMA'=>'Owned Source Setup '.$n,'KELAS'=>$class,'SPP_PERBULAN'=>'530000','POMG'=>'15000'],'reference_only'=>false,'money_columns'=>['SPP_PERBULAN','POMG'],'student_ordinal'=>$n+1];
foreach([['bayar',['NO_INDUK'=>'212209001','KELAS'=>'7A','U_SPP'=>'500000','TGL_BYR'=>'2026-10-01T12:00:00','BULAN'=>'Oktober','TAHUN'=>'2026']],['tabungan',['NO_INDUK'=>'212209001','SALDO'=>'1000']],['transaksi_m',['NO_INDUK'=>'212209001','MASUK'=>'1000','KELUAR'=>null,'TANGGAL'=>'2026-10-01T10:00:00']],['tabungan',['NO_INDUK'=>'212209002','SALDO'=>'1000']],['transaksi_m',['NO_INDUK'=>'212209002','MASUK'=>'800','KELUAR'=>null,'TANGGAL'=>'2026-10-01T10:00:00']]] as [$table,$raw])$rows[]=['table'=>$table,'raw'=>$raw,'reference_only'=>false,'money_columns'=>array_values(array_intersect(array_keys($raw),['U_SPP','SALDO','MASUK','KELUAR']))];
$source=tempnam(legacy_root(),'setup');file_put_contents($source,'Owned source setup fixture');$job=legacy_enqueue($source,'setup.bak',2,$actor,'full');unlink($source);$id=$job['id'];$dir=legacy_root().'/'.$id;
file_put_contents($dir.'/operational-request.json','{}');file_put_contents($dir.'/finance.jsonl',implode("\n",array_map('lf_json',$rows))."\n");$counts=[];foreach($rows as $r)$counts[$r['table']]=($counts[$r['table']]??0)+1;
file_put_contents($dir.'/finance_manifest.json',lf_json(['profile'=>'legacy-finance-v1','unit'=>2,'backup_hash'=>$job['hash'],'source_date'=>'2026-10-01T18:00:00','counts'=>$counts,'rows_sha256'=>hash_file('sha256',$dir.'/finance.jsonl')]));
lful_stage($db,$job);$draft=lful_draft($id);lft_assert(!empty($draft['settings']['op_groups']['7A']['source_accepted']),'Worker did not prepare source defaults');
lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM siswa')['n']===0,'Staging wrote students');
$sid='sourcesetup'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>2,'csrf_legacy_import'=>str_repeat('s',64)];session_write_close();
function setup_http(array $post=[],string $section='groups'): array {global $base,$id,$sid;$c=curl_init($base.'/legacy_operational_review.php?id='.$id.'&section='.$section);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_TIMEOUT=>180]);if($post)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);if($body===false)throw new RuntimeException(curl_error($c));$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$body];}
[$status,$html]=setup_http();lft_assert($status===200&&str_contains($html,'Siapkan &amp; Periksa Hasil'),'Simple server form missing');
$post=['action'=>'prepare_source','id'=>$id,'version'=>$draft['version'],'csrf_token'=>str_repeat('s',64),'cutoff'=>$draft['settings']['cutoff'],'year_id'=>$draft['settings']['year_id']];
lft_assert(setup_http(array_replace($post,['csrf_token'=>'wrong']))[0]===403,'CSRF bypassed');lft_assert(setup_http($post)[0]===303,'Source prepare not queued');
lft_assert(setup_http($post)[0]===409,'Double click accepted stale draft');
function setup_worker(): void { $p=proc_open([PHP_BINARY,'-d','memory_limit=768M',__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Owned worker failed: '.$err); }
setup_worker();$draft=lful_draft($id);$sim=$draft['simulation'];lft_assert($sim['ready']&&$sim['summary']['students']===2&&$sim['summary']['payments']===1,'Source-only flow blocked ready data');
lft_assert($sim['summary']['obligations_unreviewed']===2&&$sim['summary']['pending']>0,'Unknown debts or bank mismatch hidden');
[$status,$html]=setup_http(['action'=>'confirm','id'=>$id,'version'=>$draft['version'],'csrf_token'=>str_repeat('s',64),'digest'=>$sim['digest']],'confirm');
lft_assert($status===200&&str_contains($html,'id="full-confirm"')&&!str_contains($html,'type="checkbox" name="confirmation"'),'Single final confirmation missing');
$confirmed=lful_draft($id)['confirmed'];
lft_assert(setup_http(['action'=>'apply','id'=>$id,'version'=>$draft['version'],'csrf_token'=>str_repeat('s',64),'confirmation_token'=>$confirmed['token'],'confirmation'=>'MIGRASI PENUH'],'confirm')[0]===200,'Final apply not queued');
setup_worker();$batch=lf_one($db,'SELECT * FROM legacy_full_batches WHERE id=?',[$id]);lft_assert($batch['state']==='completed','Verified publication failed');
lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM siswa')['n']===2&&(int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_payments WHERE batch_id=?',[$id])['n']===1,'Student/payment not published together');
lft_assert((int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_obligations')['n']===0,'Source tariff invented historical debt');
lft_assert((float)lf_one($db,"SELECT SPP_PERBULAN FROM siswa WHERE NO_INDUK='212209001'")['SPP_PERBULAN']===530000.0,'Source rate changed');
lop_check_result($db,$id);file_put_contents($root.'/operational-source-flow-fixture.json',lf_json(['job'=>$id,'session'=>$sid,'actor'=>$actor,'base'=>$base]));
echo "PASS: source defaults, simple server form, CSRF/stale click rejection, async worker, one confirmation and verified partial publication with source tariffs and no invented debt.\n";
