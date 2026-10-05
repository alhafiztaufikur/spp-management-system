<?php
// ============================================
// role_management.php - Manajemen Akun Petugas
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['super_admin']);
$accountScopeUnit = unit_active_id();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken   = $_SESSION['csrf_token'];
$allowedRole = ['super_admin', 'bendahara', 'kasir'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function setAccountFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

function validPassword(string $password): bool {
    $length = strlen($password);
    return $length >= 8 && $length <= 72;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        setAccountFlash('error', 'Permintaan tidak valid. Silakan muat ulang halaman dan coba lagi.');
        header('Location: role_management.php');
        exit;
    }

    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah') {
        $nama                 = trim($_POST['nama'] ?? '');
        $username             = trim($_POST['username'] ?? '');
        $role                 = $_POST['role'] ?? '';
        $unitId               = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);
        $password             = $_POST['password'] ?? '';
        $passwordConfirmation = $_POST['password_confirmation'] ?? '';

        if (strlen($nama) < 3 || strlen($nama) > 100) {
            setAccountFlash('error', 'Nama lengkap harus berisi 3 sampai 100 karakter.');
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            setAccountFlash('error', 'Username harus 3–50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
        } elseif (!in_array($role, $allowedRole, true)) {
            setAccountFlash('error', 'Role akun tidak valid.');
        } elseif ($accountScopeUnit !== 0 && ($role === 'super_admin' || $unitId !== $accountScopeUnit)) {
            setAccountFlash('error', 'Pilih unit yang sesuai di sidebar. Untuk akun Super Admin, pilih Semua Unit.');
        } elseif ($role === 'super_admin' ? $unitId !== 0 : !in_array($unitId, [1,2,3], true)) {
            setAccountFlash('error', $role === 'super_admin' ? 'Super Admin harus memakai cakupan Semua Unit.' : 'Pilih unit SD, SMP, atau SMA.');
        } elseif (!validPassword($password)) {
            setAccountFlash('error', 'Password harus berisi 8 sampai 72 karakter.');
        } elseif ($password !== $passwordConfirmation) {
            setAccountFlash('error', 'Konfirmasi password tidak sama.');
        } else {
            $check = $koneksi->prepare("SELECT id FROM admin WHERE username = ? LIMIT 1");
            $check->bind_param('s', $username);
            $check->execute();
            $usernameExists = (bool)$check->get_result()->fetch_assoc();
            $check->close();

            if ($usernameExists) {
                setAccountFlash('error', 'Username sudah digunakan. Silakan pilih username lain.');
            } else {
                if ($role === 'super_admin') $unitId = null;
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $koneksi->prepare("INSERT INTO admin (username, password, nama, role, unit_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssi', $username, $passwordHash, $nama, $role, $unitId);

                if ($stmt->execute()) {
                    setAccountFlash('success', "Akun {$nama} berhasil dibuat sebagai {$role}.");
                } else {
                    setAccountFlash('error', 'Akun gagal dibuat. Silakan coba lagi.');
                }
                $stmt->close();
            }
        }

        header('Location: role_management.php');
        exit;
    }

    if ($aksi === 'reset_password') {
        $accountId            = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $password             = $_POST['new_password'] ?? '';
        $passwordConfirmation = $_POST['new_password_confirmation'] ?? '';

        if (!$accountId) {
            setAccountFlash('error', 'Akun yang dipilih tidak valid.');
        } elseif (!validPassword($password)) {
            setAccountFlash('error', 'Password baru harus berisi 8 sampai 72 karakter.');
        } elseif ($password !== $passwordConfirmation) {
            setAccountFlash('error', 'Konfirmasi password baru tidak sama.');
        } else {
            $check = $koneksi->prepare("SELECT nama, role, unit_id FROM admin WHERE id = ? LIMIT 1");
            $check->bind_param('i', $accountId);
            $check->execute();
            $account = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$account) {
                setAccountFlash('error', 'Akun tidak ditemukan.');
            } elseif ($accountScopeUnit !== 0 && (int)$account['unit_id'] !== $accountScopeUnit) {
                setAccountFlash('error', 'Akun ini berada di luar unit yang dipilih.');
            } elseif ($account['role'] === 'admin') {
                setAccountFlash('error', 'Akun Admin unit lama sudah diarsipkan.');
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $koneksi->prepare("UPDATE admin SET password = ? WHERE id = ?");
                $stmt->bind_param('si', $passwordHash, $accountId);

                if ($stmt->execute()) {
                    setAccountFlash('success', "Password akun {$account['nama']} berhasil diganti.");
                } else {
                    setAccountFlash('error', 'Password gagal diganti. Silakan coba lagi.');
                }
                $stmt->close();
            }
        }

        header('Location: role_management.php');
        exit;
    }

    if ($aksi === 'status') {
        $accountId = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $targetActive = filter_input(INPUT_POST, 'target_active', FILTER_VALIDATE_INT);
        $currentAdminId = (int)($_SESSION['admin_id'] ?? 0);

        if (!$accountId || !in_array($targetActive, [0,1], true)) {
            setAccountFlash('error', 'Akun yang dipilih tidak valid.');
        } elseif ($accountId === $currentAdminId && $targetActive === 0) {
            setAccountFlash('error', 'Anda tidak dapat menonaktifkan akun yang sedang dipakai.');
        } else {
            $koneksi->begin_transaction();
            $check = $koneksi->prepare("SELECT id, nama, username, role, unit_id, is_active FROM admin WHERE id = ? LIMIT 1 FOR UPDATE");
            $check->bind_param('i', $accountId);
            $check->execute();
            $account = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$account) {
                setAccountFlash('error', 'Akun tidak ditemukan.');
                $koneksi->rollback();
            } elseif ($accountScopeUnit !== 0 && (int)$account['unit_id'] !== $accountScopeUnit) {
                setAccountFlash('error', 'Akun ini berada di luar unit yang dipilih.');
                $koneksi->rollback();
            } elseif ($account['role'] === 'admin' && $targetActive === 1) {
                setAccountFlash('error', 'Akun Admin unit lama diarsipkan. Kelola seluruh unit melalui Super Admin.');
                $koneksi->rollback();
            } else {
                if ($targetActive === 0 && (int)$account['is_active'] === 1 && $account['role'] === 'super_admin') {
                    $roleToCount=$account['role']; $unitToCount=$account['unit_id'];
                    $count=$koneksi->prepare('SELECT id FROM admin WHERE role=? AND is_active=1 AND (unit_id<=>?) FOR UPDATE');
                    $count->bind_param('si',$roleToCount,$unitToCount);$count->execute();
                    $remaining=$count->get_result()->num_rows;$count->close();
                    if ($remaining <= 1) {
                        $koneksi->rollback();
                        setAccountFlash('error', 'Super Admin aktif terakhir tidak dapat dinonaktifkan.');
                        header('Location: role_management.php'); exit;
                    }
                }

                $stmt = $koneksi->prepare("UPDATE admin SET is_active=? WHERE id=?");
                $stmt->bind_param('ii', $targetActive, $accountId);

                if ($stmt->execute()) {
                    $koneksi->commit();
                    setAccountFlash('success', "Akun {$account['nama']} (@{$account['username']}) berhasil " . ($targetActive ? 'diaktifkan.' : 'dinonaktifkan.'));
                } else {
                    $koneksi->rollback();
                    setAccountFlash('error', 'Status akun gagal diperbarui.');
                }
                $stmt->close();
            }
        }

        header('Location: role_management.php');
        exit;
    }

    setAccountFlash('error', 'Aksi tidak dikenali.');
    header('Location: role_management.php');
    exit;
}

