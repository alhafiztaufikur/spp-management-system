<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/spp_billing.php';
require_once 'includes/reports.php';
require_once 'includes/spp_rate_confirmation.php';
require_once 'includes/student_tariff_consistency.php';
requireRole(['admin', 'kasir']);
if (!spp_billing_schema_ready($koneksi)) die('Schema Master SPP belum tersedia. Hubungi operator untuk migrasi skema.');
if (empty($_SESSION['csrf_master_spp'])) $_SESSION['csrf_master_spp']=bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_prior_debt'])) $_SESSION['csrf_prior_debt']=bin2hex(random_bytes(32));

function mspp_e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function mspp_money($value): string { return number_format((float)$value,0,',','.'); }
function mspp_redirect(string $year): void { header('Location: master_spp.php?tahun='.urlencode($year));exit; }
function mspp_read_year(mysqli $db, string $label): array {
    $stmt = $db->prepare('SELECT mst.id,mst.status,mst.published_at,mst.closed_at,ta.id tahun_ajaran_id,ta.label,
        COALESCE((SELECT MAX(a.id) FROM spp_audit_log a WHERE a.master_spp_tahun_id=mst.id),0) audit_version,
        EXISTS(SELECT 1 FROM tagihan_spp ts WHERE ts.master_spp_tahun_id=mst.id) AS has_bills
        FROM tahun_ajaran ta LEFT JOIN master_spp_tahun mst ON mst.tahun_ajaran_id=ta.id
        WHERE ta.label=? LIMIT 1');
    $stmt->bind_param('s', $label); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row && (int)($row['id'] ?? 0) > 0
        ? $row : ['id'=>0, 'tahun_ajaran_id'=>(int)($row['tahun_ajaran_id'] ?? 0),
            'label'=>$label, 'status'=>'draft'];
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$rawYear = $isPost ? ($_POST['tahun_ajaran'] ?? null) : ($_GET['tahun'] ?? du_current_academic_year());
$selectedYear = is_string($rawYear) ? trim($rawYear) : '';
try {
    $selectedYear=du_normalize_academic_year($selectedYear);
} catch (Throwable $e) {
    if ($isPost) {
        $_SESSION['flash']=['type'=>'error','msg'=>'Tahun ajaran pada formulir tidak valid. Muat ulang halaman.'];
        mspp_redirect(du_current_academic_year());
    }
    $selectedYear=du_current_academic_year();
}
$master=mspp_read_year($koneksi,$selectedYear);$masterId=(int)$master['id'];
$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!is_string($_POST['csrf_token']??null)||!hash_equals($_SESSION['csrf_master_spp'],$_POST['csrf_token']))throw new RuntimeException('Permintaan tidak valid atau sesi telah kedaluwarsa.');
        $action=$_POST['aksi']??'';if(!is_string($action))throw new RuntimeException('Aksi Master SPP tidak valid.');
        $guarded=in_array($action,['simpan_tarif','ubah_tarif_terbit','terbitkan'],true);
        $oldRates=spp_master_rates($koneksi,$masterId);$newRates=$oldRates;
        if($guarded){
            if(!is_string($_POST['expected_rate_version']??null))throw new RuntimeException('Versi tarif tidak valid. Muat ulang halaman.');
            if($action==='terbitkan'){
                $selected=$_POST['selected_students']??[];$starts=$_POST['start_month']??[];
                if(!is_array($selected)||!is_array($starts)||count($selected)>10000)throw new RuntimeException('Pilihan siswa tidak valid.');
                foreach($selected as $nis)if(!is_string($nis)||$nis==='')throw new RuntimeException('Pilihan siswa tidak valid.');
                foreach($starts as $month)if(!is_string($month)||!preg_match('/^(0[1-9]|1[0-2])$/D',$month))throw new RuntimeException('Bulan mulai tagihan tidak valid.');
                $_SESSION['spp_publish_inputs'][unit_active_id().'|'.$selectedYear]=['students'=>$selected,'starts'=>$starts];
            }
            if($action==='simpan_tarif'&&!spp_master_rates_editable($master))throw new RuntimeException('Tarif terkunci. Gunakan Edit Tarif untuk koreksi tarif terbit.');
            if($action==='ubah_tarif_terbit'&&$master['status']!=='published')throw new RuntimeException('Buka kembali tahun sebelum mengoreksi tarif terbit.');
            if($action==='terbitkan'&&$master['status']==='closed')throw new RuntimeException('Tahun SPP sudah ditutup.');
            if($action!=='terbitkan'){
                $amounts=$_POST['jumlah']??[];if(!is_array($amounts))throw new RuntimeException('Tarif tidak valid.');
                [$first,$last]=unit_level_bounds();foreach(range($first,$last) as $level){$raw=$amounts[$level]??0;if(!is_scalar($raw))throw new RuntimeException('Tarif tidak valid.');$newRates[$level]=student_amount($raw);if($newRates[$level]<=0)throw new RuntimeException('Lengkapi tarif positif kelas '.$level.'.');}
            }else{foreach($oldRates as $level=>$amount)if($amount<=0)throw new RuntimeException('Lengkapi tarif dasar kelas '.$level.' sebelum menerbitkan.');}
            spp_assert_rate_version($_POST['expected_rate_version'],$master,$oldRates);
            $confirmation=$action==='terbitkan'?'confirm_spp_publish':'confirm_rate_change';
            if(($action!=='terbitkan'||$master['status']==='draft')&&($_POST[$confirmation]??'0')!=='1'){
                $_SESSION['spp_rate_inputs'][unit_active_id().'|'.$selectedYear]=['action'=>$action,'rates'=>$newRates];
                spp_rate_confirmation_screen($_POST,$oldRates,$newRates,$action);
            }
        }
        $koneksi->begin_transaction();
        $master=spp_master_ensure_year($koneksi,$selectedYear,true);$masterId=(int)$master['id'];
        if($guarded){$master=spp_master_state($koneksi,$masterId,true);spp_assert_rate_version((string)$_POST['expected_rate_version'],$master,spp_master_rates($koneksi,$masterId,true));}
        if($action==='ubah_tarif_terbit'){
            $result=spp_master_correct_published_rates($koneksi,$masterId,$newRates,(string)$_POST['expected_rate_version'],($_POST['confirm_rate_change']??'0')==='1');
            $_SESSION['flash']=['type'=>'success','msg'=>'Perubahan tarif tersimpan. '.$result['bills_updated'].' tagihan diperbarui; '.$result['bills_locked'].' tagihan dipertahankan.'];
        }elseif($action==='simpan_tarif'){
            $result=spp_master_save_rates($koneksi,$masterId,$newRates);
            $_SESSION['flash']=['type'=>'success','msg'=>'Tarif dasar SPP tersimpan.'];
        }elseif($action==='terbitkan'){
            $students=is_array($_POST['selected_students']??null)?array_values(array_unique(array_filter(array_map('strval',$_POST['selected_students'])))):[];
            $priorDebt=report_prior_debt_summary($koneksi,$selectedYear,$students);
            if($priorDebt['has_debt']&&($_POST['confirm_previous_debt']??'0')!=='1'){
                $koneksi->rollback();spp_rate_confirmation_screen($_POST,$oldRates,$oldRates,'tunggakan');
            }
            $starts=is_array($_POST['start_month']??null)?$_POST['start_month']:[];
            $result=spp_publish_students($koneksi,$masterId,$students,$starts);
            $message=$result['created'].' tagihan SPP baru berhasil diterbitkan.';
            if($result['existing'])$message.=' '.$result['existing'].' tagihan sudah tersedia.';
            if($result['ineligible'])$message.=' '.count($result['ineligible']).' siswa dilewati karena penempatannya belum valid.';
            $_SESSION['flash']=['type'=>'success','msg'=>$message];
        }elseif($action==='tutup'){
            if($master['status']!=='published')throw new RuntimeException('Hanya tahun SPP berstatus Terbit yang dapat ditutup.');
            $stmt=$koneksi->prepare("UPDATE master_spp_tahun SET status='closed',closed_at=NOW() WHERE id=?");$stmt->bind_param('i',$masterId);$stmt->execute();$stmt->close();
            spp_write_audit($koneksi,$masterId,null,'tutup_tahun',['status'=>'published'],['status'=>'closed']);
            $_SESSION['flash']=['type'=>'success','msg'=>'Tahun SPP ditutup. Tunggakan tetap dapat dibayar, sedangkan tarif telah dikunci.'];
        }elseif($action==='buka'){
            if($master['status']!=='closed')throw new RuntimeException('Hanya tahun SPP tertutup yang dapat dibuka kembali.');
            $stmt=$koneksi->prepare("UPDATE master_spp_tahun SET status='published',closed_at=NULL WHERE id=?");$stmt->bind_param('i',$masterId);$stmt->execute();$stmt->close();
            spp_write_audit($koneksi,$masterId,null,'buka_tahun',['status'=>'closed'],['status'=>'published']);
            $_SESSION['flash']=['type'=>'success','msg'=>'Tahun SPP dibuka kembali.'];
        }elseif($action==='batalkan_mulai_bulan'){
            if($master['status']!=='published')throw new RuntimeException('Hanya tahun SPP berstatus Terbit yang dapat dibatalkan tagihannya.');
            $students=is_array($_POST['selected_students']??null)?array_values(array_unique(array_filter(array_map('strval',$_POST['selected_students'])))):[];
            $start=(string)($_POST['cancel_start_month']??'');$reason=trim((string)($_POST['cancel_reason']??''));
            if(!$students||!preg_match('/^(0[1-9]|1[0-2])$/',$start)||$reason==='')throw new RuntimeException('Pilih siswa, bulan efektif keluar, dan alasan pembatalan.');
            $periods=spp_academic_periods($selectedYear);$orders=[];foreach($periods as $p)$orders[$p['bulan'].'-'.$p['tahun']]=$p['order'];
            $startOrder=null;foreach($periods as $p)if($p['bulan']===$start){$startOrder=$p['order'];break;}
            $cancelled=0;
            $checkStudent=$koneksi->prepare("SELECT s.is_active FROM siswa s
                JOIN siswa_tahun_ajaran sta ON sta.no_induk=s.NO_INDUK
                JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
                WHERE s.NO_INDUK=? AND ta.label=?
                  AND CAST(sta.kelas AS UNSIGNED) " . unit_level_between_sql() . "
                LIMIT 1 FOR UPDATE");
            $select=$koneksi->prepare("SELECT ts.id,ts.bulan,ts.tahun,EXISTS(SELECT 1 FROM spp_alokasi a JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active' WHERE a.tagihan_spp_id=ts.id) allocated FROM tagihan_spp ts WHERE ts.master_spp_tahun_id=? AND ts.no_induk=? AND ts.status='open' FOR UPDATE");
            $update=$koneksi->prepare("UPDATE tagihan_spp SET status='cancelled',cancel_reason=? WHERE id=?");
            foreach($students as $nis){
                $checkStudent->bind_param('ss',$nis,$selectedYear);
                $checkStudent->execute();
                $student=$checkStudent->get_result()->fetch_assoc();
                if(!$student||(int)$student['is_active']!==0){
                    throw new RuntimeException('Arsipkan siswa yang keluar di Data Siswa sebelum membatalkan tagihan SPP.');
                }
                $studentCancelled=0;
                $select->bind_param('is',$masterId,$nis);
                $select->execute();
                foreach($select->get_result()->fetch_all(MYSQLI_ASSOC) as $bill){
                    $order=$orders[$bill['bulan'].'-'.$bill['tahun']]??-1;
                    if($order<$startOrder||(int)$bill['allocated']===1)continue;
                    $billId=(int)$bill['id'];
                    $update->bind_param('si',$reason,$billId);
                    $update->execute();
                    $cancelled++;
                    $studentCancelled++;
                }
                spp_write_audit($koneksi,$masterId,$nis,'batalkan_mulai_bulan',null,
                    ['bulan'=>$start,'alasan'=>$reason],$studentCancelled);
            }
            $checkStudent->close();
            $select->close();
            $update->close();
            $_SESSION['flash']=['type'=>'success','msg'=>$cancelled.' tagihan belum bayar berhasil dibatalkan.'];
        }else throw new RuntimeException('Aksi Master SPP tidak dikenali.');
        $koneksi->commit();unset($_SESSION['spp_rate_inputs'][unit_active_id().'|'.$selectedYear],$_SESSION['spp_publish_inputs'][unit_active_id().'|'.$selectedYear]);
    }catch(Throwable $e){try{$koneksi->rollback();}catch(Throwable $ignored){}
        if(in_array($action??'',['simpan_tarif','ubah_tarif_terbit'],true)&&isset($newRates))$_SESSION['spp_rate_inputs'][unit_active_id().'|'.$selectedYear]=['action'=>$action,'rates'=>$newRates];
        $_SESSION['flash']=['type'=>'error','msg'=>($e instanceof mysqli_sql_exception && in_array($e->getCode(),[1205,1213],true))?'Data sedang berubah. Periksa kembali tarif sebelum mencoba lagi.':$e->getMessage()];}

    mspp_redirect($selectedYear);
}

