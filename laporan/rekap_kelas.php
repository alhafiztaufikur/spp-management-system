<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

$monthNames = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];

function recap_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function recap_money($value): string {
    $amount = (float)$value;
    return $amount > 0 ? 'Rp ' . number_format($amount, 0, ',', '.') : '—';
}

$classes = array_map('strval', range(1, 6));

$filterClass = trim((string)($_GET['kelas'] ?? ($classes[0] ?? '')));
if ($classes && !in_array($filterClass, $classes, true)) {
    $filterClass = $classes[0];
}
$filterMonth = str_pad((string)(int)($_GET['bulan'] ?? date('m')), 2, '0', STR_PAD_LEFT);
if (!isset($monthNames[$filterMonth])) {
    $filterMonth = date('m');
}
$filterYear = (int)($_GET['tahun'] ?? date('Y'));
if ($filterYear < 2000 || $filterYear > 2100) {
    $filterYear = (int)date('Y');
}
$search = trim((string)($_GET['q'] ?? ''));

$classCountStmt = $koneksi->prepare('SELECT COUNT(*) AS total FROM siswa WHERE is_active = 1 AND KELAS = ?');
$classCountStmt->bind_param('s', $filterClass);
$classCountStmt->execute();
$classStudentTotal = (int)$classCountStmt->get_result()->fetch_assoc()['total'];
$classCountStmt->close();

$monthAliases = array_values(array_unique([
    $filterMonth,
    (string)(int)$filterMonth,
    $monthNames[$filterMonth],
]));
$monthSql = implode(', ', array_map(
    fn($value) => "'" . $koneksi->real_escape_string($value) . "'",
    $monthAliases
));
$yearSql = $koneksi->real_escape_string((string)$filterYear);
$periodSql = "b.TAHUN = '$yearSql' AND b.BULAN IN ($monthSql)";

$sql = "
    SELECT
        s.NO_INDUK,
        s.NO_induk_diknas,
        s.NAMA,
        s.KELAS,
        s.SPP_PERBULAN,
        COALESCE(p.transaksi, 0) AS transaksi,
        COALESCE(p.pangkal, 0) AS pangkal,
        COALESCE(p.bangunan, 0) AS bangunan,
        COALESCE(p.seragam, 0) AS seragam,
        COALESCE(p.kegiatan, 0) AS kegiatan,
        COALESCE(p.spp, 0) AS spp,
        COALESCE(p.komite, 0) AS komite,
        COALESCE(du.daftar_ulang, 0) AS daftar_ulang,
        COALESCE(lain.biaya_lain, 0) AS biaya_lain,
        COALESCE(p.total_bayar, 0) AS total_bayar
    FROM siswa s
    LEFT JOIN (
        SELECT
            b.NO_INDUK,
            COUNT(*) AS transaksi,
            SUM(b.U_PANGKAL) AS pangkal,
            SUM(b.U_BANGUNAN) AS bangunan,
            SUM(b.U_SERAGAM) AS seragam,
            SUM(b.U_KEGIATAN) AS kegiatan,
            SUM(b.U_SPP) AS spp,
            SUM(b.U_KOMITE) AS komite,
            SUM(b.total_jumlah) AS total_bayar
        FROM bayar b
        WHERE $periodSql
        GROUP BY b.NO_INDUK
    ) p ON p.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT bd.no_induk, SUM(bd.jumlah) AS daftar_ulang
        FROM bayar_du bd
        JOIN bayar b ON b.id = bd.bayar_id
        WHERE $periodSql
        GROUP BY bd.no_induk
    ) du ON du.no_induk = s.NO_INDUK
    LEFT JOIN (
        SELECT b.NO_INDUK, SUM(d.nominal_snapshot) AS biaya_lain
        FROM bayar_biaya_lain d
        JOIN bayar b ON b.id = d.bayar_id
        WHERE $periodSql
        GROUP BY b.NO_INDUK
    ) lain ON lain.NO_INDUK = s.NO_INDUK
    WHERE s.is_active = 1 AND s.KELAS = ?
";

