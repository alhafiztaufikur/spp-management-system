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
requireRole(['admin']);
if (empty($_SESSION['csrf_payment'])) $_SESSION['csrf_payment'] = bin2hex(random_bytes(32));
$activeAcademicYear = du_current_academic_year();
$activeAcademicYearSql = $koneksi->real_escape_string($activeAcademicYear);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: lihat.php'); exit; }

$stmt = $koneksi->prepare("SELECT p.*, s.NO_INDUK, s.NAMA, s.KELAS, s.SPP_PERBULAN FROM bayar p JOIN siswa s ON s.NO_INDUK = p.NO_INDUK WHERE p.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$d) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Data tidak ditemukan!']; header('Location: lihat.php'); exit; }
if ((int)($d['payment_link_version'] ?? 0) !== 1) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Pembayaran legacy tidak dapat diedit. Rekonsiliasi manual diperlukan terlebih dahulu.'];
    header('Location: lihat.php');
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
        COALESCE(p.paid_pangkal, 0) AS paid_pangkal,
        COALESCE(p.paid_psb, 0) AS paid_psb,
        COALESCE(du.paid_du, 0) AS paid_du,
        mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder,
        (SELECT ta_l.label FROM siswa_tahun_ajaran sta_l
         JOIN tahun_ajaran ta_l ON ta_l.id=sta_l.tahun_ajaran_id
         WHERE sta_l.no_induk=s.NO_INDUK AND sta_l.status='lulus'
         ORDER BY ta_l.label DESC LIMIT 1) AS graduation_year
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
    LEFT JOIN (
        SELECT NO_INDUK, SUM(U_PANGKAL) AS paid_pangkal, SUM(U_PSB) AS paid_psb
        FROM bayar
        WHERE id <> ?
        GROUP BY NO_INDUK
    ) p ON p.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT no_induk, SUM(jumlah) AS paid_du
        FROM bayar_du
        WHERE bayar_id IS NULL OR bayar_id <> ?
        GROUP BY no_induk
    ) du ON du.no_induk = s.NO_INDUK
    WHERE s.is_active = 1 OR s.NO_INDUK = ? OR (
        EXISTS(SELECT 1 FROM siswa_tahun_ajaran sta_l WHERE sta_l.no_induk=s.NO_INDUK AND sta_l.status='lulus')
        AND (EXISTS(SELECT 1 FROM tagihan_daftar_ulang tdu_o
            LEFT JOIN bayar_du bd_o ON bd_o.tagihan_daftar_ulang_id=tdu_o.id
            WHERE tdu_o.no_induk=s.NO_INDUK AND tdu_o.status='open'
              AND tdu_o.tahun_ajaran_snapshot<='$activeAcademicYearSql'
            GROUP BY tdu_o.id,tdu_o.nominal_tagihan
            HAVING tdu_o.nominal_tagihan-COALESCE(SUM(bd_o.jumlah),0)>.001)
          OR EXISTS(SELECT 1 FROM tagihan_spp ts_o LEFT JOIN spp_alokasi a_o ON a_o.tagihan_spp_id=ts_o.id LEFT JOIN spp_alokasi_batch ab_o ON ab_o.id=a_o.batch_id WHERE ts_o.no_induk=s.NO_INDUK AND ts_o.status='open' GROUP BY ts_o.id HAVING MIN(ts_o.nominal_tagihan)-COALESCE(SUM(CASE WHEN ab_o.status='active' THEN a_o.nominal_dari_bayar+a_o.nominal_dari_titipan ELSE 0 END),0)>.001)
          OR EXISTS(SELECT 1 FROM tagihan_komite tk_o LEFT JOIN bayar_komite bk_o ON bk_o.tagihan_komite_id=tk_o.id WHERE tk_o.no_induk=s.NO_INDUK AND tk_o.status='open' GROUP BY tk_o.id HAVING MIN(tk_o.nominal_tagihan)-COALESCE(SUM(bk_o.nominal),0)>.001))
    )
    ORDER BY s.NAMA ASC
";
$stmt_siswa = $koneksi->prepare($siswa_sql);
$stmt_siswa->bind_param(
    'iis',
    $id, $id, $d['NO_INDUK']
);
$stmt_siswa->execute();
$siswa_list = $stmt_siswa->get_result();

