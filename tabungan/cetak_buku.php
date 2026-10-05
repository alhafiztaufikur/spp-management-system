<?php
session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/savings_book.php';
requireRole(['admin', 'kasir', 'bendahara']);

header('Cache-Control: private, no-store');

function book_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function book_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Buku tabungan belum bisa dicetak</title><style>body{margin:0;padding:40px 16px;background:#eff7f2;color:#193329;font:16px/1.6 Arial,sans-serif}'
        . 'main{max-width:640px;margin:5vh auto;padding:28px;background:#fff;border:1px solid #cee5d6;border-radius:16px}h1{font-size:24px;margin:0 0 12px}'
        . 'a{color:#08794a;font-weight:bold}</style></head><body><main><h1>Buku tabungan belum bisa dicetak</h1><p>'
        . book_escape($message) . '</p><p><a href="cetak.php">Kembali ke Cetak Tabungan</a></p></main></body></html>';
    exit;
}

$nis = $_GET['nis'] ?? '';
if (!is_string($nis) || ($nis = trim($nis)) === '' || strlen($nis) > 10) {
    book_error(400, 'NIS siswa tidak valid. Pilih siswa dari menu Cetak Tabungan.');
}
$output = $_GET['output'] ?? 'preview';
if (!is_string($output) || !in_array($output, ['preview', 'pdf'], true)) {
    book_error(400, 'Format keluaran tidak dikenal.');
}

try {
    $koneksi->begin_transaction();
    $studentStmt = $koneksi->prepare('SELECT s.NO_INDUK, s.unit_id, s.NO_induk_diknas, s.NAMA, s.KELAS, COALESCE(t.SALDO, 0) AS SALDO FROM siswa s LEFT JOIN tabungan t ON t.NO_INDUK=s.NO_INDUK AND t.unit_id=s.unit_id WHERE s.NO_INDUK=? AND (?=0 OR s.id=?)');
    $studentId=(int)($_GET['student_id']??0);
    $studentStmt->bind_param('sii', $nis,$studentId,$studentId);
    $studentStmt->execute();
    $matches=$studentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if(count($matches)>1) book_error(409,'NIS ambigu. Pilih siswa dan unit dari Cetak Tabungan.');
    $student=$matches[0]??null;
    $studentStmt->close();
    if (!$student) {
        $koneksi->commit();
        book_error(404, 'Siswa dengan NIS tersebut tidak ditemukan.');
    }

    unit_set_context($koneksi,(int)$student['unit_id']);
    $transactionStmt = $koneksi->prepare("SELECT x.id, x.tanggal, x.masuk, x.keluar, x.urutan_mutasi FROM (
        SELECT id, TANGGAL AS tanggal, MASUK AS masuk, 0 AS keluar, 0 AS urutan_mutasi FROM transaksi_m WHERE NO_INDUK=?
        UNION ALL
        SELECT id, TANGGAL AS tanggal, 0 AS masuk, KELUAR AS keluar, 1 AS urutan_mutasi FROM transaksi_k WHERE NO_INDUK=?
    ) x ORDER BY x.tanggal, x.urutan_mutasi, x.id");
    $transactionStmt->bind_param('ss', $nis, $nis);
    $transactionStmt->execute();
    $transactions = $transactionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $transactionStmt->close();
    $book = savings_book_make($transactions, (float)$student['SALDO']);
    $koneksi->commit();
} catch (SavingsBookBalanceMismatch $error) {
    $koneksi->rollback();
    book_error(409, $error->getMessage());
} catch (Throwable $error) {
    $koneksi->rollback();
    error_log('Gagal menyiapkan buku tabungan: ' . $error->getMessage());
    book_error(500, 'Terjadi kesalahan saat menyiapkan buku tabungan. Coba lagi atau hubungi administrator.');
}

