<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/daftar_ulang.php';
require_once 'includes/reports.php';
require_once 'includes/master_workspace_ui.php';
requireRole(['admin', 'kasir']);

if (empty($_SESSION['csrf_master_du'])) $_SESSION['csrf_master_du'] = bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_prior_debt'])) $_SESSION['csrf_prior_debt'] = bin2hex(random_bytes(32));

function master_du_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function master_du_amount($value): float { return (float)str_replace(['.', ','], ['', '.'], trim((string)$value)); }
function master_du_redirect(string $year): void { header('Location: master_daftar_ulang.php?tahun=' . urlencode($year)); exit; }
function master_du_find_year(mysqli $db, string $label, bool $lock = false): ?array {
    $stmt = $db->prepare('SELECT * FROM tahun_ajaran WHERE label = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $label); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
function master_du_year(mysqli $db, string $label, bool $lock = false): array {
    $row = master_du_find_year($db, $label, $lock);
    if (!$row) throw new RuntimeException('Tahun ajaran tidak ditemukan.');
    return $row;
}
function master_du_ensure_year(mysqli $db, string $label): array {
    [$start, $end] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'draft') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute(); $stmt->close();
    return master_du_year($db, $label);
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$rawYear = $isPost ? ($_POST['tahun_ajaran'] ?? null) : ($_GET['tahun'] ?? du_current_academic_year());
$selectedYear = is_string($rawYear) ? trim($rawYear) : '';
try { $selectedYear = du_normalize_academic_year($selectedYear); }
catch (Throwable $e) {
    if ($isPost) {
        $_SESSION['flash'] = ['type'=>'error', 'msg'=>'Tahun ajaran pada formulir tidak valid. Muat ulang halaman.'];
        master_du_redirect(du_current_academic_year());
    }
    $selectedYear = du_current_academic_year();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_master_du'], $token)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
        master_du_redirect($selectedYear);
    }
    $action = (string)($_POST['aksi'] ?? '');
    try {
        $koneksi->begin_transaction();
        if (in_array($action, ['simpan_dan_terbitkan', 'terbitkan'], true)) {
            master_du_ensure_year($koneksi, $selectedYear);
        }
        $year = master_du_year($koneksi, $selectedYear, true);
        if ($action === 'simpan_dan_terbitkan' && $year['status'] !== 'draft') {
            throw new RuntimeException('Tahun ajaran sudah berubah sejak formulir dibuka. Muat ulang halaman.');
        }
        $yearId = (int)$year['id'];

        if(in_array($action,['simpan_dan_terbitkan','terbitkan'],true)){
            $publishStudents=report_active_regular_student_nis($koneksi);
            $priorDebt=report_prior_debt_summary($koneksi,$selectedYear,$publishStudents);
            if($priorDebt['has_debt']&&(string)($_POST['confirm_previous_debt']??'0')!=='1')throw new RuntimeException('Masih ada tunggakan tahun sebelumnya. Konfirmasi diperlukan sebelum tagihan Daftar Ulang diterbitkan.');
        }

        if (in_array($action, ['simpan_tarif', 'simpan_dan_terbitkan'], true)) {
            if ($year['status'] === 'closed') throw new RuntimeException('Tahun ajaran sudah ditutup; tarif tidak dapat diubah.');
            $amounts = $_POST['jumlah'] ?? [];
            [$firstLevel, $lastLevel] = unit_level_bounds();
            for ($class = $firstLevel; $class <= $lastLevel; $class++) {
                $amount = master_du_amount($amounts[(string)$class] ?? $amounts[$class] ?? 0);
                if ($amount <= 0) throw new RuntimeException('Nominal kelas ' . $class . ' harus lebih dari Rp 0.');
                $classText = (string)$class;
                $stmt = $koneksi->prepare('SELECT id, Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id = ? AND kelas = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('is', $yearId, $classText); $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();

                if (!$existing) {
                    if ($year['status'] !== 'draft') throw new RuntimeException('Tarif kelas ' . $class . ' tidak dapat ditambahkan setelah tahun ajaran diterbitkan.');
                    $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang (tahun_ajaran_id, th_ajaran, kelas, Jumlah) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('issd', $yearId, $selectedYear, $classText, $amount); $stmt->execute();
                    $masterId = (int)$koneksi->insert_id; $stmt->close();
                    du_write_audit($koneksi, $yearId, $masterId, 'buat_tarif', null, ['kelas'=>$classText,'jumlah'=>$amount]);
                    continue;
                }

                $oldAmount = (float)$existing['Jumlah'];
                if (abs($oldAmount - $amount) < .001) continue;
                $affected = 0;
                if ($year['status'] === 'published') {
                    $existingId = (int)$existing['id'];
                    try {
                        $affected = du_sync_open_bills_for_master_rate(
                            $koneksi,
                            $existingId,
                            $selectedYear,
                            $oldAmount,
                            $amount
                        );
                    } catch (RuntimeException $error) {
                        if (str_contains($error->getMessage(), 'lebih kecil daripada cicilan')) {
                            throw new RuntimeException('Nominal baru kelas ' . $class . ' lebih kecil daripada cicilan siswa yang sudah masuk.');
                        }
                        throw $error;
                    }
                }
                $existingId = (int)$existing['id'];
                $stmt = $koneksi->prepare('UPDATE Daftar_ulang SET Jumlah=? WHERE id=?');
                $stmt->bind_param('di', $amount, $existingId); $stmt->execute(); $stmt->close();
                du_write_audit($koneksi, $yearId, $existingId, 'ubah_tarif', ['jumlah'=>$oldAmount], ['jumlah'=>$amount], $affected);
            }
            if ($year['status'] === 'draft') {
                $created = du_publish_year_from_active_students($koneksi, $yearId, $selectedYear);
                du_write_audit($koneksi, $yearId, null, 'terbitkan_tagihan', ['status'=>'draft'], ['status'=>'published','sumber_kelas'=>'data_siswa'], $created);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Tarif disimpan dan '.$created.' tagihan siswa berhasil diterbitkan.'];
            } else {
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Tarif dan tagihan yang belum lunas berhasil diperbarui.'];
            }
        } elseif ($action === 'terbitkan') {
            if ($year['status'] !== 'draft') throw new RuntimeException('Tahun ajaran ini sudah pernah diterbitkan.');
            $created = du_publish_year_from_active_students($koneksi, $yearId, $selectedYear);
            du_write_audit($koneksi, $yearId, null, 'terbitkan_tagihan', ['status'=>'draft'], ['status'=>'published','sumber_kelas'=>'data_siswa'], $created);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$created.' tagihan berhasil diterbitkan berdasarkan kelas pada Data Siswa.'];
        } elseif ($action === 'tutup') {
            if ($year['status'] !== 'published') throw new RuntimeException('Hanya tahun ajaran terbit yang dapat ditutup.');
            $stmt = $koneksi->prepare("UPDATE tahun_ajaran SET status='closed',closed_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, $yearId, null, 'tutup_tahun', ['status'=>'published'], ['status'=>'closed']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Tahun ajaran ditutup. Tunggakan yang sudah terbit tetap dapat dilunasi.'];
        } elseif ($action === 'buka') {
            if ($year['status'] !== 'closed') throw new RuntimeException('Hanya tahun ajaran tertutup yang dapat dibuka kembali.');
            $stmt = $koneksi->prepare("UPDATE tahun_ajaran SET status='published',closed_at=NULL WHERE id=?");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, $yearId, null, 'buka_tahun', ['status'=>'closed'], ['status'=>'published']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Tahun ajaran dibuka kembali. Histori pembayaran tetap dipertahankan.'];
        } else {
            throw new RuntimeException('Aksi Master Daftar Ulang tidak dikenal.');
        }
        $koneksi->commit();
    } catch (Throwable $error) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type'=>'error','msg'=>$error->getMessage()];
    }
    master_du_redirect($selectedYear);
}

$yearRowsRaw = $koneksi->query('SELECT * FROM tahun_ajaran ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);
$yearRowsByLabel = [];
foreach ($yearRowsRaw as $yearRow) $yearRowsByLabel[$yearRow['label']] = $yearRow;
$currentStart = (int)substr(du_current_academic_year(), 0, 4);
for ($offset=-2; $offset<=3; $offset++) {
    $start=$currentStart+$offset; $label=$start.'/'.($start+1);
    if (!isset($yearRowsByLabel[$label])) $yearRowsByLabel[$label]=['label'=>$label,'status'=>'draft'];
}
if (!isset($yearRowsByLabel[$selectedYear])) $yearRowsByLabel[$selectedYear]=['label'=>$selectedYear,'status'=>'draft'];
krsort($yearRowsByLabel); $yearRows=array_values($yearRowsByLabel);
$year=master_du_find_year($koneksi,$selectedYear) ?? ['id'=>0,'label'=>$selectedYear,'status'=>'draft'];
$yearId=(int)$year['id'];

[$firstLevel, $lastLevel] = unit_level_bounds();
$masters=array_fill($firstLevel,$lastLevel-$firstLevel+1,0.0);
$stmt=$koneksi->prepare('SELECT kelas,Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id=? ORDER BY CAST(kelas AS UNSIGNED)');
$stmt->bind_param('i',$yearId); $stmt->execute(); $result=$stmt->get_result();
while($row=$result->fetch_assoc()) $masters[(int)$row['kelas']]=(float)$row['Jumlah'];
$stmt->close();

$activeByClass=array_fill($firstLevel,$lastLevel-$firstLevel+1,0);
$result=$koneksi->query("SELECT KELAS,COUNT(*) total FROM siswa WHERE is_active=1 AND CAST(KELAS AS UNSIGNED) " . unit_level_between_sql() . " GROUP BY KELAS");
while($row=$result->fetch_assoc()) $activeByClass[(int)$row['KELAS']]=(int)$row['total'];
$activeTotal=array_sum($activeByClass);

$stmt=$koneksi->prepare("SELECT COUNT(*) bills,COALESCE(SUM(tdu.nominal_tagihan),0) total,COALESCE(SUM(p.paid),0) paid
    FROM tagihan_daftar_ulang tdu
    LEFT JOIN (SELECT tagihan_daftar_ulang_id,SUM(jumlah) paid FROM bayar_du GROUP BY tagihan_daftar_ulang_id) p ON p.tagihan_daftar_ulang_id=tdu.id
    WHERE tdu.tahun_ajaran_id=? AND tdu.status='open'");
$stmt->bind_param('i',$yearId); $stmt->execute(); $billSummary=$stmt->get_result()->fetch_assoc(); $stmt->close();
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Master Daftar Ulang | SistemSPP</title>
<link rel="icon" type="image/png" href="assets/img/favicon.png?v=2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__.'/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/date_controls.css?v=<?= filemtime(__DIR__.'/assets/css/date_controls.css') ?>">
<link rel="stylesheet" href="assets/css/master_workspace.css?v=<?= filemtime(__DIR__.'/assets/css/master_workspace.css') ?>">
<link rel="stylesheet" href="assets/css/workspace_readability.css?v=<?= filemtime(__DIR__.'/assets/css/workspace_readability.css') ?>">
<script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head><body data-readable-workspace="master-registration">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include 'includes/sidebar.php'; ?><main class="main-content du-workspace">
<div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2>Master Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Tahun Ajaran / Daftar Ulang</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<?php if($flash): ?><div class="alert alert-<?= master_du_e($flash['type']) ?>" id="flash-msg"><?= master_du_e($flash['msg']) ?></div><?php endif; ?>
<?php if($year['status']==='draft'): ?><script>window.priorDebtForms=[{id:'du-rate-form',scope:'all_active_regular',csrf:<?=json_encode($_SESSION['csrf_prior_debt'])?>}];</script><script src="assets/js/prior-debt-warning.js?v=1.0"></script><?php include 'includes/prior_debt_modal.php'; ?><?php endif; ?>
<section class="main-card du-master-hero master-modern-shell">
  <div class="master-modern-hero">
    <div><span class="recap-class-overline">Konfigurasi Tahun Ajaran</span><h1><?= master_du_e($selectedYear) ?></h1><p>Periode 1 Juli <?= substr($selectedYear,0,4) ?> sampai 30 Juni <?= substr($selectedYear,5,4) ?>.</p></div>
    <div class="master-modern-stats">
      <div><span class="master-workspace-icon"><?= master_workspace_icon('students') ?></span><div><span>Siswa Aktif</span><strong><?= number_format($activeTotal) ?></strong></div></div>
      <div><span class="master-workspace-icon"><?= master_workspace_icon('document') ?></span><div><span>Tagihan Terbit</span><strong><?= number_format((int)$billSummary['bills']) ?></strong></div></div>
      <div><span class="master-workspace-icon"><?= master_workspace_icon('coins') ?></span><div><span>Terbayar</span><strong>Rp <?= number_format((float)$billSummary['paid'],0,',','.') ?></strong></div></div>
    </div>
    <div class="du-master-year-tools">
      <form method="get"><select class="field-input field-select" name="tahun" aria-label="Pilih tahun ajaran" onchange="this.form.submit()">
        <?php foreach($yearRows as $yr): ?><option value="<?= master_du_e($yr['label']) ?>" <?= $yr['label']===$selectedYear?'selected':'' ?>><?= master_du_e($yr['label']) ?> &middot; <?= master_du_e(strtoupper($yr['status'])) ?></option><?php endforeach; ?>
      </select></form>
      <span class="recap-status <?= $year['status']==='draft'?'is-partial':($year['status']==='published'?'is-paid':'is-unpaid') ?>"><?= master_workspace_icon($year['status']==='closed'?'lock':($year['status']==='published'?'check':'document')) ?><?= master_du_e(strtoupper($year['status'])) ?></span>
    </div>
  </div>
</section>
<section class="main-card master-modern-card master-modern-form">
  <div class="student-panel-heading"><span class="master-workspace-icon"><?= master_workspace_icon('coins') ?></span><div><h3>Tarif Kelas <?= $firstLevel ?>&ndash;<?= $lastLevel ?></h3><p>Jumlah siswa dibaca langsung dari Data Siswa aktif. Pastikan kelas siswa sudah benar sebelum menerbitkan tagihan.</p></div></div>
  <form method="post" id="du-rate-form">
    <input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>">
    <input type="hidden" name="aksi" value="<?= $year['status']==='draft'?'simpan_dan_terbitkan':'simpan_tarif' ?>">
    <input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>">
    <div class="du-rate-grid">
      <?php $rateEstimateTotal=0; for($c=$firstLevel;$c<=$lastLevel;$c++): $rateEstimateTotal += $activeByClass[$c]*$masters[$c]; ?>
      <article class="du-rate-card" data-du-rate-card data-students="<?= (int)$activeByClass[$c] ?>">
        <div class="du-rate-card-heading"><span class="du-class-roman" aria-hidden="true"><?= master_workspace_roman($c) ?></span><div><h3>Kelas <?= $c ?></h3><small><?= (int)$activeByClass[$c] ?> siswa aktif</small></div></div>
        <div class="du-rate-input-row"><label for="du-rate-<?= $c ?>">Tarif per siswa</label><div class="du-rate-input-wrap"><span aria-hidden="true">Rp</span><input id="du-rate-<?= $c ?>" class="field-input rupiah-input" name="jumlah[<?= $c ?>]" inputmode="numeric" value="<?= $masters[$c]>0?number_format($masters[$c],0,',','.') : '' ?>" placeholder="0" <?= $year['status']==='closed'?'disabled':'' ?>></div></div>
        <div class="du-rate-estimate"><?= master_workspace_icon('calculator') ?><span>Estimasi total tagihan</span><strong data-du-estimate>Rp <?= number_format($activeByClass[$c]*$masters[$c],0,',','.') ?></strong></div>
      </article>
      <?php endfor; ?>
    </div>
    <div class="du-total-estimate">
      <div class="student-panel-heading"><span class="master-workspace-icon"><?= master_workspace_icon('calculator') ?></span><div><h3>Estimasi Total Tagihan</h3><strong data-du-total-estimate>Rp <?= number_format($rateEstimateTotal,0,',','.') ?></strong><small>Jumlah siswa aktif &times; tarif setiap kelas. Estimasi mengikuti nilai formulir.</small></div></div>
      <?php if($year['status']!=='closed'): ?><button class="btn btn-primary" type="submit"><?= master_workspace_icon('save') ?><?= $year['status']==='draft'?'Simpan & Terbitkan Tagihan':'Simpan Perubahan Tarif' ?></button><?php else: ?><span class="recap-status is-unpaid"><?= master_workspace_icon('lock') ?>Tarif terkunci</span><?php endif; ?>
    </div>
  </form>
</section>
<section class="main-card master-modern-card du-publishing-card">
  <div class="card-title-row">
    <div class="student-panel-heading"><span class="master-workspace-icon"><?= master_workspace_icon('document') ?></span><div><h3>Penerbitan Tagihan</h3><p><?= $activeTotal ?> siswa aktif &middot; <?= (int)$billSummary['bills'] ?> tagihan terbit &middot; Total Rp <?= number_format((float)$billSummary['total'],0,',','.') ?> &middot; Terbayar Rp <?= number_format((float)$billSummary['paid'],0,',','.') ?></p><?php if($year['status']==='draft'): ?><p>Gunakan tombol Simpan & Terbitkan Tagihan di atas. Tarif, penempatan internal, dan tagihan siswa dibuat dalam satu proses.</p><?php endif; ?></div></div>
    <div class="action-bar">
      <?php if($year['status']==='published'): ?><form method="post" onsubmit="return confirm('Tutup tahun ajaran? Tunggakan tetap dapat dibayar, tetapi tarif tidak dapat diubah lagi.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="tutup"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-warning" type="submit"><?= master_workspace_icon('lock') ?>Tutup Tahun Ajaran</button></form>
      <?php elseif($year['status']==='closed'): ?><form method="post" onsubmit="return confirm('Buka kembali tahun ajaran ini? Histori pembayaran tetap aman dan status kembali menjadi terbit.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="buka"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-primary" type="submit"><?= master_workspace_icon('calendar') ?>Buka Kembali</button></form><?php endif; ?>
    </div>
  </div>
</section>
</main></div><script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script><script>document.querySelectorAll('.rupiah-input').forEach(function(el){el.addEventListener('input',function(){var n=this.value.replace(/\D/g,'');this.value=n?Number(n).toLocaleString('id-ID'):'';});});document.getElementById('du-rate-form')?.addEventListener('submit',function(){this.querySelectorAll('.rupiah-input').forEach(function(el){el.value=el.value.replace(/\./g,'');});});autoHideFlash();</script><script src="assets/js/master_workspace.js?v=<?= filemtime(__DIR__.'/assets/js/master_workspace.js') ?>"></script></body></html>
