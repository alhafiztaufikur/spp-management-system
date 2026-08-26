<?php
// ============================================
// laporan/export_pdf.php - Server-rendered payment slips PDF
// ============================================
require_once '../includes/security.php';
security_bootstrap_session();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';
requireRole(['admin', 'bendahara']);

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
    exit('Rentang PDF dibatasi maksimal 366 hari kalender. Persempit filter laporan.');
}
$preview_mode = $requestScalar('contoh') === '1';
$selected_mode = $requestScalar('mode') === 'selected';
$selected_ids_raw = $_GET['ids'] ?? [];
if (!is_array($selected_ids_raw)) {
    $selected_ids_raw = preg_split('/[,\s]+/', (string)$selected_ids_raw, -1, PREG_SPLIT_NO_EMPTY);
}
$selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids_raw), fn($id) => $id > 0)));
if (count($selected_ids) > 200) {
    http_response_code(413);
    exit('Pilihan PDF dibatasi maksimal 200 transaksi.');
}

if ($selected_mode && !$selected_ids) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih minimal satu transaksi untuk dicetak.'];
    header('Location: index.php?bulan=' . urlencode((string)$filter_bulan) . '&tahun=' . urlencode((string)$filter_tahun) . '&tanggal_awal=' . urlencode($filter_tanggal_awal) . '&tanggal_akhir=' . urlencode($filter_tanggal_akhir));
    exit;
}

