<?php
require_once __DIR__.'/kelas.php';

/** Caller owns transaction. Run before editing the student's active class. */
function student_prepare_class_transition(mysqli $db,?array $before,array $target): ?array {
    if (!$before || (int)$before['master_kelas_id']===(int)$target['id']) return null;
    $kind=class_kind($target);$oldCode=(string)$before['KELAS'];
    if ($kind!=='reguler' || !ctype_digit($oldCode) || (int)$oldCode===0 || (int)$oldCode===(int)$target['tingkat']) return null;
    $s=$db->prepare("SELECT p.id,p.tahun_ajaran_id,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND p.status='aktif' ORDER BY y.label DESC LIMIT 1 FOR UPDATE");
    $s->bind_param('s',$before['NO_INDUK']);$s->execute();$source=$s->get_result()->fetch_assoc();$s->close();
    if (!$source) throw new RuntimeException('Penempatan asal tidak tersedia. Periksa tahun ajaran siswa.');
    return promotion_apply_student($db,$before['NO_INDUK'],(int)$target['id'],promotion_context($db,(int)$source['tahun_ajaran_id']),(int)$source['id'],(int)$oldCode,false,true);
}

/** Restore membership without modifying any existing bill/payment snapshots. */
function student_restore_regular_membership(mysqli $db,?array $before,array $target): void {
    if (!$before || class_kind($target)!=='reguler' || !in_array((string)$before['KELAS'],['0','PSB','CUSTOM','OUT'],true)) return;
    $s=$db->prepare("SELECT p.id,p.status,p.kelas FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND y.label>=? AND p.status<>'dibatalkan' ORDER BY y.label DESC LIMIT 1 FOR UPDATE");
    $year=du_current_academic_year();$s->bind_param('ss',$before['NO_INDUK'],$year);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    if ($p && $p['status']!=='lulus') {
        if((int)$p['kelas']!==(int)$target['tingkat'])throw new RuntimeException('Tingkat tujuan berbeda dari riwayat penempatan yang tersimpan. Periksa riwayat kelas sebelum memulihkan penempatan.');
        // The normal synchronization below decides whether a paid rombel snapshot
        // must be retained. Restoring membership must not erase it beforehand.
        $s=$db->prepare("UPDATE siswa_tahun_ajaran SET status='aktif' WHERE id=?");
        $s->bind_param('i',$p['id']);$s->execute();$s->close();
    }
}
