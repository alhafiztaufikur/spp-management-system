<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/biaya_lain.php';
require_once 'includes/other_fee_usage.php';
require_once 'includes/legacy_finance.php';
require_once 'includes/pagination.php';
require_once 'includes/master_workspace_ui.php';
requireRole(['admin', 'kasir']);

if (empty($_SESSION['csrf_master_biaya_lain'])) $_SESSION['csrf_master_biaya_lain'] = bin2hex(random_bytes(32));

$masterTab = $_GET['master_tab'] ?? 'aktif';
if (!in_array($masterTab, ['aktif','nonaktif','hapus'], true)) $masterTab = 'aktif';
$section = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_GET['section'] ?? '') : '';
if (in_array($section, ['usage','roster'], true)) {
    header('Cache-Control: private, no-store');
    if (($_GET['scope'] ?? null) !== (string)unit_active_id()) {
        http_response_code(409);
        exit;
    }
    session_write_close();
}

if ($section === 'roster') {
    if (unit_all_readonly()) { http_response_code(403); exit; }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $classes = array_values(array_filter(class_all($koneksi, true), static fn($class) => in_array(class_kind($class), ['reguler','psb'], true)));
        $students = hp_rows($koneksi, "SELECT id,NO_INDUK,NAMA,master_kelas_id FROM siswa WHERE ".other_fee_publish_eligible()." ORDER BY NAMA,id");
        echo json_encode([
            'classes' => array_map(static fn($c) => ['id'=>(int)$c['id'],'label'=>$c['label']], $classes),
            'students' => array_map(static fn($s) => ['id'=>(int)$s['id'],'name'=>$s['NAMA'],'nis'=>$s['NO_INDUK'],'class_id'=>(int)$s['master_kelas_id']], $students),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        error_log('Sasaran penerbitan Biaya Lain: '.$error->getMessage());
        http_response_code(503);
        echo '{}';
    }
    exit;
}

$feeModes = ['berulang'=>'Berulang', 'tidak_berulang'=>'Tidak Berulang'];
if (ofb_manual_ready($koneksi)) $feeModes['input_manual'] = 'Input Manual';

function master_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    return (float)str_replace(['.', ','], ['', '.'], (string)$value);
}

function master_redirect(): void {
    $tab=$_POST['master_tab']??$_GET['master_tab']??'aktif';if(!is_string($tab)||!in_array($tab,['aktif','nonaktif','hapus'],true))$tab='aktif';
    $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
    header('Location: '.page_url('master_biaya_lain.php', ['master_tab'=>$tab], $page).'#daftar-master-biaya');
    exit;
}

