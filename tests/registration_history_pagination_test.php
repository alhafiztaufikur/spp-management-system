<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_audit_database($koneksi);

function history_page_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$koneksi->begin_transaction();
try {
    $label = '2194/2195'; $start = '2194-07-01'; $end = '2195-06-30';
    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status,published_at) VALUES (?,?,?,'published',NOW())");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute();
    $yearId = (int)$koneksi->insert_id; $stmt->close();
    $class = '1'; $amount = 1000000.0;
    $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES (?,?,?,?)');
    $stmt->bind_param('issd', $yearId, $label, $class, $amount); $stmt->execute();
    $masterId = (int)$koneksi->insert_id; $stmt->close();

    for ($i = 1; $i <= 105; $i++) {
        $noInduk = '97' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
        $name = 'UJI PAGE ' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS) VALUES (?,?,?)');
        $stmt->bind_param('sss', $noInduk, $name, $class); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,status) VALUES (?,?,?,'aktif')");
        $stmt->bind_param('iss', $yearId, $noInduk, $class); $stmt->execute();
        $placementId = (int)$koneksi->insert_id; $stmt->close();
        $stmt = $koneksi->prepare("INSERT INTO tagihan_daftar_ulang
            (tahun_ajaran_id,penempatan_id,master_daftar_ulang_id,no_induk,kelas_snapshot,
             tahun_ajaran_snapshot,nominal_awal,nominal_tagihan,status)
            VALUES (?,?,?,?,?,?,?,?, 'open')");
        $stmt->bind_param('iiisssdd', $yearId, $placementId, $masterId, $noInduk, $class, $label, $amount, $amount);
        $stmt->execute(); $billId = (int)$koneksi->insert_id; $stmt->close();

        if ($i <= 20) {
            $paid = $i <= 10 ? 1000000.0 : 400000.0;
            $date = '2194-08-06 10:00:00'; $month = '08'; $year = '2194'; $note = 'UJI PAGE';
            $stmt = $koneksi->prepare("INSERT INTO bayar
                (NO_INDUK,KELAS,KETERANGAN,TGL_BYR,BULAN,TAHUN,total_jumlah,payment_link_version)
                VALUES (?,?,?,?,?,?,?,1)");
            $stmt->bind_param('ssssssd', $noInduk, $class, $note, $date, $month, $year, $paid);
            $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();
            $stmt = $koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('iisssd', $paymentId, $billId, $noInduk, $class, $label, $paid);
            $stmt->execute(); $stmt->close();
        }
    }

    $aggregate = "SELECT tdu.id, s.NAMA,
            tdu.nominal_tagihan master_total, COALESCE(SUM(bd.jumlah),0) paid,
            GREATEST(0,tdu.nominal_tagihan-COALESCE(SUM(bd.jumlah),0)) remaining
        FROM tagihan_daftar_ulang tdu
        JOIN tahun_ajaran ta ON ta.id=tdu.tahun_ajaran_id
        JOIN siswa s ON s.NO_INDUK=tdu.no_induk
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id
        WHERE ta.label='2194/2195' AND tdu.status='open'
        GROUP BY tdu.id,s.NAMA,tdu.nominal_tagihan";
    $summary = $koneksi->query("SELECT COUNT(*) total,SUM(master_total) bill,SUM(paid) paid,SUM(remaining) remaining FROM ($aggregate) x")->fetch_assoc();
    history_page_assert((int)$summary['total'] === 105, 'Ringkasan tidak menghitung seluruh 105 tagihan.');
    history_page_assert(abs((float)$summary['bill'] - 105000000) < .001, 'Total tagihan lintas halaman salah.');
    history_page_assert(abs((float)$summary['paid'] - 14000000) < .001, 'Total pembayaran lintas halaman salah.');
    history_page_assert(abs((float)$summary['remaining'] - 91000000) < .001, 'Total sisa lintas halaman salah.');

    $pageOne = $koneksi->query($aggregate . ' ORDER BY s.NAMA,tdu.id LIMIT 25 OFFSET 0')->fetch_all(MYSQLI_ASSOC);
    $pageTwo = $koneksi->query($aggregate . ' ORDER BY s.NAMA,tdu.id LIMIT 25 OFFSET 25')->fetch_all(MYSQLI_ASSOC);
    history_page_assert(count($pageOne) === 25 && count($pageTwo) === 25, 'Ukuran halaman 25 tidak konsisten.');
    history_page_assert($pageOne[0]['NAMA'] === 'UJI PAGE 001' && $pageTwo[0]['NAMA'] === 'UJI PAGE 026', 'Offset halaman tidak menghasilkan urutan global yang benar.');
    history_page_assert(count(array_intersect(array_column($pageOne, 'id'), array_column($pageTwo, 'id'))) === 0, 'Tagihan terduplikasi antarhalaman.');

    $settled = (int)$koneksi->query("SELECT COUNT(*) total FROM ($aggregate HAVING remaining <= 0.001) x")->fetch_assoc()['total'];
    $outstanding = (int)$koneksi->query("SELECT COUNT(*) total FROM ($aggregate HAVING remaining > 0.001) x")->fetch_assoc()['total'];
    history_page_assert($settled === 10 && $outstanding === 95, 'Filter Lunas/Belum Lunas tidak sesuai saldo agregat.');

    $koneksi->rollback();
    echo "OK: pagination 105 tagihan, ringkasan global, offset, deduplikasi, dan filter status.\n";
} catch (Throwable $error) {
    $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
