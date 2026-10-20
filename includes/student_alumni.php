<?php
require_once __DIR__.'/kelas.php';

/** Current Alumni membership plus the recorded graduation event; never invent missing placements. */
function student_alumni_records(mysqli $db,array $students): array {
    if(!$students)return [];
    $ids=array_map('intval',array_column($students,'id'));$marks=implode(',',array_fill(0,count($ids),'?'));
    $s=$db->prepare("SELECT s.id,s.unit_id,s.NO_INDUK,m.id class_id,m.kode_rombel,m.graduation_year FROM siswa s JOIN master_kelas m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.id IN ($marks) AND m.legacy_kind='alumni'");
    $s->bind_param(str_repeat('i',count($ids)),...$ids);$s->execute();$records=[];
    foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r)$records[unit_student_key($r)]=['label'=>$r['kode_rombel'],'year'=>(int)$r['graduation_year'],'source_year'=>null,'class_id'=>(int)$r['class_id']];$s->close();
    if(!$records)return [];
    $s=$db->prepare("SELECT a.unit_id,a.no_induk_snapshot NO_INDUK,a.after_data FROM siswa_audit_log a WHERE a.siswa_id IN ($marks) AND a.aksi='lulus' ORDER BY a.id DESC");
    $s->bind_param(str_repeat('i',count($ids)),...$ids);$s->execute();
    foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r){$key=unit_student_key($r);if(!isset($records[$key])||$records[$key]['source_year']!==null)continue;
        $event=json_decode($r['after_data'],true);if((int)($event['alumni_class_id']??0)===$records[$key]['class_id'])$records[$key]['source_year']=$event['source_year']??null;
    }$s->close();return $records;
}

function student_has_graduated(mysqli $db,array $student): bool {
    $class=!empty($student['master_kelas_id'])?class_find($db,(int)$student['master_kelas_id']):null;
    if($class&&class_is_alumni($class))return true;
    $s=$db->prepare("SELECT id FROM siswa_tahun_ajaran WHERE unit_id=? AND no_induk=? AND status='lulus' LIMIT 1");
    $s->bind_param('is',$student['unit_id'],$student['NO_INDUK']);$s->execute();$yes=$s->get_result()->num_rows>0;$s->close();return $yes;
}
