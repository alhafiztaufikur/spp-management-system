<?php
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Silakan masuk kembali.']);exit;}
require_once __DIR__.'/koneksi.php';require_once __DIR__.'/includes/auth.php';require_once __DIR__.'/includes/authorization_history.php';
if(!hasRole(['admin','bendahara','kasir'])){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak diizinkan.']);exit;}
$id=max(0,(int)($_GET['id']??0));
try{
    require_once __DIR__.'/includes/authorization_presentation.php';
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'Gunakan GET.']);exit;}
    authorization_read_unit($koneksi,$_GET['unit_id']??null);
    $model=authorization_detail_model($koneksi,$id,false);
    echo json_encode(['ok'=>true,'reference'=>$model['reference'],'student'=>$model['student'],'unit'=>$model['unit'],'events'=>$model['events']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
catch(RuntimeException $e){http_response_code(404);echo json_encode(['ok'=>false,'message'=>'Riwayat tidak ditemukan pada cakupan akses Anda.']);}
catch(Throwable $e){error_log('Detail otorisasi: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Riwayat belum dapat dimuat.']);}
