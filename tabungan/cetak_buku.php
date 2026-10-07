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
  <div class="head"><div><h1>Buku Tabungan - <?= book_escape($student['NAMA']) ?></h1><p class="muted">Unit <?= book_escape(unit_label((int)$student['unit_id'])) ?> · NIS <?= book_escape($nis) ?> · <?= count($book['entries']) ?> transaksi · <?= count($book['pages']) ?> halaman buku · <?= count($book['sides']) / 2 ?> lembar A5 potret</p></div>
    <div class="actions"><a class="btn secondary" href="cetak.php">Kembali ke Cetak Tabungan</a><a class="btn" href="<?= book_escape($pdfUrl) ?>" target="_blank" rel="noopener">Buka PDF untuk dicetak</a></div></div>
  <div class="instructions"><strong>Pengaturan cetak buku lipat</strong>Cetak PDF pada kertas A5 potret, skala 100% atau actual size, dua sisi dengan pembalikan pada sisi pendek. Lipat lembar secara horizontal untuk menghasilkan buku A6 lanskap yang dibuka ke atas. Periksa pratinjau sisi depan dan belakang sebelum mencetak seluruh buku.</div>
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

require_once __DIR__.'/../includes/savings_book_render.php';
$html=savings_book_html($book,$student,$school,$logo);

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->setDefaultMediaType('print');
$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'portrait');
$dompdf->render();
$filename = 'buku-tabungan-' . preg_replace('/[^A-Za-z0-9_-]/', '', $nis) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
