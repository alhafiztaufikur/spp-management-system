<?php
// ============================================
// tabungan/keluar.php — Form Tabungan Keluar
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'kasir']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$prefill_nis = preg_replace('/[^\w.-]/', '', trim($_GET['nis'] ?? ''));
$today = date('Y-m-d');
$siswa_list = $koneksi->query("SELECT id, NO_INDUK, NO_induk_diknas, NAMA, KELAS FROM siswa WHERE is_active = 1 ORDER BY NAMA ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tabungan Keluar | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <meta name="description" content="Form input tabungan keluar siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=9.6" />
</head>
<body>
  <div class="bg-orbs">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Tabungan Keluar</h2>
          <span class="breadcrumb">SistemSPP / Tabungan / Keluar</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div id="flash-msg" class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin:16px 20px 0;">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <div class="page-content">
        <section class="main-card savings-entry-shell savings-entry-out">
          <div class="savings-entry-hero">
            <div>
              <span class="recap-class-overline">Tabungan Keluar</span>
              <h1>Input Penarikan Tabungan</h1>
              <p>Pilih siswa, pastikan saldo cukup, lalu catat nominal penarikan yang diberikan.</p>
            </div>
            <div class="savings-entry-panel is-warning">
              <span>Saldo Tersedia</span>
              <strong id="saldo-preview">Pilih siswa dulu</strong>
              <small>Penarikan akan ditolak jika nominal melebihi saldo.</small>
            </div>
          </div>

          <form method="POST" action="proses.php" id="form-tabungan" class="savings-entry-form">
            <input type="hidden" name="aksi" value="keluar" />

            <div class="section-divider"><span>Data Siswa</span></div>
            <div class="fields-grid savings-student-grid">
              <div class="field-row full-span">
                <label class="field-label" for="siswa-search">Cari Siswa (Nama / NIS / NIS Diknas)</label>
                <div class="search-box">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="siswa-search" list="siswa-list"
                    value="<?= htmlspecialchars($prefill_nis) ?>"
                    placeholder="Ketik nama, NIS, atau NIS Diknas..." oninput="pilihSiswaDatalist(this)" autocomplete="off" />
                </div>
                <datalist id="siswa-list">
                  <?php while ($s = $siswa_list->fetch_assoc()): ?>
                  <option value="<?= htmlspecialchars($s['NAMA']) ?>"
                    data-nis="<?= htmlspecialchars($s['NO_INDUK']) ?>"
                    data-diknas="<?= htmlspecialchars((string)($s['NO_induk_diknas'] ?? '')) ?>"
                    data-nama="<?= htmlspecialchars($s['NAMA']) ?>"
                    data-kelas="<?= htmlspecialchars($s['KELAS']) ?>">
                  <?php endwhile; ?>
                </datalist>
              </div>
              <div class="field-row savings-student-mini">
                <label class="field-label">No. Induk</label>
                <input class="field-input" type="text" id="disp-nis" name="no_induk" readonly placeholder="Otomatis terisi" />
              </div>
              <div class="field-row savings-student-mini is-name">
                <label class="field-label">Nama Siswa</label>
                <input class="field-input" type="text" id="disp-nama" readonly placeholder="Otomatis terisi" />
              </div>
              <div class="field-row savings-student-mini is-class">
                <label class="field-label">Kelas</label>
                <input class="field-input" type="text" id="disp-kelas" readonly placeholder="Otomatis terisi" />
              </div>
            </div>

            <div class="section-divider"><span>Info Saldo</span></div>
            <div class="fields-grid savings-balance-grid">
              <div class="field-row savings-balance-row">
                <label class="field-label">Saldo Tabungan Saat Ini</label>
                <input class="field-input" type="text" id="disp-saldo" readonly placeholder="Pilih siswa dulu"
                  style="background:rgba(99,102,241,0.08);color:var(--accent);font-weight:700;" />
                <input type="hidden" id="raw-saldo" value="0" />
              </div>
            </div>

            <div class="section-divider"><span>Rincian Penarikan</span></div>
            <div class="fields-grid">
              <div class="field-row">
                <label class="field-label" for="tgl-keluar">Tanggal</label>
                <input class="field-input" type="date" id="tgl-keluar" name="tanggal" value="<?= htmlspecialchars($today) ?>" readonly required />
              </div>
              <div class="field-row">
                <label class="field-label" for="nominal-keluar">Nominal Keluar (Rp)</label>
                <input class="field-input format-rupiah" type="text" id="nominal-keluar" name="nominal" placeholder="0" required />
              </div>
              <div class="field-row full-span">
                <label class="field-label" for="ket-keluar">Keterangan (opsional)</label>
                <input class="field-input" type="text" id="ket-keluar" name="keterangan" placeholder="Misal: Penarikan tunai, dll." />
              </div>
            </div>

            <div class="action-bar">
              <button type="submit" class="btn btn-primary" id="btn-simpan">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Simpan Penarikan
              </button>
              <a href="riwayat.php" class="btn btn-ghost">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Riwayat
              </a>
              <a href="masuk.php" class="btn btn-warning">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Tabungan Masuk
              </a>
            </div>
          </form>
        </section>
      </div>
    </main>
  </div>

  <div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
  <div class="modal-overlay" id="modal-overlay">
    <div class="modal-box">
      <h3 class="modal-title" id="modal-title"></h3>
      <p class="modal-body" id="modal-body"></p>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal()">Tutup</button>
      </div>
    </div>
  </div>

  <script src="../assets/js/app.js?v=6.2"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const tgl = document.getElementById('tgl-keluar');
      if (tgl && !tgl.value) tgl.value = new Date().toISOString().split('T')[0];

      document.querySelectorAll('.format-rupiah').forEach(function(input) {
        input.addEventListener('input', function () {
          const clean = this.value.replace(/\D/g, '');
          this.value = clean ? parseInt(clean).toLocaleString('id-ID') : '';
        });
      });

      // Validasi saldo sebelum submit
      document.getElementById('form-tabungan').addEventListener('submit', function (e) {
        const nominal = parseInt(document.getElementById('nominal-keluar').value.replace(/\./g, '') || 0);
        const saldo   = parseInt(document.getElementById('raw-saldo').value || 0);
        if (nominal > saldo) {
          e.preventDefault();
          alert('Nominal penarikan (Rp ' + nominal.toLocaleString('id-ID') + ') melebihi saldo tabungan (Rp ' + saldo.toLocaleString('id-ID') + ')!');
          return;
        }
        // Strip dots
        document.querySelectorAll('.format-rupiah').forEach(function(input) {
          input.value = input.value.replace(/\./g, '');
        });
      });

      autoHideFlash();

      const prefilledSearch = document.getElementById('siswa-search');
      if (prefilledSearch && prefilledSearch.value.trim()) {
        window.pilihSiswaDatalist(prefilledSearch);
      }
    });

    const _originalPilih = window.pilihSiswaDatalist;
    window.pilihSiswaDatalist = function (input) {
      _originalPilih(input);
      const nis = document.getElementById('disp-nis').value;
      if (nis) fetchSaldo(nis);
    };

    function fetchSaldo(nis) {
      fetch('../tabungan/get_saldo.php?nis=' + encodeURIComponent(nis))
        .then(r => r.json())
        .then(d => {
          const saldo = parseInt(d.saldo || 0);
          const saldoEl = document.getElementById('disp-saldo');
          if (saldoEl) saldoEl.value = 'Rp ' + saldo.toLocaleString('id-ID');
          const preview = document.getElementById('saldo-preview');
          if (preview) preview.textContent = 'Rp ' + saldo.toLocaleString('id-ID');
          const rawEl = document.getElementById('raw-saldo');
          if (rawEl) rawEl.value = saldo;
        }).catch(() => {});
    }
  </script>
</body>
</html>
