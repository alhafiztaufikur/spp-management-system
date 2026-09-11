<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/kelas.php';
requireRole(['admin', 'kasir']);

function du_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function du_money($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function du_full_date($value): array {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) return ['date' => '-', 'time' => '-'];
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return [
        'date' => $days[(int)date('w', $timestamp)] . ', ' . date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp),
        'time' => date('H:i', $timestamp) . ' WIB',
    ];
}

function du_history_page_url(array $query, int $page): string {
    $query['page'] = max(1, $page);
    return 'riwayat_daftar_ulang.php?' . http_build_query($query);
}

$search = trim((string)($_GET['q'] ?? ''));
$filterClass = trim((string)($_GET['kelas'] ?? ''));
$filterYear = trim((string)($_GET['tahun_ajaran'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$allowedPageSizes = [10, 25, 50, 100];
$requestedPage = (int)($_GET['page'] ?? 1);
$requestedPageSize = (int)($_GET['per_page'] ?? 10);
$perPage = in_array($requestedPageSize, $allowedPageSizes, true) ? $requestedPageSize : 10;
$page = max(1, $requestedPage);
$allowedClasses = ['1', '2', '3', '4', '5', '6'];
$classRows = class_all($koneksi, true);
$validClassFilters = [''];
foreach ($allowedClasses as $class) $validClassFilters[] = 'tingkat:' . $class;
foreach ($classRows as $classRow) $validClassFilters[] = 'rombel:' . (int)$classRow['id'];
$allowedStatuses = ['', 'lunas', 'cicilan'];
if (in_array($filterClass, $allowedClasses, true)) $filterClass = 'tingkat:' . $filterClass;
if (!in_array($filterClass, $validClassFilters, true)) $filterClass = '';
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = '';
if ($filterYear !== '' && !preg_match('/^\d{4}\/\d{4}$/', $filterYear)) $filterYear = '';

$academicYears = [];
$yearResult = $koneksi->query("SELECT label FROM tahun_ajaran WHERE status IN ('published','closed') ORDER BY label DESC");
while ($year = $yearResult->fetch_row()) $academicYears[] = $year[0];

$studentOptions = $koneksi->query("
    SELECT DISTINCT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS,
           s.master_kelas_id, mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM tagihan_daftar_ulang tdu
    JOIN siswa s ON s.NO_INDUK = tdu.no_induk
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE tdu.status = 'open'
    ORDER BY s.NAMA
")->fetch_all(MYSQLI_ASSOC);
$studentSearchDisplay = $search;
foreach ($studentOptions as $studentOption) {
    if ($search !== '' && ($search === $studentOption['NO_INDUK'] || $search === (string)($studentOption['NO_induk_diknas'] ?? ''))) {
        $studentSearchDisplay = $studentOption['NAMA'];
        break;
    }
}

$where = ["tdu.status = 'open'"];
$params = [];
$types = '';
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(tdu.no_induk LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
if (str_starts_with($filterClass, 'tingkat:')) {
    $where[] = 'COALESCE(mk.tingkat, s.KELAS, tdu.kelas_snapshot) = ?';
    $params[] = substr($filterClass, 8); $types .= 's';
} elseif (str_starts_with($filterClass, 'rombel:')) {
    $where[] = 's.master_kelas_id = ?';
    $params[] = (int)substr($filterClass, 7); $types .= 'i';
}
if ($filterYear !== '') {
    $where[] = 'ta.label = ?';
    $params[] = $filterYear; $types .= 's';
}

$aggregateSql = "
    SELECT tdu.id AS tagihan_id, tdu.no_induk, tdu.kelas_snapshot AS kelas,
           ta.label AS th_ajaran, s.NAMA AS nama, s.NO_induk_diknas, s.KELAS AS kelas_siswa,
           tdu.nominal_tagihan AS master_total,
           COALESCE(SUM(bd.jumlah), 0) AS paid,
           GREATEST(0, tdu.nominal_tagihan - COALESCE(SUM(bd.jumlah), 0)) AS remaining,
           CASE WHEN tdu.nominal_tagihan - COALESCE(SUM(bd.jumlah), 0) <= 0.001
                THEN 'lunas' ELSE 'cicilan' END AS payment_status
    FROM tagihan_daftar_ulang tdu
    JOIN tahun_ajaran ta ON ta.id = tdu.tahun_ajaran_id
    JOIN siswa s ON s.NO_INDUK = tdu.no_induk
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY tdu.id, tdu.no_induk, tdu.kelas_snapshot, ta.label, s.NAMA, s.NO_induk_diknas,
             s.KELAS, tdu.nominal_tagihan
";
$havingSql = '';
if ($filterStatus === 'lunas') $havingSql = ' HAVING remaining <= 0.001';
if ($filterStatus === 'cicilan') $havingSql = ' HAVING remaining > 0.001';

$summarySql = "SELECT COUNT(*) AS students,
        COALESCE(SUM(master_total), 0) AS bill,
        COALESCE(SUM(paid), 0) AS paid,
        COALESCE(SUM(remaining), 0) AS remaining
    FROM (" . $aggregateSql . $havingSql . ") filtered_bills";
$stmt = $koneksi->prepare($summarySql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: ['students'=>0, 'bill'=>0, 'paid'=>0, 'remaining'=>0];
$stmt->close();
$summary['students'] = (int)$summary['students'];
$summary['bill'] = (float)$summary['bill'];
$summary['paid'] = (float)$summary['paid'];
$summary['remaining'] = (float)$summary['remaining'];

$totalPages = max(1, (int)ceil($summary['students'] / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$pageSql = $aggregateSql . $havingSql . "
    ORDER BY ta.label DESC, CAST(tdu.kelas_snapshot AS UNSIGNED), s.NAMA, tdu.id
    LIMIT ? OFFSET ?";
$pageParams = $params;
$pageParams[] = $perPage;
$pageParams[] = $offset;
$stmt = $koneksi->prepare($pageSql);
if (!$stmt) throw new RuntimeException('Query halaman Riwayat Daftar Ulang tidak dapat disiapkan: ' . $koneksi->error);
$pageTypes = $types . 'ii';
$stmt->bind_param($pageTypes, ...$pageParams);
$stmt->execute();
$pageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$visibleGroups = [];
foreach ($pageRows as $row) {
    $group = [
        'tagihan_id' => (int)$row['tagihan_id'],
        'no_induk' => $row['no_induk'], 'no_induk_diknas' => $row['NO_induk_diknas'], 'nama' => $row['nama'],
        'kelas' => $row['kelas'], 'kelas_siswa' => $row['kelas_siswa'],
        'th_ajaran' => $row['th_ajaran'], 'total' => (float)$row['master_total'],
        'paid' => (float)$row['paid'], 'remaining' => (float)$row['remaining'],
        'status' => $row['payment_status'], 'transactions' => [],
    ];
    $visibleGroups[] = $group;
}

if ($visibleGroups) {
    $groupIndexes = [];
    $tagihanIds = [];
    foreach ($visibleGroups as $index => $group) {
        $groupIndexes[$group['tagihan_id']] = $index;
        $tagihanIds[] = $group['tagihan_id'];
    }
    $placeholders = implode(',', array_fill(0, count($tagihanIds), '?'));
    $detailSql = "SELECT bd.id AS detail_id, bd.tagihan_daftar_ulang_id AS tagihan_id,
            bd.bayar_id, bd.jumlah, b.TGL_BYR, b.BULAN, b.TAHUN,
            b.sistem_pembayaran, b.payment_link_version
        FROM bayar_du bd
        LEFT JOIN bayar b ON b.id = bd.bayar_id
        WHERE bd.tagihan_daftar_ulang_id IN ($placeholders)
        ORDER BY bd.tagihan_daftar_ulang_id, b.TGL_BYR DESC, b.id DESC, bd.id DESC";
    $stmt = $koneksi->prepare($detailSql);
    $detailTypes = str_repeat('i', count($tagihanIds));
    $stmt->bind_param($detailTypes, ...$tagihanIds);
    $stmt->execute();
    $detailRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($detailRows as $transaction) {
        $tagihanId = (int)$transaction['tagihan_id'];
        if (!isset($groupIndexes[$tagihanId])) continue;
        $transaction['full_date'] = du_full_date($transaction['TGL_BYR']);
        $visibleGroups[$groupIndexes[$tagihanId]]['transactions'][] = $transaction;
    }
}

$firstShown = $summary['students'] > 0 ? $offset + 1 : 0;
$lastShown = $summary['students'] > 0 ? min($offset + count($visibleGroups), $summary['students']) : 0;
$pageWindowStart = max(1, $page - 2);
$pageWindowEnd = min($totalPages, $pageWindowStart + 4);
$pageWindowStart = max(1, $pageWindowEnd - 4);
$paginationQuery = array_filter([
    'q'=>$search, 'kelas'=>$filterClass, 'tahun_ajaran'=>$filterYear,
    'status'=>$filterStatus, 'per_page'=>$perPage,
], static fn($value) => $value !== '');

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Daftar Ulang | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <meta name="description" content="Rekap pembayaran dan cicilan daftar ulang siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=9.6" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div class="topbar-title"><h2>Riwayat Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Pembayaran / Daftar Ulang</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?><div class="alert alert-<?= du_e($flash['type'] ?? 'error') ?>" id="flash-msg"><?= du_e($flash['msg'] ?? '') ?></div><?php endif; ?>

      <section class="main-card class-recap-card recap-report-shell history-recap-shell du-history-card">
        <div class="recap-report-header">
          <div class="recap-report-copy">
            <span class="recap-class-overline">Daftar Ulang</span>
            <h1>Riwayat Daftar Ulang</h1>
            <p><?= number_format($summary['students']) ?> siswa/periode cocok dengan filter saat ini.</p>
          </div>

        <form method="GET" class="recap-header-controls history-recap-filter filter-bar du-history-filter">
          <span class="recap-filter-label">Filter Riwayat</span>
          <div class="field-row full-span">
            <label class="field-label" for="du-history-student">Cari Siswa (Nama / NIS / NIS Diknas)</label>
            <div class="search-box">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" id="du-history-student" data-student-search data-student-list="du-history-student-list" data-student-select-callback="selectReportStudentSearchOption" data-student-query-target="du-history-student-query" value="<?= du_e($studentSearchDisplay) ?>" placeholder="Ketik nama, NIS, atau NIS Diknas..." autocomplete="off" />
              <input type="hidden" id="du-history-student-query" name="q" value="<?= du_e($search) ?>" />
            </div>
            <datalist id="du-history-student-list">
              <?php foreach ($studentOptions as $studentOption): ?>
              <?php $studentClassLabel = class_label(['tingkat' => $studentOption['master_tingkat'] ?: $studentOption['KELAS'], 'kode_rombel' => $studentOption['kode_rombel'] ?? 'BELUM', 'is_placeholder' => $studentOption['is_placeholder'] ?? 1]); ?>
              <option value="<?= du_e($studentOption['NAMA']) ?>"
                data-nis="<?= du_e($studentOption['NO_INDUK']) ?>"
                data-diknas="<?= du_e((string)($studentOption['NO_induk_diknas'] ?? '')) ?>"
                data-nama="<?= du_e($studentOption['NAMA']) ?>"
                data-kelas="<?= du_e($studentClassLabel) ?>">
                <?= du_e($studentOption['NAMA']) ?> (<?= du_e($studentClassLabel) ?>)
              </option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <select class="field-input field-select filter-sel" name="kelas"><option value="">Semua Kelas</option><?php foreach ($allowedClasses as $class): ?><option value="tingkat:<?= $class ?>" <?= $filterClass === 'tingkat:'.$class ? 'selected' : '' ?>>Semua Kelas <?= $class ?></option><?php endforeach; ?><?php foreach ($classRows as $classRow): ?><option value="rombel:<?= (int)$classRow['id'] ?>" <?= $filterClass === 'rombel:'.((int)$classRow['id']) ? 'selected' : '' ?>><?= du_e(class_label($classRow)) ?></option><?php endforeach; ?></select>
          <select class="field-input field-select filter-sel" name="tahun_ajaran"><option value="">Semua Tahun Ajaran</option><?php foreach ($academicYears as $year): ?><option value="<?= du_e($year) ?>" <?= $filterYear === $year ? 'selected' : '' ?>><?= du_e($year) ?></option><?php endforeach; ?></select>
          <select class="field-input field-select filter-sel" name="status"><option value="">Semua Status</option><option value="cicilan" <?= $filterStatus === 'cicilan' ? 'selected' : '' ?>>Belum Lunas</option><option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Lunas</option></select>
          <select class="field-input field-select filter-sel du-page-size" name="per_page" aria-label="Jumlah data per halaman"><?php foreach ($allowedPageSizes as $pageSize): ?><option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?> / halaman</option><?php endforeach; ?></select>
          <div class="history-recap-actions"><button class="btn btn-primary" type="submit">Tampilkan Rekap</button><a class="btn btn-ghost" href="riwayat_daftar_ulang.php">Reset</a><a href="form.php" class="btn btn-primary">Input Pembayaran</a></div>
        </form>
        </div>

        <div class="recap-period-strip history-recap-strip">
          <div><strong>Rekap Tagihan Daftar Ulang</strong><span>Menampilkan <?= number_format($firstShown) ?>–<?= number_format($lastShown) ?> dari <?= number_format($summary['students']) ?> siswa/periode.</span></div>
          <span><?= number_format($summary['students']) ?> data</span>
        </div>

        <div class="history-summary-grid du-history-summary">
          <div><span>Siswa / Periode</span><strong><?= number_format($summary['students']) ?></strong></div>
          <div><span>Total Tagihan</span><strong><?= du_money($summary['bill']) ?></strong></div>
          <div><span>Sudah Dibayar</span><strong><?= du_money($summary['paid']) ?></strong></div>
          <div><span>Sisa Cicilan</span><strong><?= du_money($summary['remaining']) ?></strong></div>
        </div>

        <div class="table-container">
          <table class="payment-table responsive-table du-history-table">
            <thead><tr><th>No</th><th>Siswa</th><th>Kelas / Tahun Ajaran</th><th>Tagihan</th><th>Terbayar</th><th>Sisa</th><th>Status</th><th>Rincian Pembayaran</th></tr></thead>
            <tbody>
            <?php if (!$visibleGroups): ?><tr><td colspan="8" class="text-center recap-empty">Belum ada tagihan Daftar Ulang yang diterbitkan untuk filter ini.</td></tr>
            <?php else: foreach ($visibleGroups as $index => $group): ?>
              <tr>
                <td data-label="No"><?= $offset + $index + 1 ?></td>
                <td data-label="Siswa"><strong><?= du_e($group['nama']) ?></strong><br><span class="badge-nis"><?= du_e($group['no_induk']) ?></span><?php if (!empty($group['no_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= du_e($group['no_induk_diknas']) ?></small><?php endif; ?></td>
                <td data-label="Kelas / Tahun"><div class="du-class-year-cell"><span class="kelas-badge">Kelas <?= du_e($group['kelas']) ?></span><small class="du-history-nis"><?= du_e($group['th_ajaran']) ?></small></div></td>
                <td data-label="Tagihan" class="nominal"><?= du_money($group['total']) ?></td>
                <td data-label="Terbayar" class="nominal"><?= du_money($group['paid']) ?></td>
                <td data-label="Sisa" class="nominal"><?= du_money($group['remaining']) ?></td>
                <td data-label="Status"><span class="recap-status <?= $group['status'] === 'lunas' ? 'is-paid' : 'is-partial' ?>"><?= $group['status'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span></td>
                <td data-label="Rincian">
                  <div class="du-payment-details">
                    <div class="du-payment-summary">
                      <strong><?= count($group['transactions']) ?> pembayaran</strong>
                      <span><?= $group['transactions'] ? 'Rincian transaksi' : 'Belum ada cicilan' ?></span>
                    </div>
                    <div class="du-payment-timeline">
                    <?php foreach ($group['transactions'] as $transaction): ?>
                      <div class="du-payment-entry">
                        <div><strong><?= du_money($transaction['jumlah']) ?></strong><span><?= du_e($transaction['full_date']['date']) ?> · <?= du_e($transaction['full_date']['time']) ?></span></div>
                        <div class="du-payment-actions"><a class="btn-tbl btn-tbl-print" href="../laporan/cetak_struk.php?id=<?= (int)$transaction['bayar_id'] ?>" target="_blank" rel="noopener">Cetak</a><?php if ((int)$transaction['payment_link_version'] === 1): ?><a class="btn-tbl btn-tbl-edit" href="edit.php?id=<?= (int)$transaction['bayar_id'] ?>">Edit</a><?php endif; ?></div>
                      </div>
                    <?php endforeach; ?>
                    </div>
                    <?php if (!$group['transactions']): ?><div class="du-payment-empty">Pembayaran daftar ulang belum tercatat.</div><?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($summary['students'] > 0): ?>
        <div class="du-pagination-footer">
          <p class="du-pagination-info">Menampilkan <strong><?= number_format($firstShown) ?>–<?= number_format($lastShown) ?></strong> dari <strong><?= number_format($summary['students']) ?></strong> siswa/periode</p>
          <?php if ($totalPages > 1): ?>
          <nav class="du-pagination" aria-label="Navigasi halaman Riwayat Daftar Ulang">
            <?php if ($page > 1): ?>
              <a class="du-page-link du-page-desktop-only" href="<?= du_e(du_history_page_url($paginationQuery, 1)) ?>">Awal</a>
              <a class="du-page-link du-page-prev" href="<?= du_e(du_history_page_url($paginationQuery, $page - 1)) ?>" rel="prev">Sebelumnya</a>
            <?php else: ?>
              <span class="du-page-link du-page-desktop-only is-disabled" aria-disabled="true">Awal</span>
              <span class="du-page-link du-page-prev is-disabled" aria-disabled="true">Sebelumnya</span>
            <?php endif; ?>

            <span class="du-page-mobile-label">Halaman <?= $page ?> dari <?= $totalPages ?></span>
            <span class="du-page-numbers">
              <?php for ($pageNumber = $pageWindowStart; $pageNumber <= $pageWindowEnd; $pageNumber++): ?>
                <?php if ($pageNumber === $page): ?><span class="du-page-link is-active" aria-current="page"><?= $pageNumber ?></span>
                <?php else: ?><a class="du-page-link" href="<?= du_e(du_history_page_url($paginationQuery, $pageNumber)) ?>"><?= $pageNumber ?></a><?php endif; ?>
              <?php endfor; ?>
            </span>

            <?php if ($page < $totalPages): ?>
              <a class="du-page-link du-page-next" href="<?= du_e(du_history_page_url($paginationQuery, $page + 1)) ?>" rel="next">Berikutnya</a>
              <a class="du-page-link du-page-desktop-only" href="<?= du_e(du_history_page_url($paginationQuery, $totalPages)) ?>">Akhir</a>
            <?php else: ?>
              <span class="du-page-link du-page-next is-disabled" aria-disabled="true">Berikutnya</span>
              <span class="du-page-link du-page-desktop-only is-disabled" aria-disabled="true">Akhir</span>
            <?php endif; ?>
          </nav>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
  <script src="../assets/js/app.js?v=4.4"></script>
</body>
</html>
