<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/transaction_authorization.php';
require_once 'includes/authorization_presentation.php';
requireRole(['admin', 'bendahara', 'kasir']);

if (empty($_SESSION['csrf_transaction_authorization'])) {
    $_SESSION['csrf_transaction_authorization'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_transaction_authorization'];
$currentId = (int)$_SESSION['admin_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function authorization_redirect_flash(string $type, string $message, string $status = 'pending'): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
    header('Location: otorisasi_transaksi.php?status=' . urlencode($status));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'reject' && !unit_is_super()) {
        http_response_code(403); exit('Hanya Super Admin yang dapat memberi keputusan otorisasi.');
    }
    if (isRole('bendahara')) {
        authorization_redirect_flash('error', 'Bendahara hanya dapat memeriksa data otorisasi.');
    }
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        authorization_redirect_flash('error', 'Permintaan tidak valid atau sesi telah kedaluwarsa.');
    }
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $decisionNote = trim((string)($_POST['decision_note'] ?? ''));
    try {
        transaction_authorization_assert_ready($koneksi);
        $koneksi->begin_transaction();
        $request = transaction_authorization_find($koneksi, $requestId, true);
        if (!$request || $request['status'] !== 'pending') {
            throw new RuntimeException('Permintaan sudah diproses atau tidak ditemukan.');
        }
        if ($action === 'cancel') {
            if ((int)$request['requested_by'] !== $currentId) {
                throw new RuntimeException('Hanya pemohon yang dapat membatalkan permintaan ini.');
            }
            transaction_authorization_decide($koneksi, $requestId, 'cancelled', $currentId, 'Dibatalkan oleh pemohon.');
            $message = 'Permintaan berhasil dibatalkan.';
        } elseif ($action === 'reject') {
            if (!unit_is_super()) throw new RuntimeException('Hanya Super Admin yang dapat menolak pengajuan.');
            if ((int)$request['requested_by'] === $currentId) {
                throw new RuntimeException('Pemohon tidak boleh menolak permintaannya sendiri.');
            }
            transaction_authorization_decide($koneksi, $requestId, 'rejected', $currentId, $decisionNote);
            $message = 'Permintaan berhasil ditolak.';
        } else {
            throw new RuntimeException('Tindakan otorisasi tidak dikenali.');
        }
        $koneksi->commit();
        authorization_redirect_flash('success', $message);
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        authorization_redirect_flash('error', $error->getMessage());
    }
}

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'failed', 'all'];
$historyView = ($_GET['view'] ?? (isset($_GET['status']) && $_GET['status'] !== 'pending' ? 'history' : 'queue')) === 'history';
$statusChoices=filter_register('status',$_GET['status']??null,($historyView?array_fill_keys(array_diff($allowedStatuses,['all']),''):['pending'=>'Menunggu']),$historyView?'all':'pending','all');
$statusFilter=$historyView?filter_scalar($statusChoices,'all'):'pending';
$kindChoices=filter_register('kind',$_GET['kind']??null,['edit'=>'Edit','hapus'=>'Hapus'],'all','all');
$kind=filter_scalar($kindChoices,'all');
foreach($statusChoices as $choice)if($choice!=='*'&&!in_array($choice,$allowedStatuses,true))filter_choice_error('status');
foreach($kindChoices as $choice)if($choice!=='*'&&!in_array($choice,['edit','hapus'],true))filter_choice_error('jenis perubahan');
filter_output_start();
$page = max(1,(int)($_GET['page'] ?? 1));
$total = 0; $pages = 1;
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'pending';
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
$types = '';
if (isRole('kasir')) {
    $where[] = 'r.requested_by=?';
    $params[] = $currentId;
    $types .= 'i';
}
if ($statusFilter !== 'all') {
    $where[] = 'r.status=?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(r.transaction_reference LIKE ? OR r.no_induk_snapshot LIKE ? OR r.student_name_snapshot LIKE ? OR req.nama LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($kind !== 'all') { $where[]='r.action=?';$params[]=$kind;$types.='s'; }
$sql = "SELECT r.*,req.nama requested_by_name,req.role requested_by_role,req.username requested_by_username,reviewer.nama decided_by_name FROM transaksi_otorisasi r LEFT JOIN admin req ON req.id=r.requested_by LEFT JOIN admin reviewer ON reviewer.id=r.decided_by" . ($where ? ' WHERE '.implode(' AND ',$where) : '');

$requests = [];
$schemaError = null;
try {
    transaction_authorization_assert_ready($koneksi);
    if ($historyView) {
        $result=authorization_history_page($koneksi,$statusFilter,$kind,$search,$page,$statusChoices);
        $requests=$result['rows'];$total=$result['total'];$page=$result['page'];$pages=$result['pages'];
    } else {
        $from=' FROM transaksi_otorisasi r LEFT JOIN admin req ON req.id=r.requested_by';
        $stmt=$koneksi->prepare('SELECT COUNT(*)'.$from.($where?' WHERE '.implode(' AND ',$where):''));
        if($params)$stmt->bind_param($types,...$params);$stmt->execute();$total=(int)$stmt->get_result()->fetch_row()[0];$stmt->close();
        $pages=max(1,(int)ceil($total/25));$page=min($page,$pages);$offset=($page-1)*25;
        $stmt=$koneksi->prepare($sql.' ORDER BY r.requested_at DESC,r.id DESC LIMIT 25 OFFSET ?');
        $paged=array_merge($params,[$offset]);$bind=$types.'i';$stmt->bind_param($bind,...$paged);$stmt->execute();
        $requests=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    }
} catch (Throwable $error) {
    $schemaError = $error->getMessage();
}

function authorization_amount_from_payload(array $payload): float
{
    $keys = ['uang_psb','uang_spp','uang_komite','uang_du'];
    $total = 0.0;
    foreach ($keys as $key) {
        $raw = trim((string)($payload[$key] ?? 0));
        $total += is_numeric($raw) ? (float)$raw : (float)str_replace(['.', ','], ['', '.'], $raw);
    }
    foreach (($payload['biaya_lain_nominal'] ?? []) as $value) {
        $raw = trim((string)$value);
        $total += is_numeric($raw) ? (float)$raw : (float)str_replace(['.', ','], ['', '.'], $raw);
    }
    $discountRaw = trim((string)($payload['potongan_spp'] ?? 0));
    $discount = is_numeric($discountRaw) ? (float)$discountRaw : (float)str_replace(['.', ','], ['', '.'], $discountRaw);
    return max($total - $discount, 0);
}

function authorization_request_summary(array $request): array
{
    $before = json_decode((string)$request['before_snapshot'], true);
    $payload = json_decode((string)($request['proposed_payload'] ?? ''), true);
    $payment = is_array($before) ? ($before['payment'] ?? []) : [];
    $payload = is_array($payload) ? $payload : [];
    return [
        'before_total' => (float)($payment['total_jumlah'] ?? 0),
        'after_total' => $request['action'] === 'hapus' ? 0.0 : authorization_amount_from_payload($payload),
        'before_period' => trim((string)($payment['BULAN'] ?? '') . ' ' . (string)($payment['TAHUN'] ?? '')),
        'after_period' => trim((string)($payload['bulan_bayar'] ?? '') . ' ' . (string)($payload['tahun_bayar'] ?? '')),
        'method' => (string)($payload['sistem_pembayaran'] ?? $payment['sistem_pembayaran'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= 'Otorisasi Transaksi' ?> | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png?v=2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" />
  <link rel="stylesheet" href="assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/assets/css/date_controls.css') ?>" />
<link rel="stylesheet" href="assets/css/payment_details.css?v=<?= filemtime(__DIR__.'/assets/css/payment_details.css') ?>">
<link rel="stylesheet" href="assets/css/transaction_workflows.css?v=<?= filemtime(__DIR__.'/assets/css/transaction_workflows.css') ?>">
<link rel="stylesheet" href="assets/css/authorization_workspace.css?v=<?= filemtime(__DIR__.'/assets/css/authorization_workspace.css') ?>">
</head>
<body class="authorization-page">
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Buka menu" aria-label="Buka menu">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2><?= 'Otorisasi Transaksi' ?></h2><span class="breadcrumb">SistemSPP / Pembayaran / Otorisasi</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <div class="auth-page-content">
      <?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
      <?php if ($schemaError): ?><div class="alert alert-error"><?= htmlspecialchars($schemaError) ?></div><?php endif; ?>

      <section class="authorization-hero">
        <div class="auth-hero-copy"><span class="auth-hero-icon"><?= authorization_icon('history') ?></span><div><h1><?= $historyView?'Riwayat Perubahan Transaksi':'Antrean Otorisasi Transaksi' ?></h1><p><?= $historyView?'Lihat pengajuan, keputusan, dan perubahan langsung. Riwayat tetap tersedia setelah transaksi dihapus.':'Pantau pengajuan edit dan hapus. Hanya Super Admin yang dapat menyetujui atau menolak.' ?></p></div></div>
        <div class="authorization-hero-count"><?= authorization_icon('chart') ?><div><span><?= $historyView?'Total riwayat':'Total antrean' ?></span><strong><?= number_format($total) ?></strong><small><?= $historyView?'transaksi':'pengajuan' ?> sesuai filter</small></div></div>
      </section>

      <nav class="payment-history-tabs" aria-label="Tampilan otorisasi">
        <a href="otorisasi_transaksi.php?view=queue" <?= !$historyView?'aria-current="page"':'' ?>>Antrean</a>
        <a href="otorisasi_transaksi.php?view=history" <?= $historyView?'aria-current="page"':'' ?>>Riwayat</a>
      </nav>
      <section class="main-card authorization-filter-card">
        <form method="get" class="authorization-filter-form">
          <input type="hidden" name="view" value="<?= $historyView?'history':'queue' ?>">
          <label class="field-row"><span class="field-label">Jenis perubahan</span><select class="field-input field-select" name="kind" data-filter-multiple><?php foreach(['all'=>'Semua perubahan','edit'=>'Edit','hapus'=>'Hapus'] as $value=>$label): ?><option value="<?= $value ?>" <?= $kind===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
          <label class="field-row"><span class="field-label">Status</span><select class="field-input field-select" name="status" data-filter-multiple>
            <?php foreach (($historyView ? ['pending'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak','cancelled'=>'Dibatalkan','failed'=>'Gagal/Kedaluwarsa','all'=>'Semua Status'] : ['pending'=>'Menunggu']) as $value=>$label): ?>
              <option value="<?= $value ?>" <?= $statusFilter===$value?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select></label>
          <label class="field-row authorization-search-field"><span class="field-label">Cari transaksi atau siswa</span><input class="field-input" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Nomor transaksi, NIS, atau nama siswa" /></label>
          <button class="btn btn-primary" type="submit"><?= authorization_icon('search') ?> Tampilkan</button>
          <a class="btn btn-ghost authorization-reset-button" href="otorisasi_transaksi.php?view=<?= $historyView?'history':'queue' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v5h5"/></svg><span>Reset</span></a>
        </form>
      </section>

      <?php
      $items=[];
      foreach($requests as $row) {
          if($historyView) {
              $snapshot=json_decode($row['after_snapshot']??$row['before_snapshot']??'null',true);$identity=$snapshot['payment']??[];
              $items[]=['id'=>(int)$row['payment_id'],'unit_id'=>(int)$row['unit_id'],'reference'=>'TRX-'.str_pad((string)$row['payment_id'],6,'0',STR_PAD_LEFT),
                  'student'=>$identity['NAMA']??'Tidak tercatat','nis'=>$identity['NO_INDUK']??'Tidak tercatat','action'=>$row['action'],
                  'label'=>payment_activity_labels()[$row['action']]??'Tidak tercatat','name'=>$row['actor_name']?:'Tidak tercatat',
                  'username'=>$row['actor_username'],'role'=>$row['actor_role'],'time'=>$row['occurred_at'],'authorization_id'=>$row['authorization_id']];
          } else $items[]=['id'=>(int)$row['id'],'unit_id'=>(int)$row['unit_id'],'reference'=>$row['transaction_reference'],
              'student'=>$row['student_name_snapshot'],'nis'=>$row['no_induk_snapshot'],'action'=>$row['action']==='hapus'?'request_delete':'request_edit',
              'label'=>'Pengajuan '.($row['action']==='hapus'?'hapus':'edit'),'name'=>$row['requested_by_name']??'Tidak tercatat',
              'username'=>$row['requested_by_username']??'','role'=>$row['requested_by_role']??'','time'=>$row['requested_at'],'authorization_id'=>$row['id']];
      }
      $selected=null;$requestedSelection=(int)($_GET['selected']??0);$requestedUnit=(int)($_GET['selected_unit']??0);
      foreach($items as $i=>$item) if($item['id']===$requestedSelection && (!$requestedUnit || $requestedUnit===$item['unit_id'])) $selected=$i;
      if($selected===null && $items)$selected=0;
      $detail=null;$detailError=null;$originalUnit=(int)$GLOBALS['app_unit_id'];
      if($selected!==null)try{
          authorization_read_unit($koneksi,(string)$items[$selected]['unit_id']);
          $detail=authorization_detail_model($koneksi,$items[$selected]['id'],!$historyView);
      }catch(Throwable $e){$detailError='Detail belum dapat dimuat. Muat ulang halaman untuk mencoba kembali.';error_log($e->getMessage());}
      finally{unit_set_context($koneksi,$originalUnit);}
      ?>
      <div class="auth-workspace" data-auth-workspace data-export-account="<?= $currentId ?>" data-export-scope="<?= unit_active_id() ?>" data-export-filter="<?= authorization_escape(json_encode(['kind'=>$kindChoices,'status'=>$statusChoices,'q'=>$search])) ?>" data-view="<?= $historyView?'history':'queue' ?>">
        <section class="auth-list-panel main-card">
          <header class="auth-panel-heading"><h3><?= authorization_icon('history') ?> <?= $historyView?'Daftar Riwayat Perubahan Transaksi':'Daftar Pengajuan Otorisasi' ?></h3><span class="auth-count"><?= number_format($total) ?> <?= $historyView?'transaksi':'pengajuan' ?></span></header>
          <?php if($historyView): ?><div class="auth-list-tools"><small>Filter mencocokkan aktivitas dalam riwayat; kartu menampilkan perubahan terakhir.</small></div>
          <?php if(unit_is_super()): ?>
          <form class="auth-export-tools" action="otorisasi_export_pdf.php" method="post" data-export-form>
            <input type="hidden" name="csrf_token" value="<?= authorization_escape($csrfToken) ?>">
            <input type="hidden" name="transactions" value="[]">
            <input type="hidden" name="q" value="<?= authorization_escape($search) ?>">
            <?php foreach(['kind'=>$kindChoices,'status'=>$statusChoices] as $name=>$choices): foreach($choices as $choice): ?><input type="hidden" name="<?= $name ?>[]" value="<?= authorization_escape($choice) ?>"><?php endforeach; endforeach; ?>
            <div class="auth-export-selection"><label><input type="checkbox" data-export-all-page <?= !$items?'disabled':'' ?>> Pilih semua di halaman ini</label><span data-export-count role="status">0 transaksi dipilih</span></div>
            <div class="auth-export-actions"><button class="btn btn-ghost btn-sm" type="button" data-export-clear disabled>Bersihkan pilihan</button><button class="btn btn-primary btn-sm" type="submit" data-export-selected disabled><?= authorization_icon('pdf') ?> Cetak Terpilih</button><a class="btn btn-ghost btn-sm" href="otorisasi_export_pdf.php?<?= authorization_escape(filter_build_query(['kind'=>$kind,'status'=>$statusFilter,'q'=>$search])) ?>"><?= authorization_icon('pdf') ?> Cetak Semua Hasil Filter</a></div>
            <noscript><small>Aktifkan JavaScript untuk menyimpan pilihan cetak lintas halaman. Cetak Semua Hasil Filter tetap tersedia.</small></noscript>
          </form>
          <?php endif; endif; ?>
          <div class="auth-record-list <?= $historyView && unit_is_super()?'has-export-choices':'' ?>">
          <?php if(!$items): ?><div class="auth-empty"><strong><?= $historyView?'Belum ada riwayat perubahan':'Belum ada pengajuan menunggu' ?></strong><p>Tidak ada data yang cocok dengan filter saat ini.</p></div><?php endif; ?>
          <?php foreach($items as $index=>$item): [$status,$tone,$icon]=authorization_badge($item['action']);
              $query=['view'=>$historyView?'history':'queue','kind'=>$kind,'status'=>$statusFilter,'q'=>$search,'page'=>$page,'selected'=>$item['id'],'selected_unit'=>$item['unit_id']]; ?>
              <?php if($historyView && unit_is_super()): ?><div class="auth-record-row"><label class="auth-export-check"><input type="checkbox" data-export-choice data-id="<?= $item['id'] ?>" data-unit="<?= $item['unit_id'] ?>" aria-label="Pilih <?= authorization_escape($item['reference'].' '.$item['student']) ?> untuk PDF"></label><?php endif; ?>
              <a class="auth-record <?= $selected===$index?'is-selected':'' ?>" href="?<?= authorization_escape(filter_build_query($query)) ?>" data-auth-record data-index="<?= $index ?>" data-id="<?= $item['id'] ?>" data-unit="<?= $item['unit_id'] ?>" <?= $selected===$index?'aria-current="true"':'' ?>>
                <span class="auth-action-icon auth-tone-<?= $tone ?>"><?= authorization_icon($icon) ?></span>
                <div class="auth-record-identity"><strong><?= authorization_escape($item['reference']) ?></strong><span><?= authorization_escape($item['student']) ?></span><small>NIS <?= authorization_escape($item['nis']) ?><?= unit_active_id()===0?' ? '.authorization_escape(unit_label($item['unit_id'])):'' ?></small></div>
                <div class="auth-record-action"><strong><?= authorization_escape($item['label']) ?></strong><small><?= $item['authorization_id']?'Pengajuan #'.(int)$item['authorization_id']:'Perubahan langsung' ?></small></div>
                <span class="auth-status auth-tone-<?= $tone ?>"><?= authorization_escape($status) ?></span>
                <div class="auth-record-actor"><?= authorization_icon('user') ?><div><strong><?= authorization_escape($item['name']) ?></strong><small><?= authorization_escape($item['username']?'@'.$item['username']:'Tidak tercatat') ?></small><small><?= authorization_escape(authorization_role_label($item['role'])) ?></small></div></div>
                <div class="auth-record-time"><?= authorization_icon('calendar') ?><div><strong><?= authorization_escape(spp_date_label($item['time'])) ?></strong><small><?= authorization_escape(date('H:i:s',strtotime($item['time']))) ?> WIB</small></div></div>
              </a><?php if($historyView && unit_is_super()): ?></div><?php endif; ?>
          <?php endforeach; ?></div>
        </section>
        <section class="auth-detail-panel main-card" aria-label="Detail transaksi terpilih">
          <header class="auth-panel-heading"><h3><?= authorization_icon('history') ?> <?= $historyView?'Detail Riwayat Aktivitas':'Detail Pengajuan' ?></h3><div class="auth-detail-nav"><button class="btn btn-ghost btn-sm" type="button" data-auth-prev aria-label="Transaksi sebelumnya" disabled><?= authorization_icon('left') ?></button><button class="btn btn-ghost btn-sm" type="button" data-auth-next aria-label="Transaksi berikutnya" disabled><?= authorization_icon('right') ?></button></div></header>
          <div class="auth-detail-body" data-auth-detail aria-live="polite"><?php if($detail): authorization_render_detail($detail);else: ?><div class="auth-empty"><?= authorization_escape($detailError??'Pilih transaksi pada daftar untuk melihat detail.') ?></div><?php endif; ?></div>
        </section>
      </div>
      <div class="workflow-pages"><span><?= number_format($total) ?> <?= $historyView?'transaksi':'permintaan' ?> &middot; Halaman <?= $page ?> dari <?= $pages ?></span><div>
      <?php foreach(['Sebelumnya'=>$page-1,'Berikutnya'=>$page+1] as $label=>$target): if($target>=1&&$target<=$pages): ?><a class="btn btn-ghost btn-sm" href="?<?= htmlspecialchars(filter_build_query(['view'=>$historyView?'history':'queue','status'=>$statusFilter,'kind'=>$kind,'q'=>$search,'page'=>$target])) ?>"><?= $label ?></a><?php endif; endforeach; ?></div></div>
      </div>
    </main>
  </div>
  <dialog class="payment-activity-dialog" id="payment-activity-dialog" data-endpoint="otorisasi_aktivitas.php" aria-labelledby="payment-activity-title">
    <header class="payment-activity-dialog-header"><div><h3 id="payment-activity-title">Riwayat Aktivitas</h3><p data-activity-subtitle></p></div><button type="button" class="btn btn-ghost btn-sm" data-close-activity>Tutup</button></header><div class="payment-activity-content" aria-live="polite"></div>
  </dialog>
  <script src="assets/js/authorization_workspace.js?v=<?= filemtime(__DIR__.'/assets/js/authorization_workspace.js') ?>"></script>
  <script src="assets/js/payment_activity.js?v=<?= filemtime(__DIR__.'/assets/js/payment_activity.js') ?>"></script>
  <script src="assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/assets/js/date_format.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
