<?php
// ============================================
// laporan/index.php - Rekap Laporan Keuangan
// ============================================
require_once '../includes/security.php';
security_bootstrap_session();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/pagination.php';
require_once '../includes/daftar_ulang.php';
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
    $raw = $_GET[$key] ?? '';
    $value = trim((string)(is_scalar($raw) ? $raw : ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
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

$filterMonthRaw = $_GET['bulan'] ?? date('m');
$filterYearRaw = $_GET['tahun'] ?? '';
$filter_bulan = report_month_code(is_scalar($filterMonthRaw) ? $filterMonthRaw : date('m'));
$filter_tahun = is_scalar($filterYearRaw) && preg_match('/^\d{4}$/', (string)$filterYearRaw) ? (string)$filterYearRaw : date('Y');
$filter_tanggal = report_date_param('tanggal');
$filter_tanggal_awal = report_date_param('tanggal_awal') ?: $filter_tanggal;
$filter_tanggal_akhir = report_date_param('tanggal_akhir') ?: $filter_tanggal;
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir === '') {
    $filter_tanggal_akhir = $filter_tanggal_awal;
}
if ($filter_tanggal_akhir !== '' && $filter_tanggal_awal === '') {
    $filter_tanggal_awal = $filter_tanggal_akhir;
}
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && strtotime($filter_tanggal_awal) > strtotime($filter_tanggal_akhir)) {
    [$filter_tanggal_awal, $filter_tanggal_akhir] = [$filter_tanggal_akhir, $filter_tanggal_awal];
}
if ($filter_tanggal_awal !== '' && $filter_tanggal_akhir !== '' && (new DateTimeImmutable($filter_tanggal_awal))->diff(new DateTimeImmutable($filter_tanggal_akhir))->days > 365) {
    http_response_code(413);
    exit('Rentang laporan dibatasi maksimal 366 hari kalender.');
}

$reportTypes = [
    'semua' => 'Semua transaksi',
    'sudah_bayar' => 'Yang sudah bayar',
    'belum_spp' => 'SPP belum lunas',
    'belum_komite' => 'Komite belum lunas',
    'belum_du' => 'Daftar ulang belum lunas',
    'belum_biaya_lain' => 'Biaya lain belum lunas',
];
$report_type = is_scalar($_GET['jenis_laporan'] ?? null) ? (string)$_GET['jenis_laporan'] : 'semua';
if (!isset($reportTypes[$report_type])) $report_type = 'semua';

