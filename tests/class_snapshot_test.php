<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

function class_test_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$failure = null;
try {
    $koneksi->begin_transaction();
    $suffix = (string)random_int(1000, 9999);
    $codeA = 'T' . $suffix . 'A';
    $codeB = 'T' . $suffix . 'B';
    $stmt = $koneksi->prepare('INSERT INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES(1,?,0,1)');
    $stmt->bind_param('s', $codeA); $stmt->execute(); $classA = (int)$koneksi->insert_id;
    $stmt->bind_param('s', $codeB); $stmt->execute(); $classB = (int)$koneksi->insert_id;
    $stmt->close();

    $nis = (string)random_int(9800000000, 9899999999);
    $name = 'UJI PROTEKSI TARIF';
    $level = '1';
    $spp = 250000.0;
    $pangkal = 1000000.0;
    $bangunan = 0.0;
    $komite = 100000.0;
    $daftarUlang = 800000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,PANGKAL,tot_pangkal,BANGUNAN,POMG,DAFTAR_ULANG,tot_du) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssiddddddd', $nis, $name, $level, $classA, $spp, $pangkal, $pangkal, $bangunan, $komite, $daftarUlang, $daftarUlang);
    $stmt->execute();
    $stmt->close();

    $initialSync = null;
    $placement = class_sync_student_current_year($koneksi, $nis, $classA, $spp, $komite, true, $initialSync);
    class_test_assert((int)$placement > 0, 'Penempatan awal tidak terbentuk.');
    $duBillId = du_create_bill_for_placement($koneksi, $placement, false);
    class_test_assert((int)$duBillId > 0, 'Tagihan Daftar Ulang awal tidak terbentuk.');

    $academicYear = du_current_academic_year();
    $month = date('m');
    $year = date('Y');
    $date = date('Y-m-d H:i:s');
    $classLabel = '1' . $codeA;
    $paidSpp = $spp;
    $paidPangkal = 100000.0;
    $totalPaid = $paidSpp + $paidPangkal;
    $stmt = $koneksi->prepare('INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_SPP,U_PANGKAL,TGL_BYR,BULAN,TAHUN,th_ajaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)');
    $stmt->bind_param('ssisddssssd', $nis, $level, $classA, $classLabel, $paidSpp, $paidPangkal, $date, $month, $year, $academicYear, $totalPaid);
    $stmt->execute();
    $paymentId = (int)$koneksi->insert_id;
    $stmt->close();

    $stmt = $koneksi->prepare("SELECT id FROM tagihan_tahunan_siswa WHERE penempatan_id=? AND komponen='pangkal' LIMIT 1");
    $stmt->bind_param('i', $placement); $stmt->execute();
    $pangkalBillId = (int)$stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    $component = 'pangkal';
    $stmt = $koneksi->prepare('INSERT INTO bayar_tahunan_siswa(bayar_id,tagihan_tahunan_id,no_induk,komponen,th_ajaran,jumlah) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('iisssd', $paymentId, $pangkalBillId, $nis, $component, $academicYear, $paidPangkal);
    $stmt->execute();
    $stmt->close();
    $paidDu = 100000.0;
    $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('iisssd', $paymentId, $duBillId, $nis, $level, $academicYear, $paidDu);
    $stmt->execute();
    $stmt->close();

    $newSpp = 300000.0;
    $newPangkal = 1200000.0;
    $newBangunan = 150000.0;
    $newKomite = 125000.0;
    $newDaftarUlang = 900000.0;
    $stmt = $koneksi->prepare('UPDATE siswa SET KELAS=?,master_kelas_id=?,SPP_PERBULAN=?,PANGKAL=?,tot_pangkal=?,BANGUNAN=?,POMG=?,DAFTAR_ULANG=?,tot_du=? WHERE NO_INDUK=?');
    $stmt->bind_param('siddddddds', $level, $classB, $newSpp, $newPangkal, $newPangkal, $newBangunan, $newKomite, $newDaftarUlang, $newDaftarUlang, $nis);
    $stmt->execute();
    $stmt->close();

    $sync = null;
    class_sync_student_current_year($koneksi, $nis, $classB, $newSpp, $newKomite, true, $sync);
    $stmt = $koneksi->prepare('SELECT master_kelas_id,spp_perbulan_snapshot,komite_snapshot FROM siswa_tahun_ajaran WHERE id=?');
    $stmt->bind_param('i', $placement); $stmt->execute();
    $snapshot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    class_test_assert((int)$snapshot['master_kelas_id'] === $classA, 'Kelas histori berubah setelah pembayaran.');
    class_test_assert(abs((float)$snapshot['spp_perbulan_snapshot'] - $spp) < .001, 'Snapshot SPP berbayar ikut berubah.');
    class_test_assert(abs((float)$snapshot['komite_snapshot'] - $newKomite) < .001, 'Komite tanpa pembayaran tidak ikut diperbarui.');
    class_test_assert(in_array('spp', $sync['locked'], true), 'SPP berbayar tidak dilaporkan terkunci.');

    $stmt = $koneksi->prepare("SELECT komponen,nominal_tagihan FROM tagihan_tahunan_siswa WHERE penempatan_id=? AND komponen IN ('pangkal','bangunan')");
    $stmt->bind_param('i', $placement); $stmt->execute();
    $bills = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $bill) $bills[$bill['komponen']] = (float)$bill['nominal_tagihan'];
    $stmt->close();
    class_test_assert(abs($bills['pangkal'] - $pangkal) < .001, 'Pangkal berbayar ikut berubah.');
    class_test_assert(abs($bills['bangunan'] - $newBangunan) < .001, 'Bangunan tanpa pembayaran tidak tersinkron.');
    class_test_assert(in_array('pangkal', $sync['locked'], true), 'Pangkal berbayar tidak dilaporkan terkunci.');
    class_test_assert(in_array('bangunan', $sync['synced'], true), 'Bangunan aman tidak dilaporkan tersinkron.');

    $duSync = du_reconcile_current_student_override($koneksi, $nis);
    class_test_assert($duSync['status'] === 'locked', 'Daftar Ulang berbayar tidak dilaporkan terkunci.');
    $stmt = $koneksi->prepare('SELECT nominal_tagihan FROM tagihan_daftar_ulang WHERE id=?');
    $stmt->bind_param('i', $duBillId); $stmt->execute();
    $duTotal = (float)$stmt->get_result()->fetch_assoc()['nominal_tagihan'];
    $stmt->close();
    class_test_assert(abs($duTotal - $daftarUlang) < .001, 'Daftar Ulang berbayar ikut berubah.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try { $koneksi->rollback(); } catch (Throwable $ignored) {}
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: snapshot kelas dipertahankan dan tarif dikunci per komponen pembayaran.\n";
