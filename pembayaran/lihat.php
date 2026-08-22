<?php
// ============================================
// pembayaran/lihat.php - View All Payments
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/pagination.php';
requireRole(['admin', 'kasir']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$printPayment = is_array($flash['print_payment'] ?? null) ? $flash['print_payment'] : null;
$printPaymentId = max(0, (int)($printPayment['id'] ?? 0));
$printPaymentMonth = str_pad((string)(int)($printPayment['bulan'] ?? 0), 2, '0', STR_PAD_LEFT);
$printPaymentYear = (string)(int)($printPayment['tahun'] ?? 0);
$isUpdatedPayment = ($printPayment['source'] ?? '') === 'update';
$printBatchToken = strtolower(trim((string)($printPayment['batch'] ?? '')));
$printReceiptCount = max(1, (int)($printPayment['count'] ?? 1));
$isAnnualPayment = $printReceiptCount === 12 && preg_match('/^[a-f0-9]{32}$/', $printBatchToken);
$showPrintPrompt = $printPaymentId > 0
    && preg_match('/^(0[1-9]|1[0-2])$/', $printPaymentMonth)
    && preg_match('/^\d{4}$/', $printPaymentYear);
$printPaymentUrl = $isAnnualPayment
    ? '../laporan/cetak_struk_tahunan.php?' . http_build_query(['batch' => $printBatchToken])
    : '../laporan/cetak_struk.php?' . http_build_query(['id' => $printPaymentId]);

function month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

function format_payment_datetime($value): array {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) return ['date' => '-', 'time' => '-'];
    return [
        'date' => date('d/m/Y', $timestamp),
        'time' => date('H:i', $timestamp) . ' WIB'
    ];
}

function payment_was_updated($createdAt, $updatedAt): bool {
    $created = strtotime((string)$createdAt);
    $updated = strtotime((string)$updatedAt);
    return $created && $updated && $updated > ($created + 1);
}

// Filter
$search     = trim($_GET['search'] ?? '');
$filter_bln = $_GET['bulan']  ?? '';
$filter_thn = $_GET['tahun']  ?? '';
$allowedPageSizes = [10, 25, 50];
$perPage = page_size_param('per_page', $allowedPageSizes, 10);
$page = page_int_param('page');

$where = "WHERE 1=1";
$params = [];
$types  = '';
if ($search) {
    $like = "%$search%";
    $where .= " AND (s.NAMA LIKE ? OR s.NO_INDUK LIKE ? OR s.NO_induk_diknas LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($filter_bln) {
    $month_names = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    $where .= " AND (p.BULAN = ? OR p.BULAN = ?)";
    $params[] = $filter_bln; $types .= 's';
    $params[] = $month_names[$filter_bln] ?? $filter_bln; $types .= 's';
}
if ($filter_thn) {
    $where .= " AND p.TAHUN = ?";
    $params[] = $filter_thn; $types .= 's';
}

$countSql = "SELECT COUNT(*) AS total FROM bayar p
        JOIN siswa s ON s.NO_INDUK = p.NO_INDUK
        $where";
$stmtCount = $koneksi->prepare($countSql);
if ($params) { $stmtCount->bind_param($types, ...$params); }
$stmtCount->execute();
$totalPayments = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
$stmtCount->close();

$totalPages = total_pages($totalPayments, $perPage);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS FROM bayar p
        JOIN siswa s ON s.NO_INDUK = p.NO_INDUK
        $where ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $koneksi->prepare($sql);
$pageParams = array_merge($params, [$perPage, $offset]);
$pageTypes = $types . 'ii';
$stmt->bind_param($pageTypes, ...$pageParams);
$stmt->execute();
$result = $stmt->get_result();
$paymentPaginationQuery = pagination_query(['per_page' => $perPage]);

