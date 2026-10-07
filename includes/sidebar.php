<?php
// includes/sidebar.php
$current = basename($_SERVER['PHP_SELF']);
$dir     = basename(dirname($_SERVER['PHP_SELF']));

// Tentukan root path berdasarkan posisi subfolder
if (in_array($dir, ['pembayaran', 'siswa', 'tabungan', 'laporan'])) {
    $root = '../';
} else {
    $root = '';
}

$role = $_SESSION['admin_role'] ?? '';

// Definisi semua nav item: [href, label, svg-path, roles yang boleh akses, kategori]
$allNavItems = [
  ['dashboard.php', 'Dashboard',
   '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
   ['admin', 'bendahara'], 'Menu Utama'],

  ['pembayaran/form.php', 'Input Pembayaran',
   '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
   ['admin', 'kasir'], 'Pembayaran'],

  ['pembayaran/lihat.php', 'Riwayat Pembayaran',
   '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
   ['admin', 'kasir', 'bendahara'], 'Pembayaran'],

  ['pembayaran/riwayat_daftar_ulang.php', 'Riwayat Daftar Ulang',
   '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/><path d="M9 7h6M9 11h6"/>',
   ['admin', 'kasir'], 'Pembayaran'],


  ['otorisasi_transaksi.php', $role === 'kasir' ? 'Pengajuan Saya' : ($role === 'bendahara' ? 'Riwayat Otorisasi' : 'Otorisasi Transaksi'),
   '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
   ['admin', 'bendahara', 'kasir'], 'Pembayaran'],

  ['siswa/daftar.php', 'Data Siswa',
   '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
   ['admin', 'kasir'], 'Data Master'],

  ['master_kelas.php', 'Master Kelas/Rombel',
   '<path d="M3 3h18v18H3z"/><path d="M3 9h18M9 3v18"/>',
   ['admin', 'kasir'], 'Data Master'],

  ['master_spp.php', 'Master Penerbitan SPP',
   '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
   ['admin', 'kasir'], 'Data Master'],

  ['master_biaya_lain.php', 'Master Biaya Lain',
   '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
   ['admin', 'kasir'], 'Data Master'],

  ['master_daftar_ulang.php', 'Master Daftar Ulang',
   '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/><path d="M9 7h6"/><path d="M9 11h6"/>',
   ['admin', 'kasir'], 'Data Master'],

  ['tabungan/masuk.php', 'Tabungan Masuk',
   '<path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
   ['admin', 'kasir'], 'Tabungan'],

  ['tabungan/keluar.php', 'Tabungan Keluar',
   '<path d="M6 17H18M12 22V2M7 7l5-5 5 5"/>',
   ['admin', 'kasir'], 'Tabungan'],

  ['tabungan/riwayat.php', 'Riwayat Tabungan',
   '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
   ['admin', 'kasir', 'bendahara'], 'Tabungan'],

  ['tabungan/cetak.php', 'Cetak Tabungan',
   '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/>',
   ['admin', 'kasir', 'bendahara'], 'Tabungan'],

  ['laporan/index.php', 'Laporan Umum',
   '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
   ['admin', 'bendahara'], 'Laporan'],

  ['laporan/global.php', 'Laporan Global',
   '<path d="M3 3v18h18"/><path d="M7 15l3-3 3 2 5-6"/><path d="M7 19h12"/>',
   ['admin', 'bendahara', 'kasir'], 'Laporan'],

  ['laporan/surat_laporan.php', 'Surat Laporan',
   '<path d="M4 21h16V7l-5-5H4z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>',
   ['admin', 'bendahara', 'kasir'], 'Laporan'],

  ['role_management.php', 'Role Management',
   '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
   ['super_admin'], 'Pengaturan'],
  ['backup_restore.php', 'Backup & Restore',
   '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
   ['super_admin'], 'Pengaturan'],
];

