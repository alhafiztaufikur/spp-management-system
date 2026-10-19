<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_import.php';require_once __DIR__.'/../includes/legacy_finance.php';
lf_require_schema($koneksi);$root=legacy_root();
if(stripos($root,'legacy_finance_qa_')===false)throw new RuntimeException('Use private finance QA storage.');
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$expected=[1=>['siswa'=>369,'bayar'=>8876,'bayar_du'=>1067,'tabungan'=>427,'transaksi_m'=>21247,'transaksi_k'=>1280],2=>['siswa'=>787,'bayar'=>22175,'bayar_du'=>1317,'tabungan'=>537,'transaksi_m'=>13534,'transaksi_k'=>2606],3=>['siswa'=>131,'bayar'=>1972,'bayar_du'=>731,'tabungan'=>256,'transaksi_m'=>6046,'transaksi_k'=>959]];
$fingerprint=static function()use($koneksi){unit_set_context($koneksi,0);return lf_rows($koneksi,'SELECT (SELECT COUNT(*) FROM bayar) payments,(SELECT SUM(total_jumlah) FROM bayar) receipts,(SELECT COUNT(*) FROM tabungan) accounts,(SELECT SUM(SALDO) FROM tabungan) balance,(SELECT COUNT(*) FROM tagihan_biaya_lain) bills');};
$before=$fingerprint();$jobs=[];
$expectedHashes=[1=>'1ed886d4834bcd80fc9696e61e487632a1b53e2e746c79330e22540577c086bd',2=>'8818af19481e8ef10488d43cae616040708018517d1e45e06de56d408c30880b',3=>'a115145f41989873d2701bd29623271f3d4f1affa63d17989064aeb606b3dfa9'];$report=[];
foreach([1=>'SD',2=>'SMP',3=>'SMA'] as $unit=>$label){
    $s=legacy_queue()->prepare("SELECT * FROM jobs WHERE unit=? AND category='finance' AND state IN ('queued','ready','imported') ORDER BY created DESC LIMIT 1");$s->execute([$unit]);$job=$s->fetch(PDO::FETCH_ASSOC);
    if(!$job)$job=legacy_enqueue('C:/Users/lakch/Downloads/'.$label.'-5.dat',$label.'-5.dat',$unit,$actor,'finance');
    $jobs[$unit]=$job;echo "$label queued for isolated extraction\n";
}
for($attempt=0;$attempt<12;$attempt++){
    if(legacy_queue()->query("SELECT COUNT(*) FROM jobs WHERE state='queued'")->fetchColumn()){
        $process=proc_open([PHP_BINARY,'-d','memory_limit=768M',__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['file',$root.'/real-worker.out.log','a'],2=>['file',$root.'/real-worker.err.log','a']],$pipes);fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Worker failed.');
    }
}
foreach($jobs as $unit=>$job){
    $current=legacy_job($job['id']);if(!in_array($current['state'],['ready','imported'],true))throw new RuntimeException('Extraction failed: '.$current['message']);
    [$records,$manifest]=lf_extract_read($current);
    if(strtolower($current['hash'])!==$expectedHashes[$unit])throw new RuntimeException('Backup differs from audited February 2019 source.');
    foreach($expected[$unit] as $table=>$count)if((int)($manifest['counts'][$table]??-1)!==$count)throw new RuntimeException('Count differs '.$unit.'/'.$table);
    lf_stage($koneksi,$current);lf_publish($koneksi,$current);
    $source=lf_one($koneksi,'SELECT * FROM legacy_finance_sources WHERE backup_hash=?',[$current['hash']]);
    $count=(int)lf_one($koneksi,'SELECT COUNT(*) n FROM legacy_finance_rows WHERE source_id=?',[$source['id']])['n'];if($count!==count($records))throw new RuntimeException('Incomplete publication.');
    lf_publish($koneksi,$current);if((int)lf_one($koneksi,'SELECT COUNT(*) n FROM legacy_finance_rows WHERE source_id=?',[$source['id']])['n']!==$count)throw new RuntimeException('Duplicate reimport.');
    $issues=[];$ready=0;
    foreach(lf_rows($koneksi,'SELECT savings_result FROM legacy_finance_reviews r JOIN legacy_finance_maps m ON m.id=r.map_id AND m.unit_id=r.unit_id WHERE m.source_id=?',[$source['id']]) as $r){$r=json_decode($r['savings_result'],true);if($r['ready'])$ready++;foreach($r['issues'] as $issue)$issues[$issue]=($issues[$issue]??0)+1;}
    $report[$unit]=['unit'=>$unit,'backup_hash'=>$current['hash'],'counts'=>$manifest['counts'],'fingerprint'=>$manifest['fingerprint'],'total'=>$count,'savings_reconciled'=>$ready,'held_reasons'=>$issues];echo lf_json($report[$unit])."\n";
    unset($records);
}
if($before!==$fingerprint())throw new RuntimeException('Archive affected active finances.');
file_put_contents(dirname((string)getenv('SPP_TEST_ISOLATED_INSTANCE')).'/finance-reconciliation.json',lf_json($report));
echo "PASS: three original backups, exact counts, deterministic fingerprints, idempotent publication, active finances unchanged.\n";
