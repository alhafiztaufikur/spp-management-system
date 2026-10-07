<?php
// ============================================
// laporan/index.php - Rekap Laporan Keuangan
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/payment_permissions.php';
require_once '../includes/finance_ui.php';
require_once '../includes/pagination.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/kelas.php';
require_once '../includes/tagihan_tahunan.php';
requireRole(['admin', 'bendahara']);
$reportUnitId=unit_report_scope($koneksi,(string)($_GET['unit']??''));

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

function report_date_label_id(int $timestamp): string { return spp_date_label($timestamp); }

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
require_once '../includes/general_multiple.php';
$generalChoices=general_choices($_GET);$report_type=filter_scalar($generalChoices,'semua');
$generalMultiple=is_array($_GET['jenis_laporan']??null);
filter_output_start();

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

$studentSearchSql .= unit_student_selection_where();
$studentOptions = $koneksi->query("
    SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
           mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE s.is_active = 1
    ORDER BY s.NAMA ASC
")->fetch_all(MYSQLI_ASSOC);
$studentSearchDisplay = $filter_q;
$displayMatches=array_values(array_filter($studentOptions,static fn($o)=>(int)($_GET['student_id']??0)>0 ? (int)$o['student_id']===(int)$_GET['student_id'] : ($filter_q!==''&&($filter_q===$o['NO_INDUK']||$filter_q===(string)($o['NO_induk_diknas']??'')))));
if(count($displayMatches)===1)$studentSearchDisplay=$displayMatches[0]['NAMA'];

// Rekap pembayaran pada tanggal/periode transaksi.
$stmt = $koneksi->prepare("
    SELECT COUNT(*) AS jml_tx,
           COALESCE(SUM(b.U_PSB), 0) AS psb,
           COALESCE(SUM(b.U_SPP), 0) AS spp,
           COALESCE(SUM(b.U_KOMITE), 0) AS komite,
           COALESCE(SUM(b.potong_spp), 0) AS potongan_spp,
           COALESCE(SUM(b.total_jumlah), 0) AS total
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
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
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
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
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $studentSearchSql
");
report_bind($stmtDu, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmtDu->execute();
$total_du_periode = (float)($stmtDu->get_result()->fetch_assoc()['total_du'] ?? 0);
$stmtDu->close();

$stmt2 = $koneksi->prepare("SELECT COUNT(*) AS jml_tx, COALESCE(SUM(tm.MASUK),0) AS total_masuk FROM transaksi_m tm JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK AND s.unit_id=tm.unit_id WHERE tm.TANGGAL >= ? AND tm.TANGGAL < ? $studentSearchSql");
report_bind($stmt2, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmt2->execute();
$savingsIncoming = $stmt2->get_result()->fetch_assoc();
$tab_masuk = (float)$savingsIncoming['total_masuk'];
$stmt2->close();

$stmt3 = $koneksi->prepare("SELECT COUNT(*) AS jml_tx, COALESCE(SUM(tk.KELUAR),0) AS total_keluar FROM transaksi_k tk JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK AND s.unit_id=tk.unit_id WHERE tk.TANGGAL >= ? AND tk.TANGGAL < ? $studentSearchSql");
report_bind($stmt3, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$periodStart, $periodEnd], $studentSearchParams));
$stmt3->execute();
$savingsOutgoing = $stmt3->get_result()->fetch_assoc();
$tab_keluar = (float)$savingsOutgoing['total_keluar'];
$stmt3->close();

if ($filter_q === '') {
    $total_saldo = (float)$koneksi->query("SELECT COALESCE(SUM(SALDO),0) AS s FROM tabungan")->fetch_assoc()['s'];
} else {
    $stmtSaldo = $koneksi->prepare("SELECT COALESCE(SUM(t.SALDO),0) AS s FROM tabungan t JOIN siswa s ON s.NO_INDUK = t.NO_INDUK AND s.unit_id=t.unit_id WHERE 1=1 $studentSearchSql");
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

if($generalMultiple){
    $generalSections=general_sections($koneksi,$generalChoices,$periodStart,$periodEnd,$filter_q,$sort);
    $generalAllRows=[];foreach($generalSections as $sectionIndex=>$section)foreach($section['rows'] as $row){$row['_section']=$sectionIndex;$generalAllRows[]=$row;}
    $totalDetailRows=count($generalAllRows);$totalPages=total_pages($totalDetailRows,$perPage);$page=min($page,$totalPages);$offset=($page-1)*$perPage;
    $generalPageRows=array_slice($generalAllRows,$offset,$perPage);
} elseif (!$isUnpaidReport) {
    $whereDetail = 'b.TGL_BYR >= ? AND b.TGL_BYR < ?';
    $detailTypes = 'ss';
    $detailParams = [$periodStart, $periodEnd];
    if ($report_type === 'sudah_bayar') {
        $whereDetail .= ' AND b.total_jumlah > 0';
    }
    if ($studentSearchSql !== '') {
        $whereDetail .= $studentSearchSql;
        $detailTypes .= str_repeat('s', count($studentSearchParams));
        $detailParams = array_merge($detailParams, $studentSearchParams);
    }

    $orderSql = match ($sort) {
        'nama' => 's.NAMA ASC, b.TGL_BYR DESC',
        'kelas' => 'CAST(b.KELAS AS UNSIGNED) ASC, b.kelas_rombel_snapshot ASC, s.NAMA ASC, b.TGL_BYR DESC',
        'nominal_terbesar' => 'b.total_jumlah DESC, b.TGL_BYR DESC',
        default => 'b.TGL_BYR DESC, b.id DESC',
    };

    $stmtDetailCount = $koneksi->prepare("
        SELECT COUNT(*) AS total
        FROM bayar b
        JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
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
        SELECT b.id, b.unit_id, s.NO_INDUK, s.NO_induk_diknas, s.NAMA,
               COALESCE(NULLIF(b.kelas_rombel_snapshot,''),NULLIF(b.KELAS,''),s.KELAS) AS KELAS,
               b.BULAN, b.TAHUN,
               b.U_PSB, b.U_SPP, b.U_KOMITE,
               b.sistem_pembayaran, b.total_jumlah, b.TGL_BYR
        FROM bayar b
        JOIN siswa s ON s.NO_INDUK = b.NO_INDUK AND s.unit_id=b.unit_id
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
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, ts.kelas_rombel_snapshot AS KELAS,
                       ts.nominal_tagihan AS tagihan,
                       COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) AS sudah_bayar,
                       GREATEST(ts.nominal_tagihan-COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0),0) AS sisa
                FROM tagihan_spp ts JOIN siswa s ON s.NO_INDUK=ts.no_induk AND s.unit_id=ts.unit_id
                LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id
                LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
                WHERE s.is_active=1 AND ts.status='open' AND ts.tahun=? AND ts.bulan=? AND ts.nominal_tagihan>0 $studentSearchSql
                GROUP BY ts.id,s.NO_induk_diknas,s.NAMA,ts.kelas_rombel_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        report_bind($stmtUnpaid, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$filter_tahun, $periodMonthCode], $studentSearchParams));
    } elseif ($report_type === 'belum_komite') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, t.kelas_rombel_snapshot AS KELAS,
                       t.nominal_tagihan AS tagihan,
                       COALESCE(SUM(d.nominal), 0) AS sudah_bayar,
                       GREATEST(t.nominal_tagihan - COALESCE(SUM(d.nominal), 0), 0) AS sisa
                FROM tagihan_komite t
                JOIN siswa s ON s.NO_INDUK = t.no_induk AND s.unit_id=t.unit_id
                LEFT JOIN bayar_komite d ON d.tagihan_komite_id = t.id
                WHERE s.is_active = 1
                  AND t.status = 'open'
                  AND t.tahun = ? AND t.bulan = ?
                  AND t.nominal_tagihan > 0
                  $studentSearchSql
                GROUP BY t.id,s.NO_induk_diknas,s.NAMA,t.kelas_rombel_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        report_bind($stmtUnpaid, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$filter_tahun,$periodMonthCode], $studentSearchParams));
    } elseif ($report_type === 'belum_du') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot AS KELAS,
                       tdu.nominal_tagihan AS tagihan,
                       COALESCE(SUM(bd.jumlah), 0) AS sudah_bayar,
                       GREATEST(tdu.nominal_tagihan - COALESCE(SUM(bd.jumlah), 0), 0) AS sisa
                FROM tagihan_daftar_ulang tdu
                JOIN siswa s ON s.NO_INDUK = tdu.no_induk AND s.unit_id=tdu.unit_id
                JOIN siswa_tahun_ajaran sta ON sta.id=tdu.penempatan_id
                LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
                WHERE s.is_active = 1 AND tdu.status='open' AND tdu.tahun_ajaran_snapshot = ? AND tdu.nominal_tagihan > 0 $studentSearchSql
                GROUP BY tdu.id,s.NO_induk_diknas,s.NAMA,sta.kelas_rombel_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        report_bind($stmtUnpaid, 's' . str_repeat('s', count($studentSearchParams)), array_merge([$academicYear], $studentSearchParams));
    } else {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA,
                       t.kelas_rombel_snapshot AS KELAS, t.nama_snapshot AS komponen,
                       t.nominal_tagihan AS tagihan,
                       COALESCE(SUM(d.nominal_snapshot), 0) AS sudah_bayar,
                       GREATEST(t.nominal_tagihan - COALESCE(SUM(d.nominal_snapshot), 0), 0) AS sisa
                FROM tagihan_biaya_lain t
                JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id
                LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id
                WHERE s.is_active = 1 AND t.status='open' $studentSearchSql
                GROUP BY t.id,s.NO_induk_diknas,s.NAMA,t.kelas_rombel_snapshot,t.nama_snapshot,t.nominal_tagihan
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

$financeTab = is_string($_GET['panel'] ?? null) && in_array($_GET['panel'], ['ringkasan','komponen','transaksi'], true) ? $_GET['panel'] : 'ringkasan';
$financeTrend = finance_payment_trend($koneksi, $filter_tanggal_akhir, $studentSearchSql, $studentSearchParams);
$financeTrendMax = max(1, ...array_values($financeTrend));
$financeReportLabel = $generalMultiple
    ? (filter_is_all($generalChoices) ? 'Semua jenis laporan' : implode(', ', array_map(static fn($key) => $reportTypes[$key], $generalChoices)))
    : $reportTypes[$report_type];

$laporanPaginationQuery = pagination_query([
    'unit' => $reportUnitId===0?'all':'active',
    'bulan' => $filter_bulan,
    'tahun' => $filter_tahun,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'q' => $filter_q,
    'jenis_laporan' => $report_type,
    'urut' => $sort,
    'per_page' => $perPage,
]);
$laporanPaginationQuery=filter_query($laporanPaginationQuery);
$laporanPaginationQuery['panel']=$financeTab;
$exportQuery = filter_build_query([
    'jenis_laporan' => $generalMultiple?$generalChoices:$report_type,
    'urut' => $sort,
    'unit' => $reportUnitId===0?'all':'active',
    'bulan' => $filter_bulan,
    'tahun' => $filter_tahun,
    'tanggal_awal' => $filter_tanggal_awal,
    'tanggal_akhir' => $filter_tanggal_akhir,
    'q' => $filter_q,
    'student_id' => max(0,(int)($_GET['student_id']??0)),
]);
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Laporan Keuangan | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" />
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>" />
  <link rel="stylesheet" href="../assets/css/finance_workspace.css?v=<?= filemtime(__DIR__.'/../assets/css/finance_workspace.css') ?>">
  <link rel="stylesheet" href="../assets/css/payment_details.css?v=<?= filemtime(__DIR__.'/../assets/css/payment_details.css') ?>">
</head>
<body class="report-general-page">
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

    <div class="page-content finance-workspace">
      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" id="flash-msg" style="margin-bottom:16px;">
        <?= report_e($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <section class="main-card class-recap-card recap-report-shell report-general-shell" style="margin-bottom:16px;">
        <div class="recap-report-header">
          <div class="report-general-heading finance-hero">
            <div class="recap-report-copy">
              <span class="finance-breadcrumb">SistemSPP <span aria-hidden="true">&rsaquo;</span> Laporan Keuangan</span>
              <h1>Rekap Laporan Keuangan</h1>
              <p><?= report_e($financeReportLabel) ?> untuk periode <?= report_e($periodLabel) ?>.</p>
            </div>
            <svg class="finance-hero-art" viewBox="0 0 540 110" fill="none" aria-hidden="true"><path d="M0 110Q80 45 150 72T280 35T400 65T540 15V110Z" fill="currentColor" opacity=".07"/><path d="M30 85Q100 45 155 62T280 28T410 60T520 20" stroke="currentColor" opacity=".35"/><g fill="currentColor" opacity=".12"><path d="M60 110V82h16v28M110 110V60h16v50M160 110V75h16v35M210 110V46h16v64M260 110V20h16v90M310 110V52h16v58M360 110V66h16v44M410 110V42h16v68M460 110V27h16v83M510 110V9h16v101"/></g></svg>
            <a class="report-general-catalog-link finance-catalog" href="global.php?unit=<?= $reportUnitId===0?'all':'active' ?>">
              <strong><?= finance_icon('chart') ?>Laporan Global <span aria-hidden="true">&rarr;</span></strong>
              <span class="finance-trend-copy">Pembayaran 7 hari hingga <?= report_e(spp_date_label($filter_tanggal_akhir)) ?></span>
              <span class="finance-spark" role="img" aria-label="<?= report_e(implode('; ',array_map(static fn($day,$n)=>spp_date_label($day).': '.$n.' transaksi',array_keys($financeTrend),array_values($financeTrend)))) ?>">
                <?php foreach($financeTrend as $day=>$n): ?><span style="--bar:<?= $n>0?max(8,round($n/$financeTrendMax*100)):2 ?>%" title="<?= report_e(spp_date_label($day).': '.$n.' transaksi') ?>"></span><?php endforeach; ?>
              </span>
            </a>
          </div>
        <form method="GET" class="recap-header-controls report-filter-card report-general-filter report-filter-grid<?= unit_is_super() ? ' has-scope' : '' ?>">
          <input type="hidden" name="student_id" data-student-identity="1" value="<?= max(0,(int)($_GET['student_id']??0)) ?>">
          <input type="hidden" name="panel" value="<?= report_e($financeTab) ?>">
          <div class="field-row finance-scope-field"><span class="field-label">Cakupan rekap</span>
            <?php if(unit_is_super() && unit_active_id()!==0): ?>
            <select name="unit" class="field-input field-select" onchange="unitSwitchReportScope(this)" aria-label="Cakupan rekap"><option value="active" <?= $reportUnitId!==0?'selected':'' ?>>Unit aktif: <?= report_e(unit_label(unit_active_id())) ?></option><option value="all" <?= $reportUnitId===0?'selected':'' ?>>Semua Unit</option></select>
            <?php else: ?><div class="finance-scope-value"><?= finance_icon('unit') ?><strong><?= report_e(unit_label($reportUnitId)) ?></strong></div><?php if($reportUnitId===0): ?><input type="hidden" name="unit" value="all"><?php endif; ?><?php endif; ?>
          </div>
          <div class="field-row report-date-range-field">
            <label class="field-label">Tanggal transaksi</label>
            <div class="report-date-range-control report-date-range-picker" data-range-picker data-empty-label="<?= report_e($periodLabel) ?>">
              <input type="hidden" name="tanggal_awal" value="<?= report_e($filter_tanggal_awal) ?>">
              <input type="hidden" name="tanggal_akhir" value="<?= report_e($filter_tanggal_akhir) ?>">
              <button type="button" class="report-date-range-button" aria-expanded="false">
                <span class="report-date-range-icon"><?= finance_icon('calendar') ?></span>
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
            <select class="field-input field-select" name="jenis_laporan" data-filter-multiple>
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
              <input type="text" id="report-siswa-search" data-student-fit-viewport="1" data-student-search data-student-list="report-siswa-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="report-student-query" value="<?= report_e($studentSearchDisplay) ?>" placeholder="Ketik nama, NIS, atau NIS Diknas..." autocomplete="off">
              <input type="hidden" id="report-student-query" name="q" value="<?= report_e($filter_q) ?>">
            </div>
            <datalist id="report-siswa-list">
              <?php foreach ($studentOptions as $studentOption): ?>
              <?php $studentClassLabel = class_label(['tingkat' => $studentOption['master_tingkat'] ?: $studentOption['KELAS'], 'kode_rombel' => $studentOption['kode_rombel'] ?? 'BELUM', 'is_placeholder' => $studentOption['is_placeholder'] ?? 1]); ?>
              <option value="<?= report_e($studentOption['NAMA']) ?>"
                data-student-id="<?= (int)($studentOption['student_id']??0) ?>" data-unit-id="<?= (int)($studentOption['unit_id']??0) ?>" data-nis="<?= report_e($studentOption['NO_INDUK']) ?>"
                data-diknas="<?= report_e((string)($studentOption['NO_induk_diknas'] ?? '')) ?>"
                data-nama="<?= report_e($studentOption['NAMA']) ?>"
                data-kelas="<?= report_e($studentClassLabel) ?>">
                <?= report_e($studentOption['NAMA']) ?> (<?= report_e($studentClassLabel) ?>)
              </option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="report-general-actions">
            <div class="report-filter-actions">
              <button type="submit" class="btn btn-primary"><?= finance_icon('search') ?>Tampilkan Rekap</button>
              <a href="index.php?unit=<?= $reportUnitId===0?'all':'active' ?>" class="btn btn-ghost"><?= finance_icon('reload') ?>Reset</a>
            </div>
            <div class="report-export-actions">
              <a href="export_excel.php?<?= report_e($exportQuery) ?>" class="btn btn-success" target="_blank" rel="noopener"><?= finance_icon('document') ?>Export Excel</a>
              <a href="export_pdf.php?<?= report_e($exportQuery) ?>&amp;output=preview" class="btn btn-warning" target="_blank" rel="noopener"><?= finance_icon('document') ?>Export PDF</a>
            </div>
          </div>
        </form>
        </div>
      </section>

      <div class="finance-stats">
        <article class="finance-stat is-payment"><span class="finance-stat-icon"><?= finance_icon('card') ?></span><div><strong><?= report_money($bayar_recap['total']??0) ?></strong><span>Total Pembayaran</span><small><?= number_format((int)($bayar_recap['jml_tx']??0)) ?> transaksi</small></div></article>
        <article class="finance-stat is-in"><span class="finance-stat-icon"><?= finance_icon('in') ?></span><div><strong><?= report_money($tab_masuk) ?></strong><span>Tabungan Masuk</span><small><?= number_format((int)$savingsIncoming['jml_tx']) ?> transaksi</small></div></article>
        <article class="finance-stat is-out"><span class="finance-stat-icon"><?= finance_icon('out') ?></span><div><strong><?= report_money($tab_keluar) ?></strong><span>Tabungan Keluar</span><small><?= number_format((int)$savingsOutgoing['jml_tx']) ?> transaksi</small></div></article>
        <article class="finance-stat is-count"><span class="finance-stat-icon"><?= finance_icon('clock') ?></span><div><strong><?= number_format((int)($bayar_recap['jml_tx']??0)) ?></strong><span>Jumlah Transaksi Pembayaran</span><small><?= report_e($periodLabel) ?></small></div></article>
      </div>
      <p class="finance-summary-note">Ringkasan seluruh pembayaran dan mutasi tabungan pada periode, siswa, dan cakupan unit yang dipilih. Jenis laporan mengatur tabel hasil di bawah.</p>
      <nav class="finance-tabs" aria-label="Bagian laporan">
        <?php foreach(['ringkasan'=>['Ringkasan','pie'],'komponen'=>['Komponen Pembayaran','list'],'transaksi'=>['Daftar Transaksi','document']] as $key=>[$label,$icon]): ?>
        <a href="index.php?<?= report_e(filter_build_query(array_replace($laporanPaginationQuery,['panel'=>$key,'page'=>$page]))) ?>" data-finance-tab="<?= $key ?>" class="<?= $financeTab===$key?'is-active':'' ?>" <?= $financeTab===$key?'aria-current="page"':'' ?>><?= finance_icon($icon) ?><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="finance-results" data-finance-panel="<?= report_e($financeTab) ?>">
      <div class="main-card finance-components" style="margin-bottom:16px;">

        <div class="card-header">
          <div><h3 class="card-title">Rekap Komponen Pembayaran</h3><small class="finance-table-note"><?= report_e($periodLabel) ?></small></div>
        </div>
        <div class="table-container">
          <table class="payment-table">
            <thead><tr><th>Komponen</th><th>Total (Rp)</th></tr></thead>
            <tbody>
              <?php
              $komponen_map = [
                'Uang PSB' => $bayar_recap['psb'],
                'Uang SPP' => $bayar_recap['spp'],
                'Uang Komite' => $bayar_recap['komite'],
                'Daftar Ulang' => $total_du_periode,
                'Potongan SPP' => -(float)$bayar_recap['potongan_spp'],
              ];
              $shownComponents = 0;
              foreach ($komponen_map as $nama => $val):
                if (abs((float)$val) <= 0.001) continue;
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

      <div class="main-card finance-transactions">
        <div class="card-header laporan-detail-header">
          <div class="laporan-detail-heading">
            <h3 class="card-title"><?= $generalMultiple ? 'Hasil Laporan' : ($isUnpaidReport ? report_e($reportTypes[$report_type]) : 'Daftar Transaksi') ?></h3>
            <span class="badge-count"><?= number_format($totalDetailRows) ?> <?= $isUnpaidReport ? 'siswa/tagihan' : 'transaksi' ?></span>
          </div>
          <small class="finance-table-note"><?= report_e($periodLabel) ?> &middot; Hasil mengikuti jenis laporan yang dipilih.</small>
          <?php if (!$generalMultiple && !$isUnpaidReport && !empty($bayar_detail)): ?>
          <button type="submit" form="print-selected-form" class="btn btn-warning btn-print-selected" id="btn-print-selected" disabled><?= finance_icon('print') ?><span>Cetak Dipilih</span></button>
          <?php endif; ?>
        </div>

        <?php if($generalMultiple): $sectionStart=$offset;foreach($generalSections as $sectionIndex=>$section){$rows=array_values(array_filter($generalPageRows,static fn($r)=>$r['_section']===$sectionIndex));if(!$rows&&$section['rows'])continue;$section['rows']=$rows;echo general_section_html($section,$sectionStart);$sectionStart+=count($rows);}render_pagination('index.php',$laporanPaginationQuery,$page,$totalPages,$totalDetailRows,$perPage,'baris'); ?>
        <?php elseif ($isUnpaidReport): ?>
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
        <form method="GET" action="export_pdf.php" id="print-selected-form" target="_blank" rel="noopener"><input type="hidden" name="unit" value="<?= $reportUnitId===0?'all':'active' ?>">
          <input type="hidden" name="output" value="preview">
          <input type="hidden" name="bulan" value="<?= report_e($filter_bulan) ?>">
          <input type="hidden" name="tahun" value="<?= report_e($filter_tahun) ?>">
          <input type="hidden" name="tanggal_awal" value="<?= report_e($filter_tanggal_awal) ?>">
          <input type="hidden" name="tanggal_akhir" value="<?= report_e($filter_tanggal_akhir) ?>">
          <input type="hidden" name="q" value="<?= report_e($filter_q) ?>">
          <input type="hidden" name="mode" value="selected">
          <input type="hidden" name="student_id" value="<?= max(0,(int)($_GET['student_id']??0)) ?>">
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
                <?php else: $receiptGroups=payment_activity_for_payments($koneksi,array_column($bayar_detail,'id')); foreach ($bayar_detail as $i => $b): $receiptCaps=payment_capabilities($koneksi,$b,$receiptGroups[(int)$b['id']]??[],false,[]); ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td class="select-col"><input type="checkbox" class="select-print-check row-print-check" name="ids[]" <?= !$receiptCaps['can_print']?'disabled':'' ?> value="<?= (int)$b['id'] ?>" aria-label="Pilih transaksi <?= report_e($b['NAMA']) ?>"></td>
                  <td><?= $offset + $i + 1 ?></td>
                  <td><span class="badge-nis"><?= report_e($b['NO_INDUK']) ?></span><?php if($reportUnitId===0): ?><small class="report-secondary-id"><?= report_e(unit_label((int)$b['unit_id'])) ?></small><?php endif; ?><?php if (!empty($b['NO_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= report_e($b['NO_induk_diknas']) ?></small><?php endif; ?></td>
                  <td><?= report_e($b['NAMA']) ?></td>
                  <td class="kelas-col"><span class="kelas-badge">Kelas <?= report_e($b['KELAS']) ?></span></td>
                  <td><?= report_e($b['BULAN']) ?> <?= report_e($b['TAHUN']) ?></td>
                  <td><?= report_e($b['sistem_pembayaran'] ?? 'VA') ?></td>
                  <td class="nominal"><?= report_money($b['total_jumlah']) ?></td>
                  <td><?= spp_date_label($b['TGL_BYR'],true) ?></td>
                  <td class="aksi-col"><?php if($receiptCaps['can_print']): ?><a class="btn-tbl btn-tbl-print" href="cetak_struk.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"><?= finance_icon('print') ?>Cetak</a><?php else: ?><span class="report-secondary-id">Terkunci</span><?php endif; ?><details class="finance-row-menu"><summary aria-label="Lihat detail transaksi <?= (int)$b['id'] ?>">&middot;&middot;&middot;</summary><button type="button" class="open-payment-activity" hidden data-id="<?= (int)$b['id'] ?>" data-unit="<?= (int)$b['unit_id'] ?>">Riwayat Aktivitas</button><noscript><a href="../pembayaran/lihat.php?search=<?= urlencode($b['NO_INDUK']) ?>">Lihat riwayat siswa</a></noscript></details></td>
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
    </div>
  </main>
</div>

<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<dialog class="payment-activity-dialog" id="payment-activity-dialog" data-endpoint="../pembayaran/aktivitas.php" aria-labelledby="payment-activity-title"><header class="payment-activity-dialog-header"><div><h3 id="payment-activity-title">Riwayat Aktivitas</h3><p data-activity-subtitle></p></div><button type="button" class="btn btn-ghost btn-sm" data-close-activity>Tutup</button></header><div class="payment-activity-content" aria-live="polite"></div></dialog>
<script src="../assets/js/payment_activity.js?v=<?= filemtime(__DIR__.'/../assets/js/payment_activity.js') ?>"></script>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  autoHideFlash();
  document.querySelectorAll('.finance-row-menu .open-payment-activity').forEach(button => button.hidden = false);
  const results = document.querySelector('.finance-results');
  function selectPanel(panel) {
    results.dataset.financePanel = panel;
    document.querySelectorAll('[data-finance-tab]').forEach(link => {
      const active = link.dataset.financeTab === panel;
      link.classList.toggle('is-active', active);
      if(active) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current');
    });
    const field = document.querySelector('input[name="panel"]'); if(field) field.value = panel;
    document.querySelectorAll('.finance-transactions .du-pagination-footer a').forEach(link => { const url = new URL(link.href); url.searchParams.set('panel',panel); link.href=url.href; });
  }
  document.querySelectorAll('[data-finance-tab]').forEach(link => link.addEventListener('click', event => {
    if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey) return;
    event.preventDefault(); selectPanel(link.dataset.financeTab);
    const url = new URL(location.href); url.searchParams.set('panel',link.dataset.financeTab); history.pushState(null,'',url);
  }));
  addEventListener('popstate',()=>{const panel=new URL(location.href).searchParams.get('panel');selectPanel(['ringkasan','komponen','transaksi'].includes(panel)?panel:'ringkasan');});

  const form = document.getElementById('print-selected-form');
  const checkAll = document.getElementById('check-all-print');
  const rowChecks = Array.from(document.querySelectorAll('.row-print-check:not(:disabled)'));
  const printButton = document.getElementById('btn-print-selected');

  function refreshPrintSelection() {
    const selectedCount = rowChecks.filter(check => check.checked).length;
    if (printButton) {
      printButton.disabled = selectedCount === 0;
      printButton.querySelector('span').textContent = selectedCount > 0 ? 'Cetak Dipilih (' + selectedCount + ')' : 'Cetak Dipilih';
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
