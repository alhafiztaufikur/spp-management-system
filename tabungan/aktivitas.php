<?php
session_start();
require_once '../koneksi.php';require_once '../includes/auth.php';require_once '../includes/savings_workspace.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false]);exit;}
if(!hasRole(['admin','kasir','bendahara'])){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false]);exit;}
try{
    [$kind,$id]=savings_http_identity();$row=savings_transaction($koneksi,$kind,$id);
    if(!$row){http_response_code(404);echo json_encode(['ok'=>false]);exit;}
    require_once '../includes/savings_correction_ui.php';
    $identity='<div class="sw-confirm-identity"><strong>'.savings_e($row['NAMA']).'</strong><small>'.savings_e($row['reference']).'</small><p>Pembuat awal: '.savings_e($row['operator_name']).'</p></div>';
    echo json_encode(['ok'=>true,'reference'=>$row['reference'],'student'=>$row['NAMA'],'html'=>$identity.savings_activity_html($koneksi,$row)],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
}catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(['ok'=>false]);}
catch(Throwable $e){error_log('Aktivitas Tabungan gagal: '.get_class($e));http_response_code(503);echo json_encode(['ok'=>false]);}