// Filter nav items berdasarkan role
$navItems = array_filter($allNavItems, fn($item) => in_array($role, $item[3], true)
  || ($role === 'super_admin' && in_array('admin', $item[3], true)));
$navItems = array_values($navItems);

// Short label untuk bottom nav
$shortLabels = [
  'Input Pembayaran'  => 'Input',
  'Riwayat Pembayaran' => 'Riwayat',
  'Riwayat Daftar Ulang' => 'Riwayat DU',
  'Otorisasi Transaksi' => 'Otorisasi',
  'Pengajuan Saya' => 'Pengajuan',
  'Riwayat Otorisasi' => 'Riwayat',
  'Data Siswa'        => 'Siswa',
  'Master Kelas/Rombel' => 'Kelas',
  'Master Penerbitan SPP' => 'SPP',
  'Master Biaya Lain' => 'Biaya',
  'Master Daftar Ulang' => 'DU',
  'Role Management'   => 'Akun',
  'Backup & Restore'  => 'Backup',
  'Tabungan Masuk'    => 'Masuk',
  'Tabungan Keluar'   => 'Keluar',
  'Riwayat Tabungan'  => 'Riwayat',
  'Cetak Tabungan'    => 'Cetak',
  'Laporan Umum'      => 'Umum',
  'Laporan Global'    => 'Global',
  'Surat Laporan' => 'Surat',
];

