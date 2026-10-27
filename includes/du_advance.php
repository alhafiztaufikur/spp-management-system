<?php
/** Early DU is a financial commitment, never an academic promotion. */
const DU_ADVANCE_VERSION=4;
function du_next_year(?string $year=null):string {$start=(int)substr($year??du_current_academic_year(),0,4)+1;return $start.'/'.($start+1);}
function du_publishable_year(string $year):bool {return in_array($year,[du_current_academic_year(),du_next_year()],true);}
function du_advance_schema_ready(mysqli $db,bool $refresh=false):bool {
    static $cache=[];if($refresh)unset($cache[$db->thread_id]);
    if(isset($cache[$db->thread_id]))return $cache[$db->thread_id];
    $columns=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND IS_NULLABLE='YES' AND ((TABLE_NAME IN ('tagihan_daftar_ulang_data','tagihan_daftar_ulang') AND COLUMN_NAME='penempatan_asal_id') OR (TABLE_NAME='tagihan_daftar_ulang_data' AND COLUMN_NAME='penempatan_id'))")->fetch_row()[0];
    $triggers=(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='tagihan_daftar_ulang_data' AND TRIGGER_NAME IN ('du_basis_insert','du_basis_update')")->fetch_row()[0];
    return $cache[$db->thread_id]=$columns===3&&$triggers===2;
}
function du_advance_schema_apply(mysqli $db):void {
    if(PHP_SAPI!=='cli')throw new LogicException('Gunakan pemasangan skema CLI yang dilindungi backup.');
    if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tagihan_daftar_ulang_data' AND COLUMN_NAME='penempatan_asal_id'")->fetch_row()[0])$db->query('ALTER TABLE tagihan_daftar_ulang_data ADD COLUMN penempatan_asal_id BIGINT NULL, MODIFY penempatan_id BIGINT NULL, ADD KEY idx_du_penempatan_asal(penempatan_asal_id), ADD CONSTRAINT fk_du_penempatan_asal FOREIGN KEY(penempatan_asal_id) REFERENCES siswa_tahun_ajaran_data(id) ON DELETE RESTRICT ON UPDATE CASCADE');
    // MySQL disallows CHECK on columns in cascading foreign keys. Equivalent
    // triggers enforce the presence of an academic or projection basis.
    foreach(['INSERT'=>'du_basis_insert','UPDATE'=>'du_basis_update'] as $event=>$name){
        if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->fetch_row()[0])$db->query("CREATE TRIGGER $name BEFORE $event ON tagihan_daftar_ulang_data FOR EACH ROW BEGIN IF NEW.penempatan_id IS NULL AND NEW.penempatan_asal_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tagihan DU wajib memiliki penempatan atau sumber proyeksi'; END IF; END");
    }
    $db->query('CREATE OR REPLACE VIEW tagihan_daftar_ulang AS SELECT * FROM tagihan_daftar_ulang_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');
    if(!du_advance_schema_ready($db,true))throw new RuntimeException('Skema penerbitan awal DU belum siap.');
}
/** Both old backups and new early invoices must retain strict unit/NIS/year relations. */
function du_invalid_relation_sql(mysqli $db):string {
    $normal="(p.id IS NOT NULL AND p.no_induk=t.no_induk AND p.tahun_ajaran_id=t.tahun_ajaran_id AND p.unit_id=t.unit_id)";
    if(!du_advance_schema_ready($db))return "SELECT COUNT(*) FROM tagihan_daftar_ulang t LEFT JOIN siswa_tahun_ajaran p ON p.id=t.penempatan_id WHERE COALESCE($normal,0)=0";
    $projection="(t.penempatan_id IS NULL AND a.id IS NOT NULL AND a.unit_id=t.unit_id AND a.no_induk=t.no_induk AND ty.label=t.tahun_ajaran_snapshot AND ty.label=CONCAT(CAST(LEFT(ay.label,4) AS UNSIGNED)+1,'/',CAST(RIGHT(ay.label,4) AS UNSIGNED)+1) AND CAST(t.kelas_snapshot AS UNSIGNED)=CAST(a.kelas AS UNSIGNED)+1 AND ((t.unit_id=1 AND a.kelas BETWEEN 1 AND 5) OR (t.unit_id=2 AND a.kelas BETWEEN 7 AND 8) OR (t.unit_id=3 AND a.kelas BETWEEN 10 AND 11)))";
    return "SELECT COUNT(*) FROM tagihan_daftar_ulang t LEFT JOIN siswa_tahun_ajaran p ON p.id=t.penempatan_id LEFT JOIN siswa_tahun_ajaran a ON a.id=t.penempatan_asal_id LEFT JOIN tahun_ajaran ay ON ay.id=a.tahun_ajaran_id AND ay.unit_id=a.unit_id LEFT JOIN tahun_ajaran ty ON ty.id=t.tahun_ajaran_id AND ty.unit_id=t.unit_id WHERE ty.id IS NULL OR ty.label<>t.tahun_ajaran_snapshot OR COALESCE(($normal OR $projection),0)=0";
}
function du_has_advance_bills(mysqli $db,string $year):bool {return du_advance_schema_ready($db)&&!empty(hp_one($db,'SELECT id FROM tagihan_daftar_ulang WHERE tahun_ajaran_snapshot=? AND penempatan_asal_id IS NOT NULL LIMIT 1',[$year]));}
/** Read-only; actual destination wins over a projection, including already promoted pupils. */
function du_target_roster(mysqli $db,string $year):array {
    $current=du_current_academic_year();$placements=[];$pending=[];
    foreach(hp_rows($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE y.label IN (?,?)',[$year,$current]) as $p)$placements[$p['no_induk']][$p['label']]=$p;
    if(du_advance_schema_ready($db))foreach(hp_rows($db,'SELECT * FROM tagihan_daftar_ulang WHERE tahun_ajaran_snapshot=? AND penempatan_asal_id IS NOT NULL',[$year]) as $b)$pending[$b['no_induk']]=$b;
    [$first,$last]=unit_level_bounds();$out=[];
    foreach(hp_rows($db,'SELECT s.id,s.unit_id,s.NO_INDUK,s.KELAS,s.master_kelas_id FROM siswa s LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.is_active=1 AND s.legacy_pending=0 AND CAST(s.KELAS AS UNSIGNED) BETWEEN ? AND ? AND (m.id IS NULL OR m.jenis=\'reguler\')',[$first,$last]) as $s){
        $nis=$s['NO_INDUK'];$target=$placements[$nis][$year]??null;$source=$placements[$nis][$current]??null;$bill=$pending[$nis]??null;$grade=null;$origin=null;$reason=null;
        if($target){if($target['status']!=='aktif')continue;$grade=(int)$target['kelas'];}
        elseif($bill){$grade=(int)$bill['kelas_snapshot'];$origin=(int)$bill['penempatan_asal_id'];}
        elseif($year===du_next_year()){
            if(!$source||$source['status']!=='aktif'||(int)$source['kelas']!==(int)$s['KELAS']){$reason='Riwayat kelas tahun berjalan belum sesuai.';}
            elseif((int)$source['kelas']===$last)continue;
            else{$grade=(int)$source['kelas']+1;$origin=(int)$source['id'];}
        }else{$grade=(int)$s['KELAS'];$reason='Penempatan tahun tujuan belum tersedia.';}
        if($grade!==null&&($grade<$first||$grade>$last))continue;
        $out[$nis]=['nis'=>$nis,'student_id'=>(int)$s['id'],'unit_id'=>(int)$s['unit_id'],'grade'=>$grade,'placement'=>$target,'source_placement_id'=>$origin,'reason'=>$reason];
    }return $out;
}
/** Validates an existing issued invoice without requiring promotion to have happened. */
function du_bill_relation_valid(mysqli $db,array $bill):bool {
    $year=(string)$bill['tahun_ajaran'];$nis=(string)$bill['no_induk'];$unit=(int)($bill['unit_id']??unit_active_id());
    if(!empty($bill['penempatan_id'])){$p=hp_one($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.id=?',[$bill['penempatan_id']]);return $p&&(int)$p['unit_id']===$unit&&$p['no_induk']===$nis&&$p['label']===$year;}
    if(empty($bill['penempatan_asal_id'])||!du_advance_schema_ready($db))return false;
    $p=hp_one($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.id=?',[$bill['penempatan_asal_id']]);
    if(!$p||(int)$p['unit_id']!==$unit||$p['no_induk']!==$nis||du_next_year($p['label'])!==$year||(int)$bill['kelas']!==(int)$p['kelas']+1)return false;
    $bounds=[1=>[1,6],2=>[7,9],3=>[10,12]][$unit]??[0,0];if((int)$p['kelas']<$bounds[0]||(int)$p['kelas']>=$bounds[1])return false;
    $actual=hp_one($db,'SELECT p.kelas,p.status FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND y.label=?',[$nis,$year]);
    return !$actual||($actual['status']==='aktif'&&(int)$actual['kelas']===(int)$bill['kelas']);
}
/** Caller owns transaction and year lock. No academic records or other fee bills are written. */
function du_publish_advance(mysqli $db,int $yearId,string $year,int $actor):array {
    require_once __DIR__.'/legacy_full_read.php';
    if(!du_advance_schema_ready($db))throw new DomainException('Peningkatan skema DU diperlukan sebelum menerbitkan tahun berikutnya.');
    $out=['created'=>0,'existing'=>0,'unknown'=>0,'covered_psb'=>0,'conflicts'=>0,'linked'=>0];$coverage=du_psb_reconcile($db,$year,$actor);$out['covered_psb']=$coverage['covered_psb'];
    foreach(du_target_roster($db,$year) as $r){
        if($r['reason']){$out['unknown']++;continue;}
        $student=lf_one($db,'SELECT s.is_active,s.KELAS,m.jenis FROM siswa s LEFT JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.id=? FOR UPDATE',[$r['student_id']]);
        if(!$student||!$student['is_active']||($student['jenis']!==null&&$student['jenis']!=='reguler'))continue;
        $bill=lf_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_id=? FOR UPDATE',[$r['nis'],$yearId]);
        if($bill){$out['existing']++;if($r['placement'])$out['linked']+=du_bind_advance_placement($db,(int)$r['placement']['id']);if((int)$bill['kelas_snapshot']!==$r['grade'])$out['conflicts']++;continue;}
        if(du_psb_initial_grade($r['unit_id'],$r['grade'])){if(!du_psb_state($db,$r['nis'],$year)['covered_psb'])$out['conflicts']++;continue;}
        if(lful_is_covered($db,$r['nis'],'daftar_ulang',$year)||hp_one($db,"SELECT d.id FROM legacy_debts d JOIN legacy_debt_masters m ON m.id=d.master_id AND m.unit_id=d.unit_id WHERE d.student_id=? AND m.period=? AND m.component IN ('DU','Daftar Ulang')",[$r['student_id'],$year])){$out['conflicts']++;continue;}
        $m=hp_one($db,'SELECT id,Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=? AND Jumlah>0',[$yearId,(string)$r['grade']]);if(!$m){$out['unknown']++;continue;}
        if($r['source_placement_id']){$p=lf_one($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.id=? FOR UPDATE',[$r['source_placement_id']]);if(!$p||$p['status']!=='aktif'||$p['no_induk']!==$r['nis']||(int)$p['unit_id']!==$r['unit_id']||du_next_year($p['label'])!==$year||(int)$p['kelas']+1!==$r['grade']||(int)$p['kelas']!==(int)$student['KELAS']){$out['conflicts']++;continue;}}
        lf_query($db,'INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,penempatan_asal_id,master_daftar_ulang_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?,?,?)',[$yearId,$r['placement']['id']??null,$r['source_placement_id'],$m['id'],$r['nis'],(string)$r['grade'],$year,$m['Jumlah'],$m['Jumlah']]);$id=(int)$db->insert_id;
        du_write_audit($db,$yearId,(int)$m['id'],'terbitkan_awal',null,['bill_id'=>$id,'no_induk'=>$r['nis'],'kelas'=>$r['grade'],'tahun_ajaran'=>$year,'penempatan_asal_id'=>$r['source_placement_id'],'penempatan_id'=>$r['placement']['id']??null,'nominal'=>$m['Jumlah'],'basis'=>'admin_advance_tariff','actor'=>$actor,'rule'=>DU_ADVANCE_VERSION],1);$out['created']++;
    }
    if(hp_one($db,'SELECT id FROM Daftar_ulang WHERE tahun_ajaran_id=? AND Jumlah>0 LIMIT 1',[$yearId]))du_publish_proven_legacy_year($db,$yearId,$year,$actor,'admin_advance_tariff');
    return $out;
}
function du_bind_advance_placement(mysqli $db,int $placementId):int {
    if(!du_advance_schema_ready($db))return 0;
    $p=hp_one($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.id=?',[$placementId]);if(!$p)return 0;
    $b=lf_one($db,'SELECT * FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_id=? AND penempatan_asal_id IS NOT NULL FOR UPDATE',[$p['no_induk'],$p['tahun_ajaran_id']]);if(!$b||$b['penempatan_id'])return 0;
    if((int)$b['kelas_snapshot']!==(int)$p['kelas']){
        $after=['bill_id'=>(int)$b['id'],'kelas_tagihan'=>$b['kelas_snapshot'],'kelas_tujuan'=>$p['kelas'],'penempatan_id'=>$placementId];
        if(!hp_one($db,"SELECT id FROM daftar_ulang_audit_log WHERE tahun_ajaran_id=? AND aksi='konflik_tujuan' AND after_data=?",[$p['tahun_ajaran_id'],lf_json($after)]))du_write_audit($db,(int)$p['tahun_ajaran_id'],(int)$b['master_daftar_ulang_id'],'konflik_tujuan',null,$after,0);
        return 0;
    }
    lf_query($db,'UPDATE tagihan_daftar_ulang SET penempatan_id=? WHERE id=? AND penempatan_id IS NULL',[$placementId,$b['id']]);
    du_write_audit($db,(int)$p['tahun_ajaran_id'],(int)$b['master_daftar_ulang_id'],'hubungkan_penempatan',['bill_id'=>(int)$b['id'],'penempatan_id'=>null],['bill_id'=>(int)$b['id'],'penempatan_id'=>$placementId,'penempatan_asal_id'=>(int)$b['penempatan_asal_id']],1);return 1;
}