// Months list
$bln_list = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$firstShown = $totalPayments > 0 ? $offset + 1 : 0;
$lastShown = $totalPayments > 0 ? min($offset + $perPage, $totalPayments) : 0;
$periodLabel = ($filter_bln ? ($bln_list[$filter_bln] ?? $filter_bln) : 'Semua bulan') . ($filter_thn ? ' ' . $filter_thn : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Lihat semua data transaksi pembayaran siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=7.5" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

  <div class="bg-orbs">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Riwayat Pembayaran Siswa</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Riwayat</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($flash['type'] === 'success'): ?><polyline points="20 6 9 17 4 12"/>
          <?php else: ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <?php if ($showPrintPrompt): ?>
      <div class="modal-overlay show" id="receipt-print-modal" role="dialog" aria-modal="true" aria-labelledby="receipt-print-title">
        <div class="modal-box">
          <div class="modal-icon" aria-hidden="true">🧾</div>
          <h3 class="modal-title" id="receipt-print-title"><?= $isAnnualPayment ? 'Cetak 12 struk pembayaran?' : ($isUpdatedPayment ? 'Cetak ulang struk pembayaran?' : 'Cetak struk pembayaran?') ?></h3>
          <p class="modal-body"><?= $isAnnualPayment
              ? 'Pembayaran tahunan sudah dibagi menjadi 12 transaksi. Semua struk Januari–Desember dapat dicetak sekaligus.'
              : ($isUpdatedPayment
              ? 'Perubahan pembayaran sudah tersimpan. Cetak ulang struk agar isinya sesuai dengan data terbaru.'
              : 'Pembayaran sudah tersimpan. Kamu bisa langsung membuka struk transaksi ini tanpa memilihnya lagi dari halaman Laporan.') ?></p>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" id="receipt-print-later">Tidak, nanti</button>
            <button type="button" class="btn btn-primary" id="receipt-print-now"><?= $isAnnualPayment ? 'Ya, cetak 12 struk' : ($isUpdatedPayment ? 'Ya, cetak ulang' : 'Ya, cetak struk') ?></button>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <section class="main-card class-recap-card recap-report-shell history-recap-shell payment-history-shell">
        <div class="recap-report-header">
          <div class="recap-report-copy">
            <span class="recap-class-overline">Pembayaran</span>
            <h1>Riwayat Pembayaran Siswa</h1>
            <p><?= number_format($totalPayments) ?> transaksi cocok dengan filter saat ini.</p>
          </div>

        <!-- Filter Bar -->
        <form method="GET" action="lihat.php" class="recap-header-controls history-recap-filter filter-bar">
          <span class="recap-filter-label">Filter Riwayat</span>
          <div class="search-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="search-lihat" name="search" placeholder="Cari nama / NIS / NIS Diknas..."
              value="<?= htmlspecialchars($search) ?>" />
          </div>
          <select class="field-input field-select filter-sel month-code-select" name="bulan" id="filter-bulan">
            <option value="">Semua Bulan</option>
            <?php foreach ($bln_list as $code => $label): ?>
            <option value="<?=$code?>" data-label="<?=$label?>" <?= $filter_bln === $code ? 'selected' : '' ?>><?=$label?></option>
            <?php endforeach; ?>
          </select>
          <select class="field-input field-select filter-sel" name="tahun" id="filter-tahun">
            <option value="">Semua Tahun</option>
            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
            <option value="<?=$y?>" <?= $filter_thn == $y ? 'selected' : '' ?>><?=$y?></option>
            <?php endfor; ?>
          </select>
          <select class="field-input field-select filter-sel" name="per_page" aria-label="Jumlah pembayaran per halaman">
            <?php foreach ($allowedPageSizes as $pageSize): ?>
            <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?> / halaman</option>
            <?php endforeach; ?>
          </select>
          <div class="history-recap-actions">
            <button type="submit" class="btn btn-primary" id="btn-filter">Tampilkan Rekap</button>
            <a href="lihat.php" class="btn btn-ghost" id="btn-reset-filter">Reset</a>
            <a href="form.php" class="btn btn-primary" id="btn-tambah">Tambah Baru</a>
          </div>
        </form>
        </div>

        <div class="recap-period-strip history-recap-strip">
          <div><strong>Transaksi Pembayaran <?= htmlspecialchars($periodLabel) ?></strong><span>Menampilkan <?= number_format($firstShown) ?>–<?= number_format($lastShown) ?> dari <?= number_format($totalPayments) ?> transaksi.</span></div>
          <span><?= number_format($totalPayments) ?> transaksi</span>
        </div>

        <!-- Table -->
        <div class="table-container">
          <table class="payment-table responsive-table" id="tbl-lihat">
            <thead>
              <tr>
                <th>No</th>
                <th>NIS</th>
                <th>Nama Siswa</th>
                <th class="kelas-col">Kelas</th>
                <th>Bulan / Tahun</th>
                <th>SPP</th>
                <th>Sistem</th>
                <th>Total Bayar</th>
                <th>Bayar / Update</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($result->num_rows > 0):
                $no = $offset + 1;
                while ($row = $result->fetch_assoc()):
                  $paymentDateTime = format_payment_datetime($row['TGL_BYR']);
                  $updatedDateTime = format_payment_datetime($row['updated_at'] ?? null);
                  $wasUpdated = payment_was_updated($row['created_at'] ?? null, $row['updated_at'] ?? null);
                  $canEdit = (int)($row['payment_link_version'] ?? 0) === 1;
                  $editUrl = 'edit.php?id=' . (int)$row['id'];
                  $rowAttrs = $canEdit
                    ? ' class="clickable-payment-row" data-edit-url="' . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '" tabindex="0" role="link" aria-label="Edit pembayaran ' . htmlspecialchars($row['NAMA'], ENT_QUOTES, 'UTF-8') . '"'
                    : '';
                ?>
              <tr<?= $rowAttrs ?>>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="NIS"><span class="badge-nis"><?= htmlspecialchars($row['NO_INDUK']) ?></span><?php if (!empty($row['NO_induk_diknas'])): ?><small class="du-history-nis">Diknas <?= htmlspecialchars($row['NO_induk_diknas']) ?></small><?php endif; ?></td>
                <td data-label="Nama Siswa"><?= htmlspecialchars($row['NAMA']) ?></td>
                <td data-label="Kelas" class="kelas-col"><span class="kelas-badge">Kelas <?= htmlspecialchars($row['KELAS']) ?></span></td>
                <td data-label="Bulan / Tahun">
                  <?= htmlspecialchars(month_code($row['BULAN'])) ?> <?= $row['TAHUN'] ?>
                  <?php if ((int)($row['payment_batch_count'] ?? 1) === 12): ?>
                  <small class="du-history-nis">Struk <?= (int)$row['payment_batch_sequence'] ?>/12</small>
                  <?php endif; ?>
                </td>
                <td data-label="SPP" class="nominal">Rp <?= number_format($row['U_SPP'], 0, ',', '.') ?></td>
                <td data-label="Sistem"><?= htmlspecialchars($row['sistem_pembayaran'] ?? 'VA') ?></td>
                <td data-label="Total Bayar" class="nominal">Rp <?= number_format($row['total_jumlah'], 0, ',', '.') ?></td>
                <td data-label="Bayar / Update">
                  <span class="date-time-cell">
                    <strong>Bayar: <?= htmlspecialchars($paymentDateTime['date']) ?></strong>
                    <small><?= htmlspecialchars($paymentDateTime['time']) ?></small>
                    <?php if ($wasUpdated): ?>
                    <small class="date-time-updated">Diubah: <?= htmlspecialchars($updatedDateTime['date']) ?> <?= htmlspecialchars($updatedDateTime['time']) ?></small>
                    <?php endif; ?>
                  </span>
                </td>
                <td data-label="Aksi" class="aksi-col">
                  <?php if ($canEdit): ?>
                  <a href="<?= htmlspecialchars($editUrl) ?>" class="btn-tbl btn-tbl-edit" title="Edit">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                  </a>
                  <a href="proses.php?aksi=hapus&id=<?= $row['id'] ?>"
                     class="btn-tbl btn-tbl-del" title="Hapus"
                     onclick="return confirm('Yakin ingin menghapus data pembayaran ini?')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Hapus
                  </a>
                  <?php if ((int)($row['payment_batch_count'] ?? 1) === 12): ?>
                  <a href="../laporan/cetak_struk_tahunan.php?batch=<?= urlencode((string)$row['payment_batch_token']) ?>" class="btn-tbl btn-tbl-print" target="_blank" rel="noopener" title="Cetak seluruh struk tahunan">12 Struk</a>
                  <?php endif; ?>
                  <?php else: ?>
                  <span class="master-status is-inactive" title="Transaksi lama tanpa relasi eksplisit">Legacy — rekonsiliasi manual</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endwhile;
              else: ?>
              <tr><td colspan="10">
                <div class="empty-state">
                  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                  <p>Belum ada data pembayaran</p>
                  <a href="form.php" class="btn btn-primary" style="margin-top:12px">+ Input Pembayaran</a>
                </div>
              </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php render_pagination('lihat.php', $paymentPaginationQuery, $page, $totalPages, $totalPayments, $perPage, 'transaksi'); ?>
      </section>
    </main>
  </div>

  <script src="../assets/js/app.js?v=4.1"></script>
  <?php if ($showPrintPrompt): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const modal = document.getElementById('receipt-print-modal');
      const printNow = document.getElementById('receipt-print-now');
      const printLater = document.getElementById('receipt-print-later');
      const closePrompt = function () {
        modal.classList.remove('show');
        window.setTimeout(function () { modal.remove(); }, 250);
      };

      printLater.addEventListener('click', closePrompt);
      printNow.addEventListener('click', function () {
        window.open(<?= json_encode($printPaymentUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, '_blank', 'noopener');
        closePrompt();
      });
      printNow.focus();
    });
  </script>
  <?php endif; ?>
</body>
</html>

