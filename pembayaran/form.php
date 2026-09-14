<?php
// ============================================
// pembayaran/form.php - Form Input Pembayaran
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
requireRole(['admin', 'kasir']);
$activeAcademicYear = du_current_academic_year();
$activeAcademicYearSql = $koneksi->real_escape_string($activeAcademicYear);

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
        GROUP BY NO_INDUK
    ) p ON p.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT no_induk, SUM(jumlah) AS paid_du
        FROM bayar_du
        GROUP BY no_induk
    ) du ON du.no_induk = s.NO_INDUK
    WHERE s.is_active = 1 OR (
        EXISTS(SELECT 1 FROM siswa_tahun_ajaran sta_l WHERE sta_l.no_induk=s.NO_INDUK AND sta_l.status='lulus')
        AND (EXISTS(
            SELECT 1 FROM tagihan_daftar_ulang tdu_o
            LEFT JOIN bayar_du bd_o ON bd_o.tagihan_daftar_ulang_id=tdu_o.id
            WHERE tdu_o.no_induk=s.NO_INDUK AND tdu_o.status='open'
              AND tdu_o.tahun_ajaran_snapshot<='$activeAcademicYearSql'
            GROUP BY tdu_o.id,tdu_o.nominal_tagihan
            HAVING tdu_o.nominal_tagihan-COALESCE(SUM(bd_o.jumlah),0)>.001
        ) OR EXISTS(SELECT 1 FROM tagihan_spp ts_o WHERE ts_o.no_induk=s.NO_INDUK AND ts_o.status='open'))
    )
    ORDER BY s.NAMA ASC
";
$siswa_list = $koneksi->query($siswa_sql);

