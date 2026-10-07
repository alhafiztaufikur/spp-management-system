<?php
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if(empty($_SESSION['admin_id'])) { http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Sesi habis. Silakan login kembali.']);exit; }
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/payment_history.php';
if(!hasRole(['admin','kasir','bendahara'])) { http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']);exit; }
if($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'Gunakan GET.']);exit; }
try {
    authorization_read_unit($koneksi,$_GET['unit_id']??null);
    $view=$_GET['view']??'active';if(!in_array($view,['active','deleted'],true)) throw new InvalidArgumentException('Jenis riwayat tidak valid.');
    $rawId=$_GET['id']??'';if(!is_scalar($rawId)||!preg_match('/^[1-9][0-9]*$/D',(string)$rawId)) throw new InvalidArgumentException('Nomor transaksi tidak valid.');
    $model=payment_history_model($koneksi,(int)$rawId,$view==='deleted');
    ob_start();payment_history_render($model);$html=ob_get_clean();
    echo json_encode(['ok'=>true,'html'=>$html,'capabilities'=>$model['capabilities']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    $expected=$error instanceof InvalidArgumentException||$error instanceof OutOfBoundsException||($error instanceof RuntimeException&&!($error instanceof mysqli_sql_exception));
    if(!$expected) error_log('Detail pembayaran: '.$error->getMessage());
    http_response_code($error instanceof InvalidArgumentException?400:($expected?404:500));
    echo json_encode(['ok'=>false,'message'=>$expected?$error->getMessage():'Detail belum dapat dimuat. Silakan coba kembali.'],JSON_UNESCAPED_UNICODE);
}
