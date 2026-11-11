<?php
require_once __DIR__.'/schema_catalog.php';
/** OUT is an operational decision; installing its schema never fabricates cash. */
function sout_cash_sources(): array {
    $sources=[];
    foreach(['bayar','bayar_du','bayar_tahunan_siswa','bayar_spp_periode','spp_alokasi_batch','transaksi_m','transaksi_k','tabungan'] as $t)$sources[$t]=['student',in_array($t,['bayar','transaksi_m','transaksi_k','tabungan'],true)?'NO_INDUK':'no_induk'];
    foreach(['bayar_komite','bayar_biaya_lain'] as $t)$sources[$t]=['payment','bayar_id'];
    $sources['spp_alokasi']=['allocation','batch_id'];
    foreach(['legacy_op_payments','legacy_op_journals','legacy_op_accounts'] as $t)$sources[$t]=['owner','owner_id'];
    $sources['legacy_full_payments']=['identity','student_id'];
    return $sources;
}
function sout_schema_plan(mysqli $db): array {
    $steps=[];$catalog=schema_catalog($db);
    if(!isset($catalog['tables']['student_out_periods_data']))$steps[]='periods';
    else {
        $columns=array_column($catalog['columns']['student_out_periods_data']??[],'COLUMN_TYPE','COLUMN_NAME');
        if(($columns['student_id']??'')!=='int'||!isset($columns['resume_month'],$columns['open_student_id'],$columns['first_free_month']))throw new RuntimeException('Konflik struktur periode OUT.');
        foreach(['student_id'=>'siswa_data','unit_id'=>'unit_sekolah'] as $column=>$parent){$fk=$catalog['foreign_keys']['student_out_periods_data'][$column][0]??null;if(!$fk||$fk['REFERENCED_TABLE_NAME']!==$parent||$fk['REFERENCED_COLUMN_NAME']!=='id')throw new RuntimeException('Konflik FK periode OUT: '.$column);}
    }
    if(($catalog['tables']['student_out_periods']??null)!=='VIEW')$steps[]='view';
    foreach(['out_assert_student','out_assert_owner'] as $name){$body=$catalog['routines'][$name]??'';$version=$name==='out_assert_student'?'out_v2':'out_v1';if(!str_contains($body,$version))$steps[]=$name;}
    foreach(['tagihan_spp','tagihan_komite','tagihan_daftar_ulang'] as $t){
        $c=$catalog['columns'][$t.'_data']['out_period_id']??null;
        if(!$c)$steps[]='extend_'.$t;
        elseif($c['COLUMN_TYPE']!=='bigint'||$c['IS_NULLABLE']!=='YES')throw new RuntimeException('Konflik relasi OUT: '.$t);
        if($c){$fk=$catalog['foreign_keys'][$t.'_data']['out_period_id'][0]??null;if(!$fk||$fk['REFERENCED_TABLE_NAME']!=='student_out_periods_data'||$fk['REFERENCED_COLUMN_NAME']!=='id')throw new RuntimeException('Konflik FK tagihan OUT: '.$t);}
        if(!isset($catalog['columns'][$t]['out_period_id']))$steps[]='view_'.$t;
    }
    $triggers=['out_periods_i','out_periods_u','out_periods_d'];
    foreach(sout_cash_sources() as $t=>$source)foreach(['i','u','d'] as $e)$triggers[]='out_cash_'.$t.'_'.$e;
    foreach(['tagihan_spp','tagihan_komite','tagihan_daftar_ulang'] as $t)foreach(['i','u'] as $e)$triggers[]='out_bill_'.$t.'_'.$e;
    foreach($triggers as $name){$body=$catalog['triggers'][$name]??'';$version=str_starts_with($name,'out_bill_tagihan_daftar_ulang_')?'out_v3':(str_starts_with($name,'out_cash_')||str_starts_with($name,'out_bill_')?'out_v2':'out_v1');if(!str_contains($body,$version))$steps[]=$name;}
    return $steps;
}
function sout_ready(mysqli $db): bool {return $GLOBALS['sout_ready'][$db->thread_id]??= !sout_schema_plan($db);}
function sout_schema_apply(mysqli $db): int {
    if(PHP_SAPI!=='cli')throw new LogicException('Gunakan installer CLI OUT.');
    $steps=sout_schema_plan($db);if(!$steps)return 0;
    $collation=$db->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->fetch_row()[0];if(!preg_match('/^utf8mb4_[a-z0-9_]+$/D',$collation))throw new RuntimeException('Kolasi database OUT tidak didukung.');
    $db->query("CREATE TABLE IF NOT EXISTS student_out_periods_data (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,unit_id TINYINT UNSIGNED NOT NULL,student_id INT NOT NULL,
        departed_at DATETIME NOT NULL,first_free_month DATE NOT NULL,departure_year CHAR(9) NOT NULL,
        returned_at DATETIME NULL,resume_month DATE NULL,origin ENUM('student','rollout') NOT NULL,
        actor_id INT NOT NULL,return_actor_id INT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        open_student_id INT GENERATED ALWAYS AS (IF(returned_at IS NULL,student_id,NULL)) STORED,
        UNIQUE KEY uq_out_open(unit_id,open_student_id),KEY idx_out_student(unit_id,student_id,first_free_month),
        CONSTRAINT fk_out_student FOREIGN KEY(student_id) REFERENCES siswa_data(id),
        CONSTRAINT fk_out_unit FOREIGN KEY(unit_id) REFERENCES unit_sekolah(id),
        CONSTRAINT ck_out_month CHECK(DAY(first_free_month)=1 AND (resume_month IS NULL OR DAY(resume_month)=1)),
        CONSTRAINT ck_out_return CHECK((returned_at IS NULL AND resume_month IS NULL) OR (returned_at>=departed_at AND resume_month IS NOT NULL))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation");
    $db->query('CREATE OR REPLACE VIEW student_out_periods AS SELECT * FROM student_out_periods_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');
    foreach(['i'=>'INSERT','u'=>'UPDATE','d'=>'DELETE'] as $e=>$event){
        $ref=$e==='d'?'OLD':'NEW';$body="IF current_unit_id() NOT BETWEEN 1 AND 3 OR $ref.unit_id<>current_unit_id() OR NOT EXISTS(SELECT 1 FROM siswa_data s WHERE s.id=$ref.student_id AND s.unit_id=$ref.unit_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Periode OUT lintas unit ditolak (out_v1)'; END IF;";
        if($e==='u')$body.="IF OLD.student_id<>NEW.student_id OR OLD.unit_id<>NEW.unit_id OR OLD.departed_at<>NEW.departed_at OR OLD.first_free_month<>NEW.first_free_month OR OLD.departure_year<>NEW.departure_year OR OLD.origin<>NEW.origin OR OLD.actor_id<>NEW.actor_id OR (OLD.returned_at IS NOT NULL AND (NOT(OLD.returned_at<=>NEW.returned_at) OR NOT(OLD.resume_month<=>NEW.resume_month))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar periode OUT tidak dapat diganti'; END IF;";
        if($e==='d')$body.="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Riwayat OUT dipertahankan';";
        $db->query('DROP TRIGGER IF EXISTS out_periods_'.$e);$db->query("CREATE TRIGGER out_periods_$e BEFORE $event ON student_out_periods_data FOR EACH ROW BEGIN $body END");
    }
    $db->query('DROP PROCEDURE IF EXISTS out_assert_student');
    $db->query("CREATE PROCEDURE out_assert_student(IN p_unit INT,IN p_nis VARCHAR(255)) BEGIN
        DECLARE v_id INT DEFAULT NULL;DECLARE v_out INT DEFAULT 0;
        SELECT s.id,(s.KELAS='OUT' OR COALESCE(m.jenis,'')='out' OR (s.master_kelas_id IS NULL AND EXISTS(SELECT 1 FROM legacy_op_owners_data o WHERE o.unit_id=s.unit_id AND o.student_id=s.id AND o.kind='out')) OR EXISTS(SELECT 1 FROM student_out_periods_data o WHERE o.student_id=s.id AND o.unit_id=s.unit_id AND o.returned_at IS NULL))
          INTO v_id,v_out FROM siswa_data s LEFT JOIN master_kelas_data m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.unit_id=p_unit AND s.NO_INDUK=p_nis FOR SHARE;
        IF v_out=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Siswa OUT tidak dapat melakukan transaksi (out_v2)'; END IF;
    END");
    $db->query('DROP PROCEDURE IF EXISTS out_assert_owner');
    $db->query("CREATE PROCEDURE out_assert_owner(IN p_unit INT,IN p_owner BIGINT) BEGIN
        DECLARE v_student INT DEFAULT NULL;DECLARE v_nis VARCHAR(255);DECLARE v_kind VARCHAR(20);
        SELECT student_id,kind INTO v_student,v_kind FROM legacy_op_owners_data WHERE id=p_owner AND unit_id=p_unit FOR SHARE;
        IF v_student IS NOT NULL THEN SELECT NO_INDUK INTO v_nis FROM siswa_data WHERE id=v_student AND unit_id=p_unit;CALL out_assert_student(p_unit,v_nis);
        ELSEIF v_kind='out' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rekening OUT tidak dapat melakukan transaksi (out_v1)'; END IF;
    END");
    foreach(sout_cash_sources() as $t=>[$kind,$column])foreach(['i'=>'INSERT','u'=>'UPDATE','d'=>'DELETE'] as $e=>$event){
        $calls='';foreach($e==='u'?['OLD','NEW']:[$e==='d'?'OLD':'NEW'] as $ref){
            if($kind==='student')$call="CALL out_assert_student($ref.unit_id,$ref.`$column`);";
            elseif($kind==='owner')$call="CALL out_assert_owner($ref.unit_id,$ref.$column);";
            else {
                $lookup=match($kind){'payment'=>"SELECT NO_INDUK FROM bayar_data WHERE id=$ref.$column AND unit_id=$ref.unit_id",'allocation'=>"SELECT no_induk FROM spp_alokasi_batch_data WHERE id=$ref.$column AND unit_id=$ref.unit_id",'identity'=>"SELECT NO_INDUK FROM siswa_data WHERE id=$ref.$column AND unit_id=$ref.unit_id"};
                $call="CALL out_assert_student($ref.unit_id,($lookup));";
            }
            $calls.=$call;
        }
        // Historical ingestion may append source evidence; operational corrections never use this context.
        if($e==='i'&&in_array($t,['legacy_op_payments','legacy_op_journals','legacy_full_payments','legacy_op_accounts','tabungan'],true))$calls="IF COALESCE(@legacy_full_apply,0)<>1 THEN $calls END IF;";
        $name='out_cash_'.$t.'_'.$e;$db->query('DROP TRIGGER IF EXISTS '.$name);$db->query("CREATE TRIGGER $name BEFORE $event ON {$t}_data FOR EACH ROW BEGIN /* out_v2 */ $calls END");
    }
    foreach(['tagihan_spp','tagihan_komite','tagihan_daftar_ulang'] as $t){
        if(in_array('extend_'.$t,$steps,true))$db->query("ALTER TABLE {$t}_data ADD out_period_id BIGINT NULL,ADD CONSTRAINT fk_out_$t FOREIGN KEY(out_period_id) REFERENCES student_out_periods_data(id)");
        $db->query("CREATE OR REPLACE VIEW $t AS SELECT * FROM {$t}_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
        $matches=$t==='tagihan_daftar_ulang'?"CAST(LEFT(NEW.tahun_ajaran_snapshot,4) AS UNSIGNED)>CAST(LEFT(o.departure_year,4) AS UNSIGNED) AND (o.returned_at IS NULL OR CAST(LEFT(NEW.tahun_ajaran_snapshot,4) AS UNSIGNED)<YEAR(o.resume_month)-IF(MONTH(o.resume_month)<7,1,0))":"CONCAT(NEW.tahun,'-',LPAD(NEW.bulan,2,'0'),'-01')>=o.first_free_month AND (o.resume_month IS NULL OR CONCAT(NEW.tahun,'-',LPAD(NEW.bulan,2,'0'),'-01')<o.resume_month)";
        foreach(['i'=>'INSERT','u'=>'UPDATE'] as $e=>$event){
            $body="IF NEW.out_period_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM student_out_periods_data o JOIN siswa_data s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE o.id=NEW.out_period_id AND o.unit_id=NEW.unit_id AND s.NO_INDUK=NEW.no_induk) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Dasar OUT tidak sesuai tagihan (out_v1)'; END IF;
            IF NEW.status='open' AND EXISTS(SELECT 1 FROM student_out_periods_data o JOIN siswa_data s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE o.unit_id=NEW.unit_id AND s.NO_INDUK=NEW.no_induk AND $matches) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Periode tagihan dibebaskan karena OUT (out_v1)'; END IF;";
            // Paid/credited invoices deliberately remain open when the exit service protects them.
            if($e==='u')$body=str_replace("IF NEW.status='open' AND EXISTS","IF NEW.status='open' AND OLD.status<>'open' AND EXISTS",$body);
            $lock=$e==='i'?"IF COALESCE(@legacy_full_apply,0)<>1 THEN CALL out_assert_student(NEW.unit_id,NEW.no_induk); END IF;":"IF NEW.status='open' AND OLD.status<>'open' THEN CALL out_assert_student(NEW.unit_id,NEW.no_induk); END IF;";
            $version=$t==='tagihan_daftar_ulang'?'out_v3':'out_v2';$name='out_bill_'.$t.'_'.$e;$db->query('DROP TRIGGER IF EXISTS '.$name);$db->query("CREATE TRIGGER $name BEFORE $event ON {$t}_data FOR EACH ROW BEGIN /* $version */ $lock $body END");
        }
    }
    unset($GLOBALS['sout_ready'][$db->thread_id]);if(!sout_ready($db))throw new RuntimeException('Skema OUT belum lengkap.');return count($steps);
}
