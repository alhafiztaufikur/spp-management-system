<?php
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/history_performance.php';
require_once __DIR__.'/legacy_operational_schema.php';
/** Search identities first; expensive receipt grouping never runs for a combobox. */
function history_search_rows(mysqli $db,string $context,string $q,string $source='all'):array {
    $parts=[];$args=[];$like='%'.$q.'%';
    if($source!=='legacy'){
        $exists=$context==='du'?" AND (EXISTS(SELECT 1 FROM tagihan_daftar_ulang t WHERE t.unit_id=s.unit_id AND t.no_induk=s.NO_INDUK) OR EXISTS(SELECT 1 FROM siswa_tahun_ajaran p WHERE p.unit_id=s.unit_id AND p.no_induk=s.NO_INDUK AND p.du_covered_by_psb=1))":" AND s.is_active=1";
        $parts[]="SELECT s.unit_id,s.id student_id,0 owner_id,s.NO_INDUK identity,s.NAMA name,s.NO_induk_diknas diknas,s.KELAS kelas FROM siswa s WHERE (s.NAMA LIKE ? OR s.NO_INDUK LIKE ? OR s.NO_induk_diknas LIKE ?)$exists";
        array_push($args,$like,$like,$like);
    }
    if($source!=='system'&&lop_ready($db)){
        $du=$context==='du'?" AND (p.source_table='bayar_du' OR JSON_SEARCH(p.items,'one','daftar_ulang',NULL,'$[*].category') IS NOT NULL)":'';
        $parts[]="SELECT o.unit_id,COALESCE(o.student_id,0) student_id,o.id owner_id,o.source_identity identity,o.name,COALESCE(s.NO_induk_diknas,'') diknas,COALESCE(s.KELAS,o.source_class,'') kelas FROM legacy_op_owners o JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE b.state='completed' AND (o.name LIKE ? OR o.source_identity LIKE ? OR s.NO_induk_diknas LIKE ?) AND EXISTS(SELECT 1 FROM legacy_op_payments p WHERE p.owner_id=o.id AND p.unit_id=o.unit_id AND p.cancelled=0$du)";
        array_push($args,$like,$like,$like);
    }
    if(!$parts)return [];
    // Keep one stable suggestion per identity and unit, including non-student owners.
    return hp_rows($db,"SELECT unit_id,MAX(student_id) student_id,MAX(owner_id) owner_id,identity,MIN(name) name,MAX(diknas) diknas,MAX(kelas) kelas FROM (".implode(' UNION ALL ',$parts).") candidates GROUP BY unit_id,CAST(identity AS BINARY),identity ORDER BY name,unit_id,identity LIMIT 20",$args);
}
