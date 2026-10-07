<?php
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/savings_workspace.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
if (empty($_SESSION['admin_id'])) { http_response_code(401);echo json_encode(['error'=>'Silakan masuk kembali.']);exit; }
if (!hasRole(['admin','kasir','bendahara'])) { http_response_code(403);echo json_encode(['error'=>'Akses tidak diizinkan.']);exit; }
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405);header('Allow: GET');echo json_encode(['error'=>'Gunakan GET.']);exit; }
try {
    [$kind,$id]=savings_http_identity();$row=savings_transaction($koneksi,$kind,$id);
    if (!$row) {http_response_code(404);echo json_encode(['error'=>'Transaksi tidak ditemukan dalam cakupan unit Anda.']);exit;}
    echo json_encode(['ok'=>true,'transaction'=>$row,'html'=>savings_detail_html($row)],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
} catch (InvalidArgumentException $e) {http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}