$flash = null;
if ($section !== 'usage') {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_master_biaya_lain'], (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
        master_redirect();
    }
    $aksi = $_POST['aksi'] ?? '';
    $legacyMasterId=(int)($_POST['master_id']??$_POST['id']??0);
    if(lf_reserved_master($koneksi,$legacyMasterId)){
        $_SESSION['flash']=['type'=>'error','msg'=>'Tunggakan Legacy dikelola melalui peninjauan per siswa.'];
        master_redirect();
    }

    if ($aksi === 'terbitkan_tagihan') {
        try {
            $targets = master_publish_ids('target_ids', []);
            $expected = master_publish_ids('expected_student_ids');
            if(!is_string($_POST['target']??null)||!is_string($_POST['expected_master']??null))throw new RuntimeException('Formulir penerbitan tidak valid.');
            $koneksi->begin_transaction();$result=other_fee_publish($koneksi,(int)($_POST['master_id']??0),$_POST['target'],$targets,$_POST['expected_master'],$expected);$koneksi->commit();
            $_SESSION['flash']=['type'=>'success','msg'=>$result['created'].' tagihan diterbitkan; '.$result['skipped'].' siswa sudah memiliki tagihan master ini.'];
        }catch(Throwable $error){$koneksi->rollback();$_SESSION['flash']=['type'=>'error','msg'=>$error instanceof mysqli_sql_exception?'Penerbitan gagal. Muat ulang dan coba lagi.':$error->getMessage()];}
        master_redirect();
    }

    if ($aksi === 'tambah' || $aksi === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nama = is_string($_POST['nama'] ?? null) ? trim($_POST['nama']) : '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if(legacy_finance_ready($koneksi)&&preg_match('/^Tunggakan Legacy(?:\s|[·:]|$)/iu',$nama)){
            $_SESSION['flash']=['type'=>'error','msg'=>'Nama Tunggakan Legacy khusus untuk pengesahan melalui Import Legacy.'];master_redirect();
        }
        $jenis=$_POST['jenis_pembayaran']??'berulang';
        if(!is_string($jenis)||!isset($feeModes[$jenis])){$_SESSION['flash']=['type'=>'error','msg'=>'Jenis pembayaran tidak valid.'];master_redirect();}
        $manual = $jenis === 'input_manual';
        $nominal = $manual ? 0.0 : master_amount($_POST['nominal'] ?? 0);

        if ($nama === '' || mb_strlen($nama) > 100) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nama biaya wajib diisi dan maksimal 100 karakter.'];
            master_redirect();
        }
        $hasPendingColumn = (int)$koneksi->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_biaya_lain' AND COLUMN_NAME='legacy_tariff_pending'")->fetch_row()[0] === 1;
        $pendingMaster = $aksi === 'update' && $id > 0 && $hasPendingColumn
            && lf_one($koneksi,'SELECT id FROM master_biaya_lain WHERE id=? AND legacy_tariff_pending=1',[$id]);
        if ($manual && $pendingMaster) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Tetapkan tarif master Legacy sebelum mengubah jenis pembayaran.'];
            master_redirect();
        }
        $tariffPending = $nominal === 0.0 && $isActive === 0 && $pendingMaster;
        if (!$manual && $nominal <= 0 && !$tariffPending) {
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
            $stmt = $koneksi->prepare('INSERT INTO master_biaya_lain (nama, nominal, is_active,jenis_pembayaran) VALUES (?, ?, ?,?)');
            $stmt->bind_param('sdis', $nama, $nominal, $isActive,$jenis);
            $berhasil = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $berhasil
                ? ['type' => 'success', 'msg' => "Biaya $nama berhasil ditambahkan."]
                : ['type' => 'error', 'msg' => 'Master biaya gagal ditambahkan.'];
        } else {
            if ($id <= 0) master_redirect();
            $clearPending=$nominal>0&&$hasPendingColumn;
            $stmt = $koneksi->prepare('UPDATE master_biaya_lain SET nama = ?, nominal = ?, is_active = ?,jenis_pembayaran=?'.($clearPending?',legacy_tariff_pending=0':'').' WHERE id = ?');
            $stmt->bind_param('sdisi', $nama, $nominal, $isActive, $jenis, $id);
            $berhasil = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $berhasil
                ? ['type' => 'success', 'msg' => "Biaya $nama berhasil diperbarui. Transaksi lama tetap memakai tarif sebelumnya."]
                : ['type' => 'error', 'msg' => 'Master biaya gagal diperbarui.'];
        }
        master_redirect();
    }

    if ($aksi === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $targetStatus = (string)($_POST['target_active'] ?? '');
        if (!in_array($targetStatus, ['0', '1'], true)) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Status tujuan master biaya tidak valid. Muat ulang halaman.'];
            master_redirect();
        }
        try {
            $koneksi->begin_transaction();
            $stmt = $koneksi->prepare('SELECT * FROM master_biaya_lain WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id); $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$current) throw new RuntimeException('Master biaya tidak ditemukan.');
            if($targetStatus&&$current['jenis_pembayaran']!=='input_manual'&&(float)$current['nominal']<=0)throw new DomainException('Isi tarif positif sebelum mengaktifkan master ini.');
            if ((int)$current['is_active'] === (int)$targetStatus) {
                $koneksi->commit();
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Status master biaya sudah sesuai. Tidak ada perubahan.'];
            } else {
                $newStatus = (int)$targetStatus;
                $stmt = $koneksi->prepare('UPDATE master_biaya_lain SET is_active=? WHERE id=?');
                $stmt->bind_param('ii', $newStatus, $id); $stmt->execute(); $stmt->close();
                $koneksi->commit();
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status master biaya berhasil diubah.'];
            }
        } catch (Throwable $error) {
            try { $koneksi->rollback(); } catch (Throwable $ignored) {}
            $_SESSION['flash'] = ['type' => 'error', 'msg' => $error->getMessage()];
        }
        master_redirect();
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $koneksi->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $koneksi->begin_transaction();
            // Penerbitan mengambil kunci master sebelum membuat tagihan. Hapus harus
            // mengambil kunci yang sama sebelum menghitung relasi dan menghapus.
            $stmt = $koneksi->prepare('SELECT id FROM master_biaya_lain WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id); $stmt->execute();
            $master = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$master) {
                $koneksi->commit();
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Master biaya sudah tidak tersedia.'];
                master_redirect();
            }

            $usage=other_fee_master_usage($koneksi,$id)[0]??null;
            if (!$usage || !$usage['can_delete']) {
                $koneksi->commit();
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Master sudah dipakai pada transaksi dan tidak dapat dihapus. Nonaktifkan agar tidak muncul di pembayaran baru.'];
            } else {
                $stmt = $koneksi->prepare('DELETE FROM master_biaya_lain WHERE id=?');
                $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
                $koneksi->commit();
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Master biaya berhasil dihapus.'];
            }
        } catch (Throwable $error) {
            try { $koneksi->rollback(); } catch (Throwable $ignored) {}
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Master biaya gagal dihapus karena masih dipakai atau data berubah.'];
        }
        master_redirect();
    }
}

