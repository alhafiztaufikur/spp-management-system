<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/tagihan_tahunan.php';
requireRole(['admin', 'bendahara', 'kasir']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$paymentId = max(0, (int)($_GET['id'] ?? 0));
if ($paymentId <= 0) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi untuk struk tidak valid.'];
    header('Location: index.php');
    exit;
}

function receipt_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function receipt_money($value, bool $showZero = false): string {
    $amount = (float)$value;
    if (!$showZero && abs($amount) < 0.005) return '';
    return number_format($amount, 0, ',', '.');
}

function receipt_month($value): string {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    $legacy = array_flip($months);
    if (isset($legacy[$value])) return $value;
    $code = str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
    return $months[$code] ?? (string)$value;
}

function receipt_date($value): string {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) return '-';
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

function receipt_words(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return receipt_words($number - 10) . ' belas';
    if ($number < 100) return receipt_words(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . receipt_words($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . receipt_words($number - 100) : '');
    if ($number < 1000) return receipt_words(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . receipt_words($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . receipt_words($number - 1000) : '');
    if ($number < 1000000) return receipt_words(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . receipt_words($number % 1000) : '');
    if ($number < 1000000000) return receipt_words(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . receipt_words($number % 1000000) : '');
    return receipt_words(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . receipt_words($number % 1000000000) : '');
}

function receipt_month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
}

function receipt_period_paid(mysqli $db, string $noInduk, string $bulan, string $tahun): array {
    $monthCode = receipt_month_code($bulan);
    $monthLabel = receipt_month($monthCode);
    $legacyMonth = (string)(int)$monthCode;
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(U_SPP), 0) AS spp, COALESCE(SUM(U_KOMITE), 0) AS komite
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?)
    ");
    $stmt->bind_param('sssss', $noInduk, $tahun, $monthCode, $monthLabel, $legacyMonth);
    $stmt->execute();
    $paid = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return ['spp' => (float)($paid['spp'] ?? 0), 'komite' => (float)($paid['komite'] ?? 0)];
}

function receipt_one_time_paid(mysqli $db, string $noInduk): array {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(U_MAKAN), 0) AS makan,
            COALESCE(SUM(U_SORGA), 0) AS sorga,
            COALESCE(SUM(U_INFAQ), 0) AS infaq
        FROM bayar
        WHERE NO_INDUK = ?
    ");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $paid = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'makan' => (float)($paid['makan'] ?? 0),
        'sorga' => (float)($paid['sorga'] ?? 0),
        'infaq' => (float)($paid['infaq'] ?? 0),
    ];
}

