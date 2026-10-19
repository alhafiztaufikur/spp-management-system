<?php
/** A placement without a published SPP bill is not a paid obligation. */
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target();
require_once __DIR__ . '/../koneksi.php';
spp_test_assert_database_target($koneksi);
require_once __DIR__ . '/../includes/reports.php';

function unbilled_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$koneksi->begin_transaction();
try {
    foreach ([1 => 1, 2 => 7, 3 => 10] as $unit => $level) {
        $_SESSION['active_unit_id'] = $unit;
        unit_set_context($koneksi, $unit);
        $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=$level AND kode_rombel='A' AND is_placeholder=0 LIMIT 1")->fetch_assoc();
        $year = $koneksi->query("SELECT id FROM tahun_ajaran WHERE label='2026/2027' LIMIT 1")->fetch_assoc();
        unbilled_assert((bool)$class && (bool)$year, "Master kelas/tahun unit $unit tidak tersedia.");
        $classId = (int)$class['id'];
        $yearId = (int)$year['id'];
        $levelText = (string)$level;
        $classLabel = $level . 'A';
        $placedNis = (string)random_int(9800000000, 9899999999);
        $unplacedNis = (string)random_int(9800000000, 9899999999);
        foreach ([$placedNis, $unplacedNis] as $nis) {
            $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,'UJI TANPA TAGIHAN SPP',?,?,1)");
            $stmt->bind_param('ssi', $nis, $levelText, $classId);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
        $stmt->bind_param('issis', $yearId, $placedNis, $levelText, $classId, $classLabel);
        $stmt->execute();
        $placementId = (int)$koneksi->insert_id;
        $stmt->close();
        $stmt = $koneksi->prepare('SELECT COUNT(*) n FROM tagihan_spp WHERE penempatan_id=?');
        $stmt->bind_param('i', $placementId);
        $stmt->execute();
        unbilled_assert((int)$stmt->get_result()->fetch_assoc()['n'] === 0, 'Fixture tanpa tagihan ternyata ditagih.');
        $stmt->close();

        $base = ['template'=>'spp-tahunan', 'tahun_ajaran'=>'2026/2027', 'siswa_status'=>'active'];
        $unbilled = report_build($koneksi, 'spp-tahunan', report_filters($koneksi, $base + ['q'=>$placedNis]))['rows'];
        unbilled_assert(count($unbilled) === 1, "Penempatan tanpa tagihan unit $unit hilang.");
        unbilled_assert($unbilled[0]['kelas'] === $classLabel
            && (float)$unbilled[0]['total_tagihan'] === 0.0
            && (float)$unbilled[0]['total_bayar'] === 0.0
            && (float)$unbilled[0]['tunggakan'] === 0.0
            && $unbilled[0]['_status'] === 'Tidak Ditagihkan',
            "SPP tanpa tagihan unit $unit salah diklasifikasikan.");
        unbilled_assert(report_build($koneksi, 'spp-tahunan', report_filters($koneksi, $base + ['q'=>$placedNis, 'status'=>'lunas']))['rows'] === [],
            "SPP tanpa tagihan unit $unit masuk filter Lunas.");
        unbilled_assert(count(report_build($koneksi, 'spp-tahunan', report_filters($koneksi, $base + ['q'=>$placedNis, 'status'=>'tidak_ditagihkan']))['rows']) === 1,
            "SPP tanpa tagihan unit $unit tidak masuk filter Tidak Ditagihkan.");
        unbilled_assert(report_build($koneksi, 'spp-tahunan', report_filters($koneksi, $base + ['q'=>$unplacedNis]))['rows'] === [],
            "Siswa tanpa penempatan unit $unit diberi riwayat tahun yang tidak ada.");

        // A school may cancel unpaid future months after a student exits.
        // Preserve the original bill in history, but remove it from payable
        // SPP totals and outstanding debt.
        $master = $koneksi->query("SELECT id FROM master_spp_tahun WHERE tahun_ajaran_id=$yearId LIMIT 1")->fetch_assoc();
        unbilled_assert((bool)$master, "Master SPP unit $unit tidak tersedia.");
        $issued = spp_publish_students($koneksi, (int)$master['id'], [$placedNis]);
        unbilled_assert($issued['created']===12, "Tagihan SPP unit $unit gagal diterbitkan.");
        $stmt = $koneksi->prepare("UPDATE siswa SET is_active=0 WHERE NO_INDUK=?");
        $stmt->bind_param('s',$placedNis);$stmt->execute();$stmt->close();
        $stmt = $koneksi->prepare("UPDATE tagihan_spp SET status='cancelled',cancel_reason='Uji laporan' WHERE penempatan_id=? AND bulan='07' AND tahun='2026'");
        $stmt->bind_param('i',$placementId);$stmt->execute();
        unbilled_assert($stmt->affected_rows===1, "Pembatalan fixture SPP unit $unit gagal.");
        $stmt->close();
        $openBill = $koneksi->query("SELECT SUM(nominal_tagihan) total FROM tagihan_spp WHERE penempatan_id=$placementId AND status='open'")->fetch_assoc();
        $expectedOpen = (float)$openBill['total'];
        unbilled_assert($expectedOpen>0, "Tagihan aktif fixture unit $unit kosong.");
        $archived = array_replace($base, ['q'=>$placedNis,'siswa_status'=>'archived']);
        $annual = report_build($koneksi, 'spp-tahunan', report_filters($koneksi,$archived))['rows'];
        unbilled_assert(count($annual)===1
            && abs((float)$annual[0]['total_tagihan']-$expectedOpen)<.01
            && abs((float)$annual[0]['tunggakan']-$expectedOpen)<.01
            && $annual[0]['m07_2026']['text']==='Dibatalkan',
            "SPP dibatalkan unit $unit masih dihitung sebagai tunggakan tahunan.");
        $july = ['template'=>'per-item','kategori'=>'spp','q'=>$placedNis,
            'siswa_status'=>'archived','bulan_awal'=>'07','tahun_awal'=>2026,
            'bulan_akhir'=>'07','tahun_akhir'=>2026];
        $item = report_build($koneksi,'per-item',report_filters($koneksi,$july))['rows'];
        unbilled_assert(count($item)===1
            && (float)$item[0]['total_tagihan']===0.0
            && (float)$item[0]['tunggakan']===0.0
            && $item[0]['status']==='Dibatalkan',
            "Per Item unit $unit menganggap tagihan dibatalkan sebagai utang.");
        $status = report_build($koneksi,'status',report_filters($koneksi,[
            'template'=>'status','kategori'=>'spp','q'=>$placedNis,'siswa_status'=>'archived',
            'tahun_ajaran'=>'2026/2027','bulan_awal'=>'07']))['rows'];
        unbilled_assert(count($status)===1 && $status[0]['status']==='Dibatalkan'
            && (float)$status[0]['sisa']===0.0,
            "Status Pembayaran unit $unit menyisakan tagihan yang dibatalkan.");
        $history = report_build($koneksi,'riwayat-tagihan',report_filters($koneksi,[
            'template'=>'riwayat-tagihan','komponen_tagihan'=>'spp','status'=>'dibatalkan',
            'q'=>$placedNis,'siswa_status'=>'archived']))['rows'];
        unbilled_assert(count($history)===1 && $history[0]['status']==='Dibatalkan'
            && (float)$history[0]['sisa']===0.0,
            "Riwayat Tagihan unit $unit menyisakan tagihan yang dibatalkan.");
    }
    echo "OK: SPP belum terbit/tanpa penempatan dan tagihan dibatalkan direkap benar pada SD/SMP/SMA.\n";
} finally {
    $koneksi->rollback();
}
