<?php
session_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/savings_authorization.php';requireRole(['admin','kasir']);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Gunakan POST.');}
$action=$_POST['action']??'';$token=$_POST['csrf_token']??null;$decision=in_array($action,['approve','reject'],true);
$expected=$_SESSION[$decision?'csrf_transaction_authorization':'csrf_savings']??'';
if(!is_string($token)||$expected===''||!hash_equals($expected,$token)){http_response_code(403);exit('Token CSRF tidak valid. Muat ulang halaman.');}
if(in_array($action,['request_edit','request_hapus','direct_edit','direct_hapus'],true)&&(!is_string($_POST['expected_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$_POST['expected_hash']))){http_response_code(409);exit('Formulir koreksi kedaluwarsa. Muat ulang Riwayat Tabungan.');}
$kind=is_string($_POST['jenis']??null)?$_POST['jenis']:'';$id=(int)($_POST['id']??0);
$back=$decision?'../otorisasi_transaksi.php':'riwayat.php';
$returnRombel=null;
if(!$decision&&isset($_POST['return_rombel'])){require_once __DIR__.'/../includes/history_class_filter.php';$returnRombel=history_class_filter($koneksi,['rombel'=>$_POST['return_rombel']])['values'];}
try{$result=savings_correct($koneksi,(string)$action,$kind,$id,$_POST,(int)$_SESSION['admin_id'],(int)($_POST['request_id']??0));$_SESSION['flash']=['type'=>'success','msg'=>str_starts_with($action,'request_')?'Pengajuan tersimpan. Transaksi dan saldo belum berubah.':'Tindakan tabungan berhasil diselesaikan.'];}
catch(Throwable $e){if($e instanceof mysqli_sql_exception)error_log('Koreksi tabungan: '.$e->getMessage());$_SESSION['flash']=['type'=>'error','msg'=>$e instanceof mysqli_sql_exception?'Koreksi gagal karena benturan data. Muat ulang dan periksa riwayat.':$e->getMessage()];}
$query=[];foreach(['nis','tanggal_awal','tanggal_akhir','page','per_page','student_id','view'] as $key)if(is_scalar($_POST['return_'.$key]??null))$query[$key]=(string)$_POST['return_'.$key];
if($returnRombel!==null)$query['rombel']=$returnRombel;
require_once '../includes/savings_operator.php';
if(!$decision){try{$query['operator']=history_operator_choices($_POST['return_operator']??null,savings_operator_options($koneksi));}catch(InvalidArgumentException $e){$query['operator']=['*'];}}
if(!$decision&&$id>0)$query+=['jenis'=>$kind,'id'=>$id];header('Location: '.$back.($query?'?'.http_build_query($query):''),true,303);exit;
