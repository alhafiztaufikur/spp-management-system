<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/savings_workspace.php';
header('Cache-Control: private, no-store');
if (empty($_SESSION['admin_id'])) {http_response_code(401);exit('Silakan masuk kembali.');}
if (!hasRole(['admin','kasir','bendahara'])) {http_response_code(403);exit('Akses tidak diizinkan.');}
if ($_SERVER['REQUEST_METHOD']!=='GET') {http_response_code(405);header('Allow: GET');exit('Gunakan GET.');}
try {[$kind,$id]=savings_http_identity();$row=savings_transaction($koneksi,$kind,$id);}
catch (InvalidArgumentException $e) {http_response_code(400);exit(savings_e($e->getMessage()));}
if (!$row) {http_response_code(404);exit('Transaksi tidak ditemukan dalam cakupan unit Anda.');}
if (!$row['can_print']) {http_response_code(403);exit('Anda hanya dapat mencetak transaksi tabungan milik sendiri.');}
function savings_words(int $n): string {
    $w=['','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan','sepuluh','sebelas'];
    if($n<12)return $w[$n];if($n<20)return savings_words($n-10).' belas';
    if($n<100)return trim(savings_words(intdiv($n,10)).' puluh '.savings_words($n%10));
    if($n<200)return trim('seratus '.savings_words($n-100));
    if($n<1000)return trim(savings_words(intdiv($n,100)).' ratus '.savings_words($n%100));
    if($n<2000)return trim('seribu '.savings_words($n-1000));
    foreach([1000000000000=>'triliun',1000000000=>'miliar',1000000=>'juta',1000=>'ribu'] as $base=>$label)if($n>=$base)return trim(savings_words(intdiv($n,$base)).' '.$label.' '.savings_words($n%$base));
    return '';
}
$amount=$kind==='masuk'?(float)$row['nominal']:(float)$row['keluar'];
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Struk Tabungan <?= savings_e($row['reference']) ?></title><link rel="icon" href="../assets/img/favicon.png"><style>
@page{size:A5 landscape;margin:0}*{box-sizing:border-box}body{margin:0;background:#edf3f0;font:12px/1.5 Arial,sans-serif;color:#1d3028}.receipt{width:210mm;min-height:148mm;margin:20px auto;padding:10mm;background:white;border:1px solid #d8e4dd}.tools{max-width:210mm;margin:20px auto;display:flex;gap:10px;padding:0 10px}.tools a,.tools button{border:1px solid #c9ded1;border-radius:24px;background:white;color:#176b43;padding:10px 18px;text-decoration:none;font:600 14px Arial;cursor:pointer}.tools button{background:#128849;color:white}.head{display:flex;align-items:center;gap:20px;border-bottom:2px solid #28543e;padding-bottom:12px}.head img{width:54px;height:54px}.head h1{font-size:17px;margin:0}.head p{margin:3px 0;font-size:11px}.title{text-align:center;margin:14px 0 12px}.title h2{margin:0;font-size:18px}.identity{display:grid;grid-template-columns:1fr 1fr;gap:8px 22px}.identity div{display:flex;gap:10px}.identity span{width:100px;color:#53665c}.identity strong{flex:1;overflow-wrap:anywhere}.amount{border:1px solid #b8d3c2;background:#f3f8f5;padding:12px 16px;margin:15px 0}.amount strong{font-size:24px}.amount p{margin:4px 0;font-style:italic}.note{white-space:pre-wrap;overflow-wrap:anywhere}.signs{display:flex;justify-content:space-between;text-align:center;margin-top:20px}.signs>div{width:42%}.signs .line{margin-top:36px;border-bottom:1px solid #555;padding:0 8px 4px}.foot{font-size:10px;color:#667a70;border-top:1px solid #ddd;margin-top:14px;padding-top:7px}@media(max-width:850px){.receipt{width:calc(100% - 24px);padding:20px}.identity{grid-template-columns:1fr}}@media print{body{background:white}.tools{display:none}.receipt{margin:0;border:0;width:210mm;min-height:148mm;padding:9mm}.identity{grid-template-columns:1fr 1fr}}
@media print{body{font-size:11px;line-height:1.3}.receipt{padding:7mm}.head{padding-bottom:8px;gap:14px}.head img{width:44px;height:44px}.head h1{font-size:14px}.head p{font-size:10px;margin:2px 0}.title{margin:10px 0}.title h2{font-size:16px}.identity{gap:5px 18px}.amount{padding:8px 12px;margin:10px 0}.amount strong{font-size:22px;line-height:1.2}.amount p{font-size:10px;margin:3px 0}.signs{margin-top:12px}.signs .line{margin-top:20px}.foot{font-size:9px;margin-top:8px;padding-top:5px}}
</style></head><body><nav class="tools"><a href="riwayat.php?<?= savings_e(http_build_query(['jenis'=>$kind,'id'=>$id])) ?>">Kembali ke Riwayat</a><button type="button" onclick="window.print()">Cetak Struk</button></nav>
<main class="receipt"><header class="head"><img src="../assets/img/school-logo.png" alt="Logo sekolah"><div><h1><?= savings_e(unit_school_name((int)$row['unit_id'])) ?></h1><p>Perum Bekasi Griya Asri II, Tambun Selatan · Telp. 021-88363466</p><p>Unit <?= savings_e(unit_label((int)$row['unit_id'])) ?></p></div></header>
<div class="title"><h2>BUKTI <?= $kind==='masuk'?'SETORAN':'PENARIKAN' ?> TABUNGAN</h2><span><?= savings_e($row['reference']) ?></span></div>
<section class="identity"><div><span>Nama siswa</span><strong><?= savings_e($row['NAMA']??'Tidak tercatat') ?></strong></div><div><span>No. Induk</span><strong><?= savings_e($row['NO_INDUK']) ?></strong></div><div><span>NIS Diknas</span><strong><?= savings_e($row['NO_induk_diknas']??'—') ?></strong></div><div><span>Kelas</span><strong><?= savings_e($row['KELAS']??'—') ?></strong></div><div><span>Tanggal transaksi</span><strong><?= savings_e(spp_date_label($row['TANGGAL'],true)) ?></strong></div><div><span>Operator</span><strong><?= savings_e($row['operator_name']) ?></strong></div></section>
<section class="amount"><span>Jumlah <?= $kind==='masuk'?'setoran':'penarikan' ?></span><br><strong><?= savings_money($amount) ?></strong><p><?= savings_e(ucfirst(savings_words((int)round($amount)))) ?> rupiah</p></section>
<div class="note"><strong>Keterangan:</strong> <?= savings_e($row['keterangan']?:'—') ?></div><section class="signs"><div><?= $kind==='masuk'?'Penyetor':'Penerima' ?><div class="line">&nbsp;</div></div><div>Petugas<div class="line"><?= savings_e($row['operator_name']) ?></div></div></section><footer class="foot">Simpan bukti ini sebagai catatan transaksi tabungan. Cetak ulang tidak menambah transaksi.</footer></main></body></html>
