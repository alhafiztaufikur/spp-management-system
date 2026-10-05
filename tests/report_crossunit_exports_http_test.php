<?php
/**
 * Export reconciliation on a disposable clone after
 * historical_reports_after_promotion_test.php was run with
 * SPP_TEST_PERSIST_FIXTURE=1. Cash snapshot payments are removed in finally.
 * The caller owns the clone and HTTP server.
 */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))
    || !preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string)getenv('SPP_HTTP_BASE'))) {
    throw new RuntimeException('Use an audit clone and a loopback SPP_HTTP_BASE.');
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/http_form_scope.php';
spp_test_assert_http_clone((string)getenv('SPP_HTTP_BASE'), DB_NAME);
require_once __DIR__ . '/../includes/reports.php';

function matrix_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function matrix_money(float $value): string {
    return 'Rp ' . number_format($value, 0, ',', '.');
}
function matrix_get(string $path, string $session): array {
    $url = rtrim((string)getenv('SPP_HTTP_BASE'), '/') . '/' . ltrim($path, '/');
    $context = stream_context_create(['http'=>[
        'method'=>'GET', 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>45,
        'header'=>'Cookie: ' . session_name() . '=' . $session . "\r\n",
    ]]);
    $body = file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    matrix_assert(str_contains($headers[0] ?? '', '200') && $body !== false,
        'HTTP bukan 200: ' . $path . ' (' . ($headers[0] ?? '') . ')');
    return [$headers, (string)$body];
}
function matrix_pdf_text(string $binary): string {
    matrix_assert(str_starts_with($binary, '%PDF-'), 'Ekspor PDF bukan berkas PDF biner.');
    $modulePath = (string)getenv('SPP_PYMUPDF_PATH');
    $python = (string)getenv('SPP_PDF_PYTHON');
    matrix_assert($modulePath !== '' && is_dir($modulePath) && $python !== '' && is_file($python),
        'Set SPP_PYMUPDF_PATH dan SPP_PDF_PYTHON untuk verifikasi teks PDF.');
    $pdfPath = tempnam(sys_get_temp_dir(), 'spp_matrix_');
    matrix_assert($pdfPath !== false && file_put_contents($pdfPath, $binary) === strlen($binary),
        'PDF sementara tidak dapat disimpan.');
    try {
        $code = 'import sys; sys.path.insert(0,sys.argv[1]); import pymupdf; d=pymupdf.open(sys.argv[2]); print("\\n".join(p.get_text() for p in d))';
        $process = proc_open([$python, '-c', $code, $modulePath, $pdfPath],
            [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
        matrix_assert(is_resource($process), 'Python PDF tidak tersedia.');
        fclose($pipes[0]);
        $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        matrix_assert(proc_close($process) === 0, 'Ekstraksi PDF gagal: ' . trim($error));
        return (string)$text;
    } finally {
        unlink($pdfPath);
    }
}
function matrix_export(string $template, array $params, string $session, array $expected,
    bool $pdf = false): void {
    $query = http_build_query(['template'=>$template] + $params);
    [$headers, $screen] = matrix_get('laporan/template.php?' . $query, $session);
    [$headers, $excel] = matrix_get('laporan/export_global.php?' . $query . '&format=excel&download=1', $session);
    matrix_assert(str_contains(strtolower(implode("\n", $headers)), 'application/vnd.ms-excel'),
        "{$template}: respons Excel salah.");
    foreach (['layar'=>$screen, 'Excel'=>$excel] as $surface=>$body) {
        foreach ($expected as $value) matrix_assert(str_contains($body, (string)$value),
            "{$template} {$surface} tidak memuat {$value}.");
        matrix_assert(!str_contains($body, 'Gagal memuat laporan'), "{$template} {$surface} error.");
    }
    if ($pdf) {
        [$headers, $binary] = matrix_get('laporan/export_global.php?' . $query . '&format=pdf&download=1', $session);
        matrix_assert(str_contains(strtolower(implode("\n", $headers)), 'application/pdf'),
            "{$template}: respons PDF salah.");
        $text = matrix_pdf_text($binary);
        foreach ($expected as $value) matrix_assert(str_contains($text, (string)$value),
            "{$template} PDF tidak memuat {$value}.");
    }
}
function matrix_sum(mysqli $db, string $sql): float {
    return (float)($db->query($sql)->fetch_assoc()['total'] ?? 0);
}

$super = $koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
matrix_assert((bool)$super, 'Akun super admin fixture tidak tersedia.');
$sessions = [];
$results = [];
$unitCash = [];
$historicalFixtures = [];
try {
    foreach ([1=>'SD', 2=>'SMP', 3=>'SMA'] as $unit=>$label) {
        unit_set_context($koneksi, $unit);
        $stmt = $koneksi->prepare('SELECT NO_INDUK FROM siswa WHERE NAMA=? AND is_active=0 ORDER BY id DESC LIMIT 1');
        $name = 'UJI HISTORIS ' . $label;
        $stmt->bind_param('s', $name); $stmt->execute();
        $fixture = $stmt->get_result()->fetch_assoc(); $stmt->close();
        matrix_assert((bool)$fixture, "Fixture lulusan {$label} tidak ada.");
        $nis = (string)$fixture['NO_INDUK'];
        $stmt = $koneksi->prepare("SELECT ta.label,sta.kelas_rombel_snapshot,sta.id FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=? AND ta.label IN ('2090/2091','2091/2092') ORDER BY ta.label");
        $stmt->bind_param('s', $nis); $stmt->execute();
        $placements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        matrix_assert(count($placements) === 2, "Riwayat dua tahun {$label} tidak lengkap.");
        $oldClass = (string)$placements[0]['kelas_rombel_snapshot'];
        $newClass = (string)$placements[1]['kelas_rombel_snapshot'];
        $oldId = (int)$placements[0]['id']; $newId = (int)$placements[1]['id'];
        $historicalFixtures[$unit] = ['nis'=>$nis, 'oldId'=>$oldId,
            'oldClass'=>$oldClass, 'newClass'=>$newClass,
            'oldLevel'=>(string)intval($oldClass), 'newLevel'=>(string)intval($newClass)];
        $oldMonth = matrix_sum($koneksi, "SELECT SUM(nominal_tagihan) total FROM tagihan_spp WHERE penempatan_id=$oldId AND tahun='2090' AND bulan='07'");
        $newMonth = matrix_sum($koneksi, "SELECT SUM(nominal_tagihan) total FROM tagihan_spp WHERE penempatan_id=$newId AND tahun='2091' AND bulan='07'");
        $oldYear = matrix_sum($koneksi, "SELECT SUM(nominal_tagihan) total FROM tagihan_spp WHERE penempatan_id=$oldId");
        $newYear = matrix_sum($koneksi, "SELECT SUM(nominal_tagihan) total FROM tagihan_spp WHERE penempatan_id=$newId");
        $oldKomite = matrix_sum($koneksi, "SELECT SUM(nominal_tagihan) total FROM tagihan_komite WHERE penempatan_id=$oldId AND tahun='2090' AND bulan='07'");
        $newKomite = matrix_sum($koneksi, "SELECT SUM(nominal_tagihan) total FROM tagihan_komite WHERE penempatan_id=$newId AND tahun='2091' AND bulan='07'");
        matrix_assert($oldMonth > 0 && $newMonth > 0 && $oldYear > $oldMonth && $newYear > $newMonth,
            "Snapshot tagihan {$label} tidak lengkap.");
        matrix_assert($oldKomite > 0 && $newKomite > 0, "Snapshot Komite {$label} tidak lengkap.");
        $session = 'matrix' . $unit . bin2hex(random_bytes(6));
        session_id($session); session_start();
        $_SESSION = ['admin_id'=>(int)$super['id'], 'admin_role'=>'super_admin',
            'active_unit_id'=>$unit, 'admin_nama'=>'Audit Rekap'];
        session_write_close();
        $sessions[] = $session;
        $common = ['unit'=>'active','q'=>$nis,'siswa_status'=>'archived'];
        matrix_export('status', $common + ['kategori'=>'spp','tahun_ajaran'=>'2090/2091','bulan_awal'=>'07'],
            $session, [$nis,$oldClass,matrix_money($oldMonth)], true);
        matrix_export('status', $common + ['kategori'=>'komite','tahun_ajaran'=>'2090/2091','bulan_awal'=>'07'],
            $session, [$nis,$oldClass,matrix_money($oldKomite)]);
        matrix_export('spp-tahunan', $common + ['tahun_ajaran'=>'2090/2091'],
            $session, [$nis,$oldClass,matrix_money($oldYear)]);
        matrix_export('spp-tahunan', $common + ['tahun_ajaran'=>'2091/2092'],
            $session, [$nis,$newClass,matrix_money($newYear)]);
        matrix_export('per-item', $common + ['kategori'=>'spp','bulan_awal'=>'06','tahun_awal'=>2091,
            'bulan_akhir'=>'07','tahun_akhir'=>2091],
            $session, [$nis,$oldClass,$newClass,matrix_money($newMonth)], true);
        matrix_export('per-item', $common + ['kategori'=>'komite','bulan_awal'=>'06','tahun_awal'=>2091,
            'bulan_akhir'=>'07','tahun_akhir'=>2091],
            $session, [$nis,$oldClass,$newClass,matrix_money($oldKomite),matrix_money($newKomite)], $unit===2);
        $history = report_build($koneksi, 'riwayat-tagihan', report_filters($koneksi,
            ['template'=>'riwayat-tagihan','q'=>$nis,'siswa_status'=>'archived','komponen_tagihan'=>'spp']));
        matrix_assert(count($history['rows']) === 24
            && abs(array_sum(array_column($history['rows'],'tagihan')) - ($oldYear+$newYear)) < .01,
            "Riwayat Tagihan {$label} tidak sama dengan 24 tagihan sumber.");
        matrix_export('riwayat-tagihan', $common + ['komponen_tagihan'=>'spp'],
            $session, [$nis,$oldClass,$newClass,matrix_money($oldYear+$newYear)], $unit===1);

        $empty = ['unit'=>'active','q'=>$nis,'siswa_status'=>'archived','tahun_ajaran'=>'2089/2090'];
        [$headers, $emptyScreen] = matrix_get('laporan/template.php?' . http_build_query(['template'=>'spp-tahunan']+$empty), $session);
        matrix_assert(str_contains($emptyScreen,'0 baris'), "Tahun tanpa penempatan {$label} direkonstruksi.");
        [$headers, $emptyExcel] = matrix_get('laporan/export_global.php?' . http_build_query(['template'=>'spp-tahunan']+$empty)
            . '&format=excel&download=1', $session);
        matrix_assert(!str_contains($emptyExcel,$nis)
            && str_contains($emptyExcel,'Tidak ada data pada filter terpilih.'),
            "Excel tahun tanpa penempatan {$label} tidak kosong.");
        if ($unit===3) {
            [$headers, $emptyPdf] = matrix_get('laporan/export_global.php?'
                . http_build_query(['template'=>'spp-tahunan']+$empty) . '&format=pdf&download=1', $session);
            $emptyText = matrix_pdf_text($emptyPdf);
            matrix_assert(!str_contains($emptyText,$nis)
                && str_contains($emptyText,'Tidak ada data pada filter terpilih.'),
                'PDF tahun sebelum penempatan pertama mengarang riwayat.');
        }

        $cashStart = '2026-07-01'; $cashEnd = '2027-02-28';
        $cashSource = matrix_sum($koneksi,
            "SELECT SUM(total_jumlah) total FROM bayar WHERE TGL_BYR>='$cashStart 00:00:00' AND TGL_BYR<'2027-03-01 00:00:00'");
        $unitCash[$unit] = $cashSource;
        $cashFilters = report_filters($koneksi,['template'=>'penerimaan','tanggal_awal'=>$cashStart,
            'tanggal_akhir'=>$cashEnd,'kategori'=>'semua']);
        $daily = report_build($koneksi,'penerimaan',$cashFilters);
        $setoran = report_build($koneksi,'setoran',report_filters($koneksi,
            ['template'=>'setoran','tanggal_awal'=>$cashStart,'tanggal_akhir'=>$cashEnd]));
        matrix_assert(abs(array_sum(array_column($daily['rows'],'total_penerimaan'))-$cashSource)<.01
            && abs((float)$setoran['total_setoran']-$cashSource)<.01,
            "Rekap penerimaan/setoran {$label} berbeda dari header pembayaran.");
        matrix_export('penerimaan', ['unit'=>'active','tanggal_awal'=>$cashStart,'tanggal_akhir'=>$cashEnd,
            'kategori'=>'semua'], $session, [matrix_money($cashSource)]);
        matrix_export('setoran', ['unit'=>'active','tanggal_awal'=>$cashStart,'tanggal_akhir'=>$cashEnd],
            $session, [matrix_money($cashSource)], $unit===1);
        $results[] = "OK {$label}: status, SPP tahunan, Per Item SPP/Komite, Riwayat Tagihan, tahun kosong, penerimaan, setoran; sumber kas "
            . matrix_money($cashSource) . ".";
    }
    unit_set_context($koneksi, 0);
    $allCash = matrix_sum($koneksi,
        "SELECT SUM(total_jumlah) total FROM bayar WHERE TGL_BYR>='2026-07-01 00:00:00' AND TGL_BYR<'2027-03-01 00:00:00'");
    matrix_assert(abs($allCash-array_sum($unitCash))<.01,
        'Total header Semua Unit tidak sama dengan gabungan SD/SMP/SMA.');
    $allSetoran = report_build($koneksi, 'setoran', report_filters($koneksi,
        ['template'=>'setoran','tanggal_awal'=>'2026-07-01','tanggal_akhir'=>'2027-02-28']));
    matrix_assert(abs((float)$allSetoran['total_setoran']-$allCash)<.01,
        'Rekap Setoran Semua Unit berbeda dari header sumber.');
    matrix_export('penerimaan', ['unit'=>'all','tanggal_awal'=>'2026-07-01',
        'tanggal_akhir'=>'2027-02-28','kategori'=>'semua'],
        $sessions[0], [matrix_money($allCash)]);
    matrix_export('setoran', ['unit'=>'all','tanggal_awal'=>'2026-07-01',
        'tanggal_akhir'=>'2027-02-28'], $sessions[0], [matrix_money($allCash)], true);
    $results[] = 'OK Semua Unit: kas ' . matrix_money($allCash) . ' = jumlah tiga unit pada layar, Excel, PDF.';

    // A student paid before and after promotion must occupy two cash rows,
    // each under the class snapshot of its own payment header.
    foreach ($historicalFixtures as $unit=>$fixture) {
        $_SESSION['active_unit_id']=$unit;
        unit_set_context($koneksi, $unit);
        $nis = $fixture['nis'];
        $stmt = $koneksi->prepare('SELECT PSB FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s',$nis);$stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();$stmt->close();
        matrix_assert((bool)$original, 'Siswa fixture kas historis hilang.');
        $paymentIds = [];
        try {
            $stmt = $koneksi->prepare('UPDATE siswa SET PSB=250 WHERE NO_INDUK=?');
            $stmt->bind_param('s',$nis);$stmt->execute();$stmt->close();
            foreach ([['2090-07-15 09:00:00',$fixture['oldClass'],$fixture['oldLevel'],'2090',100.0],
                ['2091-07-15 09:00:00',$fixture['newClass'],$fixture['newLevel'],'2091',150.0]]
                as [$date,$class,$level,$year,$amount]) {
                $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,kelas_rombel_snapshot,U_PSB,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran) VALUES(?,?,?,?,?,?,'07',?,'1','Tunai')");
                $stmt->bind_param('sssddss',$nis,$level,$class,$amount,$amount,$date,$year);
                $stmt->execute();$paymentIds[]=(int)$koneksi->insert_id;$stmt->close();
            }
            $source = $koneksi->query("SELECT COUNT(*) n,SUM(total_jumlah) total FROM bayar WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "' AND TGL_BYR>='2090-07-01' AND TGL_BYR<'2091-08-01'")->fetch_assoc();
            matrix_assert((int)$source['n'] === 2 && abs((float)$source['total']-250.0)<.01,
                'Dua header kas historis tidak cocok dengan fixture.');
            $filters = report_filters($koneksi,['template'=>'penerimaan','q'=>$nis,'kategori'=>'semua',
                'tanggal_awal'=>'2090-07-01','tanggal_akhir'=>'2091-07-31']);
            $receipt = report_build($koneksi,'penerimaan',$filters);
            $byClass = array_column($receipt['rows'],null,'kelas');
            matrix_assert(count($receipt['rows'])===2
                && abs((float)$byClass[$fixture['oldClass']]['total_penerimaan']-100.0)<.01
                && abs((float)$byClass[$fixture['newClass']]['total_penerimaan']-150.0)<.01,
                'Rekap penerimaan lintas kenaikan mencampur kelas/nominal.');
            matrix_export('penerimaan',['unit'=>'active','q'=>$nis,'kategori'=>'semua',
                'tanggal_awal'=>'2090-07-01','tanggal_akhir'=>'2091-07-31'],
                $sessions[$unit-1],[$nis,$fixture['oldClass'],$fixture['newClass'],
                    matrix_money(100),matrix_money(150)],true);
            $results[] = 'OK ' . unit_label($unit) . ': kas lintas kenaikan 100+150 tampil pada dua kelas historis di layar, Excel, PDF.';
        } finally {
            foreach ($paymentIds as $id) $koneksi->query('DELETE FROM bayar WHERE id=' . $id);
            $stmt = $koneksi->prepare('UPDATE siswa SET PSB=? WHERE NO_INDUK=?');
            $stmt->bind_param('ds',$original['PSB'],$nis);
            $stmt->execute();$stmt->close();
        }
    }
    foreach ($historicalFixtures as $unit=>$fixture) {
        $_SESSION['active_unit_id']=$unit;
        unit_set_context($koneksi,$unit);
        $oldId=(int)$fixture['oldId'];$nis=$fixture['nis'];
        $bill=$koneksi->query("SELECT id,status,cancel_reason,nominal_tagihan FROM tagihan_spp WHERE penempatan_id=$oldId AND bulan='07' AND tahun='2090' LIMIT 1")->fetch_assoc();
        matrix_assert((bool)$bill && $bill['status']==='open', 'Tagihan SPP pembatalan fixture tidak terbuka.');
        $billId=(int)$bill['id'];
        try {
            $koneksi->query("UPDATE tagihan_spp SET status='cancelled',cancel_reason='Uji rekap' WHERE id=$billId");
            $open = matrix_sum($koneksi,"SELECT SUM(nominal_tagihan) total FROM tagihan_spp WHERE penempatan_id=$oldId AND status<>'cancelled'");
            $annual = report_build($koneksi,'spp-tahunan',report_filters($koneksi,[
                'template'=>'spp-tahunan','q'=>$nis,'siswa_status'=>'archived','tahun_ajaran'=>'2090/2091']))['rows'];
            matrix_assert(count($annual)===1
                && abs((float)$annual[0]['total_tagihan']-$open)<.01
                && abs((float)$annual[0]['tunggakan']-$open)<.01,
                'SPP tahunan tetap menghitung tagihan yang dibatalkan.');
            $status = report_build($koneksi,'status',report_filters($koneksi,[
                'template'=>'status','q'=>$nis,'siswa_status'=>'archived','kategori'=>'spp',
                'tahun_ajaran'=>'2090/2091','bulan_awal'=>'07']))['rows'];
            matrix_assert(count($status)===1 && $status[0]['status']==='Dibatalkan'
                && (float)$status[0]['sisa']===0.0,
                'Status SPP dibatalkan masih menjadi piutang.');
            $item = report_build($koneksi,'per-item',report_filters($koneksi,[
                'template'=>'per-item','q'=>$nis,'siswa_status'=>'archived','kategori'=>'spp',
                'tahun_awal'=>2090,'bulan_awal'=>'07','tahun_akhir'=>2090,'bulan_akhir'=>'07']))['rows'];
            matrix_assert(count($item)===1 && (float)$item[0]['total_tagihan']===0.0
                && $item[0]['status']==='Dibatalkan',
                'Per Item SPP dibatalkan masih menjadi piutang.');
            $history = report_build($koneksi,'riwayat-tagihan',report_filters($koneksi,[
                'template'=>'riwayat-tagihan','q'=>$nis,'siswa_status'=>'archived',
                'komponen_tagihan'=>'spp','status'=>'dibatalkan']))['rows'];
            matrix_assert(count($history)===1
                && abs((float)$history[0]['tagihan']-(float)$bill['nominal_tagihan'])<.01
                && (float)$history[0]['sisa']===0.0,
                'Riwayat SPP dibatalkan tidak mempertahankan nominal awal/sisa nol.');
            $common=['unit'=>'active','q'=>$nis,'siswa_status'=>'archived'];
            matrix_export('status',$common+['kategori'=>'spp','tahun_ajaran'=>'2090/2091','bulan_awal'=>'07'],
                $sessions[$unit-1],[$nis,'Dibatalkan',matrix_money(0)],$unit===1);
            matrix_export('spp-tahunan',$common+['tahun_ajaran'=>'2090/2091'],
                $sessions[$unit-1],[$nis,'Dibatalkan',matrix_money($open)]);
            matrix_export('per-item',$common+['kategori'=>'spp','tahun_awal'=>2090,'bulan_awal'=>'07',
                'tahun_akhir'=>2090,'bulan_akhir'=>'07'],
                $sessions[$unit-1],[$nis,'Dibatalkan',matrix_money(0)],$unit===2);
            matrix_export('riwayat-tagihan',$common+['komponen_tagihan'=>'spp','status'=>'dibatalkan'],
                $sessions[$unit-1],[$nis,matrix_money((float)$bill['nominal_tagihan'])],$unit===3);
            $results[]='OK '.unit_label($unit).': SPP dibatalkan tidak masuk piutang; nilai asal tetap ada di riwayat, layar/Excel/PDF terpilih.';
        } finally {
            $stmt=$koneksi->prepare('UPDATE tagihan_spp SET status=?,cancel_reason=? WHERE id=?');
            $stmt->bind_param('ssi',$bill['status'],$bill['cancel_reason'],$billId);
            $stmt->execute();$stmt->close();
        }
    }
} finally {
    foreach ($sessions as $session) {
        session_id($session); session_start(); $_SESSION=[]; session_destroy(); session_write_close();
    }
}
foreach ($results as $result) echo $result . PHP_EOL;
