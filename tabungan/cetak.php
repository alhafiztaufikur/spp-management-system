<?php
session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'kasir', 'bendahara']);

$students = $koneksi->query("
    SELECT s.id, s.NO_INDUK, s.unit_id, s.NO_induk_diknas, s.NAMA, s.KELAS,
           COALESCE(t.SALDO, 0) AS saldo,
           COALESCE(m.jumlah, 0) + COALESCE(k.jumlah, 0) AS transaksi
    FROM siswa s
    LEFT JOIN tabungan t ON t.NO_INDUK = s.NO_INDUK AND t.unit_id=s.unit_id
    LEFT JOIN (SELECT unit_id,NO_INDUK, COUNT(*) AS jumlah FROM transaksi_m GROUP BY unit_id,NO_INDUK) m ON m.NO_INDUK = s.NO_INDUK AND m.unit_id=s.unit_id
    LEFT JOIN (SELECT unit_id,NO_INDUK, COUNT(*) AS jumlah FROM transaksi_k GROUP BY unit_id,NO_INDUK) k ON k.NO_INDUK = s.NO_INDUK AND k.unit_id=s.unit_id
    WHERE s.is_active = 1
    ORDER BY s.NAMA, s.NO_INDUK
")->fetch_all(MYSQLI_ASSOC);
$classes = array_values(array_unique(array_map(static fn(array $student): string => (string)$student['KELAS'], $students)));
sort($classes, SORT_NATURAL);

function print_book_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id" data-palette="<?= unit_palette_for_view(isset($reportUnitId) ? (int)$reportUnitId : null) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cetak Tabungan | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script>(function(){var t=localStorage.getItem('spp_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
  <link rel="stylesheet" href="../assets/css/date_controls.css?v=unitpalette4&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>">
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title"><h2>Cetak Tabungan</h2><span class="breadcrumb">SistemSPP / Tabungan / Cetak Buku</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <div class="page-content savings-print-page">
      <section class="savings-print-hero" aria-labelledby="savings-print-title">
        <div class="savings-print-hero-copy">
          <span class="savings-print-eyebrow">BUKU TABUNGAN SISWA</span>
          <h1 id="savings-print-title">Siapkan buku tabungan<br>untuk setiap siswa.</h1>
          <p>Pilih siswa, periksa ringkasan tabungannya, lalu buka pratinjau buku yang siap dicetak dan dilipat.</p>
          <div class="savings-print-hero-facts">
            <span><strong><?= number_format(count($students), 0, ',', '.') ?></strong> siswa aktif</span>
            <span><strong>A5</strong> kertas lanskap</span>
            <span><strong>A6</strong> ukuran buku</span>
          </div>
        </div>
        <div class="savings-print-visual" aria-hidden="true">
          <div class="savings-print-visual-page savings-print-visual-back"></div>
          <div class="savings-print-visual-page savings-print-visual-front">
            <div class="savings-print-visual-border">
              <img src="../assets/img/school-logo.png" alt="">
              <span>BUKU<br>TABUNGAN</span>
              <i></i><i></i><i></i>
            </div>
          </div>
        </div>
      </section>

      <div class="savings-print-workspace">
        <section class="main-card savings-print-selection" aria-labelledby="savings-print-selection-title">
          <div class="savings-print-card-heading"><span class="savings-print-step">01</span><div><span class="savings-print-kicker">PILIH SISWA</span><h2 id="savings-print-selection-title">Cari pemilik buku</h2></div></div>
          <p class="savings-print-card-copy">Cari berdasarkan nama, NIS, atau NIS Diknas. Pilih siswa dari daftar yang muncul.</p>

          <div class="savings-print-fields">
            <div class="field-row">
              <label class="field-label" for="savings-print-class">Kelas</label>
              <select class="field-input field-select" id="savings-print-class">
                <option value="">Semua kelas</option>
                <?php foreach ($classes as $class): ?>
                <option value="rombel:<?= print_book_escape($class) ?>">Kelas <?= print_book_escape($class) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="savings-print-search">Nama / NIS / NIS Diknas</label>
              <div class="search-box savings-print-search-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" id="savings-print-search" data-student-search data-student-list="savings-print-list" data-student-class-filter="#savings-print-class" data-student-select-callback="selectSavingsBookStudent" placeholder="Ketik nama atau nomor induk siswa..." autocomplete="off" <?= !$students ? 'disabled' : '' ?>>
              </div>
              <datalist id="savings-print-list">
                <?php foreach ($students as $student): ?>
                <option value="<?= print_book_escape($student['NAMA']) ?>" data-student-id="<?= (int)$student['id'] ?>" data-unit-id="<?= (int)$student['unit_id'] ?>" data-nis="<?= print_book_escape($student['NO_INDUK']) ?>" data-diknas="<?= print_book_escape($student['NO_induk_diknas'] ?? '') ?>" data-nama="<?= print_book_escape($student['NAMA']) ?>" data-kelas="<?= print_book_escape((unit_all_readonly()?unit_label((int)$student['unit_id']).' · ':'').$student['KELAS']) ?>" data-kelas-id="<?= print_book_escape($student['KELAS']) ?>" data-saldo="<?= print_book_escape($student['saldo']) ?>" data-transaksi="<?= (int)$student['transaksi'] ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>
          <div class="savings-print-help"><span class="savings-print-help-dot"></span><?= $students ? 'Pencarian akan menampilkan siswa yang sesuai dengan kelas pilihan.' : 'Belum ada siswa aktif yang bisa dipilih.' ?></div>
        </section>

        <section class="main-card savings-print-detail" aria-labelledby="savings-print-detail-title" aria-live="polite">
          <div class="savings-print-card-heading"><span class="savings-print-step">02</span><div><span class="savings-print-kicker">PRATINJAU BUKU</span><h2 id="savings-print-detail-title">Periksa sebelum mencetak</h2></div></div>
          <div class="savings-print-student-summary" id="savings-print-summary">
            <div class="savings-print-student-icon" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/><path d="M9 8h6M9 12h6"/></svg></div>
            <div><span class="savings-print-summary-label">BUKU UNTUK</span><strong id="savings-print-name">Pilih siswa terlebih dahulu</strong><small id="savings-print-nis">Data buku akan tampil di sini.</small></div>
          </div>
          <div class="savings-print-details-grid">
            <div><span>Kelas</span><strong id="savings-print-student-class">—</strong></div>
            <div><span>Saldo saat ini</span><strong id="savings-print-balance">—</strong></div>
            <div><span>Riwayat transaksi</span><strong id="savings-print-transactions">—</strong></div>
            <div><span>Format buku</span><strong>A6, 18 baris / halaman</strong></div>
          </div>
          <button type="button" class="btn btn-primary savings-print-action" id="savings-print-action" disabled>
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>
            Pratinjau &amp; Cetak
          </button>
          <p class="savings-print-action-note">PDF disusun pada kertas A5 lanskap untuk dicetak dua sisi dan dilipat menjadi buku A6.</p>
        </section>
      </div>

      <div class="savings-print-guide"><span class="savings-print-guide-icon">i</span><div><strong>Pengaturan cetak</strong><p>Gunakan kertas A5, skala 100%, cetak dua sisi, dan pilih pembalikan pada sisi pendek. Lipat lembar di tengah setelah dicetak.</p></div></div>
    </div>
  </main>
</div>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script>
(function () {
  const search = document.getElementById('savings-print-search');
  const classFilter = document.getElementById('savings-print-class');
  const action = document.getElementById('savings-print-action');
  const summary = document.getElementById('savings-print-summary');
  let selectedNis = ''; let selectedId = '';
  const rupiah = new Intl.NumberFormat('id-ID', {style: 'currency', currency: 'IDR', maximumFractionDigits: 0});

  function resetSelection() {
    selectedNis = '';
    action.disabled = true;
    summary.classList.remove('is-selected');
    document.getElementById('savings-print-name').textContent = 'Pilih siswa terlebih dahulu';
    document.getElementById('savings-print-nis').textContent = 'Data buku akan tampil di sini.';
    document.getElementById('savings-print-student-class').textContent = '—';
    document.getElementById('savings-print-balance').textContent = '—';
    document.getElementById('savings-print-transactions').textContent = '—';
  }

  window.selectSavingsBookStudent = function (input, option) {
    const nis = option.dataset.nis || '';
    if (!nis) { resetSelection(); return; }
    selectedNis = nis; selectedId=option.dataset.studentId || '';
    document.getElementById('savings-print-name').textContent = option.dataset.nama || option.value;
    document.getElementById('savings-print-nis').textContent = 'NIS ' + nis + (option.dataset.diknas ? ' · NIS Diknas ' + option.dataset.diknas : '');
    document.getElementById('savings-print-student-class').textContent = 'Kelas ' + (option.dataset.kelas || '-');
    document.getElementById('savings-print-balance').textContent = rupiah.format(Number(option.dataset.saldo || 0));
    document.getElementById('savings-print-transactions').textContent = Number(option.dataset.transaksi || 0).toLocaleString('id-ID') + ' transaksi';
    summary.classList.add('is-selected');
    action.disabled = false;
  };

  search.addEventListener('input', resetSelection);
  classFilter.addEventListener('change', function () { search.value = ''; resetSelection(); });
  action.addEventListener('click', function () {
    if (selectedNis) window.location.href = 'cetak_buku.php?nis=' + encodeURIComponent(selectedNis) + '&student_id=' + encodeURIComponent(selectedId);
  });
})();
</script>
</body>
</html>
