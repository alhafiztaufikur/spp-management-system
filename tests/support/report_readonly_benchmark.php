<?php

require_once __DIR__ . '/../../koneksi.php';
require_once __DIR__ . '/assert_audit_database.php';
require_once __DIR__ . '/../../includes/reports.php';

$databaseName = test_require_audit_database($koneksi);
$filters = report_filters($koneksi, [
    'tanggal_awal' => '2026-07-01',
    'tanggal_akhir' => '2026-08-31',
    'tahun_ajaran' => '2026/2027',
    'tahun' => 2026,
    'bulan_awal' => '07',
    'bulan_akhir' => '08',
    'kategori' => 'spp',
    'siswa_status' => 'all',
    'per_page' => 100,
]);

echo "database\ttemplate\trows\tselects\twall_ms\tpeak_delta_bytes\n";
foreach (array_keys(report_registry()) as $template) {
    $templateFilters = $filters;
    if (in_array($template, ['penerimaan', 'setoran'], true)) $templateFilters['kategori'] = 'semua';
    if ($template === 'tabungan-siswa') $templateFilters['mode'] = 'buku';

    $beforeSelects = (int)$koneksi->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
    $beforePeak = memory_get_peak_usage(true);
    $started = hrtime(true);
    $report = report_build($koneksi, $template, $templateFilters);
    $wallMs = (hrtime(true) - $started) / 1_000_000;
    $afterPeak = memory_get_peak_usage(true);
    $afterSelects = (int)$koneksi->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];

    printf(
        "%s\t%s\t%d\t%d\t%.3f\t%d\n",
        $databaseName,
        $template,
        count($report['rows']),
        $afterSelects - $beforeSelects,
        $wallMs,
        max(0, $afterPeak - $beforePeak)
    );
}