function receipt_du_paid(mysqli $db, int $billId): float {
    if ($billId <= 0) return 0.0;
    $stmt = $db->prepare('SELECT COALESCE(SUM(jumlah), 0) AS paid FROM bayar_du WHERE tagihan_daftar_ulang_id = ?');
    $stmt->bind_param('i', $billId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function receipt_biaya_lain_paid(mysqli $db, string $noInduk, int $masterId): float {
    if ($masterId <= 0) return 0.0;
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(d.nominal_snapshot), 0) AS paid
        FROM bayar_biaya_lain d
        JOIN bayar b ON b.id = d.bayar_id
        WHERE b.NO_INDUK = ? AND d.master_biaya_lain_id = ?
    ");
    $stmt->bind_param('si', $noInduk, $masterId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function receipt_add_remaining_line(array &$lines, string $label, float $currentAmount, float $total, float $paid): void {
    if (abs($currentAmount) < 0.005 || $total <= 0) return;
    $lines[] = [$label, max(0, $total - $paid)];
}

function receipt_remaining_lines(mysqli $db, array $payment, array $otherDetails): array {
    $lines = [];
    $annualRemaining = annual_fee_remaining_for_payment($db, (int)$payment['id']);
    $annualLabels = [
        'pangkal' => ['Sisa PSB', 'U_PANGKAL'],
        'bangunan' => ['Sisa Bangunan', 'U_BANGUNAN'],
        'seragam' => ['Sisa Seragam', 'U_SERAGAM'],
        'kegiatan' => ['Sisa Kegiatan', 'U_KEGIATAN'],
        'komite' => ['Sisa Komite', 'U_KOMITE'],
        'makan' => ['Sisa Makan', 'U_MAKAN'],
        'sorga' => ['Sisa Sorga', 'U_SORGA'],
        'infaq' => ['Sisa Infaq', 'U_INFAQ'],
    ];
    foreach ($annualLabels as $component => [$label, $field]) {
        if (abs((float)($payment[$field] ?? 0)) >= 0.005 && isset($annualRemaining[$component])) {
            $lines[] = [$label, $annualRemaining[$component]];
        }
    }

    $duBillId = (int)($payment['tagihan_daftar_ulang_id'] ?? 0);
    $duTotal = (float)($payment['du_nominal_tagihan'] ?? 0);
    if ($duTotal <= 0) {
        $duTotal = (float)$payment['tot_du'] > 0
            ? (float)$payment['tot_du']
            : max(0, (float)$payment['DAFTAR_ULANG'] - (float)$payment['potong_du']);
    }
    $duPaid = $duBillId > 0 ? receipt_du_paid($db, $duBillId) : (float)($payment['total_du_bayar'] ?? 0);
    receipt_add_remaining_line($lines, 'Sisa DU', (float)$payment['uang_du'], $duTotal, $duPaid);

    return $lines;
}

$stmt = $koneksi->prepare("
    SELECT
        b.*,
        s.NAMA,
        s.KELAS AS KELAS_SISWA,
        s.PANGKAL,
        s.NO_induk_diknas,
        s.PANGKAL_BAYAR,
        s.BANGUNAN,
        s.BANGUNAN_BAYAR,
        s.SERAGAM,
        s.SERAGAM_BAYAR,
        s.KEGIATAN,
        s.KEGIATAN_BAYAR,
        s.MAKAN,
        s.SORGA,
        s.INFAQ,
        s.SPP_PERBULAN,
        s.POMG,
        s.potong_pangkal,
        s.tot_pangkal,
        s.DAFTAR_ULANG,
        s.potong_du,
        s.tot_du,
        du.tagihan_daftar_ulang_id,
        COALESCE(du.jumlah, 0) AS uang_du,
        COALESCE(tdu.nominal_tagihan, 0) AS du_nominal_tagihan,
        COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name,
        COALESCE((SELECT SUM(bp.U_PANGKAL) FROM bayar bp WHERE bp.NO_INDUK = b.NO_INDUK), 0) AS total_pangkal_bayar,
        COALESCE((SELECT SUM(bd.jumlah) FROM bayar_du bd WHERE bd.no_induk = b.NO_INDUK), 0) AS total_du_bayar
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    LEFT JOIN bayar_du du ON du.bayar_id = b.id
    LEFT JOIN tagihan_daftar_ulang tdu ON tdu.id = du.tagihan_daftar_ulang_id
    WHERE b.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi tidak ditemukan atau siswa sudah tidak tersedia.'];
    header('Location: index.php');
    exit;
}

$otherDetails = [];
$stmt = $koneksi->prepare('
    SELECT d.master_biaya_lain_id, d.nama_biaya_snapshot, d.nominal_snapshot, d.keterangan, m.nominal AS master_nominal
    FROM bayar_biaya_lain d
    LEFT JOIN master_biaya_lain m ON m.id = d.master_biaya_lain_id
    WHERE d.bayar_id = ?
    ORDER BY d.urutan, d.id
');
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$detailResult = $stmt->get_result();
while ($detail = $detailResult->fetch_assoc()) $otherDetails[] = $detail;
$stmt->close();

$primaryLines = [
    ['Uang PSB', $payment['U_PANGKAL']],
    ['Uang Daftar Ulang', $payment['uang_du']],
    ['Uang SPP', $payment['U_SPP']],
    ['Komite Sekolah', $payment['U_KOMITE']],
];
$otherLines = [
    ['Uang Bangunan', $payment['U_BANGUNAN']],
    ['Uang Seragam', $payment['U_SERAGAM']],
    ['Uang Kegiatan', $payment['U_KEGIATAN']],
    ['Uang Makan', $payment['U_MAKAN']],
    ['Uang Sorga', $payment['U_SORGA']],
    ['Uang Infaq', $payment['U_INFAQ']],
];
foreach ($otherDetails as $detail) {
    $label = $detail['nama_biaya_snapshot'];
    if (trim((string)$detail['keterangan']) !== '') $label .= ' - ' . $detail['keterangan'];
    $otherLines[] = [$label, $detail['nominal_snapshot']];
}
if ((float)$payment['potong_spp'] > 0) $otherLines[] = ['Potongan SPP', -(float)$payment['potong_spp']];
$otherLines = array_values(array_filter($otherLines, fn($line) => abs((float)$line[1]) >= 0.005));

$remainingLines = receipt_remaining_lines($koneksi, $payment, $otherDetails);
$total = (float)$payment['total_jumlah'];
$signer = $payment['operator_name'] ?: ($_SESSION['admin_nama'] ?? 'Bagian Keuangan');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Struk #<?= $paymentId ?> - <?= receipt_e($payment['NAMA']) ?></title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <style>
    @page { size: A5 landscape; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e7ece9; color: #000; font: 11.5px/1.25 "Times New Roman", serif; }
    .print-toolbar { position: sticky; top: 0; z-index: 3; min-height: 54px; padding: 9px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #143f31; color: #fff; font: 600 13px Arial, sans-serif; }
    .print-toolbar-actions { display: flex; gap: 8px; }
    .print-toolbar button { border: 0; border-radius: 8px; padding: 9px 14px; cursor: pointer; font-weight: 700; }
    .print-primary { background: #22a866; color: #fff; }
    .print-secondary { background: #fff; color: #143f31; }
    .receipt-sheet { width: 210mm; min-height: 148mm; margin: 16px auto; padding: 8mm 12mm 7mm; background: #fff; box-shadow: 0 8px 30px rgba(20,63,49,.16); overflow: hidden; }
    h1 { margin: 0; text-align: center; font: 800 15px/1.15 Arial, sans-serif; }
    .address { margin: 6px 0 5px; text-align: center; font-size: 10.5px; }
    .rule { border-top: 1px solid #000; }
    .document-title { margin: 5px 0 9px; text-align: center; font-weight: 800; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .info { margin-bottom: 5px; }
    .info > tbody > tr > td, .detail > tbody > tr > td { width: 50%; padding: 0 8mm; }
    .mini td, .payments td { padding: 1.6px 0; }
    .label { width: 35mm; }
    .separator { width: 5mm; text-align: center; }
    .split { margin: 4px 0 8px; }
    .section-label { margin-bottom: 4px; text-decoration: underline; }
    .number { width: 7mm; }
    .payment-label { width: 47mm; }
    .amount { width: 28mm; text-align: right; white-space: nowrap; }
    .total { margin-top: 8px; border-top: 1px solid #000; border-bottom: 1px solid #000; font: 800 11px Arial, sans-serif; letter-spacing: .5px; }
    .total td { padding: 5px 0; }
    .footer { margin-top: 8px; }
    .footer-left { width: 68%; }
    .footer-right { width: 32%; text-align: center; }
    .words { min-height: 34px; font-size: 12px; font-style: italic; }
    .signature-space { height: 20px; }
    @media (max-width: 900px) { .receipt-sheet { width: 100%; min-height: auto; margin: 0; box-shadow: none; } }
    @media print {
      body { background: #fff; }
      .print-toolbar { display: none !important; }
      .receipt-sheet { width: 210mm; height: 148mm; min-height: 148mm; margin: 0; box-shadow: none; page-break-after: avoid; }
    }
  </style>
</head>
<body>
  <div class="print-toolbar">
    <span>Struk siap dicetak</span>
    <div class="print-toolbar-actions">
      <button type="button" class="print-secondary" onclick="window.close()">Tutup</button>
      <button type="button" class="print-primary" onclick="window.print()">Cetak Lagi</button>
    </div>
  </div>

  <main class="receipt-sheet">
    <h1>SEKOLAH DASAR AL-QUR'AN<br>( SDA ) MUTIARA HIKMAH</h1>
    <p class="address">Perum Bekasi Griya Asri II, Blok E Jl.H.Nabrih Ds. Sumber Jaya Kp.Buwek Tambun Selatan Telp. 021.88363466</p>
    <div class="rule"></div>
    <div class="document-title">SLIP PEMBAYARAN SEKOLAH</div>

    <table class="info"><tr>
      <td><table class="mini">
        <tr><td class="label">No. Induk</td><td class="separator">:</td><td><?= receipt_e($payment['NO_INDUK']) ?></td></tr>
        <?php if (!empty($payment['NO_induk_diknas'])): ?><tr><td class="label">NIS Diknas</td><td class="separator">:</td><td><?= receipt_e($payment['NO_induk_diknas']) ?></td></tr><?php endif; ?>
        <tr><td class="label">Nama Siswa</td><td class="separator">:</td><td><?= receipt_e($payment['NAMA']) ?></td></tr>
      </table></td>
      <td><table class="mini">
        <tr><td class="label">Kelas</td><td class="separator">:</td><td><?= receipt_e($payment['KELAS_SISWA']) ?></td></tr>
        <tr><td class="label">Periode</td><td class="separator">:</td><td><?= receipt_e(receipt_month($payment['BULAN'])) ?> <?= receipt_e($payment['TAHUN']) ?></td></tr>
      </table></td>
    </tr></table>

    <div class="rule split"></div>
    <table class="detail"><tr>
      <td>
        <div class="section-label">Data Pembayaran:</div>
        <table class="payments">
          <?php foreach ($primaryLines as $index => [$label, $amount]): ?>
          <tr><td class="number"><?= $index + 1 ?>.</td><td class="payment-label"><?= receipt_e($label) ?></td><td class="separator">:</td><td class="amount"><?= receipt_e(receipt_money($amount)) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </td>
      <td>
        <div class="section-label">Sisa Pembayaran:</div>
        <table class="payments">
          <?php if ($remainingLines): foreach ($remainingLines as [$label, $amount]): ?>
          <tr><td><strong><?= receipt_e($label) ?></strong></td><td class="separator">:</td><td class="amount"><?= receipt_e(receipt_money($amount, true)) ?></td></tr>
          <?php endforeach; else: ?>
          <tr><td>-</td><td></td><td></td></tr>
          <?php endif; ?>
        </table>
        <div class="section-label" style="margin-top:8px">Pembayaran Lain-lain:</div>
        <table class="payments">
          <?php if ($otherLines): foreach ($otherLines as [$label, $amount]): ?>
          <tr><td><?= receipt_e($label) ?></td><td class="separator">:</td><td class="amount"><?= $amount < 0 ? '-' : '' ?><?= receipt_e(receipt_money(abs((float)$amount))) ?></td></tr>
          <?php endforeach; else: ?>
          <tr><td>-</td><td></td><td></td></tr>
          <?php endif; ?>
        </table>
      </td>
    </tr></table>

    <table class="total"><tr><td>JUMLAH TOTAL</td><td class="amount"><?= receipt_e(receipt_money($total, true)) ?></td></tr></table>
    <table class="footer"><tr>
      <td class="footer-left">
        <div class="words"><strong>Terbilang:</strong> <?= receipt_e(ucfirst(receipt_words((int)round($total))) . ' rupiah') ?></div>
        <div><strong>Sistem Pembayaran:</strong> <?= receipt_e($payment['sistem_pembayaran'] ?? 'VA') ?></div>
      </td>
      <td class="footer-right">
        <div>Bekasi, <?= receipt_e(receipt_date($payment['TGL_BYR'])) ?></div>
        <div>Bagian Keuangan</div>
        <div class="signature-space"></div>
        <strong><?= receipt_e($signer) ?></strong>
      </td>
    </tr></table>
  </main>

  <script>
    window.addEventListener('load', function () {
      window.setTimeout(function () { window.print(); }, 40);
    });
    window.addEventListener('afterprint', function () {
      if (window.opener) window.close();
    });
  </script>
</body>
</html>
