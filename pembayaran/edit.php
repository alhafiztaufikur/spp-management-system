<?php
// ============================================
// pembayaran/edit.php
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/biaya_lain.php';
require_once '../includes/tagihan_tahunan.php';
require_once '../includes/tagihan_sekali.php';
require_once '../includes/spp_billing.php';
require_once '../includes/komite_billing.php';
require_once '../includes/transaction_authorization.php';
require_once '../includes/payment_return.php';
requireRole(['admin', 'kasir']);
if (empty($_SESSION['csrf_payment'])) $_SESSION['csrf_payment'] = bin2hex(random_bytes(32));

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$id = (int)($_GET['id'] ?? 0);
$returnContext = is_string($_GET['return_context'] ?? null) ? $_GET['return_context'] : '';
$returnUrl = payment_return_url($returnContext, $id);
$returnEntry = payment_return_entry($returnContext, $id);
if (!empty($returnEntry['draft'])) {
    $restoreDraft = $returnEntry['draft'];
    ob_start(static fn($html) => payment_edit_restore_markup($html, $restoreDraft));
}
if ($id < -1000000000) { require __DIR__.'/../includes/legacy_payment_form.php'; exit; }
if ($id <= 0) { header('Location: ' . $returnUrl); exit; }

$stmt = $koneksi->prepare("SELECT p.*, s.NO_INDUK, s.NAMA, s.KELAS, s.SPP_PERBULAN FROM bayar p JOIN siswa s ON s.NO_INDUK = p.NO_INDUK AND s.unit_id=p.unit_id WHERE p.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$d) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Data tidak ditemukan!']; header('Location: ' . $returnUrl); exit; }
try { payment_assert_owner($koneksi,$id,(int)$_SESSION['admin_id']); }
catch (Throwable $error) { http_response_code(403); exit(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8')); }
if ((int)($d['payment_link_version'] ?? 0) !== 1) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Pembayaran legacy tidak dapat diedit. Rekonsiliasi manual diperlukan terlebih dahulu.'];
    header('Location: ' . $returnUrl);
    exit;
}
if (transaction_authorization_pending_for_payments($koneksi, [$id])) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Transaksi ini masih menunggu keputusan otorisasi.'];
    header('Location: ' . $returnUrl);
    exit;
}
// Old direct edit links also need a server-bound context for safe draft recovery.
if (!$returnEntry) {
    $returnContext = payment_return_create($id, unit_active_id(), [], 'lihat.php');
    $returnUrl = payment_return_url($returnContext, $id);
}
if ($periodMeta = payment_period_meta($koneksi,$id)) {
    require __DIR__.'/../includes/payment_period_edit.php';
    exit;
}

// Ambil Daftar Ulang yang secara eksplisit milik pembayaran ini.
$stmt_du = $koneksi->prepare("SELECT tagihan_daftar_ulang_id, jumlah, kelas, th_ajaran FROM bayar_du WHERE bayar_id = ? LIMIT 1");
$stmt_du->bind_param('i', $id);
$stmt_du->execute();
$res_du = $stmt_du->get_result()->fetch_assoc();
$d['uang_du'] = $res_du ? (float)$res_du['jumlah'] : 0.0;
if ($res_du) {
    $d['kelas_du'] = $res_du['kelas'];
    $d['th_ajaran'] = $res_du['th_ajaran'];
}
$stmt_du->close();

$d['kewajiban_spp'] = 0.0;
$currentSppAllocation = spp_billing_schema_ready($koneksi) ? spp_payment_allocation_summary($koneksi, $id) : null;
$d['uang_spp_baru'] = $currentSppAllocation ? (float)$currentSppAllocation['uang_baru'] : (float)$d['U_SPP'];

$siswa_sql = "
    SELECT
        s.*,
        COALESCE(p.paid_psb, 0) AS paid_psb,
        COALESCE(du.paid_du, 0) AS paid_du,
        mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder, mk.legacy_kind,
        (SELECT ta_l.label FROM siswa_tahun_ajaran sta_l
         JOIN tahun_ajaran ta_l ON ta_l.id=sta_l.tahun_ajaran_id
         WHERE sta_l.no_induk=s.NO_INDUK AND sta_l.unit_id=s.unit_id AND sta_l.status='lulus'
         ORDER BY ta_l.label DESC LIMIT 1) AS graduation_year
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id
    LEFT JOIN (
        SELECT unit_id,NO_INDUK, SUM(U_PSB) AS paid_psb
        FROM bayar
        WHERE id <> ?
        GROUP BY unit_id,NO_INDUK
    ) p ON p.NO_INDUK = s.NO_INDUK AND p.unit_id=s.unit_id
    LEFT JOIN (
        SELECT unit_id,no_induk, SUM(jumlah) AS paid_du
        FROM bayar_du
        WHERE bayar_id IS NULL OR bayar_id <> ?
        GROUP BY unit_id,no_induk
    ) du ON du.no_induk = s.NO_INDUK AND du.unit_id=s.unit_id
    WHERE s.NO_INDUK = ? AND s.unit_id = ?
";
$stmt_siswa = $koneksi->prepare($siswa_sql);
$stmt_siswa->bind_param(
    'iisi',
    $id, $id, $d['NO_INDUK'], $d['unit_id']
);
$stmt_siswa->execute();
$siswa_list = $stmt_siswa->get_result();

$period_payments = [];
foreach (payment_period_paid_components($koneksi,$id,$d['NO_INDUK']) as $paid) {
    $bulan_key = month_code($paid['BULAN']);
    $periodKey = $bulan_key . '-' . $paid['TAHUN'];
    $period_payments[$paid['NO_INDUK']]['spp'][$periodKey] =
        ($period_payments[$paid['NO_INDUK']]['spp'][$periodKey] ?? 0) + (float)$paid['spp'];
    $period_payments[$paid['NO_INDUK']]['komite'][$periodKey] =
        ($period_payments[$paid['NO_INDUK']]['komite'][$periodKey] ?? 0) + (float)$paid['komite'];
}

$spp_placements = [];
$placements = hp_rows($koneksi,"SELECT sta.no_induk, ta.label AS tahun_ajaran, sta.spp_perbulan_snapshot,sta.spp_covered_by_psb
    FROM siswa_tahun_ajaran sta
    JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
    WHERE sta.status = 'aktif' AND sta.no_induk = ? AND sta.unit_id = ?
    ORDER BY ta.label", [$d['NO_INDUK'], $d['unit_id']]);
foreach ($placements as $placement) {
    $spp_placements[$placement['no_induk']][] = [
        'tahun_ajaran' => (string)$placement['tahun_ajaran'],
        'tarif' => (float)$placement['spp_perbulan_snapshot'],
        'covered_by_psb' => (int)$placement['spp_covered_by_psb'],
    ];
}
$published_spp_payload = spp_billing_schema_ready($koneksi) ? spp_payment_payload($koneksi, $id, $d['NO_INDUK']) : [];
$komite_payload = komite_payment_payload($koneksi,$id,$d['NO_INDUK']);

$currentPeriodKey = month_code($d['BULAN']) . '-' . $d['TAHUN'];
$d['kewajiban_spp'] = max(0, (float)$d['SPP_PERBULAN'] - (float)($period_payments[$d['NO_INDUK']]['spp'][$currentPeriodKey] ?? 0));

$linkedDuBillId = (int)($res_du['tagihan_daftar_ulang_id'] ?? 0);
$du_bills = du_selectable_bills_payload($koneksi, $id, $linkedDuBillId, $d['NO_INDUK']);

function active_academic_year_from_payment_period($bulan, $tahun): string {
    $month = (int)month_code($bulan);
    $year = (int)$tahun;
    if ($month < 1 || $month > 12 || $year < 2000) {
        return du_current_academic_year();
    }
    return du_academic_year_label($month, $year);
}

$selectedAcademicYear = $res_du && trim((string)$res_du['th_ajaran']) !== ''
    ? (string)$res_du['th_ajaran']
    : active_academic_year_from_payment_period($d['BULAN'], $d['TAHUN']);
$annual_fee_payload = annual_fee_payload_for_options($koneksi, $id, $d['NO_INDUK']);
$oneTimeAvailability = one_time_fee_status($koneksi, (string)$d['NO_INDUK'], $id);
$initialFeeLocks = [];
foreach (['psb'] as $feeKey) {
    $fee = $oneTimeAvailability[$feeKey];
    $initialFeeLocks[$feeKey] = $fee['total'] <= .001 || $fee['remaining'] <= .001;
}
$initialFeeLocks['spp'] = true; // Dibuka setelah status tagihan periode ini diperiksa.
$initialFeeLocks['komite'] = true;
$initialFeeLocks['du'] = true;
$initialInputZero = ['psb' => $initialFeeLocks['psb']];
$initialLockReasons = [];
$sppBill = null;
foreach ($published_spp_payload[$d['NO_INDUK']]['tagihan'] ?? [] as $bill) {
    if ((string)$bill['bulan'] . '-' . (string)$bill['tahun'] === $currentPeriodKey) {
        $sppBill = $bill;
        break;
    }
}
$initialInputZero['spp'] = (!$sppBill || (float)$sppBill['remaining'] <= .001);
if ($initialInputZero['spp']) $initialLockReasons['spp'] = 'Tidak ada tagihan SPP terbuka pada bulan ini.';
$komiteBill = $komite_payload[$d['NO_INDUK']][$currentPeriodKey] ?? null;
$initialInputZero['komite'] = !$komiteBill || (float)$komiteBill['total'] <= .001
    || (float)$komiteBill['paid'] + .001 >= (float)$komiteBill['total'];
if ($initialInputZero['komite']) $initialLockReasons['komite'] = $komiteBill ? 'Tagihan Komite sudah lunas.' : 'Belum ada tagihan Komite bulan ini.';
$linkedDuBill = null;
foreach ($du_bills[$d['NO_INDUK']] ?? [] as $bill) {
    if ((int)$bill['id'] === $linkedDuBillId) {
        $linkedDuBill = $bill;
        break;
    }
}
$initialInputZero['du'] = !$linkedDuBill || (float)$linkedDuBill['total'] <= .001
    || (float)$linkedDuBill['sisa'] <= .001;
if ($initialInputZero['du']) $initialLockReasons['du'] = $linkedDuBill ? 'Tagihan Daftar Ulang sudah lunas.' : 'Belum ada tagihan Daftar Ulang yang dipilih.';

$biaya_lain_bills = [];
$stmtBills = $koneksi->prepare("SELECT t.id,t.no_induk,t.master_biaya_lain_id,t.nama_snapshot nama,
    t.nominal_tagihan nominal,t.status,
    COALESCE(SUM(CASE WHEN d.bayar_id<>? THEN d.nominal_snapshot ELSE 0 END),0) paid
    FROM tagihan_biaya_lain t
    LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id
    WHERE t.no_induk=? AND t.unit_id=? AND
        (t.status='open' OR EXISTS(SELECT 1 FROM bayar_biaya_lain own WHERE own.bayar_id=? AND own.tagihan_biaya_lain_id=t.id))
    GROUP BY t.id ORDER BY t.nama_snapshot");
$stmtBills->bind_param('isii',$id,$d['NO_INDUK'],$d['unit_id'],$id); $stmtBills->execute();
foreach($stmtBills->get_result()->fetch_all(MYSQLI_ASSOC) as $bill){
    $bill['paid']=ofb_paid($koneksi,(int)$bill['id'],$id);
    $bill['id']=(int)$bill['id']; $bill['master_id']=(int)$bill['master_biaya_lain_id'];
    $bill['nominal']=(float)$bill['nominal']; $bill['paid']=(float)$bill['paid'];
    $bill['sisa']=max(0,$bill['nominal']-$bill['paid']);
    $biaya_lain_bills[$bill['no_induk']][]=$bill;
}
$stmtBills->close();

$stmt_biaya_lain = $koneksi->prepare("
    SELECT d.*
    FROM bayar_biaya_lain d
    WHERE d.bayar_id = ?
    ORDER BY d.urutan ASC, d.id ASC
");
$stmt_biaya_lain->bind_param('i', $id);
$stmt_biaya_lain->execute();
$biaya_lain_details = $stmt_biaya_lain->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($biaya_lain_details as &$feeDetail) {
    $feeBill = !empty($feeDetail['tagihan_biaya_lain_id'])
        ? hp_one($koneksi,'SELECT origin,provenance FROM tagihan_biaya_lain WHERE id=?',[$feeDetail['tagihan_biaya_lain_id']]) : null;
    $feeDetail['_purchase'] = ($feeBill['origin'] ?? '') === 'purchase';
    $feeDetail['_fee_mode'] = other_fee_purchase_mode($feeBill);
    $feeDetail['_original_choice'] = ($feeDetail['_purchase'] || empty($feeDetail['tagihan_biaya_lain_id'])) && !empty($feeDetail['master_biaya_lain_id'])
        ? 'master:'.$feeDetail['master_biaya_lain_id'] : '';
    $feeDetail['_original_amount'] = $feeDetail['nominal_snapshot'];
}
unset($feeDetail);
$stmt_biaya_lain->close();
if (isset($restoreDraft['biaya_lain_tagihan_id']) && is_array($restoreDraft['biaya_lain_tagihan_id'])) {
    $originalDetails = array_column($biaya_lain_details, null, 'id');
    $biaya_lain_details = [];
    $catalogModes = array_column(other_fee_optional_choices($koneksi), 'jenis_pembayaran', 'id');
    foreach (array_slice($restoreDraft['biaya_lain_tagihan_id'], 0, 100) as $index => $billId) {
        $detailId = (int)($restoreDraft['biaya_lain_detail_id'][$index] ?? 0);
        $original = $originalDetails[$detailId] ?? [];
        $nominal = $restoreDraft['biaya_lain_nominal'][$index] ?? 0;
        $choice = is_scalar($billId) ? (string)$billId : '';
        $sameChoice = $choice === ($original['_original_choice'] ?? '');
        $feeMode = $sameChoice ? ($original['_fee_mode'] ?? 'berulang') : ($catalogModes[$choice] ?? 'berulang');
        $biaya_lain_details[] = ['id'=>$original['id'] ?? '', 'choice'=>$choice,'master_biaya_lain_id'=>$original['master_biaya_lain_id']??null,'tagihan_biaya_lain_id'=>(int)$choice,
            '_fee_mode'=>$feeMode, '_original_choice'=>$original['_original_choice']??'', '_original_amount'=>$original['_original_amount']??0,
            'tarif_referensi_snapshot'=>$sameChoice?($original['tarif_referensi_snapshot']??null):null,
            '_draft_amount'=>$feeMode==='input_manual'&&is_scalar($nominal)?(string)$nominal:null,
            'nama_biaya_snapshot'=>$original['nama_biaya_snapshot'] ?? '',
            'nominal_snapshot'=>is_scalar($nominal)?(float)str_replace(['.',','],['','.'],(string)$nominal):0,
        ];
    }
}
if (!$biaya_lain_details) {
    $biaya_lain_details[] = [
        'id' => '', 'master_biaya_lain_id' => '', 'tagihan_biaya_lain_id' => '', 'nama_biaya_snapshot' => '',
        'nominal_snapshot' => 0, 'keterangan' => ''
    ];
}

function money_attr($value) {
    return htmlspecialchars((string)(float)$value, ENT_QUOTES, 'UTF-8');
}

function total_after_discount($total, $discount, $fallbackTotal = 0) {
    // $fallbackTotal dipertahankan agar kontrak pemanggil lama tidak putus,
    return max(0, (float)$total - (float)$discount);
}

function month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

$selectedPaymentMethod = $d['sistem_pembayaran'] ?? 'VA';
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <meta name="description" content="Edit data transaksi pembayaran siswa." />
<link rel="stylesheet" href="../assets/css/style.css?v=duselector8&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" />
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=duselector8&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>" />
  <link rel="stylesheet" href="../assets/css/payment_details.css?v=<?= filemtime(__DIR__ . "/../assets/css/payment_details.css") ?>">
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="../assets/css/rombel_payment_refinement.css?v=<?= filemtime(__DIR__.'/../assets/css/rombel_payment_refinement.css') ?>">
<link rel="stylesheet" href="../assets/css/payment_workspace_refinement.css?v=<?= filemtime(__DIR__.'/../assets/css/payment_workspace_refinement.css') ?>">
<link rel="stylesheet" href="../assets/css/payment_edit.css?v=<?= filemtime(__DIR__.'/../assets/css/payment_edit.css') ?>">
</head>
<body class="ui-time-polish">

  <div class="bg-orbs">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Edit Pembayaran</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Edit</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash && ($flash['scope'] ?? '') !== 'spp'): ?>
      <div class="alert alert-<?= htmlspecialchars((string)($flash['type'] ?? 'error')) ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= htmlspecialchars((string)($flash['msg'] ?? 'Terjadi kesalahan.')) ?>
      </div>
      <?php endif; ?>

      <div class="main-card payment-edit">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Data Pembayaran
          </div>
          <span class="badge-id">TRX-<?= str_pad((string)$id,6,'0',STR_PAD_LEFT) ?></span>
        </div>

        <form method="POST" action="../pembayaran/proses.php" id="form-bayar">
          <input type="hidden" name="_operational_unit" value="<?= unit_active_id() ?>" />
          <input type="hidden" name="aksi" value="update" />
          <input type="hidden" name="return_context" value="<?= htmlspecialchars($returnContext, ENT_QUOTES, 'UTF-8') ?>" />
          <input type="hidden" name="id" value="<?= $d['id'] ?>" />
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_payment']) ?>" />
          <input type="hidden" name="activity_request_key" value="<?= bin2hex(random_bytes(16)) ?>" />

          <div class="payment-edit-settings">
            <section class="payment-edit-panel" aria-labelledby="edit-payment-settings">
              <h3 id="edit-payment-settings">Pengaturan Transaksi</h3>
              <div class="payment-edit-fields">
                <div class="field-row">
                  <label class="field-label" for="tgl-bayar">Tanggal Bayar</label>
                  <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar"
                    value="<?= date('Y-m-d', strtotime($d['TGL_BYR'])) ?>" required />
                </div>
                <div class="field-row payment-edit-month">
                  <div>
                    <label class="field-label" for="bulan-bayar">Bulan Tagihan SPP &amp; Komite</label>
                    <select class="field-input field-select month-code-select" name="bulan_bayar" id="bulan-bayar" required>
                      <?php
                      $month_labels = [
                          '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                          '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                          '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
                      ];
                      $selectedMonth = month_code($d['BULAN']);
                      foreach ($month_labels as $code => $label):
                      ?>
                      <option value="<?= $code ?>" data-label="<?= $label ?>" <?= $selectedMonth === (string)$code ? 'selected' : '' ?>><?= $label ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php
                    $selectedYear = (int)$d['TAHUN'];
                    $yearStart = min((int)date('Y') - 6, $selectedYear);
                    $yearEnd = max((int)date('Y') + 10, $selectedYear);
                  ?>
                  <div class="payment-year-picker">
                    <label class="payment-year-caption" for="tahun-bayar">Tahun Tagihan</label>
                    <input class="field-input payment-year-select" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4"
                      name="tahun_bayar" id="tahun-bayar" value="<?= htmlspecialchars((string)$d['TAHUN']) ?>" autocomplete="off" required />
                    <div class="payment-year-options" role="listbox" aria-label="Tahun Tagihan">
                      <?php for ($y = $yearStart; $y <= $yearEnd; $y++): ?>
                      <button type="button" class="payment-year-option" data-year="<?= $y ?>" role="option"><?= $y ?></button>
                      <?php endfor; ?>
                    </div>
                  </div>
                </div>
                <div class="field-row">
                  <label class="field-label" for="sistem-pembayaran">Sistem Pembayaran</label>
                  <select class="field-input field-select" id="sistem-pembayaran" name="sistem_pembayaran" required>
                    <?php foreach (['Tunai', 'VA', 'Qris'] as $method): ?>
                    <option value="<?= $method ?>" <?= $selectedPaymentMethod === $method ? 'selected' : '' ?>><?= $method ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </section>
            <div class="payment-edit-total">
              <span>Total Bayar</span>
              <strong id="totalJumlah" aria-live="polite"><?= transaction_authorization_money($d['total_jumlah']) ?></strong>
              <input type="hidden" name="total_jumlah" id="hidden-total" value="<?= $d['total_jumlah'] ?>" />
            </div>
          </div>

          <section class="payment-edit-panel payment-edit-student" aria-labelledby="edit-payment-student">
            <h3 id="edit-payment-student">Data Siswa</h3>
            <div class="payment-edit-student-fields">
              <label class="field-row"><span class="field-label">Nama Siswa</span>
                <input class="field-input" type="text" id="disp-nama" value="<?= htmlspecialchars($d['NAMA']) ?>" readonly />
              </label>
              <label class="field-row"><span class="field-label">NIS</span>
                <input class="field-input" type="text" id="disp-nis" name="no_induk" value="<?= htmlspecialchars($d['NO_INDUK']) ?>" readonly />
              </label>
              <label class="field-row"><span class="field-label">Kelas</span>
                <input class="field-input" type="text" id="disp-kelas" value="<?= htmlspecialchars($d['kelas_rombel_snapshot'] ?: ('Kelas '.$d['KELAS'].' (Belum Ditentukan)')) ?>" readonly />
              </label>
            </div>
            <datalist id="siswa-list" hidden>
                <?php while ($s = $siswa_list->fetch_assoc()):
                    $classLabel = $s['graduation_year'] ? 'LULUS · TA '.$s['graduation_year']
                        : class_label(['tingkat'=>$s['master_tingkat']?:$s['KELAS'],'kode_rombel'=>$s['kode_rombel']??'BELUM',
                            'is_placeholder'=>$s['is_placeholder']??1,'legacy_kind'=>$s['legacy_kind']]);
                ?>
                <option value="<?= htmlspecialchars($s['NAMA'].' · NIS '.$s['NO_INDUK']) ?>"
                  data-nis="<?= htmlspecialchars($s['NO_INDUK']) ?>" data-student-id="<?= (int)$s['id'] ?>"
                  data-diknas="<?= htmlspecialchars((string)($s['NO_induk_diknas'] ?? '')) ?>"
                  data-nama="<?= htmlspecialchars($s['NAMA']) ?>"
                  data-kelas="<?= htmlspecialchars($classLabel) ?>"
                  data-is-graduate="<?= $s['graduation_year'] || $s['legacy_kind']==='alumni' ? '1' : '0' ?>"
                  data-graduation-year="<?= htmlspecialchars((string)$s['graduation_year']) ?>"
                  data-total-psb="<?= money_attr(max(0,(float)$s['PSB']-(float)$s['potongan_psb_nominal'])) ?>"
                  data-total-spp="<?= money_attr($s['SPP_PERBULAN']) ?>"
                  data-total-komite="<?= money_attr($s['POMG']) ?>"
                  data-paid-psb="<?= money_attr($s['paid_psb']) ?>"
                  data-paid-spp-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['spp'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-spp-placements="<?= htmlspecialchars(json_encode($spp_placements[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-spp-billing="<?= htmlspecialchars(json_encode($published_spp_payload[$s['NO_INDUK']] ?? ['saldo'=>0,'tagihan'=>[]], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-komite-billing="<?= htmlspecialchars(json_encode($komite_payload[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-komite-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['komite'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-annual-fees="<?= htmlspecialchars(json_encode($annual_fee_payload[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-du-bills="<?= htmlspecialchars(json_encode($du_bills[$s['NO_INDUK']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-du-covered-psb="<?= du_psb_state($koneksi,$s['NO_INDUK'],du_current_academic_year())['covered_psb']?'1':'0' ?>"
                  data-biaya-lain-bills="<?= htmlspecialchars(json_encode($biaya_lain_bills[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($s['NAMA']) ?> (<?= htmlspecialchars($classLabel) ?>)
                </option>
                <?php endwhile; ?>
            </datalist>
          </section>

          <!-- Rincian Pembayaran -->
          <div class="section-divider"><span>Rincian Pembayaran</span></div>
          <div class="alert alert-warning payment-overpaid-alert" id="payment-overpaid-alert" hidden></div>
          <div class="alert alert-warning payment-input-overlimit-alert" id="payment-input-overlimit-alert" hidden></div>
          <div class="table-container">
            <table class="payment-table form-payment-table">
              <thead>
                <tr>
                  <th>Komponen Bayar</th>
                  <th>Total Tagihan (Rp)</th>
                  <th>Sudah Terbayar (Rp)</th>
                  <th>Sisa (Rp)</th>
                  <th>Input Bayar (Rp)</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $komp = [
                  ['psb', '🎒 Uang PSB', 'U_PSB', 'uang_psb'],
                  ['spp', '🎓 Uang SPP Diterima', 'uang_spp_baru', 'uang_spp'],
                  ['komite', '🏫 Uang Komite', 'U_KOMITE', 'uang_komite'],
                  ['du', '📚 Daftar Ulang', 'uang_du', 'uang_du']
                ];
                foreach ($komp as $i => [$key,$label,$col,$inputName]):
                ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td><?php if($key==='du'): ?><div class="du-bill-selector"><button type="button" class="du-selector-trigger" id="du-selector-trigger" aria-haspopup="listbox" aria-controls="du-selector-menu" aria-expanded="false"><span class="du-trigger-label"><?=$label?></span><span class="du-arrear-warning" id="du-arrear-warning" role="img" hidden></span><span class="du-chevron" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M3.5 6 8 10 12.5 6"/></svg></span></button><div class="du-selector-menu" id="du-selector-menu" role="listbox" aria-label="Pilih tagihan Daftar Ulang" tabindex="-1" hidden></div></div><?php else: ?><span class="comp-label"<?= $key === 'spp' ? ' id="spp-component-label"' : '' ?>><?=$label?></span><?php endif; ?><?php if($key==='spp'): ?><small class="du-inline-context du-context-label" id="spp-context-label"><?= htmlspecialchars($initialLockReasons['spp'] ?? 'Memeriksa tagihan bulan ini.') ?></small><?php endif; ?><?php if($key==='komite'): ?><small class="du-inline-context du-context-label" id="komite-context-label"><?= htmlspecialchars($initialLockReasons['komite'] ?? 'Memeriksa tagihan bulan ini.') ?></small><?php endif; ?><?php if(in_array($key,['psb'],true)): ?><small class="du-inline-context du-context-label" id="<?=$key?>-context-label"><?= $initialFeeLocks[$key] ? (($oneTimeAvailability[$key]['total'] <= .001) ? 'Belum ada tagihan di Data Siswa.' : 'Tagihan sudah lunas.') : 'Tagihan satu kali, dapat dicicil' ?></small><?php endif; ?><?php if($key==='du'): ?><small class="du-inline-context du-context-label" id="du-context-label"><?= htmlspecialchars($initialLockReasons['du'] ?? 'Memeriksa tagihan Daftar Ulang.') ?></small><small class="du-inline-context du-master-warning" id="du-master-warning" hidden></small><?php endif; ?></td>
                  <td data-label="Total Tagihan"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-total" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sudah Terbayar"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-bayar" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sisa"><input class="tbl-input tbl-system tbl-system-sisa" type="text" value="0" id="<?=$key?>-sisa" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Input Bayar"><input class="tbl-input tbl-pay <?= $initialFeeLocks[$key] ? 'tbl-readonly' : '' ?>" type="text"
                        id="<?=$key?>-input" name="<?=$inputName?>"
                        value="<?= substr(transaction_authorization_money($initialInputZero[$key] ? 0 : $d[$col]), 3) ?>"
                        <?= $initialFeeLocks[$key] ? 'readonly' : '' ?> aria-readonly="<?= $initialFeeLocks[$key] ? 'true' : 'false' ?>"
                        aria-describedby="<?=$key?>-context-label" /></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <input type="hidden" id="kelas-du" name="kelas_du" value="<?= htmlspecialchars(preg_replace('/\D+/', '', (string)($res_du['kelas'] ?? $d['KELAS']))) ?>" />
          <input type="hidden" id="tahun-ajaran-du" name="tahun_ajaran_du" value="<?= htmlspecialchars($selectedAcademicYear) ?>" />
          <input type="hidden" id="tagihan-daftar-ulang-id" name="tagihan_daftar_ulang_id" value="<?= $linkedDuBillId ?>" />
          <input type="hidden" id="du-expected-total" name="du_expected_total" value="" />
          <input type="hidden" id="du-expected-paid" name="du_expected_paid" value="" />

          <!-- Lain-lain -->
          <div class="section-divider"><span>Lain-lain</span></div>
          <div class="alert alert-warning biaya-lain-overpaid-alert" id="biaya-lain-overpaid-alert" hidden></div>
          <div class="lainlain-grid" id="biaya-lain-list">
            <?php foreach ($biaya_lain_details as $index => $detail):
              $selectedBillId = !empty($detail['_purchase'])?0:(int)($detail['tagihan_biaya_lain_id'] ?? 0);
              $optionalChoice=$detail['choice']??(!$selectedBillId&&!empty($detail['master_biaya_lain_id'])?'master:'.$detail['master_biaya_lain_id']:'');
              $currentNominal = (float)($detail['nominal_snapshot'] ?? 0);
              $manualFee = ($detail['_fee_mode'] ?? '') === 'input_manual' && str_starts_with($optionalChoice,'master:');
              $displayAmount = $detail['_draft_amount'] ?? number_format($currentNominal, fmod($currentNominal,1.0)===0.0?0:2, ',', '.');
              $selectedBill = null;
              foreach($biaya_lain_bills[$d['NO_INDUK']] ?? [] as $candidate) if((int)$candidate['id']===$selectedBillId){$selectedBill=$candidate;break;}
              $masterTotal = $selectedBill ? (float)$selectedBill['nominal'] : $currentNominal;
              $paidOther = $selectedBill ? (float)$selectedBill['paid'] : 0.0;
              $remainingAfterCurrent = max(0, $masterTotal - $paidOther);
            ?>
            <div class="lainlain-row biaya-lain-row<?= $manualFee?' is-optional-fee is-manual-fee':'' ?>" data-original-choice="<?= htmlspecialchars($detail['_original_choice']??'',ENT_QUOTES,'UTF-8') ?>" data-original-amount="<?= money_attr($detail['_original_amount']??$currentNominal) ?>">
              <span class="ll-num"><?= $index + 1 ?></span>
              <input type="hidden" name="biaya_lain_detail_id[]" value="<?= (int)($detail['id'] ?? 0) ?: '' ?>" />
              <label class="ll-field ll-field-kind">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_tagihan_id[]">
                  <?php if($optionalChoice): ?><option value="<?= htmlspecialchars($optionalChoice,ENT_QUOTES,'UTF-8') ?>" data-fee-mode="<?= $manualFee?'input_manual':'berulang' ?>" data-nominal="<?= money_attr($detail['tarif_referensi_snapshot']??$currentNominal) ?>" data-base-label="<?= htmlspecialchars($detail['nama_biaya_snapshot'],ENT_QUOTES,'UTF-8') ?>" selected><?= htmlspecialchars($detail['nama_biaya_snapshot']) ?></option><?php endif; ?>
                  <option value="" <?= !$optionalChoice && $selectedBillId === 0 ? 'selected' : '' ?> <?= $selectedBillId === 0 && !empty($detail['id']) ? 'data-legacy="1" data-nominal="'.money_attr($currentNominal).'"' : '' ?>><?= $selectedBillId === 0 && !empty($detail['id']) ? htmlspecialchars($detail['nama_biaya_snapshot']) . ' (Data lama)' : '-- Pilih Biaya / Tagihan Lama --' ?></option>
                  <?php foreach ($biaya_lain_bills[$d['NO_INDUK']] ?? [] as $bill): $isSelected=(int)$bill['id']===$selectedBillId; ?>
                  <option value="<?= (int)$bill['id'] ?>" data-master-id="<?= (int)$bill['master_id'] ?>" data-nominal="<?= money_attr($bill['nominal']) ?>" data-paid="<?= money_attr($bill['paid']) ?>" data-base-label="<?= htmlspecialchars($bill['nama']) ?>" <?= $isSelected ? 'selected' : '' ?>>
                    <?= htmlspecialchars($bill['nama']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ll-field"><span class="ll-field-label">Total</span><input class="field-input biaya-lain-total" type="text" readonly aria-label="Total biaya lain"
                value="<?= number_format($masterTotal, 0, ',', '.') ?>" placeholder="Total" /></label>
              <label class="ll-field"><span class="ll-field-label">Sudah Dibayar</span><input class="field-input biaya-lain-paid" type="text" readonly aria-label="Sudah dibayar biaya lain"
                value="<?= number_format($paidOther, 0, ',', '.') ?>" placeholder="Sudah" /></label>
              <label class="ll-field"><span class="ll-field-label">Sisa</span><input class="field-input biaya-lain-sisa" type="text" readonly aria-label="Sisa biaya lain"
                value="<?= number_format($remainingAfterCurrent, 0, ',', '.') ?>" placeholder="Sisa" /></label>
              <label class="ll-field"><span class="ll-field-label"><?= $manualFee?'Nominal Dibayar':'Bayar' ?></span><input class="field-input biaya-lain-nominal" type="text" inputmode="decimal" name="biaya_lain_nominal[]" aria-label="Input bayar biaya lain"
                value="<?= htmlspecialchars($displayAmount, ENT_QUOTES, 'UTF-8') ?>" placeholder="Input bayar" /></label>
              <button class="btn-icon-danger btn-remove-biaya-lain" type="button" title="Hapus baris" aria-label="Hapus baris">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-ghost btn-add-biaya-lain" id="btn-add-biaya-lain" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Biaya Lain
          </button>
          <template id="biaya-lain-row-template">
            <div class="lainlain-row biaya-lain-row">
              <span class="ll-num"></span>
              <input type="hidden" name="biaya_lain_detail_id[]" value="" />
              <label class="ll-field ll-field-kind">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_tagihan_id[]">
                  <option value="">-- Pilih Biaya / Tagihan Lama --</option>
                  <?php foreach ($biaya_lain_bills[$d['NO_INDUK']] ?? [] as $bill): ?>
                  <option value="<?= (int)$bill['id'] ?>" data-master-id="<?= (int)$bill['master_id'] ?>" data-nominal="<?= money_attr($bill['nominal']) ?>" data-paid="<?= money_attr($bill['paid']) ?>" data-base-label="<?= htmlspecialchars($bill['nama']) ?>"><?= htmlspecialchars($bill['nama']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ll-field"><span class="ll-field-label">Total</span><input class="field-input biaya-lain-total" type="text" value="0" placeholder="Total" readonly aria-label="Total biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Sudah Dibayar</span><input class="field-input biaya-lain-paid" type="text" value="0" placeholder="Sudah" readonly aria-label="Sudah dibayar biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Sisa</span><input class="field-input biaya-lain-sisa" type="text" value="0" placeholder="Sisa" readonly aria-label="Sisa biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Bayar</span><input class="field-input biaya-lain-nominal" type="text" value="0" placeholder="Input bayar" name="biaya_lain_nominal[]" aria-label="Input bayar biaya lain" /></label>
              <button class="btn-icon-danger btn-remove-biaya-lain" type="button" title="Hapus baris" aria-label="Hapus baris">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </div>
          </template>

          <input type="hidden" name="potongan_spp" id="potongan-spp" value="0" />

          <input type="hidden" name="catatan" value="<?= htmlspecialchars($d['KETERANGAN'] ?? '') ?>">
          <?php if (trim((string)($d['KETERANGAN'] ?? '')) !== ''): ?>
          <section class="payment-edit-panel payment-edit-note">
            <h3>Catatan</h3>
            <p><?= htmlspecialchars($d['KETERANGAN']) ?></p>
          </section>
          <?php endif; ?>
          <div class="section-divider"><span>Riwayat Transaksi Siswa</span></div>
          <div class="payment-history-panel" id="payment-history-panel">
            <div class="payment-history-head">
              <div>
                <strong>Riwayat pembayaran siswa</strong>
                <span id="payment-history-period">Memuat history transaksi siswa.</span><label class="payment-history-scope">Tahun Histori <select id="payment-history-scope" class="field-input field-select"><option value="active">Penempatan Aktif</option><option value="all">Semua Tahun</option></select></label>
              </div>
            </div>
            <div class="table-container payment-history-table-wrap">
              <table class="payment-table payment-history-table">
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th>Periode</th>
                    <th>Komponen</th>
                    <th>Metode</th>
                    <th>Operator</th>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody id="payment-history-body">
                  <tr><td colspan="6">Memuat data.</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <?php if (transaction_authorization_requires_request()): ?>
          <div class="authorization-request-panel">
            <div>
              <h3>Ajukan perubahan transaksi</h3>
              <p>Perubahan diterapkan setelah disetujui Super Admin.</p>
            </div>
            <label class="field-row authorization-reason-field">
              <span class="field-label">Alasan perubahan</span>
              <textarea class="field-input" name="authorization_reason" rows="3" minlength="5" maxlength="500" required placeholder="Jelaskan alasan dan bagian transaksi yang perlu diperbaiki."></textarea>
            </label>
          </div>
          <?php endif; ?>

          <!-- Action Buttons -->
          <div class="action-bar">
            <button type="submit" class="btn btn-primary" id="btn-update">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              <?= transaction_authorization_requires_request() ? 'Ajukan Perubahan' : 'Simpan Perubahan' ?>
            </button>
            <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost" id="btn-batal">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Batal
            </a>
          </div>
        </form>
      </div>
    </main>
  </div>

  <?php include '../includes/spp_warning_modal.php'; ?>

  <script>
    window.sppDaftarUlangMasters = {};
    window.sppDaftarUlangHasMasters = true;
    window.sppPaymentHistoryUrl = 'history_siswa.php';
    window.sppOtherFeeBillsUrl = 'biaya_lain_siswa.php';
    window.sppOtherFeeMasters = <?= json_encode(other_fee_optional_choices($koneksi),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    window.sppPaymentHistoryUnit = <?= unit_active_id() ?>;
    window.sppPaymentStatusUrl = 'status_spp.php';
    window.sppPublishedBilling = true;
    window.sppEditPaymentId = <?= (int)$d['id'] ?>;
    window.sppEditingDuBillId = <?= $linkedDuBillId ?>;
    window.sppEditOriginal = <?= json_encode([
      'no_induk' => (string)$d['NO_INDUK'],
      'bulan' => month_code((string)$d['BULAN']),
      'tahun' => (string)$d['TAHUN'],
      'amount' => (float)$d['uang_spp_baru'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    window.sppFlashWarning = <?= json_encode(
      ($flash['scope'] ?? '') === 'spp' ? ($flash['spp_status'] ?? null) : null,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;
  </script>
  <script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/other_fee_picker.js?v=<?= filemtime(__DIR__ . "/../assets/js/other_fee_picker.js") ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>