function master_publish_ids(string $name, ?array $default = null): array {
    $json = $_POST[$name.'_json'] ?? null;
    $ids = $json === null || $json === '' ? ($_POST[$name] ?? $default) : (is_string($json) ? json_decode($json, false, 8) : null);
    if (!is_array($ids) || !array_is_list($ids)) throw new RuntimeException('Formulir penerbitan tidak valid.');
    return $ids;
}

function master_tab_counts(mysqli $db): array {
    $counts = ['aktif'=>0, 'nonaktif'=>0];
    foreach (hp_rows($db, 'SELECT is_active,COUNT(*) total FROM master_biaya_lain GROUP BY is_active') as $row) {
        $counts[$row['is_active'] ? 'aktif' : 'nonaktif'] = (int)$row['total'];
    }
    return $counts;
}

$perPage = 25;
$masterRows = [];
$masterPage = 1;
$masterPages = 1;
$detailsReady = $section === 'usage';
try {
    $koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $masterCounts = master_tab_counts($koneksi);
    $masterTotal = $masterCounts[$masterTab] ?? 0;
    if ($masterTab === 'hapus' && $detailsReady) {
        $deletable = array_values(array_filter(other_fee_master_usage($koneksi, orderByName: true), static fn($row) => $row['can_delete']));
        $masterTotal = count($deletable);
    }
    $masterPages = total_pages($masterTotal, $perPage);
    $masterPage = min(page_int_param('page'), $masterPages);
    $offset = ($masterPage - 1) * $perPage;
    if ($masterTab === 'hapus') {
        if ($detailsReady) $masterRows = array_slice($deletable, $offset, $perPage);
    } elseif ($masterTotal > 0) {
        $active = (int)($masterTab === 'aktif');
        $masterRows = hp_rows($koneksi, "SELECT * FROM master_biaya_lain WHERE is_active=? ORDER BY nama,id LIMIT $perPage OFFSET $offset", [$active]);
        if ($detailsReady) {
            $usage = array_column(other_fee_master_usage($koneksi, array_column($masterRows, 'id')), null, 'id');
            $masterRows = array_values(array_filter(array_map(static fn($row) => $usage[$row['id']] ?? null, $masterRows)));
        }
    }
    $koneksi->commit();
} catch (Throwable $error) {
    $koneksi->rollback();
    error_log('Pembacaan pemakaian master biaya: '.$error->getMessage());
    http_response_code(503);
    exit('Pemakaian master biaya belum dapat dibaca.');
}
if ($detailsReady) {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__.'/includes/other_fee_master_list.php';
    exit;
}

