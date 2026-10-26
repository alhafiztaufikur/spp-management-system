<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/biaya_lain.php';
require_once 'includes/other_fee_usage.php';
require_once 'includes/legacy_finance.php';
requireRole(['admin', 'kasir']);

if (empty($_SESSION['csrf_master_biaya_lain'])) $_SESSION['csrf_master_biaya_lain'] = bin2hex(random_bytes(32));

function master_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    return (float)str_replace(['.', ','], ['', '.'], (string)$value);
}

function master_redirect(): void {
    $tab=$_POST['master_tab']??$_GET['master_tab']??'aktif';if(!is_string($tab)||!in_array($tab,['aktif','nonaktif','hapus'],true))$tab='aktif';
    header('Location: master_biaya_lain.php?master_tab='.$tab.'#daftar-master-biaya');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

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
            if(!is_string($_POST['target']??null)||!is_string($_POST['expected_master']??null)||!is_array($_POST['target_ids']??[])||!is_array($_POST['expected_student_ids']??null))throw new RuntimeException('Formulir penerbitan tidak valid.');
            $koneksi->begin_transaction();$result=other_fee_publish($koneksi,(int)($_POST['master_id']??0),$_POST['target'],$_POST['target_ids']??[],$_POST['expected_master'],$_POST['expected_student_ids']);$koneksi->commit();
            $_SESSION['flash']=['type'=>'success','msg'=>$result['created'].' tagihan diterbitkan; '.$result['skipped'].' siswa sudah memiliki tagihan master ini.'];
        }catch(Throwable $error){$koneksi->rollback();$_SESSION['flash']=['type'=>'error','msg'=>$error instanceof mysqli_sql_exception?'Penerbitan gagal. Muat ulang dan coba lagi.':$error->getMessage()];}
        master_redirect();
    }

    if ($aksi === 'tambah' || $aksi === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $nominal = master_amount($_POST['nominal'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if(legacy_finance_ready($koneksi)&&preg_match('/^Tunggakan Legacy(?:\s|[·:]|$)/iu',$nama)){
            $_SESSION['flash']=['type'=>'error','msg'=>'Nama Tunggakan Legacy khusus untuk pengesahan melalui Import Legacy.'];master_redirect();
        }
        $jenis=$_POST['jenis_pembayaran']??'berulang';
        if(!is_string($jenis)||!in_array($jenis,['berulang','tidak_berulang'],true)){$_SESSION['flash']=['type'=>'error','msg'=>'Jenis pembayaran tidak valid.'];master_redirect();}

        if ($nama === '' || mb_strlen($nama) > 100) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nama biaya wajib diisi dan maksimal 100 karakter.'];
            master_redirect();
        }
        $tariffPending=$nominal===0.0&&$isActive===0&&$id>0&&(int)$koneksi->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_biaya_lain' AND COLUMN_NAME='legacy_tariff_pending'")->fetch_row()[0]===1&&lf_one($koneksi,'SELECT id FROM master_biaya_lain WHERE id=? AND legacy_tariff_pending=1',[$id]);
        if ($nominal <= 0 && !$tariffPending) {
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
            $clearPending=$nominal>0&&(int)$koneksi->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_biaya_lain' AND COLUMN_NAME='legacy_tariff_pending'")->fetch_row()[0]===1;
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
            if($targetStatus&&((float)$current['nominal']<=0))throw new DomainException('Isi tarif positif sebelum mengaktifkan master ini.');
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

try {$masterRows=other_fee_master_usage($koneksi);}
catch(Throwable $error){error_log('Pembacaan pemakaian master biaya: '.$error->getMessage());http_response_code(503);exit('Pemakaian master biaya belum dapat dibaca. Muat ulang sebelum mengubah atau menghapus master.');}
usort($masterRows,static fn($a,$b)=>(int)$b['is_active']<=>(int)$a['is_active']?:strnatcasecmp($a['nama'],$b['nama']));
$masterTab=is_string($_GET['master_tab']??null)&&in_array($_GET['master_tab'],['aktif','nonaktif','hapus'],true)?$_GET['master_tab']:'aktif';
$masterCounts=['aktif'=>0,'nonaktif'=>0,'hapus'=>0];
foreach($masterRows as &$master){$master['reserved']=lf_reserved_master($koneksi,(int)$master['id']);$masterCounts[$master['is_active']?'aktif':'nonaktif']++;if($master['can_delete'])$masterCounts['hapus']++;}unset($master);
$masterRows=array_values(array_filter($masterRows,static fn($m)=>$masterTab==='hapus'?$m['can_delete']:(bool)$m['is_active']===($masterTab==='aktif')));
$activeMasters = $koneksi->query("SELECT * FROM master_biaya_lain WHERE is_active=1 AND jenis_pembayaran='tidak_berulang' ORDER BY nama")->fetch_all(MYSQLI_ASSOC);
$activeMasters=array_values(array_filter($activeMasters,static fn($master)=>!lf_reserved_master($koneksi,(int)$master['id'])));
$activeClasses = array_values(array_filter(class_all($koneksi,true),static fn($c)=>in_array(class_kind($c),['reguler','psb'],true)));
$activeStudents = $koneksi->query("SELECT s.id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,s.KELAS,s.master_kelas_id,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1 AND s.legacy_pending=0 AND s.KELAS NOT IN ('CUSTOM','OUT') ORDER BY s.NAMA")->fetch_all(MYSQLI_ASSOC);
require_once __DIR__ . '/includes/master_workspace_ui.php';
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
      <section class="main-card fee-hero">
          <div class="fee-hero-copy">
            <span class="recap-class-overline">Data Master</span>
            <h1>Master Biaya Lain</h1>
            <p>Kelola biaya tambahan di luar SPP. Berulang dibayar langsung; Tidak Berulang diterbitkan sekali per siswa.</p>
            <div class="fee-hero-stats">
              <div class="fee-stat"><span class="fee-icon"><?= master_workspace_icon('document') ?></span><div><span>Biaya Aktif</span><strong><?= number_format(count($activeMasters)) ?></strong></div></div>
              <div class="fee-stat"><span class="fee-icon"><?= master_workspace_icon('students') ?></span><div><span>Rombel Aktif</span><strong><?= number_format(count($activeClasses)) ?></strong></div></div>
              <div class="fee-stat"><span class="fee-icon"><?= master_workspace_icon('student') ?></span><div><span>Siswa Aktif</span><strong><?= number_format(count($activeStudents)) ?></strong></div></div>
            </div>
          </div>
          <div class="fee-hero-art" aria-hidden="true">
            <svg viewBox="0 0 340 220" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="190" cy="116" r="94" fill="currentColor" opacity=".06"/>
              <path d="M27 192h291" stroke="currentColor" stroke-width="2" opacity=".2"/>
              <rect x="81" y="30" width="151" height="158" rx="14" class="fee-art-paper" stroke="currentColor" stroke-opacity=".25" stroke-width="2"/>
              <rect x="125" y="20" width="62" height="22" rx="7" fill="currentColor" opacity=".2"/>
              <path d="M107 64h97" stroke="currentColor" stroke-width="7" stroke-linecap="round" opacity=".65"/>
              <g stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="102" y="88" width="18" height="18" rx="4"/><path d="m107 97 3 3 6-7M134 97h67"/>
                <rect x="102" y="121" width="18" height="18" rx="4"/><path d="m107 130 3 3 6-7M134 130h53"/>
                <rect x="102" y="154" width="18" height="18" rx="4"/><path d="m107 163 3 3 6-7M134 163h65"/>
              </g>
              <rect x="221" y="105" width="81" height="87" rx="12" class="fee-art-paper" stroke="currentColor" stroke-width="2"/>
              <rect x="235" y="119" width="53" height="17" rx="4" fill="currentColor" opacity=".18"/>
              <path d="M239 150h6m15 0h6m15 0h3m-45 16h6m15 0h6m15 0h3" stroke="currentColor" stroke-width="6" stroke-linecap="round" opacity=".7"/>
              <circle cx="63" cy="171" r="26" class="fee-art-paper" stroke="currentColor" stroke-width="2"/>
              <path d="M58 161v20m0-20h6a6 6 0 0 1 0 12h-6m6 0 7 8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
      </section>

      <div class="fee-panel-grid">
      <div class="main-card master-modern-card master-modern-form fee-panel">
        <div class="card-title-row fee-panel-heading">
          <span class="fee-icon"><?= master_workspace_icon($editData ? 'document' : 'document-add') ?></span>
          <div><h2><?= $editData ? 'Edit Master Biaya' : 'Tambah Master Biaya' ?></h2><?php if($editData): ?><p>Perbarui jenis biaya; transaksi lama tetap memakai tarif sebelumnya.</p><?php endif; ?></div>
        </div>
        <form method="POST" action="master_biaya_lain.php" id="form-master-biaya">
          <input type="hidden" name="master_tab" value="<?= htmlspecialchars($masterTab) ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
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
              <div class="fee-amount-control"><span aria-hidden="true">Rp</span>
              <input class="field-input rupiah-input" id="nominal-biaya" name="nominal" inputmode="numeric" required
                placeholder="0" value="<?= $editData ? number_format((float)$editData['nominal'], 0, ',', '.') : '' ?>" />
              </div>
            </div>
            <div class="field-row"><label class="field-label" for="jenis-pembayaran">Jenis Pembayaran</label><select class="field-input field-select" id="jenis-pembayaran" name="jenis_pembayaran"><option value="berulang" <?= ($editData['jenis_pembayaran']??'berulang')==='berulang'?'selected':'' ?>>Berulang — langsung tersedia</option><option value="tidak_berulang" <?= ($editData['jenis_pembayaran']??'')==='tidak_berulang'?'selected':'' ?>>Tidak Berulang — terbitkan tagihan</option></select><small class="payment-auto-note">Perubahan jenis tidak mengubah transaksi dan tagihan lama.</small></div>
            <label class="master-active-check">
              <input type="checkbox" name="is_active" value="1" <?= !$editData || (int)$editData['is_active'] === 1 ? 'checked' : '' ?> />
              <span class="fee-switch-track" aria-hidden="true"></span>
              <span>Aktif dan tampil di pembayaran<small>Berulang tampil langsung; Tidak Berulang memerlukan penerbitan.</small></span>
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

      <div class="main-card master-modern-card master-modern-list fee-list-panel" id="daftar-master-biaya" style="margin-top:0">
        <div class="card-title-row fee-panel-heading"><span class="fee-icon"><?= master_workspace_icon('document') ?></span><div><h2>Daftar Master Biaya (<?= count($masterRows) ?>)</h2></div></div>
        <nav class="fee-master-tabs" aria-label="Bagian master biaya"><?php foreach(['aktif'=>'Aktif','nonaktif'=>'Nonaktif','hapus'=>'Bisa Dihapus'] as $tab=>$label): ?><a href="master_biaya_lain.php?master_tab=<?= $tab ?>#daftar-master-biaya" <?= $masterTab===$tab?'aria-current="page"':'' ?>><?= $label ?><span class="badge-count"><?= $masterCounts[$tab] ?></span></a><?php endforeach; ?></nav>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>Nama Biaya</th><th>Nominal</th><th>Jenis</th><th>Status</th><th>Tagihan Terbit</th><th>Transaksi</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php if (!$masterRows): ?>
              <tr><td colspan="8"><div class="empty-state"><p>Tidak ada master pada bagian ini</p><span><?= $masterTab==='aktif'&&$masterCounts['nonaktif']>0?'Master hasil migrasi tersedia pada tab Nonaktif. Tarifnya belum ditetapkan untuk pembayaran baru.':'Pilih bagian lain atau tambahkan master biaya.' ?></span></div></td></tr>
              <?php else: $no = 1; foreach ($masterRows as $item): ?>
              <tr>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="Nama Biaya"><strong><?= unit_record_badge($item) ?><?= htmlspecialchars($item['nama']) ?></strong></td>
                <td data-label="Nominal" class="nominal"><?= lf_reserved_master($koneksi,(int)$item['id'])?'Per pengesahan':(!empty($item['legacy_tariff_pending'])&&(float)$item['nominal']===0.0?'Tarif belum ditetapkan':'Rp '.number_format((float)$item['nominal'],0,',','.')) ?></td>
                <td data-label="Jenis"><?= $item['jenis_pembayaran']==='berulang'?'Berulang':'Tidak Berulang' ?></td><td data-label="Status"><span class="master-status <?= $item['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $item['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td data-label="Tagihan Terbit"><span class="badge-count"><?= (int)$item['jumlah_tagihan'] ?></span></td>
                <td data-label="Transaksi"><div class="fee-master-transactions">
                  <?php if($item['jumlah_pemakaian']): ?>
                    <span class="fee-transaction-caption"><?= number_format($item['jumlah_rincian'],0,',','.') ?> rincian</span>
                    <a class="fee-transaction-link" href="pembayaran/lihat.php?<?= htmlspecialchars(http_build_query(['fee_master_id'=>(int)$item['id'],'all_periods'=>'1'])) ?>" aria-label="Riwayat pembayaran <?= htmlspecialchars($item['nama'],ENT_QUOTES,'UTF-8') ?>"><?= master_workspace_icon('document') ?><span>Pembayaran</span><strong><?= number_format($item['jumlah_pemakaian'],0,',','.') ?></strong></a>
                    <div class="fee-transaction-amount"><span>Total</span><strong>Rp <?= number_format((float)$item['nominal_pemakaian'],2,',','.') ?></strong></div>
                  <?php else: ?><span class="fee-transaction-empty">Belum ada transaksi</span><?php endif; ?>
                  <?php if($item['jumlah_batal']): ?><a class="fee-transaction-link is-cancelled" href="pembayaran/lihat.php?<?= htmlspecialchars(http_build_query(['fee_master_id'=>(int)$item['id'],'all_periods'=>'1','view'=>'deleted'])) ?>" aria-label="Riwayat transaksi dibatalkan <?= htmlspecialchars($item['nama'],ENT_QUOTES,'UTF-8') ?>"><?= master_workspace_icon('document') ?><span>Dibatalkan</span><strong><?= number_format($item['jumlah_batal'],0,',','.') ?></strong></a><?php endif; ?>
                </div></td>
                <td data-label="Aksi" class="aksi-col">
                  <?php if(lf_reserved_master($koneksi,(int)$item['id'])): ?><span class="master-status">Pengesahan Legacy</span><?php else: ?>
                  <a class="btn-tbl btn-tbl-edit" href="master_biaya_lain.php?master_tab=<?= $masterTab ?>&amp;edit=<?= (int)$item['id'] ?>">Edit</a>
                  <form method="POST" action="master_biaya_lain.php" style="display:inline">
                    <input type="hidden" name="master_tab" value="<?= htmlspecialchars($masterTab) ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
                    <input type="hidden" name="aksi" value="toggle" /><input type="hidden" name="id" value="<?= (int)$item['id'] ?>" /><input type="hidden" name="target_active" value="<?= $item['is_active'] ? '0' : '1' ?>" />
                    <button class="btn-tbl btn-tbl-toggle" type="submit"><?= $item['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                  </form>
                  <form method="POST" action="master_biaya_lain.php" style="display:inline" onsubmit="return confirm('Hapus master biaya <?= htmlspecialchars(addslashes($item['nama'])) ?>?')">
                    <input type="hidden" name="master_tab" value="<?= htmlspecialchars($masterTab) ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_biaya_lain']) ?>" />
                    <input type="hidden" name="aksi" value="hapus" /><input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
                    <button class="btn-tbl btn-tbl-del" type="submit" <?= !$item['can_delete'] ? 'disabled title="Sudah memiliki tagihan atau riwayat transaksi"' : '' ?>>Hapus</button>
                  </form>
                  <?php endif; ?>
                  <?php if(!$item['can_delete']): ?><small class="fee-master-lock"><?= $item['reserved']?'Dikelola melalui pengesahan Legacy.':'Tidak dapat dihapus: sudah memiliki tagihan atau transaksi.' ?></small><?php endif; ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      </div>
    </main>
  </div>
  <script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
  <script src="assets/js/workflow_dialogs.js?v=<?= filemtime(__DIR__.'/assets/js/workflow_dialogs.js') ?>"></script>
  <script src="assets/js/other_fee_publish.js?v=<?= filemtime(__DIR__.'/assets/js/other_fee_publish.js') ?>"></script>
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
      autoHideFlash();
    });
  </script>
</body>
</html>
