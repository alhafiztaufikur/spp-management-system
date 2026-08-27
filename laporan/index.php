<?php
// ============================================
// laporan/index.php - Rekap Laporan Keuangan
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/pagination.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/kelas.php';
requireRole(['admin', 'bendahara']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function report_money($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function report_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
    ];
    if (isset($map[$value])) return $map[$value];
    $number = (int)$value;
    return $number >= 1 && $number <= 12 ? str_pad((string)$number, 2, '0', STR_PAD_LEFT) : '01';
}

function report_academic_year($bulan, $tahun): string {
    $month = (int)report_month_code($bulan);
    $year = (int)$tahun;
    return du_academic_year_label($month, $year);
}

function report_bind(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
}

function report_date_param(string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function report_date_label_id(int $timestamp): string {
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    return date('d', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

function report_month_name_id(int $month): string {
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    return $months[$month] ?? '';
}

function report_transaction_date_label(string $startDate, string $endDate, string $fallback): string {
    if ($startDate === '' || $endDate === '') return $fallback;
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if (!$startTs || !$endTs) return $fallback;
    if ($startDate === $endDate) return report_date_label_id($startTs);
    if (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        return date('d', $startTs) . '-' . date('d', $endTs) . ' ' . report_month_name_id((int)date('n', $endTs)) . ' ' . date('Y', $endTs);
    }
    return report_date_label_id($startTs) . ' - ' . report_date_label_id($endTs);
}

$bln_names = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];

$filter_q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$filter_tanggal = report_date_param('tanggal');
$filter_tanggal_awal = report_date_param('tanggal_awal') ?: ($filter_tanggal ?: date('Y-m-01'));
$filter_tanggal_akhir = report_date_param('tanggal_akhir') ?: ($filter_tanggal ?: date('Y-m-d'));
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir === '') {
    $filter_tanggal_akhir = $filter_tanggal_awal;
}
if ($filter_tanggal_akhir !== '' && $filter_tanggal_awal === '') {
    $filter_tanggal_awal = $filter_tanggal_akhir;
}
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && strtotime($filter_tanggal_awal) > strtotime($filter_tanggal_akhir)) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
$periodReferenceTs = strtotime($filter_tanggal_akhir ?: date('Y-m-d'));
$filter_bulan = report_month_code($_GET['bulan'] ?? date('m', $periodReferenceTs));
$filter_tahun = preg_match('/^\d{4}$/', (string)($_GET['tahun'] ?? '')) ? (string)$_GET['tahun'] : date('Y', $periodReferenceTs);

$reportTypes = [
    'semua' => 'Semua transaksi',
    'sudah_bayar' => 'Yang sudah bayar',
    'belum_spp' => 'SPP belum lunas',
    'belum_komite' => 'Komite belum lunas',
    'belum_du' => 'Daftar ulang belum lunas',
    'belum_biaya_lain' => 'Biaya lain belum lunas',
];
$report_type = $_GET['jenis_laporan'] ?? 'semua';
if (!isset($reportTypes[$report_type])) $report_type = 'semua';

$sortOptions = [
    'terbaru' => 'Terbaru',
    'nama' => 'Nama siswa',
    'kelas' => 'Kelas',
    'nominal_terbesar' => 'Nominal terbesar',
    'sisa_terbesar' => 'Sisa terbesar',
];
$sort = $_GET['urut'] ?? 'terbaru';
if (!isset($sortOptions[$sort])) $sort = 'terbaru';

$allowedPageSizes = [10, 25, 50];
$perPage = page_size_param('per_page', $allowedPageSizes, 10);
$page = page_int_param('page');

$periodStart = $filter_tanggal_awal !== '' ? $filter_tanggal_awal . ' 00:00:00' : $filter_tahun . '-' . $filter_bulan . '-01 00:00:00';
$periodEnd = $filter_tanggal_akhir !== ''
    ? date('Y-m-d H:i:s', strtotime($filter_tanggal_akhir . ' +1 day'))
    : date('Y-m-d H:i:s', strtotime($periodStart . ' +1 month'));
$bulan_label = $bln_names[$filter_bulan];
$periodLabel = report_transaction_date_label($filter_tanggal_awal, $filter_tanggal_akhir, $bulan_label . ' ' . $filter_tahun);
$academicYear = report_academic_year($filter_bulan, $filter_tahun);

