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
requireRole(['admin','kasir','bendahara']);
require_once __DIR__.'/../includes/savings_book_print.php';
$output=$_GET['output']??'preview';if(!is_string($output)||!in_array($output,['preview','pdf'],true))book_error(400,'Format keluaran tidak valid.');
require_once __DIR__.'/../includes/receipt_print.php';
try {
    $o=!empty($row['legacy_owner_id'])&&empty($row['student_id']);
    [$identity,$book]=$o?savings_book_owner_load($koneksi,(int)$row['legacy_owner_id']):savings_book_load($koneksi,$row['NO_INDUK'],(int)$row['student_id'],(int)$row['unit_id']);
    $journalId=$o?-$id-1000000000:($id<0&&$id>-1000000000?-$id:$id);$balance=null;
    foreach($book['entries'] as $entry)if((int)($entry['id']??0)===$journalId&&($entry['jenis']??'')===$kind){$balance=$entry['saldo']/100;break;}
    $amount=(float)($kind==='masuk'?$row['nominal']:$row['keluar']);
    $p=['NO_INDUK'=>$row['NO_INDUK'],'NAMA'=>$row['NAMA'],'KELAS'=>$identity['kelas_label'],'owner_type'=>$o?'account':'student','total_jumlah'=>$amount,'TGL_BYR'=>$row['TANGGAL']];
    receipt_http_output([['type'=>'savings','kind'=>$kind,'payment'=>$p,'school'=>unit_school_name((int)$row['unit_id']),'primary'=>[[$kind==='masuk'?'Setoran':'Penarikan',$amount]],'remaining'=>[['Saldo setelah transaksi',$balance]],'other'=>[],'reference'=>$row['reference'],'period'=>'','words'=>receipt_money_words($amount),'date'=>spp_date_label($row['TANGGAL']),'signer'=>$row['operator_name'],'note'=>$row['keterangan']]],'Bukti Transaksi Tabungan','Struk siap dicetak');
}catch(Throwable $ex){http_response_code(409);echo savings_e($ex->getMessage());}
