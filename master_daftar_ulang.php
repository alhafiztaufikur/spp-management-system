<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/daftar_ulang.php';
require_once 'includes/du_master_service.php';
require_once 'includes/reports.php';
require_once 'includes/master_workspace_ui.php';
requireRole(['admin', 'kasir']);

if (empty($_SESSION['csrf_master_du'])) $_SESSION['csrf_master_du'] = bin2hex(random_bytes(32));


function master_du_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

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
        if (in_array($action, ['simpan_lengkapi','simpan_dan_terbitkan', 'terbitkan','simpan_tarif'], true)) {
            master_du_ensure_year($koneksi, $selectedYear);
        }
        $year = master_du_year($koneksi, $selectedYear, true);
        if ($action === 'simpan_dan_terbitkan' && $year['status'] !== 'draft') {
            throw new RuntimeException('Tahun ajaran sudah berubah sejak formulir dibuka. Muat ulang halaman.');
        }
        $yearId = (int)$year['id'];

        if(in_array($action,['simpan_lengkapi','simpan_dan_terbitkan','simpan_tarif','terbitkan'],true)){
            $result=du_master_save_and_complete($koneksi,$yearId,$selectedYear,$_POST['jumlah']??[],(int)$_SESSION['admin_id']);
            $_SESSION['flash']=['type'=>($result['unknown']||$result['conflicts'])?'warning':'success','msg'=>$result['rates_saved'].' tarif disimpan; '.$result['created'].' tagihan baru; '.$result['existing'].' tagihan existing dipertahankan; '.$result['covered_psb'].' siswa tercakup PSB; '.$result['conflicts'].' konflik DU tersendiri; '.$result['unpaid_bills_aligned'].' tagihan belum dibayar disesuaikan; '.$result['unknown'].' siswa belum dapat diterbitkan.'];
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
        if(lop_ready($koneksi))lb_refresh_manifests($koneksi,lb_sync_owners($koneksi,array_column(lb_cache($koneksi)['students'],'id')));
        $koneksi->commit();
    } catch (Throwable $error) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type'=>'error','msg'=>$error->getMessage()];
        $_SESSION['du_rate_input']=['year'=>$selectedYear,'amounts'=>is_array($_POST['jumlah']??null)?$_POST['jumlah']:[]];
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
$masters=array_fill($firstLevel,$lastLevel-$firstLevel+1,0.0);$masterIds=[];
$stmt=$koneksi->prepare('SELECT id,kelas,Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id=? ORDER BY CAST(kelas AS UNSIGNED)');
$stmt->bind_param('i',$yearId); $stmt->execute(); $result=$stmt->get_result();
while($row=$result->fetch_assoc()){$masters[(int)$row['kelas']]=(float)$row['Jumlah'];$masterIds[(int)$row['kelas']]=(int)$row['id'];}
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
$issuedByClass=array_fill($firstLevel,$lastLevel-$firstLevel+1,0.0);$issuedCountByClass=array_fill($firstLevel,$lastLevel-$firstLevel+1,0);$activeIssuedByClass=array_fill($firstLevel,$lastLevel-$firstLevel+1,0);
foreach(lf_rows($koneksi,"SELECT no_induk,kelas_snapshot,nominal_tagihan FROM tagihan_daftar_ulang WHERE tahun_ajaran_id=? AND status='open'",[$yearId]) as $b){$billSummary['paid']+=lb_du_bill_credit($koneksi,$b['no_induk'],$selectedYear);$issuedByClass[(int)$b['kelas_snapshot']]+=(float)$b['nominal_tagihan'];$issuedCountByClass[(int)$b['kelas_snapshot']]++;}
require_once __DIR__.'/includes/legacy_du.php';
$duPlan=$selectedYear===ldu_target_year()?ldu_plan($koneksi,$selectedYear):['positions'=>[]];
$duUnknown=count(array_filter($duPlan['positions'],static fn($p)=>!$p['known']||$p['reason']!==null));$duUnknownByClass=[];foreach($duPlan['positions'] as $p)if(!$p['known']||$p['reason']!==null){$level=(int)($p['placement']['kelas']??0);$duUnknownByClass[$level]=($duUnknownByClass[$level]??0)+1;}
$masterBases=ldu_master_bases($koneksi,$yearId);
$activeBillRows=hp_rows($koneksi,"SELECT s.KELAS,COUNT(DISTINCT s.id) n FROM siswa s JOIN tagihan_daftar_ulang t ON t.unit_id=s.unit_id AND t.no_induk=s.NO_INDUK WHERE s.is_active=1 AND t.tahun_ajaran_id=? AND t.status='open' GROUP BY s.KELAS",[$yearId]);
foreach($activeBillRows as $r)$activeIssuedByClass[(int)$r['KELAS']]=(int)$r['n'];
$coveredByClass=array_fill($firstLevel,$lastLevel-$firstLevel+1,0);$coverageConflicts=0;
foreach(du_psb_states($koneksi,$selectedYear) as $state){if($state['covered_psb']&&!empty($state['placement']['is_active']))$coveredByClass[(int)$state['placement']['kelas']]++;if($state['conflict'])$coverageConflicts++;}
$coveredTotal=array_sum($coveredByClass);$activeIssued=array_sum($activeIssuedByClass);$activeMissing=max(0,$activeTotal-$activeIssued-$coveredTotal);
$canEdit=$year['status']!=='closed'&&$selectedYear===du_current_academic_year();
$inputState=$_SESSION['du_rate_input']??null;unset($_SESSION['du_rate_input']);
$priorDebt=$yearId?report_prior_debt_summary($koneksi,$selectedYear,report_active_regular_student_nis($koneksi)):['has_debt'=>false];
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Master Daftar Ulang | SistemSPP</title>
<link rel="icon" type="image/png" href="assets/img/favicon.png?v=2">
<link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__.'/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/date_controls.css?v=<?= filemtime(__DIR__.'/assets/css/date_controls.css') ?>">
<link rel="stylesheet" href="assets/css/master_workspace.css?v=<?= filemtime(__DIR__.'/assets/css/master_workspace.css') ?>">
<link rel="stylesheet" href="assets/css/workspace_readability.css?v=<?= filemtime(__DIR__.'/assets/css/workspace_readability.css') ?>">
<script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/css/menu_refinements.css?v=<?= filemtime(__DIR__.'/assets/css/menu_refinements.css') ?>"></head><body data-readable-workspace="master-registration">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include 'includes/sidebar.php'; ?><main class="main-content du-workspace">
<div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2>Master Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Tahun Ajaran / Daftar Ulang</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<?php if($flash): ?><div class="alert alert-<?= master_du_e($flash['type']) ?>" id="flash-msg"><?= master_du_e($flash['msg']) ?></div><?php endif; ?>
<section class="main-card du-master-hero master-modern-shell">
  <div class="master-modern-hero">
    <div><span class="recap-class-overline">Konfigurasi Tahun Ajaran</span><h1><?= master_du_e($selectedYear) ?></h1><p>Atur tarif dan lengkapi tagihan siswa dalam satu langkah.</p></div>
    <div class="du-master-year-tools">
      <form method="get"><select class="field-input field-select" name="tahun" aria-label="Pilih tahun ajaran" onchange="this.form.submit()">
        <?php foreach($yearRows as $yr): ?><option value="<?= master_du_e($yr['label']) ?>" <?= $yr['label']===$selectedYear?'selected':'' ?>><?= master_du_e($yr['label']) ?></option><?php endforeach; ?>
      </select></form>
      <span class="recap-status <?= $year['status']==='draft'?'is-partial':($year['status']==='published'?'is-paid':'is-unpaid') ?>"><?= master_du_e(['draft'=>'Draf','published'=>'Terbit','closed'=>'Tertutup'][$year['status']]) ?></span>
      <?php if($year['status']==='published'): ?><form method="post" onsubmit="return confirm('Tutup tahun ajaran? Tarif dan penerbitan akan dikunci; tunggakan tetap dapat dibayar.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="tutup"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-ghost" type="submit">Tutup Tahun Ajaran</button></form><?php endif; ?>
      <?php if($year['status']==='closed'): ?><form method="post" onsubmit="return confirm('Buka kembali tahun ajaran? Histori pembayaran tetap dipertahankan.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="buka"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-ghost" type="submit">Buka Kembali</button></form><?php endif; ?>
    </div>
  </div>
