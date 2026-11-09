<?php
/** Native monthly obligations may precede official promotion; installation is CLI only. */
function spa_schema_plan(mysqli $db): array {
    $steps=[];
    foreach(['tagihan_spp','tagihan_komite','spp_tarif_siswa'] as $table){
        $c=$db->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}_data' AND COLUMN_NAME='penempatan_asal_id'")->fetch_assoc();
        if(!$c)$steps[]='extend_'.$table;
        elseif($c['COLUMN_TYPE']!=='bigint'||$c['IS_NULLABLE']!=='YES')throw new RuntimeException('Konflik dasar proyeksi '.$table);
        if($c&&!$db->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}_data' AND COLUMN_NAME='penempatan_asal_id' AND REFERENCED_TABLE_NAME='siswa_tahun_ajaran_data' AND REFERENCED_COLUMN_NAME='id'")->num_rows)throw new RuntimeException('FK proyeksi tidak sesuai: '.$table);
        if(!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='penempatan_asal_id'")->num_rows)$steps[]='view_'.$table;
        foreach(['insert','update'] as $event){$body=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='spa_{$table}_{$event}'")->fetch_row();if(!$body||!str_contains($body[0],'spa_v1'))$steps[]=$table.'_'.$event;}
        $body=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='pspp_{$table}_insert'")->fetch_row();
        if(!$body||!str_contains($body[0],'NEW.penempatan_asal_id IS NULL'))$steps[]='basis_'.$table;
    }
    foreach(['i','u'] as $e){$body=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='spp_tarif_siswa_data_$e'")->fetch_row();if(!$body||!str_contains($body[0],'NEW.penempatan_asal_id IS NULL'))$steps[]='tariff_'.$e;}
    return $steps;
}
function spa_ready(mysqli $db): bool {return $GLOBALS['spa_ready'][$db->thread_id]??= !spa_schema_plan($db);}
function spa_schema_apply(mysqli $db): int {
    if(PHP_SAPI!=='cli')throw new LogicException('Gunakan installer CLI.');
    $steps=spa_schema_plan($db);if(!$steps)return 0;
    foreach(['tagihan_spp','tagihan_komite','spp_tarif_siswa'] as $table){
        if(in_array('extend_'.$table,$steps,true))$db->query("ALTER TABLE {$table}_data ADD penempatan_asal_id BIGINT NULL, ADD CONSTRAINT fk_spa_{$table} FOREIGN KEY(penempatan_asal_id) REFERENCES siswa_tahun_ajaran_data(id)");
        $db->query("CREATE OR REPLACE VIEW $table AS SELECT * FROM {$table}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
        foreach(['INSERT','UPDATE'] as $event){
            $old='pspp_'.$table.'_'.strtolower($event);$body=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$old'")->fetch_row()[0]??null;
            if(!$body)throw new RuntimeException('Prasyarat PSB tidak lengkap.');
            if(!str_contains($body,'NEW.penempatan_asal_id IS NULL')){
                $body=str_replace('IF NEW.penempatan_id IS NULL AND NEW.rencana_masuk_id IS NULL THEN','IF NEW.penempatan_id IS NULL AND NEW.rencana_masuk_id IS NULL AND NEW.penempatan_asal_id IS NULL THEN',$body);
                $db->query("DROP TRIGGER $old");$db->query("CREATE TRIGGER $old BEFORE $event ON {$table}_data FOR EACH ROW $body");
            }
            $name='spa_'.$table.'_'.strtolower($event);
            $student=$table==='spp_tarif_siswa'?'s.id=NEW.student_id':'s.NO_INDUK=NEW.no_induk';
            $period=$table==='spp_tarif_siswa'?'NEW.period':"CONCAT(NEW.tahun,'-',LPAD(NEW.bulan,2,'0'))";
            $grade=$table==='tagihan_spp'?' AND NEW.tingkat_snapshot=CAST(a.kelas AS UNSIGNED)+1':'';
            $body="IF NEW.penempatan_asal_id IS NOT NULL THEN
              IF NEW.rencana_masuk_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar PSB dan proyeksi tidak boleh bercampur'; END IF;
              IF NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data a JOIN siswa_data s ON s.NO_INDUK=a.no_induk AND s.unit_id=a.unit_id JOIN tahun_ajaran_data ay ON ay.id=a.tahun_ajaran_id AND ay.unit_id=a.unit_id JOIN tahun_ajaran_data y ON y.id=NEW.tahun_ajaran_id AND y.unit_id=a.unit_id JOIN unit_sekolah u ON u.id=a.unit_id WHERE a.id=NEW.penempatan_asal_id AND a.unit_id=NEW.unit_id AND NEW.unit_id=current_unit_id() AND $student AND CAST(a.kelas AS UNSIGNED) BETWEEN u.tingkat_awal AND u.tingkat_akhir-1 AND y.label=CONCAT(CAST(LEFT(ay.label,4) AS UNSIGNED)+1,'/',CAST(LEFT(ay.label,4) AS UNSIGNED)+2) AND $period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' AND $period BETWEEN DATE_FORMAT(y.tanggal_mulai,'%Y-%m') AND DATE_FORMAT(y.tanggal_selesai,'%Y-%m') $grade AND (NEW.penempatan_id IS NULL OR EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data p WHERE p.id=NEW.penempatan_id AND p.unit_id=a.unit_id AND p.no_induk=a.no_induk AND p.tahun_ajaran_id=y.id AND CAST(p.kelas AS UNSIGNED)=CAST(a.kelas AS UNSIGNED)+1))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar proyeksi atau periode tidak sesuai (spa_v1)'; END IF;
            END IF;";
            if($event==='UPDATE')$body.="IF OLD.penempatan_asal_id IS NOT NULL AND (NOT(OLD.penempatan_asal_id <=> NEW.penempatan_asal_id) OR OLD.tahun_ajaran_id<>NEW.tahun_ajaran_id OR (OLD.penempatan_id IS NOT NULL AND NOT(OLD.penempatan_id <=> NEW.penempatan_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar proyeksi tidak dapat diganti'; END IF;";
            $db->query("DROP TRIGGER IF EXISTS $name");$db->query("CREATE TRIGGER $name BEFORE $event ON {$table}_data FOR EACH ROW BEGIN $body END");
        }
    }
    foreach(['i'=>'INSERT','u'=>'UPDATE'] as $suffix=>$event){
        $name='spp_tarif_siswa_data_'.$suffix;$body=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row()[0];
        if(!str_contains($body,'NEW.penempatan_asal_id IS NULL')){
            $body=str_replace('IF NEW.rencana_masuk_id IS NULL AND NOT EXISTS','IF NEW.rencana_masuk_id IS NULL AND NEW.penempatan_asal_id IS NULL AND NOT EXISTS',$body);
            $db->query("DROP TRIGGER $name");$db->query("CREATE TRIGGER $name BEFORE $event ON spp_tarif_siswa_data FOR EACH ROW $body");
        }
    }
    unset($GLOBALS['spa_ready'][$db->thread_id]);if(!spa_ready($db))throw new RuntimeException('Skema proyeksi belum lengkap.');return count($steps);
}