$isUnpaidReport = in_array($report_type, ['belum_spp', 'belum_komite', 'belum_du', 'belum_biaya_lain'], true);
$studentSearchSql = '';
$studentSearchParams = [];
if ($filter_q !== '') {
    $studentSearchSql = ' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $studentLike = '%' . $filter_q . '%';
    $studentSearchParams = [$studentLike, $studentLike, $studentLike];
}

$studentOptions = $koneksi->query("
    SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
           mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE s.is_active = 1
    ORDER BY s.NAMA ASC
")->fetch_all(MYSQLI_ASSOC);
$studentSearchDisplay = $filter_q;
foreach ($studentOptions as $studentOption) {
    if ($filter_q !== '' && ($filter_q === $studentOption['NO_INDUK'] || $filter_q === (string)($studentOption['NO_induk_diknas'] ?? ''))) {
        $studentSearchDisplay = $studentOption['NAMA'];
        break;
    }
}

// Rekap pembayaran pada tanggal/periode transaksi.
$stmt = $koneksi->prepare("
    SELECT COUNT(*) AS jml_tx,
           COALESCE(SUM(b.U_PANGKAL), 0) AS pangkal,
           COALESCE(SUM(b.U_BANGUNAN), 0) AS bangunan,
           COALESCE(SUM(b.U_SERAGAM), 0) AS seragam,
           COALESCE(SUM(b.U_KEGIATAN), 0) AS kegiatan,
           COALESCE(SUM(b.U_SPP), 0) AS spp,
           COALESCE(SUM(b.U_MAKAN), 0) AS makan,
           COALESCE(SUM(b.U_SORGA), 0) AS sorga,
           COALESCE(SUM(b.U_INFAQ), 0) AS infaq,
           COALESCE(SUM(b.U_KOMITE), 0) AS komite,
           COALESCE(SUM(b.total_jumlah), 0) AS total
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentSearchSql
");
report_bind($stmt, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmt->execute();
$bayar_recap = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmtBiayaLain = $koneksi->prepare("
    SELECT d.nama_biaya_snapshot AS nama, COALESCE(SUM(d.nominal_snapshot), 0) AS total
    FROM bayar_biaya_lain d
    JOIN bayar b ON b.id = d.bayar_id
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentSearchSql
    GROUP BY d.nama_biaya_snapshot
    ORDER BY d.nama_biaya_snapshot ASC
");
report_bind($stmtBiayaLain, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmtBiayaLain->execute();
$rekap_biaya_lain = $stmtBiayaLain->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtBiayaLain->close();

$stmtDu = $koneksi->prepare("
    SELECT COALESCE(SUM(bd.jumlah), 0) AS total_du
    FROM bayar_du bd
    JOIN bayar b ON b.id = bd.bayar_id
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentSearchSql
");
report_bind($stmtDu, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmtDu->execute();
$total_du_periode = (float)($stmtDu->get_result()->fetch_assoc()['total_du'] ?? 0);
$stmtDu->close();

$stmt2 = $koneksi->prepare("SELECT COALESCE(SUM(tm.MASUK),0) AS total_masuk FROM transaksi_m tm JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK WHERE tm.TANGGAL >= ? AND tm.TANGGAL < ? $studentSearchSql");
report_bind($stmt2, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmt2->execute();
$tab_masuk = (float)$stmt2->get_result()->fetch_assoc()['total_masuk'];
$stmt2->close();

$stmt3 = $koneksi->prepare("SELECT COALESCE(SUM(tk.KELUAR),0) AS total_keluar FROM transaksi_k tk JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK WHERE tk.TANGGAL >= ? AND tk.TANGGAL < ? $studentSearchSql");
report_bind($stmt3, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmt3->execute();
$tab_keluar = (float)$stmt3->get_result()->fetch_assoc()['total_keluar'];
$stmt3->close();

if ($filter_q === '') {
    $total_saldo = (float)$koneksi->query("SELECT COALESCE(SUM(SALDO),0) AS s FROM tabungan")->fetch_assoc()['s'];
} else {
    $stmtSaldo = $koneksi->prepare("SELECT COALESCE(SUM(t.SALDO),0) AS s FROM tabungan t JOIN siswa s ON s.NO_INDUK = t.NO_INDUK WHERE 1=1 $studentSearchSql");
    report_bind($stmtSaldo, str_repeat('s', count($studentSearchParams)), $studentSearchParams);
    $stmtSaldo->execute();
    $total_saldo = (float)($stmtSaldo->get_result()->fetch_assoc()['s'] ?? 0);
    $stmtSaldo->close();
}

$bayar_detail = [];
$unpaid_rows = [];
$totalDetailRows = 0;
$totalPages = 1;
$offset = 0;

if (!$isUnpaidReport) {
    $whereDetail = 'b.TGL_BYR >= ? AND b.TGL_BYR < ?';
    $detailTypes = 'ss';
    $detailParams = [$periodStart, $periodEnd];
    if ($report_type === 'sudah_bayar') {
        $whereDetail .= ' AND b.total_jumlah > 0';
    }
    if ($filter_q !== '') {
        $whereDetail .= $studentSearchSql;
        $detailTypes .= str_repeat('s', count($studentSearchParams));
        $detailParams = array_merge($detailParams, $studentSearchParams);
    }

    $orderSql = match ($sort) {
        'nama' => 's.NAMA ASC, b.TGL_BYR DESC',
        'kelas' => 'CAST(s.KELAS AS UNSIGNED) ASC, s.NAMA ASC, b.TGL_BYR DESC',
        'nominal_terbesar' => 'b.total_jumlah DESC, b.TGL_BYR DESC',
        default => 'b.TGL_BYR DESC, b.id DESC',
    };

    $stmtDetailCount = $koneksi->prepare("
        SELECT COUNT(*) AS total
        FROM bayar b
        JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
        WHERE $whereDetail
    ");
    report_bind($stmtDetailCount, $detailTypes, $detailParams);
    $stmtDetailCount->execute();
    $totalDetailRows = (int)($stmtDetailCount->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtDetailCount->close();

    $totalPages = total_pages($totalDetailRows, $perPage);
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt4 = $koneksi->prepare("
        SELECT b.id, s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
               b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
               b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_KOMITE,
               b.sistem_pembayaran, b.total_jumlah, b.TGL_BYR
        FROM bayar b
        JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
        WHERE $whereDetail
        ORDER BY $orderSql
        LIMIT ? OFFSET ?
    ");
    $detailTypesWithLimit = $detailTypes . 'ii';
    $detailParamsWithLimit = array_merge($detailParams, [$perPage, $offset]);
    report_bind($stmt4, $detailTypesWithLimit, $detailParamsWithLimit);
    $stmt4->execute();
    $bayar_detail = $stmt4->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt4->close();
} else {
    $periodMonthCode = $filter_bulan;
    $periodMonthName = $bulan_label;
    $periodMonthLegacy = (string)(int)$filter_bulan;
    $orderUnpaid = match ($sort) {
        'nama' => 'NAMA ASC',
        'kelas' => 'CAST(KELAS AS UNSIGNED) ASC, NAMA ASC',
        'nominal_terbesar', 'sisa_terbesar' => 'sisa DESC, NAMA ASC',
        default => 'CAST(KELAS AS UNSIGNED) ASC, NAMA ASC',
    };

    if ($report_type === 'belum_spp' || $report_type === 'belum_komite') {
        $studentBillColumn = $report_type === 'belum_spp' ? 'SPP_PERBULAN' : 'POMG';
        $paymentColumn = $report_type === 'belum_spp' ? 'U_SPP' : 'U_KOMITE';
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
                       s.$studentBillColumn AS tagihan,
                       COALESCE(SUM(b.$paymentColumn), 0) AS sudah_bayar,
                       GREATEST(s.$studentBillColumn - COALESCE(SUM(b.$paymentColumn), 0), 0) AS sisa
                FROM siswa s
                LEFT JOIN bayar b
                    ON b.NO_INDUK = s.NO_INDUK
                    AND b.TAHUN = ?
                    AND (b.BULAN = ? OR b.BULAN = ? OR b.BULAN = ?)
                WHERE s.is_active = 1 AND s.$studentBillColumn > 0 $studentSearchSql
                GROUP BY s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, s.$studentBillColumn
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        report_bind($stmtUnpaid, 'ssss' . str_repeat('s', count($studentSearchParams)), array_merge([$filter_tahun, $periodMonthCode, $periodMonthName, $periodMonthLegacy], $studentSearchParams));
    } elseif ($report_type === 'belum_du') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
                       tdu.nominal_tagihan AS tagihan,
                       COALESCE(SUM(bd.jumlah), 0) AS sudah_bayar,
                       GREATEST(tdu.nominal_tagihan - COALESCE(SUM(bd.jumlah), 0), 0) AS sisa
                FROM tagihan_daftar_ulang tdu
                JOIN siswa s ON s.NO_INDUK = tdu.no_induk
                LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
                WHERE s.is_active = 1 AND tdu.tahun_ajaran_snapshot = ? AND tdu.nominal_tagihan > 0 $studentSearchSql
                GROUP BY s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tdu.nominal_tagihan
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        report_bind($stmtUnpaid, 's' . str_repeat('s', count($studentSearchParams)), array_merge([$academicYear], $studentSearchParams));
    } else {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
                       m.nama AS komponen,
                       m.nominal AS tagihan,
                       COALESCE(SUM(d.nominal_snapshot), 0) AS sudah_bayar,
                       GREATEST(m.nominal - COALESCE(SUM(d.nominal_snapshot), 0), 0) AS sisa
                FROM siswa s
                JOIN master_biaya_lain m ON m.is_active = 1
                LEFT JOIN bayar b ON b.NO_INDUK = s.NO_INDUK
                LEFT JOIN bayar_biaya_lain d ON d.bayar_id = b.id AND d.master_biaya_lain_id = m.id
                WHERE s.is_active = 1 $studentSearchSql
                GROUP BY s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, m.id, m.nama, m.nominal
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        report_bind($stmtUnpaid, str_repeat('s', count($studentSearchParams)), $studentSearchParams);
    }
    $stmtUnpaid->execute();
    $unpaid_rows = $stmtUnpaid->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtUnpaid->close();
    $totalDetailRows = count($unpaid_rows);
}

$laporanPaginationQuery = pagination_query([
    'bulan' => $filter_bulan,
    'tahun' => $filter_tahun,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'q' => $filter_q,
    'jenis_laporan' => $report_type,
    'urut' => $sort,
    'per_page' => $perPage,
]);
$exportQuery = http_build_query([
    'bulan' => $filter_bulan,
    'tahun' => $filter_tahun,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'q' => $filter_q,
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Laporan Keuangan | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=7.4" />
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>

<div class="layout">
  <?php include '../includes/sidebar.php'; ?>

  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title">
        <h2>Laporan Keuangan</h2>
        <span class="breadcrumb">SistemSPP / Laporan</span>
      </div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <div class="page-content">
      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" id="flash-msg" style="margin-bottom:16px;">
        <?= report_e($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <section class="main-card class-recap-card recap-report-shell report-general-shell" style="margin-bottom:16px;">
        <div class="recap-report-header">
          <div class="recap-report-copy">
            <span class="recap-class-overline">Laporan Umum</span>
            <h1>Rekap Laporan Keuangan</h1>
            <p><?= report_e($reportTypes[$report_type]) ?> untuk periode <?= report_e($periodLabel) ?>.</p>
          </div>
        <form method="GET" class="recap-header-controls report-filter-card report-general-filter report-filter-grid">
          <div class="field-row report-date-range-field">
            <label class="field-label">Tanggal transaksi</label>
            <div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= report_e($periodLabel) ?>">
              <input type="hidden" name="tanggal_awal" value="<?= report_e($filter_tanggal_awal) ?>">
              <input type="hidden" name="tanggal_akhir" value="<?= report_e($filter_tanggal_akhir) ?>">
              <button type="button" class="report-date-range-button" aria-expanded="false">
                <span class="report-date-range-icon">📅</span>
                <span class="report-date-range-value"><?= report_e($periodLabel) ?></span>
              </button>
              <div class="report-date-range-popover" hidden>
                <label>
                  <span>Mulai</span>
                  <input type="date" value="<?= report_e($filter_tanggal_awal) ?>" data-range-start aria-label="Tanggal transaksi mulai">
                </label>
                <label>
                  <span>Sampai</span>
                  <input type="date" value="<?= report_e($filter_tanggal_akhir) ?>" data-range-end aria-label="Tanggal transaksi sampai">
                </label>
                <div class="report-date-range-popover-actions">
                  <button type="button" class="btn btn-primary btn-sm" data-range-apply>Terapkan</button>
                </div>
              </div>
            </div>
          </div>
          <div class="field-row">
            <label class="field-label">Jenis laporan</label>
            <select class="field-input field-select" name="jenis_laporan">
              <?php foreach ($reportTypes as $key => $label): ?>
              <option value="<?= report_e($key) ?>" <?= $report_type === $key ? 'selected' : '' ?>><?= report_e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row">
            <label class="field-label">Urutkan</label>
            <select class="field-input field-select" name="urut">
              <?php foreach ($sortOptions as $key => $label): ?>
              <option value="<?= report_e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= report_e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row">
            <label class="field-label">Per Halaman</label>
            <select class="field-input field-select" name="per_page">
              <?php foreach ($allowedPageSizes as $pageSize): ?>
              <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row full-span">
            <label class="field-label" for="report-siswa-search">Cari Siswa (Nama / NIS / NIS Diknas)</label>
            <div class="search-box">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="report-siswa-search" data-student-search data-student-list="report-siswa-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="report-student-query" value="<?= report_e($studentSearchDisplay) ?>" placeholder="Ketik nama, NIS, atau NIS Diknas..." autocomplete="off">
              <input type="hidden" id="report-student-query" name="q" value="<?= report_e($filter_q) ?>">
            </div>
            <datalist id="report-siswa-list">
              <?php foreach ($studentOptions as $studentOption): ?>
              <?php $studentClassLabel = class_label(['tingkat' => $studentOption['master_tingkat'] ?: $studentOption['KELAS'], 'kode_rombel' => $studentOption['kode_rombel'] ?? 'BELUM', 'is_placeholder' => $studentOption['is_placeholder'] ?? 1]); ?>
              <option value="<?= report_e($studentOption['NAMA']) ?>"
                data-nis="<?= report_e($studentOption['NO_INDUK']) ?>"
                data-diknas="<?= report_e((string)($studentOption['NO_induk_diknas'] ?? '')) ?>"
                data-nama="<?= report_e($studentOption['NAMA']) ?>"
                data-kelas="<?= report_e($studentClassLabel) ?>">
                <?= report_e($studentOption['NAMA']) ?> (<?= report_e($studentClassLabel) ?>)
              </option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="report-filter-actions">
            <button type="submit" class="btn btn-primary">Tampilkan</button>
            <a href="index.php" class="btn btn-ghost">Reset</a>
            <a href="global.php" class="btn btn-success">Laporan Global</a>
          </div>
          <div class="report-export-actions">
            <a href="export_excel.php?<?= report_e($exportQuery) ?>" class="btn btn-success">Export Excel</a>
            <a href="export_pdf.php?<?= report_e($exportQuery) ?>" class="btn btn-warning">Export PDF</a>
          </div>
        </form>
        </div>
      </section>

      <div class="stats-grid report-stats-grid" style="margin-bottom:8px;">
        <div class="stat-card stat-blue">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
          <div class="stat-info"><span class="stat-value"><?= report_money($bayar_recap['total'] ?? 0) ?></span><span class="stat-label">Total Pembayaran</span></div>
        </div>
        <div class="stat-card stat-green">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
          <div class="stat-info"><span class="stat-value"><?= report_money($tab_masuk) ?></span><span class="stat-label">Tabungan Masuk</span></div>
        </div>
        <div class="stat-card" style="--c:#ef4444;">
          <div class="stat-icon" style="background:rgba(239,68,68,0.15);color:#ef4444;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 17H18M12 22V2M7 7l5-5 5 5"/></svg></div>
          <div class="stat-info"><span class="stat-value"><?= report_money($tab_keluar) ?></span><span class="stat-label">Tabungan Keluar</span></div>
        </div>
        <div class="stat-card stat-purple">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
          <div class="stat-info"><span class="stat-value"><?= number_format((int)($bayar_recap['jml_tx'] ?? 0)) ?></span><span class="stat-label">Transaksi <?= report_e($periodLabel) ?></span></div>
        </div>
      </div>

      <div class="main-card" style="margin-bottom:16px;">
        <div class="card-header">
          <h3 class="card-title">Rekap Komponen Pembayaran - <?= report_e($periodLabel) ?></h3>
        </div>
        <div class="table-container">
          <table class="payment-table">
            <thead><tr><th>Komponen</th><th>Total (Rp)</th></tr></thead>
            <tbody>
              <?php
              $komponen_map = [
                'Uang Pangkal' => $bayar_recap['pangkal'],
                'Uang Bangunan' => $bayar_recap['bangunan'],
                'Uang Seragam' => $bayar_recap['seragam'],
                'Uang Kegiatan' => $bayar_recap['kegiatan'],
                'Uang SPP' => $bayar_recap['spp'],
                'Uang Komite' => $bayar_recap['komite'],
                'Uang Makan' => $bayar_recap['makan'],
                'Uang Sorga' => $bayar_recap['sorga'],
                'Uang Infaq' => $bayar_recap['infaq'],
                'Daftar Ulang' => $total_du_periode,
              ];
              $shownComponents = 0;
              foreach ($komponen_map as $nama => $val):
                if ((float)$val <= 0) continue;
                $shownComponents++;
              ?>
              <tr><td><?= report_e($nama) ?></td><td class="nominal"><?= report_money($val) ?></td></tr>
              <?php endforeach; ?>
              <?php foreach ($rekap_biaya_lain as $biaya): if ((float)$biaya['total'] <= 0) continue; $shownComponents++; ?>
              <tr><td><?= report_e($biaya['nama']) ?></td><td class="nominal"><?= report_money($biaya['total']) ?></td></tr>
              <?php endforeach; ?>
              <?php if ($shownComponents === 0): ?>
              <tr><td colspan="2" style="text-align:center;padding:28px;color:var(--text-muted);">Belum ada komponen pembayaran pada periode ini.</td></tr>
              <?php endif; ?>
              <tr style="font-weight:700;border-top:2px solid var(--border);">
                <td>TOTAL</td>
                <td class="nominal" style="color:var(--accent);"><?= report_money($bayar_recap['total'] ?? 0) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="main-card">
        <div class="card-header laporan-detail-header">
          <div class="laporan-detail-heading">
            <h3 class="card-title"><?= report_e($reportTypes[$report_type]) ?> - <?= report_e($isUnpaidReport ? ($bulan_label . ' ' . $filter_tahun) : $periodLabel) ?></h3>
            <span class="badge-count"><?= number_format($totalDetailRows) ?> <?= $isUnpaidReport ? 'siswa/tagihan' : 'transaksi' ?></span>
          </div>
          <?php if (!$isUnpaidReport && !empty($bayar_detail)): ?>
          <button type="submit" form="print-selected-form" class="btn btn-warning btn-print-selected" id="btn-print-selected" disabled>Cetak Dipilih</button>
          <?php endif; ?>
        </div>

        <?php if ($isUnpaidReport): ?>
        <div class="table-container">
          <table class="payment-table report-unpaid-table">
            <thead><tr><th>No</th><th>No. Induk</th><th>Nama</th><th class="kelas-col">Kelas</th><?php if ($report_type === 'belum_biaya_lain'): ?><th>Komponen</th><?php endif; ?><th>Tagihan</th><th>Sudah Bayar</th><th>Sisa</th></tr></thead>
            <tbody>
              <?php if (!$unpaid_rows): ?>
              <tr><td colspan="<?= $report_type === 'belum_biaya_lain' ? 8 : 7 ?>" style="text-align:center;padding:40px;color:var(--text-muted);">Tidak ada data belum lunas untuk pilihan ini.</td></tr>
              <?php else: foreach ($unpaid_rows as $i => $row): ?>
              <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                <td><?= $i + 1 ?></td>
                <td><span class="badge-nis"><?= report_e($row['NO_INDUK']) ?></span><?php if (!empty($row['NO_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= report_e($row['NO_induk_diknas']) ?></small><?php endif; ?></td>
                <td><?= report_e($row['NAMA']) ?></td>
                <td class="kelas-col"><span class="kelas-badge">Kelas <?= report_e($row['KELAS']) ?></span></td>
                <?php if ($report_type === 'belum_biaya_lain'): ?><td><?= report_e($row['komponen'] ?? 'Biaya Lain') ?></td><?php endif; ?>
                <td class="nominal"><?= report_money($row['tagihan']) ?></td>
                <td class="nominal"><?= report_money($row['sudah_bayar']) ?></td>
                <td class="nominal report-remaining"><?= report_money($row['sisa']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <form method="GET" action="export_pdf.php" id="print-selected-form" target="_blank" rel="noopener">
          <input type="hidden" name="bulan" value="<?= report_e($filter_bulan) ?>">
          <input type="hidden" name="tahun" value="<?= report_e($filter_tahun) ?>">
          <input type="hidden" name="tanggal_awal" value="<?= report_e($filter_tanggal_awal) ?>">
          <input type="hidden" name="tanggal_akhir" value="<?= report_e($filter_tanggal_akhir) ?>">
          <input type="hidden" name="q" value="<?= report_e($filter_q) ?>">
          <input type="hidden" name="mode" value="selected">
          <div class="table-container">
            <table class="payment-table" id="tbl-laporan">
              <thead>
                <tr>
                  <th class="select-col"><input type="checkbox" class="select-print-check" id="check-all-print" aria-label="Pilih semua transaksi"></th>
                  <th>No</th><th>No. Induk</th><th>Nama</th><th class="kelas-col">Kelas</th><th>Bulan Bayar</th><th>Sistem</th><th>Total (Rp)</th><th>Tgl Bayar</th><th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($bayar_detail)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">Belum ada data pembayaran pada pilihan laporan ini.</td></tr>
                <?php else: foreach ($bayar_detail as $i => $b): ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td class="select-col"><input type="checkbox" class="select-print-check row-print-check" name="ids[]" value="<?= (int)$b['id'] ?>" aria-label="Pilih transaksi <?= report_e($b['NAMA']) ?>"></td>
                  <td><?= $offset + $i + 1 ?></td>
                  <td><span class="badge-nis"><?= report_e($b['NO_INDUK']) ?></span><?php if (!empty($b['NO_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= report_e($b['NO_induk_diknas']) ?></small><?php endif; ?></td>
                  <td><?= report_e($b['NAMA']) ?></td>
                  <td class="kelas-col"><span class="kelas-badge">Kelas <?= report_e($b['KELAS']) ?></span></td>
                  <td><?= report_e($b['BULAN']) ?> <?= report_e($b['TAHUN']) ?></td>
                  <td><?= report_e($b['sistem_pembayaran'] ?? 'VA') ?></td>
                  <td class="nominal"><?= report_money($b['total_jumlah']) ?></td>
                  <td><?= date('d M Y H:i', strtotime($b['TGL_BYR'])) ?></td>
                  <td class="aksi-col"><a class="btn-tbl btn-tbl-print" href="cetak_struk.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener">Cetak</a></td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </form>
        <?php render_pagination('index.php', $laporanPaginationQuery, $page, $totalPages, $totalDetailRows, $perPage, 'transaksi'); ?>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<script src="../assets/js/app.js?v=7.2"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  autoHideFlash();

  const form = document.getElementById('print-selected-form');
  const checkAll = document.getElementById('check-all-print');
  const rowChecks = Array.from(document.querySelectorAll('.row-print-check'));
  const printButton = document.getElementById('btn-print-selected');

  function refreshPrintSelection() {
    const selectedCount = rowChecks.filter(check => check.checked).length;
    if (printButton) {
      printButton.disabled = selectedCount === 0;
      printButton.textContent = selectedCount > 0 ? 'Cetak Dipilih (' + selectedCount + ')' : 'Cetak Dipilih';
    }
    if (checkAll) {
      checkAll.checked = selectedCount > 0 && selectedCount === rowChecks.length;
      checkAll.indeterminate = selectedCount > 0 && selectedCount < rowChecks.length;
    }
  }

  if (checkAll) {
    checkAll.addEventListener('change', function(){
      rowChecks.forEach(check => { check.checked = checkAll.checked; });
      refreshPrintSelection();
    });
  }

  rowChecks.forEach(check => check.addEventListener('change', refreshPrintSelection));

  if (form) {
    form.addEventListener('submit', function(event){
      if (!rowChecks.some(check => check.checked)) {
        event.preventDefault();
        if (typeof showToast === 'function') {
          showToast('!', 'Pilih minimal satu transaksi untuk dicetak.', 'error');
        }
      }
    });
  }
});
</script>
</body>
</html>
