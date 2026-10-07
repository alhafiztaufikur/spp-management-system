<?php
require_once __DIR__.'/excel_test_helpers.php';
/** Reconcile daily receipts with payment headers when SPP has a discount. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))
    || !preg_match('#^http://127\.0\.0\.1:\d+/?$#D', (string)getenv('SPP_HTTP_BASE'))) {
    fwrite(STDERR, "FAILED: use a disposable audit database, mutation flag, and local HTTP server.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';

function receipt_discount_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function receipt_discount_http(string $url, string $sessionId): string {
    $context = stream_context_create(['http' => [
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
        'header' => 'Cookie: ' . session_name() . '=' . $sessionId . "\r\n",
    ]]);
    $body = file_get_contents($url, false, $context);
    receipt_discount_assert(str_contains($http_response_header[0] ?? '', '200'),
        'Laporan HTTP tidak menghasilkan status 200: ' . $url);
    return $body === false ? '' : $body;
}
function receipt_discount_pdf_text(string $pdf): string {
    $binary = getenv('SPP_PDFTOTEXT') ?: 'pdftotext';
    $pdfFile = tempnam(sys_get_temp_dir(), 'spp_report_pdf_');
    $textFile = tempnam(sys_get_temp_dir(), 'spp_report_text_');
    receipt_discount_assert($pdfFile !== false && $textFile !== false, 'Berkas sementara PDF tidak dapat dibuat.');
    try {
        receipt_discount_assert(file_put_contents($pdfFile, $pdf) === strlen($pdf), 'PDF biner tidak dapat ditulis untuk pemeriksaan.');
        $process = proc_open([$binary, '-layout', $pdfFile, $textFile],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null,
            ['bypass_shell' => true]);
        receipt_discount_assert(is_resource($process), 'pdftotext tidak tersedia untuk memeriksa PDF biner.');
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        receipt_discount_assert(proc_close($process) === 0, 'Ekstraksi PDF gagal: ' . trim($error));
        return (string)file_get_contents($textFile);
    } finally {
        if ($pdfFile !== false) unlink($pdfFile);
        if ($textFile !== false) unlink($textFile);
    }
}
function receipt_discount_audit(): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/readiness_integrity_audit.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), null,
        ['bypass_shell' => true]);
    receipt_discount_assert(is_resource($process), 'Audit integritas tidak dapat dijalankan.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    receipt_discount_assert($status === 0
        && preg_match('/^database=' . preg_quote(DB_NAME, '/') . '\r?$/m', $output) === 1
        && preg_match('/^OK\.total_header_tidak_cocok=0\r?$/m', $output) === 1
        && preg_match_all('/^OK\.[a-z_]+=0\r?$/m', $output) === 14,
        'Audit penuh gagal saat dua pembayaran diskon aktif: ' . trim($error ?: $output));
}
function receipt_discount_row(string $html, string $nis, bool $preview = false): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    if ($preview) {
        $frame = (new DOMXPath($document))->query('//iframe[@data-preview-frame]')->item(0);
        receipt_discount_assert($frame instanceof DOMElement, 'Pratinjau PDF tidak memiliki dokumen laporan.');
        $document = new DOMDocument();
        $document->loadHTML($frame->getAttribute('srcdoc'));
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $matches = $xpath->query("//tr[contains(., '$nis')]");
    receipt_discount_assert($matches->length === 1, 'Baris siswa penerimaan tidak tunggal.');
    $cells = [];
    foreach ($xpath->query('./td', $matches->item(0)) as $cell) {
        $cells[] = trim((string)preg_replace('/\s+/u', ' ', $cell->textContent));
    }
    return $cells;
}

$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$nis = (string)random_int(9800000000, 9899999999);
$sessionId = 'receiptdiscount' . bin2hex(random_bytes(8));
$paymentIds = [];
$studentCreated = false;
$failure = null;
try {
    $account = $koneksi->query("SELECT id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    receipt_discount_assert((bool)$account, 'Akun admin SD tidak tersedia.');
    session_id($sessionId);
    session_start();
    $_SESSION = ['admin_id' => (int)$account['id'], 'admin_role' => 'admin', 'admin_nama' => 'Uji Penerimaan'];
    session_write_close();

    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,'UJI REKAP POTONGAN','1',1)");
    $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
    $studentCreated = true;
    $operator = (string)$account['id'];
    foreach ([[100.0, 70.0, 20.0, 150.0, '2099-12-30 08:00:00'],
              [200.0, 0.0, 30.0, 170.0, '2099-12-30 09:00:00']] as [$spp, $komite, $discount, $cash, $date]) {
        $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,kelas_rombel_snapshot,U_SPP,U_KOMITE,potong_spp,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,payment_link_version) VALUES(?,'1','1A',?,?,?,?,?,'12','2099',?,'Tunai',1)");
        $stmt->bind_param('sddddss', $nis, $spp, $komite, $discount, $cash, $date, $operator);
        $stmt->execute(); $paymentIds[] = (int)$koneksi->insert_id; $stmt->close();
    }

    $filters = report_filters($koneksi, ['template' => 'penerimaan', 'kategori' => 'semua',
        'tanggal_awal' => '2099-12-30', 'tanggal_akhir' => '2099-12-30', 'q' => $nis]);
    $components = report_payment_components($koneksi, $filters);
    $headerTotal = 0.0;
    foreach ($paymentIds as $paymentId) {
        $componentTotal = 0.0;
        foreach ($components as $component) if ($component['id'] === $paymentId) $componentTotal += (float)$component['nominal'];
        $header = $koneksi->query('SELECT total_jumlah FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
        $headerTotal += (float)$header['total_jumlah'];
        receipt_discount_assert(abs($componentTotal - (float)$header['total_jumlah']) < .01,
            'Rincian komponen tidak sama dengan bayar.total_jumlah #' . $paymentId . '.');
    }
    $report = report_build($koneksi, 'penerimaan', $filters);
    receipt_discount_assert(count($report['rows']) === 1, 'Penerimaan harus menggabungkan dua pembayaran siswa.');
    $row = $report['rows'][0];
    receipt_discount_assert(($row['spp'] ?? null) === 300.0
        && ($row['komite'] ?? null) === 70.0
        && ($row['potongan'] ?? null) === -50.0
        && ($row['total_penerimaan'] ?? null) === 320.0
        && abs((float)$row['total_penerimaan'] - $headerTotal) < .01,
        'Penerimaan harus menampilkan SPP 300 + Komite 70 - Potongan 50 = kas 320.');
    $totals = array_column(report_money_totals($report, 'penerimaan'), 'value', 'key');
    receipt_discount_assert(($totals['potongan'] ?? null) === -50.0
        && ($totals['total_penerimaan'] ?? null) === 320.0,
        'Total kolom penerimaan tidak merekonsiliasi potongan dan kas.');
    receipt_discount_audit();

    $base = rtrim((string)getenv('SPP_HTTP_BASE'), '/') . '/';
    $query = http_build_query(['template' => 'penerimaan', 'kategori' => 'semua',
        'tanggal_awal' => '2099-12-30', 'tanggal_akhir' => '2099-12-30', 'q' => $nis]);
    foreach ([
        'layar' => $base . 'laporan/template.php?' . $query,
        'Excel' => $base . 'laporan/export_global.php?' . $query . '&format=excel&download=1',
        'pratinjau PDF' => $base . 'laporan/export_global.php?' . $query . '&format=preview',
    ] as $label => $url) {
        $html = receipt_discount_http($url, $sessionId);
        if($label==='Excel')$html=test_excel_html($html);
        receipt_discount_assert(str_contains($html, 'Potongan SPP'), $label . ' tidak memuat kolom Potongan SPP.');
        $cells = receipt_discount_row($html, $nis, $label === 'pratinjau PDF');
        foreach (['Rp 300', 'Rp 70', 'Rp -50', 'Rp 320'] as $amount) {
            receipt_discount_assert(in_array($amount, $cells, true), $label . ' tidak memuat ' . $amount . ' pada baris siswa.');
        }
    }
    $pdf = receipt_discount_http($base . 'laporan/export_global.php?' . $query . '&format=pdf&download=1', $sessionId);
    receipt_discount_assert(str_starts_with($pdf, '%PDF-'), 'Ekspor Penerimaan Harian tidak menghasilkan PDF biner.');
    $pdfText = receipt_discount_pdf_text($pdf);
    receipt_discount_assert(str_contains($pdfText, 'TOTAL POTONGAN SPP'),
        'PDF biner tidak memuat total kolom Potongan SPP.');
    receipt_discount_assert(preg_match('/^\s*1\s+' . preg_quote($nis, '/') . '\b([^\r\n]*)/m', $pdfText, $pdfRow) === 1,
        'PDF biner tidak memuat baris siswa.');
    receipt_discount_assert(preg_match('/Rp 300\b.*?Rp 70\b.*?Rp -50\b.*?Rp 320\b/', $pdfRow[1]) === 1,
        'Nominal baris PDF biner tidak sama dengan SPP 300 + Komite 70 - Potongan 50 = kas 320.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        foreach ($paymentIds as $paymentId) $koneksi->query('DELETE FROM bayar WHERE id=' . $paymentId);
        if ($studentCreated) {
            $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) { session_id($sessionId); session_start(); }
        $_SESSION = []; session_destroy(); session_write_close();
    } catch (Throwable $error) {
        if (!$failure) $failure = $error;
    }
}
if ($failure) { fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL); exit(1); }
echo "PASS: Penerimaan Harian cocok dengan bayar.total_jumlah pada data, layar, Excel, PDF biner, dan audit integritas penuh.\n";