$sortOptions = [
    'terbaru' => 'Terbaru',
    'nama' => 'Nama siswa',
    'kelas' => 'Kelas',
    'nominal_terbesar' => 'Nominal terbesar',
    'sisa_terbesar' => 'Sisa terbesar',
];
$sort = is_scalar($_GET['urut'] ?? null) ? (string)$_GET['urut'] : 'terbaru';
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
           COALESCE(SUM(b.potong_spp), 0) AS potong_spp,
           COALESCE(SUM(b.total_jumlah), 0) AS total
    FROM bayar b
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?
");
$stmt->bind_param('ss', $periodStart, $periodEnd);
$stmt->execute();
$bayar_recap = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmtBiayaLain = $koneksi->prepare("
    SELECT d.nama_biaya_snapshot AS nama, COALESCE(SUM(d.nominal_snapshot), 0) AS total
    FROM bayar_biaya_lain d
    JOIN bayar b ON b.id = d.bayar_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?
    GROUP BY d.nama_biaya_snapshot
    ORDER BY d.nama_biaya_snapshot ASC
");
$stmtBiayaLain->bind_param('ss', $periodStart, $periodEnd);
$stmtBiayaLain->execute();
$rekap_biaya_lain = $stmtBiayaLain->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtBiayaLain->close();

$stmtDu = $koneksi->prepare("
    SELECT COALESCE(SUM(bd.jumlah), 0) AS total_du
    FROM bayar_du bd
    JOIN bayar b ON b.id = bd.bayar_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ?
");
$stmtDu->bind_param('ss', $periodStart, $periodEnd);
$stmtDu->execute();
$total_du_periode = (float)($stmtDu->get_result()->fetch_assoc()['total_du'] ?? 0);
$stmtDu->close();

$stmt2 = $koneksi->prepare("SELECT COALESCE(SUM(MASUK),0) AS total_masuk FROM transaksi_m WHERE bayar_id IS NULL AND TANGGAL >= ? AND TANGGAL < ?");
$stmt2->bind_param('ss', $periodStart, $periodEnd);
$stmt2->execute();
$tab_masuk = (float)$stmt2->get_result()->fetch_assoc()['total_masuk'];
$stmt2->close();

$stmt3 = $koneksi->prepare("SELECT COALESCE(SUM(KELUAR),0) AS total_keluar FROM transaksi_k WHERE TANGGAL >= ? AND TANGGAL < ?");
$stmt3->bind_param('ss', $periodStart, $periodEnd);
$stmt3->execute();
$tab_keluar = (float)$stmt3->get_result()->fetch_assoc()['total_keluar'];
$stmt3->close();

$total_saldo = (float)$koneksi->query("SELECT COALESCE(SUM(SALDO),0) AS s FROM tabungan")->fetch_assoc()['s'];

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

    if ($report_type === 'belum_spp') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot AS KELAS,
                       sta.spp_perbulan_snapshot AS tagihan,
                       COALESCE(SUM(b.U_SPP), 0) AS sudah_bayar,
                       GREATEST(sta.spp_perbulan_snapshot - COALESCE(SUM(b.U_SPP), 0), 0) AS sisa
                FROM siswa_tahun_ajaran sta
                JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
                JOIN siswa s ON s.NO_INDUK = sta.no_induk
                LEFT JOIN bayar_spp_periode bsp ON bsp.no_induk = sta.no_induk AND bsp.tahun = ? AND bsp.bulan = ?
                LEFT JOIN bayar b ON b.id = bsp.bayar_id
                WHERE ta.label = ? AND sta.status = 'aktif' AND s.is_active = 1 AND sta.spp_perbulan_snapshot > 0
                GROUP BY sta.id, s.NO_INDUK, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot, sta.spp_perbulan_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        $stmtUnpaid->bind_param('sss', $filter_tahun, $periodMonthCode, $academicYear);
    } elseif ($report_type === 'belum_komite') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot AS KELAS,
                       sta.komite_snapshot AS tagihan,
                       COALESCE(SUM(b.U_KOMITE), 0) AS sudah_bayar,
                       GREATEST(sta.komite_snapshot - COALESCE(SUM(b.U_KOMITE), 0), 0) AS sisa
                FROM siswa_tahun_ajaran sta
                JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
                JOIN siswa s ON s.NO_INDUK = sta.no_induk
                LEFT JOIN bayar b ON b.NO_INDUK = sta.no_induk AND b.TAHUN = ?
                    AND (b.BULAN = ? OR b.BULAN = ? OR b.BULAN = ?)
                WHERE ta.label = ? AND sta.status = 'aktif' AND s.is_active = 1 AND sta.komite_snapshot > 0
                GROUP BY sta.id, s.NO_INDUK, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot, sta.komite_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        $stmtUnpaid->bind_param('sssss', $filter_tahun, $periodMonthCode, $periodMonthName, $periodMonthLegacy, $academicYear);
    } elseif ($report_type === 'belum_du') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, tdu.kelas_snapshot AS KELAS,
                       tdu.nominal_tagihan AS tagihan,
                       COALESCE(SUM(bd.jumlah), 0) AS sudah_bayar,
                       GREATEST(tdu.nominal_tagihan - COALESCE(SUM(bd.jumlah), 0), 0) AS sisa
                FROM tagihan_daftar_ulang tdu
                JOIN siswa s ON s.NO_INDUK = tdu.no_induk
                LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
                WHERE s.is_active = 1 AND tdu.tahun_ajaran_snapshot = ? AND tdu.status = 'open' AND tdu.nominal_tagihan > 0
                GROUP BY s.NO_INDUK, s.NO_induk_diknas, s.NAMA, tdu.kelas_snapshot, tdu.nominal_tagihan
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        $stmtUnpaid->bind_param('s', $academicYear);
    } else {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA,
                       COALESCE(t.kelas_rombel_snapshot, s.KELAS) AS KELAS,
                       t.nama_snapshot AS komponen,
                       t.nominal_tagihan AS tagihan,
                       COALESCE(SUM(d.nominal_snapshot), 0) AS sudah_bayar,
                       GREATEST(t.nominal_tagihan - COALESCE(SUM(d.nominal_snapshot), 0), 0) AS sisa
                FROM tagihan_biaya_lain t
                JOIN siswa s ON s.NO_INDUK = t.no_induk
                LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id = t.id
                WHERE s.is_active = 1 AND t.status = 'open'
                GROUP BY t.id, s.NO_INDUK, s.NO_induk_diknas, s.NAMA, t.kelas_rombel_snapshot, s.KELAS, t.nama_snapshot, t.nominal_tagihan
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
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
    'jenis_laporan' => $report_type,
    'urut' => $sort,
    'per_page' => $perPage,
]);
$exportQuery = http_build_query([
    'bulan' => $filter_bulan,
    'tahun' => $filter_tahun,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
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
  <link rel="stylesheet" href="../assets/css/style.css?v=6.4" />
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>

<div class="layout">
  <?php include '../includes/sidebar.php'; ?>

  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi" aria-expanded="false">
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

      <div class="main-card report-filter-card" style="margin-bottom:16px;">
        <form method="GET" class="report-filter-grid">
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
            <label class="field-label">Bulan periode</label>
            <select class="field-input field-select" name="bulan">
              <?php foreach ($bln_names as $num => $nama): ?>
              <option value="<?= $num ?>" <?= $filter_bulan === $num ? 'selected' : '' ?>><?= report_e($nama) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row">
            <label class="field-label">Tahun</label>
            <?php
              $selectedReportYear = (int)$filter_tahun;
              $reportYearStart = min((int)date('Y'), $selectedReportYear);
              $reportYearEnd = max((int)date('Y') + 10, $selectedReportYear);
            ?>
            <div class="payment-year-picker report-year-picker">
              <input class="field-input payment-year-select" type="text" name="tahun" inputmode="numeric" pattern="\d{4}" maxlength="4" value="<?= report_e($filter_tahun) ?>" autocomplete="off">
              <div class="payment-year-options" role="listbox" aria-label="Pilihan tahun laporan">
                <?php for ($y = $reportYearStart; $y <= $reportYearEnd; $y++): ?>
                <button type="button" class="payment-year-option" data-year="<?= $y ?>" role="option"><?= $y ?></button>
                <?php endfor; ?>
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
                'Potongan SPP' => -(float)$bayar_recap['potong_spp'],
              ];
              $shownComponents = 0;
              foreach ($komponen_map as $nama => $val):
                if (abs((float)$val) < 0.005) continue;
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
        <form method="GET" action="export_pdf.php" id="print-selected-form">
          <input type="hidden" name="bulan" value="<?= report_e($filter_bulan) ?>">
          <input type="hidden" name="tahun" value="<?= report_e($filter_tahun) ?>">
          <input type="hidden" name="tanggal_awal" value="<?= report_e($filter_tanggal_awal) ?>">
          <input type="hidden" name="tanggal_akhir" value="<?= report_e($filter_tanggal_akhir) ?>">
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

<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"><span id="toast-icon" aria-hidden="true"></span><span id="toast-msg"></span></div>
<script src="../assets/js/app.js?v=6.4"></script>
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
