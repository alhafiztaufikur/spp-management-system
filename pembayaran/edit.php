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
requireRole(['admin', 'kasir']);

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
$stmt_du = $koneksi->prepare("SELECT jumlah, kelas, th_ajaran FROM bayar_du WHERE bayar_id = ? LIMIT 1");
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

$siswa_sql = "
    SELECT
        s.*,
        GREATEST(COALESCE(s.PANGKAL_BAYAR, 0) - IF(s.NO_INDUK = ?, ?, 0), 0) AS paid_pangkal,
        GREATEST(COALESCE(s.BANGUNAN_BAYAR, 0) - IF(s.NO_INDUK = ?, ?, 0), 0) AS paid_bangunan,
        GREATEST(COALESCE(s.SERAGAM_BAYAR, 0) - IF(s.NO_INDUK = ?, ?, 0), 0) AS paid_seragam,
        GREATEST(COALESCE(s.KEGIATAN_BAYAR, 0) - IF(s.NO_INDUK = ?, ?, 0), 0) AS paid_kegiatan,
        COALESCE(p.paid_makan, 0) AS paid_makan,
        COALESCE(p.paid_sorga, 0) AS paid_sorga,
        COALESCE(p.paid_infaq, 0) AS paid_infaq,
        COALESCE(du.paid_du, 0) AS paid_du,
        mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
    LEFT JOIN (
        SELECT
            NO_INDUK,
            SUM(U_MAKAN) AS paid_makan,
            SUM(U_SORGA) AS paid_sorga,
            SUM(U_INFAQ) AS paid_infaq
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
    WHERE s.is_active = 1 OR s.NO_INDUK = ?
    ORDER BY s.NAMA ASC
";
$stmt_siswa = $koneksi->prepare($siswa_sql);
$currentNis = (string)$d['NO_INDUK'];
$currentPangkal = (float)$d['U_PANGKAL'];
$currentBangunan = (float)$d['U_BANGUNAN'];
$currentSeragam = (float)$d['U_SERAGAM'];
$currentKegiatan = (float)$d['U_KEGIATAN'];
$stmt_siswa->bind_param(
    'sdsdsdsdiis',
    $currentNis, $currentPangkal,
    $currentNis, $currentBangunan,
    $currentNis, $currentSeragam,
    $currentNis, $currentKegiatan,
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

$currentPeriodKey = month_code($d['BULAN']) . '-' . $d['TAHUN'];
$d['kewajiban_spp'] = max(0, (float)$d['SPP_PERBULAN'] - (float)($period_payments[$d['NO_INDUK']]['spp'][$currentPeriodKey] ?? 0));

$du_bills = [];
$stmt_du_bills = $koneksi->prepare("SELECT tdu.id, tdu.no_induk, tdu.kelas_snapshot, tdu.tahun_ajaran_snapshot,
        tdu.nominal_tagihan, tdu.status, ta.status AS tahun_status,
        COALESCE(SUM(CASE WHEN bd.bayar_id IS NULL OR bd.bayar_id <> ? THEN bd.jumlah ELSE 0 END), 0) AS paid
    FROM tagihan_daftar_ulang tdu
    JOIN tahun_ajaran ta ON ta.id = tdu.tahun_ajaran_id
    LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
    GROUP BY tdu.id, tdu.no_induk, tdu.kelas_snapshot, tdu.tahun_ajaran_snapshot,
             tdu.nominal_tagihan, tdu.status, ta.status");
$stmt_du_bills->bind_param('i', $id);
$stmt_du_bills->execute();
$du_bill_result = $stmt_du_bills->get_result();
while ($bill = $du_bill_result->fetch_assoc()) {
    $du_bills[$bill['no_induk']][$bill['tahun_ajaran_snapshot']] = [
        'id' => (int)$bill['id'], 'kelas' => (string)$bill['kelas_snapshot'],
        'total' => (float)$bill['nominal_tagihan'], 'paid' => (float)$bill['paid'],
        'status' => (string)$bill['status'], 'tahun_status' => (string)$bill['tahun_status'],
    ];
}
$stmt_du_bills->close();

function active_academic_year_from_payment_period($bulan, $tahun): string {
    $month = (int)month_code($bulan);
    $year = (int)$tahun;
    if ($month < 1 || $month > 12 || $year < 2000) {
        return du_current_academic_year();
    }
    return du_academic_year_label($month, $year);
}

$selectedAcademicYear = active_academic_year_from_payment_period($d['BULAN'], $d['TAHUN']);

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
    $fallbackTotal = (float)$fallbackTotal;
    if ($fallbackTotal > 0) return $fallbackTotal;
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
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Edit data transaksi pembayaran siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=5.8" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
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

          <!-- Tanggal + Jumlah -->
          <div class="top-info-row">
            <div class="info-group">
              <div class="field-row">
                <label class="field-label" for="tgl-bayar">Tanggal Bayar</label>
                <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar"
                  value="<?= date('Y-m-d', strtotime($d['TGL_BYR'])) ?>" required />
              </div>
              <div class="field-row">
                <label class="field-label">Pembayaran Bulan</label>
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
                    $yearStart = min((int)date('Y'), $selectedYear);
                    $yearEnd = max((int)date('Y') + 10, $selectedYear);
                  ?>
                  <div class="payment-year-picker">
                    <input class="field-input payment-year-select" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4"
                      name="tahun_bayar" id="tahun-bayar" value="<?= htmlspecialchars((string)$d['TAHUN']) ?>" autocomplete="off" required />
                    <div class="payment-year-options" role="listbox" aria-label="Pilihan tahun pembayaran">
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
                  data-kelas="<?= htmlspecialchars(class_label(['tingkat'=>$s['master_tingkat']?:$s['KELAS'],'kode_rombel'=>$s['kode_rombel']??'BELUM','is_placeholder'=>$s['is_placeholder']??1])) ?>"
                  data-total-pangkal="<?= money_attr(total_after_discount($s['PANGKAL'], $s['potong_pangkal'], $s['tot_pangkal'])) ?>"
                  data-total-bangunan="<?= money_attr($s['BANGUNAN']) ?>"
                  data-total-seragam="<?= money_attr($s['SERAGAM']) ?>"
                  data-total-kegiatan="<?= money_attr($s['KEGIATAN']) ?>"
                  data-total-spp="<?= money_attr($s['SPP_PERBULAN']) ?>"
                  data-total-komite="<?= money_attr($s['POMG']) ?>"
                  data-total-makan="<?= money_attr($s['MAKAN']) ?>"
                  data-total-sorga="<?= money_attr($s['SORGA']) ?>"
                  data-total-infaq="<?= money_attr($s['INFAQ']) ?>"
                  data-paid-pangkal="<?= money_attr($s['paid_pangkal']) ?>"
                  data-paid-bangunan="<?= money_attr($s['paid_bangunan']) ?>"
                  data-paid-seragam="<?= money_attr($s['paid_seragam']) ?>"
                  data-paid-kegiatan="<?= money_attr($s['paid_kegiatan']) ?>"
                  data-paid-spp-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['spp'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-komite-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['komite'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-makan="<?= money_attr($s['paid_makan']) ?>"
                  data-paid-sorga="<?= money_attr($s['paid_sorga']) ?>"
                  data-paid-infaq="<?= money_attr($s['paid_infaq']) ?>"
                  data-du-bills="<?= htmlspecialchars(json_encode($du_bills[$s['NO_INDUK']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-biaya-lain-bills="<?= htmlspecialchars(json_encode($biaya_lain_bills[$s['NO_INDUK']] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($s['NAMA']) ?> (<?= htmlspecialchars(class_label(['tingkat'=>$s['master_tingkat']?:$s['KELAS'],'kode_rombel'=>$s['kode_rombel']??'BELUM','is_placeholder'=>$s['is_placeholder']??1])) ?>)
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
          <p class="payment-auto-note">Kolom total, sudah terbayar, dan sisa dihitung otomatis dari riwayat transaksi.</p>
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
                  ['bangunan', '🏗️ Uang Bangunan', 'U_BANGUNAN', 'uang_bangunan'],
                  ['seragam', '👔 Uang Seragam', 'U_SERAGAM', 'uang_seragam'],
                  ['kegiatan', '🎡 Uang Kegiatan', 'U_KEGIATAN', 'uang_kegiatan'],
                  ['spp', '🎓 Uang SPP', 'U_SPP', 'uang_spp'],
                  ['makan', '🍽️ Uang Makan', 'U_MAKAN', 'uang_makan'],
                  ['sorga', '🌅 Uang Sorga', 'U_SORGA', 'uang_sorga'],
                  ['infaq', '🕌 Uang Infaq', 'U_INFAQ', 'uang_infaq'],
                  ['du', '📚 Daftar Ulang', 'uang_du', 'uang_du']
                ];
                array_splice($komp, 5, 0, [[ 'komite', '🏫 Uang Komite', 'U_KOMITE', 'uang_komite' ]]);
                foreach ($komp as $i => [$key,$label,$col,$inputName]):
                ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td><span class="comp-label"><?=$label?></span><?php if(in_array($key,['makan','sorga','infaq'],true)): ?><small class="du-inline-context du-context-label" id="<?= $key ?>-context-label">Tagihan satu kali · dapat dicicil</small><?php endif; ?><?php if($key==='du'): ?><small class="du-inline-context du-context-label" id="du-context-label">Pilih siswa, bulan, dan tahun pembayaran.</small><small class="du-inline-context du-master-warning" id="du-master-warning" hidden></small><?php endif; ?></td>
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

          <!-- Penyesuaian SPP -->
          <div class="section-divider"><span>Penyesuaian SPP</span></div>
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label">Potongan SPP</label>
              <input class="field-input" type="text" name="potongan_spp" id="potongan-spp"
                value="<?= number_format((float)$d['potong_spp'], 0, ',', '.') ?>" />
            </div>
            <div class="field-row">
              <label class="field-label">Kewajiban SPP</label>
              <input class="field-input" type="text" name="kewajiban_spp" id="kewajiban-spp" readonly
                value="<?= number_format((float)$d['kewajiban_spp'], 0, ',', '.') ?>"
                style="background:rgba(99,102,241,0.08);color:var(--accent)" />
            </div>
          </div>

          <!-- Catatan -->
          <div class="section-divider"><span>Catatan</span></div>
          <textarea class="field-input field-textarea" name="catatan" maxlength="255"
            placeholder="Catatan..."><?= htmlspecialchars($d['KETERANGAN'] ?? '') ?></textarea>

          <!-- Action Buttons -->
          <div class="action-bar">
            <button type="submit" class="btn btn-warning" id="btn-update">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Update
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

  <script>
    window.sppDaftarUlangMasters = {};
    window.sppDaftarUlangHasMasters = true;
  </script>
  <script src="../assets/js/app.js?v=6.4"></script>
</body>
</html>

