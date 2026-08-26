<?php
// ============================================
// laporan/export_excel.php — Export ke Excel
// ============================================
require_once __DIR__ . '/../includes/security.php';
security_bootstrap_session();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

function excel_text($value, bool $identifier = false): string {
    $text = str_replace(["\0", "\r", "\n", "\t"], ' ', (string)$value);
    if ($identifier || preg_match('/^[\p{Z}\s]*[=+\-@]/u', $text)) $text = "'" . $text;
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

$requestScalar = static function (string $key, $default = '') {
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? $value : $default;
};
$filter_bulan = (int)$requestScalar('bulan', date('m'));
$filter_tahun = (int)$requestScalar('tahun', date('Y'));
if ($filter_bulan < 1 || $filter_bulan > 12) $filter_bulan = (int)date('m');
if ($filter_tahun < 2000 || $filter_tahun > 2100) $filter_tahun = (int)date('Y');
$dateParam = static function (string $key) use ($requestScalar): string {
    $value = trim((string)$requestScalar($key));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
};
$filter_tanggal = $dateParam('tanggal');
$filter_tanggal_awal = $dateParam('tanggal_awal') ?: $filter_tanggal;
$filter_tanggal_akhir = $dateParam('tanggal_akhir') ?: $filter_tanggal;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir === '') $filter_tanggal_akhir = $filter_tanggal_awal;
if ($filter_tanggal_akhir !== '' && $filter_tanggal_awal === '') $filter_tanggal_awal = $filter_tanggal_akhir;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && strtotime($filter_tanggal_awal) > strtotime($filter_tanggal_akhir)) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && (new DateTimeImmutable($filter_tanggal_awal))->diff(new DateTimeImmutable($filter_tanggal_akhir))->days > 365) {
    http_response_code(413);
    exit('Rentang Excel dibatasi maksimal 366 hari kalender. Persempit filter laporan.');
}
$download = $requestScalar('download') === '1';

$bln_names = ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni',
               '7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[$filter_bulan] ?? 'Unknown';
$period_start = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : sprintf('%04d-%02d-01 00:00:00', $filter_tahun, $filter_bulan);
$period_end = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($period_start . ' +1 month'));
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '') {
    $startTs = strtotime($filter_tanggal_awal);
    $endTs = strtotime($filter_tanggal_akhir);
    if ($filter_tanggal_awal === $filter_tanggal_akhir) {
        $period_label = date('d M Y', $startTs);
    } elseif (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        $period_label = date('d', $startTs) . ' - ' . date('d M Y', $endTs);
    } else {
        $period_label = date('d M Y', $startTs) . ' - ' . date('d M Y', $endTs);
    }
} else {
    $period_label = $bulan_label . ' ' . $filter_tahun;
}

