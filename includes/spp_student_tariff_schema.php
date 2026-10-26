<?php
/** Additive installation only; never run DDL from a page request. */
function sct_schema_apply(mysqli $db): int {
    if(PHP_SAPI!=='cli')throw new LogicException('Pemasangan tarif khusus hanya melalui CLI.');
    $exists=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='spp_tarif_siswa_data'")->fetch_row()[0];
    $changed=0;
    if(!$exists){$db->query("CREATE TABLE spp_tarif_siswa_data (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        unit_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
        student_id INT NOT NULL,
        penempatan_id BIGINT NOT NULL,
        tahun_ajaran_id INT NOT NULL,
        period CHAR(7) NOT NULL,
        nominal_akhir DECIMAL(15,2) NOT NULL,
        revision INT UNSIGNED NOT NULL DEFAULT 1,
        reason VARCHAR(255) NOT NULL,
        actor_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_sct_student_period(unit_id,student_id,period),
        KEY idx_sct_year(unit_id,tahun_ajaran_id),
        FOREIGN KEY(unit_id,student_id) REFERENCES siswa_data(unit_id,id),
        FOREIGN KEY(penempatan_id) REFERENCES siswa_tahun_ajaran_data(id),
        FOREIGN KEY(tahun_ajaran_id) REFERENCES tahun_ajaran_data(id),
        FOREIGN KEY(actor_id) REFERENCES admin(id),
        CHECK(nominal_akhir>=0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");$changed++;}
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='spp_tarif_siswa'")->fetch_row()[0]){
        $db->query('CREATE VIEW spp_tarif_siswa AS SELECT * FROM spp_tarif_siswa_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');$changed++;
    }
    foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $event=>$side){
        $name='spp_tarif_siswa_data_'.strtolower(substr($event,0,1));
        if((int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row()[0])continue;
        $body="IF current_unit_id() NOT BETWEEN 1 AND 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pilih unit operasional'; END IF; ";
        if($event==='INSERT')$body.='SET NEW.unit_id=current_unit_id(); ';
        else $body.="IF $side.unit_id<>current_unit_id() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Relasi lintas unit ditolak'; END IF; ";
        if($event!=='DELETE')$body.="IF NOT EXISTS(SELECT 1 FROM siswa_tahun_ajaran_data p JOIN siswa_data s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id JOIN tahun_ajaran_data y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.id=NEW.penempatan_id AND p.unit_id=NEW.unit_id AND s.id=NEW.student_id AND p.tahun_ajaran_id=NEW.tahun_ajaran_id AND NEW.period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' AND NEW.period BETWEEN DATE_FORMAT(y.tanggal_mulai,'%Y-%m') AND DATE_FORMAT(y.tanggal_selesai,'%Y-%m')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Siswa, penempatan atau periode tarif tidak sesuai'; END IF; ";
        $db->query("CREATE TRIGGER $name BEFORE $event ON spp_tarif_siswa_data FOR EACH ROW BEGIN $body END");
        $changed++;
    }
    unset($GLOBALS['sct_ready'][$db->thread_id]);
    return $changed;
}
