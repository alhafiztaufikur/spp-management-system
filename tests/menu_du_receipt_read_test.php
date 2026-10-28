<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_du.php';
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];$checks=0;
foreach([0,1,2,3] as $unit){
    unit_set_context($koneksi,$unit);
    $hashes=[];$counts=[];
    foreach([false,true] as $fast){
        $hash=hash_init('sha256');$count=0;
        $sql='SELECT * FROM ('.ldu_receipt_lines_sql($fast).') d ORDER BY unit_id,payment_id,year';
        foreach(hp_rows($koneksi,$sql) as $row){hash_update($hash,lf_json($row)."\n");$count++;}
        $hashes[]=hash_final($hash);$counts[]=$count;
    }
    if($hashes[0]!==$hashes[1]||$counts[0]!==$counts[1])throw new RuntimeException('DU receipt slots/years/amounts changed in unit '.$unit);
    $checks++;echo 'PASS exact DU receipt model unit '.$unit.' rows='.$counts[0].PHP_EOL;
}
echo "PASS $checks DU source read contracts.\n";
