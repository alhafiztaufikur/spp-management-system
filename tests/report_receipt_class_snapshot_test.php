<?php
/** Reconcile payment cash by the class snapshot of each transaction. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))) {
    throw new RuntimeException('Use a disposable audit clone with SPP_TEST_ALLOW_MUTATION=1.');
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';

function receipt_class_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$koneksi->begin_transaction();
try {
    foreach ([1=>[5,6], 2=>[8,9], 3=>[11,12]] as $unit=>$levels) {
        unit_set_context($koneksi, $unit);
        $nis = (string)random_int(9800000000, 9899999999);
        $oldClass = $levels[0] . 'A';
        $newClass = $levels[1] . 'A';
        $levelText = (string)$levels[1];
        $classRow = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat={$levels[1]} AND kode_rombel='A' AND is_placeholder=0 LIMIT 1")->fetch_assoc();
        receipt_class_assert((bool)$classRow, "Kelas tujuan unit $unit tidak tersedia.");
        $classId = (int)$classRow['id'];
        $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,'UJI KAS LINTAS KELAS',?,?,1)");
        $stmt->bind_param('ssi', $nis, $levelText, $classId);
        $stmt->execute(); $stmt->close();
        foreach ([[$oldClass,'2090-07-15 09:00:00',100.0],[$newClass,'2091-07-15 09:00:00',150.0]] as [$class,$date,$amount]) {
            $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,kelas_rombel_snapshot,U_PSB,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,?,?,?,?,?,'07','2091','1','Tunai')");
            $stmt->bind_param('sssdds', $nis, $levelText, $class, $amount, $amount, $date);
            $stmt->execute(); $stmt->close();
        }
        $filters = report_filters($koneksi, ['template'=>'penerimaan','q'=>$nis,
            'tanggal_awal'=>'2090-07-01','tanggal_akhir'=>'2091-07-31','kategori'=>'semua']);
        $report = report_build($koneksi, 'penerimaan', $filters);
        receipt_class_assert(count($report['rows']) === 2,
            "Rekap unit $unit menggabungkan pembayaran dari dua kelas.");
        $byClass = array_column($report['rows'], null, 'kelas');
        receipt_class_assert(count($byClass) === 2
            && abs((float)$byClass[$oldClass]['total_penerimaan']-100.0) < .01
            && abs((float)$byClass[$newClass]['total_penerimaan']-150.0) < .01,
            "Nominal rekap unit $unit tidak mengikuti snapshot kelas transaksi.");
        receipt_class_assert(abs(array_sum(array_column($report['rows'],'total_penerimaan'))-250.0)<.01,
            "Total kas rekap unit $unit berubah setelah pemisahan kelas.");
    }
    echo "OK: pembayaran lintas kenaikan dipisah menurut kelas transaksi di SD/SMP/SMA, total kas tetap sama.\n";
} finally {
    $koneksi->rollback();
}
