<?php
require_once __DIR__.'/schema_catalog.php';
/** CLI installation; admission plans are financial evidence, not academic placements. */
function pspp_schema_plan(mysqli $db): array {
    $steps=[];$catalog=schema_catalog($db);
    if(!isset($catalog['tables']['psb_spp_plans_data']))$steps[]='create_plan';
    foreach(['tagihan_spp','tagihan_komite','spp_tarif_siswa'] as $name){
        $columns=array_column($catalog['columns'][$name.'_data']??[],'IS_NULLABLE','COLUMN_NAME');
        if(!isset($columns['rencana_masuk_id']))$steps[]='extend_'.$name;
        elseif(($columns['penempatan_id']??'')!=='YES')throw new RuntimeException('Konflik nullability '.$name);
        if(isset($columns['rencana_masuk_id'])){
            $column=$catalog['columns'][$name.'_data']['rencana_masuk_id'];
            if($column['COLUMN_TYPE']!=='bigint'||$column['IS_NULLABLE']!=='YES')throw new RuntimeException('Konflik tipe dasar rencana '.$name);
            $matching=array_filter($catalog['foreign_keys'][$name.'_data']['rencana_masuk_id']??[],static fn($fk)=>$fk['REFERENCED_TABLE_NAME']==='psb_spp_plans_data'&&$fk['REFERENCED_COLUMN_NAME']==='id');
            if(!$matching)$steps[]='fk_'.$name;
        }
        if(!isset($catalog['columns'][$name]['rencana_masuk_id']))$steps[]='view_'.$name;
        foreach(['INSERT','UPDATE'] as $event){$trigger='pspp_'.$name.'_'.strtolower($event);$body=$catalog['triggers'][$trigger]??'';if(!str_contains($body,"REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'")||($event==='INSERT'&&!str_contains($body,'SET NEW.unit_id=current_unit_id()')))$steps[]=$trigger;}
    }
    if(($catalog['tables']['psb_spp_plans']??null)!=='VIEW')$steps[]='plan_view';
    foreach(['i','u'] as $suffix){$body=$catalog['triggers']['spp_tarif_siswa_data_'.$suffix]??'';if(!str_contains($body,'NEW.rencana_masuk_id IS NULL'))$steps[]='tariff_plan_'.$suffix;}
    foreach(['INSERT','UPDATE','DELETE'] as $event){$body=$catalog['triggers']['pspp_plan_'.strtolower($event)]??'';if($body===''||($event!=='DELETE'&&!str_contains($body,'NEW.penempatan_id IS NOT NULL OR y.label=')))$steps[]='plan_'.strtolower($event);}
    return $steps;
}
function pspp_ready(mysqli $db): bool {return $GLOBALS['pspp_ready'][$db->thread_id]??= !pspp_schema_plan($db);}
function pspp_schema_apply(mysqli $db): int {
    if(PHP_SAPI!=='cli')throw new LogicException('Gunakan installer CLI.');
    $steps=pspp_schema_plan($db);if(!$steps)return 0;
    if(in_array('create_plan',$steps,true))$db->query("CREATE TABLE psb_spp_plans_data (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,unit_id TINYINT UNSIGNED NOT NULL,
        student_id INT NOT NULL,tahun_ajaran_id INT NOT NULL,registered_year CHAR(9) NOT NULL,
        tingkat TINYINT UNSIGNED NOT NULL,spp_rate DECIMAL(15,2) NOT NULL,komite_rate DECIMAL(15,2) NOT NULL,
        penempatan_id BIGINT NULL,actor_id INT NOT NULL,revision INT UNSIGNED NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_pspp_student(unit_id,student_id),
        FOREIGN KEY(unit_id,student_id) REFERENCES siswa_data(unit_id,id),
        FOREIGN KEY(tahun_ajaran_id) REFERENCES tahun_ajaran_data(id),
        FOREIGN KEY(penempatan_id) REFERENCES siswa_tahun_ajaran_data(id),FOREIGN KEY(actor_id) REFERENCES admin(id),
        CHECK(spp_rate>=0 AND komite_rate>=0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query('CREATE OR REPLACE VIEW psb_spp_plans AS SELECT * FROM psb_spp_plans_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');
    foreach(['INSERT','UPDATE','DELETE'] as $event){
        $name='pspp_plan_'.strtolower($event);
        if(!in_array('plan_'.strtolower($event),$steps,true))continue;
        if($event==='DELETE')$body="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rencana masuk dan audit wajib dipertahankan';";
        else {
            $body="IF current_unit_id() NOT BETWEEN 1 AND 3 OR NEW.unit_id<>current_unit_id() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unit rencana tidak sesuai'; END IF;
            IF NOT EXISTS(SELECT 1 FROM siswa_data s JOIN tahun_ajaran_data y ON y.unit_id=s.unit_id JOIN unit_sekolah u ON u.id=s.unit_id WHERE s.id=NEW.student_id AND s.unit_id=NEW.unit_id AND y.id=NEW.tahun_ajaran_id AND u.tingkat_awal=NEW.tingkat AND NEW.registered_year REGEXP '^[0-9]{4}/[0-9]{4}$' AND CAST(RIGHT(NEW.registered_year,4) AS UNSIGNED)=CAST(LEFT(NEW.registered_year,4) AS UNSIGNED)+1 AND (NEW.penempatan_id IS NOT NULL OR y.label=CONCAT(CAST(LEFT(NEW.registered_year,4) AS UNSIGNED)+1,'/',CAST(LEFT(NEW.registered_year,4) AS UNSIGNED)+2))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Identitas rencana masuk tidak sesuai'; END IF;
            IF NEW.penempatan_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data p JOIN siswa_data s ON s.unit_id=p.unit_id AND s.NO_INDUK=p.no_induk WHERE p.id=NEW.penempatan_id AND p.unit_id=NEW.unit_id AND s.id=NEW.student_id AND p.tahun_ajaran_id=NEW.tahun_ajaran_id AND CAST(p.kelas AS UNSIGNED)=NEW.tingkat AND p.status<>'dibatalkan') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Penempatan rencana masuk tidak sesuai'; END IF;";
            if($event==='UPDATE')$body.="IF NEW.unit_id<>OLD.unit_id OR NEW.student_id<>OLD.student_id OR NEW.tahun_ajaran_id<>OLD.tahun_ajaran_id OR NEW.tingkat<>OLD.tingkat OR NEW.registered_year<>OLD.registered_year OR (OLD.penempatan_id IS NOT NULL AND NOT(NEW.penempatan_id <=> OLD.penempatan_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar rencana masuk tidak dapat diganti'; END IF;";
        }
        $db->query("DROP TRIGGER IF EXISTS $name");$db->query("CREATE TRIGGER $name BEFORE $event ON psb_spp_plans_data FOR EACH ROW BEGIN $body END");
    }
    foreach(['tagihan_spp','tagihan_komite','spp_tarif_siswa'] as $table){
        if(in_array('extend_'.$table,$steps,true))$db->query("ALTER TABLE {$table}_data ADD rencana_masuk_id BIGINT NULL, MODIFY penempatan_id BIGINT NULL, ADD CONSTRAINT fk_pspp_{$table} FOREIGN KEY(rencana_masuk_id) REFERENCES psb_spp_plans_data(id)");
        if(in_array('fk_'.$table,$steps,true))$db->query("ALTER TABLE {$table}_data ADD CONSTRAINT fk_pspp_{$table} FOREIGN KEY(rencana_masuk_id) REFERENCES psb_spp_plans_data(id)");
        $db->query("CREATE OR REPLACE VIEW $table AS SELECT * FROM {$table}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
        foreach(['INSERT','UPDATE'] as $event){
            $name='pspp_'.$table.'_'.strtolower($event);if(!in_array($name,$steps,true))continue;
            $student=$table==='spp_tarif_siswa'?'s.id=NEW.student_id':'s.NO_INDUK=NEW.no_induk';
            $period=$table==='spp_tarif_siswa'?'NEW.period':"CONCAT(NEW.tahun,'-',LPAD(NEW.bulan,2,'0'))";
            $grade=$table==='tagihan_spp'?' AND NEW.tingkat_snapshot=r.tingkat':'';
            $body=($event==='INSERT'?'SET NEW.unit_id=current_unit_id(); ':'')."IF NEW.penempatan_id IS NULL AND NEW.rencana_masuk_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tagihan atau tarif wajib memiliki dasar'; END IF;
            IF NEW.rencana_masuk_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM psb_spp_plans_data r JOIN siswa_data s ON s.id=r.student_id AND s.unit_id=r.unit_id JOIN tahun_ajaran_data y ON y.id=r.tahun_ajaran_id AND y.unit_id=r.unit_id WHERE r.id=NEW.rencana_masuk_id AND r.unit_id=NEW.unit_id AND $student AND r.tahun_ajaran_id=NEW.tahun_ajaran_id AND $period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' AND $period BETWEEN DATE_FORMAT(y.tanggal_mulai,'%Y-%m') AND DATE_FORMAT(y.tanggal_selesai,'%Y-%m') $grade AND (NEW.penempatan_id IS NULL OR NEW.penempatan_id=r.penempatan_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar rencana atau periode tidak sesuai'; END IF;
            IF NEW.penempatan_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data p JOIN siswa_data s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE p.id=NEW.penempatan_id AND p.unit_id=NEW.unit_id AND $student AND p.tahun_ajaran_id=NEW.tahun_ajaran_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar penempatan tidak sesuai'; END IF;";
            $db->query("DROP TRIGGER IF EXISTS $name");
            $db->query("CREATE TRIGGER $name BEFORE $event ON {$table}_data FOR EACH ROW BEGIN $body END");
        }
    }
    // Existing tariff triggers enforce placement-only evidence; extend their predicate.
    foreach(['i','u'] as $suffix){
        $name='spp_tarif_siswa_data_'.$suffix;$row=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row();
        if($row&&!str_contains($row[0],'NEW.rencana_masuk_id IS NULL')){
            $body=str_replace('IF NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data','IF NEW.rencana_masuk_id IS NULL AND NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data',$row[0]);
            if($body===$row[0])throw new RuntimeException('Trigger tarif tidak dikenal: '.$name);
            $db->query("DROP TRIGGER $name");$event=$suffix==='i'?'INSERT':'UPDATE';$db->query("CREATE TRIGGER $name BEFORE $event ON spp_tarif_siswa_data FOR EACH ROW $body");
        }
    }
    unset($GLOBALS['sct_ready'][$db->thread_id]);
    unset($GLOBALS['pspp_ready'][$db->thread_id]);
    if(!pspp_ready($db))throw new RuntimeException('Skema rencana masuk belum lengkap.');
    return count($steps);
}