$params = [$filterClass];
$types = 's';
if ($search !== '') {
    $sql .= ' AND (s.NAMA LIKE ? OR s.NO_INDUK LIKE ? OR s.NO_induk_diknas LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}
$sql .= ' ORDER BY s.NAMA, s.NO_INDUK';

$stmt = $koneksi->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = [
    'students' => count($rows),
    'paid_spp' => 0.0,
    'total' => 0.0,
    'paid_off' => 0,
];
foreach ($rows as &$row) {
    $sppBill = (float)$row['SPP_PERBULAN'];
    $sppPaid = (float)$row['spp'];
    if ($sppBill <= 0) {
        $row['status_label'] = 'Tanpa Tagihan';
        $row['status_class'] = 'is-neutral';
    } elseif ($sppPaid >= $sppBill) {
        $row['status_label'] = 'Lunas';
        $row['status_class'] = 'is-paid';
        $summary['paid_off']++;
    } elseif ($sppPaid > 0) {
        $row['status_label'] = 'Sebagian';
        $row['status_class'] = 'is-partial';
    } else {
        $row['status_label'] = 'Belum Bayar';
        $row['status_class'] = 'is-unpaid';
    }
    $summary['paid_spp'] += $sppPaid;
    $summary['total'] += (float)$row['total_bayar'];
}
unset($row);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rekap Pembayaran per Kelas | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Rekap pembayaran siswa berdasarkan kelas dan periode." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=5.4" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs recap-no-print">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar recap-no-print">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Rekap Kelas</h2>
          <span class="breadcrumb">SistemSPP / Laporan / Rekap Kelas</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <div class="main-card class-recap-card recap-report-shell">
        <div class="recap-report-header">
          <div class="recap-report-copy">
            <span class="recap-class-overline">Kelas <?= recap_e($filterClass) ?></span>
            <h1>Data Pembayaran &amp; Rekap Kelas</h1>
            <p><?= number_format(count($rows)) ?> siswa tampil dari <?= number_format($classStudentTotal) ?> siswa.</p>
          </div>

          <form method="GET" action="rekap_kelas.php" class="recap-header-controls recap-no-print">
            <span class="recap-filter-label">Filter Rekap</span>
            <div class="recap-filter-fields">
              <label class="recap-filter-field">
                <span>Kelas</span>
                <select name="kelas" required>
                  <?php foreach ($classes as $class): ?>
                  <option value="<?= $class ?>" <?= $filterClass === $class ? 'selected' : '' ?>>Kelas <?= $class ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="recap-filter-field">
                <span>Bulan</span>
                <select name="bulan">
                  <?php foreach ($monthNames as $code => $label): ?>
                  <option value="<?= $code ?>" <?= $filterMonth === $code ? 'selected' : '' ?>><?= recap_e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="recap-filter-field">
                <span>Tahun</span>
                <select name="tahun">
                  <?php for ($year = (int)date('Y') - 2; $year <= (int)date('Y') + 1; $year++): ?>
                  <option value="<?= $year ?>" <?= $filterYear === $year ? 'selected' : '' ?>><?= $year ?></option>
                  <?php endfor; ?>
                </select>
              </label>
            </div>
            <div class="recap-search-row">
              <label class="recap-header-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="q" placeholder="Cari nama / NIS / NIS Diknas..." value="<?= recap_e($search) ?>" />
              </label>
              <a href="rekap_kelas.php" class="recap-reset-link">Reset</a>
            </div>
            <div class="recap-view-switch">
              <button type="submit" class="is-active">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M3 9h18M9 3v18"/></svg>
                Tampilkan Rekap
              </button>
              <button type="button" onclick="window.print()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Cetak Rekap
              </button>
            </div>
          </form>
        </div>

        <div class="recap-period-strip">
          <div>
            <strong>Pembayaran <?= recap_e($monthNames[$filterMonth]) ?> <?= $filterYear ?></strong>
            <span>SPP diterima Rp <?= number_format($summary['paid_spp'], 0, ',', '.') ?> · Total penerimaan Rp <?= number_format($summary['total'], 0, ',', '.') ?></span>
          </div>
          <span><?= number_format($summary['paid_off']) ?> siswa lunas</span>
        </div>

        <div class="table-container class-recap-scroll">
          <table class="payment-table class-recap-table">
            <thead>
              <tr>
                <th colspan="3" class="recap-identity-group-head">Data Siswa</th>
                <th colspan="9" class="recap-group-head">Pembayaran <?= recap_e($monthNames[$filterMonth]) ?> <?= $filterYear ?></th>
              </tr>
              <tr>
                <th class="recap-identity-head recap-number-head">No</th>
                <th class="recap-identity-head">Nama Peserta Didik</th>
                <th class="recap-identity-head">Status SPP</th>
                <th class="recap-component-head">Pangkal</th>
                <th class="recap-component-head">Bangunan</th>
                <th class="recap-component-head">Seragam</th>
                <th class="recap-component-head">Kegiatan</th>
                <th class="recap-component-head">SPP</th>
                <th class="recap-component-head">Komite</th>
                <th class="recap-component-head">Daftar Ulang</th>
                <th class="recap-component-head">Biaya Lain</th>
                <th class="recap-component-head">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
              <tr><td colspan="12" class="text-center recap-empty">Belum ada siswa aktif di kelas <?= recap_e($filterClass) ?> untuk filter ini.</td></tr>
              <?php else: ?>
              <?php foreach ($rows as $index => $row): ?>
              <?php $detailUrl = 'detail_siswa.php?' . http_build_query([
                  'nis' => $row['NO_INDUK'],
                  'kelas' => $filterClass,
                  'bulan' => $filterMonth,
                  'tahun' => $filterYear,
                  'q' => $search,
              ]); ?>
              <tr>
                <td class="text-center recap-row-number"><?= $index + 1 ?></td>
                <td class="recap-student-name"><a class="recap-student-link" href="<?= recap_e($detailUrl) ?>"><?= recap_e($row['NAMA']) ?></a><span>NIS <?= recap_e($row['NO_INDUK']) ?><?= !empty($row['NO_induk_diknas']) ? ' · Diknas ' . recap_e($row['NO_induk_diknas']) : '' ?> · <?= (int)$row['transaksi'] ?> transaksi</span></td>
                <td><span class="recap-status <?= recap_e($row['status_class']) ?>"><?= recap_e($row['status_label']) ?></span></td>
                <td class="recap-money"><?= recap_money($row['pangkal']) ?></td>
                <td class="recap-money"><?= recap_money($row['bangunan']) ?></td>
                <td class="recap-money"><?= recap_money($row['seragam']) ?></td>
                <td class="recap-money"><?= recap_money($row['kegiatan']) ?></td>
                <td class="recap-money recap-spp"><?= recap_money($row['spp']) ?></td>
                <td class="recap-money"><?= recap_money($row['komite']) ?></td>
                <td class="recap-money"><?= recap_money($row['daftar_ulang']) ?></td>
                <td class="recap-money"><?= recap_money($row['biaya_lain']) ?></td>
                <td class="recap-money recap-total"><?= recap_money($row['total_bayar']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="recap-mobile-list">
          <?php if (!$rows): ?>
          <div class="recap-mobile-empty">Belum ada siswa aktif di kelas <?= recap_e($filterClass) ?> untuk filter ini.</div>
          <?php else: foreach ($rows as $index => $row): ?>
          <?php $detailUrl = 'detail_siswa.php?' . http_build_query([
              'nis' => $row['NO_INDUK'],
              'kelas' => $filterClass,
              'bulan' => $filterMonth,
              'tahun' => $filterYear,
              'q' => $search,
          ]); ?>
          <article class="recap-mobile-card">
            <div class="recap-mobile-card-head">
              <span class="recap-mobile-number"><?= $index + 1 ?></span>
              <div>
                <a href="<?= recap_e($detailUrl) ?>"><?= recap_e($row['NAMA']) ?></a>
                <span>NIS <?= recap_e($row['NO_INDUK']) ?><?= !empty($row['NO_induk_diknas']) ? ' · Diknas ' . recap_e($row['NO_induk_diknas']) : '' ?> · <?= (int)$row['transaksi'] ?> transaksi</span>
              </div>
              <span class="recap-status <?= recap_e($row['status_class']) ?>"><?= recap_e($row['status_label']) ?></span>
            </div>
            <div class="recap-mobile-values">
              <div><span>SPP</span><strong><?= recap_money($row['spp']) ?></strong></div>
              <div><span>Komite</span><strong><?= recap_money($row['komite']) ?></strong></div>
              <div><span>Daftar Ulang</span><strong><?= recap_money($row['daftar_ulang']) ?></strong></div>
              <div><span>Biaya Lain</span><strong><?= recap_money($row['biaya_lain']) ?></strong></div>
              <div class="is-total"><span>Total</span><strong><?= recap_money($row['total_bayar']) ?></strong></div>
            </div>
            <a class="recap-mobile-detail" href="<?= recap_e($detailUrl) ?>">Lihat riwayat selama sekolah <span>→</span></a>
          </article>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=3.8"></script>
</body>
</html>
