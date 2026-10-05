<?php
/** CLI-only staged conversion; old snapshots are historical evidence. */
function financial_components_ready(mysqli $db): bool {
    foreach(['siswa_data'=>['PANGKAL','PANGKAL_BAYAR','potong_pangkal','tot_pangkal','potongan_spp_persen'],'bayar_data'=>['U_PANGKAL'],'tagihan_spp_data'=>['potongan_persen_snapshot']] as $t=>$cols)
        foreach($cols as $c)if(components_has_column($db,$t,$c))return false;
    $types=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='siswa_data' AND COLUMN_NAME='potongan_spp_nominal') OR (TABLE_NAME='tagihan_spp_data' AND COLUMN_NAME='potongan_nominal_ditetapkan_snapshot')) AND COLUMN_TYPE='decimal(15,2)' AND IS_NULLABLE='NO'")->fetch_row()[0];
    return $types===2 && components_check_exists($db,'chk_siswa_potongan_spp_nominal') && components_check_exists($db,'chk_tagihan_spp_nominal') && components_has_column($db,'siswa_data','potongan_spp_nominal') && components_has_column($db,'tagihan_spp_data','potongan_nominal_ditetapkan_snapshot') && components_has_column($db,'tagihan_spp','potongan_nominal_ditetapkan_snapshot');
}
function components_has_column(mysqli $db,string $table,string $column):bool {
    $s=$db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->bind_param('ss',$table,$column);$s->execute();$n=(int)$s->get_result()->fetch_row()[0];$s->close();return $n===1;
}
function components_check_exists(mysqli $db,string $name):bool {
    $s=$db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='CHECK'");$s->bind_param('s',$name);$s->execute();$n=(int)$s->get_result()->fetch_row()[0];$s->close();return $n>0;
}
function components_student_discount(mysqli $db,array $s):float {
    $percent=(float)($s['potongan_spp_persen']??0);if($percent===0.0)return 0.0;
    if($percent<0||$percent>100)throw new RuntimeException('Invalid historical SPP percentage');
    $q=$db->prepare("SELECT mt.nominal_dasar FROM master_spp_tarif_data mt JOIN master_spp_tahun_data my ON my.id=mt.master_spp_tahun_id AND my.unit_id=mt.unit_id JOIN tahun_ajaran_data y ON y.id=my.tahun_ajaran_id AND y.unit_id=my.unit_id WHERE mt.unit_id=? AND mt.tingkat=? AND y.label=COALESCE((SELECT yy.label FROM siswa_tahun_ajaran_data p JOIN tahun_ajaran_data yy ON yy.id=p.tahun_ajaran_id AND yy.unit_id=p.unit_id WHERE p.unit_id=? AND p.no_induk=? ORDER BY yy.label DESC LIMIT 1),?) LIMIT 1");
    $unit=(int)$s['unit_id'];$grade=(int)$s['KELAS'];$nis=(string)$s['NO_INDUK'];$now=(int)date('n')>=7?(int)date('Y'):(int)date('Y')-1;$year=$now.'/'.($now+1);
    $q->bind_param('iiiss',$unit,$grade,$unit,$nis,$year);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    if(!$row||(float)$row['nominal_dasar']<=0)throw new RuntimeException('Historical discount has no proven base tariff; migration stopped');
    return round((float)$row['nominal_dasar']*$percent/100,0);
}
function financial_components_preflight(mysqli $db):void {
    foreach(['siswa_data'=>'potongan_spp_nominal','tagihan_spp_data'=>'potongan_nominal_ditetapkan_snapshot'] as $t=>$c){
        $r=$db->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->fetch_assoc();
        if($r&&($r['COLUMN_TYPE']!=='decimal(15,2)'||$r['IS_NULLABLE']!=='NO'))throw new RuntimeException('Unknown nominal column schema');
    }
    if(financial_components_ready($db))return;
    if((int)$db->query("SELECT COUNT(*) FROM transaksi_otorisasi_data WHERE status='pending'")->fetch_row()[0])throw new RuntimeException('Pending authorizations must be resolved before conversion');
    if(components_has_column($db,'bayar_data','U_PANGKAL') && (int)$db->query("SELECT COUNT(*) FROM bayar_data WHERE COALESCE(U_PANGKAL,0)<0 OR COALESCE(U_PSB,0)<0")->fetch_row()[0])throw new RuntimeException('Negative historical fee');
    if(components_has_column($db,'bayar_data','U_PANGKAL')&&(int)$db->query('SELECT COUNT(*) FROM (SELECT unit_id,NO_INDUK,SUM(COALESCE(U_PSB,0)+COALESCE(U_PANGKAL,0)) paid FROM bayar_data GROUP BY unit_id,NO_INDUK HAVING paid>9999999999999.99) limits_check')->fetch_row()[0])throw new RuntimeException('Historical PSB credit exceeds target capacity');
    if(components_has_column($db,'siswa_data','potongan_spp_persen'))foreach($db->query('SELECT * FROM siswa_data')->fetch_all(MYSQLI_ASSOC) as $s)components_student_discount($db,$s);
    foreach(['siswa_data','bayar_data','tagihan_spp_data'] as $t) {
        $r=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetch_assoc();
        if(!$r||$r['TABLE_TYPE']!=='BASE TABLE'||$r['ENGINE']!=='InnoDB')throw new RuntimeException('Unknown component schema: '.$t);
    }
}
function financial_components_apply(mysqli $db):void {
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
    financial_components_preflight($db);
    $db->query("CREATE TABLE IF NOT EXISTS financial_component_migration (
      migration_key VARCHAR(64) PRIMARY KEY, stage VARCHAR(32) NOT NULL, baseline JSON NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS financial_component_migration_row (
      migration_key VARCHAR(64) NOT NULL, entity VARCHAR(32) NOT NULL, source_id BIGINT NOT NULL, unit_id TINYINT UNSIGNED NOT NULL,
      before_data JSON NOT NULL, after_data JSON NOT NULL, PRIMARY KEY(migration_key,entity,source_id),
      CONSTRAINT fk_component_migration FOREIGN KEY(migration_key) REFERENCES financial_component_migration(migration_key) ON DELETE RESTRICT) ENGINE=InnoDB");
    $key='psb_nominal_20261005';
    $journal=$db->query("SELECT * FROM financial_component_migration WHERE migration_key='$key'")->fetch_assoc();
    if(!$journal) {
        $baseline=json_encode($db->query("SELECT COUNT(*) payments,COALESCE(SUM(total_jumlah),0) receipts FROM bayar_data")->fetch_assoc(),JSON_THROW_ON_ERROR);
        $q=$db->prepare("INSERT INTO financial_component_migration(migration_key,stage,baseline,started_at) VALUES(?,'prepared',?,NOW())");$q->bind_param('ss',$key,$baseline);$q->execute();$q->close();$journal=['stage'=>'prepared'];
    }
    if(!components_has_column($db,'siswa_data','potongan_spp_nominal'))$db->query("ALTER TABLE siswa_data ADD potongan_spp_nominal DECIMAL(15,2) NOT NULL DEFAULT 0");
    $newSnapshot=!components_has_column($db,'tagihan_spp_data','potongan_nominal_ditetapkan_snapshot');
    if($newSnapshot&&$journal['stage']==='complete')throw new RuntimeException('Completed migration has a missing snapshot column; restore verified schema before proceeding');
    if($newSnapshot)$db->query('ALTER TABLE tagihan_spp_data ADD potongan_nominal_ditetapkan_snapshot DECIMAL(15,2) NOT NULL DEFAULT 0');
    if($journal['stage']==='prepared') {
        echo "DATA: PSB credit and nominal discount\n";
        $db->begin_transaction();
        try {
            $db->query("SELECT migration_key FROM financial_component_migration WHERE migration_key='$key' FOR UPDATE");
            $write=$db->prepare("INSERT INTO financial_component_migration_row(migration_key,entity,source_id,unit_id,before_data,after_data) VALUES(?,?,?,?,?,?)");
            $record=function(string $entity,array $old,array $new)use($write,$key){$id=(int)$old['id'];$unit=(int)$old['unit_id'];$before=json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$after=json_encode($new,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$write->bind_param('ssiiss',$key,$entity,$id,$unit,$before,$after);$write->execute();};
            $hasPangkal=components_has_column($db,'bayar_data','U_PANGKAL');
            $payments=$db->query("SELECT * FROM bayar_data ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
            $testFault=preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)$db->query('SELECT DATABASE()')->fetch_row()[0])&&getenv('SPP_TEST_ALLOW_MUTATION')==='1'?(string)getenv('SPP_TEST_COMPONENT_FAIL_STAGE'):'';
            $rowIndex=0;
            $totals=[];$q=$db->prepare('UPDATE bayar_data SET U_PSB=?,updated_at=? WHERE id=?');
            foreach($payments as $p){if($testFault==='during-data'&&$rowIndex++===1)throw new RuntimeException('Injected clone data failure');$amount=(float)($p['U_PSB']??0)+(float)($p['U_PANGKAL']??0);$u=(int)$p['unit_id'];$totals[$u][(string)$p['NO_INDUK']]=($totals[$u][(string)$p['NO_INDUK']]??0)+$amount;
                if($hasPangkal){$new=$p;$new['U_PSB']=$amount;unset($new['U_PANGKAL']);$record('payment',$p,$new);$id=(int)$p['id'];unit_set_context($db,$u);$stamp=$p['updated_at'];$q->bind_param('dsi',$amount,$stamp,$id);$q->execute();}
            }$q->close();
            $q=$db->prepare('UPDATE siswa_data SET PSB=?,potongan_spp_nominal=? WHERE id=?');
            foreach($db->query('SELECT * FROM siswa_data ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC) as $s){
                $psb=max((float)$s['PSB'],$totals[(int)$s['unit_id']][(string)$s['NO_INDUK']]??0);$discount=components_has_column($db,'siswa_data','potongan_spp_persen')?components_student_discount($db,$s):(float)$s['potongan_spp_nominal'];
                $new=$s;$new['PSB']=$psb;$new['potongan_spp_nominal']=$discount;foreach(['PANGKAL','potong_pangkal','tot_pangkal','potongan_spp_persen'] as $f)unset($new[$f]);$record('student',$s,$new);
                $id=(int)$s['id'];unit_set_context($db,(int)$s['unit_id']);$q->bind_param('ddi',$psb,$discount,$id);$q->execute();
            }$q->close();
            if(components_has_column($db,'tagihan_spp_data','potongan_persen_snapshot'))foreach($db->query('SELECT * FROM tagihan_spp_data ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC) as $b){$new=$b;$new['potongan_nominal_ditetapkan_snapshot']=$b['potongan_nominal_snapshot'];unset($new['potongan_persen_snapshot']);$record('spp_bill',$b,$new);unit_set_context($db,(int)$b['unit_id']);$id=(int)$b['id'];$nom=(float)$b['potongan_nominal_snapshot'];$stamp=$b['updated_at'];$q=$db->prepare('UPDATE tagihan_spp_data SET potongan_nominal_ditetapkan_snapshot=?,updated_at=? WHERE id=?');$q->bind_param('dsi',$nom,$stamp,$id);$q->execute();$q->close();}
            $write->close();
            $db->query("UPDATE financial_component_migration SET stage='data_done' WHERE migration_key='$key'");$db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
    }
    if(getenv('SPP_TEST_ALLOW_MUTATION')==='1'&&preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)$db->query('SELECT DATABASE()')->fetch_row()[0])&&getenv('SPP_TEST_COMPONENT_FAIL_STAGE')==='after-data')throw new RuntimeException('Injected clone DDL checkpoint failure');
    if($journal['stage']!=='complete'||!components_has_column($db,'tagihan_spp','potongan_nominal_ditetapkan_snapshot')) {
        echo "DDL: retired columns, views, constraints\n";
        foreach(['siswa','bayar','tagihan_spp'] as $v){$exists=$db->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$v'")->fetch_row();if($exists&&$exists[0]==='VIEW')$db->query("DROP VIEW $v");}
        foreach(['siswa_data'=>['PANGKAL','potong_pangkal','tot_pangkal','PANGKAL_BAYAR','potongan_spp_persen'],'bayar_data'=>['U_PANGKAL'],'tagihan_spp_data'=>['potongan_persen_snapshot']] as $t=>$drop){
            $checks=$db->query("SELECT tc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME='$t'")->fetch_all(MYSQLI_ASSOC);
            foreach($checks as $c)foreach($drop as $column)if(stripos($c['CHECK_CLAUSE'],chr(96).$column.chr(96))!==false){$db->query("ALTER TABLE $t DROP CHECK {$c['CONSTRAINT_NAME']}");break;}
            foreach($drop as $col)if(components_has_column($db,$t,$col))$db->query("ALTER TABLE $t DROP COLUMN $col");
        }
        if(!components_check_exists($db,'chk_siswa_potongan_spp_nominal'))$db->query("ALTER TABLE siswa_data ADD CONSTRAINT chk_siswa_potongan_spp_nominal CHECK(potongan_spp_nominal>=0)");
        if(!components_check_exists($db,'chk_tagihan_spp_nominal'))$db->query("ALTER TABLE tagihan_spp_data ADD CONSTRAINT chk_tagihan_spp_nominal CHECK(tarif_dasar_snapshot>=0 AND potongan_nominal_ditetapkan_snapshot>=0 AND potongan_nominal_snapshot>=0 AND potongan_nominal_snapshot<=tarif_dasar_snapshot AND nominal_tagihan>=0)");
        foreach(['siswa','bayar','tagihan_spp'] as $v)$db->query("CREATE OR REPLACE VIEW $v AS SELECT * FROM {$v}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
        if(!financial_components_ready($db))throw new RuntimeException('Final components schema failed');
        $db->query("UPDATE financial_component_migration SET stage='complete',completed_at=NOW() WHERE migration_key='$key'");
    }
    echo "Components migration verified\n";
}