$period_payments = [];
$spp_paid_result = $koneksi->query("
    SELECT NO_INDUK, BULAN, TAHUN, SUM(U_SPP) AS paid_spp, SUM(U_KOMITE) AS paid_komite
    FROM bayar
    GROUP BY NO_INDUK, BULAN, TAHUN
");
while ($paid = $spp_paid_result->fetch_assoc()) {
    $bulan_key = month_code($paid['BULAN']);
    $periodKey = $bulan_key . '-' . $paid['TAHUN'];
    $period_payments[$paid['NO_INDUK']]['spp'][$periodKey] =
        ($period_payments[$paid['NO_INDUK']]['spp'][$periodKey] ?? 0) + (float)$paid['paid_spp'];
    $period_payments[$paid['NO_INDUK']]['komite'][$periodKey] =
        ($period_payments[$paid['NO_INDUK']]['komite'][$periodKey] ?? 0) + (float)$paid['paid_komite'];
}

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
$published_spp_payload = spp_billing_schema_ready($koneksi) ? spp_payment_payload($koneksi) : [];

$du_bills = du_selectable_bills_payload($koneksi);

$annual_fee_payload = annual_fee_payload_for_options($koneksi);

$biaya_lain_bills = [];
$billResult = $koneksi->query("SELECT t.id,t.no_induk,t.master_biaya_lain_id,t.nama_snapshot nama,
    t.nominal_tagihan nominal,COALESCE(SUM(d.nominal_snapshot),0) paid
    FROM tagihan_biaya_lain t
    LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id
    WHERE t.status='open'
    GROUP BY t.id ORDER BY t.nama_snapshot");
while ($bill = $billResult->fetch_assoc()) {
    $bill['id']=(int)$bill['id']; $bill['master_id']=(int)$bill['master_biaya_lain_id'];
    $bill['nominal']=(float)$bill['nominal']; $bill['paid']=(float)$bill['paid'];
    $bill['sisa']=max(0,$bill['nominal']-$bill['paid']);
    if ($bill['sisa'] > .001) $biaya_lain_bills[$bill['no_induk']][]=$bill;
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

function money_attr($value) {
    return htmlspecialchars((string)(float)$value, ENT_QUOTES, 'UTF-8');
}

function total_after_discount($total, $discount, $fallbackTotal = 0) {
    $fallbackTotal = (float)$fallbackTotal;
    if ($fallbackTotal > 0) return $fallbackTotal;
    return max(0, (float)$total - (float)$discount);
}

// Flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Input Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <meta name="description" content="Form input transaksi pembayaran siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=9.9" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

  <div class="bg-orbs">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main -->
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Form Transaksi Pembayaran</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Input</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash && ($flash['scope'] ?? '') !== 'spp'): ?>
      <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($flash['type'] === 'success'): ?>
            <polyline points="20 6 9 17 4 12"/>
          <?php else: ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            Input Pembayaran Siswa
          </div>
        </div>

        <form method="POST" action="../pembayaran/proses.php" id="form-bayar">
          <input type="hidden" name="aksi" value="input" />

          <!-- Pengaturan transaksi + ringkasan tagihan -->
          <div class="top-info-row payment-input-top">
            <div class="info-group payment-settings-group" aria-label="Pengaturan transaksi">
              <div class="payment-panel-kicker">Pengaturan Transaksi</div>
              <input type="hidden" name="payment_plan" value="monthly" />
              <div class="payment-settings-grid">
                <div class="field-row payment-field-card">
                  <label class="field-label" for="tgl-bayar">
                    <span class="payment-field-icon" aria-hidden="true">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                    Tanggal Bayar
                  </label>
                  <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar"
                    value="<?= date('Y-m-d') ?>" readonly aria-readonly="true" required />
                </div>
                <div class="field-row payment-field-card payment-period-card">
                  <label class="field-label" for="bulan-bayar" id="payment-period-label">
                    <span class="payment-field-icon" aria-hidden="true">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg>
                    </span>
                    Pembayaran Bulan
                  </label>
                  <div class="field-group-inline payment-period-inputs">
                    <select class="field-input field-select month-code-select" id="bulan-bayar" name="bulan_bayar" required>
                      <?php
                      $month_labels = [
                          '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                          '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                          '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
                      ];
                      $cur = date('m');
                      foreach ($month_labels as $code => $label) {
                          echo "<option value=\"$code\" data-label=\"$label\"" . ($code === $cur ? ' selected' : '') . ">$label</option>";
                      }
                      ?>
                    </select>
                    <div class="payment-year-picker">
                      <input class="field-input payment-year-select" type="text" inputmode="numeric" pattern="\d{4}" maxlength="4"
                        id="tahun-bayar" name="tahun_bayar" value="<?= date('Y') ?>" autocomplete="off" required />
                      <div class="payment-year-options" role="listbox" aria-label="Pilihan tahun pembayaran">
                        <?php for ($y = (int)date('Y'); $y <= (int)date('Y') + 10; $y++): ?>
                        <button type="button" class="payment-year-option" data-year="<?= $y ?>" role="option"><?= $y ?></button>
                        <?php endfor; ?>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="field-row payment-field-card">
                  <label class="field-label" for="sistem-pembayaran">
                    <span class="payment-field-icon" aria-hidden="true">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    </span>
                    Sistem Pembayaran
                  </label>
                  <select class="field-input field-select" id="sistem-pembayaran" name="sistem_pembayaran" required>
                    <option value="Tunai">Tunai</option>
                    <option value="VA" selected>VA</option>
                    <option value="Qris">Qris</option>
                  </select>
                </div>
              </div>
            </div>
            <section class="payment-overview-panel" aria-label="Ringkasan pembayaran">
              <div class="payment-current-total">
                <div>
                  <span>Total Bayar</span>
                  <small>Nominal transaksi yang sedang diinput</small>
                </div>
                <strong id="totalJumlah">Rp 0</strong>
                <input type="hidden" name="total_jumlah" id="hidden-total" value="0" />
              </div>
            </section>
          </div>

          <!-- Data Siswa -->
          <div class="section-divider"><span>Data Siswa</span></div>
          <div class="fields-grid">
            <div class="field-row full-span">
              <label class="field-label" for="siswa-search">Cari Siswa (Nama / NIS / NIS Diknas)</label>
              <div class="search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="siswa-search" list="siswa-list" placeholder="Ketik nama, NIS, atau NIS Diknas..." oninput="pilihSiswaDatalist(this)" autocomplete="off" />
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
              <label class="field-label" for="disp-nis">No. Induk</label>
              <input class="field-input" type="text" id="disp-nis" name="no_induk" placeholder="Otomatis terisi" readonly required />
            </div>
            <div class="field-row">
              <label class="field-label" for="disp-nama">Nama Siswa</label>
              <input class="field-input" type="text" id="disp-nama" placeholder="Otomatis terisi" readonly />
            </div>
            <div class="field-row">
              <label class="field-label" for="disp-kelas">Kelas</label>
              <input class="field-input" type="text" id="disp-kelas" placeholder="Otomatis terisi" readonly />
            </div>
          </div>

          <!-- Rincian Pembayaran -->
          <div class="section-divider"><span>Rincian Pembayaran</span></div>
          <p class="payment-auto-note">SPP dialokasikan otomatis ke tagihan terbit yang paling lama. Periode transaksi dipakai oleh Komite; Daftar Ulang mengikuti tahun ajaran yang dipilih.</p>
          <section class="spp-deposit-banner" id="spp-deposit-banner" hidden aria-live="polite">
            <div><span>Saldo Titipan SPP</span><strong id="spp-deposit-balance">Rp 0</strong><small id="spp-deposit-capacity">Pilih siswa untuk melihat saldo.</small></div>
            <button type="button" class="btn btn-ghost" id="spp-use-deposit-button">Gunakan Titipan</button>
          </section>
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
                $komponen = [
                  ['pangkal', '💰 Uang Pangkal', 'uang_pangkal'],
                  ['psb', '🎒 Uang PSB', 'uang_psb'],
                  ['spp', '🎓 Uang SPP Diterima', 'uang_spp'],
                  ['komite', '🏫 Uang Komite', 'uang_komite'],
                  ['du', '📚 Daftar Ulang', 'uang_du']
                ];
                foreach ($komponen as $i => $k):
                  [$key, $label, $name] = $k;
                ?>
                <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                  <td><?php if($key==='du'): ?><div class="du-bill-selector"><span class="comp-label du-static-label" id="du-static-label"><?= $label ?></span><button type="button" class="du-selector-trigger" id="du-selector-trigger" aria-haspopup="listbox" aria-controls="du-selector-menu" aria-expanded="false" hidden><span class="du-trigger-label"><?= $label ?></span><span class="du-arrear-warning" id="du-arrear-warning" role="img"></span><span class="du-chevron" aria-hidden="true">⌄</span></button><div class="du-selector-menu" id="du-selector-menu" role="listbox" aria-label="Pilih tagihan Daftar Ulang" tabindex="-1" hidden></div></div><?php else: ?><span class="comp-label"<?= $key === 'spp' ? ' id="spp-component-label"' : '' ?>><?= $label ?></span><?php endif; ?><?php if($key==='spp'): ?><small class="du-inline-context du-context-label" id="spp-context-label">Lunasi tagihan tertua; dana yang belum teralokasi menjadi titipan</small><?php endif; ?><?php if($key==='komite'): ?><small class="du-inline-context du-context-label" id="komite-context-label">Tagihan tahunan bisa dicicil</small><?php endif; ?><?php if(in_array($key,['pangkal','psb'],true)): ?><small class="du-inline-context du-context-label">Tagihan satu kali, dapat dicicil</small><?php endif; ?><?php if($key==='du'): ?><small class="du-inline-context du-context-label" id="du-context-label">Pilih siswa untuk melihat tagihan.</small><small class="du-inline-context du-master-warning" id="du-master-warning" hidden></small><?php endif; ?></td>
                  <td data-label="Total Tagihan"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-total" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sudah Terbayar"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-bayar" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sisa"><input class="tbl-input tbl-system tbl-system-sisa" type="text" value="0" id="<?=$key?>-sisa" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Input Bayar"><input class="tbl-input tbl-pay" type="text" value="0"
                        id="<?=$key?>-input" name="<?= $name ?>" placeholder="0" /></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <input type="hidden" id="kelas-du" name="kelas_du" value="" />
          <input type="hidden" id="tahun-ajaran-du" name="tahun_ajaran_du" value="<?= htmlspecialchars($activeAcademicYear) ?>" />
          <input type="hidden" id="tagihan-daftar-ulang-id" name="tagihan_daftar_ulang_id" value="" />

          <!-- Lain-lain -->
          <div class="section-divider"><span>Lain-lain</span></div>
          <div class="alert alert-warning biaya-lain-overpaid-alert" id="biaya-lain-overpaid-alert" hidden></div>
          <div class="lainlain-grid" id="biaya-lain-list">
            <div class="lainlain-row biaya-lain-row">
              <span class="ll-num">1</span>
              <input type="hidden" name="biaya_lain_detail_id[]" value="" />
              <label class="ll-field">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_tagihan_id[]">
                  <option value="">-- Pilih Tagihan --</option>
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

          <input type="hidden" id="potongan-spp" name="potongan_spp" value="0" />
          <input type="hidden" id="gunakan-titipan-spp" name="gunakan_titipan_spp" value="0" />

          <input type="hidden" id="catatan" name="catatan" value="">
          <div class="section-divider"><span>History Transaksi Siswa</span></div>
          <div class="payment-history-panel" id="payment-history-panel">
            <div class="payment-history-head">
              <div>
                <strong>Riwayat pembayaran siswa</strong>
                <span id="payment-history-period">Pilih siswa untuk melihat transaksi.</span>
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
                  <tr><td colspan="6">Belum ada siswa dipilih.</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="action-bar">
            <button type="submit" class="btn btn-primary" id="btn-input">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              <span id="payment-submit-label">Simpan</span>
            </button>
            <button type="reset" class="btn btn-ghost" id="btn-reset" onclick="resetForm()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.59"/></svg>
              Reset
            </button>
            <a href="../pembayaran/lihat.php" class="btn btn-warning" id="btn-lihat">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Lihat Data
            </a>
            <a href="../dashboard.php" class="btn btn-ghost" id="btn-keluar">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              Keluar
            </a>
          </div>
        </form>
      </div>
    </main>
  </div>

  <?php include '../includes/spp_warning_modal.php'; ?>
  <div class="spp-deposit-modal" id="spp-deposit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="spp-deposit-modal-title">
    <div class="spp-deposit-modal-card"><h3 id="spp-deposit-modal-title">Pratinjau Penggunaan Titipan</h3><p id="spp-deposit-modal-summary"></p><div id="spp-deposit-modal-lines" class="spp-deposit-preview-lines"></div><div class="action-bar"><button type="button" class="btn btn-primary" id="spp-deposit-confirm">Konfirmasi Penggunaan</button><button type="button" class="btn btn-ghost" id="spp-deposit-cancel">Batal</button></div></div>
  </div>

  <script>
    window.sppDaftarUlangMasters = {};
    window.sppDaftarUlangHasMasters = true;
    window.sppPaymentHistoryUrl = 'history_siswa.php';
    window.sppOtherFeeBillsUrl = 'biaya_lain_siswa.php';
    window.sppPaymentStatusUrl = 'status_spp.php';
    window.sppPublishedBilling = true;
    window.sppFlashWarning = <?= json_encode(
      ($flash['scope'] ?? '') === 'spp' ? ($flash['spp_status'] ?? null) : null,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;
  </script>
  <script src="../assets/js/app.js?v=6.9"></script>
</body>
</html>

