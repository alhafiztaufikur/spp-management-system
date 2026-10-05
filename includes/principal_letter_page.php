<?php
if (!isset($report, $filters, $reportUnitId, $exportQuery, $classes, $classLevels, $koneksi)) {
    http_response_code(404);
    exit;
}

$filters['q'] = '';
$principalRows = $report['rows'] ?? [];
$principalStudentCount = array_sum(array_column($principalRows, 'jumlah_siswa'));
$principalTotal = array_sum(array_column($principalRows, 'total_tunggakan'));
$principalAverage = $principalStudentCount > 0 ? $principalTotal / $principalStudentCount : 0;
$principalDate = (string)($report['as_of_date'] ?? report_letter_today());
$scopeLabel = report_principal_scope_label($filters, $classes);
$scope = $reportUnitId === 0 ? 'all' : 'active';
$letterQuery = $exportQuery;
unset($letterQuery['view'], $letterQuery['detail'], $letterQuery['mode'], $letterQuery['nis'], $letterQuery['q'], $letterQuery['format'], $letterQuery['download']);
$letterQuery['template'] = 'tunggakan-siswa';
$letterQuery['unit'] = $scope;
$recapUrl = 'template.php?' . http_build_query($letterQuery);
$previewPageUrl = 'template.php?' . http_build_query(array_merge($letterQuery, ['view'=>'preview', 'mode'=>'filtered']));
$exportQueryBase = array_merge($letterQuery, ['mode'=>'filtered']);
$filteredPreview = 'export_global.php?' . http_build_query(array_merge($exportQueryBase, ['format'=>'preview']));
$allPreview = 'export_global.php?' . http_build_query(array_merge($exportQueryBase, ['format'=>'preview', 'mode'=>'all', 'kelas'=>'']));
$excelPreview = 'export_global.php?' . http_build_query(array_merge($exportQueryBase, ['format'=>'excel']));
$downloadPdf = 'export_global.php?' . http_build_query(array_merge($exportQueryBase, ['format'=>'pdf', 'download'=>'1']));

$view = (string)($_GET['view'] ?? 'summary');
if (!in_array($view, ['summary', 'detail', 'preview'], true)) $view = 'summary';
$principalDetailKey = static function (array $row): string {
    $classId = (int)($row['master_kelas_id'] ?? 0);
    return $classId > 0 ? 'rombel:' . $classId
        : 'kelas:' . (int)($row['tingkat'] ?? 0) . ':' . (string)($row['kelas'] ?? '');
};
$detailKey = (string)($_GET['detail'] ?? '');
$detailRow = null;
if ($view === 'detail' && $detailKey !== '') {
    foreach ($principalRows as $row) {
        if ($principalDetailKey($row) === $detailKey) {
            $detailRow = $row;
            break;
        }
    }
}
if ($view === 'detail' && !$detailRow) $view = 'summary';

