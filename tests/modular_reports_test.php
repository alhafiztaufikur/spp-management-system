<?php
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';
function modular_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
try{
    modular_assert(count(report_registry())===10,'Katalog tidak berisi sepuluh template.');
    $missing=(int)$koneksi->query("SELECT COUNT(*) total FROM siswa WHERE KELAS IN ('1','2','3','4','5','6') AND master_kelas_id IS NULL")->fetch_assoc()['total'];
    modular_assert($missing===0,'Masih ada siswa SD tanpa Master Kelas.');
    $orphan=(int)$koneksi->query("SELECT COUNT(*) total FROM bayar_biaya_lain d LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id WHERE d.tagihan_biaya_lain_id IS NOT NULL AND t.id IS NULL")->fetch_assoc()['total'];
    modular_assert($orphan===0,'Ada detail Biaya Lain dengan tagihan yatim.');

    $filters=report_filters($koneksi,['tanggal_awal'=>'2026-08-01','tanggal_akhir'=>'2026-08-31','tahun_ajaran'=>'2026/2027','tahun'=>2026,'bulan_awal'=>'08','bulan_akhir'=>'08','kategori'=>'spp']);
    $categories=report_categories($koneksi);
    modular_assert(isset($categories['psb'])&&$categories['psb']==='Uang PSB','Kategori laporan Uang PSB belum tersedia.');
    foreach(['bangunan','seragam','kegiatan','makan','sorga','infaq'] as $removedCategory)modular_assert(!isset($categories[$removedCategory]),'Kategori lama masih tersedia: '.$removedCategory.'.');
    modular_assert(array_keys(one_time_fee_components())===['pangkal','psb'],'Registry tagihan satu kali harus hanya memuat Pangkal dan PSB.');
    foreach(array_keys(report_registry()) as $template){$f=$filters;if(in_array($template,['penerimaan','setoran'],true))$f['kategori']='semua';if($template==='tabungan-siswa')$f['mode']='buku';$report=report_build($koneksi,$template,$f);modular_assert(isset($report['columns'],$report['rows'],$report['title']),'Kontrak data template '.$template.' tidak lengkap.');}
    $psbStatus=report_status_data($koneksi,array_merge($filters,['kategori'=>'psb']));
    modular_assert($psbStatus['title']==='Uang PSB','Status pembayaran PSB memakai judul yang salah.');
    foreach($psbStatus['rows'] as $row)modular_assert($row['periode']==='Sekali saat masuk','Periode status PSB bukan sekali saat masuk.');
    $komiteStatus=report_status_data($koneksi,array_merge($filters,['kategori'=>'komite']));
    foreach($komiteStatus['rows'] as $row)modular_assert($row['periode']==='Agustus 2026','Status Komite tidak memakai bulan tagihan.');
    $komiteItem=report_item_data($koneksi,array_merge($filters,['kategori'=>'komite','tahun_awal'=>2026,'tahun_akhir'=>2026]));
    modular_assert(str_contains($komiteItem['subtitle'],'Agustus 2026'),'Rekap per item Komite tidak memakai periode bulanan.');
    $spp=report_spp_year_data($koneksi,$filters);$monthColumns=array_values(array_filter($spp['columns'],fn($column)=>($column[2]??'')==='html'));
    modular_assert(count($monthColumns)===12,'Rekap SPP tidak mempunyai 12 kolom Juli-Juni.');
    modular_assert(str_contains($monthColumns[0][1],'Juli')&&str_contains($monthColumns[11][1],'Juni'),'Urutan tahun ajaran SPP bukan Juli-Juni.');

    $components=report_payment_components($koneksi,$filters);$componentTotals=[];foreach($components as $row)$componentTotals[$row['id']]=($componentTotals[$row['id']]??0)+(float)$row['nominal'];
    foreach($componentTotals as $paymentId=>$total){$stmt=$koneksi->prepare('SELECT total_jumlah,payment_link_version FROM bayar WHERE id=?');$stmt->bind_param('i',$paymentId);$stmt->execute();$payment=$stmt->get_result()->fetch_assoc();$stmt->close();if((int)$payment['payment_link_version']===1)modular_assert(abs((float)$payment['total_jumlah']-$total)<.01,'Rincian penerimaan tidak sama dengan total transaksi #'.$paymentId.'.');}
    $settlement=report_settlement_data($koneksi,array_merge($filters,['kategori'=>'semua','metode'=>'','operator'=>'']));
    $rows=array_column($settlement['rows'],'nominal','bagian');$payment=($rows['Pembayaran Tunai']??0)+($rows['Pembayaran Virtual Account']??0)+($rows['Pembayaran QRIS']??0);
    modular_assert(abs($payment-($rows['Total Setoran']??0))<.01,'Formula total setoran pembayaran tidak seimbang.');
    $componentSummary=$settlement['component_summary']??[];
    $componentRows=$settlement['component_rows']??[];$componentLabels=array_column($componentRows,'komponen');
    modular_assert(!in_array('Tabungan Masuk',$componentLabels,true)&&!in_array('Tabungan Keluar',$componentLabels,true),'Rekap setoran masih mencampur mutasi tabungan.');
    modular_assert(abs(array_sum(array_column($componentSummary,'nominal'))-(float)($settlement['payment_total']??0))<.01,'Total komponen pembayaran tidak seimbang.');
    modular_assert(abs($payment-(float)($settlement['payment_total']??0))<.01,'Rekap komponen pembayaran tidak sama dengan penerimaan berdasarkan metode.');
    modular_assert(abs(array_sum(array_column($componentRows,'nominal'))-(float)($settlement['component_total']??0))<.01,'Total tabel komponen pembayaran tidak seimbang.');
    modular_assert(abs((float)($rows['Total Setoran']??0)-(float)($settlement['component_total']??0))<.01,'Total tabel komponen tidak sama dengan total setoran.');
    modular_assert(abs((float)($settlement['total_setoran']??0)-(float)($settlement['component_total']??0))<.01,'Total setoran tidak sama dengan total pembayaran.');
    modular_assert(!isset($settlement['details']),'Laporan setoran masih mengirim rincian transaksi yang tidak diperlukan.');

    $emptySavings=report_savings_cash_summary([]);
    modular_assert($emptySavings['total_masuk']===0.0&&$emptySavings['total_keluar']===0.0&&$emptySavings['mutasi_bersih']===0.0&&$emptySavings['count_total']===0,'Ringkasan tabungan kosong tidak bernilai nol.');
    $incomingSavings=report_savings_cash_summary([['masuk'=>125000,'keluar'=>0],['masuk'=>75000,'keluar'=>0]]);
    modular_assert($incomingSavings['total_masuk']===200000.0&&$incomingSavings['mutasi_bersih']===200000.0&&$incomingSavings['count_masuk']===2&&$incomingSavings['count_total']===2,'Skenario tabungan masuk saja tidak valid.');
    $outgoingSavings=report_savings_cash_summary([['masuk'=>0,'keluar'=>90000]]);
    modular_assert($outgoingSavings['total_keluar']===90000.0&&$outgoingSavings['mutasi_bersih']===-90000.0&&$outgoingSavings['count_keluar']===1,'Skenario tabungan keluar dan mutasi negatif tidak valid.');
    $savingCash=report_savings_cash_data($koneksi,array_merge($filters,['operator'=>'']));
    $savingSource=report_savings_transactions($koneksi,$filters['tanggal_awal'].' 00:00:00',date('Y-m-d H:i:s',strtotime($filters['tanggal_akhir'].' +1 day')),array_merge($filters,['operator'=>'']));
    $savingExpected=report_savings_cash_summary($savingSource);
    foreach(['total_masuk','total_keluar','mutasi_bersih'] as $key)modular_assert(abs((float)$savingCash[$key]-(float)$savingExpected[$key])<.01,'Rekap kas tabungan tidak cocok dengan jurnal pada '.$key.'.');
    modular_assert((int)$savingCash['settlement']['transaction_count']===(int)$savingExpected['count_total'],'Jumlah transaksi Rekap Kas Tabungan tidak cocok.');
    modular_assert(array_column($savingCash['component_rows'],'komponen')===['Tabungan Masuk','Tabungan Keluar'],'Rekap Kas Tabungan memuat komponen selain tabungan.');
    modular_assert(!isset($savingCash['details']),'Rekap Kas Tabungan masih mengirim rincian transaksi siswa.');
    $largeRows=array_map(fn($index)=>['nis'=>(string)$index],range(1,1001));$largePage=report_paginate($largeRows,array_merge($filters,['page'=>2,'per_page'=>100]),false);
    modular_assert($largePage['total']===1001&&$largePage['pages']===11&&$largePage['rows'][0]['nis']==='101','Pagination 1.000+ baris tidak stabil.');
    echo "OK: sepuluh template, Master Kelas, relasi tagihan, matriks SPP, rekap kas pembayaran, rekap kas tabungan, dan Titipan SPP tervalidasi.\n";
}catch(Throwable $error){fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);exit(1);}