// Ambil data pembayaran
$stmt = $koneksi->prepare("
    SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
           b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
           b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_KOMITE,
           b.sistem_pembayaran, b.total_jumlah, b.TGL_BYR
    FROM bayar b JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?
    ORDER BY b.TGL_BYR DESC, b.id DESC
    LIMIT 10001
");
$stmt->bind_param('ss', $period_start, $period_end);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
if (count($rows) > 10000) { http_response_code(413); exit('Ekspor Excel dibatasi maksimal 10.000 transaksi. Persempit filter laporan.'); }

$stmtKomponen = $koneksi->prepare("
    SELECT SUM(U_PANGKAL) AS pangkal, SUM(U_BANGUNAN) AS bangunan,
           SUM(U_SERAGAM) AS seragam, SUM(U_KEGIATAN) AS kegiatan,
           SUM(U_SPP) AS spp, SUM(U_MAKAN) AS makan,
           SUM(U_SORGA) AS sorga, SUM(U_INFAQ) AS infaq,
           SUM(U_KOMITE) AS komite, SUM(potong_spp) AS potong_spp
    FROM bayar WHERE TGL_BYR >= ? AND TGL_BYR < ?
");
$stmtKomponen->bind_param('ss', $period_start, $period_end);
$stmtKomponen->execute();
$komponenTetap = $stmtKomponen->get_result()->fetch_assoc();
$stmtKomponen->close();

$komponen_rows = [];
$komponenMap = [
    'Uang Pangkal' => 'pangkal', 'Uang Bangunan' => 'bangunan',
    'Uang Seragam' => 'seragam', 'Uang Kegiatan' => 'kegiatan',
    'Uang SPP' => 'spp', 'Uang Komite' => 'komite', 'Uang Makan' => 'makan',
    'Uang Sorga' => 'sorga', 'Uang Infaq' => 'infaq', 'Potongan SPP' => 'potong_spp'
];
foreach ($komponenMap as $nama => $key) {
    $amount = (float)($komponenTetap[$key] ?? 0);
    if ($key === 'potong_spp') $amount *= -1;
    if (abs($amount) >= 0.005) {
        $komponen_rows[] = ['nama' => $nama, 'total' => $amount];
    }
}

$stmtDu = $koneksi->prepare("SELECT COALESCE(SUM(bd.jumlah),0) total FROM bayar_du bd JOIN bayar b ON b.id=bd.bayar_id WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?");
$stmtDu->bind_param('ss', $period_start, $period_end);
$stmtDu->execute();
$duTotal = (float)($stmtDu->get_result()->fetch_assoc()['total'] ?? 0);
$stmtDu->close();
if ($duTotal >= 0.005) $komponen_rows[] = ['nama' => 'Daftar Ulang', 'total' => $duTotal];

$stmtBiayaLain = $koneksi->prepare("
    SELECT d.nama_biaya_snapshot AS nama, SUM(d.nominal_snapshot) AS total
    FROM bayar_biaya_lain d JOIN bayar b ON b.id = d.bayar_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?
    GROUP BY d.nama_biaya_snapshot ORDER BY d.nama_biaya_snapshot ASC
");
$stmtBiayaLain->bind_param('ss', $period_start, $period_end);
$stmtBiayaLain->execute();
$komponen_rows = array_merge($komponen_rows, $stmtBiayaLain->get_result()->fetch_all(MYSQLI_ASSOC));
$stmtBiayaLain->close();

// Ambil data tabungan periode ini
$stmt2 = $koneksi->prepare("
    SELECT tm.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tm.TANGGAL, tm.MASUK as nominal, 'masuk' as jenis
    FROM transaksi_m tm JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK
    WHERE tm.bayar_id IS NULL AND tm.TANGGAL >= ? AND tm.TANGGAL < ?
    UNION ALL
    SELECT tk.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tk.TANGGAL, tk.KELUAR as nominal, 'keluar' as jenis
    FROM transaksi_k tk JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK
    WHERE tk.TANGGAL >= ? AND tk.TANGGAL < ?
    ORDER BY TANGGAL DESC, jenis ASC, NO_INDUK ASC
    LIMIT 10001
");
$stmt2->bind_param('ssss', $period_start, $period_end, $period_start, $period_end);
$stmt2->execute();
$tab_rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
if (count($tab_rows) > 10000) { http_response_code(413); exit('Ekspor Excel dibatasi maksimal 10.000 mutasi tabungan. Persempit filter laporan.'); }

$preview_total_pembayaran = 0.0;
foreach ($rows as $row) {
    $preview_total_pembayaran += (float)$row['total_jumlah'];
}
$preview_total_tab_masuk = 0.0;
$preview_total_tab_keluar = 0.0;
foreach ($tab_rows as $tab) {
    if ($tab['jenis'] === 'masuk') {
        $preview_total_tab_masuk += (float)$tab['nominal'];
    } else {
        $preview_total_tab_keluar += (float)$tab['nominal'];
    }
}

// Set header untuk download Excel
$filename = 'Laporan_SPP_' . str_replace(' ', '_', $period_label) . '.xls';
if ($download) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
  <meta charset="UTF-8">
  <title><?= $download ? htmlspecialchars($filename) : 'Preview Excel - ' . htmlspecialchars($period_label) ?></title>
  <!--[if gte mso 9]>
  <xml><x:ExcelWorkbook><x:ExcelWorksheets>
    <x:ExcelWorksheet><x:Name>Pembayaran SPP</x:Name><x:WorksheetOptions><x:Print><x:FitToPage/></x:Print></x:WorksheetOptions></x:ExcelWorksheet>
  </x:ExcelWorksheets></x:ExcelWorkbook></xml>
  <![endif]-->
  <style>
    html { overflow-x: hidden; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f3f7f1; color: #17231c; overflow-x: hidden; }
    .no-print {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 10px 14px;
      background: #fff;
      border-bottom: 1px solid #cddccc;
    }
    .no-print a {
      border: 1px solid #1f6f3f;
      border-radius: 4px;
      padding: 8px 14px;
      color: #1f6f3f;
      background: #fff;
      font-size: 12px;
      font-weight: 700;
      text-decoration: none;
    }
    .no-print a.primary { background: #1f6f3f; color: #fff; }
    .preview-sheet {
      width: min(1040px, calc(100% - 32px));
      margin: 18px auto 28px;
      padding: 18px 22px 22px;
      background: #fff;
      border: 1px solid #cddccc;
      box-shadow: 0 14px 34px rgba(31, 111, 63, .10);
      overflow-x: auto;
    }
    .report-head {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
      margin-bottom: 14px;
      padding-bottom: 12px;
      border-bottom: 3px solid #f28c28;
    }
    .report-title {
      margin: 0 0 6px;
      color: #1f6f3f;
      font-size: 22px;
      line-height: 1.2;
    }
    .report-meta {
      margin: 0;
      color: #506053;
      font-size: 13px;
    }
    .period-pill {
      display: inline-block;
      padding: 7px 12px;
      border-radius: 4px;
      background: #fff4e6;
      color: #b45309;
      border: 1px solid #ffd6a3;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 14px;
    }
    .summary-card {
      padding: 10px 12px;
      border: 1px solid #cfe4d2;
      border-left: 5px solid #1f6f3f;
      background: #f8fcf8;
    }
    .summary-card.orange { border-left-color: #f28c28; background: #fff9f1; }
    .summary-label {
      display: block;
      margin-bottom: 4px;
      color: #5a695d;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .summary-value {
      display: block;
      color: #17231c;
      font-size: 16px;
      font-weight: 800;
    }
    table {
      width: 100%;
      min-width: 760px;
      margin: 0 0 14px;
      border-collapse: collapse;
    }
    th { background: #1f6f3f; color: white; font-weight: bold; padding: 8px 10px; border: 1px solid #8eb99a; }
    td { padding: 7px 10px; border: 1px solid #cfd8cf; }
    .excel-text { mso-number-format: "\\@"; }
    .total-row { background: #fff4e6; color: #17231c; font-weight: bold; }
    .header-row { background: #f28c28; color: white; font-size: 12pt; font-weight: bold; }
    .section-header { background: #e6f3e8; font-weight: bold; font-size: 11pt; }
    .empty-row { color: #68746a; text-align: center; font-style: italic; background: #fbfdfb; }
    .table-card {
      margin: 0 0 14px;
    }
    .table-scroll {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .table-scroll table {
      margin-bottom: 0;
    }
    @media print {
      body { background: #fff; }
      .no-print { display: none !important; }
      .preview-sheet { width: auto; margin: 0; padding: 0; border: 0; box-shadow: none; }
    }
    @media (max-width: 760px) {
      .no-print {
        position: static;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        padding: 8px;
        background: #f7fbf7;
      }
      .no-print a {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 42px;
        padding: 8px 10px;
        border-radius: 7px;
        text-align: center;
      }
      .preview-sheet {
        width: 100%;
        margin: 0;
        padding: 14px 10px 18px;
        border-left: 0;
        border-right: 0;
        box-shadow: none;
        overflow-x: hidden;
      }
      .summary-grid {
        grid-template-columns: 1fr;
        gap: 8px;
      }
      .summary-card {
        padding: 11px 12px;
      }
      .report-head {
        display: block;
        margin-bottom: 12px;
        padding-bottom: 12px;
      }
      .report-title {
        font-size: 20px;
      }
      .period-pill { margin-top: 10px; }
      .table-card {
        border: 1px solid #cfe4d2;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
      }
      .table-scroll {
        overflow-x: auto;
      }
      table {
        min-width: 720px;
        table-layout: auto;
        font-size: 11px;
      }
      .table-card.compact table {
        min-width: 100%;
      }
      th,
      td {
        padding: 8px 8px;
        white-space: normal;
      }
      .header-row {
        font-size: 12px;
      }
      .empty-row {
        padding: 14px 10px;
      }
    }
  </style>
</head>
<body>

<?php if (!$download): ?>
<div class="no-print">
  <a class="primary" href="export_excel.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&tanggal_awal=<?= urlencode($filter_tanggal_awal) ?>&tanggal_akhir=<?= urlencode($filter_tanggal_akhir) ?>&download=1">Download Excel</a>
  <a href="index.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&tanggal_awal=<?= urlencode($filter_tanggal_awal) ?>&tanggal_akhir=<?= urlencode($filter_tanggal_akhir) ?>">Kembali</a>
</div>
<main class="preview-sheet">
<?php endif; ?>

<div class="report-head">
  <div>
    <h2 class="report-title">Laporan Keuangan Sistem SPP</h2>
    <p class="report-meta">Dicetak: <?= date('d M Y H:i') ?></p>
  </div>
  <span class="period-pill">Periode <?= htmlspecialchars($period_label) ?></span>
</div>

<?php if (!$download): ?>
<div class="summary-grid">
  <div class="summary-card">
    <span class="summary-label">Total Pembayaran</span>
    <span class="summary-value">Rp <?= number_format($preview_total_pembayaran, 0, ',', '.') ?></span>
  </div>
  <div class="summary-card orange">
    <span class="summary-label">Tabungan Masuk</span>
    <span class="summary-value">Rp <?= number_format($preview_total_tab_masuk, 0, ',', '.') ?></span>
  </div>
  <div class="summary-card orange">
    <span class="summary-label">Tabungan Keluar</span>
    <span class="summary-value">Rp <?= number_format($preview_total_tab_keluar, 0, ',', '.') ?></span>
  </div>
</div>
<?php endif; ?>

<div class="table-card compact">
<div class="table-scroll">
<table>
  <tr class="header-row"><td colspan="2">RINCIAN KOMPONEN PEMBAYARAN</td></tr>
  <tr><th>Komponen</th><th>Total (Rp)</th></tr>
  <?php if (empty($komponen_rows)): ?>
  <tr><td colspan="2" class="empty-row">Belum ada komponen pembayaran pada periode ini.</td></tr>
  <?php endif; ?>
  <?php foreach ($komponen_rows as $komponen): ?>
  <tr><td><?= excel_text($komponen['nama']) ?></td><td><?= number_format((float)$komponen['total'],0,',','.') ?></td></tr>
  <?php endforeach; ?>
</table>
</div>
</div>

<!-- Sheet 1: Pembayaran SPP -->
<div class="table-card">
<div class="table-scroll">
<table>
  <tr class="header-row"><td colspan="7">REKAP PEMBAYARAN SPP — <?= strtoupper($period_label) ?></td></tr>
  <tr>
    <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
    <th>Bulan Bayar / Sistem</th><th>Total Bayar (Rp)</th><th>Tanggal Bayar</th>
  </tr>
  <?php if (empty($rows)): ?>
  <tr><td colspan="7" class="empty-row">Belum ada transaksi pembayaran pada periode ini.</td></tr>
  <?php endif; ?>
  <?php
  $grand_total = 0;
  foreach ($rows as $i => $r):
    $grand_total += (float)$r['total_jumlah'];
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td class="excel-text"><?= excel_text($r['NO_INDUK'], true) ?><?= !empty($r['NO_induk_diknas']) ? '<br>Diknas: ' . excel_text($r['NO_induk_diknas'], true) : '' ?></td>
    <td><?= excel_text($r['NAMA']) ?></td>
    <td><?= excel_text($r['KELAS']) ?></td>
    <td><?= excel_text($r['BULAN']) ?> <?= excel_text($r['TAHUN']) ?><br>Sistem: <?= excel_text($r['sistem_pembayaran'] ?? 'VA') ?></td>
    <td><?= number_format((float)$r['total_jumlah'],0,',','.') ?></td>
    <td><?= date('d M Y', strtotime($r['TGL_BYR'])) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="5">TOTAL</td>
    <td><?= number_format($grand_total,0,',','.') ?></td>
    <td></td>
  </tr>
</table>
</div>
</div>

<!-- Sheet 2: Tabungan -->
<div class="table-card">
<div class="table-scroll">
<table>
  <tr class="header-row"><td colspan="7">REKAP TABUNGAN — <?= strtoupper($period_label) ?></td></tr>
  <tr>
    <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
    <th>Tanggal</th><th>Jenis</th><th>Nominal (Rp)</th>
  </tr>
  <?php if (empty($tab_rows)): ?>
  <tr><td colspan="7" class="empty-row">Belum ada transaksi tabungan pada periode ini.</td></tr>
  <?php endif; ?>
  <?php
  $total_masuk_tab = 0;
  $total_keluar_tab = 0;
  foreach ($tab_rows as $i => $t):
    if ($t['jenis'] === 'masuk') $total_masuk_tab += (float)$t['nominal'];
    else $total_keluar_tab += (float)$t['nominal'];
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td class="excel-text"><?= excel_text($t['NO_INDUK'], true) ?><?= !empty($t['NO_induk_diknas']) ? '<br>Diknas: ' . excel_text($t['NO_induk_diknas'], true) : '' ?></td>
    <td><?= excel_text($t['NAMA']) ?></td>
    <td><?= excel_text($t['KELAS']) ?></td>
    <td><?= date('d M Y H:i', strtotime($t['TANGGAL'])) ?></td>
    <td><?= $t['jenis'] === 'masuk' ? '↑ Masuk' : '↓ Keluar' ?></td>
    <td><?= number_format((float)$t['nominal'],0,',','.') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row"><td colspan="6">Total Masuk</td><td><?= number_format($total_masuk_tab,0,',','.') ?></td></tr>
  <tr class="total-row"><td colspan="6">Total Keluar</td><td><?= number_format($total_keluar_tab,0,',','.') ?></td></tr>
</table>
</div>
</div>

<?php if (!$download): ?>
</main>
<?php endif; ?>

</body>
</html>
