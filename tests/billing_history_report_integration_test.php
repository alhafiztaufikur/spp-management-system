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
        'template' => 'riwayat-tagihan',
        'kelas' => 'rombel:' . (int)$class['id'],
        'siswa_status' => 'all',
        'komponen_tagihan' => '',
        'status' => '',
        'q' => '',
        'page' => 1,
        'per_page' => 25,
    ]);
    $report = report_billing_history_data($koneksi, $filters);
    billing_report_assert($filters['tanggal_awal']===''&&$filters['tanggal_akhir']==='','Filter awal Riwayat Tagihan tidak menampilkan seluruh riwayat.');
    billing_report_assert($filters['periode_awal']===''&&$filters['periode_akhir']===''&&$filters['_billing_show_future'],
        'Semua bulan harus mencakup tagihan terbit sampai akhir tahun ajaran.');

    $groups = report_billing_history_group_students($report['rows']);
    $columns = report_billing_history_component_columns($groups);
    $detailKeys = array_values(array_unique(array_column($report['rows'], 'komponen_key')));
    $columnKeys = array_column($columns, 'komponen_key');
    sort($detailKeys, SORT_STRING);
    $sortedColumnKeys = $columnKeys;
    sort($sortedColumnKeys, SORT_STRING);
    billing_report_assert($detailKeys === $sortedColumnKeys, 'Kolom matriks tidak mencakup semua komponen hasil filter.');
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
        foreach (['tagihan'=>'total_tagihan','terbayar'=>'total_terbayar','sisa'=>'total_sisa'] as $itemKey=>$totalKey) {
            billing_report_assert(abs(array_sum(array_column($group['components'],$itemKey))-$group[$totalKey])<.01, 'Total komponen tidak cocok dengan total siswa.');
        }
        billing_report_assert(array_sum(array_column($group['components'],'item_count'))===$group['item_count'],'Ada tagihan yang hilang dari komponen.');
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
        $exactRows=report_billing_history_data($koneksi,$exactFilters)['rows'];
        billing_report_assert(count(report_billing_history_group_students($exactRows))===1,'Pencarian NIS tidak menampilkan satu siswa.');
    }
    if ($report['rows']) {
        $example=$report['rows'][0];
        $date=substr((string)$example['tanggal_dibuat'],0,10);
        $dateFilters=$filters;
        $dateFilters['tanggal_awal']=$date;
        $dateFilters['tanggal_akhir']=$date;
        $dateRows=report_billing_history_data($koneksi,$dateFilters)['rows'];
        billing_report_assert($dateRows!==[],'Tagihan pada tanggal batas tidak muncul.');
        billing_report_assert(count($dateRows)<=count($report['rows']),'Filter tanggal menambah jumlah tagihan.');
        $dateFilters['q']=(string)$example['nis'];
        $dateFilters['komponen_tagihan']=(string)$example['komponen_key'];
        $dateFilters['status']=report_status_key((string)$example['status']);
        $combinedRows=report_billing_history_data($koneksi,$dateFilters)['rows'];
        billing_report_assert($combinedRows!==[],'Kombinasi filter tanggal, siswa, komponen, dan status kehilangan tagihan.');
        foreach($combinedRows as $row){
            billing_report_assert((string)$row['nis']===(string)$example['nis']&&$row['komponen_key']===$example['komponen_key']&&substr((string)$row['tanggal_dibuat'],0,10)===$date&&report_status_key((string)$row['status'])===$dateFilters['status'],'Kombinasi filter mengembalikan tagihan yang tidak sesuai.');
        }
    }
    $approxRows=array_values(array_filter($report['rows'],static fn($row)=>(bool)($row['tanggal_perkiraan']??false)));
    if ($approxRows) {
        $approx=$approxRows[0];
        $studentDate=substr((string)$approx['tanggal_dibuat'],0,10);
        $approxFilters=$filters;
        $approxFilters['q']=(string)$approx['nis'];
        $approxFilters['komponen_tagihan']=(string)$approx['komponen_key'];
        $approxFilters['tanggal_awal']=$studentDate;
        $approxFilters['tanggal_akhir']=$studentDate;
        billing_report_assert(count(report_billing_history_data($koneksi,$approxFilters)['rows'])===1,'Uang Pangkal/PSB tidak mengikuti tanggal data siswa dibuat.');
        $previousDate=date('Y-m-d',strtotime($studentDate.' -1 day'));
        $approxFilters['tanggal_awal']=$previousDate;
        $approxFilters['tanggal_akhir']=$previousDate;
        billing_report_assert(report_billing_history_data($koneksi,$approxFilters)['rows']===[],'Uang Pangkal/PSB tampil di luar tanggal data siswa dibuat.');
    }

    echo "OK: laporan database read-only menjaga komponen, tanggal, total, identitas siswa, dan pagination.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
