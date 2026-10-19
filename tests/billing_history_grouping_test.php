<?php

require_once __DIR__ . '/../includes/units.php';
require_once __DIR__ . '/../includes/reports.php';

function billing_group_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function billing_group_row(string $nis, string $name, string $componentKey, string $component, string $period, string $periodCode, float $bill, float $paid, string $academicYear = '2026/2027'): array {
    return [
        'nis'=>$nis, 'nis_diknas'=>'D-'.$nis, 'nama'=>$name, 'kelas'=>'2A', 'tingkat'=>2,
        'komponen_key'=>$componentKey, 'komponen'=>$component, 'periode'=>$period,
        'periode_code'=>$periodCode, 'tahun_ajaran'=>$academicYear,
        'tagihan'=>$bill, 'terbayar'=>$paid, 'sisa'=>max(0,$bill-$paid),
        'status'=>report_billing_status($bill,$paid),
    ];
}

try {
    $rows = [
        billing_group_row('002','Hafiz','spp','SPP','Agustus 2026','2026-08',100000,0),
        billing_group_row('001','Lakchamana','spp','SPP','Agustus 2026','2026-08',100000,50000),
        billing_group_row('001','Lakchamana','psb','Uang PSB','Sekali saat masuk','',500000,500000),
        billing_group_row('001','Lakchamana','spp','SPP','Juli 2026','2026-07',100000,100000),
        billing_group_row('001','Lakchamana','spp','SPP','Juli 2027','2027-07',100000,0,'2027/2028'),
        billing_group_row('001','Lakchamana','daftar_ulang','Daftar Ulang','2026/2027','',1000000,250000),
        billing_group_row('001','Lakchamana','biaya_lain:9','Kunjungan Edukasi','Diterbitkan','',200000,0),
    ];
    report_billing_history_sort_rows($rows);
    $groups = report_billing_history_group_students($rows);
    billing_group_assert(count($groups)===2,'Harus ada satu kelompok per siswa.');
    $columns=report_billing_history_component_columns($groups);
    billing_group_assert(array_column($columns,'komponen_key')===['daftar_ulang','psb','biaya_lain:9','spp'],'Kolom matriks tidak mengikuti komponen dan urutan laporan.');
    $student=array_column($groups,null,'nis')['001'];
    $components=array_column($student['components'],null,'komponen_key');
    billing_group_assert(count($components)===4,'Jumlah komponen siswa tidak sesuai.');
    billing_group_assert($components['spp']['item_count']===3,'SPP dari beberapa bulan dan tahun tidak digabung.');
    billing_group_assert(abs($components['spp']['tagihan']-300000)<.01,'Total tagihan SPP salah.');
    billing_group_assert(abs($components['spp']['terbayar']-150000)<.01,'Total terbayar SPP salah.');
    billing_group_assert(abs($components['spp']['sisa']-150000)<.01,'Total sisa SPP salah.');
    foreach(['tagihan'=>'total_tagihan','terbayar'=>'total_terbayar','sisa'=>'total_sisa'] as $itemKey=>$totalKey){
        billing_group_assert(abs(array_sum(array_column($student['components'],$itemKey))-$student[$totalKey])<.01,'Total komponen dan siswa tidak sama: '.$itemKey);
    }
    billing_group_assert($student['item_count']===6,'Jumlah tagihan rinci siswa berubah.');
    $page=report_paginate($groups,['page'=>2,'per_page'=>1],false);
    billing_group_assert($page['total']===2&&count($page['rows'])===1&&$page['rows'][0]['nis']==='001','Pagination harus menghitung siswa.');

    billing_group_assert(report_billing_date_range([])===['',''],'Tanggal kosong harus berarti seluruh riwayat.');
    billing_group_assert(report_billing_date_range(['tanggal_awal'=>'2026-09-29','tanggal_akhir'=>'2026-09-01'])===['2026-09-01','2026-09-29'],'Rentang tanggal terbalik belum dinormalisasi.');
    billing_group_assert(report_billing_date_range(['tanggal_awal'=>'2026-09-29'])===['2026-09-29','2026-09-29'],'Satu tanggal harus menjadi rentang satu hari.');
    billing_group_assert(report_billing_date_range(['tanggal_awal'=>'2026-02-30'])===['',''],'Tanggal tidak valid harus ditolak.');
    $dateFilter=['filter_tanggal_tagihan'=>true,'tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-09-29'];
    billing_group_assert(report_billing_date_matches('2026-09-01 00:00:00',$dateFilter),'Batas awal tidak inklusif.');
    billing_group_assert(report_billing_date_matches('2026-09-29 23:59:59',$dateFilter),'Batas akhir tidak inklusif.');
    billing_group_assert(!report_billing_date_matches('2026-09-30 00:00:00',$dateFilter),'Tagihan di luar rentang masih tampil.');
    billing_group_assert(report_billing_date_matches('2026-09-30 00:00:00',['filter_tanggal_tagihan'=>false]+$dateFilter),'Filter tanggal Riwayat Tagihan memengaruhi laporan lain.');

    $statusRows=array_map(static fn($row)=>$row+['_status'=>$row['status']],$rows);
    $cicilan=report_filter_rows($statusRows,['status'=>'cicilan','q'=>'001']);
    billing_group_assert(count($cicilan)===2,'Filter status harus diterapkan ke tagihan sebelum pengelompokan.');
    echo "OK: komponen, total, tanggal, status, dan pagination per siswa tervalidasi.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
