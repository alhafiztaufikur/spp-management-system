<?php
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';
function modular_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
try{
    modular_assert(count(report_registry())===8,'Katalog tidak berisi delapan template.');
    $placeholders=(int)$koneksi->query("SELECT COUNT(*) total FROM master_kelas WHERE is_placeholder=1 AND is_active=1")->fetch_assoc()['total'];
    modular_assert($placeholders===6,'Placeholder kelas 1-6 tidak lengkap.');
    $missing=(int)$koneksi->query("SELECT COUNT(*) total FROM siswa WHERE KELAS IN ('1','2','3','4','5','6') AND master_kelas_id IS NULL")->fetch_assoc()['total'];
    modular_assert($missing===0,'Masih ada siswa SD tanpa Master Kelas.');
    $orphan=(int)$koneksi->query("SELECT COUNT(*) total FROM bayar_biaya_lain d LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id WHERE d.tagihan_biaya_lain_id IS NOT NULL AND t.id IS NULL")->fetch_assoc()['total'];
    modular_assert($orphan===0,'Ada detail Biaya Lain dengan tagihan yatim.');

    $filters=report_filters($koneksi,['tanggal_awal'=>'2026-08-01','tanggal_akhir'=>'2026-08-31','tahun_ajaran'=>'2026/2027','tahun'=>2026,'bulan_awal'=>'08','bulan_akhir'=>'08','kategori'=>'spp']);
    foreach(array_keys(report_registry()) as $template){$f=$filters;if(in_array($template,['penerimaan','setoran'],true))$f['kategori']='semua';if($template==='tabungan-siswa')$f['mode']='buku';$report=report_build($koneksi,$template,$f);modular_assert(isset($report['columns'],$report['rows'],$report['title']),'Kontrak data template '.$template.' tidak lengkap.');}
    $spp=report_spp_year_data($koneksi,$filters);$monthColumns=array_values(array_filter($spp['columns'],fn($column)=>($column[2]??'')==='html'));
    modular_assert(count($monthColumns)===12,'Rekap SPP tidak mempunyai 12 kolom Juli-Juni.');
    modular_assert(str_contains($monthColumns[0][1],'Juli')&&str_contains($monthColumns[11][1],'Juni'),'Urutan tahun ajaran SPP bukan Juli-Juni.');

    $components=report_payment_components($koneksi,$filters);$componentTotals=[];foreach($components as $row)$componentTotals[$row['id']]=($componentTotals[$row['id']]??0)+(float)$row['nominal'];
    foreach($componentTotals as $paymentId=>$total){$stmt=$koneksi->prepare('SELECT total_jumlah,payment_link_version FROM bayar WHERE id=?');$stmt->bind_param('i',$paymentId);$stmt->execute();$payment=$stmt->get_result()->fetch_assoc();$stmt->close();if((int)$payment['payment_link_version']===1)modular_assert(abs((float)$payment['total_jumlah']-$total)<.01,'Rincian penerimaan tidak sama dengan total transaksi #'.$paymentId.'.');}
    $settlement=report_settlement_data($koneksi,array_merge($filters,['kategori'=>'semua','metode'=>'','operator'=>'']));
    $rows=array_column($settlement['rows'],'nominal','bagian');$payment=($rows['Pembayaran Tunai']??0)+($rows['Pembayaran Virtual Account']??0)+($rows['Pembayaran QRIS']??0);$expected=$payment+($rows['Tabungan Masuk']??0)+($rows['Tabungan Keluar']??0);
    modular_assert(abs($expected-($rows['Total Bersih']??0))<.01,'Formula kas bersih setoran tidak seimbang.');
    $componentSummary=$settlement['component_summary']??[];
    $componentRows=$settlement['component_rows']??[];$componentLabels=array_column($componentRows,'komponen');
    modular_assert(in_array('Tabungan Masuk',$componentLabels,true)&&in_array('Tabungan Keluar',$componentLabels,true),'Rekap komponen tidak memuat mutasi tabungan default.');
    modular_assert(abs(array_sum(array_column($componentSummary,'nominal'))-(float)($settlement['payment_total']??0))<.01,'Total komponen pembayaran tidak seimbang.');
    modular_assert(abs($payment-(float)($settlement['payment_total']??0))<.01,'Rekap komponen pembayaran tidak sama dengan penerimaan berdasarkan metode.');
    modular_assert(abs(array_sum(array_column($componentRows,'nominal'))-(float)($settlement['component_total']??0))<.01,'Total tabel komponen dan tabungan tidak seimbang.');
    modular_assert(abs((float)($rows['Total Bersih']??0)-(float)($settlement['component_total']??0))<.01,'Total tabel komponen tidak sama dengan total bersih.');
    modular_assert(abs((float)($settlement['total_setoran']??0)-(float)($settlement['component_total']??0))<.01,'Total setoran tidak sama dengan total bersih.');
    modular_assert(!isset($settlement['details']),'Laporan setoran masih mengirim rincian transaksi yang tidak diperlukan.');
    $largeRows=array_map(fn($index)=>['nis'=>(string)$index],range(1,1001));$largePage=report_paginate($largeRows,array_merge($filters,['page'=>2,'per_page'=>100]),false);
    modular_assert($largePage['total']===1001&&$largePage['pages']===11&&$largePage['rows'][0]['nis']==='101','Pagination 1.000+ baris tidak stabil.');
    echo "OK: delapan template, Master Kelas, relasi tagihan, matriks SPP, penerimaan, rekap komponen, dan formula setoran tervalidasi.\n";
}catch(Throwable $error){fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);exit(1);}
