<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/kelas.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_master_kelas'])) $_SESSION['csrf_master_kelas'] = bin2hex(random_bytes(32));

function class_master_redirect(): void {
    header('Location: master_kelas.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_master_kelas'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Permintaan tidak valid atau sesi telah kedaluwarsa.');
        }
        $action = (string)($_POST['aksi'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'template_aj') {
            $created = class_ensure_rombel_templates($koneksi);
            $message = $created > 0 ? $created . ' rombel template A-J berhasil ditambahkan.' : 'Template rombel A-J sudah lengkap.';
        } elseif ($action === 'promosi_tahun') {
            $targetYear = (string)($_POST['target_tahun_ajaran'] ?? class_next_academic_year_label(du_current_academic_year()));
            $koneksi->begin_transaction();
            $result = class_process_year_promotion($koneksi, $targetYear);
            $koneksi->commit();
            $message = 'Kenaikan tahun ajaran ' . $result['target_year'] . ' selesai: ' . $result['promoted'] . ' siswa naik, ' . $result['graduated'] . ' siswa lulus, ' . $result['skipped'] . ' siswa dilewati.';
        } elseif ($action === 'nonaktifkan_kosong') {
            $affected = class_disable_empty_rombel($koneksi);
            $message = $affected > 0 ? $affected . ' rombel kosong berhasil dinonaktifkan.' : 'Tidak ada rombel kosong aktif yang perlu dinonaktifkan.';
        } elseif (in_array($action, ['tambah', 'update'], true)) {
            $level = (int)($_POST['tingkat'] ?? 0);
            $code = strtoupper(trim((string)($_POST['kode_rombel'] ?? '')));
            $code = preg_replace('/\s+/', '', $code);
            if ($level < 1 || $level > 6) throw new RuntimeException('Tingkat kelas harus 1 sampai 6.');
            if (!preg_match('/^[A-Z0-9]{1,10}$/', $code)) throw new RuntimeException('Kode rombel hanya boleh berisi huruf/angka, maksimal 10 karakter.');

            $stmt = $koneksi->prepare('SELECT id FROM master_kelas WHERE tingkat = ? AND kode_rombel = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('isi', $level, $code, $id);
            $stmt->execute(); $duplicate = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($duplicate) throw new RuntimeException('Rombel ' . $level . $code . ' sudah tersedia.');

            if ($action === 'tambah') {
                $stmt = $koneksi->prepare('INSERT INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active) VALUES (?, ?, 0, 1)');
                $stmt->bind_param('is', $level, $code);
                $stmt->execute(); $stmt->close();
                $message = 'Rombel ' . $level . $code . ' berhasil ditambahkan.';
            } else {
                $class = class_find($koneksi, $id);
                if (!$class || (int)$class['is_placeholder'] === 1) throw new RuntimeException('Rombel placeholder tidak dapat diubah.');
                if ((int)$class['tingkat'] !== $level) {
                    $stmt = $koneksi->prepare('SELECT (SELECT COUNT(*) FROM siswa WHERE master_kelas_id=?) + (SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE master_kelas_id=?) total');
                    $stmt->bind_param('ii',$id,$id);$stmt->execute();$usage=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
                    if ($usage > 0) throw new RuntimeException('Tingkat rombel yang sudah dipakai tidak dapat diubah. Buat rombel baru agar histori tetap benar.');
                }
                $stmt = $koneksi->prepare('UPDATE master_kelas SET tingkat = ?, kode_rombel = ? WHERE id = ?');
                $stmt->bind_param('isi', $level, $code, $id);
                $stmt->execute(); $stmt->close();
                $message = 'Rombel berhasil diperbarui. Snapshot histori lama tetap dipertahankan.';
            }
        } elseif ($action === 'toggle') {
            $class = class_find($koneksi, $id);
            if (!$class || (int)$class['is_placeholder'] === 1) throw new RuntimeException('Rombel placeholder harus selalu aktif.');
            if ((int)$class['is_active'] === 1) {
                $stmt=$koneksi->prepare('SELECT COUNT(*) total FROM siswa WHERE master_kelas_id=? AND is_active=1');$stmt->bind_param('i',$id);$stmt->execute();$activeStudents=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
                if($activeStudents>0)throw new RuntimeException('Rombel masih dipakai '.$activeStudents.' siswa aktif dan belum dapat dinonaktifkan.');
            }
            $stmt = $koneksi->prepare('UPDATE master_kelas SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            $message = 'Status rombel berhasil diubah.';
        } else {
            throw new RuntimeException('Aksi Master Kelas tidak dikenali.');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $message];
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $error->getMessage()];
    }
    class_master_redirect();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$nextAcademicYear = class_next_academic_year_label(du_current_academic_year());
$editId = (int)($_GET['edit'] ?? 0);
$editClass = $editId > 0 ? class_find($koneksi, $editId) : null;
$classes = $koneksi->query("SELECT mk.*,
    (SELECT COUNT(*) FROM siswa s WHERE s.master_kelas_id = mk.id AND s.is_active=1) AS siswa_count,
    (SELECT COUNT(*) FROM siswa_tahun_ajaran sta WHERE sta.master_kelas_id = mk.id) AS history_count
    FROM master_kelas mk ORDER BY mk.tingkat, mk.is_placeholder, mk.kode_rombel")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Master Kelas | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="stylesheet" href="assets/css/style.css?v=7.7">
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div class="topbar-title"><h2>Master Kelas &amp; Rombel</h2><span class="breadcrumb">SistemSPP / Data Master / Kelas</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

    <section class="main-card master-modern-shell">
      <div class="master-modern-hero">
        <div>
          <span class="recap-class-overline">Data Master</span>
          <h1>Master Kelas/Rombel</h1>
          <p>Atur rombel yang dipakai Data Siswa, pembayaran, laporan, dan histori tahun ajaran.</p>
        </div>
        <div class="master-modern-stats">
          <div><span>Total Rombel</span><strong><?= number_format(count($classes)) ?></strong></div>
          <div><span>Siswa Aktif</span><strong><?= number_format(array_sum(array_map(fn($row)=>(int)$row['siswa_count'], $classes))) ?></strong></div>
          <div><span>Histori</span><strong><?= number_format(array_sum(array_map(fn($row)=>(int)$row['history_count'], $classes))) ?></strong></div>
        </div>
      </div>
    </section>

    <div class="main-card master-modern-card master-modern-form">
      <div class="card-title-row"><div><div class="card-title"><?= $editClass ? 'Edit Rombel' : 'Tambah Rombel' ?></div><p class="payment-auto-note">Tingkat tetap 1–6. Kode rombel membentuk label seperti 1A, 1B, atau 2C.</p></div></div>
      <form method="post" class="report-filter-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
        <input type="hidden" name="aksi" value="<?= $editClass ? 'update' : 'tambah' ?>"><input type="hidden" name="id" value="<?= (int)($editClass['id'] ?? 0) ?>">
        <div class="field-row"><label class="field-label">Tingkat</label><select class="field-input field-select" name="tingkat" required><?php for($i=1;$i<=6;$i++): ?><option value="<?= $i ?>" <?= (int)($editClass['tingkat'] ?? 1)===$i?'selected':'' ?>>Kelas <?= $i ?></option><?php endfor; ?></select></div>
        <div class="field-row"><label class="field-label">Kode Rombel</label><input class="field-input" name="kode_rombel" maxlength="10" required placeholder="Contoh: A" value="<?= htmlspecialchars((string)($editClass['kode_rombel'] ?? '')) ?>" <?= $editClass && (int)$editClass['is_placeholder']===1?'disabled':'' ?>></div>
        <div class="report-filter-actions"><button class="btn btn-primary" type="submit" <?= $editClass && (int)$editClass['is_placeholder']===1?'disabled':'' ?>><?= $editClass?'Simpan Perubahan':'Tambah Rombel' ?></button><?php if($editClass): ?><a class="btn btn-ghost" href="master_kelas.php">Batal</a><?php endif; ?></div>
      </form>
    </div>

    <div class="main-card master-modern-card">
      <div class="card-title-row"><div><div class="card-title">Proses Tahun Ajaran</div><p class="payment-auto-note">Kenaikan dijalankan manual oleh admin. Rombel siswa tetap mengikuti huruf rombel yang sama.</p></div></div>
      <div class="action-bar">
        <form method="post" onsubmit="return confirm('Lengkapi template rombel A sampai J untuk kelas 1 sampai 6?')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
          <input type="hidden" name="aksi" value="template_aj">
          <button class="btn btn-ghost" type="submit">Lengkapi Template A-J</button>
        </form>
        <form method="post" onsubmit="return confirm('Proses kenaikan ke tahun ajaran <?= htmlspecialchars($nextAcademicYear) ?>? Siswa kelas 6 akan diarsipkan sebagai lulus.')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
          <input type="hidden" name="aksi" value="promosi_tahun">
          <input type="hidden" name="target_tahun_ajaran" value="<?= htmlspecialchars($nextAcademicYear) ?>">
          <button class="btn btn-primary" type="submit">Proses Kenaikan Tahun Ajaran</button>
        </form>
        <form method="post" onsubmit="return confirm('Nonaktifkan rombel kosong yang sedang aktif? Histori lama tetap aman.')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
          <input type="hidden" name="aksi" value="nonaktifkan_kosong">
          <button class="btn btn-warning" type="submit">Nonaktifkan Rombel Kosong</button>
        </form>
      </div>
    </div>

    <div class="main-card master-modern-card master-modern-list">
      <div class="card-title-row"><div><div class="card-title">Daftar Kelas/Rombel</div><p class="payment-auto-note">Placeholder menjaga data lama tetap valid sampai siswa dipindahkan ke rombel sebenarnya.</p></div></div>
      <div class="table-container"><table class="payment-table responsive-table"><thead><tr><th>No</th><th>Label</th><th>Tingkat</th><th>Status</th><th class="text-center">Siswa Aktif</th><th class="text-center">Histori</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach($classes as $i=>$class): $label=class_label($class); ?>
        <tr><td data-label="No"><?= $i+1 ?></td><td data-label="Label"><strong><?= htmlspecialchars($label) ?></strong></td><td data-label="Tingkat"><span class="kelas-badge">Kelas <?= (int)$class['tingkat'] ?></span></td><td data-label="Status"><span class="master-status <?= (int)$class['is_active']===1?'is-active':'is-inactive' ?>"><?= (int)$class['is_placeholder']===1?'Placeholder':((int)$class['is_active']===1?'Aktif':'Nonaktif') ?></span></td><td data-label="Siswa Aktif" class="text-center"><?= number_format((int)$class['siswa_count']) ?></td><td data-label="Histori" class="text-center"><?= number_format((int)$class['history_count']) ?></td><td data-label="Aksi" class="aksi-col">
        <?php if((int)$class['is_placeholder']!==1): ?><a class="btn-tbl btn-tbl-edit" href="master_kelas.php?edit=<?= (int)$class['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>"><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$class['id'] ?>"><button class="btn-tbl btn-tbl-toggle" type="submit"><?= (int)$class['is_active']===1?'Nonaktifkan':'Aktifkan' ?></button></form><?php else: ?><span class="payment-auto-note">Dikelola sistem</span><?php endif; ?>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </div>
  </main>
</div>
<script src="assets/js/app.js?v=6.5"></script><script>document.addEventListener('DOMContentLoaded',function(){autoHideFlash();});</script>
</body></html>
