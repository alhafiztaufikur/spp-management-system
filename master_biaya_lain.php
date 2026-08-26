<?php
require_once __DIR__ . '/includes/security.php';
security_bootstrap_session();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/biaya_lain.php';
requireRole(['admin']);

$_SESSION['csrf_master_biaya_lain'] = security_csrf_token('master-other-fees');

function master_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    return (float)str_replace(['.', ','], ['', '.'], (string)$value);
}

function master_redirect(): void {
    header('Location: master_biaya_lain.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!security_csrf_is_valid('master-other-fees', $_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
        master_redirect();
    }
    $aksi = security_input_scalar($_POST, 'aksi');

    if ($aksi === 'terbitkan_tagihan') {
        try {
            $masterId = (int)security_input_scalar($_POST, 'master_id', 0);
            $target = (string)security_input_scalar($_POST, 'target', 'all');
            if (!in_array($target, ['all', 'tingkat', 'rombel', 'siswa'], true)) throw new RuntimeException('Target penerbitan tidak valid.');

            $koneksi->begin_transaction();
            $stmt = $koneksi->prepare('SELECT id, nama, nominal FROM master_biaya_lain WHERE id=? AND is_active=1 AND nominal>0 FOR UPDATE');
            $stmt->bind_param('i', $masterId); $stmt->execute();
            $master = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$master) throw new RuntimeException('Master biaya tidak aktif atau tidak ditemukan.');

            $where = ['s.is_active=1']; $types = ''; $params = []; $targetValue = null;
            if ($target === 'tingkat') {
                $level = (int)security_input_scalar($_POST, 'tingkat', 0);
                if ($level < 1 || $level > 6) throw new RuntimeException('Pilih tingkat kelas 1 sampai 6.');
                $where[] = 's.KELAS=?'; $types .= 'i'; $params[] = $level; $targetValue = (string)$level;
            } elseif ($target === 'rombel') {
                $classId = (int)security_input_scalar($_POST, 'master_kelas_id', 0);
                if ($classId <= 0) throw new RuntimeException('Pilih rombel tujuan.');
                $where[] = 's.master_kelas_id=?'; $types .= 'i'; $params[] = $classId; $targetValue = (string)$classId;
            } elseif ($target === 'siswa') {
                $rawStudents = $_POST['no_induk'] ?? [];
                $rawStudents = is_array($rawStudents) ? $rawStudents : [];
                $students = [];
                foreach ($rawStudents as $rawStudent) {
                    if (is_scalar($rawStudent) && trim((string)$rawStudent) !== '') $students[] = trim((string)$rawStudent);
                }
                $students = array_values(array_unique($students));
                if (!$students) throw new RuntimeException('Pilih minimal satu siswa.');
                $where[] = 's.NO_INDUK IN (' . implode(',', array_fill(0, count($students), '?')) . ')';
                $types .= str_repeat('s', count($students)); $params = array_merge($params, $students);
                $targetValue = implode(',', $students);
            }

            $sql = "SELECT s.NO_INDUK,s.master_kelas_id,s.KELAS,mk.tingkat,mk.kode_rombel,mk.is_placeholder
                FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE " . implode(' AND ', $where) . ' ORDER BY s.NAMA';
            $stmt = $koneksi->prepare($sql);
            if ($types !== '') $stmt->bind_param($types, ...$params);
            $stmt->execute(); $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            if (!$students) throw new RuntimeException('Tidak ada siswa aktif pada target yang dipilih.');

            $inserted = 0;
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            $insert = $koneksi->prepare("INSERT IGNORE INTO tagihan_biaya_lain
                (master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,status,created_by)
                VALUES (?,?,NULLIF(?,0),?,?,?,'open',NULLIF(?,0))");
            foreach ($students as $student) {
                $noInduk = (string)$student['NO_INDUK'];
                $classId = (int)($student['master_kelas_id'] ?? 0);
                $classSnapshot = class_label([
                    'tingkat' => $student['tingkat'] ?? $student['KELAS'],
                    'kode_rombel' => $student['kode_rombel'] ?? 'BELUM',
                    'is_placeholder' => $student['is_placeholder'] ?? 1,
                ]);
                $name = (string)$master['nama']; $amount = (float)$master['nominal'];
                $insert->bind_param('isisdsi', $masterId, $noInduk, $classId, $name, $amount, $classSnapshot, $adminId);
                $insert->execute(); $inserted += $insert->affected_rows > 0 ? 1 : 0;
            }
            $insert->close();
            other_fee_write_audit($koneksi, $masterId, $target, $targetValue, $inserted, $inserted * (float)$master['nominal']);
            $koneksi->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $inserted > 0
                ? $inserted . ' tagihan ' . $master['nama'] . ' berhasil diterbitkan.'
                : 'Semua siswa pada target tersebut sudah memiliki tagihan. Tidak ada duplikasi dibuat.'];
        } catch (Throwable $error) {
            if ($koneksi->errno || $koneksi->thread_id) { try { $koneksi->rollback(); } catch (Throwable $ignored) {} }
            $_SESSION['flash'] = ['type' => 'error', 'msg' => security_exception_message($error, 'Tagihan biaya lain gagal diterbitkan.', 'master-other-fees')];
        }
        master_redirect();
    }

    if ($aksi === 'tambah' || $aksi === 'update') {
        $id = (int)security_input_scalar($_POST, 'id', 0);
        $nama = trim((string)security_input_scalar($_POST, 'nama'));
        $nominal = master_amount(security_input_scalar($_POST, 'nominal', 0));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($nama === '' || mb_strlen($nama) > 100) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nama biaya wajib diisi dan maksimal 100 karakter.'];
            master_redirect();
        }
        if ($nominal <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nominal biaya harus lebih dari Rp 0.'];
            master_redirect();
        }

        $stmtDuplikat = $koneksi->prepare('SELECT id FROM master_biaya_lain WHERE LOWER(nama) = LOWER(?) AND id <> ? LIMIT 1');
        $stmtDuplikat->bind_param('si', $nama, $id);
        $stmtDuplikat->execute();
        $duplikat = $stmtDuplikat->get_result()->fetch_assoc();
        $stmtDuplikat->close();

        if ($duplikat) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nama biaya tersebut sudah tersedia di master.'];
            master_redirect();
        }

        if ($aksi === 'tambah') {
            $stmt = $koneksi->prepare('INSERT INTO master_biaya_lain (nama, nominal, is_active) VALUES (?, ?, ?)');
            $stmt->bind_param('sdi', $nama, $nominal, $isActive);
            $berhasil = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $berhasil
                ? ['type' => 'success', 'msg' => "Biaya $nama berhasil ditambahkan."]
                : ['type' => 'error', 'msg' => 'Master biaya gagal ditambahkan.'];
        } else {
            if ($id <= 0) master_redirect();
            $stmt = $koneksi->prepare('UPDATE master_biaya_lain SET nama = ?, nominal = ?, is_active = ? WHERE id = ?');
            $stmt->bind_param('sdii', $nama, $nominal, $isActive, $id);
            $berhasil = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $berhasil
                ? ['type' => 'success', 'msg' => "Biaya $nama berhasil diperbarui. Transaksi lama tetap memakai tarif sebelumnya."]
                : ['type' => 'error', 'msg' => 'Master biaya gagal diperbarui.'];
        }
        master_redirect();
    }

    if ($aksi === 'toggle') {
        $id = (int)security_input_scalar($_POST, 'id', 0);
        $stmt = $koneksi->prepare('UPDATE master_biaya_lain SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status master biaya berhasil diubah.'];
        master_redirect();
    }

    if ($aksi === 'hapus') {
        $id = (int)security_input_scalar($_POST, 'id', 0);
        $stmtCount = $koneksi->prepare('SELECT (SELECT COUNT(*) FROM bayar_biaya_lain WHERE master_biaya_lain_id=?) + (SELECT COUNT(*) FROM tagihan_biaya_lain WHERE master_biaya_lain_id=?) AS jumlah');
        $stmtCount->bind_param('ii', $id, $id);
        $stmtCount->execute();
        $jumlahPemakaian = (int)$stmtCount->get_result()->fetch_assoc()['jumlah'];
        $stmtCount->close();

        if ($jumlahPemakaian > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Master sudah dipakai pada transaksi dan tidak dapat dihapus. Nonaktifkan agar tidak muncul di pembayaran baru.'];
        } else {
            $stmt = $koneksi->prepare('DELETE FROM master_biaya_lain WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Master biaya berhasil dihapus.'];
        }
        master_redirect();
    }
}