// Role label
$roleLabels = ['super_admin' => 'Super Admin', 'admin' => 'Administrator', 'bendahara' => 'Bendahara TU', 'kasir' => 'Kasir'];
$roleLabel  = $roleLabels[$role] ?? 'Pengguna';
$roleAvatars = [
  'super_admin' => 'SA',
  'admin' => 'AD',
  'bendahara' => 'BD',
  'kasir' => 'KS',
];
$roleAvatar = $roleAvatars[$role] ?? 'US';
if (empty($_SESSION['csrf_unit_switch'])) $_SESSION['csrf_unit_switch']=bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_logout'])) $_SESSION['csrf_logout']=bin2hex(random_bytes(32));
$activeUnit=unit_active_id();
$transactionUnitOnly = unit_transaction_route((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$unitPalette = unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null);
?>
<script>
function unitSwitchReportScope(select) {
  var form=document.createElement('form');form.method='post';form.action=<?= json_encode($root.'unit_switch.php') ?>;
  var fields={csrf_token:<?= json_encode($_SESSION['csrf_unit_switch']) ?>,unit_id:select.value==='all'?'0':<?= json_encode((string)$activeUnit) ?>,next:location.pathname+location.search};
  Object.keys(fields).forEach(function(name){var input=document.createElement('input');input.type='hidden';input.name=name;input.value=fields[name];form.append(input)});
  document.body.append(form);form.submit();
}
</script>
<?php if (unit_all_readonly() && !$transactionUnitOnly && $current !== 'role_management.php'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var main=document.querySelector('main');
  if(main && !main.querySelector('.report-general-shell')){var note=document.createElement('div');note.className='alert alert-info';note.textContent='Semua Unit: tampilan baca. Pilih SD, SMP, atau SMA untuk transaksi atau perubahan data.';var content=main.querySelector('.page-content');if(content)content.prepend(note);else main.querySelector('.topbar')?.after(note)}
  document.querySelectorAll('form[method="post" i]').forEach(function(form){
    if(form.classList.contains('sidebar-unit-form') || form.classList.contains('dashboard-unit-switch-form') || form.action.includes('logout.php'))return;
    form.querySelectorAll('input,select,textarea,button').forEach(function(control){control.disabled=true});
    form.hidden=true;form.style.setProperty('display','none','important');
    var editor=form.closest('.master-modern-form');if(editor){editor.hidden=true;editor.style.setProperty('display','none','important')}
    if(form.id==='form-tambah-akun'){var card=form.closest('.main-card');if(card){card.hidden=true;card.style.setProperty('display','none','important')}}
    form.addEventListener('submit',function(event){event.preventDefault()});
  });
  document.querySelectorAll('.btn-tbl-edit,.btn-reset-password,.btn-delete-account').forEach(function(control){control.hidden=true;control.style.setProperty('display','none','important')});
  document.querySelectorAll('[data-edit-url]').forEach(function(row){row.removeAttribute('data-edit-url');row.removeAttribute('role');row.removeAttribute('tabindex');row.classList.remove('clickable-payment-row')});
});
</script>
<?php endif; ?>
<!-- Early theme init to prevent flash -->
<link rel="stylesheet" href="<?= $root ?>assets/css/dropdowns.css?v=<?= filemtime(__DIR__.'/../assets/css/dropdowns.css') ?>">
<script defer src="<?= $root ?>assets/js/dropdowns.js?v=<?= filemtime(__DIR__.'/../assets/js/dropdowns.js') ?>"></script>
<script>(function(){document.documentElement.setAttribute('data-palette',<?= json_encode($unitPalette, JSON_HEX_TAG | JSON_HEX_AMP) ?>);try{document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')}catch(e){document.documentElement.setAttribute('data-theme',document.documentElement.getAttribute('data-theme')||'light')}})();</script>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon brand-logo-wrap">
      <img src="<?= $root ?>assets/img/school-logo.png" alt="Logo Mutiara Hikmah" class="brand-logo-img" />
    </div>
    <span class="brand-name">SistemSPP</span>
  </div>

  <?php if (unit_is_super()): ?>
  <div class="sidebar-unit-panel">
    <form action="<?= $root ?>unit_switch.php" method="post" class="sidebar-unit-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_unit_switch'],ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="next" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/dashboard.php',ENT_QUOTES,'UTF-8') ?>">
      <span class="sidebar-unit-kicker"><span aria-hidden="true"></span> SUPER ADMIN</span>
      <label for="sidebar-unit-select">Unit operasional</label>
      <span class="sidebar-unit-select-wrap">
        <select id="sidebar-unit-select" name="unit_id" data-native-select onchange="this.form.submit()">
          <?php if ($transactionUnitOnly && $activeUnit === 0): ?><option value="" selected disabled>Pilih unit</option><?php endif; ?>
          <?php foreach (($transactionUnitOnly ? [1=>'SD',2=>'SMP',3=>'SMA'] : [0=>'Semua Unit',1=>'SD',2=>'SMP',3=>'SMA']) as $id=>$name): ?>
          <option value="<?= $id ?>" <?= $activeUnit===$id?'selected':'' ?>><?= $name ?></option>
          <?php endforeach; ?>
        </select>
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
      </span>
      <small>Pilih satu unit. <?= $current === 'role_management.php' ? ($activeUnit === 0 ? 'Kelola akun semua unit dari halaman ini.' : 'Kelola akun unit yang dipilih.') : ($transactionUnitOnly ? 'Transaksi wajib menggunakan satu unit.' : ($activeUnit === 0 ? 'Semua Unit hanya untuk melihat data dan rekap.' : 'Menu operasional mengikuti unit ini.')) ?></small>
    </form>
  </div>
  <?php endif; ?>
  <?php if (unit_is_super()): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var bar = document.querySelector('.topbar');
    if (!bar || bar.querySelector('.topbar-unit')) return;
    var badge = document.createElement('span');
    badge.className = 'topbar-unit';
    var label = document.createElement('span');
    label.className = 'topbar-unit-label';
    label.textContent = <?= json_encode(isset($reportUnitId) && (int)$reportUnitId === 0 ? 'Rekap' : 'Unit operasional', JSON_HEX_TAG|JSON_HEX_AMP) ?>;
    var value = document.createElement('strong');
    value.className = 'topbar-unit-value';
    value.textContent = <?= json_encode($transactionUnitOnly && $activeUnit === 0 ? 'Belum dipilih' : (isset($reportUnitId) && (int)$reportUnitId === 0 ? 'Semua Unit' : unit_label($activeUnit)), JSON_HEX_TAG|JSON_HEX_AMP) ?>;
    badge.setAttribute('aria-label', label.textContent + ': ' + value.textContent);
    badge.append(label, value);
    var clock = bar.querySelector('.clock-badge');
    bar.insertBefore(badge, clock);
  });
  </script>
  <?php endif; ?>

  <nav class="sidebar-nav">
    <?php $lastSection = null; ?>
    <?php foreach ($navItems as [$href, $label, $icon, $roles, $section]):
      $isPrincipal = $current === 'template.php' && ($_GET['template'] ?? '') === 'tunggakan-siswa';
      $isGlobalDetail = $href === 'laporan/global.php' && $current === 'template.php' && !$isPrincipal;
      $isLetterDetail = $href === 'laporan/surat_laporan.php' && ($current === 'surat_orang_tua.php' || $isPrincipal);
      $isActive = (strpos($_SERVER['PHP_SELF'], str_replace('../', '', $href)) !== false || $isGlobalDetail || $isLetterDetail) ? 'active' : '';
    ?>
    <?php if ($section !== $lastSection): $lastSection = $section; ?>
    <div class="nav-section-label"><?= htmlspecialchars($section) ?></div>
    <?php endif; ?>
    <a href="<?= $root . $href ?>" class="nav-item <?= $isActive ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Theme Toggle -->
  <div style="padding: 0 12px 8px;">
    <button class="theme-toggle" id="btn-theme-toggle" onclick="toggleTheme()" title="Ganti tema">
      <span id="theme-icon">🌙</span>
      <span id="theme-label">Mode Gelap</span>
      <span class="toggle-track"><span class="toggle-thumb"></span></span>
    </button>
  </div>

  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar admin-avatar-<?= htmlspecialchars($role) ?>">
        <img src="<?= $root ?>assets/img/profile-avatar.png?v=2" alt="<?= htmlspecialchars($roleLabel) ?>" class="admin-avatar-img" width="38" height="38" />
        <span class="admin-avatar-badge"><?= htmlspecialchars($roleAvatar) ?></span>
      </div>
      <div>
        <span class="admin-name"><?= htmlspecialchars($_SESSION['admin_nama'] ?? 'Admin') ?></span>
        <span class="admin-role"><?= $roleLabel ?></span>
      </div>
    </div>
    <form action="<?= $root ?>logout.php" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_logout'], ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="logout-btn" title="Logout">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span class="logout-text">Logout</span>
      </button>
    </form>
  </div>
</aside>
<div class="sidebar-backdrop" onclick="toggleSidebar()"></div>

<!-- Material 3 Bottom Navigation for Mobile -->
<nav class="bottom-nav">
  <?php foreach ($navItems as [$href, $label, $icon, $roles, $section]):
    $isPrincipal = $current === 'template.php' && ($_GET['template'] ?? '') === 'tunggakan-siswa';
    $isGlobalDetail = $href === 'laporan/global.php' && $current === 'template.php' && !$isPrincipal;
    $isLetterDetail = $href === 'laporan/surat_laporan.php' && ($current === 'surat_orang_tua.php' || $isPrincipal);
    $isActive   = (strpos($_SERVER['PHP_SELF'], str_replace('../', '', $href)) !== false || $isGlobalDetail || $isLetterDetail) ? 'active' : '';
    $shortLabel = $shortLabels[$label] ?? $label;
  ?>
  <a href="<?= $root . $href ?>" class="bottom-nav-item <?= $isActive ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
    <div class="bottom-nav-icon-wrap">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
    </div>
    <span class="bottom-nav-label"><?= $shortLabel ?></span>
  </a>
  <?php endforeach; ?>
</nav>
