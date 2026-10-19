<?php
if(PHP_SAPI!=='cli')exit(1);require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$actor=(int)$db->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$root=legacy_root();
if(!str_contains(strtolower($root),'legacy_full_qa_'))throw new RuntimeException('Use private full QA storage.');
$q=legacy_queue();$q->exec("UPDATE jobs SET state='cancelled',cancel=1 WHERE name='synthetic.dat' AND state IN ('queued','apply_queued')");
$expected=[1=>['label'=>'SD','students'=>369,'bayar'=>8876,'total'=>34676],2=>['label'=>'SMP','students'=>787,'bayar'=>22175,'total'=>43599],3=>['label'=>'SMA','students'=>131,'bayar'=>1972,'total'=>11133]];
$before=[];foreach(['siswa_data','bayar_data','tabungan_data','transaksi_m_data','transaksi_k_data','tagihan_biaya_lain_data'] as $t)$before[$t]=$db->query("CHECKSUM TABLE $t EXTENDED")->fetch_assoc()['Checksum'];
$results=[];foreach($expected as $unit=>$e){
    $job=legacy_enqueue('C:/Users/lakch/Downloads/'.$e['label'].'-5.dat',$e['label'].'-5.dat',$unit,$actor,'full');
    $proc=proc_open([PHP_BINARY,'-d','memory_limit=768M',__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['file',$root.'/full-real.out.log','a'],2=>['file',$root.'/full-real.err.log','a']],$pipes);fclose($pipes[0]);if(proc_close($proc)!==0)throw new RuntimeException('Worker failed.');
    $current=legacy_job($job['id']);[$records,$manifest]=lf_extract_read($current);lft_assert($manifest['counts']['siswa']===$e['students']&&$manifest['counts']['bayar']===$e['bayar']&&count($records)===$e['total'],'Source independent counts differ');
    if($unit===1){lft_assert($current['state']==='failed','Occupied target accepted');}
    else{lft_assert($current['state']==='ready','Source full profile rejected: '.$current['message']);$sim=lful_simulate($db,$job['id']);lft_assert(!$sim['ready']&&count($sim['issues'])>0,'Unreviewed original source became operational');}
    $groups=[];foreach($records as $r)if(!$r['reference_only']){$nis=lful_identity($r['raw']['NO_INDUK']??$r['raw']['no_induk']??'');$groups[$nis][]=$r;}$held=0;foreach($groups as $rows){$savings=array_filter($rows,static fn($r)=>in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true));if($savings&&!lf_reconcile($savings,$manifest['source_date'])['ready'])$held++;}
    $results[$unit]=['job'=>$job['id'],'counts'=>$manifest['counts'],'total'=>$manifest['total'],'fingerprint'=>$manifest['fingerprint'],'savings_held'=>$held,'target_occupied'=>$unit===1];echo 'PASS: '.$e['label'].' full catalog, actual restore, counts/fingerprint; held savings='.$held."\n";unset($records);
}
foreach($before as $t=>$checksum)lft_assert($db->query("CHECKSUM TABLE $t EXTENDED")->fetch_assoc()['Checksum']===$checksum,'Source inspection changed live data');
file_put_contents(dirname((string)getenv('SPP_TEST_ISOLATED_INSTANCE')).'/full-real-results.json',lf_json($results));
echo "PASS: all three actual SQL Server backups; no data publication during inspection.\n";
