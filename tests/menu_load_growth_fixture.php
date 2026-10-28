<?php
/** Synthetic native read-load fixture; never runs on an operational server. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
$manifest=spp_test_isolated_manifest($koneksi);
if((int)$manifest['port']!==3320||getenv('SPP_LOAD_GROWTH')!=='5')throw new RuntimeException('Use the explicit 5x disposable fixture on port 3320.');
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($db,0);
if((int)$db->query("SELECT COUNT(*) FROM siswa_data WHERE NAMA LIKE 'QA Growth %'")->fetch_row()[0])throw new RuntimeException('Growth fixture already exists; restore a clean copy first.');
$tables=['siswa_data','siswa_tahun_ajaran_data','tagihan_spp_data','tagihan_komite_data','tagihan_daftar_ulang_data'];$before=[];$max=[];
foreach($tables as $table){$r=$db->query("SELECT COUNT(*) n,COALESCE(MAX(id),0) last_id FROM `$table`")->fetch_assoc();$before[$table]=(int)$r['n'];$max[$table]=(int)$r['last_id'];}
$stride=1000000;
if(max($max)>=$stride)throw new RuntimeException('Fixture identity range is already in use.');
$db->begin_transaction();
try{
    foreach(range(1,4) as $copy){
        $offset=$stride*$copy;
        foreach([1,2,3] as $unit){
            unit_set_context($db,$unit);
            foreach($tables as $table){
                $columns=array_column($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');
                $select=[];
                foreach($columns as $column){
                    $select[]=match($column){
                        'id'=>"t.id+$offset",
                        'NO_INDUK','no_induk'=>"CONCAT('9',$copy,LPAD(".($table==='siswa_data'?'t.id':'s.id').",7,'0'))",
                        'NAMA'=>"CONCAT('QA Growth $copy ',t.NAMA)",
                        'penempatan_id','penempatan_asal_id'=>"IF(t.`$column` IS NULL,NULL,t.`$column`+$offset)",
                        default=>"t.`$column`",
                    };
                }
                $join=$table==='siswa_data'?'':" JOIN siswa_data s ON s.unit_id=t.unit_id AND s.NO_INDUK=t.no_induk AND s.id<=".$max['siswa_data'];
                $names='`'.implode('`,`',$columns).'`';
                $db->query("INSERT INTO `$table` ($names) SELECT ".implode(',',$select)." FROM `$table` t$join WHERE t.unit_id=$unit AND t.id<=".$max[$table]);
            }
        }
        echo 'Prepared native student/placement/invoice copy '.$copy.PHP_EOL;
    }
    unit_set_context($db,0);$after=[];
    foreach($tables as $table){$after[$table]=(int)$db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];if($after[$table]!==$before[$table]*5)throw new RuntimeException('Unexpected 5x row count: '.$table);}
    require_once __DIR__.'/../includes/du_advance.php';require_once __DIR__.'/../includes/daftar_ulang.php';
    if((int)$db->query(du_invalid_relation_sql($db))->fetch_row()[0])throw new RuntimeException('Growth fixture contains an invalid DU relation.');
    $db->commit();
    file_put_contents(getenv('SPP_QA_DIR').'/growth-fixture.json',json_encode(['synthetic'=>true,'native_growth_factor'=>5,'legacy_archive_unchanged'=>true,'before'=>$before,'after'=>$after,'identity'=>$manifest],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo 'PASS exact 5x native students, placements and historical invoices; valid FK/DU links. Legacy sources and savings unchanged.'.PHP_EOL;
}catch(Throwable $e){$db->rollback();throw $e;}
