<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$qa=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$jobs=json_decode(file_get_contents($qa.'/latest-source-jobs.json'),true);$proof=[];
$only=0;foreach($argv as $arg)if(preg_match('/^--unit=([123])$/D',$arg,$match))$only=(int)$match[1];
foreach($jobs as $item)if($item['version']==='2026'&&$item['mode']==='finance'&&(!$only||$item['unit']===$only)){
    unit_set_context($koneksi,(int)$item['unit']);$job=legacy_job($item['id']);$manifest=lf_extract_manifest($job);$native=lf_one($koneksi,'SELECT (SELECT COUNT(*) FROM siswa) students,(SELECT COUNT(*) FROM bayar) payments,(SELECT COALESCE(SUM(total_jumlah),0) FROM bayar) cash,(SELECT COALESCE(SUM(SALDO),0) FROM tabungan) balance,(SELECT COUNT(*) FROM legacy_finance_rows) archive_rows');
    $koneksi->begin_transaction();try{lf_publish($koneksi,$job,true);$source=lf_one($koneksi,'SELECT * FROM legacy_finance_sources WHERE backup_hash=?',[$job['hash']]);$fp=lf_source_fingerprint($koneksi,(int)$item['unit'],(int)$source['id']);lft_assert($fp['count']===(int)$manifest['total']&&$fp['fingerprint']===$source['fingerprint'],'Incomplete real archive');lf_publish($koneksi,$job,true);lft_assert(lf_source_fingerprint($koneksi,(int)$item['unit'],(int)$source['id'])===$fp,'Archive reimport duplicated rows');
        if($item['unit']===1)lft_assert((int)lf_one($koneksi,"SELECT COUNT(*) n FROM legacy_finance_rows WHERE source_id=? AND source_table IN ('tb_total','tb_masuk','tb_keluar','tbg') AND reference_only=1",[$source['id']])['n']===129,'Additional savings lost or promoted');
        $proof[]=['unit'=>$item['unit'],'count'=>$fp['count'],'fingerprint'=>$fp['fingerprint'],'idempotent'=>true,'rolled_back'=>true];
    }finally{$koneksi->rollback();}
    lft_assert($native===lf_one($koneksi,'SELECT (SELECT COUNT(*) FROM siswa) students,(SELECT COUNT(*) FROM bayar) payments,(SELECT COALESCE(SUM(total_jumlah),0) FROM bayar) cash,(SELECT COALESCE(SUM(SALDO),0) FROM tabungan) balance,(SELECT COUNT(*) FROM legacy_finance_rows) archive_rows'),'Archive affected active data or rollback lost');echo 'PASS: latest unit '.$item['unit'].' real archive, exact fingerprint, retry and complete rollback.'.PHP_EOL;
}
file_put_contents($qa.($only?'/latest-archive-index-results.json':'/latest-archive-results.json'),lf_json($proof));
