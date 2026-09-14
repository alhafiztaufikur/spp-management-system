<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

function class_test_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function class_test_student(mysqli $db, int $classId, bool $origin, float $spp): string {
    $nis = (string)random_int(9800000000, 9899999999);
    $name = $origin ? 'UJI SISWA ASAL PSB' : 'UJI SISWA REGULER';
    $level = '1';
    $asal = $origin ? 1 : 0;
    $psb = $origin ? 3600000.0 : 0.0;
    $komite = 100000.0;
    $stmt = $db->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,PSB,asal_psb,POMG) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssidddd', $nis, $name, $level, $classId, $spp, $psb, $asal, $komite);
    $stmt->execute(); $stmt->close();
    return $nis;
}

$failure = null;
try {
    $koneksi->begin_transaction();
    $suffix = (string)random_int(1000, 9999);
    $code = 'T' . $suffix;
    $stmt = $koneksi->prepare('INSERT INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES(1,?,0,1)');
    $stmt->bind_param('s', $code); $stmt->execute(); $classId = (int)$koneksi->insert_id; $stmt->close();

    $spp = 250000.0;
    $originNis = class_test_student($koneksi, $classId, true, $spp);
    $sync = null;
    $originPlacement = class_sync_student_current_year($koneksi, $originNis, $classId, $spp, 100000, true, $sync);
    $row = $koneksi->query('SELECT spp_perbulan_snapshot,spp_covered_by_psb FROM siswa_tahun_ajaran WHERE id=' . (int)$originPlacement)->fetch_assoc();
    class_test_assert((int)$row['spp_covered_by_psb'] === 1, 'Penempatan kelas 1 pertama siswa asal PSB tidak ditandai.');
    class_test_assert(abs((float)$row['spp_perbulan_snapshot']) < .001, 'SPP kelas 1 pertama siswa asal PSB tidak bernilai Rp0.');

    class_sync_student_current_year($koneksi, $originNis, $classId, 325000, 100000, true, $sync);
    $row = $koneksi->query('SELECT spp_perbulan_snapshot,spp_covered_by_psb FROM siswa_tahun_ajaran WHERE id=' . (int)$originPlacement)->fetch_assoc();
    class_test_assert((int)$row['spp_covered_by_psb'] === 1 && abs((float)$row['spp_perbulan_snapshot']) < .001, 'Sinkronisasi menimpa perlindungan SPP PSB.');

    $regularNis = class_test_student($koneksi, $classId, false, $spp);
    $regularPlacement = class_sync_student_current_year($koneksi, $regularNis, $classId, $spp, 100000, true, $sync);
    $row = $koneksi->query('SELECT spp_perbulan_snapshot,spp_covered_by_psb FROM siswa_tahun_ajaran WHERE id=' . (int)$regularPlacement)->fetch_assoc();
    class_test_assert((int)$row['spp_covered_by_psb'] === 0, 'Siswa non-PSB memperoleh perlindungan SPP.');
    class_test_assert(abs((float)$row['spp_perbulan_snapshot'] - $spp) < .001, 'Tarif SPP normal siswa non-PSB salah.');

    $date = date('Y-m-d H:i:s'); $month = date('m'); $year = date('Y'); $academicYear = du_current_academic_year();
    $stmt = $koneksi->prepare('INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,TGL_BYR,BULAN,TAHUN,th_ajaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,1)');
    $level = '1';
    $stmt->bind_param('ssdssssd', $regularNis, $level, $spp, $date, $month, $year, $academicYear, $spp);
    $stmt->execute(); $stmt->close();
    class_sync_student_current_year($koneksi, $regularNis, $classId, 325000, 125000, true, $sync);
    $row = $koneksi->query('SELECT spp_perbulan_snapshot,komite_snapshot FROM siswa_tahun_ajaran WHERE id=' . (int)$regularPlacement)->fetch_assoc();
    class_test_assert(abs((float)$row['spp_perbulan_snapshot'] - $spp) < .001, 'Snapshot SPP yang sudah dibayar ikut berubah.');
    class_test_assert(abs((float)$row['komite_snapshot'] - 125000) < .001, 'Komite yang belum dibayar tidak ikut disinkronkan.');
    class_test_assert(in_array('spp', $sync['locked'], true), 'SPP berbayar tidak dilaporkan terkunci.');

    $oldLabel = '1900/1901';
    $oldStart = '1900-07-01'; $oldEnd = '1901-06-30';
    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES(?,?,?,'closed')");
    $stmt->bind_param('sss', $oldLabel, $oldStart, $oldEnd); $stmt->execute(); $oldYearId = (int)$koneksi->insert_id; $stmt->close();
    $repeatNis = class_test_student($koneksi, $classId, true, $spp);
    $classText = '1'; $snapshot = '1' . $code; $status = 'pindah'; $zero = 0.0; $covered = 1; $komite = 100000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,spp_covered_by_psb,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('issisdids', $oldYearId, $repeatNis, $classText, $classId, $snapshot, $zero, $covered, $komite, $status);
    $stmt->execute(); $stmt->close();
    $repeatPlacement = class_sync_student_current_year($koneksi, $repeatNis, $classId, $spp, $komite, true, $sync);
    $row = $koneksi->query('SELECT spp_perbulan_snapshot,spp_covered_by_psb FROM siswa_tahun_ajaran WHERE id=' . (int)$repeatPlacement)->fetch_assoc();
    class_test_assert((int)$row['spp_covered_by_psb'] === 0, 'Pengulangan kelas 1 masih memperoleh fasilitas PSB.');
    class_test_assert(abs((float)$row['spp_perbulan_snapshot'] - $spp) < .001, 'SPP tahun berikutnya tidak kembali normal.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try { $koneksi->rollback(); } catch (Throwable $ignored) {}
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: cakupan PSB kelas 1 dan proteksi snapshot tarif tervalidasi.\n";
