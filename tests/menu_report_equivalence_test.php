<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
$qa=$argv[1]??'';$mode=$argv[2]??'';
$unit=(int)($argv[3]??1);if(!in_array($unit,[0,1,2,3],true))throw new RuntimeException('Invalid audit unit.');
$fullHistory=($argv[4]??'')==='full';
if(!str_contains($qa,'menu_speed_')||!in_array($mode,['baseline','after'],true))throw new RuntimeException('Explicit menu audit root and baseline/after mode required.');
$source=$mode==='baseline'?$qa.'/baseline':realpath(__DIR__.'/..');
require_once $source.'/koneksi.php';spp_test_assert_database_target($koneksi);
require_once $source.'/includes/reports.php';
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
$results=[];$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{foreach($fullHistory?['riwayat-tagihan']:array_keys(report_registry()) as $template){
    $_GET=['template'=>$template,'tahun_ajaran'=>'2026/2027','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-21'];
    if($fullHistory)$_GET=['template'=>$template,'siswa_status'=>'all'];
    $report=report_build($koneksi,$template,report_filters($koneksi,$_GET));$rows=array_map(static fn($row)=>json_encode($row,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),$report['rows']);
    $orderHash=hash('sha256',implode("\n",$rows));sort($rows,SORT_STRING);
    $results[$template]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
    if($fullHistory)$results[$template]['order_sha256']=$orderHash;
    echo $template.': '.count($rows)." records\n";
}}finally{$koneksi->rollback();}
$suffix=($unit===1?'':'-unit'.$unit).($fullHistory?'-full':'');$file=$qa.'/report-model-baseline'.$suffix.'.json';
if($mode==='baseline')file_put_contents($file,json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
else{if($results!==json_decode(file_get_contents($file),true))throw new RuntimeException('Report records differ from baseline.');file_put_contents($qa.'/report-model-verification'.$suffix.'.json',json_encode($results,JSON_PRETTY_PRINT));echo 'PASS '.count($results)." report record sets exactly match the baseline (unit $unit)\n";}
