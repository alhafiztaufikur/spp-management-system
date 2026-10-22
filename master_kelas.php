<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/kelas.php';
require_once 'includes/pagination.php';
require_once __DIR__.'/includes/filter_choices.php';
require_once __DIR__.'/includes/psb_placement.php';
require_once __DIR__.'/includes/special_classes_http.php';
require_once __DIR__.'/includes/promotion_participants_ui.php';
requireRole(['admin', 'kasir']);
[$unitMinLevel,$unitMaxLevel]=unit_level_bounds();
$modeInput=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?($_POST['promotion_mode']??'regular'):($_GET['promotion_mode']??'regular');
$promotionMode=is_string($modeInput)?$modeInput:'invalid';
$customPromotion=$promotionMode==='custom';

if (empty($_SESSION['csrf_master_kelas'])) $_SESSION['csrf_master_kelas'] = bin2hex(random_bytes(32));

special_classes_handle($koneksi);

function class_master_redirect(): void {
    $query=[];
    foreach(['source_year_id','source_level','source_rombel','promotion_q','promotion_mode'] as $key)if(isset($_POST[$key])&&is_scalar($_POST[$key]))$query[$key]=(string)$_POST[$key];
    header('Location: master_kelas.php'.($query?'?'.filter_build_query($query):''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_master_kelas'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Permintaan tidak valid atau sesi telah kedaluwarsa.');
        }
        $action = (string)($_POST['aksi'] ?? '');
        $isPromotion=in_array($action,['proses_siswa_batch','luluskan_siswa','naikkan_siswa'],true);
        if($isPromotion){
            if(!in_array($promotionMode,['regular','custom'],true))throw new RuntimeException('Mode kenaikan tidak valid.');
            if(!isset($_POST['source_year_id'],$_POST['source_level'],$_POST['target_tahun_ajaran']))throw new RuntimeException('Formulir lama tidak berlaku. Pilih tahun dan tingkat asal.');
            foreach(['source_year_id','source_level','target_tahun_ajaran'] as $field)if(!is_scalar($_POST[$field]))throw new RuntimeException('Konteks proses tidak valid.');
            foreach(['source_year_id','source_level'] as $field)if(!preg_match('/^[0-9]+$/D',(string)$_POST[$field])||(int)$_POST[$field]<=0)throw new RuntimeException('Konteks proses tidak valid.');
            if($action!=='proses_siswa_batch')foreach(['no_induk','source_placement_id','target_master_kelas_id'] as $field)if(isset($_POST[$field])&&!is_scalar($_POST[$field]))throw new RuntimeException('Identitas proses tidak valid.');
            $ctx=promotion_context($koneksi,(int)$_POST['source_year_id'],(string)$_POST['target_tahun_ajaran']);
            $level=(int)$_POST['source_level'];
            $participants=$action==='proses_siswa_batch'?(is_array($_POST['selected_students']??null)?$_POST['selected_students']:[]):[(string)($_POST['no_induk']??'')];
            $placements=$action==='proses_siswa_batch'?(is_array($_POST['source_placement_id']??null)?$_POST['source_placement_id']:[]):[(string)($_POST['no_induk']??'')=>(int)($_POST['source_placement_id']??0)];
            if(!$participants)throw new RuntimeException('Pilih minimal satu siswa.');
            foreach($participants as $nis)if(!is_scalar($nis)||!isset($placements[(string)$nis])||!is_scalar($placements[(string)$nis])||!preg_match('/^[0-9]+$/D',(string)$placements[(string)$nis])||(int)$placements[(string)$nis]<=0)throw new RuntimeException('Penempatan asal tidak lengkap. Muat ulang formulir.');
            if(($action==='luluskan_siswa'&&$level!==$unitMaxLevel)||($action==='naikkan_siswa'&&$level===$unitMaxLevel))throw new RuntimeException('Tindakan tidak sesuai tingkat asal.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $flashType = 'success';
        if ($action === 'tempatkan_psb') {
            if (!is_array($_POST['selected_students']??null) || !is_array($_POST['target_master_kelas_id']??null) || !is_string($_POST['target_tahun_ajaran']??null)) throw new RuntimeException('Formulir penempatan PSB tidak valid. Muat ulang halaman.');
            $batchResult=psb_placement_run($koneksi,$_POST['selected_students'],$_POST['target_master_kelas_id'],$_POST['target_tahun_ajaran']);
            $_SESSION['promotion_batch_result']=$batchResult;
            $successCount=count($batchResult['successes']);$failureCount=count($batchResult['failures']);
            $message=$successCount.' siswa PSB berhasil ditempatkan pada TA '.$batchResult['target_year'].'.';
            if ($failureCount) { $message.=' '.$failureCount.' siswa belum berhasil ditempatkan.'; $flashType=$successCount?'warning':'error'; }
        } elseif ($action === 'template_aj') {
            $created = class_ensure_rombel_templates($koneksi);
            $message = $created > 0 ? $created . ' kelas/template PSB dan rombel A-J berhasil ditambahkan.' : 'Kelas PSB dan template rombel A-J sudah lengkap.';
        } elseif ($isPromotion) {
            $targetClassIds=$action==='proses_siswa_batch'?(is_array($_POST['target_master_kelas_id']??null)?$_POST['target_master_kelas_id']:[]):[(string)($_POST['no_induk']??'')=>(int)($_POST['target_master_kelas_id']??0)];
            $batchResult=promotion_run_batch($koneksi,$participants,$targetClassIds,$ctx['source_year_id'],$ctx['target_year'],$level,$placements,$customPromotion);
            $_SESSION['promotion_batch_result']=$batchResult;
            $successCount=count($batchResult['successes']);$failureCount=count($batchResult['failures']);
            $message=$successCount.' siswa berhasil '.(!$customPromotion&&$level===$unitMaxLevel?'diluluskan ke Alumni'.promotion_alumni_year($ctx['source_year']):($customPromotion?'diproses':'dinaikkan')).' dari TA '.$ctx['source_year'].'.';
            if($failureCount){$message.=' '.$failureCount.' siswa ditahan atau belum berhasil diproses.';$flashType=$successCount?'warning':'error';}
        } elseif ($action === 'nonaktifkan_kosong') {
            $affected = class_disable_empty_rombel($koneksi);
            $message = $affected > 0 ? $affected . ' rombel kosong berhasil dinonaktifkan.' : 'Tidak ada rombel kosong aktif yang perlu dinonaktifkan.';
        } elseif ($action === 'aktifkan_semua_rombel') {
            $affected = class_enable_all_rombel($koneksi);
            $message = $affected > 0 ? $affected . ' rombel berhasil diaktifkan kembali.' : 'Seluruh rombel sudah aktif.';
        } elseif (in_array($action, ['tambah', 'update'], true)) {
            $level = (int)($_POST['tingkat'] ?? 0);
            $code = strtoupper(trim((string)($_POST['kode_rombel'] ?? '')));
            $code = preg_replace('/\s+/', '', $code);
            if ($level < $unitMinLevel || $level > $unitMaxLevel) throw new RuntimeException('Tingkat kelas harus '.$unitMinLevel.' sampai '.$unitMaxLevel.'. Kelas PSB dibuat otomatis oleh sistem.');
            if (!preg_match('/^[A-Z0-9]{1,10}$/', $code)) throw new RuntimeException('Kode rombel hanya boleh berisi huruf/angka, maksimal 10 karakter.');

            $stmt = $koneksi->prepare('SELECT id FROM master_kelas WHERE tingkat = ? AND kode_rombel = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('isi', $level, $code, $id);
            $stmt->execute(); $duplicate = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($duplicate) throw new RuntimeException('Rombel ' . $level . $code . ' sudah tersedia.');

            if ($action === 'tambah') {
                $stmt = $koneksi->prepare('INSERT INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active) VALUES (?, ?, 0, 1)');
                $stmt->bind_param('is', $level, $code);
                $stmt->execute(); $stmt->close();
                $message = 'Rombel ' . $level . $code . ' berhasil ditambahkan.';
            } else {
                $class = class_find($koneksi, $id);
                if (!$class || (int)$class['is_placeholder'] === 1 || class_kind($class)!=='reguler') throw new RuntimeException('Rombel PSB dan placeholder dikelola sistem dan tidak dapat diubah.');
                if ((int)$class['tingkat'] !== $level) {
                    $stmt = $koneksi->prepare('SELECT (SELECT COUNT(*) FROM siswa WHERE master_kelas_id=?) + (SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE master_kelas_id=?) total');
                    $stmt->bind_param('ii',$id,$id);$stmt->execute();$usage=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
                    if ($usage > 0) throw new RuntimeException('Tingkat rombel yang sudah dipakai tidak dapat diubah. Buat rombel baru agar histori tetap benar.');
                }
                $stmt = $koneksi->prepare('UPDATE master_kelas SET tingkat = ?, kode_rombel = ? WHERE id = ?');
                $stmt->bind_param('isi', $level, $code, $id);
                $stmt->execute(); $stmt->close();
                $message = 'Rombel berhasil diperbarui. Snapshot histori lama tetap dipertahankan.';
            }
        } elseif ($action === 'toggle') {
            $targetStatus = (string)($_POST['target_active'] ?? '');
            if (!in_array($targetStatus, ['0', '1'], true)) {
                throw new RuntimeException('Status tujuan rombel tidak valid. Muat ulang halaman.');
            }
            $koneksi->begin_transaction();
            $class = class_find($koneksi, $id, false, true);
            if (!$class || (int)$class['is_placeholder'] === 1) throw new RuntimeException('Rombel placeholder harus selalu aktif.');
            if(($class['legacy_kind']??'')==='alumni')throw new RuntimeException('Kelas Alumni merupakan kelompok nonakademik dan tetap nonaktif.');
            $newStatus = (int)$targetStatus;
            if (in_array(class_kind($class),['psb','out'],true) && $newStatus === 0) throw new RuntimeException('Rombel PSB harus tetap aktif untuk pendaftaran calon siswa.');
            if ((int)$class['is_active'] === $newStatus) {
                $koneksi->commit();
                $message = 'Status rombel sudah sesuai. Tidak ada perubahan.';
                $flashType = 'warning';
            } else {
                if ($newStatus === 0) {
                    $stmt=$koneksi->prepare('SELECT COUNT(*) total FROM siswa WHERE master_kelas_id=? AND is_active=1');$stmt->bind_param('i',$id);$stmt->execute();$activeStudents=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
                    if($activeStudents>0)throw new RuntimeException('Rombel masih dipakai '.$activeStudents.' siswa aktif dan belum dapat dinonaktifkan.');
                }
                $stmt = $koneksi->prepare('UPDATE master_kelas SET is_active = ? WHERE id = ?');
                $stmt->bind_param('ii', $newStatus, $id); $stmt->execute(); $stmt->close();
                $koneksi->commit();
                $message = 'Status rombel berhasil diubah.';
            }
        } else {
            throw new RuntimeException('Aksi Master Kelas tidak dikenali.');
        }
        $_SESSION['flash'] = ['type' => $flashType, 'msg' => $message];
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $error->getMessage()];
    }
    class_master_redirect();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$promotionBatchResult = $_SESSION['promotion_batch_result'] ?? null;
unset($_SESSION['promotion_batch_result']);
$promotionYears=promotion_year_options($koneksi);
$sourceYearId=(int)($_GET['source_year_id']??0);
if(!isset($_GET['source_year_id']))foreach($promotionYears as $year)if($year['label']===du_current_academic_year()){$sourceYearId=(int)$year['id'];break;}
$sourceYear='';$nextAcademicYear='';$promotionContextError='';
if($sourceYearId)try{$selectedContext=promotion_context($koneksi,$sourceYearId,null,true);$sourceYear=$selectedContext['source_year'];$nextAcademicYear=$selectedContext['target_year'];}catch(Throwable $e){$promotionContextError=$e->getMessage();$sourceYearId=0;}
$monitorPsb = ($_GET['source_level'] ?? null) === 'psb';
$currentPromotionLevel=(int)($_GET['source_level']??0);
if($currentPromotionLevel<$unitMinLevel||$currentPromotionLevel>$unitMaxLevel)$currentPromotionLevel=0;
$promotionQuery=trim((string)($_GET['promotion_q']??''));
$promotionDefaultSourceKey=trim((string)($_GET['source_rombel']??''));
$promotionRoster=$sourceYearId&&$currentPromotionLevel?promotion_roster($koneksi,$sourceYearId,$currentPromotionLevel):[];
$promotionHeld=array_values(array_filter($promotionRoster,static fn($r)=>$r['source_status']==='aktif'&&!$r['eligible']));

$editId = (int)($_GET['edit'] ?? 0);
$editClass = $editId > 0 ? class_find($koneksi, $editId) : null;
$classEditError='';
if ($editClass && ((int)$editClass['is_placeholder']===1 || in_array(class_kind($editClass),['psb','out'],true))) { $classEditError='Rombel PSB dan placeholder dikelola sistem. Pilih rombel reguler untuk mengedit.'; $editClass=null; }

$customEdit=$editClass&&class_kind($editClass)==='custom'?$editClass:null;if($customEdit)$editClass=null;
$promotionStudents=array_values(array_filter($promotionRoster,static fn($r)=>$r['eligible']));
$studentsMissingSourceYear=$sourceYearId&&!$monitorPsb?class_students_missing_source_year($koneksi,$nextAcademicYear):[];
$promotionTargets = $currentPromotionLevel >= $unitMinLevel && $currentPromotionLevel < $unitMaxLevel
    ? class_target_rombel_options($koneksi, $currentPromotionLevel + 1)
    : [];
if($customPromotion)$promotionTargets=array_values(array_filter(class_all($koneksi,true),static fn($c)=>(int)$c['tingkat']>0&&!(int)$c['is_placeholder']));
$promotionSourceRombels = [];
foreach ($promotionStudents as &$promotionStudent) {
    $sourceClassId = (int)($promotionStudent['master_kelas_id'] ?? 0);
    $sourceKey = $sourceClassId > 0 ? 'id:' . $sourceClassId : 'label:' . $promotionStudent['kelas_label'];
    $promotionStudent['source_key'] = $sourceKey;
    if (!isset($promotionSourceRombels[$sourceKey])) {
        $promotionSourceRombels[$sourceKey] = [
            'label' => $promotionStudent['kelas_label'],
            'code' => strtoupper(trim((string)($promotionStudent['kode_rombel'] ?? ''))),
            'count' => 0,
        ];
    }
    $promotionSourceRombels[$sourceKey]['count']++;
}
unset($promotionStudent);
if($promotionDefaultSourceKey!==''&&!isset($promotionSourceRombels[$promotionDefaultSourceKey]))$promotionDefaultSourceKey='';
$promotionTargetByCode = [];
foreach ($promotionTargets as $target) {
    $promotionTargetByCode[strtoupper((string)$target['kode_rombel'])] = (int)$target['id'];
}
$classes = $koneksi->query("SELECT mk.*,
    (SELECT COUNT(*) FROM siswa s WHERE s.master_kelas_id = mk.id AND s.is_active=1) AS siswa_count,
    (SELECT COUNT(*) FROM siswa_tahun_ajaran sta WHERE sta.master_kelas_id = mk.id) AS history_count
    FROM master_kelas mk WHERE mk.jenis NOT IN ('custom','out') AND NOT(mk.tingkat=0 AND UPPER(mk.kode_rombel) REGEXP '^ALUMNI20[0-9]{2}$') ORDER BY CASE WHEN mk.jenis='out' THEN 0 WHEN mk.jenis='psb' THEN 1 WHEN mk.jenis='custom' THEN 3 ELSE 2 END, mk.tingkat, mk.is_placeholder, mk.kode_rombel")->fetch_all(MYSQLI_ASSOC);
$classSearch = trim((string)($_GET['q_kelas'] ?? ''));
$levelOptions=['psb'=>'PSB'];foreach(range($unitMinLevel,$unitMaxLevel) as $level)$levelOptions[(string)$level]='Kelas '.$level;
$classLevels=filter_register('tingkat_kelas',$_GET['tingkat_kelas']??null,$levelOptions);
$classStatuses=filter_register('status_kelas',$_GET['status_kelas']??null,['aktif'=>'Aktif','nonaktif'=>'Nonaktif','placeholder'=>'Placeholder']);
$classLevelFilter=filter_scalar($classLevels);$classStatusFilter=filter_scalar($classStatuses);
filter_register('source_rombel_filter',$_GET['source_rombel_filter']??null,array_map(static fn($r)=>$r['label'],$promotionSourceRombels),$promotionDefaultSourceKey);
filter_output_start();
$classRows = array_values(array_filter($classes, static function (array $class) use ($classSearch, $classLevels, $classStatuses): bool {
    $label = class_label($class);
    $kind=class_kind($class);
    if(!filter_is_all($classLevels)&&!in_array($kind==='reguler'?(string)(int)$class['tingkat']:$kind,$classLevels,true))return false;
    $matchesStatus=filter_is_all($classStatuses)||array_filter($classStatuses,static fn($v)=>
        ($v==='aktif'&&(int)$class['is_active']===1&&(int)$class['is_placeholder']!==1)||
        ($v==='nonaktif'&&(int)$class['is_active']===0)||($v==='placeholder'&&(int)$class['is_placeholder']===1));
    if(!$matchesStatus)return false;
    if ($classSearch !== '') {
        $haystack = strtolower($label . ' kelas ' . $class['tingkat'] . ' ' . $class['kode_rombel']);
        if (!str_contains($haystack, strtolower($classSearch))) return false;
    }
    return true;
}));
$classTotalRows = count($classRows);
$classActiveCount = count(array_filter($classRows, static fn(array $class): bool => (int)$class['is_active'] === 1 && (int)$class['is_placeholder'] === 0));
$classInactiveCount = count(array_filter($classRows, static fn(array $class): bool => (int)$class['is_active'] === 0));
// Every filtered rombel is rendered once, in the established source order.
$classGroups = [];
foreach ($classRows as $class) {
    $grade = class_kind($class)==='reguler'?(int)$class['tingkat']:class_kind($class);
    if (!isset($classGroups[$grade])) $classGroups[$grade] = ['count'=>0,'students'=>0,'rows'=>[]];
    $classGroups[$grade]['count']++;
    $classGroups[$grade]['students'] += (int)$class['siswa_count'];
}
foreach ($classRows as $index=>$class) $classGroups[class_kind($class)==='reguler'?(int)$class['tingkat']:class_kind($class)]['rows'][$index] = $class;
$firstVisibleGroup = null;
foreach ($classGroups as $grade=>$group) if ($group['rows']) { $firstVisibleGroup=$grade; if (is_int($grade)&&$grade>0) break; }
$classQuickUrl = static function (?array $levels=null, ?array $statuses=null) use ($classSearch,$classLevels,$classStatuses): string {
    return 'master_kelas.php?'.filter_build_query(['q_kelas'=>$classSearch,'tingkat_kelas'=>$levels??$classLevels,'status_kelas'=>$statuses??$classStatuses]).'#kelola-rombel';
};
$psbStudents = $monitorPsb ? $koneksi->query("SELECT s.id,s.unit_id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA FROM siswa s WHERE s.KELAS IN ('0','PSB') AND s.is_active=1 AND s.legacy_pending=0 ORDER BY s.unit_id,s.NAMA,s.NO_INDUK")->fetch_all(MYSQLI_ASSOC) : [];
$promotionLookup=trim((string)($_GET['promotion_lookup']??''));$promotionMatches=[];
if($promotionLookup!==''){
    $like='%'.$promotionLookup.'%';$q=$koneksi->prepare("SELECT s.NAMA,s.NO_INDUK,p.kelas,p.kelas_rombel_snapshot,p.status,p.tahun_ajaran_id,y.label FROM siswa s JOIN siswa_tahun_ajaran p ON p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE (s.NAMA LIKE ? OR s.NO_INDUK LIKE ? OR s.NO_induk_diknas LIKE ?) AND p.status<>'dibatalkan' ORDER BY s.NAMA,y.label DESC LIMIT 100");$q->bind_param('sss',$like,$like,$like);$q->execute();$promotionMatches=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
}
$participantsModel=$sourceYearId&&$currentPromotionLevel?participants_model($koneksi,$sourceYearId,$currentPromotionLevel,$customPromotion?'custom':'regular',$promotionRoster):null;
$participantsOpen=($_GET['show_participants']??'')==='1'||$promotionLookup!=='';
$psbYear=du_current_academic_year();
$psbTargets=$monitorPsb&&!unit_all_readonly()?class_target_rombel_options($koneksi,$unitMinLevel):[];
require_once __DIR__.'/includes/master_workspace_ui.php';
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Master Kelas | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png?v=2">
  <link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="assets/css/promotion.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/promotion.css') ?>">
  <link rel="stylesheet" href="assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/date_controls.css') ?>">
  <link rel="stylesheet" href="assets/css/master_kelas.css?v=<?= filemtime(__DIR__ . '/assets/css/master_kelas.css') ?>">
  <link rel="stylesheet" href="assets/css/workspace_readability.css?v=<?= filemtime(__DIR__ . '/assets/css/workspace_readability.css') ?>">
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/css/promotion_participants.css?v=<?= filemtime(__DIR__.'/assets/css/promotion_participants.css') ?>">
<link rel="stylesheet" href="assets/css/rombel_payment_refinement.css?v=<?= filemtime(__DIR__.'/assets/css/rombel_payment_refinement.css') ?>">
<link rel="stylesheet" href="assets/css/legacy_workspace.css?v=<?= filemtime(__DIR__.'/assets/css/legacy_workspace.css') ?>">
<link rel="stylesheet" href="assets/css/special_classes.css?v=<?= filemtime(__DIR__.'/assets/css/special_classes.css') ?>">
<link rel="stylesheet" href="assets/css/menu_refinements.css?v=<?= filemtime(__DIR__.'/assets/css/menu_refinements.css') ?>"></head>
<body data-readable-workspace="master-classes">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content class-workspace" data-class-mode="manage">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div class="topbar-title"><h2>Master Kelas &amp; Rombel</h2><span class="breadcrumb">SistemSPP / Data Master / Kelas</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

    <div class="class-workspace-content">
    <header class="class-page-heading"><div><span class="breadcrumb">SistemSPP / Data Master</span><h1>Master Kelas/Rombel</h1><p>Kelola rombel dan proses perpindahan siswa untuk setiap tahun ajaran.</p></div><nav class="class-mode-tabs" aria-label="Bagian Master Kelas"><a href="#kelola-rombel" data-class-tab="manage"><?= master_workspace_icon('layers') ?>Kelola Rombel</a><a href="#naik-kelas" data-class-tab="promotion"><?= master_workspace_icon('student') ?>Naik Kelas</a></nav></header>
      <nav class="class-quick-filters" aria-label="Filter cepat rombel">
        <a href="<?= htmlspecialchars($classQuickUrl(['*'],['*'])) ?>" <?= filter_is_all($classLevels)&&filter_is_all($classStatuses)?'aria-current="true"':'' ?>>Semua</a>
        <?php foreach (['aktif'=>'Aktif','nonaktif'=>'Nonaktif'] as $status=>$caption): ?><a href="<?= htmlspecialchars($classQuickUrl(null,[$status])) ?>" <?= $classStatuses===[$status]?'aria-current="true"':'' ?>><?= $caption ?></a><?php endforeach; ?>
        <a href="<?= htmlspecialchars($classQuickUrl(['psb'])) ?>" <?= $classLevels===['psb']?'aria-current="true"':'' ?>>PSB</a>
        <?php foreach (range($unitMinLevel,$unitMaxLevel) as $grade): ?><a href="<?= htmlspecialchars($classQuickUrl([(string)$grade])) ?>" <?= $classLevels===[(string)$grade]?'aria-current="true"':'' ?>>Kelas <?= $grade ?></a><?php endforeach; ?>
      </nav>
    <section class="class-summary-grid" aria-label="Ringkasan kelas dan rombel">
      <div class="class-stat"><span class="class-workspace-icon"><?= master_workspace_icon('layers') ?></span><div><span>Total Rombel</span><strong><?= number_format(count($classes)) ?></strong><small>Seluruh rombel yang terdaftar.</small></div></div>
      <div class="class-stat"><span class="class-workspace-icon"><?= master_workspace_icon('students') ?></span><div><span>Siswa Aktif</span><strong><?= number_format(array_sum(array_map(fn($row)=>in_array(class_kind($row),['reguler','psb'],true)?(int)$row['siswa_count']:0, $classes))) ?></strong><small>Total siswa aktif dalam rombel.</small></div></div>
      <div class="class-stat"><span class="class-workspace-icon"><?= master_workspace_icon('calendar') ?></span><div><span>Histori</span><strong><?= number_format(array_sum(array_map(fn($row)=>(int)$row['history_count'], $classes))) ?></strong><small>Seluruh catatan penempatan siswa.</small></div></div>
      <div class="class-stat"><span class="class-workspace-icon"><?= master_workspace_icon('document') ?></span><div><span>Rombel Aktif / Nonaktif</span><strong><span><?= number_format(count(array_filter($classes, static fn($row)=>(int)$row['is_active']===1))) ?></span><span class="class-stat-divider"> / </span><span><?= number_format(count(array_filter($classes, static fn($row)=>(int)$row['is_active']===0))) ?></span></strong><small>Termasuk placeholder yang dikelola sistem.</small></div></div>
      <?php if (!unit_all_readonly()): ?><div class="class-create-actions"><a class="class-add-shortcut" href="#class-rombel-form" data-open-class-form><span class="class-shortcut-icon" aria-hidden="true"><?= master_workspace_icon('plus') ?></span><div><strong>Tambah Rombel</strong><small>Buat rombel baru untuk kelas pada unit ini.</small></div><span aria-hidden="true">&#8594;</span></a><a class="btn btn-primary" href="master_kelas.php?special_new=1#special-classes"><?= master_workspace_icon('plus') ?> Tambah Kelas Khusus</a></div><?php endif; ?>
    </section>

    <section id="kelola-rombel" data-class-panel="manage" aria-label="Kelola rombel">
    <?php if (!unit_all_readonly()): ?>
    <details class="main-card master-modern-card master-modern-form class-add-panel" id="class-rombel-form" open><summary class="class-form-summary"><?= $editClass ? 'Edit Rombel' : 'Tambah Rombel' ?></summary><div class="class-form-content"><?php if ($classEditError): ?><div class="alert alert-warning"><?= htmlspecialchars($classEditError) ?></div><?php endif; ?>
      <div class="card-title-row"><span class="class-workspace-icon"><?= master_workspace_icon('plus') ?></span><div><div class="card-title"><?= $editClass ? 'Edit Rombel' : 'Tambah Rombel' ?></div><p class="payment-auto-note">Tingkat unit ini <?= $unitMinLevel ?>–<?= $unitMaxLevel ?>. Kode rombel membentuk label seperti <?= $unitMinLevel ?>A atau <?= $unitMinLevel ?>B.</p></div></div>
      <form method="post" class="report-filter-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
        <input type="hidden" name="aksi" value="<?= $editClass ? 'update' : 'tambah' ?>"><input type="hidden" name="id" value="<?= (int)($editClass['id'] ?? 0) ?>">
        <div class="field-row"><label class="field-label">Tingkat</label><select class="field-input field-select" name="tingkat" required><?php for($i=$unitMinLevel;$i<=$unitMaxLevel;$i++): ?><option value="<?= $i ?>" <?= (int)($editClass['tingkat'] ?? $unitMinLevel)===$i?'selected':'' ?>>Kelas <?= $i ?></option><?php endfor; ?></select></div>
        <div class="field-row"><label class="field-label">Kode Rombel</label><input class="field-input" name="kode_rombel" maxlength="10" required placeholder="Contoh: A" value="<?= htmlspecialchars((string)($editClass['kode_rombel'] ?? '')) ?>" <?= $editClass && (int)$editClass['is_placeholder']===1?'disabled':'' ?>></div>
        <div class="report-filter-actions"><button class="btn btn-primary" type="submit" <?= $editClass && (int)$editClass['is_placeholder']===1?'disabled':'' ?>><?= master_workspace_icon($editClass?'save':'plus') ?><?= $editClass?'Simpan Perubahan':'Tambah Rombel' ?></button><?php if($editClass): ?><a class="btn btn-ghost" href="master_kelas.php">Batal</a><?php endif; ?></div>
      </form>
    </div></details>
    <?php endif; ?>
      <div class="class-manage-grid">
    <section class="main-card master-modern-card master-modern-list class-list-panel" data-rombel-view="groups">
      <div class="card-title-row class-list-heading"><span class="class-workspace-icon"><?= master_workspace_icon('layers') ?></span><div><div class="card-title">Daftar Rombel per Kelas</div></div><span class="class-group-total"><?= number_format($classTotalRows) ?> rombel hasil filter</span></div>
      <div class="class-view-switch" hidden role="group" aria-label="Tampilan daftar rombel"><button type="button" data-class-view="groups" aria-pressed="true">Per Kelas</button><button type="button" data-class-view="cards" aria-pressed="false">Kartu</button><button type="button" data-class-view="table" aria-pressed="false">Tabel</button></div>
      <div class="class-level-groups">
      <?php if (!$classGroups): ?><div class="empty-state"><p>Rombel tidak ditemukan</p><span>Ubah pencarian atau filter yang dipilih.</span></div><?php endif; ?>
      <?php foreach ($classGroups as $grade=>$group): ?>
        <details class="class-level-group" data-level-group <?= $grade===$firstVisibleGroup?'open':'' ?>>
          <summary><span class="class-rombel-icon" aria-hidden="true"><?= master_workspace_icon('layers') ?></span><strong><?= is_int($grade)?'Kelas '.$grade:($grade==='psb'?'PSB':ucfirst($grade)) ?></strong><span class="class-group-count"><?= number_format($group['count']) ?> rombel</span><span class="class-group-students"><?= number_format($group['students']) ?> siswa aktif</span><svg class="class-group-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></summary>
          <?php if ($group['rows']): ?>
          <div class="table-container" tabindex="0" aria-label="Rombel <?= is_int($grade)?'Kelas '.$grade:($grade==='psb'?'PSB':ucfirst($grade)) ?>"><table class="payment-table responsive-table rombel-data-table"><thead><tr><th>No</th><th>Label</th><th>Tingkat</th><th>Status</th><th>Siswa Aktif</th><th>Histori</th><th>Aksi</th></tr></thead><tbody>
          <?php foreach ($group['rows'] as $i=>$class): $label=class_label($class); ?>
        <tr data-class-id="<?= (int)$class['id'] ?>"><td data-label="No"><?= $i+1 ?></td><td data-label="Label"><span class="class-rombel-icon" aria-hidden="true"><?= master_workspace_icon('students') ?></span><strong><?= unit_record_badge($class) ?><?= htmlspecialchars($label) ?></strong></td><td data-label="Tingkat"><span class="kelas-badge"><?= class_kind($class)==='reguler'?'Kelas '.(int)$class['tingkat']:ucfirst(class_kind($class)) ?></span></td><td data-label="Status"><span class="master-status <?= (int)$class['is_active']===1?'is-active':'is-inactive' ?>"><?= (int)$class['is_placeholder']===1?'Placeholder':((int)$class['is_active']===1?'Aktif':'Nonaktif') ?></span></td><td data-label="Siswa Aktif" class="text-center"><span class="class-card-caption">Siswa Aktif</span><?= number_format((int)$class['siswa_count']) ?></td><td data-label="Histori" class="text-center"><span class="class-card-caption">Histori</span><?= number_format((int)$class['history_count']) ?></td><td data-label="Aksi" class="aksi-col">
        <?php if(!unit_all_readonly() && (int)$class['is_placeholder']!==1 && in_array(class_kind($class),['reguler','custom'],true)): ?><a class="btn-tbl btn-tbl-edit" href="master_kelas.php?edit=<?= (int)$class['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>"><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$class['id'] ?>"><input type="hidden" name="target_active" value="<?= (int)$class['is_active']===1?'0':'1' ?>"><button class="btn-tbl btn-tbl-toggle <?= (int)$class['is_active']===1?'is-deactivate':'is-activate' ?>" type="submit"><?= (int)$class['is_active']===1?'Nonaktifkan':'Aktifkan' ?></button></form><?php elseif (!unit_all_readonly() && class_kind($class)==='psb' && !(int)$class['is_active']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>"><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$class['id'] ?>"><input type="hidden" name="target_active" value="1"><button class="btn-tbl btn-tbl-toggle is-activate" type="submit">Aktifkan PSB</button></form><?php else: ?><span class="payment-auto-note"><?= !unit_all_readonly()?'Dikelola sistem':'Pilih satu unit untuk mengubah rombel' ?></span><?php endif; ?>
        </td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </details>
      <?php endforeach; ?>
      </div>
      <?php if (!unit_all_readonly()): ?>
      <div class="master-rombel-maintenance">
        <div class="master-rombel-maintenance-copy"><strong>Pengelolaan Rombel</strong><span>Nonaktifkan rombel reguler tanpa siswa aktif di seluruh unit ini. PSB, placeholder, dan histori tetap dilindungi.</span></div>
        <div class="master-rombel-maintenance-actions">
          <form method="post" onsubmit="return confirm('Aktifkan kembali seluruh rombel reguler? Data siswa dan histori tidak diubah.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
            <input type="hidden" name="aksi" value="aktifkan_semua_rombel">
            <button class="btn btn-primary" type="submit"><?= master_workspace_icon('check') ?><span>Aktifkan Semua Rombel</span></button>
          </form>
          <form method="post" onsubmit="return confirm('Nonaktifkan seluruh rombel reguler tanpa siswa aktif pada unit ini? PSB, placeholder, dan histori tetap aman.')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
            <input type="hidden" name="aksi" value="nonaktifkan_kosong">
            <button class="btn btn-warning" type="submit"><?= master_workspace_icon('lock') ?><span>Nonaktifkan Rombel Kosong</span></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </section>
    <aside class="main-card class-filter-panel">
      <div class="class-panel-heading"><span class="class-workspace-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 4h18l-7 8v7l-4 2v-9L3 4Z"/></svg></span><div><h2>Filter &amp; Pencarian</h2></div></div>
      <form method="get" class="master-class-filter-bar">
        <div class="field-row"><label class="field-label" for="class-filter-search">Cari Rombel</label>
        <div class="search-box"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="search" name="q_kelas" id="class-filter-search" value="<?= htmlspecialchars($classSearch) ?>" placeholder="Cari label atau kode rombel..."></div>
        </div>
        <div class="field-row"><label class="field-label" for="class-filter-level">Tingkat / Kelas</label>
        <select class="field-input field-select" name="tingkat_kelas" id="class-filter-level" data-filter-multiple aria-label="Filter tingkat"><option value="">Semua tingkat</option><option value="psb" <?= $classLevelFilter==='psb'?'selected':'' ?>>PSB</option><?php for($i=$unitMinLevel;$i<=$unitMaxLevel;$i++): ?><option value="<?= $i ?>" <?= $classLevelFilter===(string)$i?'selected':'' ?>>Kelas <?= $i ?></option><?php endfor; ?></select>
        </div>
        <div class="field-row"><label class="field-label" for="class-filter-status">Status</label>
        <select class="field-input field-select" name="status_kelas" id="class-filter-status" data-filter-multiple aria-label="Filter status"><option value="">Semua status</option><option value="aktif" <?= $classStatusFilter==='aktif'?'selected':'' ?>>Aktif</option><option value="nonaktif" <?= $classStatusFilter==='nonaktif'?'selected':'' ?>>Nonaktif</option><option value="placeholder" <?= $classStatusFilter==='placeholder'?'selected':'' ?>>Placeholder</option></select>
        </div>
        <button class="btn btn-primary" type="submit">Terapkan Filter</button><a class="btn btn-ghost" href="master_kelas.php">Reset Filter</a>
      </form>
      <div class="master-class-summary"><div><span>Hasil Filter</span><strong><?= number_format($classTotalRows) ?></strong></div><div><span>Rombel Aktif</span><strong><?= number_format($classActiveCount) ?></strong></div><div><span>Rombel Nonaktif</span><strong><?= number_format($classInactiveCount) ?></strong></div></div>
    </aside>
      </div>
    <?php require __DIR__.'/includes/special_classes_view.php'; ?>
    <?php require __DIR__.'/includes/legacy_operational_groups_view.php'; ?>
    </section>
    <section id="naik-kelas" data-class-panel="promotion" aria-label="Proses tahun ajaran">
    <section class="main-card master-modern-card class-context-panel">
      <div class="card-title-row"><span class="class-workspace-icon"><?= master_workspace_icon('reset') ?></span><div><div class="card-title">Proses Tahun Ajaran</div><p class="payment-auto-note">Pilih tahun dan tingkat asal. Setiap tingkat dapat diproses tanpa menunggu tingkat lain selesai.</p></div></div>
      <?php if($promotionContextError): ?><div class="alert alert-warning"><?= htmlspecialchars($promotionContextError) ?></div><?php endif; ?>
      <?php if(!unit_all_readonly()): ?>
      <form method="get" id="promotion-context-form" data-custom="<?= $customPromotion?'1':'0' ?>" class="report-filter-grid" data-last-level="<?= $unitMaxLevel ?>">
        <div class="field-row"><label class="field-label" for="promotion-mode">Sistem Kenaikan</label><select class="field-input field-select" id="promotion-mode" name="promotion_mode"><option value="regular" <?= !$customPromotion?'selected':'' ?>>Reguler satu tingkat</option><option value="custom" <?= $customPromotion?'selected':'' ?>>Custom pilih tingkat/rombel</option></select></div><div class="field-row"><label class="field-label" for="promotion-source-year"><?= $monitorPsb?'Tahun Penempatan':'Tahun Ajaran Asal' ?></label><select id="promotion-source-year" name="source_year_id" class="field-input field-select" <?= $monitorPsb?'disabled':'required' ?>><?php if ($monitorPsb): ?><option value="<?= $sourceYearId ?>"><?= htmlspecialchars($psbYear) ?></option><?php else: ?><option value="">Pilih tahun asal</option><?php foreach($promotionYears as $year): ?><option value="<?= (int)$year['id'] ?>" <?= $sourceYearId===(int)$year['id']?'selected':'' ?>><?= htmlspecialchars($year['label']) ?></option><?php endforeach; endif; ?></select></div>
        <div class="field-row"><label class="field-label" for="promotion-source-level">Tingkat Asal</label><select id="promotion-source-level" name="source_level" class="field-input field-select" required><option value="">Pilih tingkat</option><option value="psb" <?= $monitorPsb?'selected':'' ?>>PSB</option><?php foreach(range($unitMinLevel,$unitMaxLevel) as $grade): ?><option value="<?= $grade ?>" <?= $currentPromotionLevel===$grade?'selected':'' ?>>Kelas <?= $grade ?><?= $grade===$unitMaxLevel?' (Kelulusan)':'' ?></option><?php endforeach; ?></select></div>
        <div class="field-row"><span class="field-label" id="promotion-target-label"><?= $monitorPsb?(unit_all_readonly()?'Mode Tampilan':'Kelas Tujuan'):((!$customPromotion && $currentPromotionLevel===$unitMaxLevel)?'Tahun Kelulusan':'Tahun Ajaran Tujuan') ?></span><strong id="promotion-target-year"><?= $monitorPsb?(unit_all_readonly()?'Pemantauan PSB':'Kelas '.$unitMinLevel):htmlspecialchars(((!$customPromotion && $currentPromotionLevel===$unitMaxLevel)?$sourceYear:$nextAcademicYear)?:'Pilih tahun asal') ?></strong><small><?= $monitorPsb?(unit_all_readonly()?'Pilih satu unit untuk menempatkan siswa PSB.':'Penempatan awal pada tahun ajaran '.$psbYear.'.'):((!$customPromotion && $currentPromotionLevel===$unitMaxLevel)?'Kelulusan memakai tahun asal, tanpa membuat penempatan tahun berikutnya.':'Tepat satu tahun berikutnya; tahun mendatang belum boleh menjadi asal.') ?></small></div>
        <div class="report-filter-actions"><button class="btn btn-primary" type="submit"><?= master_workspace_icon('send') ?>Tampilkan Peserta</button></div>
      <input type="hidden" name="show_participants" value="1">
      </form>
      <p class="promotion-context-pending" id="promotion-context-pending" role="status" tabindex="-1" hidden>Pilihan belum diterapkan. Klik Tampilkan Peserta untuk memuat daftar yang sesuai sebelum memproses siswa.</p>
      <noscript><a class="btn btn-ghost" href="master_kelas.php?source_level=psb#naik-kelas">Lihat Siswa PSB</a></noscript>
      <?php else: ?><p class="payment-auto-note">Pilih SD, SMP, atau SMA untuk mengelola rombel dan memproses tahun ajaran. Semua Unit digunakan untuk pemantauan.</p><a class="btn btn-ghost" href="master_kelas.php?source_level=psb#naik-kelas">Lihat PSB</a><?php endif; ?>
    </section>
    <div class="class-participants-panel">
      <?php participants_panel($participantsModel,$promotionLookup,$promotionMatches,$sourceYearId,$currentPromotionLevel,$customPromotion?'custom':'regular',$participantsOpen); ?>
      <?php if ($promotionBatchResult): ?>
      <div class="promotion-batch-result <?= count($promotionBatchResult['failures']) > 0 ? 'has-failures' : '' ?>">
        <?php foreach($promotionBatchResult['successes'] as $success): if(empty($success['target_placement_id']))continue;$destId=(int)$success['target_placement_id'];$dest=$koneksi->query("SELECT p.tahun_ajaran_id,p.kelas FROM siswa_tahun_ajaran p WHERE p.id=$destId")->fetch_assoc();if(!$dest)continue; ?><p><?= htmlspecialchars($success['student'].' ? '.$success['target'].' ? '.$success['target_year']) ?> <a class="btn btn-ghost" href="master_kelas.php?source_year_id=<?= (int)$dest['tahun_ajaran_id'] ?>&amp;source_level=<?= (int)$dest['kelas'] ?>&amp;show_participants=1#naik-kelas">Lihat Peserta Tujuan</a></p><?php endforeach; ?>
        <div><strong><?= number_format(count($promotionBatchResult['successes'])) ?> berhasil</strong><span>dari <?= number_format((int)$promotionBatchResult['attempted']) ?> siswa yang dipilih.</span></div>
        <?php if ($promotionBatchResult['failures']): ?>
        <details><summary>Lihat <?= number_format(count($promotionBatchResult['failures'])) ?> siswa yang belum berhasil</summary><ul><?php foreach ($promotionBatchResult['failures'] as $failure): ?><li><strong><?= htmlspecialchars($failure['student']) ?></strong><span><?= htmlspecialchars($failure['reason']) ?></span></li><?php endforeach; ?></ul></details>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="master-promotion-actions">
        <?php if ($studentsMissingSourceYear): ?>
        <div class="alert alert-warning" style="margin-bottom:1rem"><?= number_format(count($studentsMissingSourceYear)) ?> siswa aktif belum memiliki riwayat kelas TA <?= htmlspecialchars($sourceYear) ?>. Periksa Data Siswa atau hasil migrasi sebelum menerbitkan tahun berikutnya.</div>
        <?php endif; ?>
        <?php if ($monitorPsb && !unit_all_readonly()): ?>
        <section class="main-card class-psb-monitor">
          <div class="class-panel-heading"><span class="class-workspace-icon"><?= master_workspace_icon('students') ?></span><div><h2>Tempatkan Siswa PSB</h2><p>Kelas <?= $unitMinLevel ?> pada tahun ajaran <?= htmlspecialchars($psbYear) ?>. Pilih rombel untuk setiap siswa.</p></div></div>
          <?php if (!$psbTargets): ?><div class="alert alert-warning">Belum ada rombel aktif pada kelas <?= $unitMinLevel ?>. Aktifkan atau tambahkan rombel terlebih dahulu.</div><?php endif; ?>
          <form method="post" id="psb-placement-form" data-target-year="<?= htmlspecialchars($psbYear) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>"><input type="hidden" name="aksi" value="tempatkan_psb"><input type="hidden" name="source_level" value="psb"><input type="hidden" name="target_tahun_ajaran" value="<?= htmlspecialchars($psbYear) ?>">
            <label class="field-label" for="psb-monitor-search">Cari Siswa PSB</label>
            <div class="search-box"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" id="psb-monitor-search" placeholder="Cari nama, NIS, atau NIS Diknas..." autocomplete="off"></div>
            <div class="promotion-selection-toolbar"><div><strong id="psb-monitor-count" role="status"><?= count($psbStudents) ?> siswa PSB aktif</strong><span id="psb-selected-count" role="status">0 siswa dipilih</span></div><div><button class="btn btn-ghost" type="button" id="psb-select-visible">Pilih yang Ditampilkan</button><button class="btn btn-ghost" type="button" id="psb-select-all">Pilih Semua</button><button class="btn btn-ghost" type="button" id="psb-clear">Kosongkan Pilihan</button></div></div>
            <div class="class-psb-list" tabindex="0" aria-label="Daftar siswa dan rombel tujuan PSB">
              <?php foreach ($psbStudents as $index=>$student): ?>
              <article class="class-psb-row" data-psb-id="<?= (int)$student['id'] ?>" data-psb-unit="<?= (int)$student['unit_id'] ?>" data-psb-search="<?= htmlspecialchars(mb_strtolower($student['NAMA'].' '.$student['NO_INDUK'].' '.$student['NO_induk_diknas'],'UTF-8')) ?>">
                <input type="checkbox" id="psb-check-<?= (int)$student['id'] ?>" name="selected_students[]" value="<?= htmlspecialchars($student['NO_INDUK']) ?>" aria-label="Pilih <?= htmlspecialchars($student['NAMA']) ?>">
                <span class="class-psb-number"><?= $index+1 ?></span><div><label for="psb-check-<?= (int)$student['id'] ?>"><strong><?= htmlspecialchars($student['NAMA']) ?></strong></label><small>NIS <?= htmlspecialchars($student['NO_INDUK']) ?> &middot; NIS Diknas <?= htmlspecialchars($student['NO_induk_diknas'] ?: 'Tidak tercatat') ?></small></div>
                <div class="class-psb-target-field"><label class="field-label" for="psb-target-<?= (int)$student['id'] ?>">Rombel Kelas <?= $unitMinLevel ?></label><select class="field-input field-select" id="psb-target-<?= (int)$student['id'] ?>" name="target_master_kelas_id[<?= htmlspecialchars($student['NO_INDUK']) ?>]" data-psb-target><option value="">Pilih rombel</option><?php foreach ($psbTargets as $target): ?><option value="<?= (int)$target['id'] ?>"><?= htmlspecialchars($target['label']) ?></option><?php endforeach; ?></select></div>
              </article>
              <?php endforeach; ?>
              <div class="empty-state" id="psb-monitor-empty" <?= $psbStudents?'hidden':'' ?>><p>Tidak ada siswa PSB yang cocok</p><span>Daftar mengikuti siswa PSB aktif pada unit ini.</span></div>
            </div>
            <div class="promotion-submit-bar class-psb-submit"><div><strong id="psb-submit-summary">Pilih siswa dan rombel tujuan</strong><span>Tahun penempatan <?= htmlspecialchars($psbYear) ?> &middot; Kelas <?= $unitMinLevel ?></span></div><button class="btn btn-primary" id="psb-submit" type="submit" <?= !$psbStudents||!$psbTargets?'disabled':'' ?>>Tempatkan Siswa</button></div>
          </form>
        </section>
        <?php elseif ($monitorPsb): ?>
        <section class="main-card class-psb-monitor">
          <div class="class-panel-heading"><span class="class-workspace-icon"><?= master_workspace_icon('students') ?></span><div><h2>Pemantauan Siswa PSB</h2><p>Daftar calon siswa aktif. PSB tidak diproses melalui kenaikan kelas.</p></div></div>
          <label class="field-label" for="psb-monitor-search">Cari Siswa PSB</label>
          <div class="search-box"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" id="psb-monitor-search" placeholder="Cari nama, NIS, atau NIS Diknas..." autocomplete="off"></div>
          <p class="class-psb-count" id="psb-monitor-count" role="status"><?= count($psbStudents) ?> siswa PSB aktif</p>
          <div class="class-psb-list" tabindex="0" aria-label="Daftar siswa PSB">
            <?php foreach ($psbStudents as $index=>$student): ?>
            <article class="class-psb-row" data-psb-id="<?= (int)$student['id'] ?>" data-psb-unit="<?= (int)$student['unit_id'] ?>" data-psb-search="<?= htmlspecialchars(mb_strtolower($student['NAMA'].' '.$student['NO_INDUK'].' '.$student['NO_induk_diknas'],'UTF-8')) ?>">
              <span class="class-psb-number"><?= $index+1 ?></span><span class="class-rombel-icon" aria-hidden="true"><?= master_workspace_icon('student') ?></span>
              <div><strong><?= htmlspecialchars($student['NAMA']) ?></strong><small>NIS <?= htmlspecialchars($student['NO_INDUK']) ?> · NIS Diknas <?= htmlspecialchars($student['NO_induk_diknas'] ?: 'Tidak tercatat') ?></small><?= unit_record_badge($student) ?></div><span class="kelas-badge">PSB</span>
            </article>
            <?php endforeach; ?>
            <div class="empty-state" id="psb-monitor-empty" <?= $psbStudents?'hidden':'' ?>><p>Tidak ada siswa PSB yang cocok</p><span>Daftar mengikuti siswa PSB aktif pada cakupan unit ini.</span></div>
          </div>
        </section>
        <?php elseif(!unit_all_readonly() && $currentPromotionLevel >= $unitMinLevel && $currentPromotionLevel <= $unitMaxLevel): ?>
        <form method="post" id="promotion-batch-form" class="promotion-batch-form" data-graduation="<?= (!$customPromotion && $currentPromotionLevel===$unitMaxLevel)?'1':'0' ?>" data-source-year="<?= htmlspecialchars($sourceYear) ?>" data-target-year="<?= htmlspecialchars($nextAcademicYear) ?>" data-promotion-action="<?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Luluskan' : 'Naikkan' ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
          <input type="hidden" name="aksi" value="proses_siswa_batch"><input type="hidden" name="promotion_mode" value="<?= $customPromotion?'custom':'regular' ?>">
          <input type="hidden" name="target_tahun_ajaran" value="<?= htmlspecialchars($nextAcademicYear) ?>">
          <input type="hidden" name="source_year_id" value="<?= $sourceYearId ?>">
          <input type="hidden" name="source_level" value="<?= $currentPromotionLevel ?>">
          <input type="hidden" name="source_rombel" id="promotion-source-rombel-submit" value="<?= htmlspecialchars($promotionDefaultSourceKey) ?>">
          <input type="hidden" name="promotion_q" id="promotion-search-submit" value="<?= htmlspecialchars($promotionQuery) ?>">

          <aside class="main-card class-source-panel">
            <div class="class-panel-heading"><span class="class-workspace-icon"><?= master_workspace_icon('layers') ?></span><div><h2>Rombel Asal</h2><p>Peserta tersedia pada tingkat dan tahun yang dipilih.</p></div></div>
            <div class="field-row"><label class="field-label" for="promotion-source-filter">Rombel Asal</label><select class="field-input field-select" id="promotion-source-filter" name="source_rombel_filter" data-filter-multiple data-filter-persist data-all-label="Semua rombel"><option value="">Semua rombel</option><?php foreach($promotionSourceRombels as $sourceKey => $source): ?><option value="<?= htmlspecialchars($sourceKey) ?>" <?= $promotionDefaultSourceKey === (string)$sourceKey ? 'selected' : '' ?>><?= htmlspecialchars($source['label']) ?> (<?= number_format($source['count']) ?>)</option><?php endforeach; ?></select></div>
            <ul class="class-context-list" aria-label="Rombel sumber dan jumlah peserta">
              <?php foreach ($promotionSourceRombels as $source): ?><li><span class="class-rombel-icon" aria-hidden="true"><?= master_workspace_icon('students') ?></span><div><strong><?= htmlspecialchars($source['label']) ?></strong><small>Kelas <?= (int)$currentPromotionLevel ?></small></div><span class="class-source-count"><strong><?= number_format($source['count']) ?></strong><small>siswa</small></span></li><?php endforeach; ?>
              <?php if (!$promotionSourceRombels): ?><li class="class-context-empty">Belum ada rombel sumber dengan peserta tersedia.</li><?php endif; ?>
            </ul>
          </aside>
          <section class="main-card class-roster-panel">
            <div class="class-panel-heading"><span class="class-workspace-icon"><?= master_workspace_icon('students') ?></span><div><h2>Peserta Tahun Ajaran</h2><p>Pilih siswa untuk kenaikan kelas atau kelulusan sesuai tingkat asal.</p></div></div>
          <div class="promotion-filter-bar">
            <div class="field-row promotion-search-field"><label class="field-label" for="promotion-batch-search">Cari Siswa</label><div class="search-box"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="search" id="promotion-batch-search" value="<?= htmlspecialchars($promotionQuery) ?>" placeholder="Ketik nama, NIS, atau NIS Diknas..." autocomplete="off"></div></div>
          </div>

          <div class="promotion-selection-toolbar">
            <div><strong id="promotion-visible-count"><?= number_format(count($promotionStudents)) ?> siswa ditampilkan</strong><span id="promotion-selected-count">0 siswa dipilih</span></div>
            <div><button class="btn btn-ghost" type="button" id="promotion-select-visible">Pilih Rombel Ditampilkan</button><button class="btn btn-ghost" type="button" id="promotion-select-all">Pilih Semua Rombel Tingkat Ini</button><button class="btn btn-ghost" type="button" id="promotion-clear-selection">Kosongkan Pilihan</button></div>
          </div>

          <div class="promotion-student-list" id="promotion-student-list" tabindex="0" aria-label="Daftar peserta tahun ajaran">
          <div class="class-roster-head" aria-hidden="true"><span></span><span>No</span><span>Nama Siswa / NIS / NIS Diknas</span><span>Rombel Asal</span><?php if($customPromotion || $currentPromotionLevel < $unitMaxLevel): ?><span>Rombel Tujuan</span><?php endif; ?></div>
            <?php foreach($promotionStudents as $index => $student):
              $studentNis = (string)$student['NO_INDUK'];
              $studentDiknas = trim((string)($student['NO_induk_diknas'] ?? ''));
              $defaultTargetId = !$customPromotion && $currentPromotionLevel < $unitMaxLevel ? ($promotionTargetByCode[strtoupper((string)($student['promotion_kode_rombel'] ?? ''))] ?? 0) : 0;
              $searchText = strtolower(trim($student['NAMA'] . ' ' . $studentNis . ' ' . $studentDiknas . ' ' . $student['kelas_label'] . ' ' . $student['kelas_aktif_label']));
            ?>
            <input type="hidden" name="source_placement_id[<?= htmlspecialchars($studentNis) ?>]" value="<?= (int)$student['source_placement_id'] ?>">
            <article class="promotion-student-row" data-promotion-student data-source-rombel="<?= htmlspecialchars($student['source_key']) ?>" data-search="<?= htmlspecialchars($searchText) ?>">
              <label class="promotion-student-check" for="promotion-student-<?= (int)$index ?>"><input type="checkbox" id="promotion-student-<?= (int)$index ?>" name="selected_students[]" value="<?= htmlspecialchars($studentNis) ?>"><span></span></label>
              <span class="class-roster-number"><?= $index+1 ?></span>
              <label class="promotion-student-identity" for="promotion-student-<?= (int)$index ?>"><span class="class-student-avatar" aria-hidden="true"><?php $initials='';foreach(array_slice(preg_split('/\s+/u',trim((string)$student['NAMA'])),0,2) as $word)$initials.=mb_substr($word,0,1,'UTF-8');echo htmlspecialchars(mb_strtoupper($initials,'UTF-8')); ?></span><span><strong><?= htmlspecialchars($student['NAMA']) ?></strong><small>NIS <?= htmlspecialchars($studentNis) ?><?= $studentDiknas !== '' ? ' · NIS Diknas ' . htmlspecialchars($studentDiknas) : '' ?><?= $student['kelas_aktif_label'] !== '' && $student['kelas_aktif_label'] !== $student['kelas_label'] ? ' · Rombel aktif ' . htmlspecialchars($student['kelas_aktif_label']) : '' ?></small></span></label>
              <span class="kelas-badge"><?= htmlspecialchars($student['kelas_label']) ?></span>
              <?php if($customPromotion || $currentPromotionLevel < $unitMaxLevel): ?>
              <div class="promotion-target-field"><label for="promotion-target-<?= (int)$index ?>">Rombel Tujuan</label><select class="field-input field-select" id="promotion-target-<?= (int)$index ?>" name="target_master_kelas_id[<?= htmlspecialchars($studentNis) ?>]" disabled><option value="">Pilih rombel</option><?php foreach($promotionTargets as $target): ?><option value="<?= (int)$target['id'] ?>" <?= $defaultTargetId === (int)$target['id'] ? 'selected' : '' ?>><?= htmlspecialchars($target['label']) ?></option><?php endforeach; ?></select></div>
              <?php endif; ?>
            </article>
            <?php endforeach; ?>
            <div class="promotion-empty-filter" id="promotion-empty-filter" hidden>Tidak ada siswa yang cocok dengan pencarian atau rombel ini.</div>
          </div>

          </section>
          <aside class="main-card class-destination-panel">
            <div class="class-panel-heading"><span class="class-workspace-icon"><?= master_workspace_icon('calendar') ?></span><div><h2><?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Kelulusan' : 'Tujuan dan Ringkasan' ?></h2><p><?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Siswa diproses pada tahun ajaran asal.' : 'Atur rombel tujuan pada setiap baris siswa.' ?></p></div></div>
          <div class="promotion-stage-overview">
            <div><span>Tingkat Asal</span><strong><?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Kelulusan Kelas '.$unitMaxLevel : 'Kenaikan Kelas ' . (int)$currentPromotionLevel ?></strong></div>
            <div><span>Siswa Tersisa</span><strong><?= number_format(count($promotionStudents)) ?> siswa</strong></div>
            <div><span><?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Tahun Ajaran Kelulusan' : 'Tahun Ajaran Tujuan' ?></span><strong><?= htmlspecialchars((!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? $sourceYear : $nextAcademicYear) ?></strong></div>
          </div>

            <?php if ($customPromotion || $currentPromotionLevel < $unitMaxLevel): ?>
            <h3 class="class-context-caption">Rombel Tujuan Tersedia</h3>
            <ul class="class-context-list class-target-list" aria-label="Rombel tujuan tersedia">
              <?php foreach ($promotionTargets as $target): ?><li><span class="class-rombel-icon" aria-hidden="true"><?= master_workspace_icon('layers') ?></span><div><strong><?= htmlspecialchars($target['label']) ?></strong><small>Kelas <?= (int)$target['tingkat'] ?></small></div></li><?php endforeach; ?>
              <?php if (!$promotionTargets): ?><li class="class-context-empty">Belum ada rombel aktif pada tingkat tujuan.</li><?php endif; ?>
            </ul>
            <?php endif; ?>
          <div class="promotion-submit-bar"><div><strong id="promotion-submit-summary">Belum ada siswa dipilih</strong><span><?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Siswa terpilih masuk Alumni'.promotion_alumni_year($sourceYear).'.' : 'Rombel tujuan dapat diatur berbeda untuk setiap siswa.' ?></span></div><button class="btn btn-primary" id="promotion-submit-button" type="submit" disabled><?= (!$customPromotion && $currentPromotionLevel === $unitMaxLevel) ? 'Luluskan' : 'Naikkan' ?> 0 Siswa</button></div>
          </aside>
        </form>
        <?php else: ?>
        <section class="main-card class-roster-panel class-roster-empty">
          <div class="class-panel-heading"><span class="class-workspace-icon"><?= master_workspace_icon('students') ?></span><div><h2>Peserta Tahun Ajaran</h2><p>Pilih siswa untuk kenaikan kelas atau kelulusan sesuai tingkat asal.</p></div></div>
          <div class="alert alert-info" style="margin:0">Pilih tahun dan tingkat asal untuk melihat peserta. Jika sudah dipilih, tidak ada peserta yang memenuhi syarat; siswa yang sudah diproses, PSB, Legacy, atau arsip tidak diproses kembali.</div>
        </section>
        <?php endif; ?>
      </div>
      <?php participants_held($promotionHeld,$participantsModel); ?>
    </div>
    </section>
    </div>
  </main>
</div>
<script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/workflow_dialogs.js?v=<?= filemtime(__DIR__.'/assets/js/workflow_dialogs.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script><script>document.addEventListener('DOMContentLoaded',function(){autoHideFlash();});</script>
<script src="assets/js/master_kelas.js?v=<?= filemtime(__DIR__ . '/assets/js/master_kelas.js') ?>"></script>
<script src="assets/js/promotion_participants.js?v=<?= filemtime(__DIR__.'/assets/js/promotion_participants.js') ?>"></script>
<script src="assets/js/special_classes.js?v=<?= filemtime(__DIR__.'/assets/js/special_classes.js') ?>"></script>
</body></html>