$detailStudents = [];
$totalClassStudents = null;
if ($view === 'detail') {
    $allDebtors = report_student_debt_groups($koneksi, $filters, '', [], $principalDate);
    foreach ($allDebtors as $student) {
        if ((int)($student['master_kelas_id'] ?? 0) === (int)$detailRow['master_kelas_id']
            && (int)($student['unit_id'] ?? 0) === (int)($detailRow['unit_id'] ?? 0)
            && (int)($student['tingkat'] ?? 0) === (int)$detailRow['tingkat']
            && (string)($student['kelas'] ?? '') === (string)($detailRow['kelas_asli'] ?? $detailRow['kelas'])) {
            $detailStudents[] = $student;
        }
    }
    if ((int)$detailRow['master_kelas_id'] > 0) {
        $studentStatusWhere = $filters['siswa_status'] === 'active' ? ' AND is_active=1'
            : ($filters['siswa_status'] === 'archived' ? ' AND is_active=0' : '');
        $stmt = $koneksi->prepare('SELECT COUNT(*) total FROM siswa WHERE master_kelas_id=?' . $studentStatusWhere);
        $classId = (int)$detailRow['master_kelas_id'];
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $totalClassStudents = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}

$previewLetterHtml = '';
if ($view === 'preview' && $principalRows) {
    require_once __DIR__ . '/report_letters.php';
    $previewLetterHtml = report_principal_letter_html($principalRows, $principalDate, $scopeLabel);
}
?>
<!doctype html>
<html lang="id" data-palette="<?= report_e(unit_palette_for_view($reportUnitId)) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Surat Laporan ke Kepala Sekolah | SistemSPP</title>
    <link rel="icon" href="../assets/img/favicon.png?v=2">
    <link rel="stylesheet" href="../assets/css/style.css?v=principal-redesign1&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="../assets/css/date_controls.css?v=principal-redesign1&amp;mtime=<?= filemtime(__DIR__ . '/../assets/css/date_controls.css') ?>">
    <script>(function(){try{document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')}catch(e){}})();</script>
</head>
<body class="principal-report-page">
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout"><?php include __DIR__ . '/sidebar.php'; ?><main class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div class="topbar-title"><h2>Surat Laporan ke Kepala Sekolah</h2><span class="breadcrumb"><a href="surat_laporan.php">Surat Laporan</a> / Kepala Sekolah</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>
    <div class="letter-list-shell principal-list-shell">
        <a class="letter-back-link" href="surat_laporan.php">&larr; Kembali ke pilihan surat</a>

        <?php if ($view === 'summary'): ?>
        <header class="principal-page-header">
            <span class="recap-class-overline">SURAT KE KEPALA SEKOLAH · <?= report_e(unit_label($reportUnitId)) ?></span>
            <h1>Surat Laporan ke Kepala Sekolah</h1>
            <p>Rekap data tunggakan siswa sebagai bahan pembuatan surat resmi ke kepala sekolah.</p>
        </header>

        <?php if (!empty($report['error'])): ?><div class="principal-error" role="alert">Data rekap gagal dimuat. Silakan coba kembali.</div><?php endif; ?>
        <section class="principal-metrics" aria-label="Ringkasan tunggakan">
            <article class="principal-metric principal-metric--primary"><span class="principal-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="14" rx="3"/><path d="M7 6V4h10v2M12 10v6m-2-4h4"/></svg></span><div><span class="principal-metric-label">Total Tunggakan</span><strong class="principal-metric-value"><?= report_e(report_money($principalTotal)) ?></strong><small class="principal-metric-note"><?= number_format($principalStudentCount) ?> siswa menunggak</small></div></article>
            <article class="principal-metric"><span class="principal-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 4v16M12 9h5m-5 4h5"/></svg></span><div><span class="principal-metric-label">Rombel Menunggak</span><strong class="principal-metric-value"><?= number_format(count($principalRows)) ?></strong><small class="principal-metric-note">pada cakupan pilihan</small></div></article>
            <article class="principal-metric"><span class="principal-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 6a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5"/></svg></span><div><span class="principal-metric-label">Siswa Menunggak</span><strong class="principal-metric-value"><?= number_format($principalStudentCount) ?></strong><small class="principal-metric-note">status <?= report_e(['active'=>'aktif','archived'=>'arsip/lulus','all'=>'semua'][$filters['siswa_status']] ?? 'aktif') ?></small></div></article>
            <article class="principal-metric"><span class="principal-metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5m0 14h16M8 15l4-4 3 2 5-6"/></svg></span><div><span class="principal-metric-label">Rata-rata Tunggakan</span><strong class="principal-metric-value"><?= report_e(report_money($principalAverage)) ?></strong><small class="principal-metric-note">per siswa menunggak</small></div></article>
        </section>

        <section class="principal-panel principal-filter-card" aria-labelledby="principal-filter-title">
            <div class="principal-section-heading"><div><h2 id="principal-filter-title">Filter Data</h2><p>Pilih cakupan dan status siswa untuk memperbarui seluruh rekap.</p></div></div>
            <form method="get" class="principal-filter-form">
                <input type="hidden" name="template" value="tunggakan-siswa"><input type="hidden" name="unit" value="<?= report_e($scope) ?>">
                <div class="field-row"><label class="field-label" for="principal-class">Cakupan Kelas/Rombel</label><select class="field-input field-select" id="principal-class" name="kelas"><option value="">Seluruh Kelas/Rombel</option><?php foreach ($classLevels as $level): ?><option value="tingkat:<?= report_e($level) ?>" <?= $filters['kelas'] === 'tingkat:' . $level ? 'selected' : '' ?>>Seluruh Rombel Kelas <?= report_e($level) ?></option><?php endforeach; ?><?php foreach ($classes as $class): ?><option value="rombel:<?= (int)$class['id'] ?>" <?= $filters['kelas'] === 'rombel:' . (int)$class['id'] ? 'selected' : '' ?>>Rombel <?= report_e($class['label']) ?></option><?php endforeach; ?></select></div>
                <div class="field-row"><label class="field-label" for="principal-status">Status Siswa</label><select class="field-input field-select" id="principal-status" name="siswa_status"><?php foreach (['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['siswa_status'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="principal-filter-date"><span>Jumlah dihitung sampai</span><strong><?= report_e(report_date_label($principalDate)) ?></strong></div>
                <div class="principal-filter-actions"><button class="btn btn-primary" type="submit">Tampilkan Rekap</button><a class="btn btn-ghost" href="template.php?template=tunggakan-siswa&amp;unit=<?= report_e($scope) ?>">Reset</a></div>
            </form>
        </section>

        <section class="principal-panel principal-recap-card" aria-labelledby="principal-recap-title">
            <div class="principal-section-heading"><div><h2 id="principal-recap-title">Rekap Tunggakan per Kelas/Rombel</h2><p>Jumlah siswa menunggak dan total nominal per rombel pada <?= report_e($scopeLabel) ?>.</p></div><div class="principal-recap-tools"><label class="principal-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><span class="sr-only">Cari kelas atau rombel</span><input type="search" placeholder="Cari kelas/rombel..." aria-label="Cari kelas atau rombel" data-principal-search></label><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= report_e($excelPreview) ?>">Preview Excel</a></div></div>
            <p class="principal-search-note">Pencarian hanya menyaring baris di layar. Surat dan Excel mengikuti filter data di atas.</p>
            <div class="principal-table-scroll" role="region" tabindex="0" aria-label="Rekap tunggakan per rombel"><table class="data-table principal-summary-table"><thead><tr><th>No</th><th>Kelas/Rombel</th><th>Siswa Menunggak</th><th>Total Tunggakan</th><th>Porsi Tunggakan</th><th>Aksi</th></tr></thead><tbody>
                <?php foreach ($principalRows as $index=>$row): $percent = $principalTotal > 0 ? 100 * (float)$row['total_tunggakan'] / $principalTotal : 0; $detailUrl = 'template.php?' . http_build_query(array_merge($letterQuery, ['view'=>'detail', 'detail'=>$principalDetailKey($row)])); ?>
                <tr data-principal-row data-search-text="<?= report_e($row['kelas']) ?>"><td data-label="No"><?= $index + 1 ?></td><td data-label="Kelas/Rombel"><strong><?= report_e($row['kelas']) ?></strong></td><td data-label="Siswa Menunggak"><?= number_format((int)$row['jumlah_siswa']) ?> siswa</td><td data-label="Total Tunggakan" class="money"><?= report_e(report_money($row['total_tunggakan'])) ?></td><td data-label="Porsi Tunggakan"><div class="principal-progress"><span class="principal-progress-track"><span style="width:<?= max(0, min(100, $percent)) ?>%"></span></span><span><?= number_format($percent, 1, ',', '.') ?>%</span></div></td><td data-label="Aksi"><a class="principal-row-action" href="<?= report_e($detailUrl) ?>">Lihat Detail <span aria-hidden="true">&rsaquo;</span></a></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php if (!$principalRows): ?><div class="principal-empty">Tidak ada siswa yang memiliki tunggakan pada filter ini. <a href="template.php?template=tunggakan-siswa&amp;unit=<?= report_e($scope) ?>">Reset filter</a></div><?php endif; ?>
            <div class="principal-empty principal-search-empty" data-principal-search-empty hidden>Tidak ditemukan kelas/rombel dengan kata kunci tersebut.</div>
            <div class="principal-recap-footer"><span><strong><?= number_format(count($principalRows)) ?></strong> rombel menunggak</span><span><strong><?= number_format($principalStudentCount) ?></strong> siswa menunggak</span><span>Total <strong><?= report_e(report_money($principalTotal)) ?></strong></span></div>
        </section>

        <section class="principal-panel principal-preview-card"><div class="principal-section-heading"><div><h2>Surat untuk <?= report_e($scopeLabel) ?></h2><p>Dokumen resmi memuat total per rombel dan total pilihan, tanpa daftar nama siswa.</p></div><div class="principal-preview-actions"><a class="btn btn-primary <?= !$principalRows ? 'is-disabled' : '' ?>" <?= $principalRows ? '' : 'aria-disabled="true" tabindex="-1"' ?> href="<?= report_e($previewPageUrl) ?>">Pratinjau Surat Pilihan</a><?php if ($filters['kelas'] !== ''): ?><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= report_e($allPreview) ?>">Pratinjau Seluruh Kelas</a><?php endif; ?></div></div></section>

        <?php elseif ($view === 'detail'): ?>
        <header class="principal-page-header"><span class="recap-class-overline">DETAIL ROMBEL · <?= report_e(unit_label($reportUnitId)) ?></span><h1>Detail Tunggakan — <?= report_e($detailRow['kelas']) ?></h1><p>Daftar siswa yang mempunyai tunggakan pada rombel ini sampai <?= report_e(report_date_label($principalDate)) ?>.</p><a class="btn btn-ghost" href="<?= report_e($recapUrl) ?>">&larr; Kembali ke Rekap</a></header>
        <section class="principal-metrics principal-detail-metrics" aria-label="Ringkasan rombel"><article class="principal-metric"><span class="principal-metric-label">Total Siswa</span><strong class="principal-metric-value"><?= $totalClassStudents === null ? '—' : number_format($totalClassStudents) ?></strong><small class="principal-metric-note">sesuai status pilihan</small></article><article class="principal-metric"><span class="principal-metric-label">Siswa Menunggak</span><strong class="principal-metric-value"><?= number_format((int)$detailRow['jumlah_siswa']) ?></strong><small class="principal-metric-note">pada rombel ini</small></article><article class="principal-metric principal-metric--primary"><span class="principal-metric-label">Total Tunggakan</span><strong class="principal-metric-value"><?= report_e(report_money($detailRow['total_tunggakan'])) ?></strong><small class="principal-metric-note">hingga <?= report_e(report_date_label($principalDate)) ?></small></article></section>
        <section class="principal-panel principal-detail-card"><div class="principal-section-heading"><div><h2>Daftar Siswa Menunggak</h2><p>Rincian ini untuk pemeriksaan operator. Surat kepala sekolah tetap berupa rekap rombel.</p></div><div class="principal-recap-tools"><label class="principal-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><span class="sr-only">Cari nama atau NIS</span><input type="search" placeholder="Cari nama / NIS..." aria-label="Cari nama atau NIS" data-principal-search></label><?php if ((int)$detailRow['master_kelas_id'] > 0): $detailExcel = 'export_global.php?' . http_build_query(array_merge($letterQuery, ['format'=>'excel', 'download'=>'1', 'view'=>'detail', 'kelas'=>'rombel:' . (int)$detailRow['master_kelas_id']])); ?><a class="btn btn-ghost" href="<?= report_e($detailExcel) ?>">Export Excel</a><?php endif; ?></div></div><div class="principal-table-scroll" role="region" tabindex="0" aria-label="Daftar siswa menunggak"><table class="data-table principal-detail-table"><thead><tr><th>No</th><th>NIS</th><th>Nama Siswa</th><th>Jumlah Tunggakan</th><th>Periode Tagihan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
            <?php foreach ($detailStudents as $index=>$student): $periods = []; foreach ($student['items'] as $item) { $period = trim((string)($item['periode'] ?? '')); if ($period !== '') $periods[$period] = true; } $periodLabels = array_keys($periods); $periodText = implode(', ', array_slice($periodLabels, 0, 3)); if (count($periodLabels) > 3) $periodText .= ' +' . (count($periodLabels) - 3); $historyUrl = 'template.php?' . http_build_query(['template'=>'riwayat-tagihan', 'unit'=>$scope, 'siswa_status'=>$filters['siswa_status'], 'q'=>$student['nis']]); ?>
            <tr data-principal-row data-search-text="<?= report_e($student['nama'] . ' ' . $student['nis'] . ' ' . ($student['nis_diknas'] ?? '')) ?>"><td data-label="No"><?= $index + 1 ?></td><td data-label="NIS"><?= report_e($student['nis']) ?></td><td data-label="Nama Siswa"><strong><?= report_e($student['nama']) ?></strong></td><td data-label="Jumlah Tunggakan" class="money"><?= report_e(report_money($student['total_tunggakan'])) ?></td><td data-label="Periode Tagihan"><?= report_e($periodText !== '' ? $periodText : '—') ?></td><td data-label="Status"><span class="principal-status-badge">Menunggak</span></td><td data-label="Aksi"><a class="principal-row-action" href="<?= report_e($historyUrl) ?>">Riwayat Tagihan <span aria-hidden="true">&rsaquo;</span></a></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><div class="principal-empty principal-search-empty" data-principal-search-empty hidden>Tidak ditemukan siswa dengan kata kunci tersebut.</div></section>

        <?php else: ?>
        <header class="principal-page-header"><span class="recap-class-overline">PRATINJAU SURAT · <?= report_e(unit_label($reportUnitId)) ?></span><h1>Preview Surat Laporan</h1><p>Periksa isi surat untuk <?= report_e($scopeLabel) ?> sebelum mengunduh PDF.</p><a class="btn btn-ghost" href="<?= report_e($recapUrl) ?>">&larr; Kembali ke Rekap</a></header>
        <section class="principal-panel principal-preview-card"><div class="principal-section-heading"><div><h2>Surat Laporan ke Kepala Sekolah</h2><p>Format standar sekolah · <?= report_e(report_date_label($principalDate)) ?> · <?= number_format(count($principalRows)) ?> rombel</p></div><div class="principal-preview-actions"><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= report_e($filteredPreview) ?>">Buka Pratinjau Penuh</a><a class="btn btn-primary <?= !$principalRows ? 'is-disabled' : '' ?>" <?= $principalRows ? '' : 'aria-disabled="true" tabindex="-1"' ?> href="<?= report_e($downloadPdf) ?>">Download PDF</a></div></div><?php if ($previewLetterHtml !== ''): ?><div class="principal-preview-stage"><iframe class="principal-preview-frame" title="Pratinjau surat laporan kepala sekolah" srcdoc="<?= report_e($previewLetterHtml) ?>"></iframe></div><?php else: ?><div class="principal-empty">Tidak ada tunggakan pada pilihan ini. Pilih cakupan lain untuk membuat surat.</div><?php endif; ?></section>
        <?php endif; ?>
    </div>
</main></div>
<script src="../assets/js/date_format.js?v=<?= filemtime(__DIR__ . '/../assets/js/date_format.js') ?>"></script>
  <script src="../assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script src="../assets/js/principal_report.js?v=1" defer></script>
</body></html>
