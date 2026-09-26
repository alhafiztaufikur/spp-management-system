<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/transaction_authorization.php';
requireRole(['admin', 'bendahara']);

if (empty($_SESSION['csrf_transaction_authorization'])) {
    $_SESSION['csrf_transaction_authorization'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_transaction_authorization'];
$currentId = (int)$_SESSION['admin_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function authorization_redirect_flash(string $type, string $message, string $status = 'pending'): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
    header('Location: otorisasi_transaksi.php?status=' . urlencode($status));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        authorization_redirect_flash('error', 'Permintaan tidak valid atau sesi telah kedaluwarsa.');
    }
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $decisionNote = trim((string)($_POST['decision_note'] ?? ''));
    try {
        transaction_authorization_assert_ready($koneksi);
        $koneksi->begin_transaction();
        $request = transaction_authorization_find($koneksi, $requestId, true);
        if (!$request || $request['status'] !== 'pending') {
            throw new RuntimeException('Permintaan sudah diproses atau tidak ditemukan.');
        }
        if ($action === 'cancel') {
            if ((int)$request['requested_by'] !== $currentId) {
                throw new RuntimeException('Hanya pemohon yang dapat membatalkan permintaan ini.');
            }
            transaction_authorization_decide($koneksi, $requestId, 'cancelled', $currentId, 'Dibatalkan oleh pemohon.');
            $message = 'Permintaan berhasil dibatalkan.';
        } elseif ($action === 'reject') {
            if ((int)$request['requested_by'] === $currentId) {
                throw new RuntimeException('Pemohon tidak boleh menolak permintaannya sendiri.');
            }
            transaction_authorization_decide($koneksi, $requestId, 'rejected', $currentId, $decisionNote);
            $message = 'Permintaan berhasil ditolak.';
        } else {
            throw new RuntimeException('Tindakan otorisasi tidak dikenali.');
        }
        $koneksi->commit();
        authorization_redirect_flash('success', $message);
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        authorization_redirect_flash('error', $error->getMessage());
    }
}

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'failed', 'all'];
$statusFilter = (string)($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'pending';
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
$types = '';
if ($statusFilter !== 'all') {
    $where[] = 'r.status=?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(r.transaction_reference LIKE ? OR r.no_induk_snapshot LIKE ? OR r.student_name_snapshot LIKE ? OR req.nama LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$sql = "SELECT r.*,req.nama requested_by_name,req.role requested_by_role,reviewer.nama decided_by_name
        FROM transaksi_otorisasi r
        JOIN admin req ON req.id=r.requested_by
        LEFT JOIN admin reviewer ON reviewer.id=r.decided_by";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY (r.status='pending') DESC,r.requested_at DESC,r.id DESC LIMIT 250";

$requests = [];
$schemaError = null;
try {
    transaction_authorization_assert_ready($koneksi);
    $stmt = $koneksi->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $error) {
    $schemaError = $error->getMessage();
}

function authorization_amount_from_payload(array $payload): float
{
    $keys = ['uang_pangkal','uang_psb','uang_spp','uang_komite','uang_du'];
    $total = 0.0;
    foreach ($keys as $key) {
        $raw = trim((string)($payload[$key] ?? 0));
        $total += is_numeric($raw) ? (float)$raw : (float)str_replace(['.', ','], ['', '.'], $raw);
    }
    foreach (($payload['biaya_lain_nominal'] ?? []) as $value) {
        $raw = trim((string)$value);
        $total += is_numeric($raw) ? (float)$raw : (float)str_replace(['.', ','], ['', '.'], $raw);
    }
    $discountRaw = trim((string)($payload['potongan_spp'] ?? 0));
    $discount = is_numeric($discountRaw) ? (float)$discountRaw : (float)str_replace(['.', ','], ['', '.'], $discountRaw);
    return max($total - $discount, 0);
}

function authorization_request_summary(array $request): array
{
    $before = json_decode((string)$request['before_snapshot'], true);
    $payload = json_decode((string)($request['proposed_payload'] ?? ''), true);
    $payment = is_array($before) ? ($before['payment'] ?? []) : [];
    $payload = is_array($payload) ? $payload : [];
    return [
        'before_total' => (float)($payment['total_jumlah'] ?? 0),
        'after_total' => $request['action'] === 'hapus' ? 0.0 : authorization_amount_from_payload($payload),
        'before_period' => trim((string)($payment['BULAN'] ?? '') . ' ' . (string)($payment['TAHUN'] ?? '')),
        'after_period' => trim((string)($payload['bulan_bayar'] ?? '') . ' ' . (string)($payload['tahun_bayar'] ?? '')),
        'method' => (string)($payload['sistem_pembayaran'] ?? $payment['sistem_pembayaran'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Otorisasi Transaksi | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png?v=2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=10.9" />
</head>
<body>
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Buka menu" aria-label="Buka menu">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2>Otorisasi Transaksi</h2><span class="breadcrumb">SistemSPP / Pembayaran / Otorisasi</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
      <?php if ($schemaError): ?><div class="alert alert-error"><?= htmlspecialchars($schemaError) ?></div><?php endif; ?>

      <section class="authorization-hero">
        <div><span>Kontrol Transaksi</span><h1>Antrean Otorisasi</h1><p>Periksa usulan perubahan atau penghapusan sebelum data pembayaran benar-benar diubah.</p></div>
        <div class="authorization-hero-count"><span>Hasil filter</span><strong><?= number_format(count($requests)) ?></strong><small>permintaan</small></div>
      </section>

      <section class="main-card authorization-filter-card">
        <form method="get" class="authorization-filter-form">
          <label class="field-row"><span class="field-label">Status</span><select class="field-input field-select" name="status">
            <?php foreach (['pending'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak','cancelled'=>'Dibatalkan','failed'=>'Gagal/Kedaluwarsa','all'=>'Semua Status'] as $value=>$label): ?>
              <option value="<?= $value ?>" <?= $statusFilter===$value?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select></label>
          <label class="field-row authorization-search-field"><span class="field-label">Cari transaksi atau siswa</span><input class="field-input" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nomor transaksi, NIS, atau nama siswa" /></label>
          <button class="btn btn-primary" type="submit">Tampilkan</button>
          <a class="btn btn-ghost" href="otorisasi_transaksi.php">Reset</a>
        </form>
      </section>

      <section class="authorization-list" aria-label="Daftar permintaan otorisasi">
        <?php if (!$requests): ?>
          <div class="main-card empty-state"><p>Belum ada permintaan pada filter ini</p><span>Permintaan edit atau hapus transaksi akan tampil di sini.</span></div>
        <?php endif; ?>
        <?php foreach ($requests as $request): $summary = authorization_request_summary($request); $isRequester=(int)$request['requested_by']===$currentId; $isPending=$request['status']==='pending'; ?>
          <article class="authorization-card">
            <header class="authorization-card-head">
              <div><span class="authorization-reference"><?= htmlspecialchars($request['transaction_reference']) ?></span><h3><?= htmlspecialchars($request['student_name_snapshot']) ?></h3><p>NIS <?= htmlspecialchars((string)$request['no_induk_snapshot']) ?> &middot; <?= htmlspecialchars(transaction_authorization_action_label($request['action'])) ?></p></div>
              <span class="authorization-status authorization-status-<?= htmlspecialchars($request['status']) ?>"><?= htmlspecialchars(transaction_authorization_status_label($request['status'])) ?></span>
            </header>
            <div class="authorization-comparison">
              <div><span>Sebelum</span><strong><?= transaction_authorization_money($summary['before_total']) ?></strong><small>Periode <?= htmlspecialchars($summary['before_period'] ?: 'Tidak tersedia') ?></small></div>
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
              <div><span><?= $request['action']==='hapus'?'Setelah dihapus':'Usulan' ?></span><strong><?= transaction_authorization_money($summary['after_total']) ?></strong><small><?= $request['action']==='hapus'?'Transaksi dihapus':('Periode '.htmlspecialchars($summary['after_period'] ?: 'Tidak berubah')) ?></small></div>
            </div>
            <div class="authorization-reason"><span>Alasan pemohon</span><p><?= nl2br(htmlspecialchars($request['request_reason'])) ?></p></div>
            <footer class="authorization-card-footer">
              <div><strong><?= htmlspecialchars($request['requested_by_name']) ?></strong><span><?= htmlspecialchars(ucfirst($request['requested_by_role'])) ?> &middot; <?= htmlspecialchars(date('d/m/Y H:i', strtotime($request['requested_at']))) ?></span><?php if($request['decided_by_name']): ?><small>Diproses oleh <?= htmlspecialchars($request['decided_by_name']) ?></small><?php endif; ?></div>
              <?php if ($isPending && $isRequester): ?>
                <form method="post" onsubmit="return confirm('Batalkan permintaan ini?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Batalkan Permintaan</button></form>
              <?php elseif ($isPending): ?>
                <div class="authorization-decision-actions">
                  <form method="post" action="pembayaran/proses.php" onsubmit="return confirm('Setujui dan terapkan perubahan transaksi ini?')"><input type="hidden" name="aksi" value="otorisasi_setujui"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input class="field-input" name="decision_note" maxlength="1000" placeholder="Catatan persetujuan (opsional)"><button class="btn btn-primary" type="submit">Setujui dan Terapkan</button></form>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="reject"><input class="field-input" name="decision_note" maxlength="1000" required placeholder="Alasan penolakan wajib diisi"><button class="btn btn-danger" type="submit">Tolak</button></form>
                </div>
              <?php elseif (!empty($request['decision_note'])): ?>
                <div class="authorization-decision-note"><span>Catatan keputusan</span><p><?= nl2br(htmlspecialchars($request['decision_note'])) ?></p></div>
              <?php endif; ?>
            </footer>
          </article>
        <?php endforeach; ?>
      </section>
    </main>
  </div>
  <script src="assets/js/app.js?v=10.4"></script>
</body>
</html>