</section>
<section class="du-overview" aria-label="Posisi tagihan siswa aktif">
  <div><span>Siswa aktif</span><strong><?= $activeTotal ?></strong></div>
  <div><span>Memiliki tagihan DU terpisah</span><strong><?= $activeIssued ?></strong></div>
  <div><span>DU tercakup PSB</span><strong><?= $coveredTotal ?></strong></div>
  <div class="du-overview-pending"><span>Perlu dilengkapi</span><strong><?= $activeMissing ?></strong></div>
</section>
<section class="main-card master-modern-card master-modern-form">
  <div class="student-panel-heading"><span class="master-workspace-icon"><?= master_workspace_icon('coins') ?></span><div><h3>Tarif daftar ulang per kelas</h3><p>Isi tarif yang belum tersedia. Tagihan dan pembayaran yang sudah ada tetap dipertahankan.</p></div></div>
  <?php if(!empty($priorDebt['has_debt'])): ?><p class="du-info-note">Tunggakan tahun sebelumnya tetap tercatat dan dapat dibayar. Penerbitan tahun berjalan tetap dapat dilanjutkan.</p><?php endif; ?>
  <?php if($coverageConflicts): ?><p class="du-info-note"><?= $coverageConflicts ?> penetapan DU tersendiri bertentangan dengan cakupan PSB. Tagihan dan pembayaran tetap disimpan. Periksa melalui <a href="pembayaran/riwayat_daftar_ulang.php">Riwayat Daftar Ulang</a> untuk koreksi melalui alur yang tersedia.</p><?php endif; ?>
  <form method="post" id="du-rate-form">
    <input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>">
    <input type="hidden" name="aksi" value="simpan_lengkapi">
    <input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>">
    <div class="du-rate-grid">
      <?php for($c=$firstLevel;$c<=$lastLevel;$c++):
        $basis=$masters[$c]>0?(isset($masterBases[$masterIds[$c]??0])?'Ditetapkan admin':'Legacy'):'Belum ditetapkan';
        $value=$masters[$c]>0?number_format($masters[$c],round($masters[$c],2)==round($masters[$c])?0:2,',','.') : '';
        if(($inputState['year']??'')===$selectedYear&&isset($inputState['amounts'][$c])&&is_scalar($inputState['amounts'][$c]))$value=mb_substr((string)$inputState['amounts'][$c],0,80);
      ?>
      <?php if($c===$firstLevel&&du_psb_schema_ready($koneksi)&&($selectedYear>=du_current_academic_year()||$coveredByClass[$c]>0)): ?>
      <article class="du-rate-card" data-du-rate-card><div class="du-rate-card-heading"><div><h3>Kelas <?= $c ?></h3><small><?= $activeByClass[$c] ?> siswa aktif</small></div><span class="du-tariff-basis">Tercakup PSB</span></div><p><strong>DU tercakup dalam PSB</strong></p><p><?= $coveredByClass[$c] ?> siswa tercakup; <?= max(0,$activeByClass[$c]-$coveredByClass[$c]) ?> memiliki penetapan lain atau perlu ditinjau.</p><p>Cicilan tetap mengikuti kewajiban PSB.</p><input type="hidden" name="jumlah[<?= $c ?>]" value="0"></article>
      <?php continue; endif; ?>
      <article class="du-rate-card <?= $masters[$c]<=0?'du-rate-unset':'' ?>" data-du-rate-card>
        <div class="du-rate-card-heading"><div><h3>Kelas <?= $c ?></h3><small><?= $activeByClass[$c] ?> siswa aktif</small></div><span class="du-tariff-basis"><?= master_du_e($basis) ?></span></div>
        <label class="du-rate-label" for="du-rate-<?= $c ?>">Tarif per siswa</label>
        <div class="du-rate-input-wrap"><span aria-hidden="true">Rp</span><input id="du-rate-<?= $c ?>" class="field-input rupiah-input" name="jumlah[<?= $c ?>]" inputmode="decimal" autocomplete="off" value="<?= master_du_e($value) ?>" placeholder="Masukkan nominal" data-saved-value="<?= master_du_e($value) ?>" <?= !$canEdit?'disabled':'' ?>></div>
        <p class="du-class-progress"><?= $activeIssuedByClass[$c] ?> dari <?= $activeByClass[$c] ?> siswa memiliki tagihan</p>
        <?php if($masters[$c]<=0): ?><small>Tarif belum tersedia untuk tahun ini.</small><?php endif; ?>
        <div class="du-rate-estimate"><span>Total tagihan tercatat</span><strong><?= ($masters[$c]<=0||(!empty($duUnknownByClass[$c])&&!$issuedCountByClass[$c]))?'-':'Rp '.number_format($issuedByClass[$c],0,',','.') ?></strong></div>
      </article>
      <?php endfor; ?>
    </div>
    <p class="du-info-note">Tarif yang Anda tetapkan menjadi dasar tagihan baru. Pembayaran Legacy tetap diperhitungkan. Tarif kelas yang dikosongkan tidak diubah.</p>
    <div class="du-save-footer">
      <div><span>Total Tagihan DU Terpisah</span><strong data-du-issued-total>Rp <?= number_format((float)$billSummary['total'],0,',','.') ?></strong><small><?= $coveredTotal ?> siswa tercakup PSB; <?= $activeMissing ?> siswa perlu dilengkapi.</small></div>
      <div class="du-save-action">
        <?php if($canEdit): ?><button class="btn btn-primary" type="submit"><?= master_workspace_icon('save') ?>Simpan Tarif &amp; Lengkapi Tagihan</button><small>Menyimpan tarif dan membuat tagihan yang belum ada.</small><small data-du-unsaved hidden role="status">Ada perubahan tarif yang belum disimpan.</small><?php else: ?><span class="recap-status is-unpaid"><?= $year['status']==='closed'?'Tarif dan penerbitan terkunci':'Histori tahun lain tetap tersimpan; penerbitan untuk tahun berjalan.' ?></span><?php endif; ?>
      </div>
    </div>
  </form>
</section>
</main></div><script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script><script>document.querySelectorAll('.rupiah-input').forEach(function(el){el.addEventListener('blur',function(){if(!/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/.test(this.value))return;var parts=this.value.split(','),n=parts[0].replace(/\./g,'');this.value=n.replace(/\B(?=(\d{3})+(?!\d))/g,'.')+(parts.length>1?','+parts[1]:'');});});autoHideFlash();</script><script src="assets/js/master_workspace.js?v=<?= filemtime(__DIR__.'/assets/js/master_workspace.js') ?>"></script></body></html>
