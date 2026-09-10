<?php

require_once __DIR__ . '/../includes/reports.php';

function billing_group_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function billing_group_row(string $nis, string $name, string $componentKey, string $component, string $period, string $periodCode, float $bill, float $paid, string $class = '2A', int $level = 2, string $academicYear = '2026/2027'): array {
    return [
        'nis' => $nis,
        'nis_diknas' => 'D-' . $nis,
        'nama' => $name,
        'kelas' => $class,
        'tingkat' => $level,
        'komponen_key' => $componentKey,
        'komponen' => $component,
        'periode' => $period,
        'periode_code' => $periodCode,
        'tahun_ajaran' => $academicYear,
        'tagihan' => $bill,
        'terbayar' => $paid,
        'sisa' => max(0, $bill - $paid),
        'status' => report_billing_status($bill, $paid),
    ];
}

try {
    $rows = [
        billing_group_row('002', 'Hafiz', 'spp', 'SPP', 'Agustus 2026', '2026-08', 100000, 0),
        billing_group_row('001', 'Lakchamana', 'spp', 'SPP', 'Agustus 2026', '2026-08', 100000, 50000),
        billing_group_row('001', 'Lakchamana', 'pangkal', 'Uang Pangkal', '2026/2027', '', 500000, 500000),
        billing_group_row('001', 'Lakchamana', 'spp', 'SPP', 'Juli 2026', '2026-07', 100000, 100000),
        billing_group_row('001', 'Lakchamana', 'daftar_ulang', 'Daftar Ulang', '2026/2027', '', 1000000, 250000),
        billing_group_row('001', 'Lakchamana', 'biaya_lain:9', 'Kunjungan Edukasi', 'Diterbitkan 10-08-2026', '', 200000, 0),
    ];

    report_billing_history_sort_rows($rows);
    billing_group_assert(array_column($rows, 'nis') === ['002', '001', '001', '001', '001', '001'], 'Urutan siswa tidak stabil berdasarkan kelas, nama, dan NIS.');
    $lakchamanaRows = array_values(array_filter($rows, static fn($row) => $row['nis'] === '001'));
    billing_group_assert(array_column($lakchamanaRows, 'komponen_key') === ['daftar_ulang', 'pangkal', 'biaya_lain:9', 'spp', 'spp'], 'Urutan bisnis komponen tagihan tidak sesuai.');
    billing_group_assert(array_column(array_slice($lakchamanaRows, 3, 2), 'periode_code') === ['2026-07', '2026-08'], 'Periode SPP tidak urut secara kronologis.');

    $groups = report_billing_history_group_students($rows);
    billing_group_assert(count($groups) === 2, 'Tagihan tidak terkelompok menjadi satu entri per siswa.');
    $groupsByNis = array_column($groups, null, 'nis');
    billing_group_assert($groupsByNis['001']['item_count'] === 5, 'Identitas atau jumlah rincian kelompok siswa salah.');
    billing_group_assert(abs($groupsByNis['001']['total_tagihan'] - 1900000) < .01, 'Total tagihan siswa salah.');
    billing_group_assert(abs($groupsByNis['001']['total_terbayar'] - 900000) < .01, 'Total terbayar siswa salah.');
    billing_group_assert(abs($groupsByNis['001']['total_sisa'] - 1000000) < .01, 'Total sisa siswa salah.');

    $baseFilters = ['kelas' => 'rombel:7', 'q' => ''];
    billing_group_assert(report_billing_history_uses_grouped_view($baseFilters, $rows), 'Filter rombel tidak mengaktifkan tampilan per siswa.');
    billing_group_assert(report_billing_history_uses_grouped_view(['kelas' => 'tingkat:2', 'q' => ''], $rows), 'Filter tingkat tidak mengaktifkan tampilan per siswa.');
    billing_group_assert(!report_billing_history_uses_grouped_view(['kelas' => '', 'q' => ''], $rows), 'Semua kelas tidak boleh memakai tampilan per siswa.');
    billing_group_assert(!report_billing_history_uses_grouped_view(['kelas' => 'rombel:7', 'q' => '001'], $rows), 'Siswa yang dipilih melalui NIS harus memakai tabel detail.');
    billing_group_assert(!report_billing_history_uses_grouped_view(['kelas' => 'tingkat:2', 'q' => 'D-001'], $rows), 'Siswa yang dipilih melalui NIS Diknas harus memakai tabel detail.');

    $page = report_paginate($groups, ['page' => 2, 'per_page' => 1], false);
    billing_group_assert($page['total'] === 2 && count($page['rows']) === 1 && $page['rows'][0]['nis'] === '001', 'Pagination per siswa masih memotong data berdasarkan rincian.');

    echo "OK: pengelompokan siswa, total, mode filter, pagination, dan urutan SPP tervalidasi.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