$editData = null;
$editId = (int)security_input_scalar($_GET, 'edit', 0);
if ($editId > 0) {
    $stmt = $koneksi->prepare('SELECT * FROM master_biaya_lain WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$masterList = $koneksi->query("
    SELECT m.*, COUNT(DISTINCT d.id) AS jumlah_pemakaian, COUNT(DISTINCT t.id) AS jumlah_tagihan,
           COALESCE(SUM(DISTINCT CASE WHEN t.id IS NOT NULL THEN t.nominal_tagihan ELSE 0 END),0) AS nominal_tagihan
    FROM master_biaya_lain m
    LEFT JOIN bayar_biaya_lain d ON d.master_biaya_lain_id = m.id
    LEFT JOIN tagihan_biaya_lain t ON t.master_biaya_lain_id = m.id
    GROUP BY m.id
    ORDER BY m.is_active DESC, m.nama ASC
");
$activeMasters = $koneksi->query("SELECT id,nama,nominal FROM master_biaya_lain WHERE is_active=1 ORDER BY nama")->fetch_all(MYSQLI_ASSOC);
$activeClasses = class_all($koneksi, true);
$activeStudents = $koneksi->query("SELECT s.NO_INDUK,s.NAMA,s.KELAS,s.master_kelas_id,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1 ORDER BY s.NAMA")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Master Biaya Lain | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=4.7" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Toggle Sidebar" aria-label="Buka navigasi" aria-expanded="false">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2>Master Biaya Lain</h2><span class="breadcrumb">SistemSPP / Master Biaya Lain</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title"><?= $editData ? 'Edit Master Biaya' : 'Tambah Master Biaya' ?></div>
        </div>
        <form method="POST" action="master_biaya_lain.php" id="form-master-biaya">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
          <input type="hidden" name="aksi" value="<?= $editData ? 'update' : 'tambah' ?>" />
          <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>" />
          <div class="fields-grid master-fee-form">
            <div class="field-row">
              <label class="field-label" for="nama-biaya">Nama Biaya</label>
              <input class="field-input" id="nama-biaya" name="nama" maxlength="100" required
                placeholder="Contoh: Biaya Ekstrakurikuler" value="<?= htmlspecialchars($editData['nama'] ?? '') ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="nominal-biaya">Nominal</label>
              <input class="field-input rupiah-input" id="nominal-biaya" name="nominal" inputmode="numeric" required
                placeholder="Rp 0" value="<?= $editData ? number_format((float)$editData['nominal'], 0, ',', '.') : '' ?>" />
            </div>
            <label class="master-active-check">
              <input type="checkbox" name="is_active" value="1" <?= !$editData || (int)$editData['is_active'] === 1 ? 'checked' : '' ?> />
              <span>Aktif dan tampil di pembayaran</span>
            </label>
          </div>
          <div class="action-bar" style="margin-top:16px">
            <button type="submit" class="btn btn-primary"><?= $editData ? 'Simpan Perubahan' : 'Tambah Biaya' ?></button>
            <?php if ($editData): ?><a href="master_biaya_lain.php" class="btn btn-ghost">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="main-card" style="margin-top:0">
        <div class="card-title-row"><div><div class="card-title">Terbitkan Tagihan Biaya Lain</div><p class="payment-auto-note">Tagihan memakai snapshot nama dan nominal saat diterbitkan. Penerbitan ulang tidak membuat duplikasi.</p></div></div>
        <?php if (!$activeMasters): ?>
          <div class="empty-state"><p>Belum ada master biaya aktif</p><span>Aktifkan atau tambahkan master biaya terlebih dahulu.</span></div>
        <?php else: ?>
        <form method="post" id="form-terbit-biaya" onsubmit="return confirm('Terbitkan tagihan ke target yang dipilih?')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>">
          <input type="hidden" name="aksi" value="terbitkan_tagihan">
          <div class="report-filter-grid">
            <div class="field-row"><label class="field-label">Item Biaya</label><select class="field-input field-select" name="master_id" id="publish-fee" required><?php foreach($activeMasters as $master): ?><option value="<?= (int)$master['id'] ?>" data-nominal="<?= (float)$master['nominal'] ?>"><?= htmlspecialchars($master['nama']) ?> — Rp <?= number_format((float)$master['nominal'],0,',','.') ?></option><?php endforeach; ?></select></div>
            <div class="field-row"><label class="field-label">Target</label><select class="field-input field-select" name="target" id="publish-target"><option value="all">Semua siswa aktif</option><option value="tingkat">Tingkat kelas</option><option value="rombel">Rombel tertentu</option><option value="siswa">Pilih siswa</option></select></div>
            <div class="field-row publish-target-field" data-target="tingkat" hidden><label class="field-label">Tingkat</label><select class="field-input field-select" name="tingkat" id="publish-level"><?php for($i=1;$i<=6;$i++): ?><option value="<?= $i ?>">Kelas <?= $i ?></option><?php endfor; ?></select></div>
            <div class="field-row publish-target-field" data-target="rombel" hidden><label class="field-label">Rombel</label><select class="field-input field-select" name="master_kelas_id" id="publish-class"><?php foreach($activeClasses as $class): ?><option value="<?= (int)$class['id'] ?>"><?= htmlspecialchars(class_label($class)) ?></option><?php endforeach; ?></select></div>
            <div class="field-row publish-target-field" data-target="siswa" hidden><label class="field-label">Siswa (bisa lebih dari satu)</label><select class="field-input field-select" name="no_induk[]" id="publish-students" multiple size="6"><?php foreach($activeStudents as $student): ?><option value="<?= htmlspecialchars($student['NO_INDUK']) ?>"><?= htmlspecialchars($student['NO_INDUK'].' — '.$student['NAMA'].' — '.class_label($student)) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="report-summary-grid" style="margin-top:16px"><div class="report-summary-card"><span>Pratinjau Siswa</span><strong id="publish-preview-count">0</strong></div><div class="report-summary-card"><span>Total Nominal</span><strong id="publish-preview-total">Rp 0</strong></div></div>
          <div class="action-bar" style="margin-top:16px"><button class="btn btn-primary" type="submit">Terbitkan Tagihan</button></div>
        </form>
        <?php endif; ?>
      </div>

      <div class="main-card" style="margin-top:0">
        <div class="card-title-row"><div class="card-title">Daftar Master Biaya (<?= $masterList->num_rows ?>)</div></div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>Nama Biaya</th><th>Nominal</th><th>Status</th><th>Tagihan</th><th>Transaksi</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php if ($masterList->num_rows === 0): ?>
              <tr><td colspan="7"><div class="empty-state"><p>Belum ada master biaya lain</p><span>Tambahkan biaya dari formulir di atas.</span></div></td></tr>
              <?php else: $no = 1; while ($item = $masterList->fetch_assoc()): ?>
              <tr>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="Nama Biaya"><strong><?= htmlspecialchars($item['nama']) ?></strong></td>
                <td data-label="Nominal" class="nominal">Rp <?= number_format((float)$item['nominal'], 0, ',', '.') ?></td>
                <td data-label="Status"><span class="master-status <?= $item['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $item['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td data-label="Tagihan"><span class="badge-count"><?= (int)$item['jumlah_tagihan'] ?></span></td>
                <td data-label="Transaksi"><span class="badge-count"><?= (int)$item['jumlah_pemakaian'] ?>x</span></td>
                <td data-label="Aksi" class="aksi-col">
                  <a class="btn-tbl btn-tbl-edit" href="master_biaya_lain.php?edit=<?= (int)$item['id'] ?>">Edit</a>
                  <form method="POST" action="master_biaya_lain.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
                    <input type="hidden" name="aksi" value="toggle" /><input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
                    <button class="btn-tbl btn-tbl-toggle" type="submit"><?= $item['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                  </form>
                  <form method="POST" action="master_biaya_lain.php" style="display:inline" onsubmit="return confirm('Hapus master biaya <?= htmlspecialchars(addslashes($item['nama'])) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
                    <input type="hidden" name="aksi" value="hapus" /><input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
                    <button class="btn-tbl btn-tbl-del" type="submit" <?= ((int)$item['jumlah_pemakaian'] > 0 || (int)$item['jumlah_tagihan'] > 0) ? 'disabled title="Sudah memiliki tagihan atau transaksi"' : '' ?>>Hapus</button>
                  </form>
                </td>
              </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
  <script src="assets/js/app.js?v=3.1"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var nominal = document.getElementById('nominal-biaya');
      if (nominal) nominal.addEventListener('input', function () {
        var clean = this.value.replace(/\D/g, '');
        this.value = clean ? Number(clean).toLocaleString('id-ID') : '';
      });
      document.getElementById('form-master-biaya')?.addEventListener('submit', function () {
        nominal.value = nominal.value.replace(/\./g, '');
      });
      var students = <?= json_encode(array_map(static function($student){ return ['nis'=>$student['NO_INDUK'],'tingkat'=>(int)$student['KELAS'],'kelas_id'=>(int)($student['master_kelas_id']??0)]; }, $activeStudents), JSON_UNESCAPED_UNICODE) ?>;
      var target = document.getElementById('publish-target');
      var fee = document.getElementById('publish-fee');
      var level = document.getElementById('publish-level');
      var classSelect = document.getElementById('publish-class');
      var studentSelect = document.getElementById('publish-students');
      function updatePublishPreview() {
        if (!target || !fee) return;
        document.querySelectorAll('.publish-target-field').forEach(function(field){ field.hidden = field.dataset.target !== target.value; });
        var count = students.length;
        if (target.value === 'tingkat') count = students.filter(function(s){ return s.tingkat === Number(level.value); }).length;
        if (target.value === 'rombel') count = students.filter(function(s){ return s.kelas_id === Number(classSelect.value); }).length;
        if (target.value === 'siswa') count = Array.from(studentSelect.selectedOptions).length;
        var amount = Number(fee.options[fee.selectedIndex]?.dataset.nominal || 0);
        document.getElementById('publish-preview-count').textContent = count.toLocaleString('id-ID') + ' siswa';
        document.getElementById('publish-preview-total').textContent = 'Rp ' + (count * amount).toLocaleString('id-ID');
      }
      [target,fee,level,classSelect,studentSelect].forEach(function(el){ el?.addEventListener('change',updatePublishPreview); });
      updatePublishPreview();
      autoHideFlash();
    });
  </script>
</body>
</html>
