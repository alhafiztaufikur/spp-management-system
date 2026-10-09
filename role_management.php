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
require_once 'includes/filter_choices.php';
require_once 'includes/account_letter_ui.php';
$accountRoleLabels=['super_admin'=>'Super Admin','admin'=>'Administrator','bendahara'=>'Bendahara TU','kasir'=>'Kasir'];
$accountRoles=filter_choices($_GET['account_role']??'*',$accountRoleLabels);
$accountStatus=filter_choices($_GET['account_status']??'*',['active'=>'Aktif','inactive'=>'Nonaktif']);
$accountUnits=filter_choices($_GET['account_unit']??'*',$accountScopeUnit?[(string)$accountScopeUnit=>unit_label($accountScopeUnit)]:['global'=>'Semua Unit (Super Admin)','1'=>'SD','2'=>'SMP','3'=>'SMA']);
foreach(['account_role'=>$accountRoles,'account_status'=>$accountStatus,'account_unit'=>$accountUnits] as $name=>$values){
    $options=$name==='account_role'?$accountRoleLabels:($name==='account_status'?['active'=>'Aktif','inactive'=>'Nonaktif']:($accountScopeUnit?[(string)$accountScopeUnit=>unit_label($accountScopeUnit)]:['global'=>'Semua Unit (Super Admin)','1'=>'SD','2'=>'SMP','3'=>'SMA']));
    if(!filter_is_all($values)&&array_diff($values,array_map('strval',array_keys($options))))filter_choice_error($name);
}
if(isset($_GET['q'])&&!is_string($_GET['q']))filter_choice_error('pencarian akun');
$accountSearch=trim($_GET['q']??'');
if(mb_strlen($accountSearch)>100)filter_choice_error('pencarian akun');
$accountQuery=['q'=>$accountSearch,'account_role'=>$accountRoles,'account_status'=>$accountStatus];
if(!$accountScopeUnit)$accountQuery['account_unit']=$accountUnits;
$accountReturn='role_management.php?'.http_build_query($accountQuery);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken   = $_SESSION['csrf_token'];
$allowedRole = ['super_admin', 'admin', 'bendahara', 'kasir'];

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
        header('Location: '.$accountReturn);
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

        header('Location: '.$accountReturn);
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

        header('Location: '.$accountReturn);
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
            } else {
                if ($targetActive === 0 && (int)$account['is_active'] === 1 && $account['role'] === 'super_admin') {
                    $roleToCount=$account['role']; $unitToCount=$account['unit_id'];
                    $count=$koneksi->prepare('SELECT id FROM admin WHERE role=? AND is_active=1 AND (unit_id<=>?) FOR UPDATE');
                    $count->bind_param('si',$roleToCount,$unitToCount);$count->execute();
                    $remaining=$count->get_result()->num_rows;$count->close();
                    if ($remaining <= 1) {
                        $koneksi->rollback();
                        setAccountFlash('error', 'Super Admin aktif terakhir tidak dapat dinonaktifkan.');
                        header('Location: '.$accountReturn); exit;
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

        header('Location: '.$accountReturn);
        exit;
    }

    setAccountFlash('error', 'Aksi tidak dikenali.');
    header('Location: '.$accountReturn);
    exit;
}

$accountResult = $koneksi->query(
    "SELECT id, username, nama, role, unit_id, is_active, created_at FROM admin WHERE 1=1 "
    . ($accountScopeUnit ? 'AND unit_id='.(int)$accountScopeUnit.' ' : '')
    . "ORDER BY unit_id,FIELD(role,'super_admin','admin','bendahara','kasir'),nama ASC"
);
$accountRows=[];$roleCounts=array_fill_keys(array_keys($accountRoleLabels),0);
while($row=$accountResult->fetch_assoc()){
    $unitKey=$row['unit_id']===null?'global':(string)$row['unit_id'];
    if(!filter_is_all($accountUnits)&&!in_array($unitKey,$accountUnits,true))continue;
    if(!filter_is_all($accountStatus)&&!in_array((int)$row['is_active']===1?'active':'inactive',$accountStatus,true))continue;
    if($accountSearch!==''&&mb_stripos($row['nama'].' '.$row['username'].' '.($accountRoleLabels[$row['role']]??''),$accountSearch)===false)continue;
    $roleCounts[$row['role']]=($roleCounts[$row['role']]??0)+1;
    if(filter_is_all($accountRoles)||in_array($row['role'],$accountRoles,true))$accountRows[]=$row;
}
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
  <link rel="stylesheet" href="assets/css/account_workspace.css?v=<?= filemtime(__DIR__.'/assets/css/account_workspace.css') ?>" />
  <link rel="stylesheet" href="assets/css/workspace_readability.css?v=<?= filemtime(__DIR__.'/assets/css/workspace_readability.css') ?>" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="account-workspace" data-readable-workspace="accounts">
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

      <div class="main-card account-create-card">
        <div class="card-title-row">
          <div class="card-title">
            <?= account_letter_icon('add') ?>
            <span>Buat Akun Petugas<small>Tambah akun untuk admin, bendahara, atau kasir sesuai unit yang dipilih.</small></span>
          </div>
        </div>
        <div class="account-create-notice"><?= account_letter_icon('document') ?><span><strong>Hanya Super Admin yang dapat membuat akun.</strong><small>Akun baru mengikuti unit yang dipilih pada formulir.</small></span></div>

        <form method="POST" action="<?= htmlspecialchars($accountReturn) ?>" id="form-tambah-akun">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
          <input type="hidden" name="aksi" value="tambah" />
          <div class="account-steps"><section class="account-step"><h3><span>1</span><div>Informasi Akun<small>Masukkan data dasar petugas.</small></div></h3><div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="nama"><?= account_letter_icon('user') ?>Nama Lengkap</label>
              <input class="field-input" type="text" id="nama" name="nama" minlength="3" maxlength="100" placeholder="Nama petugas" required autocomplete="name" />
              <span class="field-hint">Nama lengkap akan ditampilkan pada sistem.</span>
            </div>
            <div class="field-row">
              <label class="field-label" for="username"><span aria-hidden="true">@</span>Username</label>
              <input class="field-input" type="text" id="username" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" placeholder="Contoh: kasir.andi" required autocomplete="username" />
              <span class="field-hint">Huruf, angka, titik, garis bawah, atau tanda hubung.</span>
            </div>
          </div></section><section class="account-step"><h3><span>2</span><div>Akses &amp; Keamanan<small>Tentukan role, unit, dan password.</small></div></h3><div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="role"><?= account_letter_icon('shield') ?>Role</label>
              <select class="field-input field-select" id="role" name="role" required>
                <option value="">-- Pilih Role --</option>
                <option value="admin">Admin</option>
                <option value="kasir">Kasir</option>
                <option value="bendahara">Bendahara</option>
                <?php if ($accountScopeUnit === 0): ?><option value="super_admin">Super Admin</option><?php endif; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="account-unit"><?= account_letter_icon('unit') ?>Unit</label>
              <select class="field-input field-select" id="account-unit" name="unit_id" required>
                <?php if ($accountScopeUnit === 0): ?><option value="">-- Pilih Unit --</option><option value="0">Semua Unit (Super Admin)</option><?php endif; ?>
                <?php foreach (($accountScopeUnit === 0 ? [1=>'SD',2=>'SMP',3=>'SMA'] : [$accountScopeUnit=>unit_label($accountScopeUnit)]) as $id=>$label): ?>
                <option value="<?= $id ?>" <?= $accountScopeUnit===$id?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="password-baru"><?= account_letter_icon('lock') ?>Password</label>
              <div class="password-field-wrap">
                <input class="field-input" type="password" id="password-baru" name="password" minlength="8" maxlength="72" placeholder="Minimal 8 karakter" required autocomplete="new-password" />
                <button type="button" class="toggle-pw" data-toggle-password="password-baru" title="Tampilkan password" aria-label="Tampilkan password">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="field-row">
              <label class="field-label" for="password-konfirmasi"><?= account_letter_icon('lock') ?>Konfirmasi Password</label>
              <div class="password-field-wrap">
                <input class="field-input" type="password" id="password-konfirmasi" name="password_confirmation" minlength="8" maxlength="72" placeholder="Ulangi password" required autocomplete="new-password" />
                <button type="button" class="toggle-pw" data-toggle-password="password-konfirmasi" title="Tampilkan password" aria-label="Tampilkan password">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
          </div></section></div>
          <div class="action-bar">
            <div class="account-access-note"><?= account_letter_icon('shield') ?><span><strong>Hak Akses</strong><small>Role menentukan menu dan fitur yang dapat diakses petugas.</small></span></div>
            <button type="submit" class="btn btn-primary" id="btn-tambah-akun">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
              Buat Akun Petugas
            </button>
          </div>
        </form>
      </div>

      <div class="main-card account-list-card" style="margin-top:0">
        <div class="card-title-row">
          <div class="card-title">
            <?= account_letter_icon('users') ?>
            <span>Daftar Akun (<?= count($accountRows) ?> akun)<small>Kelola akun petugas, ubah password, dan atur status akun.</small></span>
          </div>
          <a class="btn btn-ghost account-reload" href="<?= htmlspecialchars($accountReturn) ?>"><?= account_letter_icon('reload') ?>Muat Ulang</a>
        </div>
        <form method="get" class="account-filter-form" id="account-filter-form">
          <label class="account-search"><?= account_letter_icon('search') ?><input class="field-input" name="q" value="<?= htmlspecialchars($accountSearch) ?>" maxlength="100" placeholder="Cari nama, username, atau role..." aria-label="Cari akun"></label>
          <?php if(!$accountScopeUnit): ?><select name="account_unit[]" multiple data-filter-multiple data-dropdown-label="Unit" data-all-label="Semua unit" class="field-input"><option value="*" <?= filter_is_all($accountUnits)?'selected':'' ?>>Semua unit</option><?php foreach(['global'=>'Semua Unit (Super Admin)','1'=>'SD','2'=>'SMP','3'=>'SMA'] as $key=>$label): ?><option value="<?= $key ?>" <?= in_array((string)$key,$accountUnits,true)?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><?php else: ?><span class="account-scope"><?= account_letter_icon('unit') ?>Unit <?= unit_label($accountScopeUnit) ?></span><?php endif; ?>
          <select name="account_role[]" multiple data-filter-multiple data-dropdown-label="Role" data-all-label="Semua role" class="field-input"><option value="*" <?= filter_is_all($accountRoles)?'selected':'' ?>>Semua role</option><?php foreach($accountRoleLabels as $key=>$label): ?><option value="<?= $key ?>" <?= in_array($key,$accountRoles,true)?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
          <select name="account_status[]" multiple data-filter-multiple data-dropdown-label="Status" data-all-label="Semua status" class="field-input"><option value="*" <?= filter_is_all($accountStatus)?'selected':'' ?>>Semua status</option><?php foreach(['active'=>'Aktif','inactive'=>'Nonaktif'] as $key=>$label): ?><option value="<?= $key ?>" <?= in_array($key,$accountStatus,true)?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
          <button type="submit" class="btn btn-primary account-filter-submit">Tampilkan</button>
          <a class="btn btn-ghost account-filter-reset" href="role_management.php" title="Hapus pencarian dan kembalikan semua filter"><?= account_letter_icon('reload') ?>Reset</a>
        </form>
        <nav class="account-role-tabs" aria-label="Filter cepat peran">
          <?php foreach(['*'=>'Semua']+$accountRoleLabels as $key=>$label): if($key==='super_admin'&&$accountScopeUnit)continue; $tab=$accountQuery;$tab['account_role']=[$key]; ?>
          <a href="role_management.php?<?= htmlspecialchars(http_build_query($tab)) ?>" class="<?= $accountRoles===[$key]?'is-active':'' ?>" <?= $accountRoles===[$key]?'aria-current="true"':'' ?>><?= $label ?><span><?= $key==='*'?array_sum($roleCounts):$roleCounts[$key] ?></span></a>
          <?php endforeach; ?>
        </nav>
        <p class="account-results-note">Menampilkan <?= count($accountRows) ?> akun hasil filter dalam cakupan <?= htmlspecialchars(unit_label($accountScopeUnit)) ?>. Jumlah tab mengikuti pencarian, unit, dan status.</p>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead>
              <tr><th>Akun</th><th>Unit</th><th>Role</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              <?php foreach ($accountRows as $account): ?>
              <tr>
                <td data-label="Akun">
                  <div class="account-summary">
                    <span class="account-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($account['nama'], 0, 2))) ?></span>
                    <span>
                      <span class="account-name"><?= htmlspecialchars($account['nama']) ?></span>
                      <span class="account-username">@<?= htmlspecialchars($account['username']) ?></span>
                    </span>
                  </div>
                </td>
                <td data-label="Unit"><span class="unit-pill"><?= unit_label((int)($account['unit_id'] ?? 0)) ?></span></td>
                <td data-label="Role"><span class="badge-role badge-role-<?= htmlspecialchars($account['role']) ?>"><?= htmlspecialchars($accountRoleLabels[$account['role']] ?? $account['role']) ?></span></td>
                <td data-label="Status"><span class="account-status <?= (int)$account['is_active']===1?'is-active':'is-inactive' ?>"><?= (int)$account['is_active']===1?'Aktif':'Nonaktif' ?></span></td>
                <td data-label="Dibuat"><?= spp_date_label($account['created_at']) ?></td>
                <td data-label="Aksi" class="aksi-col">
                  <div class="savings-row-actions">
                    <button type="button" class="btn-tbl btn-tbl-edit btn-reset-password"
                      data-account-id="<?= (int)$account['id'] ?>"
                      data-account-name="<?= htmlspecialchars($account['nama']) ?>"
                      data-account-username="<?= htmlspecialchars($account['username']) ?>">
                      <?= account_letter_icon('lock') ?>
                      Ganti Password
                    </button>
                    <?php if ((int)$account['id'] !== (int)($_SESSION['admin_id'] ?? 0) && (int)$account['is_active']===1): ?>
                    <button type="button" class="btn-tbl btn-tbl-del btn-delete-account"
                      data-account-id="<?= (int)$account['id'] ?>"
                      data-account-name="<?= htmlspecialchars($account['nama']) ?>"
                      data-account-username="<?= htmlspecialchars($account['username']) ?>"
                      title="Nonaktifkan akun">
                      <?= account_letter_icon('user_off') ?>
                      Nonaktifkan
                    </button>
                    <?php elseif ((int)$account['is_active']===0): ?>
                    <form method="post" action="<?= htmlspecialchars($accountReturn) ?>" class="account-activate-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                      <input type="hidden" name="aksi" value="status">
                      <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                      <input type="hidden" name="target_active" value="1">
                      <button class="btn-tbl btn-tbl-edit" type="submit"><?= account_letter_icon('user_check') ?>Aktifkan</button>
                    </form>
                    <?php else: ?>
                    <span class="account-self-badge"><?= account_letter_icon('user') ?> Akun Anda</span>
                    <?php endif; ?>
                  </div>
                  <details class="account-more"><summary aria-label="Tindakan akun <?= htmlspecialchars($account['nama']) ?>">⋯</summary><div><button type="button" data-account-shortcut="password"><?= account_letter_icon('lock') ?>Ganti Password</button><?php if((int)$account['id']!==(int)$_SESSION['admin_id']): ?><button type="button" data-account-shortcut="status" class="<?= (int)$account['is_active']?'is-danger':'is-success' ?>"><?= account_letter_icon((int)$account['is_active']?'user_off':'user_check') ?><?= (int)$account['is_active']?'Nonaktifkan':'Aktifkan' ?></button><?php endif; ?></div></details>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(!$accountRows): ?><tr><td colspan="6" class="account-empty"><?= account_letter_icon('users') ?><strong>Tidak ada akun sesuai filter.</strong><span>Ubah pilihan filter atau gunakan Reset.</span></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div class="modal-overlay" id="reset-password-modal" role="dialog" aria-modal="true" aria-labelledby="reset-modal-title">
    <div class="modal-box">
      <div class="modal-icon"><?= account_letter_icon('lock') ?></div>
      <div class="modal-title" id="reset-modal-title">Ganti Password Akun</div>
      <div class="modal-account" id="reset-account-label"></div>
      <form method="POST" action="<?= htmlspecialchars($accountReturn) ?>" id="form-reset-password">
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
      <div class="modal-icon"><?= account_letter_icon('shield') ?></div>
      <div class="modal-title" id="delete-modal-title">Konfirmasi Nonaktifkan Akun</div>
      <p style="color:var(--text-secondary);margin:12px 0 6px;font-size:13px;">Akun berikut tidak dapat login sampai diaktifkan kembali.</p>
      <div class="modal-account" id="delete-account-label" style="font-weight:700;color:var(--red);"></div>
      <p class="payment-auto-note" style="margin-top:8px;">Riwayat transaksi dan audit tetap tersimpan.</p>
      <form method="POST" action="<?= htmlspecialchars($accountReturn) ?>" id="form-delete-account" style="margin-top:16px;">
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
  <script src="assets/js/account_workspace.js?v=<?= filemtime(__DIR__.'/assets/js/account_workspace.js') ?>"></script>
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
