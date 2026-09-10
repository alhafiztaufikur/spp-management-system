<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';

function billing_report_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY tingkat,kode_rombel LIMIT 1")->fetch_assoc();
    if (!$class) {
        echo "SKIPPED: belum ada rombel aktif non-placeholder untuk integration test read-only.\n";
        exit(0);
    }

    $filters = report_filters($koneksi, [
        'kelas' => 'rombel:' . (int)$class['id'],
        'siswa_status' => 'all',
        'tahun_tagihan' => '',
        'komponen_tagihan' => '',
        'status' => '',
        'q' => '',
        'page' => 1,
        'per_page' => 25,
    ]);
    $report = report_billing_history_data($koneksi, $filters);
    billing_report_assert(report_billing_history_uses_grouped_view($filters, $report['rows']), 'Filter rombel nyata tidak mengaktifkan mode kelompok.');

    $groups = report_billing_history_group_students($report['rows']);
    $detailBill = array_sum(array_map(static fn($row) => (float)$row['tagihan'], $report['rows']));
    $detailPaid = array_sum(array_map(static fn($row) => (float)$row['terbayar'], $report['rows']));
    $detailRemaining = array_sum(array_map(static fn($row) => (float)$row['sisa'], $report['rows']));
    $groupBill = array_sum(array_column($groups, 'total_tagihan'));
    $groupPaid = array_sum(array_column($groups, 'total_terbayar'));
    $groupRemaining = array_sum(array_column($groups, 'total_sisa'));

    billing_report_assert(abs($detailBill - $groupBill) < .01, 'Total tagihan berubah setelah pengelompokan.');
    billing_report_assert(abs($detailPaid - $groupPaid) < .01, 'Total terbayar berubah setelah pengelompokan.');
    billing_report_assert(abs($detailRemaining - $groupRemaining) < .01, 'Total sisa berubah setelah pengelompokan.');
    billing_report_assert(count(array_unique(array_column($groups, 'nis'))) === count($groups), 'Satu siswa muncul pada lebih dari satu kelompok.');
    billing_report_assert(array_sum(array_column($groups, 'item_count')) === count($report['rows']), 'Ada rincian yang hilang atau terhitung ganda.');

    foreach ($groups as $group) {
        $sppPeriods = array_values(array_map(
            static fn($row) => (string)($row['periode_code'] ?? ''),
            array_filter($group['items'], static fn($row) => ($row['komponen_key'] ?? '') === 'spp')
        ));
        $expectedPeriods = $sppPeriods;
        sort($expectedPeriods, SORT_STRING);
        billing_report_assert($sppPeriods === $expectedPeriods, 'Periode SPP siswa tidak kronologis.');
    }

    $page = report_paginate($groups, ['page' => 1, 'per_page' => 25], false);
    billing_report_assert(count($page['rows']) <= 25 && $page['total'] === count($groups), 'Pagination kelompok tidak menghitung siswa.');
    if ($groups) {
        $exactFilters = $filters;
        $exactFilters['q'] = (string)$groups[0]['nis'];
        billing_report_assert(!report_billing_history_uses_grouped_view($exactFilters, $report['rows']), 'Filter satu siswa tidak kembali ke mode detail.');
    }

    echo "OK: laporan database read-only menjaga jumlah rincian, total, identitas siswa, pagination, dan urutan SPP.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
