<?php
// ============================================
// tabungan/keluar.php — Form Tabungan Keluar
// ============================================
require_once __DIR__ . '/../includes/security.php';
security_bootstrap_session();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/idempotency.php';
requireRole(['admin', 'kasir']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$rawPrefillNis = $_GET['nis'] ?? '';
$prefill_nis = preg_replace('/[^\w.-]/', '', is_scalar($rawPrefillNis) ? trim((string)$rawPrefillNis) : '');
$siswa_list = $koneksi->query("SELECT id, NO_INDUK, NO_induk_diknas, NAMA, KELAS FROM siswa WHERE is_active = 1 ORDER BY NAMA ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tabungan Keluar | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Form input tabungan keluar siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=5.8" />
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
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi" aria-expanded="false">
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
        <div class="main-card">
          <div class="card-header">
            <h3 class="card-title">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 17H18M12 22V2M7 7l5-5 5 5"/></svg>
              Input Tabungan Keluar
            </h3>
          </div>

          <form method="POST" action="proses.php" id="form-tabungan">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(security_csrf_token('savings'), ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars(idempotency_generate_key(), ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="aksi" value="keluar" />

            <div class="section-divider"><span>Data Siswa</span></div>
            <div class="fields-grid">
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
                  <option value="<?= htmlspecialchars($s['NO_INDUK']) ?> — <?= htmlspecialchars($s['NAMA']) ?>"
                    data-nis="<?= htmlspecialchars($s['NO_INDUK']) ?>"
                    data-diknas="<?= htmlspecialchars((string)($s['NO_induk_diknas'] ?? '')) ?>"
                    data-nama="<?= htmlspecialchars($s['NAMA']) ?>"
                    data-kelas="<?= htmlspecialchars($s['KELAS']) ?>">
                  <?php endwhile; ?>
                </datalist>
              </div>
              <div class="field-row">
                <label class="field-label">No. Induk</label>
                <input class="field-input" type="text" id="disp-nis" name="no_induk" readonly placeholder="Otomatis terisi" />
              </div>
              <div class="field-row">
                <label class="field-label">Nama Siswa</label>
                <input class="field-input" type="text" id="disp-nama" readonly placeholder="Otomatis terisi" />
              </div>
              <div class="field-row">
                <label class="field-label">Kelas</label>
                <input class="field-input" type="text" id="disp-kelas" readonly placeholder="Otomatis terisi" />
              </div>
            </div>

            <div class="section-divider"><span>Info Saldo</span></div>
            <div class="fields-grid">
              <div class="field-row">
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
                <input class="field-input" type="date" id="tgl-keluar" name="tanggal" required />
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
        </div>
      </div>
    </main>
  </div>

  <div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true"><span id="toast-icon" aria-hidden="true"></span><span id="toast-msg"></span></div>
  <div class="modal-overlay" id="modal-overlay" role="presentation">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-describedby="modal-body" tabindex="-1">
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
          const rawEl = document.getElementById('raw-saldo');
          if (rawEl) rawEl.value = saldo;
        }).catch(() => {});
    }
  </script>
</body>
</html>
