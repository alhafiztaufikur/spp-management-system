<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/includes/auth.php';
requireRole(['super_admin','admin','kasir']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Gunakan formulir pencadangan yang tersedia.');
}
require_once __DIR__.'/includes/legacy_import.php';
require_once __DIR__.'/includes/legacy_ui.php';
$legacyTab=($_GET['tab']??'')==='legacy';
require_once __DIR__.'/includes/database_backup.php';
require_once __DIR__.'/includes/database_restore_ui.php';
if(empty($_SESSION['csrf_legacy_import']))$_SESSION['csrf_legacy_import']=bin2hex(random_bytes(32));
if(empty($_SESSION['csrf_backup']))$_SESSION['csrf_backup']=bin2hex(random_bytes(32));
if(empty($_SESSION['backup_request_key']))$_SESSION['backup_request_key']=bin2hex(random_bytes(16));
foreach (['q','type'] as $field) {
    if (isset($_GET[$field]) && !is_string($_GET[$field])) {
        http_response_code(400); exit('Filter backup tidak valid.');
    }
}
$backupSearch = trim((string)($_GET['q'] ?? ''));
$backupType = (string)($_GET['type'] ?? '');
if (!in_array($backupType, ['', 'manual', 'scheduled', 'catchup'], true) || strlen($backupSearch) > 200) {
    http_response_code(400); exit('Filter backup tidak valid.');
}
try {
    $backupSummary = db_backup_summary();
} catch (Throwable $error) {
    $backupSummary = ['configured'=>false,'ready'=>false,'active'=>null,'last_success'=>null,'count'=>0,'total_bytes'=>0,'next_schedule'=>null,'alerts'=>['Layanan backup belum dapat dihubungi.'],'jobs'=>[],'free_bytes'=>null];
}
$backupFlash = $_SESSION['backup_flash'] ?? null;
unset($_SESSION['backup_flash']);
function backup_ui_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function backup_ui_bytes($value): string {
    if ($value === null) return '—';
    $value = max(0, (float)$value);
    foreach (['B','KiB','MiB','GiB','TiB'] as $unit) {
        if ($value < 1024 || $unit === 'TiB') return number_format($value, $unit === 'B' ? 0 : 1, ',', '.') . ' ' . $unit;
        $value /= 1024;
    }
    return '—';
}
function backup_ui_date($value): string {
    if (!$value) return 'Belum ada';
    try { return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') . ' WIB'; }
    catch (Throwable $error) { return '—'; }
}
$backupSettings = $backupSummary['settings'] ?? ['daily_time'=>'18:00','retention_days'=>90,'version'=>0,'activated'=>false];
$backupClock = str_replace(':','.', $backupSettings['daily_time']);
$backupStates = ['queued'=>'Menunggu','dumping'=>'Mencadangkan','verifying'=>'Memverifikasi','verified'=>'Terverifikasi','failed'=>'Gagal','expired'=>'Kedaluwarsa'];
$backupTypes = ['manual'=>'Manual','scheduled'=>'Terjadwal','catchup'=>'Susulan'];
$backupJobs = array_values(array_filter($backupSummary['jobs'] ?? [], static function (array $job) use ($backupSearch, $backupType, $backupTypes): bool {
    if ($backupType !== '' && ($job['type'] ?? '') !== $backupType) return false;
    $haystack = implode(' ', [(string)($job['name'] ?? ''),(string)($job['actor_name'] ?? ''),(string)($job['message'] ?? ''),$backupTypes[$job['type'] ?? ''] ?? '']);
    return $backupSearch === '' || stripos($haystack, $backupSearch) !== false;
}));
$backupActive = $backupSummary['active'] ?? null;
$backupCanCreate = !empty($backupSummary['can_create']);
$databaseConnected = false;
$databaseSize = null;
try {
    $databaseConnected = $koneksi->query('SELECT 1')->fetch_row()[0] == 1;
    $size = $koneksi->query("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetch_row()[0];
    if ($size !== null) $databaseSize = number_format((float)$size / 1048576, 2, ',', '.') . ' MB';
} catch (mysqli_sql_exception $e) {
    // Do not expose credentials or SQL errors in this administrative view.
}
function backup_ui_icon(string $name): string {
    $paths = [
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'file' => '<path d="M14 2H5v20h14V7zM14 2v6h5M8 13h8M8 17h6"/>',
        'upload' => '<path d="M7 18H5a4 4 0 0 1 0-8 7 7 0 0 1 14-1 4.5 4.5 0 0 1 0 9h-2M12 21V10m-4 4 4-4 4 4"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'download' => '<path d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5"/>',
    ];
    return '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['database']) . '</svg>';
}
function backup_ui_picker(string $prefix, string $label): void { ?>
  <div class="dbt-dropzone" id="<?= $prefix ?>-dropzone">
    <?= backup_ui_icon('upload') ?>
    <span>Tarik satu file <?= $prefix==='legacy'?'backup SQL Server':'SQL' ?> ke sini atau</span>
    <button type="button" class="dbt-button dbt-button-outline" id="<?= $prefix ?>-choose">Pilih File</button>
    <input type="file" id="<?= $prefix ?>-file" <?= $prefix==='legacy'?'':'accept=".sql"' ?> class="dbt-file-input" aria-label="<?= $label ?>" tabindex="-1">
  </div>
  <p class="dbt-hint"><?= $prefix==='legacy'?'Backup SQL Server: .dat, .bak, atau tanpa ekstensi. Maksimal 100 MiB. Diunggah hanya ketika proses dimulai.':'SQL (.sql), maksimal 100 MiB. Gunakan unduhan cadangan terverifikasi dari riwayat PC ini; file dikirim saat diperiksa.' ?></p>
  <div class="dbt-file-summary" id="<?= $prefix ?>-summary" hidden>
    <?= backup_ui_icon('file') ?><div><strong id="<?= $prefix ?>-name"></strong><span id="<?= $prefix ?>-size"></span></div>
    <button type="button" class="dbt-text-button" id="<?= $prefix ?>-change">Ganti</button>
    <button type="button" class="dbt-text-button" id="<?= $prefix ?>-remove">Hapus</button>
  </div>
  <p class="dbt-file-status" id="<?= $prefix ?>-status" role="status" aria-live="polite">Belum ada file dipilih.</p>
<?php } ?>
<!DOCTYPE html>
<html lang="id" class="legacy-document" data-palette="<?= unit_palette_for_view() ?>">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backup &amp; Restore Database | SistemSPP</title>
  <link rel="icon" href="assets/img/favicon.png?v=2">
  <link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/date_controls.css') ?>">
  <link rel="stylesheet" href="assets/css/backup_restore.css?v=<?= filemtime(__DIR__ . '/assets/css/backup_restore.css') ?>">
  <link rel="stylesheet" href="assets/css/legacy_ui.css"><link rel="stylesheet" href="assets/css/database_restore.css?v=<?= filemtime(__DIR__.'/assets/css/database_restore.css') ?>">
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light');})();</script>
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content dbt-main">
  <div class="topbar">
    <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka atau tutup sidebar"><?= backup_ui_icon('menu') ?></button>
    <div class="topbar-title"><h2>Backup &amp; Restore Database</h2><span class="breadcrumb">SistemSPP / Pengaturan / Backup &amp; Restore</span></div>
    <div class="clock-badge" id="liveClock">--:--:--</div>
  </div>
  <div class="db-tools-page">
    <nav class="dbt-breadcrumb" aria-label="Lokasi halaman">Pengaturan <span aria-hidden="true">›</span> Backup &amp; Restore Database</nav>
    <header class="dbt-heading"><span class="dbt-icon"><?= backup_ui_icon('database') ?></span><div><h1>Backup &amp; Restore Database</h1><p>Cadangan harian dan pengelolaan data SistemSPP.</p></div><span class="dbt-badge" id="backup-schedule-badge">Setiap hari · <?= backup_ui_escape($backupClock) ?> WIB</span></header>
    <div class="dbt-tabs" role="tablist" aria-label="Mode pengelolaan database">
      <a class="dbt-button" role="tab" id="tab-backup" aria-controls="panel-backup" aria-selected="<?= $legacyTab?'false':'true' ?>" href="?tab=backup">Backup &amp; Restore</a>
      <a class="dbt-button" role="tab" id="tab-legacy" aria-controls="panel-legacy" aria-selected="<?= $legacyTab?'true':'false' ?>" href="?tab=legacy">Import Legacy</a>
    </div>
    <section id="panel-backup" role="tabpanel" aria-labelledby="tab-backup" <?= $legacyTab?'hidden':'' ?>>
      <div class="dbt-notice"><?= backup_ui_icon('info') ?><div><strong>Seluruh Database SistemSPP</strong><p>Cadangan mencakup SD, SMP, dan SMA. Cadangan manual tetap disimpan; penyimpanan cadangan otomatis mengikuti pengaturan di bawah.</p></div></div>
      <div id="backup-alerts" class="dbt-backup-alerts" aria-live="polite">
        <?php foreach ($backupSummary['alerts'] ?? [] as $alert): if(!empty($backupSummary['configured'])&&empty($backupSummary['service_registered'])&&str_starts_with($alert,'Backup otomatis belum aktif.'))continue; ?><div class="dbt-warning"><?= backup_ui_escape($alert) ?></div><?php endforeach; ?>
      </div>
      <div id="backup-feedback" class="dbt-feedback<?= $backupFlash ? ' is-' . (($backupFlash['type'] ?? '') === 'success' ? 'success' : 'error') : '' ?>" role="status" aria-live="polite"<?= !$backupFlash ? ' hidden' : '' ?>><?= backup_ui_escape($backupFlash['message'] ?? '') ?></div>
      <div class="dbt-metrics">
        <?php foreach ([
          ['clock', 'Jadwal Berikutnya', backup_ui_date($backupSummary['next_schedule'] ?? null), 'Backup harian pukul '.$backupClock.' WIB', 'backup-next'],
          ['file', 'Backup Terverifikasi Terakhir', backup_ui_date($backupSummary['last_success']['finished_at'] ?? null), 'Sudah diuji melalui pemulihan', 'backup-last'],
          ['database', 'Ukuran Database', $databaseSize ?? 'Belum tersedia', $databaseConnected ? 'Database aktif' : 'Koneksi tidak tersedia', 'backup-db-size'],
          ['file', 'Cadangan Terverifikasi', number_format((int)($backupSummary['count'] ?? 0), 0, ',', '.'), backup_ui_bytes($backupSummary['total_bytes'] ?? 0) . ' ukuran terverifikasi', 'backup-count'],
        ] as [$icon, $title, $value, $description, $id]): ?>
        <article class="dbt-metric"><span class="dbt-icon"><?= backup_ui_icon($icon) ?></span><div><h2><?= $title ?></h2><strong id="<?= $id ?>"><?= backup_ui_escape($value) ?></strong><p<?= $id === 'backup-count' ? ' id="backup-total-size"' : ($id === 'backup-next' ? ' id="backup-schedule-description"' : '') ?>><?= backup_ui_escape($description) ?></p></div></article>
        <?php endforeach; ?>
      </div>
      <section class="dbt-card dbt-settings" aria-labelledby="backup-settings-title">
        <header class="dbt-card-heading"><span class="dbt-icon"><?= backup_ui_icon('clock') ?></span><div><h2 id="backup-settings-title">Pengaturan Backup Harian</h2></div></header>
        <div id="backup-scheduler-status" class="backup-scheduler-status"<?= empty($backupSummary['configured'])?' hidden':'' ?>>
          <div class="backup-scheduler-summary"><strong id="backup-scheduler-label"><?= !empty($backupSummary['ready'])?'Backup otomatis aktif':(!empty($backupSummary['service_registered'])?'Jadwal terpasang; menunggu layanan':'Backup otomatis belum aktif') ?></strong><span id="backup-scheduler-note"><?= !empty($backupSummary['service_registered'])?'Jadwal mengikuti jam yang tersimpan.':'Backup manual tetap tersedia.' ?></span></div>
          <details id="backup-scheduler-help"<?= !empty($backupSummary['service_registered'])?' hidden':'' ?>><summary>Cara mengaktifkan</summary><p>Buka <strong>PowerShell sebagai Administrator</strong> dengan akun Windows pemilik aplikasi, lalu jalankan perintah ini satu kali:</p>
            <code id="backup-scheduler-command"><?= backup_ui_escape('powershell -NoProfile -ExecutionPolicy Bypass -File "'.str_replace('/','\\',__DIR__). '\scripts\install_database_backup.ps1" -ConfirmMain -RegisterTask') ?></code>
            <button type="button" id="backup-scheduler-copy" class="dbt-button dbt-button-outline">Salin Perintah</button><span id="backup-scheduler-copy-status" role="status"></span><p>Status berubah otomatis setelah pemasangan berhasil. Jangan gunakan akun Windows yang berbeda.</p>
          </details>
        </div>
        <form action="backup_api.php" method="post" id="database-backup-settings-form">
          <input type="hidden" name="action" value="settings"><input type="hidden" name="return" value="page">
          <input type="hidden" name="csrf_token" value="<?= backup_ui_escape($_SESSION['csrf_backup']) ?>">
          <input type="hidden" name="version" value="<?= (int)$backupSettings['version'] ?>">
          <div class="dbt-settings-grid">
            <div><label for="backup-daily-time">Jam Backup (WIB)</label><input id="backup-daily-time" name="daily_time" type="time" step="60" value="<?= backup_ui_escape($backupSettings['daily_time']) ?>" required<?= empty($backupSummary['configured']) ? ' disabled' : '' ?>></div>
            <div><label for="backup-retention-days">Simpan Cadangan Selama (hari)</label><input id="backup-retention-days" name="retention_days" type="number" min="1" max="365" step="1" value="<?= (int)$backupSettings['retention_days'] ?>" required aria-describedby="backup-retention-hint"<?= empty($backupSummary['configured']) ? ' disabled' : '' ?>></div>
            <button type="submit" id="backup-settings-save" class="dbt-button dbt-button-primary"<?= empty($backupSummary['configured']) ? ' disabled' : '' ?>>Simpan Pengaturan</button>
          </div>
          <p class="dbt-hint" id="backup-retention-hint">1–365 hari. Cadangan otomatis lama dibersihkan setelah backup otomatis baru terverifikasi. Cadangan manual tetap disimpan.</p>
          <p class="dbt-hint" id="backup-settings-status"><?= !empty($backupSettings['activated']) ? 'Aktif: setiap hari pukul '.backup_ui_escape($backupClock).' WIB · penyimpanan '.(int)$backupSettings['retention_days'].' hari.' : 'Pengaturan belum disimpan. Jadwal tetap 18.00 WIB dan cadangan lama tetap disimpan.' ?></p>
          <div id="backup-settings-feedback" class="dbt-feedback" role="status" aria-live="polite" hidden></div>
        </form>
      </section>
      <div class="dbt-actions">
        <article class="dbt-card">
          <header class="dbt-card-heading"><span class="dbt-icon"><?= backup_ui_icon('download') ?></span><div><h2>Buat Backup Database</h2><p>Cadangan disimpan secara lokal dan diuji sebelum tersedia untuk diunduh.</p></div></header>
          <dl class="dbt-definition"><div><dt>Cakupan</dt><dd>SD, SMP, dan SMA</dd></div><div><dt>Format</dt><dd>SQL (.sql)</dd></div><div><dt>Ruang tersedia</dt><dd id="backup-free-space"><?= backup_ui_bytes($backupSummary['free_bytes'] ?? null) ?></dd></div></dl>
          <form action="backup_api.php" method="post" id="database-backup-form" data-backup-create="true">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= backup_ui_escape($_SESSION['csrf_backup']) ?>">
            <input type="hidden" name="request_key" value="<?= backup_ui_escape($_SESSION['backup_request_key']) ?>">
            <input type="hidden" name="return" value="page">
            <button type="submit" class="dbt-button dbt-button-primary dbt-wide" id="backup-create"<?= !$backupCanCreate ? ' disabled' : '' ?> aria-describedby="backup-create-hint"><?= backup_ui_icon('download') ?> <span>Buat Backup Sekarang</span></button>
          </form>
          <p class="dbt-hint" id="backup-create-hint"><?= $backupCanCreate ? ($backupActive ? 'Proses sebelumnya terputus. Klik untuk memeriksa dan melanjutkan antrean.' : 'Backup manual langsung diproses tanpa menunggu jadwal harian.') : ($backupActive ? 'Satu pencadangan sedang berlangsung. Progres tampil di bawah.' : 'Pemicu backup manual belum tersedia. Periksa konfigurasi backup pada PC ini.') ?></p>
          <div id="backup-progress" class="dbt-backup-progress" aria-live="polite"<?= !$backupActive ? ' hidden' : '' ?>>
            <div class="dbt-progress-heading"><strong id="backup-progress-state"><?= backup_ui_escape($backupStates[$backupActive['state'] ?? ''] ?? 'Menunggu') ?></strong><a href="backup_restore.php" class="dbt-text-button">Perbarui</a></div>
            <ol aria-label="Tahapan backup"><?php foreach (['queued'=>'Menunggu','dumping'=>'Mencadangkan','verifying'=>'Memverifikasi','verified'=>'Terverifikasi'] as $state=>$label): ?><li data-backup-stage="<?= $state ?>"<?= ($backupActive['state'] ?? '') === $state ? ' aria-current="step"' : '' ?>><?= $label ?></li><?php endforeach; ?></ol>
            <p class="dbt-hint" id="backup-progress-message"><?= backup_ui_escape($backupActive['message'] ?? '') ?></p>
          </div>
        </article>
        <article class="dbt-card">
          <header class="dbt-card-heading"><span class="dbt-icon"><?= backup_ui_icon('database') ?></span><div><h2>Restore Database</h2><p>Periksa cadangan, lalu konfirmasi pemulihan seluruh database.</p></div></header>
          <div class="dbt-warning"><strong>Pemulihan akan menggantikan seluruh data database saat ini.</strong><p>Pemilihan file di tahap ini tidak menjalankan pemulihan.</p></div>
          <?php backup_ui_picker('restore', 'Pilih file SQL untuk pratinjau pemulihan'); ?>
          <button type="button" class="dbt-button dbt-button-outline" id="restore-review" disabled>Periksa File &amp; Tinjau Pemulihan</button>
          <p class="dbt-file-status" id="restore-job-status" role="status" aria-live="polite"></p>
          <a href="restore_status.php" class="dbt-text-button" id="restore-progress-link" hidden>Lihat Progres Pemulihan</a>
        </article>
      </div>
      <section class="dbt-card dbt-history" aria-labelledby="history-title">
        <header class="dbt-history-heading"><div class="dbt-card-heading"><span class="dbt-icon"><?= backup_ui_icon('clock') ?></span><div><h2 id="history-title">Riwayat Backup</h2><p id="backup-history-count"><?= number_format(count($backupJobs),0,',','.') ?> cadangan ditampilkan</p></div></div><form action="backup_restore.php" method="get" class="dbt-history-controls" id="backup-history-filters"><input type="search" name="q" aria-label="Cari backup" placeholder="Cari nama atau pembuat" value="<?= backup_ui_escape($backupSearch) ?>" maxlength="200"><select name="type" aria-label="Filter tipe backup"><option value="">Semua tipe</option><?php foreach ($backupTypes as $value=>$label): ?><option value="<?= $value ?>"<?= $backupType === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><button type="submit" class="dbt-button dbt-button-outline">Cari</button><?php if ($backupSearch !== '' || $backupType !== ''): ?><a href="backup_restore.php" class="dbt-text-button">Reset</a><?php endif; ?></form></header>
        <div class="dbt-table-scroll"><table><thead><tr><?php foreach (['Nama Backup','Tipe','Tanggal','Ukuran','Pembuat','Status','Aksi'] as $column): ?><th scope="col"><?= $column ?></th><?php endforeach; ?></tr></thead><tbody id="backup-history-body">
        <?php if (!$backupJobs): ?><tr><td colspan="7" class="dbt-empty"><div class="dbt-empty-content"><?= backup_ui_icon('file') ?><strong><?= $backupSearch !== '' || $backupType !== '' ? 'Tidak ada cadangan yang cocok' : 'Belum ada cadangan' ?></strong><p><?= $backupSearch !== '' || $backupType !== '' ? 'Ubah pencarian atau filter tipe.' : 'Cadangan akan tampil di sini setelah pencadangan dimulai.' ?></p></div></td></tr><?php endif; ?>
        <?php foreach ($backupJobs as $job): ?><tr>
          <td><strong><?= backup_ui_escape($job['name'] ?? '') ?></strong><?php if (!empty($job['message'])): ?><span class="dbt-row-message"><?= backup_ui_escape($job['message']) ?></span><?php endif; ?><?php if (($job['type'] ?? '') === 'catchup' && !empty($job['scheduled_for'])): ?><span class="dbt-row-message">Jadwal terlewat: <?= backup_ui_escape(backup_ui_date($job['scheduled_for'])) ?></span><?php endif; ?></td>
          <td><?= backup_ui_escape($backupTypes[$job['type'] ?? ''] ?? '—') ?></td><td><?= backup_ui_escape(backup_ui_date($job['created_at'] ?? null)) ?></td><td><?= backup_ui_bytes($job['bytes'] ?? 0) ?></td><td><?= backup_ui_escape($job['actor_name'] ?? 'Otomatis') ?></td>
          <td><span class="dbt-job-status is-<?= backup_ui_escape($job['state'] ?? 'queued') ?>"><?= backup_ui_escape($backupStates[$job['state'] ?? ''] ?? 'Menunggu') ?></span></td><td><?php if (($job['state'] ?? '') === 'verified' && !empty($job['download_url'])): ?><a class="dbt-button dbt-button-outline dbt-download" href="<?= backup_ui_escape($job['download_url']) ?>"><?= backup_ui_icon('download') ?> Unduh</a><?php else: ?><span class="dbt-hint">—</span><?php endif; ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="dbt-hint">Jika komputer tidak tersedia saat jadwal backup, backup susulan dijalankan setelah layanan kembali tersedia.</p>
      </section>
    </section>
    <section id="panel-legacy" class="legacy-ui" role="tabpanel" aria-labelledby="tab-legacy" <?= !$legacyTab?'hidden':'' ?>>
      <div class="dbt-heading"><div><span class="legacy-eyebrow">IMPORT LEGACY</span><h2>Pilih data yang ingin dipindahkan</h2><p>Unit aktif: <strong><?= backup_ui_escape(unit_label(unit_active_id())) ?></strong></p></div></div>
      <div class="legacy-goals">
      <?php foreach([
        ['Migrasi Penuh','Pindahkan siswa dan keuangan agar server baru dengan unit kosong siap menggantikan sistem lama.','legacy_operational_review.php','upload',true],
        ['Impor Siswa','Tambahkan identitas siswa untuk ditinjau dan diaktifkan di Data Siswa, tanpa memindahkan keuangan.','legacy_import.php?mode=students','users',false],
        ['Histori Keuangan','Simpan transaksi lama sebagai arsip, lalu tinjau saldo dan tunggakan bagi siswa yang sudah diimpor.','legacy_import.php?mode=finance','file',false]
      ] as [$goal,$description,$href,$icon,$primary]): ?>
      <article class="legacy-goal <?= $primary?'is-primary':'' ?>"><?php if($primary): ?><span class="legacy-eyebrow">PINDAH KE SERVER BARU</span><?php endif; ?><?= legacy_ui_icon($icon) ?><h3><?= backup_ui_escape($goal) ?></h3><p><?= backup_ui_escape($description) ?></p><a class="dbt-button <?= $primary?'dbt-button-primary':'dbt-button-outline' ?>" href="<?= backup_ui_escape($href) ?>">Buka <?= backup_ui_escape($goal) ?> <?= legacy_ui_icon('next') ?></a></article>
      <?php endforeach; ?></div>
      <?php legacy_ui_recent(unit_active_id()); ?>
      <a class="dbt-button dbt-button-outline" href="legacy_finance.php?tab=archive">Arsip Histori</a>
      <a class="dbt-button dbt-button-outline" href="legacy_finance.php?tab=reviews">Peninjauan Saldo &amp; Tunggakan</a>
    </section>
    <noscript><div class="dbt-warning">Backup tetap dapat dibuat dan diunduh tanpa JavaScript. Muat ulang halaman untuk melihat progres. Migrasi Penuh tersedia melalui formulir biasa pada tautan di atas.</div></noscript>
  </div>
</main>
</div>
<dialog id="restore-dialog" class="dbt-dialog restore-dialog" aria-labelledby="restore-dialog-title" aria-describedby="restore-dialog-description">
  <div class="dbt-dialog-content">
    <header class="restore-dialog-heading"><span class="dbt-icon"><?= backup_ui_icon('database') ?></span><div><h2 id="restore-dialog-title" tabindex="-1" autofocus>Pulihkan Database</h2><p>Seluruh unit: SD, SMP, dan SMA</p></div></header>
    <p id="restore-dialog-description" class="restore-notice">Data dan akun diganti sesuai cadangan yang dipilih. Backup pengaman dibuat otomatis sebelum penggantian.</p>
    <dl class="restore-file-details"><div><dt>File cadangan</dt><dd id="restore-dialog-file"></dd></div><div><dt>Status</dt><dd id="restore-validation-state">Belum divalidasi</dd></div></dl>
    <?php database_restore_progress_ui(); database_restore_confirmation_ui(); ?>
    <div class="dbt-dialog-actions"><button type="button" class="dbt-button dbt-button-outline" id="restore-cancel">Tutup</button><button type="button" class="dbt-button dbt-button-primary" id="restore-apply" disabled aria-describedby="restore-unavailable">Pulihkan Database</button><button type="button" class="dbt-button dbt-button-outline" id="restore-resume" hidden>Lanjutkan Pemulihan Pengaman</button><a href="login.php" class="dbt-button dbt-button-outline" id="restore-login" hidden>Masuk Kembali</a></div>
  </div>
</dialog>
<script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<script src="assets/js/backup_restore.js?v=<?= filemtime(__DIR__ . '/assets/js/backup_restore.js') ?>"></script>
<script type="application/json" id="database-restore-config"><?= json_encode(['csrf'=>$_SESSION['csrf_backup'],'job'=>$_SESSION['database_restore_job']??null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="assets/js/database_restore.js?v=<?= filemtime(__DIR__.'/assets/js/database_restore.js') ?>"></script>
<script id="database-backup-config" type="application/json"><?= json_encode(['summary'=>$backupSummary,'search'=>$backupSearch,'type'=>$backupType], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="assets/js/database_backup.js?v=<?= filemtime(__DIR__ . '/assets/js/database_backup.js') ?>"></script>





</body>
</html>