$master=mspp_read_year($koneksi,$selectedYear);$masterId=(int)$master['id'];$rates=spp_master_rates($koneksi,$masterId);[$firstLevel,$lastLevel]=unit_level_bounds();
$ratesEditable=spp_master_rates_editable($master);$rateVersion=spp_master_rate_version($master,$rates);
if((string)($_GET['cancel_tarif']??'')==='1')unset($_SESSION['spp_rate_inputs'][unit_active_id().'|'.$selectedYear]);
$publishDraft=$_SESSION['spp_publish_inputs'][unit_active_id().'|'.$selectedYear]??['students'=>[],'starts'=>[]];unset($_SESSION['spp_publish_inputs'][unit_active_id().'|'.$selectedYear]);
$rateDraft=$_SESSION['spp_rate_inputs'][unit_active_id().'|'.$selectedYear]??null;unset($_SESSION['spp_rate_inputs'][unit_active_id().'|'.$selectedYear]);
$rateEditing=$master['status']==='published'&&((string)($_GET['edit_tarif']??'')==='1'||($rateDraft['action']??'')==='ubah_tarif_terbit');
$rateValues=$rateDraft&&in_array($rateDraft['action'],['simpan_tarif','ubah_tarif_terbit'],true)?$rateDraft['rates']:$rates;
$years=[];$currentStart=(int)substr(du_current_academic_year(),0,4);for($offset=-2;$offset<=3;$offset++){$start=$currentStart+$offset;$label=$start.'/'.($start+1);$years[$label]=$label;}$res=$koneksi->query('SELECT label FROM tahun_ajaran ORDER BY label DESC');while($r=$res->fetch_assoc())$years[$r['label']]=$r['label'];krsort($years);
$stmt=$koneksi->prepare("SELECT sta.no_induk,s.NAMA,s.NO_induk_diknas,sta.kelas,sta.master_kelas_id,sta.kelas_rombel_snapshot,sta.komite_mulai_bulan,s.potongan_spp_nominal,s.is_active,
 COUNT(ts.id) bill_count,SUM(CASE WHEN ts.status='open' THEN 1 ELSE 0 END) open_count
 FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk AND s.unit_id=sta.unit_id
 LEFT JOIN tagihan_spp ts ON ts.penempatan_id=sta.id WHERE ta.label=? AND CAST(sta.kelas AS UNSIGNED) " . unit_level_between_sql() . "
 GROUP BY sta.id,s.NAMA,s.NO_induk_diknas,s.potongan_spp_nominal,s.is_active ORDER BY sta.kelas,sta.kelas_rombel_snapshot,s.NAMA");
$stmt->bind_param('s',$selectedYear);$stmt->execute();$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$rombelChoices=[];foreach($students as $student)$rombelChoices[(string)$student['kelas_rombel_snapshot']]=(string)$student['kelas_rombel_snapshot'];
filter_register('rombel_filter',$_GET['rombel_filter']??null,$rombelChoices);filter_output_start();
$cancelEligibleStudents=array_values(array_filter($students,static fn(array $student):bool=>(int)$student['is_active']===0));
$stmt=$koneksi->prepare("SELECT COUNT(*) bills,SUM(CASE WHEN paid+0.001>=nominal_tagihan THEN 1 ELSE 0 END) paid_bills,SUM(CASE WHEN paid+0.001<nominal_tagihan AND status='open' THEN 1 ELSE 0 END) unpaid_bills FROM (SELECT ts.id,ts.nominal_tagihan,ts.status,COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp ts LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id WHERE ts.master_spp_tahun_id=? GROUP BY ts.id) x");
$stmt->bind_param('i',$masterId);$stmt->execute();$stats=$stmt->get_result()->fetch_assoc();$stmt->close();

require_once __DIR__ . '/includes/master_workspace_ui.php';
$activeYearStudents = array_values(array_filter($students, static fn(array $student): bool => (int)$student['is_active'] === 1));
$activeStudentCount = count($activeYearStudents);
$publishedStudentCount = count(array_filter($activeYearStudents, static fn(array $student): bool => (int)$student['bill_count'] > 0));
$pendingStudentCount = $activeStudentCount - $publishedStudentCount;
$publishedPercent = $activeStudentCount ? (int)round(100 * $publishedStudentCount / $activeStudentCount) : 0;
$pendingPercent = $activeStudentCount ? (int)round(100 * $pendingStudentCount / $activeStudentCount) : 0;
$yearStart = (int)substr($selectedYear, 0, 4);
?>
<!doctype html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Master Penerbitan SPP | SistemSPP</title>
  <link rel="icon" href="assets/img/favicon.png?v=2">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=unitpalette5&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="assets/css/date_controls.css?v=unitpalette5&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/date_controls.css') ?>">
  <link rel="stylesheet" href="assets/css/master_spp.css?v=<?= filemtime(__DIR__ . '/assets/css/master_spp.css') ?>">
  <link rel="stylesheet" href="assets/css/workspace_readability.css?v=<?= filemtime(__DIR__ . '/assets/css/workspace_readability.css') ?>">
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body data-readable-workspace="master-spp">
<div class="layout">
<?php include 'includes/sidebar.php'; ?>
<main class="main-content spp-workspace">
  <div class="topbar">
    <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
    <div class="topbar-title"><h2>Master Penerbitan SPP</h2><span class="breadcrumb">SistemSPP / Data Master / SPP</span></div>
    <div class="clock-badge" id="liveClock">--:--:--</div>
  </div>
  <?php if ($flash): ?><div class="alert alert-<?= mspp_e($flash['type']) ?>" id="flash-msg"><?= mspp_e($flash['msg']) ?></div><?php endif; ?>
  <script>window.priorDebtForms=[{id:'spp-publish-form',scope:'selected',csrf:<?= json_encode($_SESSION['csrf_prior_debt']) ?>}];</script>
  <script src="assets/js/prior-debt-warning.js?v=1.0"></script>
  <?php include 'includes/prior_debt_modal.php'; include 'includes/spp_warning_modal.php'; ?>
  <div class="spp-workspace-content">
    <header class="spp-page-heading">
      <div class="spp-heading-copy"><span class="breadcrumb">Beranda <span aria-hidden="true">&rsaquo;</span> Penerbitan SPP</span><h1>Penerbitan SPP Juli–Juni</h1><p>Terbitkan tagihan SPP untuk siswa yang memiliki penempatan pada tahun ajaran ini.</p></div>
      <div class="spp-year-picker">
        <div class="spp-year-control">
          <a class="spp-icon-button" href="master_spp.php?tahun=<?= urlencode(($yearStart - 1) . '/' . $yearStart) ?>" aria-label="Tahun ajaran sebelumnya"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m14 6-6 6 6 6"/></svg></a>
          <form method="get"><label class="spp-sr-only" for="spp-year-choice">Tahun ajaran</label><select class="field-input field-select" id="spp-year-choice" name="tahun" onchange="this.form.submit()">
          <?php foreach ($years as $year): ?><option value="<?= mspp_e($year) ?>" <?= $year === $selectedYear ? 'selected' : '' ?>><?= mspp_e($year) ?></option><?php endforeach; ?>
          </select><noscript><button type="submit" class="btn btn-ghost">Tampilkan</button></noscript></form>
          <a class="spp-icon-button" href="master_spp.php?tahun=<?= urlencode(($yearStart + 1) . '/' . ($yearStart + 2)) ?>" aria-label="Tahun ajaran berikutnya"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10 6 6 6-6 6"/></svg></a>
        </div>
        <span class="recap-status <?= $master['status'] === 'draft' ? 'is-partial' : ($master['status'] === 'published' ? 'is-paid' : 'is-unpaid') ?>"><?= mspp_e(strtoupper($master['status'])) ?></span>
      </div>
    </header>
    <section class="spp-summary-grid" aria-label="Ringkasan penerbitan SPP">
      <div class="spp-summary-card"><span class="spp-workspace-icon"><?= master_workspace_icon('students') ?></span><div><span>Total Siswa Aktif</span><strong><?= number_format($activeStudentCount) ?></strong><small>Siswa aktif pada tahun ajaran ini.</small></div></div>
      <div class="spp-summary-card"><span class="spp-workspace-icon"><?= master_workspace_icon('document') ?></span><div><span>Sudah Diterbitkan</span><strong><?= number_format($publishedStudentCount) ?></strong><div class="spp-progress-row"><div class="spp-progress-track"><span style="width:<?= $publishedPercent ?>%"></span></div><small><?= $publishedPercent ?>%</small></div><small>Siswa aktif dengan catatan tagihan.</small></div></div>
      <div class="spp-summary-card spp-summary-pending"><span class="spp-workspace-icon"><?= master_workspace_icon('hourglass') ?></span><div><span>Belum Diterbitkan</span><strong><?= number_format($pendingStudentCount) ?></strong><div class="spp-progress-row"><div class="spp-progress-track"><span style="width:<?= $pendingPercent ?>%"></span></div><small><?= $pendingPercent ?>%</small></div><small>Siswa aktif tanpa catatan tagihan.</small></div></div>
      <div class="spp-summary-card"><span class="spp-workspace-icon"><?= master_workspace_icon('document') ?></span><div><span>Total Tagihan</span><strong><?= number_format((int)($stats['bills'] ?? 0)) ?></strong><small><?= number_format((int)($stats['unpaid_bills'] ?? 0)) ?> tagihan belum bayar.</small></div></div>
    </section>
    <div class="spp-workspace-grid">
      <section class="main-card master-modern-card spp-main-panel">
        <nav class="spp-workspace-tabs" aria-label="Bagian Master SPP">
          <a href="#spp-rate-panel" data-spp-tab="rates"><?= master_workspace_icon('coins') ?>Tarif SPP</a>
          <a href="#spp-students-panel" data-spp-tab="students" class="is-active"><?= master_workspace_icon('students') ?>Pilih Siswa</a>
          <a href="#spp-year-panel" data-spp-tab="year"><?= master_workspace_icon('calendar') ?>Status Tahun</a>
        </nav>
        <section class="spp-tab-panel" id="spp-rate-panel" data-spp-panel="rates">
          <div class="spp-section-heading"><h2>Tarif Dasar per Tingkat</h2><p>Tarif dasar per kelas. Koreksi tarif terbit hanya memperbarui tagihan yang belum dibayar.</p></div>
          <form method="post" id="spp-rate-form" data-status="<?= mspp_e($master['status']) ?>" data-editing="<?= $rateEditing?'true':'false' ?>">
            <input type="hidden" name="csrf_token" value="<?= mspp_e($_SESSION['csrf_master_spp']) ?>"><input type="hidden" name="aksi" value="<?= $master['status']==='published'?'ubah_tarif_terbit':'simpan_tarif' ?>"><input type="hidden" name="expected_rate_version" value="<?= mspp_e($rateVersion) ?>"><input type="hidden" name="confirm_rate_change" value="0"><input type="hidden" name="tahun_ajaran" value="<?= mspp_e($selectedYear) ?>">
            <div class="spp-rate-grid">
              <?php for ($i = $firstLevel; $i <= $lastLevel; $i++): ?>
              <label class="field-row spp-grade-rate"><span class="spp-grade-roman" aria-hidden="true"><?= master_workspace_roman($i) ?></span><span class="field-label">Kelas <?= $i ?></span><input class="field-input rupiah-input" name="jumlah[<?= $i ?>]" inputmode="numeric" data-original="<?= mspp_e($rates[$i]) ?>" data-level="<?= $i ?>" value="<?= $rateValues[$i] > 0 ? mspp_money($rateValues[$i]) : '' ?>" placeholder="Rp 0" required <?= (!$ratesEditable&&!$rateEditing) ? 'disabled' : '' ?>></label>
              <?php endfor; ?>
            </div>
            <div class="action-bar">
            <?php if ($ratesEditable): ?><button class="btn btn-primary" id="spp-rate-save" type="submit"><?= master_workspace_icon('save') ?>Simpan Tarif</button>
            <?php elseif ($master['status']==='published'): ?>
              <a class="btn btn-primary" id="spp-rate-edit" href="?tahun=<?= urlencode($selectedYear) ?>&amp;edit_tarif=1#spp-rate-panel"><?= master_workspace_icon('edit') ?><span>Edit Tarif</span></a>
              <button class="btn btn-primary" type="submit" id="spp-rate-save" hidden>Simpan Perubahan Tarif</button>
              <a class="btn btn-ghost" id="spp-rate-cancel" href="?tahun=<?= urlencode($selectedYear) ?>&amp;cancel_tarif=1#spp-rate-panel" <?= !$rateEditing?'hidden':'' ?>>Batalkan</a>
              <noscript><?php if($rateEditing): ?><button class="btn btn-primary" type="submit">Simpan Perubahan Tarif</button><?php endif; ?></noscript>
            <?php endif; ?>
            </div>
            <?php if (!$ratesEditable): ?><p class="spp-info-note"><?= master_workspace_icon('lock') ?><?= $master['status']==='closed'?'Buka kembali tahun untuk mengoreksi tarif.':'Tarif terkunci. Pilih Edit Tarif untuk melakukan koreksi.' ?></p><?php endif; ?>
          </form>
        </section>
        <section class="spp-tab-panel spp-publish-card" id="spp-students-panel" data-spp-panel="students">
          <h2 class="spp-sr-only">Pilih Siswa dan Terbitkan</h2>
          <div class="promotion-filter-bar">
            <div class="search-box"><span class="search-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4.2-4.2"/></svg></span><input type="search" id="spp-student-search" placeholder="Cari nama, NIS, atau rombel..." aria-label="Cari siswa"></div>
            <select class="field-input field-select" id="spp-rombel-filter" name="rombel_filter" aria-label="Rombel" data-filter-multiple data-filter-persist><option value="">Semua rombel</option><?php $rombel=[];foreach($students as $s)$rombel[$s['kelas_rombel_snapshot']]=$s['kelas_rombel_snapshot'];ksort($rombel);foreach($rombel as $r): ?><option value="<?= mspp_e($r) ?>"><?= mspp_e($r) ?></option><?php endforeach; ?></select>
          </div>
          <p class="spp-list-note">Hanya siswa dengan penempatan pada tahun ajaran ini yang dapat diterbitkan. Status baris menunjukkan catatan tagihan yang sudah tersedia.</p>
          <form method="post" id="spp-publish-form" data-first-publication="<?= $master['status']==='draft'?'true':'false' ?>">
            <input type="hidden" name="csrf_token" value="<?= mspp_e($_SESSION['csrf_master_spp']) ?>"><input type="hidden" name="aksi" value="terbitkan"><input type="hidden" name="expected_rate_version" value="<?= mspp_e($rateVersion) ?>"><input type="hidden" name="confirm_spp_publish" value="0"><input type="hidden" name="tahun_ajaran" value="<?= mspp_e($selectedYear) ?>">
            <div class="promotion-selection-toolbar"><div><strong id="spp-visible-count"><?= count($students) ?> siswa</strong><span id="spp-selected-count">0 siswa dipilih</span></div><div><button class="btn btn-ghost" type="button" id="spp-select-visible"><?= master_workspace_icon('check') ?>Pilih yang Ditampilkan</button><button class="btn btn-ghost" type="button" id="spp-clear-selection"><?= master_workspace_icon('reset') ?>Kosongkan</button></div></div>
            <div class="spp-students-table-wrap">
              <div class="promotion-student-list" id="spp-student-list" tabindex="0" aria-label="Daftar siswa untuk penerbitan SPP">
              <div class="spp-students-table-head" aria-hidden="true"><span></span><span>Nama Siswa</span><span>NIS</span><span>Rombel</span><span>Kelas</span><span>Tarif Efektif</span><span>Mulai Tagihan</span><span>Status</span></div>
              <?php foreach ($students as $idx => $s): $level=(int)$s['kelas'];$net=spp_net_tariff((float)$rates[$level],(float)$s['potongan_spp_nominal']);$search=strtolower($s['NAMA'].' '.$s['no_induk'].' '.$s['kelas_rombel_snapshot']);$initials='';foreach(array_slice(preg_split('/\s+/u',trim((string)$s['NAMA'])),0,2) as $part)$initials.=mb_substr($part,0,1,'UTF-8'); ?>
                <article class="promotion-student-row spp-publish-row" data-search="<?= mspp_e($search) ?>" data-rombel="<?= mspp_e($s['kelas_rombel_snapshot']) ?>">
                  <label class="promotion-student-check"><input type="checkbox" name="selected_students[]" value="<?= mspp_e($s['no_induk']) ?>" <?= in_array((string)$s['no_induk'],$publishDraft['students'],true)?'checked':'' ?> aria-label="Pilih <?= mspp_e($s['NAMA']) ?>"><span></span></label>
                  <div class="promotion-student-identity"><span class="spp-student-avatar" aria-hidden="true"><?= mspp_e(mb_strtoupper($initials,'UTF-8')) ?></span><div><strong><?= mspp_e($s['NAMA']) ?></strong><small>Potongan Rp <?= mspp_money($s['potongan_spp_nominal']) ?></small><?php if (!(int)$s['is_active']): ?><small>Siswa diarsipkan</small><?php endif; ?></div></div>
                  <span class="spp-student-nis" data-label="NIS"><?= mspp_e($s['no_induk']) ?></span>
                  <span class="spp-student-rombel" data-label="Rombel"><?= mspp_e($s['kelas_rombel_snapshot']) ?></span>
                  <span class="kelas-badge" data-label="Kelas">Kelas <?= $level ?></span>
                  <div class="spp-rate-preview"><span>Tarif efektif</span><strong>Rp <?= mspp_money($net['net']) ?></strong></div>
                  <label class="promotion-target-field"><span class="spp-start-caption">Mulai tagihan</span><select class="field-input field-select" name="start_month[<?= mspp_e($s['no_induk']) ?>]" aria-label="Mulai tagihan <?= mspp_e($s['NAMA']) ?>"><?php foreach (spp_academic_periods($selectedYear) as $p): ?><option value="<?= $p['bulan'] ?>" <?= $p['bulan'] === ($publishDraft['starts'][$s['no_induk']]??$s['komite_mulai_bulan']) ? 'selected' : '' ?>><?= mspp_e($p['label']) ?></option><?php endforeach; ?></select></label>
                  <span class="master-status <?= (int)$s['bill_count'] > 0 ? 'is-active' : 'is-inactive' ?>"><?= (int)$s['bill_count'] > 0 ? number_format((int)$s['bill_count']).' bulan' : 'Belum terbit' ?></span>
                </article>
              <?php endforeach; ?>
              <?php if (!$students): ?><div class="empty-state"><p>Belum ada penempatan siswa</p><span>Proses tahun ajaran pada Master Kelas/Rombel terlebih dahulu.</span></div><?php endif; ?>
              </div>
            </div>
            <p class="spp-info-note spp-search-empty" id="spp-search-empty" hidden>Tidak ada siswa yang cocok dengan pencarian atau rombel.</p>
            <div class="promotion-submit-bar"><span class="spp-workspace-icon"><?= master_workspace_icon('check') ?></span><div><strong id="spp-submit-summary">Belum ada siswa dipilih</strong><span>Penerbitan tidak akan membuat tagihan ganda.</span></div><?php if ($master['status'] !== 'closed' && $students): ?><button class="btn btn-primary" id="spp-submit-button" type="submit" disabled><?= master_workspace_icon('send') ?>Terbitkan SPP</button><?php elseif ($master['status'] === 'closed'): ?><span class="recap-status is-unpaid">Tahun SPP ditutup</span><?php endif; ?></div>
          </form>
        </section>
        <section class="spp-tab-panel" id="spp-year-panel" data-spp-panel="year">
          <div class="spp-section-heading"><h2>Status Tahun SPP <?= mspp_e($selectedYear) ?></h2><p>Tarif dan tagihan SPP berdiri sendiri dari penerbitan Daftar Ulang.</p></div>
          <div class="spp-year-explanation"><?= master_workspace_icon('info') ?><div><strong><?= $master['status'] === 'closed' ? 'Tahun SPP ditutup' : ($master['status'] === 'published' ? 'Tahun SPP sudah diterbitkan' : 'Tahun SPP belum diterbitkan') ?></strong><p>Tarif dasar terkunci saat halaman dibuka. Koreksi dapat dilakukan melalui Edit Tarif. Tunggakan yang sudah terbit tetap bisa dilunasi. Gunakan tindakan pada panel Status Tahun SPP di sebelah kanan.</p></div></div>
          <?php if ($master['status'] === 'published' && $cancelEligibleStudents): ?>
          <section class="spp-cancel-section"><h3>Batalkan Tagihan Siswa Keluar</h3><p class="payment-auto-note">Arsipkan siswa yang keluar di Data Siswa terlebih dahulu. Tagihan belum dibayar mulai bulan efektif keluar akan dibatalkan; tagihan yang pernah menerima alokasi tetap dilindungi.</p>
            <form method="post" class="fields-grid" onsubmit="return confirm('Batalkan tagihan SPP siswa mulai bulan terpilih?')">
              <input type="hidden" name="csrf_token" value="<?= mspp_e($_SESSION['csrf_master_spp']) ?>"><input type="hidden" name="aksi" value="batalkan_mulai_bulan"><input type="hidden" name="tahun_ajaran" value="<?= mspp_e($selectedYear) ?>">
              <div class="field-row"><label class="field-label">Siswa</label><select class="field-input field-select" name="selected_students[]" required><option value="">Pilih siswa</option><?php foreach ($cancelEligibleStudents as $s): ?><option value="<?= mspp_e($s['no_induk']) ?>"><?= mspp_e($s['NAMA'].' · '.$s['kelas_rombel_snapshot']) ?></option><?php endforeach; ?></select></div>
              <div class="field-row"><label class="field-label">Efektif Mulai</label><select class="field-input field-select" name="cancel_start_month" required><?php foreach (spp_academic_periods($selectedYear) as $p): ?><option value="<?= $p['bulan'] ?>"><?= mspp_e($p['label']) ?></option><?php endforeach; ?></select></div>
              <div class="field-row full-span"><label class="field-label">Alasan</label><input class="field-input" name="cancel_reason" maxlength="255" required placeholder="Contoh: pindah sekolah efektif Oktober"></div>
              <div class="action-bar full-span"><button class="btn btn-warning" type="submit">Batalkan Tagihan Belum Bayar</button></div>
            </form>
          </section><?php endif; ?>
        </section>
      </section>
      <aside class="spp-aside" aria-label="Informasi tarif dan status tahun">
        <section class="main-card master-modern-card spp-side-card"><div class="spp-side-heading"><span class="spp-workspace-icon"><?= master_workspace_icon('coins') ?></span><div><h2>Tarif Dasar per Tingkat</h2><p>Tarif tersimpan untuk menentukan tagihan SPP sesuai kelas.</p></div></div><dl class="spp-rate-list"><?php for ($i=$firstLevel;$i<=$lastLevel;$i++): ?><div><dt>Kelas <?= $i ?></dt><dd>Rp <?= mspp_money($rates[$i]) ?></dd></div><?php endfor; ?></dl><a href="#spp-rate-panel" class="spp-side-link" data-spp-tab="rates">Lihat Pengaturan Tarif <span aria-hidden="true">&rarr;</span></a></section>
        <section class="main-card master-modern-card spp-side-card spp-status-card"><div class="spp-side-heading"><span class="spp-workspace-icon"><?= master_workspace_icon('calendar') ?></span><div><h2>Status Tahun SPP</h2><p>Menutup tahun menghentikan penerbitan tambahan. Tunggakan yang sudah terbit tetap bisa dilunasi.</p></div></div>
          <div class="spp-status-note"><?= master_workspace_icon($master['status'] === 'closed' ? 'lock' : 'info') ?><div><strong><?= $master['status'] === 'closed' ? 'Tahun ajaran ini sudah ditutup' : ($master['status'] === 'published' ? 'Tahun ajaran ini sedang terbuka' : 'Tahun ajaran ini masih draft') ?></strong><small><?= $master['status'] === 'closed' ? 'Buka kembali untuk melanjutkan penerbitan atau koreksi tarif.' : ($ratesEditable ? 'Tarif dapat diubah sebelum penerbitan pertama.' : 'Gunakan Edit Tarif untuk mengoreksi tagihan yang belum dibayar.') ?></small></div></div>
          <div class="action-bar">
            <?php if ($master['status'] === 'published'): ?><form method="post" onsubmit="return confirm('Tutup tahun SPP ini?')"><input type="hidden" name="csrf_token" value="<?= mspp_e($_SESSION['csrf_master_spp']) ?>"><input type="hidden" name="aksi" value="tutup"><input type="hidden" name="tahun_ajaran" value="<?= mspp_e($selectedYear) ?>"><button class="btn btn-warning" type="submit"><?= master_workspace_icon('lock') ?>Tutup Tahun</button></form>
            <?php elseif ($master['status'] === 'closed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= mspp_e($_SESSION['csrf_master_spp']) ?>"><input type="hidden" name="aksi" value="buka"><input type="hidden" name="tahun_ajaran" value="<?= mspp_e($selectedYear) ?>"><button class="btn btn-primary" type="submit"><?= master_workspace_icon('reset') ?>Buka Kembali</button></form><?php endif; ?>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main></div>
<script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<script id="spp-rate-confirmation-data" type="application/json"><?= json_encode(['year'=>$selectedYear,'unit'=>unit_label(unit_active_id()),'rates'=>$rates,'copy'=>array_combine(['simpan_tarif','ubah_tarif_terbit','terbitkan'],array_map('spp_rate_confirmation_copy',['simpan_tarif','ubah_tarif_terbit','terbitkan']))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/js/spp_rate_confirmation.js?v=<?= filemtime(__DIR__.'/assets/js/spp_rate_confirmation.js') ?>"></script>
<script src="assets/js/master_spp.js?v=<?= filemtime(__DIR__ . '/assets/js/master_spp.js') ?>"></script>
</body></html>
