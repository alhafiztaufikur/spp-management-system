<?php
require_once __DIR__ . '/../includes/security.php';
security_bootstrap_session();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

function history_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function history_money($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function history_month($value): string {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    if (in_array($value, $months, true)) return (string)$value;
    $code = str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
    return $months[$code] ?? (string)$value;
}

function history_full_date($value): array {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) return ['date' => '-', 'time' => '-'];
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return [
        'date' => $days[(int)date('w', $timestamp)] . ', ' . date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp),
        'time' => date('H:i', $timestamp) . ' WIB',
    ];
}

$noIndukRaw = $_GET['nis'] ?? '';
$noInduk = trim((string)(is_scalar($noIndukRaw) ? $noIndukRaw : ''));
if ($noInduk === '' || strlen($noInduk) > 50) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Siswa untuk detail rekap tidak valid.'];
    header('Location: rekap_kelas.php');
    exit;
}

$stmt = $koneksi->prepare('SELECT * FROM siswa WHERE NO_INDUK = ? LIMIT 1');
$stmt->bind_param('s', $noInduk);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$student) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data siswa tidak ditemukan.'];
    header('Location: rekap_kelas.php');
    exit;
}

$stmt = $koneksi->prepare("
    SELECT
        b.*,
        COALESCE(du.daftar_ulang, 0) AS daftar_ulang,
        COALESCE(lain.biaya_lain, 0) AS biaya_lain
    FROM bayar b
    LEFT JOIN (
        SELECT bayar_id, SUM(jumlah) AS daftar_ulang
        FROM bayar_du
        GROUP BY bayar_id
    ) du ON du.bayar_id = b.id
    LEFT JOIN (
        SELECT bayar_id, SUM(nominal_snapshot) AS biaya_lain
        FROM bayar_biaya_lain
        GROUP BY bayar_id
    ) lain ON lain.bayar_id = b.id
    WHERE b.NO_INDUK = ?
    ORDER BY b.TGL_BYR DESC, b.id DESC
");
$stmt->bind_param('s', $noInduk);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$otherDetails = [];
if ($transactions) {
    $ids = array_map(fn($row) => (int)$row['id'], $transactions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $koneksi->prepare("SELECT bayar_id, nama_biaya_snapshot, nominal_snapshot, keterangan FROM bayar_biaya_lain WHERE bayar_id IN ($placeholders) ORDER BY urutan, id");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($detail = $result->fetch_assoc()) $otherDetails[(int)$detail['bayar_id']][] = $detail;
    $stmt->close();
}

$summary = [
    'transactions' => count($transactions),
    'total' => 0.0,
    'spp' => 0.0,
    'first_date' => null,
    'last_date' => null,
];
$componentMap = [
    'U_PANGKAL' => 'Uang Pangkal',
    'U_BANGUNAN' => 'Uang Bangunan',
    'U_SERAGAM' => 'Uang Seragam',
    'U_KEGIATAN' => 'Uang Kegiatan',
    'U_SPP' => 'Uang SPP',
    'U_KOMITE' => 'Uang Komite',
    'U_MAKAN' => 'Uang Makan',
    'U_SORGA' => 'Uang Sorga',
    'U_INFAQ' => 'Uang Infaq',
];

foreach ($transactions as &$transaction) {
    $transaction['full_date'] = history_full_date($transaction['TGL_BYR']);
    $transaction['lines'] = [];
    foreach ($componentMap as $column => $label) {
        if ((float)$transaction[$column] > 0) {
            $transaction['lines'][] = ['label' => $label, 'amount' => (float)$transaction[$column]];
        }
    }
    if ((float)$transaction['daftar_ulang'] > 0) {
        $transaction['lines'][] = ['label' => 'Daftar Ulang', 'amount' => (float)$transaction['daftar_ulang']];
    }
    foreach ($otherDetails[(int)$transaction['id']] ?? [] as $detail) {
        $label = $detail['nama_biaya_snapshot'];
        if (trim((string)$detail['keterangan']) !== '') $label .= ' - ' . $detail['keterangan'];
        $transaction['lines'][] = ['label' => $label, 'amount' => (float)$detail['nominal_snapshot']];
    }
    if ((float)$transaction['potong_spp'] > 0) {
        $transaction['lines'][] = ['label' => 'Potongan SPP', 'amount' => -(float)$transaction['potong_spp']];
    }
    $transaction['grand_total'] = (float)$transaction['total_jumlah'];
    $summary['total'] += $transaction['grand_total'];
    $summary['spp'] += (float)$transaction['U_SPP'];
    $timestamp = strtotime((string)$transaction['TGL_BYR']);
    if ($timestamp) {
        if ($summary['first_date'] === null || $timestamp < $summary['first_date']) $summary['first_date'] = $timestamp;
        if ($summary['last_date'] === null || $timestamp > $summary['last_date']) $summary['last_date'] = $timestamp;
    }
}
unset($transaction);

$backParams = [];
foreach (['kelas', 'bulan', 'tahun', 'q'] as $key) {
    if (isset($_GET[$key]) && is_scalar($_GET[$key]) && trim((string)$_GET[$key]) !== '') $backParams[$key] = trim((string)$_GET[$key]);
}
$backUrl = 'rekap_kelas.php' . ($backParams ? '?' . http_build_query($backParams) : '');
$schoolPeriod = $summary['first_date']
    ? date('Y', $summary['first_date']) . '—' . date('Y', $summary['last_date'] ?? $summary['first_date'])
    : 'Belum ada transaksi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat <?= history_e($student['NAMA']) ?> | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Riwayat pembayaran lengkap siswa selama sekolah." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=4.9" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2>Riwayat Pembayaran Siswa</h2><span class="breadcrumb">SistemSPP / Rekap Kelas / Detail Siswa</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <div class="main-card student-history-card">
        <a href="<?= history_e($backUrl) ?>" class="history-back-link">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
          Kembali ke rekap kelas
        </a>

        <div class="student-history-hero">
          <div class="student-history-avatar"><?= history_e(mb_strtoupper(mb_substr($student['NAMA'], 0, 1))) ?></div>
          <div class="student-history-identity">
            <span>REKAP SELAMA SEKOLAH</span>
            <h1><?= history_e($student['NAMA']) ?></h1>
            <p>NIS <?= history_e($student['NO_INDUK']) ?><?= !empty($student['NO_induk_diknas']) ? ' · NIS Diknas ' . history_e($student['NO_induk_diknas']) : '' ?> · Kelas <?= history_e($student['KELAS']) ?> · Periode <?= history_e($schoolPeriod) ?></p>
          </div>
          <span class="master-status <?= (int)$student['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>"><?= (int)$student['is_active'] === 1 ? 'Siswa Aktif' : 'Diarsipkan' ?></span>
        </div>

        <div class="history-summary-grid">
          <div><span>Total Transaksi</span><strong><?= number_format($summary['transactions']) ?></strong></div>
          <div><span>Total Pembayaran</span><strong><?= history_money($summary['total']) ?></strong></div>
          <div><span>Total SPP</span><strong><?= history_money($summary['spp']) ?></strong></div>
        </div>

        <div class="history-section-heading">
          <div><h2>Detail Transaksi</h2><p>Urutan terbaru hingga transaksi pertama siswa.</p></div>
          <span><?= number_format(count($transactions)) ?> transaksi</span>
        </div>

        <div class="table-container">
          <table class="payment-table responsive-table student-history-table">
            <thead><tr>
              <th>No</th><th>Tanggal Pembayaran</th><th>Periode</th><th>Rincian</th><th>Metode</th><th>Total</th><th>Aksi</th>
            </tr></thead>
            <tbody>
              <?php if (!$transactions): ?>
              <tr><td colspan="7" class="text-center recap-empty">Siswa ini belum memiliki transaksi pembayaran.</td></tr>
              <?php else: foreach ($transactions as $index => $transaction): ?>
              <tr>
                <td data-label="No"><?= $index + 1 ?></td>
                <td data-label="Tanggal"><div class="history-date"><strong><?= history_e($transaction['full_date']['date']) ?></strong><span><?= history_e($transaction['full_date']['time']) ?></span></div></td>
                <td data-label="Periode"><strong><?= history_e(history_month($transaction['BULAN'])) ?> <?= history_e($transaction['TAHUN']) ?></strong></td>
                <td data-label="Rincian">
                  <div class="history-components">
                    <?php if (!$transaction['lines']): ?><span>Tanpa rincian komponen</span><?php endif; ?>
                    <?php foreach ($transaction['lines'] as $line): ?>
                    <span><?= history_e($line['label']) ?> <b><?= $line['amount'] < 0 ? '-' : '' ?><?= history_money(abs($line['amount'])) ?></b></span>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td data-label="Metode"><span class="history-method"><?= history_e($transaction['sistem_pembayaran'] ?? 'VA') ?></span></td>
                <td data-label="Total" class="nominal"><?= history_money($transaction['grand_total']) ?></td>
                <td data-label="Aksi"><a class="btn-tbl btn-tbl-print" href="cetak_struk.php?id=<?= (int)$transaction['id'] ?>" target="_blank" rel="noopener">Cetak Struk</a></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
  <script src="../assets/js/app.js?v=4.1"></script>
</body>
</html>