$editData = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    if(lf_reserved_master($koneksi,$editId))master_redirect();
    $stmt = $koneksi->prepare('SELECT * FROM master_biaya_lain WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$activeMasters = unit_all_readonly() ? [] : hp_rows($koneksi, "SELECT * FROM master_biaya_lain WHERE is_active=1 AND jenis_pembayaran='tidak_berulang' ORDER BY nama,id");
$activeMasters = array_values(array_filter($activeMasters, static fn($master) => !lf_reserved_master($koneksi, (int)$master['id'])));
$usageQuery = ['master_tab'=>$masterTab,'scope'=>unit_active_id(),'section'=>'usage','page'=>page_int_param('page')];
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Master Biaya Lain | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png?v=2" />
  <link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" />
  <link rel="stylesheet" href="assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/date_controls.css') ?>" />
  <link rel="stylesheet" href="assets/css/master_biaya_lain.css?v=<?= filemtime(__DIR__ . '/assets/css/master_biaya_lain.css') ?>" />
  <link rel="stylesheet" href="assets/css/workspace_readability.css?v=<?= filemtime(__DIR__ . '/assets/css/workspace_readability.css') ?>" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/css/rombel_payment_refinement.css?v=<?= filemtime(__DIR__.'/assets/css/rombel_payment_refinement.css') ?>">
<link rel="stylesheet" href="assets/css/payment_ui_polish.css?v=<?= filemtime(__DIR__ . '/assets/css/payment_ui_polish.css') ?>">
<link rel="stylesheet" href="assets/css/menu_refinements.css?v=<?= filemtime(__DIR__.'/assets/css/menu_refinements.css') ?>"></head>
<body data-readable-workspace="master-fees">
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content fee-workspace">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Toggle Sidebar">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2>Master Biaya Lain</h2><span class="breadcrumb">SistemSPP / Master Biaya Lain</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="fee-workspace-content">
      <section class="main-card fee-overview" aria-labelledby="fee-page-title">
        <h1 id="fee-page-title">Master Biaya Lain</h1>
        <dl><dt>Biaya Aktif</dt><dd><?= number_format($masterCounts['aktif'], 0, ',', '.') ?></dd></dl>
      </section>

      <div class="fee-panel-grid">
      <div class="main-card master-modern-card master-modern-form fee-panel">
        <div class="card-title-row fee-panel-heading">
          <div><h2><?= $editData ? 'Edit Master Biaya' : 'Tambah Master Biaya' ?></h2><?php if($editData): ?><p>Perbarui jenis biaya; transaksi lama tetap memakai tarif sebelumnya.</p><?php endif; ?></div>
        </div>
        <form method="POST" action="master_biaya_lain.php" id="form-master-biaya">
          <input type="hidden" name="master_tab" value="<?= htmlspecialchars($masterTab) ?>"><input type="hidden" name="page" value="<?= $masterPage ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
          <input type="hidden" name="aksi" value="<?= $editData ? 'update' : 'tambah' ?>" />
          <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>" />
          <div class="fields-grid master-fee-form">
            <div class="field-row">
              <label class="field-label" for="nama-biaya">Nama Biaya</label>
              <input class="field-input" id="nama-biaya" name="nama" maxlength="100" required
                placeholder="Contoh: Biaya Ekstrakurikuler" value="<?= htmlspecialchars($editData['nama'] ?? '') ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="jenis-pembayaran">Jenis Pembayaran</label>
              <select class="field-input field-select" id="jenis-pembayaran" name="jenis_pembayaran"><?php foreach($feeModes as $mode=>$label): ?>
                <option value="<?= $mode ?>" <?= ($editData['jenis_pembayaran']??'berulang')===$mode?'selected':'' ?>><?= $label ?></option>
              <?php endforeach; ?></select>
              <small class="payment-auto-note" id="fee-manual-note" <?= ($editData['jenis_pembayaran']??'')==='input_manual'?'':'hidden' ?>>Nominal diisi saat pembayaran.</small>
            </div>
            <?php $manualEdit = ($editData['jenis_pembayaran'] ?? '') === 'input_manual'; ?>
            <div class="field-row" id="fee-amount-row" <?= $manualEdit?'hidden':'' ?>>
              <label class="field-label" for="nominal-biaya">Nominal</label>
              <div class="fee-amount-control"><span aria-hidden="true">Rp</span>
              <input class="field-input rupiah-input" id="nominal-biaya" name="nominal" inputmode="numeric" <?= $manualEdit?'disabled':'required' ?>
                placeholder="0" value="<?= $editData && !$manualEdit ? number_format((float)$editData['nominal'], 0, ',', '.') : '' ?>" />
              </div>
            </div>
            <label class="master-active-check">
              <input type="checkbox" name="is_active" value="1" <?= !$editData || (int)$editData['is_active'] === 1 ? 'checked' : '' ?> />
              <span class="fee-switch-track" aria-hidden="true"></span>
              <span>Aktif</span>
            </label>
          </div>
          <div class="action-bar" style="margin-top:16px">
            <button type="submit" class="btn btn-primary"><?= master_workspace_icon($editData ? 'save' : 'plus') ?><?= $editData ? 'Simpan Perubahan' : 'Tambah Biaya' ?></button>
            <?php if ($editData): ?><a href="master_biaya_lain.php" class="btn btn-ghost">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>

      <?php include __DIR__.'/includes/other_fee_publish_ui.php'; ?>
      </div>

      <section class="main-card master-modern-card master-modern-list fee-list-panel" id="daftar-master-biaya"
          data-usage-url="<?= htmlspecialchars('master_biaya_lain.php?'.http_build_query($usageQuery), ENT_QUOTES, 'UTF-8') ?>"
          data-load-usage="<?= $masterRows || $masterTab === 'hapus' ? '1' : '0' ?>">
        <div class="card-title-row fee-panel-heading"><h2>Daftar Master Biaya</h2></div>
        <nav class="fee-master-tabs" aria-label="Bagian master biaya">
          <?php foreach (['aktif'=>'Aktif','nonaktif'=>'Nonaktif','hapus'=>'Bisa Dihapus'] as $tab => $label): ?>
            <a href="master_biaya_lain.php?master_tab=<?= $tab ?>#daftar-master-biaya" <?= $masterTab===$tab?'aria-current="page"':'' ?>>
              <?= $label ?><?php if ($tab !== 'hapus' || $masterTab === 'hapus'): ?>
                <span class="badge-count" <?= $tab === 'hapus' ? 'id="fee-delete-count" hidden' : '' ?>><?= $masterCounts[$tab] ?? '' ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <div class="fee-usage-feedback" <?= $masterRows || $masterTab === 'hapus' ? '' : 'hidden' ?>>
          <p id="fee-usage-status" role="status">Memuat rincian pemakaian…</p>
          <button type="button" class="btn btn-ghost btn-sm" id="fee-usage-retry" hidden>Coba Lagi</button>
          <noscript><a href="<?= htmlspecialchars('master_biaya_lain.php?'.http_build_query($usageQuery), ENT_QUOTES, 'UTF-8') ?>">Muat rincian pemakaian</a></noscript>
        </div>
        <?php require __DIR__.'/includes/other_fee_master_list.php'; ?>
      </section>
      </div>
    </main>
  </div>
  <script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
  <script src="assets/js/other_fee_publish.js?v=<?= filemtime(__DIR__.'/assets/js/other_fee_publish.js') ?>"></script>
  <script src="assets/js/other_fee_master.js?v=<?= filemtime(__DIR__.'/assets/js/other_fee_master.js') ?>"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var nominal = document.getElementById('nominal-biaya');
      const mode = document.getElementById('jenis-pembayaran');
      const amountRow = document.getElementById('fee-amount-row');
      const manualNote = document.getElementById('fee-manual-note');
      const syncFeeMode = () => {
        const manual = mode.value === 'input_manual';
        amountRow.hidden = manual;
        nominal.disabled = manual;
        nominal.required = !manual;
        manualNote.hidden = !manual;
      };
      mode?.addEventListener('change', syncFeeMode);
      if (mode) syncFeeMode();
      if (nominal) nominal.addEventListener('input', function () {
        var clean = this.value.replace(/\D/g, '');
        this.value = clean ? Number(clean).toLocaleString('id-ID') : '';
      });
      document.getElementById('form-master-biaya')?.addEventListener('submit', function () {
        nominal.value = nominal.value.replace(/\./g, '');
      });
      autoHideFlash();
    });
  </script>
</body>
</html>