$pdfUrl = 'cetak_buku.php?' . http_build_query(['nis' => $nis, 'student_id'=>$studentId, 'output' => 'pdf']);
if ($output === 'preview'):
    header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cetak Buku Tabungan - <?= book_escape($student['NAMA']) ?></title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2">
  <style>
    *{box-sizing:border-box}body{margin:0;background:#edf5f0;color:#173327;font:15px/1.5 Arial,sans-serif}
    .wrap{max-width:1100px;margin:26px auto;padding:0 18px}.head{display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap}
    h1{font-size:25px;margin:0 0 5px}p{margin:4px 0}.muted{color:#60756a}.actions{display:flex;gap:8px;flex-wrap:wrap}
    .btn{display:inline-block;padding:11px 16px;border-radius:9px;text-decoration:none;font-weight:700;background:#0e8c56;color:#fff}.btn.secondary{background:#fff;color:#1b4d36;border:1px solid #c8e0d1}
    .instructions{margin:20px 0;padding:15px 18px;background:#fff;border:1px solid #c8e0d1;border-radius:12px}.instructions strong{display:block;margin-bottom:5px}
    iframe{width:100%;height:76vh;min-height:540px;border:1px solid #c8e0d1;border-radius:12px;background:#fff}
  </style>
</head>
<body><main class="wrap">
  <div class="head"><div><h1>Buku Tabungan - <?= book_escape($student['NAMA']) ?></h1><p class="muted">Unit <?= book_escape(unit_label((int)$student['unit_id'])) ?> · NIS <?= book_escape($nis) ?> · <?= count($book['entries']) ?> transaksi · <?= count($book['pages']) ?> halaman buku · <?= count($book['sides']) / 2 ?> lembar A5</p></div>
    <div class="actions"><a class="btn secondary" href="cetak.php">Kembali ke Cetak Tabungan</a><a class="btn" href="<?= book_escape($pdfUrl) ?>" target="_blank" rel="noopener">Buka PDF untuk dicetak</a></div></div>
  <div class="instructions"><strong>Pengaturan cetak buku lipat</strong>Cetak PDF pada kertas A5 lanskap, skala 100% atau actual size, dua sisi dengan pembalikan pada sisi pendek. Lipat setiap lembar di tengah untuk menghasilkan buku A6. Periksa pratinjau sisi depan dan belakang sebelum mencetak seluruh buku.</div>
  <iframe src="<?= book_escape($pdfUrl) ?>" title="Pratinjau PDF buku tabungan <?= book_escape($student['NAMA']) ?>"></iframe>
</main></body></html>
<?php
    exit;
endif;

require_once __DIR__ . '/../includes/pdf.php';
require_pdf_library();
$logoFile = __DIR__ . '/../assets/img/school-logo.png';
$logo = is_file($logoFile) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile)) : '';
$school = unit_school_name((int)$student['unit_id']);

function book_page(array $page, array $student, string $school, string $logo, string $position): string
{
    $out = '<div class="leaf ' . $position . '">';
    if ($page['type'] === 'front') {
        $out .= '<div class="cover"><div class="cover-frame">';
        if ($logo !== '') $out .= '<img class="school-logo" src="' . $logo . '" alt="">';
        $out .= '<div class="school-name">' . book_escape($school) . '</div><h1>BUKU<br>TABUNGAN</h1>'
            . '<div class="identity"><div><span>No. Induk</span><strong>' . book_escape($student['NO_INDUK']) . '</strong></div>'
            . '<div><span>Nama</span><strong>' . book_escape($student['NAMA']) . '</strong></div>'
            . '<div><span>Kelas</span><strong>' . book_escape($student['KELAS']) . '</strong></div>';
        if (!empty($student['NO_induk_diknas'])) $out .= '<div><span>NIS Diknas</span><strong>' . book_escape($student['NO_induk_diknas']) . '</strong></div>';
        $out .= '</div></div></div>';
    } elseif ($page['type'] === 'back') {
        $out .= '<div class="back-cover"><div class="back-frame">';
        if ($logo !== '') $out .= '<img class="school-logo" src="' . $logo . '" alt="">';
        $out .= '<strong>' . book_escape($school) . '</strong><p>Buku Tabungan Siswa</p></div></div>';
    } else {
        $out .= '<div class="ledger-heading"><strong>BUKU TABUNGAN</strong><span>' . book_escape($student['NAMA']) . ' - NIS ' . book_escape($student['NO_INDUK']) . '</span></div>'
            . '<table class="ledger"><colgroup><col style="width:16mm"><col style="width:20mm"><col style="width:20mm"><col style="width:22mm"><col style="width:17mm"></colgroup>'
            . '<thead><tr><th rowspan="2">Tanggal</th><th colspan="2">Tabungan (Rp)</th><th rowspan="2">Saldo (Rp)</th><th rowspan="2">Tanda<br>Tangan</th></tr><tr><th>Masuk</th><th>Keluar</th></tr></thead><tbody>';
        foreach ($page['rows'] as $entry) {
            $date = $entry && $entry['tanggal'] !== '' ? spp_date_label($entry['tanggal']) : ($entry ? '-' : '');
            $out .= '<tr><td>' . book_escape($date) . '</td>'
                . '<td class="amount">' . ($entry && $entry['masuk'] ? savings_book_money($entry['masuk']) : '') . '</td>'
                . '<td class="amount">' . ($entry && $entry['keluar'] ? savings_book_money($entry['keluar']) : '') . '</td>'
                . '<td class="amount">' . ($entry ? savings_book_money($entry['saldo']) : '') . '</td><td></td></tr>';
        }
        $out .= '</tbody></table><div class="ledger-footer"><span>' . book_escape($school) . '</span><span>Hal. ' . $page['number'] . '</span></div>';
    }
    return $out . '</div>';
}

$html = '<!doctype html><html lang="id"><head><meta charset="utf-8"><style>
@page{size:A5 landscape;margin:0}body{margin:0;color:#16352b;font-family:DejaVu Sans,Arial,sans-serif}
.sheet{width:210mm;height:148mm;position:relative;page-break-after:always;overflow:hidden}.sheet.last{page-break-after:auto}
.leaf{position:absolute;top:0;width:95mm;height:138mm;padding:5mm;overflow:hidden}.leaf.left{left:0}.leaf.right{left:105mm}
.ledger-heading{height:11mm;border-bottom:0.7pt solid #2a6950;padding-bottom:1mm}
.ledger-heading strong{display:block;font-size:8.5pt;letter-spacing:.55pt}.ledger-heading span{display:block;margin-top:.7mm;font-size:5.8pt}
.ledger{width:100%;table-layout:fixed;border-collapse:collapse;margin-top:1.5mm;font-size:6pt;color:#172b24}
.ledger th,.ledger td{border:0.35pt solid #567465;padding:0 .4mm;height:5.7mm;vertical-align:middle;overflow:hidden}
.ledger th{background:#e4f1e8;text-align:center;font-size:5.6pt;height:4.5mm}.ledger td.amount{text-align:right;white-space:nowrap}
.ledger-footer{margin-top:2mm;border-top:0.4pt solid #a9c7b5;padding-top:1.3mm;font-size:4.6pt;color:#4f6e5d}
.ledger-footer span:last-child{float:right}.cover,.back-cover{position:relative;height:134mm;background:#d5f1e5;border:1.2pt solid #23805c}
.cover-frame,.back-frame{position:absolute;top:3mm;right:3mm;bottom:3mm;left:3mm;border:.6pt solid #23805c;text-align:center;padding:5mm}
.school-logo{width:17mm;height:17mm;object-fit:contain}.school-name{margin:3mm auto 0;max-width:78mm;font-size:6.7pt;font-weight:bold;line-height:1.35}
.cover h1{margin:13mm 0 10mm;font-size:18pt;line-height:1.2;letter-spacing:1pt;color:#134c38}
.identity{margin:0 auto;width:78mm;text-align:left;font-size:7pt}.identity div{min-height:9mm;border-bottom:.5pt solid #69a389;padding:1mm 0}
.identity span{display:inline-block;width:22mm;color:#496b5b}.identity strong{display:inline-block;width:53mm;vertical-align:top;word-wrap:break-word}
.back-frame{padding-top:43mm}.back-frame strong{display:block;margin:4mm auto;max-width:76mm;font-size:7.5pt;line-height:1.4}.back-frame p{font-size:7pt}
</style></head><body>';
foreach ($book['sides'] as $index => $side) {
    $left = $book['pages'][$side['left']];
    $right = $book['pages'][$side['right']];
    $html .= '<div class="sheet' . ($index === count($book['sides']) - 1 ? ' last' : '') . '">'
        . book_page($left, $student, $school, $logo, 'left') . book_page($right, $student, $school, $logo, 'right') . '</div>';
}
$html .= '</body></html>';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->setDefaultMediaType('print');
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'landscape');
$dompdf->render();
$filename = 'buku-tabungan-' . preg_replace('/[^A-Za-z0-9_-]/', '', $nis) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