$bln_names = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$bulan_label = $bln_names[$filter_bulan] ?? '';
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '') {
    $startTs = strtotime($filter_tanggal_awal);
    $endTs = strtotime($filter_tanggal_akhir);
    if ($filter_tanggal_awal === $filter_tanggal_akhir) {
        $periode = date('d M Y', $startTs);
    } elseif (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        $periode = date('d', $startTs) . ' - ' . date('d M Y', $endTs);
    } else {
        $periode = date('d M Y', $startTs) . ' - ' . date('d M Y', $endTs);
    }
} else {
    $periode = trim($bulan_label . ' ' . $filter_tahun);
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_plain($n): string {
    $amount = (float)$n;
    return $amount > 0 ? number_format($amount, 0, ',', '.') : '';
}

function money_total($n): string {
    return number_format((float)$n, 0, ',', '.');
}

function month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

function month_name_from_value($value, array $names): string {
    $code = (int)month_code($value);
    return $names[$code] ?? (string)$value;
}

function terbilang_int(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return terbilang_int($number - 10) . ' belas';
    if ($number < 100) return terbilang_int(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . terbilang_int($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . terbilang_int($number - 100) : '');
    if ($number < 1000) return terbilang_int(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . terbilang_int($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . terbilang_int($number - 1000) : '');
    if ($number < 1000000) return terbilang_int(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . terbilang_int($number % 1000) : '');
    if ($number < 1000000000) return terbilang_int(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . terbilang_int($number % 1000000) : '');
    return terbilang_int(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . terbilang_int($number % 1000000000) : '');
}

function terbilang_rupiah($amount): string {
    $number = (int)round((float)$amount);
    if ($number <= 0) return 'nol rupiah';
    return ucfirst(terbilang_int($number)) . ' rupiah';
}

function payment_line(string $label, $amount): array {
    return ['label' => $label, 'amount' => (float)$amount];
}

$period_start = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : sprintf('%04d-%02d-01 00:00:00', $filter_tahun, $filter_bulan);
$period_end = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($period_start . ' +1 month'));
$where_sql = 'WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?';
$types = 'ss';
$params = [$period_start, $period_end];
if ($selected_mode && $selected_ids) {
    $where_sql .= ' AND b.id IN (' . implode(',', array_fill(0, count($selected_ids), '?')) . ')';
    $types .= str_repeat('i', count($selected_ids));
    array_push($params, ...$selected_ids);
}

$stmt = $koneksi->prepare("
    SELECT
        b.*,
        s.NAMA,
        s.NO_induk_diknas,
        COALESCE(NULLIF(b.kelas_rombel_snapshot, ''), NULLIF(b.KELAS, ''), s.KELAS) AS KELAS_SISWA,
        s.PANGKAL,
        s.PANGKAL_BAYAR,
        s.potong_pangkal,
        s.tot_pangkal,
        s.DAFTAR_ULANG,
        s.potong_du,
        s.tot_du,
        COALESCE(du_current.jumlah, 0) AS uang_du,
        du_current.tagihan_daftar_ulang_id,
        COALESCE(tdu.nominal_tagihan, 0) AS du_nominal_tagihan,
        COALESCE(psb_paid.total_pangkal_bayar, 0) AS total_pangkal_bayar,
        COALESCE(du_paid.total_du_bayar, 0) AS total_du_bayar,
        COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    LEFT JOIN bayar_du du_current ON du_current.bayar_id = b.id
    LEFT JOIN tagihan_daftar_ulang tdu ON tdu.id = du_current.tagihan_daftar_ulang_id
    LEFT JOIN (
        SELECT NO_INDUK, SUM(U_PANGKAL) AS total_pangkal_bayar
        FROM bayar
        GROUP BY NO_INDUK
    ) psb_paid ON psb_paid.NO_INDUK = b.NO_INDUK
    LEFT JOIN (
        SELECT no_induk, SUM(jumlah) AS total_du_bayar
        FROM bayar_du
        GROUP BY no_induk
    ) du_paid ON du_paid.no_induk = b.NO_INDUK
    $where_sql
    ORDER BY b.TGL_BYR DESC, b.id DESC
    LIMIT 201
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (count($rows) > 200) {
    http_response_code(413);
    exit('PDF dibatasi maksimal 200 struk. Persempit filter atau gunakan pilihan transaksi.');
}

if ($selected_mode && !$rows) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Transaksi yang dipilih tidak ditemukan pada periode ini.'];
    header('Location: index.php?bulan=' . urlencode((string)$filter_bulan) . '&tahun=' . urlencode((string)$filter_tahun) . '&tanggal_awal=' . urlencode($filter_tanggal_awal) . '&tanggal_akhir=' . urlencode($filter_tanggal_akhir));
    exit;
}

$details_by_payment = [];
if ($rows) {
    $ids = array_map(fn($row) => (int)$row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt_details = $koneksi->prepare("
        SELECT bayar_id, nama_biaya_snapshot, nominal_snapshot, keterangan
        FROM bayar_biaya_lain
        WHERE bayar_id IN ($placeholders)
        ORDER BY bayar_id ASC, urutan ASC, id ASC
    ");
    $stmt_details->bind_param($types, ...$ids);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    while ($detail = $result_details->fetch_assoc()) {
        $details_by_payment[(int)$detail['bayar_id']][] = $detail;
    }
    $stmt_details->close();
}

if (!$rows && !$selected_mode && !$preview_mode) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Tidak ada transaksi pada periode yang dipilih. PDF tidak dibuat.'];
    header('Location: index.php?bulan=' . urlencode((string)$filter_bulan) . '&tahun=' . urlencode((string)$filter_tahun) . '&tanggal_awal=' . urlencode($filter_tanggal_awal) . '&tanggal_akhir=' . urlencode($filter_tanggal_akhir));
    exit;
}

if (!$rows && $preview_mode) {
    $rows[] = [
        'id' => 0,
        'NO_INDUK' => '000000001',
        'NAMA' => 'Contoh Siswa',
        'KELAS_SISWA' => '2B',
        'BULAN' => str_pad((string)$filter_bulan, 2, '0', STR_PAD_LEFT),
        'TAHUN' => (string)$filter_tahun,
        'U_PANGKAL' => 0,
        'U_BANGUNAN' => 0,
        'U_SERAGAM' => 0,
        'U_KEGIATAN' => 0,
        'U_SPP' => 495000,
        'U_MAKAN' => 0,
        'U_SORGA' => 0,
        'U_INFAQ' => 0,
        'U_KOMITE' => 15000,
        'sistem_pembayaran' => 'VA',
        'potong_spp' => 0,
        'total_jumlah' => 510000,
        'TGL_BYR' => date('Y-m-d H:i:s'),
        'PANGKAL' => 0,
        'PANGKAL_BAYAR' => 0,
        'potong_pangkal' => 0,
        'tot_pangkal' => 0,
        'DAFTAR_ULANG' => 0,
        'potong_du' => 0,
        'tot_du' => 0,
        'uang_du' => 0,
        'total_pangkal_bayar' => 0,
        'total_du_bayar' => 0,
    ];
}

function primary_lines(array $row): array {
    return [
        payment_line('Uang PSB', $row['U_PANGKAL']),
        payment_line('Uang Daftar Ulang', $row['uang_du']),
        payment_line('Uang SPP', $row['U_SPP']),
        payment_line('Komite Sekolah', $row['U_KOMITE']),
    ];
}

function other_lines(array $row, array $details): array {
    $lines = [
        payment_line('Uang Bangunan', $row['U_BANGUNAN']),
        payment_line('Uang Seragam', $row['U_SERAGAM']),
        payment_line('Uang Kegiatan', $row['U_KEGIATAN']),
        payment_line('Uang Makan', $row['U_MAKAN']),
        payment_line('Uang Sorga', $row['U_SORGA']),
        payment_line('Uang Infaq', $row['U_INFAQ']),
    ];

    foreach ($details as $detail) {
        $label = $detail['nama_biaya_snapshot'];
        if (trim((string)$detail['keterangan']) !== '') {
            $label .= ' - ' . $detail['keterangan'];
        }
        $lines[] = payment_line($label, $detail['nominal_snapshot']);
    }

    if ((float)$row['potong_spp'] > 0) {
        $lines[] = payment_line('Potongan SPP', -1 * (float)$row['potong_spp']);
    }

    return array_values(array_filter($lines, fn($line) => abs((float)$line['amount']) > 0.001));
}

function total_psb_bill(array $row): float {
    $derived = (float)$row['tot_pangkal'];
    if ($derived > 0) return $derived;
    return max(0, (float)$row['PANGKAL'] - (float)$row['potong_pangkal']);
}

function total_du_bill(array $row): float {
    if ((float)($row['du_nominal_tagihan'] ?? 0) > 0) return (float)$row['du_nominal_tagihan'];
    $derived = (float)$row['tot_du'];
    if ($derived > 0) return $derived;
    return max(0, (float)$row['DAFTAR_ULANG'] - (float)$row['potong_du']);
}

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Slip Pembayaran</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <style>
    @page { size: 210mm 148mm; margin: 0; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #fff;
      color: #000;
      font-family: "Times New Roman", Times, serif;
      font-size: 11.5px;
      line-height: 1.22;
    }
    .slip {
      width: 190mm;
      height: 133mm;
      padding: 8mm 10mm 7mm;
      background: #fff;
      page-break-after: always;
      page-break-inside: avoid;
      overflow: hidden;
    }
    .slip:last-child { page-break-after: auto; }
    .school-title {
      margin: 0;
      text-align: center;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 15px;
      line-height: 1.15;
      font-weight: 800;
    }
    .school-address {
      margin: 7px 0 5px;
      text-align: center;
      font-size: 10.5px;
    }
    .line { border-top: 1px solid #000; }
    .doc-title {
      margin: 5px 0 9px;
      text-align: center;
      font-weight: 800;
      letter-spacing: 1.1px;
      font-size: 11px;
    }
    table {
      border-collapse: collapse;
      width: 100%;
    }
    .info-table,
    .detail-table,
    .total-table,
    .footer-table {
      width: 174mm;
      margin-left: 8mm;
      margin-right: 8mm;
    }
    .info-table {
      margin-bottom: 5px;
    }
    .info-table td,
    .mini-table td,
    .pay-table td,
    .footer-table td {
      vertical-align: top;
    }
    .info-left,
    .info-right,
    .detail-left,
    .detail-right {
      width: 50%;
    }
    .info-left,
    .detail-left {
      padding-right: 8mm;
    }
    .info-right,
    .detail-right {
      padding-left: 8mm;
    }
    .mini-table td {
      padding: 1.3px 0;
    }
    .label {
      width: 33mm;
    }
    .sep {
      width: 4mm;
      text-align: center;
    }
    .year-inline {
      display: inline-block;
      width: 27mm;
      text-align: right;
    }
    .split-line {
      margin: 3px 0 8px;
    }
    .detail-table {
      margin-bottom: 8px;
    }
    .section-label {
      margin-bottom: 4px;
      text-decoration: underline;
    }
    .pay-table td {
      padding: 2px 0;
    }
    .no-col {
      width: 6mm;
    }
    .pay-label {
      width: 48mm;
    }
    .pay-sep {
      width: 4mm;
      text-align: center;
    }
    .pay-amount {
      width: 25mm;
      text-align: right;
      white-space: nowrap;
    }
    .amount {
      text-align: right;
      white-space: nowrap;
    }
    .compact-label {
      font-style: italic;
      font-weight: 700;
    }
    .other-spacer {
      height: 9px;
    }
    .total-table {
      border-top: 1px solid #000;
      border-bottom: 1px solid #000;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .6px;
    }
    .total-table td {
      padding: 5px 0;
    }
    .total-label {
      padding-left: 0;
    }
    .total-amount {
      width: 32mm;
      text-align: right;
    }
    .footer-table {
      margin-top: 8px;
    }
    .footer-left {
      width: 68%;
    }
    .footer-right {
      width: 32%;
      text-align: center;
    }
    .terbilang-table td {
      padding: 0 0 13px;
    }
    .terbilang-label {
      width: 24mm;
      font-weight: 700;
    }
    .terbilang-value {
      font-size: 12.5px;
      font-style: italic;
    }
    .method-label {
      width: 34mm;
    }
    .signature-space { height: 20px; }
    .signature-name { font-weight: 700; }
  </style>
</head>
<body>
  <?php foreach ($rows as $row):
    $details = $details_by_payment[(int)$row['id']] ?? [];
    $primary = primary_lines($row);
    $others = other_lines($row, $details);
    $sisa_psb = max(0, total_psb_bill($row) - max((float)$row['PANGKAL_BAYAR'], (float)$row['total_pangkal_bayar']));
    $sisa_du = max(0, total_du_bill($row) - (float)$row['total_du_bayar']);
    $month_name = month_name_from_value($row['BULAN'], $bln_names);
    $signer = $row['operator_name'] ?: ($_SESSION['admin_nama'] ?? 'Bagian Keuangan');
  ?>
  <section class="slip">
    <h1 class="school-title">SEKOLAH DASAR AL-QUR'AN<br>( SDA ) MUTIARA HIKMAH</h1>
    <p class="school-address">Perum Bekasi Griya Asri II, Blok E Jl.H.Nabrih Ds. Sumber Jaya Kp.Buwek Tambun Selatan Telp. 021.88363466</p>
    <div class="line"></div>
    <div class="doc-title">SLIP PEMBAYARAN SEKOLAH</div>

    <table class="info-table">
      <tr>
        <td class="info-left">
          <table class="mini-table">
            <tr>
              <td class="label">No.Induk</td>
              <td class="sep">:</td>
              <td><?= e($row['NO_INDUK']) ?></td>
            </tr>
            <?php if (!empty($row['NO_induk_diknas'])): ?>
            <tr>
              <td class="label">NIS Diknas</td>
              <td class="sep">:</td>
              <td><?= e($row['NO_induk_diknas']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
              <td class="label">Nama Siswa</td>
              <td class="sep">:</td>
              <td><?= e($row['NAMA']) ?></td>
            </tr>
          </table>
        </td>
        <td class="info-right">
          <table class="mini-table">
            <tr>
              <td class="label">Kelas</td>
              <td class="sep">:</td>
              <td><?= e($row['KELAS_SISWA']) ?></td>
            </tr>
            <tr>
              <td class="label">Untuk Pembayaran Bulan</td>
              <td class="sep">:</td>
              <td><?= e($month_name) ?><span class="year-inline"><?= e($row['TAHUN']) ?></span></td>
            </tr>
          </table>
        </td>
      </tr>
    </table>

    <div class="line split-line"></div>

    <table class="detail-table">
      <tr>
        <td class="detail-left">
          <div class="section-label">Data Pembayaran :</div>
          <table class="pay-table">
            <?php foreach ($primary as $index => $line): ?>
            <tr>
              <td class="no-col"><?= $index + 1 ?>.</td>
              <td class="pay-label"><?= e($line['label']) ?></td>
              <td class="pay-sep">:</td>
              <td class="pay-amount"><?= e(money_plain($line['amount'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </td>
        <td class="detail-right">
          <div class="section-label">Sisa Pembayaran :</div>
          <table class="pay-table">
            <tr>
              <td class="compact-label">Sisa PSB</td>
              <td class="pay-sep">:</td>
              <td class="pay-amount"><?= e(money_plain($sisa_psb)) ?></td>
            </tr>
            <tr>
              <td class="compact-label">Sisa DU</td>
              <td class="pay-sep">:</td>
              <td class="pay-amount"><?= e(money_plain($sisa_du)) ?></td>
            </tr>
            <tr><td class="other-spacer" colspan="3"></td></tr>
          </table>
          <div class="section-label">Pembayaran Lain - lain</div>
          <table class="pay-table">
            <?php if ($others): ?>
              <?php foreach ($others as $line): ?>
              <tr>
                <td><?= e($line['label']) ?></td>
                <td class="pay-sep">:</td>
                <td class="pay-amount"><?= e($line['amount'] < 0 ? '-' . money_plain(abs($line['amount'])) : money_plain($line['amount'])) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td>&nbsp;</td><td class="pay-sep">:</td><td></td></tr>
              <tr><td>&nbsp;</td><td class="pay-sep">:</td><td></td></tr>
            <?php endif; ?>
          </table>
        </td>
      </tr>
    </table>

    <table class="total-table">
      <tr>
        <td class="total-label">JUMLAH TOTAL</td>
        <td class="total-amount"><?= e(money_total($row['total_jumlah'])) ?></td>
      </tr>
    </table>

    <table class="footer-table">
      <tr>
        <td class="footer-left">
          <table class="terbilang-table">
            <tr>
              <td class="terbilang-label">Terbilang:</td>
              <td class="terbilang-value"><?= e(terbilang_rupiah($row['total_jumlah'])) ?></td>
            </tr>
          </table>
          <table>
            <tr>
              <td class="method-label">Sistem Pembayaran</td>
              <td class="sep">:</td>
              <td><strong><?= e($row['sistem_pembayaran'] ?? 'VA') ?></strong></td>
            </tr>
          </table>
        </td>
        <td class="footer-right">
          <div>Bekasi, <?= e(date('d-M-Y', strtotime($row['TGL_BYR']))) ?></div>
          <div>Bagian Keuangan</div>
          <div class="signature-space"></div>
          <div class="signature-name"><?= e($signer) ?></div>
        </td>
      </tr>
    </table>
  </section>
  <?php endforeach; ?>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new \Dompdf\Options();
$options->setDefaultMediaType('print');
$options->setIsHtml5ParserEnabled(true);
$options->setIsRemoteEnabled(false);
$options->setChroot(realpath(__DIR__ . '/..'));

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper([0, 0, 595.276, 419.528]);
$dompdf->render();
$dompdf->stream(sprintf('slip-pembayaran-%04d-%02d.pdf', $filter_tahun, $filter_bulan), ['Attachment' => false]);
exit;
?>