$accounts = $koneksi->query(
    "SELECT id, username, nama, role, unit_id, is_active, created_at FROM admin WHERE role<>'admin' "
    . ($accountScopeUnit ? 'AND unit_id='.(int)$accountScopeUnit.' ' : '')
    . "ORDER BY unit_id,FIELD(role,'super_admin','admin','bendahara','kasir'),nama ASC"
);
$roleLabels = ['super_admin'=>'Super Admin','admin' => 'Admin', 'bendahara' => 'Bendahara', 'kasir' => 'Kasir'];
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Role Management | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png?v=2" />
  <meta name="description" content="Super Admin mengelola akun petugas SD, SMP, dan SMA." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" />
  <link rel="stylesheet" href="assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/date_controls.css') ?>" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Toggle Sidebar">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Role Management</h2>
          <span class="breadcrumb">SistemSPP / Role Management</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($flash['type'] === 'success'): ?><polyline points="20 6 9 17 4 12"/>
          <?php else: ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Buat Akun Petugas
          </div>
        </div>
        <p class="field-hint">Hanya Super Admin yang membuat akun. Saat memilih <?= htmlspecialchars(unit_label($accountScopeUnit)) ?>, akun baru mengikuti cakupan ini. Akun Admin unit lama diarsipkan.</p>

        <form method="POST" action="role_management.php" id="form-tambah-akun">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <input type="hidden" name="aksi" value="tambah" />
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="nama">Nama Lengkap</label>
              <input class="field-input" type="text" id="nama" name="nama" minlength="3" maxlength="100" placeholder="Nama petugas" required autocomplete="name" />
            </div>
            <div class="field-row">
              <label class="field-label" for="username">Username</label>
              <input class="field-input" type="text" id="username" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" placeholder="Contoh: kasir.andi" required autocomplete="username" />
              <span class="field-hint">Huruf, angka, titik, garis bawah, atau tanda hubung.</span>
            </div>
            <div class="field-row">
              <label class="field-label" for="role">Role</label>
              <select class="field-input field-select" id="role" name="role" required>
                <option value="">-- Pilih Role --</option>
                <option value="kasir">Kasir</option>
                <option value="bendahara">Bendahara</option>
                <?php if ($accountScopeUnit === 0): ?><option value="super_admin">Super Admin</option><?php endif; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="account-unit">Unit</label>
              <select class="field-input field-select" id="account-unit" name="unit_id" required>
                <?php if ($accountScopeUnit === 0): ?><option value="">-- Pilih Unit --</option><option value="0">Semua Unit (Super Admin)</option><?php endif; ?>
                <?php foreach (($accountScopeUnit === 0 ? [1=>'SD',2=>'SMP',3=>'SMA'] : [$accountScopeUnit=>unit_label($accountScopeUnit)]) as $id=>$label): ?>
                <option value="<?= $id ?>" <?= $accountScopeUnit===$id?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="password-baru">Password</label>
              <div class="password-field-wrap">
                <input class="field-input" type="password" id="password-baru" name="password" minlength="8" maxlength="72" placeholder="Minimal 8 karakter" required autocomplete="new-password" />
                <button type="button" class="toggle-pw" data-toggle-password="password-baru" title="Tampilkan password" aria-label="Tampilkan password">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="field-row">
              <label class="field-label" for="password-konfirmasi">Konfirmasi Password</label>
              <div class="password-field-wrap">
                <input class="field-input" type="password" id="password-konfirmasi" name="password_confirmation" minlength="8" maxlength="72" placeholder="Ulangi password" required autocomplete="new-password" />
                <button type="button" class="toggle-pw" data-toggle-password="password-konfirmasi" title="Tampilkan password" aria-label="Tampilkan password">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
          </div>
          <div class="action-bar">
            <button type="submit" class="btn btn-primary" id="btn-tambah-akun">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
              Buat Akun
            </button>
          </div>
        </form>
      </div>

      <div class="main-card" style="margin-top:0">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
            Daftar Akun (<?= $accounts->num_rows ?> akun)
          </div>
        </div>
        <div class="account-unit-filter">Menampilkan akun: <strong><?= htmlspecialchars(unit_label($accountScopeUnit)) ?></strong>. Ganti unit melalui pilihan di sidebar.</div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead>
              <tr><th>Akun</th><th>Unit</th><th>Role</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              <?php while ($account = $accounts->fetch_assoc()): ?>
              <tr>
                <td data-label="Akun">
                  <div class="account-summary">
                    <span class="account-avatar"><?= htmlspecialchars(strtoupper(substr($account['nama'], 0, 2))) ?></span>
                    <span>
                      <span class="account-name"><?= htmlspecialchars($account['nama']) ?></span>
                      <span class="account-username">@<?= htmlspecialchars($account['username']) ?></span>
                    </span>
                  </div>
                </td>
                <td data-label="Unit"><span class="unit-pill"><?= unit_label((int)($account['unit_id'] ?? 0)) ?></span></td>
                <td data-label="Role"><span class="badge-role badge-role-<?= htmlspecialchars($account['role']) ?>"><?= htmlspecialchars($roleLabels[$account['role']] ?? $account['role']) ?></span></td>
                <td data-label="Status"><?= (int)$account['is_active']===1?'Aktif':'Nonaktif' ?></td>
                <td data-label="Dibuat"><?= spp_date_label($account['created_at']) ?></td>
                <td data-label="Aksi" class="aksi-col">
                  <div class="savings-row-actions">
                    <?php if ($account['role'] !== 'admin'): ?>
                    <button type="button" class="btn-tbl btn-tbl-edit btn-reset-password"
                      data-account-id="<?= (int)$account['id'] ?>"
                      data-account-name="<?= htmlspecialchars($account['nama']) ?>"
                      data-account-username="<?= htmlspecialchars($account['username']) ?>">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                      Ganti Password
                    </button>
                    <?php endif; ?>
                    <?php if ((int)$account['id'] !== (int)($_SESSION['admin_id'] ?? 0) && (int)$account['is_active']===1): ?>
                    <button type="button" class="btn-tbl btn-tbl-del btn-delete-account"
                      data-account-id="<?= (int)$account['id'] ?>"
                      data-account-name="<?= htmlspecialchars($account['nama']) ?>"
                      data-account-username="<?= htmlspecialchars($account['username']) ?>"
                      title="Nonaktifkan akun">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                      Nonaktifkan
                    </button>
                    <?php elseif ((int)$account['is_active']===0 && $account['role'] !== 'admin'): ?>
                    <form method="post" action="role_management.php" class="account-activate-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                      <input type="hidden" name="aksi" value="status">
                      <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                      <input type="hidden" name="target_active" value="1">
                      <button class="btn-tbl btn-tbl-edit" type="submit">Aktifkan</button>
                    </form>
                    <?php elseif ($account['role'] === 'admin'): ?>
                    <span class="account-self-badge">Arsip Admin unit</span>
                    <?php else: ?>
                    <span class="account-self-badge">👤 Akun Anda</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div class="modal-overlay" id="reset-password-modal" role="dialog" aria-modal="true" aria-labelledby="reset-modal-title">
    <div class="modal-box">
      <div class="modal-icon">🔐</div>
      <div class="modal-title" id="reset-modal-title">Ganti Password Akun</div>
      <div class="modal-account" id="reset-account-label"></div>
      <form method="POST" action="role_management.php" id="form-reset-password">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="aksi" value="reset_password" />
        <input type="hidden" name="account_id" id="reset-account-id" />
        <div class="modal-form-fields">
          <div class="field-row">
            <label class="field-label" for="new-password">Password Baru</label>
            <div class="password-field-wrap">
              <input class="field-input" type="password" id="new-password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="Minimal 8 karakter" />
              <button type="button" class="toggle-pw" data-toggle-password="new-password" title="Tampilkan password" aria-label="Tampilkan password">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <div class="field-row">
            <label class="field-label" for="new-password-confirmation">Konfirmasi Password Baru</label>
            <input class="field-input" type="password" id="new-password-confirmation" name="new_password_confirmation" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="Ulangi password baru" />
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" id="btn-cancel-reset">Batal</button>
          <button type="submit" class="btn btn-warning">Simpan Password</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="delete-account-modal" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title">
    <div class="modal-box">
      <div class="modal-icon">⚠️</div>
      <div class="modal-title" id="delete-modal-title">Konfirmasi Nonaktifkan Akun</div>
      <p style="color:var(--text-secondary);margin:12px 0 6px;font-size:13px;">Akun berikut tidak dapat login sampai diaktifkan kembali.</p>
      <div class="modal-account" id="delete-account-label" style="font-weight:700;color:var(--red);"></div>
      <p class="payment-auto-note" style="margin-top:8px;">Riwayat transaksi dan audit tetap tersimpan.</p>
      <form method="POST" action="role_management.php" id="form-delete-account" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
        <input type="hidden" name="aksi" value="status" />
        <input type="hidden" name="target_active" value="0" />
        <input type="hidden" name="account_id" id="delete-account-id" />
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" id="btn-cancel-delete">Batal</button>
          <button type="submit" class="btn btn-error" style="background:var(--red);color:#fff;border:none;">Nonaktifkan Akun</button>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
  <script>
    (function () {
      const modal = document.getElementById('reset-password-modal');
      const resetForm = document.getElementById('form-reset-password');
      const deleteModal = document.getElementById('delete-account-modal');
      const deleteForm = document.getElementById('form-delete-account');

      document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
          const input = document.getElementById(button.dataset.togglePassword);
          if (!input) return;
          input.type = input.type === 'password' ? 'text' : 'password';
          button.setAttribute('aria-label', input.type === 'password' ? 'Tampilkan password' : 'Sembunyikan password');
        });
      });

      document.querySelectorAll('.btn-reset-password').forEach(function (button) {
        button.addEventListener('click', function () {
          resetForm.reset();
          document.getElementById('reset-account-id').value = button.dataset.accountId;
          document.getElementById('reset-account-label').textContent =
            button.dataset.accountName + ' (@' + button.dataset.accountUsername + ')';
          modal.classList.add('show');
          document.getElementById('new-password').focus();
        });
      });

      document.querySelectorAll('.btn-delete-account').forEach(function (button) {
        button.addEventListener('click', function () {
          deleteForm.reset();
          document.getElementById('delete-account-id').value = button.dataset.accountId;
          document.getElementById('delete-account-label').textContent =
            button.dataset.accountName + ' (@' + button.dataset.accountUsername + ')';
          deleteModal.classList.add('show');
        });
      });

      function closeResetModal() {
        modal.classList.remove('show');
        resetForm.reset();
      }

      function closeDeleteModal() {
        deleteModal.classList.remove('show');
        deleteForm.reset();
      }

      document.getElementById('btn-cancel-reset').addEventListener('click', closeResetModal);
      document.getElementById('btn-cancel-delete').addEventListener('click', closeDeleteModal);
      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeResetModal();
      });
      deleteModal.addEventListener('click', function (event) {
        if (event.target === deleteModal) closeDeleteModal();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          if (modal.classList.contains('show')) closeResetModal();
          if (deleteModal.classList.contains('show')) closeDeleteModal();
        }
      });

      resetForm.addEventListener('submit', function (event) {
        const password = document.getElementById('new-password').value;
        const confirmation = document.getElementById('new-password-confirmation').value;
        if (password !== confirmation) {
          event.preventDefault();
          document.getElementById('new-password-confirmation').setCustomValidity('Konfirmasi password tidak sama.');
          document.getElementById('new-password-confirmation').reportValidity();
        }
      });

      document.getElementById('new-password-confirmation').addEventListener('input', function () {
        this.setCustomValidity('');
      });

      document.getElementById('role').addEventListener('change', function () {
        const unit = document.getElementById('account-unit');
        if (this.value === 'super_admin') unit.value = '0';
        else if (unit.value === '0') unit.value = '';
      });

      document.getElementById('form-tambah-akun').addEventListener('submit', function (event) {
        const password = document.getElementById('password-baru').value;
        const confirmation = document.getElementById('password-konfirmasi');
        if (password !== confirmation.value) {
          event.preventDefault();
          confirmation.setCustomValidity('Konfirmasi password tidak sama.');
          confirmation.reportValidity();
        }
      });

      document.getElementById('password-konfirmasi').addEventListener('input', function () {
        this.setCustomValidity('');
      });
    })();
  </script>
</body>
</html>
