<?php
/** Per Item Daftar Ulang must use the bill year and cumulative paid amount. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target($koneksi);

function du_item_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$nis = (string)random_int(9800000000, 9899999999);
$years = ['2180/2181', '2181/2182'];
$amounts = [1000000.0, 2000000.0];
$failure = null;
$koneksi->begin_transaction();
try {
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label IN (?,?)');
    $stmt->bind_param('ss', $years[0], $years[1]); $stmt->execute();
    du_item_assert((int)$stmt->get_result()->fetch_row()[0] === 0, 'Tahun fixture sudah ada.');
    $stmt->close();

    $classes = [];
    foreach ([1, 2] as $level) {
        $stmt = $koneksi->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
        $stmt->bind_param('i', $level); $stmt->execute();
        $classes[$level] = (int)($stmt->get_result()->fetch_row()[0] ?? 0); $stmt->close();
        du_item_assert($classes[$level] > 0, 'Rombel fixture tidak tersedia.');
    }

    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,'UJI PER ITEM DU','2',?,1)");
    $stmt->bind_param('si', $nis, $classes[2]); $stmt->execute(); $stmt->close();
    foreach ($years as $index => $label) {
        $start = 2180 + $index;
        $begin = $start . '-07-01'; $end = ($start + 1) . '-06-30';
        $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES(?,?,?,'published')");
        $stmt->bind_param('sss', $label, $begin, $end); $stmt->execute();
        $yearId = (int)$koneksi->insert_id; $stmt->close();
        $level = $index + 1; $levelText = (string)$level; $classLabel = $levelText . 'A';
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
        $stmt->bind_param('issis', $yearId, $nis, $levelText, $classes[$level], $classLabel);
        $stmt->execute(); $placementId = (int)$koneksi->insert_id; $stmt->close();
        $bill = $amounts[$index];
        $stmt = $koneksi->prepare('INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?)');
        $stmt->bind_param('iisssdd', $yearId, $placementId, $nis, $levelText, $label, $bill, $bill);
        $stmt->execute(); $billId = (int)$koneksi->insert_id; $stmt->close();
        if ($index === 0) {
            $date = '2182-10-01 08:00:00';
            $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,'1',?,'1A',400000,?,'10','2182','1','Tunai')");
            $stmt->bind_param('sis', $nis, $classes[1], $date);
            $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();
            $paid = 400000.0;
            $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,?)');
            $stmt->bind_param('iisssd', $paymentId, $billId, $nis, $levelText, $label, $paid);
            $stmt->execute(); $stmt->close();
        }
    }

    $base = ['template'=>'per-item', 'kategori'=>'daftar_ulang', 'q'=>$nis,
        'tanggal_awal'=>'2183-01-01', 'tanggal_akhir'=>'2183-01-01'];
    $old = report_build($koneksi, 'per-item', report_filters($koneksi, $base + ['tahun_ajaran'=>$years[0]]))['rows'];
    $new = report_build($koneksi, 'per-item', report_filters($koneksi, $base + ['tahun_ajaran'=>$years[1]]))['rows'];
    du_item_assert(count($old) === 1 && count($new) === 1, 'Per Item DU kehilangan penempatan tahun terkait.');
    du_item_assert($old[0]['kelas'] === '1A' && (float)$old[0]['tagihan'] === 1000000.0
        && (float)$old[0]['terbayar'] === 400000.0 && (float)$old[0]['sisa'] === 600000.0,
        'Per Item DU tahun lama harus memakai kelas, tagihan, dan pembayaran kumulatif tahun lama.');
    du_item_assert($new[0]['kelas'] === '2A' && (float)$new[0]['tagihan'] === 2000000.0
        && (float)$new[0]['terbayar'] === 0.0 && (float)$new[0]['sisa'] === 2000000.0,
        'Per Item DU tahun baru tercampur pembayaran tahun lama.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
}

if ($failure) { fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL); exit(1); }
echo "PASS: Per Item DU memisahkan tagihan, kelas, dan pembayaran kumulatif per tahun.\n";
