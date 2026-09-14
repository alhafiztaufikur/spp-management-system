<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

function graduation_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$failure = null;
$koneksi->begin_transaction();
try {
    $class = $koneksi->query("SELECT id,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat=6 AND is_active=1 ORDER BY is_placeholder,id LIMIT 1")->fetch_assoc();
    graduation_assert((bool)$class, 'Kelas 6 aktif tidak tersedia.');
    $classId = (int)$class['id'];
    $nis = (string)random_int(9600000000, 9699999999);
    $name = 'UJI RIWAYAT LULUS'; $level = '6'; $spp = 300000.0; $komite = 100000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,?,?,1)');
    $stmt->bind_param('sssidd', $nis, $name, $level, $classId, $spp, $komite);
    $stmt->execute(); $stmt->close();

    $current = du_current_academic_year();
    $currentYearId = class_ensure_academic_year($koneksi, $current);
    $snapshot = class_label(['tingkat'=>6, 'kode_rombel'=>$class['kode_rombel'], 'is_placeholder'=>$class['is_placeholder']]);
    $status = 'aktif';
    $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->bind_param('issisdds', $currentYearId, $nis, $level, $classId, $snapshot, $spp, $komite, $status);
    $stmt->execute(); $stmt->close();

    $target = class_next_academic_year_label($current);
    $result = class_manual_graduate_student($koneksi, $nis, $target);
    graduation_assert($result['graduation_year'] === $current, 'Tahun kelulusan bukan tahun saat kelas 6 diselesaikan.');

    $stmt = $koneksi->prepare('SELECT ta.label AS tahun_ajaran,sta.kelas,sta.kelas_rombel_snapshot,sta.status FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=? ORDER BY ta.label');
    $stmt->bind_param('s', $nis); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    graduation_assert(count($rows) === 1, 'Kelulusan membuat penempatan palsu pada tahun ajaran berikutnya.');
    graduation_assert($rows[0]['tahun_ajaran'] === $current && $rows[0]['kelas'] === '6' && $rows[0]['status'] === 'lulus', 'Snapshot kelas 6 tidak dipertahankan sebagai titik kelulusan.');
    $stmt = $koneksi->prepare('SELECT is_active FROM siswa WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute();
    graduation_assert((int)$stmt->get_result()->fetch_assoc()['is_active'] === 0, 'Siswa lulus tidak diarsipkan.');
    $stmt->close();

    $studentPage = file_get_contents(__DIR__ . '/../siswa/daftar.php');
    graduation_assert(str_contains($studentPage, "sta.kelas IN ('1','2','3','4','5','6')"), 'Timeline belum mengecualikan masa PSB.');
    graduation_assert(str_contains($studentPage, 'student-class-history-toggle'), 'Kontrol expandable Riwayat Kelas belum tersedia.');
    graduation_assert(str_contains($studentPage, 'student-class-history-panel'), 'Panel Riwayat Kelas belum memakai struktur visual yang baru.');
    graduation_assert(str_contains($studentPage, 'student-class-timeline-item is-'), 'Timeline kelas belum memiliki penanda status visual.');

    $style = file_get_contents(__DIR__ . '/../assets/css/style.css');
    graduation_assert(str_contains($style, '.student-class-history-row[hidden]'), 'Status hidden timeline belum diamankan untuk tampilan mobile.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: tahun kelulusan, snapshot kelas 6, dan kontrak timeline tervalidasi.\n";
