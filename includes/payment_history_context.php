<?php
/** Context describes the student's placement when money is received, not the debt's year. */
function payment_history_current_context(mysqli $db,string $nis): array {
    $s=$db->prepare("SELECT y.label,p.kelas_rombel_snapshot,p.kelas FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND p.status='aktif' ORDER BY y.label DESC,p.id DESC LIMIT 1");
    $s->bind_param('s',$nis);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    if($p)return ['year'=>$p['label'],'class'=>$p['kelas_rombel_snapshot']?:$p['kelas']];
    $s=$db->prepare('SELECT s.KELAS,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id WHERE s.NO_INDUK=? LIMIT 1');$s->bind_param('s',$nis);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
    $label=isset($student['tingkat'])&&function_exists('class_label')?class_label($student):((string)($student['KELAS']??'')==='0'?'PSB':($student['KELAS']??'Tidak tercatat'));
    return ['year'=>du_current_academic_year(),'class'=>$label];
}
function payment_history_stamp(mysqli $db,int $paymentId,string $nis): void {
    $c=payment_history_current_context($db,$nis);
    $s=$db->prepare('UPDATE bayar SET riwayat_tahun_ajaran=?,riwayat_kelas_snapshot=? WHERE id=? AND riwayat_tahun_ajaran IS NULL');$s->bind_param('ssi',$c['year'],$c['class'],$paymentId);$s->execute();$s->close();
}
