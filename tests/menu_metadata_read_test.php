<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/reports.php';
$checks=0;
foreach(['daftar_ulang','DAFTAR_ULANG','dáftar_ulang','spp','komite',''] as $category){
    foreach([JSON_UNESCAPED_UNICODE,0] as $encoding){
        $items=json_encode([['category'=>$category,'migration'=>['version'=>4,'period'=>'2027/2028']]],$encoding|JSON_THROW_ON_ERROR);
        foreach([$items,str_replace('category','\\u0063ategory',str_replace('daftar','\\u0064aftar',$items))] as $json){
            $candidate=report_du_metadata_candidate_sql('p');
            $metadata="(SELECT MIN(mp.period) FROM JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(category VARCHAR(100) PATH '$.category',period VARCHAR(9) PATH '$.migration.period')) mp WHERE mp.category='daftar_ulang')";
            $row=lf_query($koneksi,"SELECT $metadata old_year,CASE WHEN $candidate THEN $metadata ELSE NULL END new_year FROM (SELECT ? items) p",[$json])->fetch_assoc();
            if($row['old_year']!==$row['new_year'])throw new RuntimeException('DU option omitted for a supported JSON/collation representation');
            $checks++;
        }
    }
}
echo "PASS $checks escaped, Unicode, case and non-DU metadata reads.\n";