$period_payments = [];
$stmt_period = $koneksi->prepare("
    SELECT NO_INDUK, BULAN, TAHUN, SUM(U_SPP) AS paid_spp, SUM(U_KOMITE) AS paid_komite
    FROM bayar
    WHERE id <> ?
    GROUP BY NO_INDUK, BULAN, TAHUN
");
$stmt_period->bind_param('i', $id);
$stmt_period->execute();
$spp_paid_result = $stmt_period->get_result();
while ($paid = $spp_paid_result->fetch_assoc()) {
    $bulan_key = month_code($paid['BULAN']);
    $periodKey = $bulan_key . '-' . $paid['TAHUN'];
    $period_payments[$paid['NO_INDUK']]['spp'][$periodKey] =
        ($period_payments[$paid['NO_INDUK']]['spp'][$periodKey] ?? 0) + (float)$paid['paid_spp'];
    $period_payments[$paid['NO_INDUK']]['komite'][$periodKey] =
        ($period_payments[$paid['NO_INDUK']]['komite'][$periodKey] ?? 0) + (float)$paid['paid_komite'];
}
$stmt_period->close();

$spp_placements = [];
$placementResult = $koneksi->query("SELECT sta.no_induk, ta.label AS tahun_ajaran, sta.spp_perbulan_snapshot,sta.spp_covered_by_psb
    FROM siswa_tahun_ajaran sta
    JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
    WHERE sta.status = 'aktif'
    ORDER BY sta.no_induk, ta.label");
while ($placement = $placementResult->fetch_assoc()) {
    $spp_placements[$placement['no_induk']][] = [
        'tahun_ajaran' => (string)$placement['tahun_ajaran'],
        'tarif' => (float)$placement['spp_perbulan_snapshot'],
        'covered_by_psb' => (int)$placement['spp_covered_by_psb'],
    ];
}
$published_spp_payload = spp_billing_schema_ready($koneksi) ? spp_payment_payload($koneksi, $id) : [];
$komite_payload = komite_payment_payload($koneksi,$id);

$currentPeriodKey = month_code($d['BULAN']) . '-' . $d['TAHUN'];
$d['kewajiban_spp'] = max(0, (float)$d['SPP_PERBULAN'] - (float)($period_payments[$d['NO_INDUK']]['spp'][$currentPeriodKey] ?? 0));

$linkedDuBillId = (int)($res_du['tagihan_daftar_ulang_id'] ?? 0);
$du_bills = du_selectable_bills_payload($koneksi, $id, $linkedDuBillId);

function active_academic_year_from_payment_period($bulan, $tahun): string {
    $month = (int)month_code($bulan);
    $year = (int)$tahun;
    if ($month < 1 || $month > 12 || $year < 2000) {
        return du_current_academic_year();
    }
    return du_academic_year_label($month, $year);
}

$selectedAcademicYear = active_academic_year_from_payment_period($d['BULAN'], $d['TAHUN']);
$annual_fee_payload = annual_fee_payload_for_options($koneksi, $id);

$biaya_lain_bills = [];
$stmtBills = $koneksi->prepare("SELECT t.id,t.no_induk,t.master_biaya_lain_id,t.nama_snapshot nama,
    t.nominal_tagihan nominal,t.status,
    COALESCE(SUM(CASE WHEN d.bayar_id<>? THEN d.nominal_snapshot ELSE 0 END),0) paid
    FROM tagihan_biaya_lain t
    LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id
    WHERE t.status='open' OR EXISTS(SELECT 1 FROM bayar_biaya_lain own WHERE own.bayar_id=? AND own.tagihan_biaya_lain_id=t.id)
    GROUP BY t.id ORDER BY t.nama_snapshot");
$stmtBills->bind_param('ii',$id,$id); $stmtBills->execute();
foreach($stmtBills->get_result()->fetch_all(MYSQLI_ASSOC) as $bill){
    $bill['id']=(int)$bill['id']; $bill['master_id']=(int)$bill['master_biaya_lain_id'];
    $bill['nominal']=(float)$bill['nominal']; $bill['paid']=(float)$bill['paid'];
    $bill['sisa']=max(0,$bill['nominal']-$bill['paid']);
    if($bill['sisa']>.001 || $bill['no_induk']===$d['NO_INDUK']) $biaya_lain_bills[$bill['no_induk']][]=$bill;
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
$stmt_biaya_lain->close();
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
    // tetapi Pangkal selalu dihitung dari nominal Master Siswa dan potongan.
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
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <meta name="description" content="Edit data transaksi pembayaran siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=10.9" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

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

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Data Pembayaran
          </div>
          <span class="badge-id">ID #<?= $d['id'] ?></span>
        </div>

        <form method="POST" action="../pembayaran/proses.php" id="form-bayar">
          <input type="hidden" name="aksi" value="update" />
          <input type="hidden" name="id" value="<?= $d['id'] ?>" />
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_payment']) ?>" />

          <!-- Tanggal + Jumlah -->
          <div class="top-info-row">
            <div class="info-group">
              <div class="field-row">
                <label class="field-label" for="tgl-bayar">Tanggal Bayar</label>
                <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar"
                  value="<?= date('Y-m-d', strtotime($d['TGL_BYR'])) ?>" required />
              </div>
              <div class="field-row">
                <label class="field-label" for="bulan-bayar">Bulan Tagihan SPP &amp; Komite</label>
                <div class="field-group-inline">
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
                    <option value="<?= $code ?>" data-label="<?= $label ?>" <?= $selectedMonth === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
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
            <div class="jumlah-box">
              <span class="jumlah-label">Total Jumlah</span>
              <span class="jumlah-value" id="totalJumlah">Rp <?= number_format($d['total_jumlah'],0,',','.') ?></span>
              <input type="hidden" name="total_jumlah" id="hidden-total" value="<?= $d['total_jumlah'] ?>" />
            </div>
          </div>

          <!-- Data Siswa -->
          <div class="section-divider"><span>Data Siswa</span></div>
          <div class="fields-grid">
            <div class="field-row full-span">
              <label class="field-label" for="siswa-search">Cari Siswa (Nama / NIS / NIS Diknas)</label>
              <div class="search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="siswa-search" list="siswa-list"
                  value="<?= htmlspecialchars($d['NAMA']) ?>"
                  placeholder="Ketik nama, NIS, atau NIS Diknas..." oninput="pilihSiswaDatalist(this)" autocomplete="off" />
              </div>
              <datalist id="siswa-list">
                <?php while ($s = $siswa_list->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($s['NAMA']) ?>"
                  data-nis="<?= htmlspecialchars($s['NO_INDUK']) ?>"
                  data-diknas="<?= htmlspecialchars((string)($s['NO_induk_diknas'] ?? '')) ?>"
                  data-nama="<?= htmlspecialchars($s['NAMA']) ?>"
                  data-kelas="<?= htmlspecialchars($s['graduation_year'] ? ('LULUS · TA '.$s['graduation_year']) : class_label(['tingkat'=>$s['master_tingkat']?:$s['KELAS'],'kode_rombel'=>$s['kode_rombel']??'BELUM','is_placeholder'=>$s['is_placeholder']??1])) ?>"
                  data-is-graduate="<?= $s['graduation_year'] ? '1' : '0' ?>"
                  data-graduation-year="<?= htmlspecialchars((string)$s['graduation_year']) ?>"
                  data-total-pangkal="<?= money_attr(total_after_discount($s['PANGKAL'], $s['potong_pangkal'], $s['tot_pangkal'])) ?>"
                  data-total-psb="<?= money_attr($s['PSB']) ?>"
                  data-total-spp="<?= money_attr($s['SPP_PERBULAN']) ?>"
                  data-total-komite="<?= money_attr($s['POMG']) ?>"
                  data-paid-pangkal="<?= money_attr($s['paid_pangkal']) ?>"
                  data-paid-psb="<?= money_attr($s['paid_psb']) ?>"
                  data-paid-spp-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['spp'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-spp-placements="<?= htmlspecialchars(json_encode($spp_placements[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-spp-billing="<?= htmlspecialchars(json_encode($published_spp_payload[$s['NO_INDUK']] ?? ['saldo'=>0,'tagihan'=>[]], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-komite-billing="<?= htmlspecialchars(json_encode($komite_payload[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-komite-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['komite'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-annual-fees="<?= htmlspecialchars(json_encode($annual_fee_payload[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  data-du-bills="<?= htmlspecialchars(json_encode($du_bills[$s['NO_INDUK']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-biaya-lain-bills="<?= htmlspecialchars(json_encode($biaya_lain_bills[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($s['NAMA']) ?> (<?= htmlspecialchars($s['graduation_year'] ? ('LULUS · TA '.$s['graduation_year']) : class_label(['tingkat'=>$s['master_tingkat']?:$s['KELAS'],'kode_rombel'=>$s['kode_rombel']??'BELUM','is_placeholder'=>$s['is_placeholder']??1])) ?>)
                </option>
                <?php endwhile; ?>
              </datalist>
            </div>
            <div class="field-row">
              <label class="field-label">No. Induk</label>
              <input class="field-input" type="text" id="disp-nis" name="no_induk" value="<?= htmlspecialchars($d['NO_INDUK']) ?>" readonly required />
            </div>
            <div class="field-row">
              <label class="field-label">Nama Siswa</label>
              <input class="field-input" type="text" id="disp-nama" value="<?= htmlspecialchars($d['NAMA']) ?>" readonly />
            </div>
            <div class="field-row">
              <label class="field-label">Kelas</label>
              <input class="field-input" type="text" id="disp-kelas" value="<?= htmlspecialchars($d['kelas_rombel_snapshot'] ?: ('Kelas '.$d['KELAS'].' (Belum Ditentukan)')) ?>" readonly />
            </div>
          </div>

          <!-- Rincian Pembayaran -->
          <div class="section-divider"><span>Rincian Pembayaran</span></div>
          <p class="payment-auto-note">SPP dan Komite bulan ini dibayar bersama.</p>
          <section class="spp-deposit-banner" id="spp-deposit-banner" hidden aria-live="polite"><div><span>Saldo Titipan SPP</span><strong id="spp-deposit-balance">Rp 0</strong><small id="spp-deposit-capacity"></small></div><button type="button" class="btn btn-ghost" id="spp-use-deposit-button">Gunakan Titipan</button></section>
          <div class="spp-deposit-action"><button type="button" class="btn btn-ghost" id="spp-record-deposit-button"><?= !empty($currentSppAllocation['titipan_baru']) ? 'Kembali ke Bayar SPP' : 'Catat Titipan SPP' ?></button><span id="spp-action-context" aria-live="polite"><?= !empty($currentSppAllocation['titipan_baru']) ? 'Nominal SPP dicatat sebagai titipan.' : '' ?></span></div>
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
                  ['pangkal', '💰 Uang Pangkal', 'U_PANGKAL', 'uang_pangkal'],
                  ['psb', '🎒 Uang PSB', 'U_PSB', 'uang_psb'],
                  ['spp', '🎓 Uang SPP Diterima', 'uang_spp_baru', 'uang_spp'],
                  ['komite', '🏫 Uang Komite', 'U_KOMITE', 'uang_komite'],
                  ['du', '📚 Daftar Ulang', 'uang_du', 'uang_du']
                ];
                foreach ($komp as $i => [$key,$label,$col,$inputName]):
                ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td><?php if($key==='du'): ?><div class="du-bill-selector"><span class="comp-label du-static-label" id="du-static-label"><?=$label?></span><button type="button" class="du-selector-trigger" id="du-selector-trigger" aria-haspopup="listbox" aria-controls="du-selector-menu" aria-expanded="false" hidden><span class="du-trigger-label"><?=$label?></span><span class="du-arrear-warning" id="du-arrear-warning" role="img"></span><span class="du-chevron" aria-hidden="true">⌄</span></button><div class="du-selector-menu" id="du-selector-menu" role="listbox" aria-label="Pilih tagihan Daftar Ulang" tabindex="-1" hidden></div></div><?php else: ?><span class="comp-label"<?= $key === 'spp' ? ' id="spp-component-label"' : '' ?>><?=$label?></span><?php endif; ?><?php if($key==='spp'): ?><small class="du-inline-context du-context-label" id="spp-context-label">Pilih bulan tagihan.</small><?php endif; ?><?php if($key==='komite'): ?><small class="du-inline-context du-context-label" id="komite-context-label">Lunas penuh per bulan.</small><?php endif; ?><?php if(in_array($key,['pangkal','psb'],true)): ?><small class="du-inline-context du-context-label">Tagihan satu kali, dapat dicicil</small><?php endif; ?><?php if($key==='du'): ?><small class="du-inline-context du-context-label" id="du-context-label">Pilih tagihan yang akan dibayar.</small><small class="du-inline-context du-master-warning" id="du-master-warning" hidden></small><?php endif; ?></td>
                  <td data-label="Total Tagihan"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-total" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sudah Terbayar"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-bayar" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sisa"><input class="tbl-input tbl-system tbl-system-sisa" type="text" value="0" id="<?=$key?>-sisa" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Input Bayar"><input class="tbl-input tbl-pay" type="text"
                        id="<?=$key?>-input" name="<?=$inputName?>"
                        value="<?= number_format((float)$d[$col], 0, ',', '.') ?>" /></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <input type="hidden" id="kelas-du" name="kelas_du" value="<?= htmlspecialchars(preg_replace('/\D+/', '', (string)$d['KELAS'])) ?>" />
          <input type="hidden" id="tahun-ajaran-du" name="tahun_ajaran_du" value="<?= htmlspecialchars($selectedAcademicYear) ?>" />
          <input type="hidden" id="tagihan-daftar-ulang-id" name="tagihan_daftar_ulang_id" value="<?= $linkedDuBillId ?>" />

          <!-- Lain-lain -->
          <div class="section-divider"><span>Lain-lain</span></div>
          <div class="alert alert-warning biaya-lain-overpaid-alert" id="biaya-lain-overpaid-alert" hidden></div>
          <div class="lainlain-grid" id="biaya-lain-list">
            <?php foreach ($biaya_lain_details as $index => $detail):
              $selectedBillId = (int)($detail['tagihan_biaya_lain_id'] ?? 0);
              $currentNominal = (float)($detail['nominal_snapshot'] ?? 0);
              $selectedBill = null;
              foreach($biaya_lain_bills[$d['NO_INDUK']] ?? [] as $candidate) if((int)$candidate['id']===$selectedBillId){$selectedBill=$candidate;break;}
              $masterTotal = $selectedBill ? (float)$selectedBill['nominal'] : $currentNominal;
              $paidOther = $selectedBill ? (float)$selectedBill['paid'] : 0.0;
              $remainingAfterCurrent = max(0, $masterTotal - $paidOther);
            ?>
            <div class="lainlain-row biaya-lain-row">
              <span class="ll-num"><?= $index + 1 ?></span>
              <input type="hidden" name="biaya_lain_detail_id[]" value="<?= (int)($detail['id'] ?? 0) ?: '' ?>" />
              <label class="ll-field">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_tagihan_id[]">
                  <option value="" <?= $selectedBillId === 0 ? 'selected' : '' ?> <?= $selectedBillId === 0 && !empty($detail['id']) ? 'data-legacy="1" data-nominal="'.money_attr($currentNominal).'"' : '' ?>><?= $selectedBillId === 0 && !empty($detail['id']) ? htmlspecialchars($detail['nama_biaya_snapshot']) . ' (Data lama)' : '-- Pilih Tagihan --' ?></option>
                  <?php foreach ($biaya_lain_bills[$d['NO_INDUK']] ?? [] as $bill): $isSelected=(int)$bill['id']===$selectedBillId; ?>
                  <option value="<?= (int)$bill['id'] ?>" data-master-id="<?= (int)$bill['master_id'] ?>" data-nominal="<?= money_attr($bill['nominal']) ?>" data-paid="<?= money_attr($bill['paid']) ?>" data-base-label="<?= htmlspecialchars($bill['nama']) ?>" <?= $isSelected ? 'selected' : '' ?>>
                    <?= htmlspecialchars($bill['nama']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ll-field"><span class="ll-field-label">Total</span><input class="field-input biaya-lain-total" type="text" readonly aria-label="Total biaya lain"
                value="<?= number_format($masterTotal, 0, ',', '.') ?>" placeholder="Total" /></label>
              <label class="ll-field"><span class="ll-field-label">Sudah</span><input class="field-input biaya-lain-paid" type="text" readonly aria-label="Sudah dibayar biaya lain"
                value="<?= number_format($paidOther, 0, ',', '.') ?>" placeholder="Sudah" /></label>
              <label class="ll-field"><span class="ll-field-label">Sisa</span><input class="field-input biaya-lain-sisa" type="text" readonly aria-label="Sisa biaya lain"
                value="<?= number_format($remainingAfterCurrent, 0, ',', '.') ?>" placeholder="Sisa" /></label>
              <label class="ll-field"><span class="ll-field-label">Bayar</span><input class="field-input biaya-lain-nominal" type="text" name="biaya_lain_nominal[]" aria-label="Input bayar biaya lain"
                value="<?= number_format($currentNominal, 0, ',', '.') ?>" placeholder="Input bayar" /></label>
              <label class="ll-field ll-field-note"><span class="ll-field-label">Keterangan</span><input class="field-input biaya-lain-keterangan" type="text" name="biaya_lain_keterangan[]" maxlength="255"
                value="<?= htmlspecialchars($detail['keterangan'] ?? '') ?>" placeholder="Keterangan opsional..." /></label>
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
              <label class="ll-field">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_tagihan_id[]">
                  <option value="">-- Pilih Tagihan --</option>
                  <?php foreach ($biaya_lain_bills[$d['NO_INDUK']] ?? [] as $bill): ?>
                  <option value="<?= (int)$bill['id'] ?>" data-master-id="<?= (int)$bill['master_id'] ?>" data-nominal="<?= money_attr($bill['nominal']) ?>" data-paid="<?= money_attr($bill['paid']) ?>" data-base-label="<?= htmlspecialchars($bill['nama']) ?>"><?= htmlspecialchars($bill['nama']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ll-field"><span class="ll-field-label">Total</span><input class="field-input biaya-lain-total" type="text" value="0" placeholder="Total" readonly aria-label="Total biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Sudah</span><input class="field-input biaya-lain-paid" type="text" value="0" placeholder="Sudah" readonly aria-label="Sudah dibayar biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Sisa</span><input class="field-input biaya-lain-sisa" type="text" value="0" placeholder="Sisa" readonly aria-label="Sisa biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Bayar</span><input class="field-input biaya-lain-nominal" type="text" value="0" placeholder="Input bayar" name="biaya_lain_nominal[]" aria-label="Input bayar biaya lain" /></label>
              <label class="ll-field ll-field-note"><span class="ll-field-label">Keterangan</span><input class="field-input biaya-lain-keterangan" type="text" placeholder="Keterangan opsional..." name="biaya_lain_keterangan[]" maxlength="255" /></label>
              <button class="btn-icon-danger btn-remove-biaya-lain" type="button" title="Hapus baris" aria-label="Hapus baris">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </div>
          </template>

          <input type="hidden" name="potongan_spp" id="potongan-spp" value="0" />
          <input type="hidden" name="gunakan_titipan_spp" id="gunakan-titipan-spp" data-nis="<?= htmlspecialchars($d['NO_INDUK']) ?>" value="<?= !empty($currentSppAllocation['titipan_digunakan']) ? '1' : '0' ?>" />
          <input type="hidden" id="spp-action" name="spp_action" value="<?= !empty($currentSppAllocation['titipan_baru']) ? 'titipan' : 'bayar' ?>" />

          <input type="hidden" name="catatan" value="<?= htmlspecialchars($d['KETERANGAN'] ?? '') ?>">
          <div class="section-divider"><span>History Transaksi Siswa</span></div>
          <div class="payment-history-panel" id="payment-history-panel">
            <div class="payment-history-head">
              <div>
                <strong>Riwayat pembayaran siswa</strong>
                <span id="payment-history-period">Memuat history transaksi siswa.</span>
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

          <div class="authorization-request-panel">
            <div>
              <h3>Ajukan perubahan transaksi</h3>
              <p>Perubahan baru diterapkan setelah disetujui bendahara atau administrator lain.</p>
            </div>
            <label class="field-row authorization-reason-field">
              <span class="field-label">Alasan perubahan</span>
              <textarea class="field-input" name="authorization_reason" rows="3" minlength="5" maxlength="500" required placeholder="Jelaskan alasan dan bagian transaksi yang perlu diperbaiki."></textarea>
            </label>
          </div>

          <!-- Action Buttons -->
          <div class="action-bar">
            <button type="submit" class="btn btn-warning" id="btn-update">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Ajukan Perubahan
            </button>
            <a href="lihat.php" class="btn btn-ghost" id="btn-batal">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Batal
            </a>
          </div>
        </form>
      </div>
    </main>
  </div>

  <?php include '../includes/spp_warning_modal.php'; ?>
  <div class="spp-deposit-modal" id="spp-deposit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="spp-deposit-modal-title"><div class="spp-deposit-modal-card"><h3 id="spp-deposit-modal-title">Pratinjau Penggunaan Titipan</h3><p id="spp-deposit-modal-summary"></p><div id="spp-deposit-modal-lines" class="spp-deposit-preview-lines"></div><div class="action-bar"><button type="button" class="btn btn-primary" id="spp-deposit-confirm">Konfirmasi Penggunaan</button><button type="button" class="btn btn-ghost" id="spp-deposit-cancel">Batal</button></div></div></div>

  <script>
    window.sppDaftarUlangMasters = {};
    window.sppDaftarUlangHasMasters = true;
    window.sppPaymentHistoryUrl = 'history_siswa.php';
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
  <script src="../assets/js/app.js?v=10.4"></script>
</body>
</html>

