<?php
session_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';requireRole(['admin','kasir']);require_once __DIR__.'/../includes/savings_owners.php';
if(session_status()===PHP_SESSION_ACTIVE && ($_GET['format']??'')==='json')session_write_close();
header('Cache-Control: private, no-store');if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit('Gunakan GET untuk mencari pemilik.');}
if(($_GET['format']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');try{echo json_encode(['ok'=>true]+so_page($koneksi,$_GET),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}exit;
}
// The regular entry page is the server-rendered search fallback.
$savingsKind=($_GET['kind']??'')==='keluar'?'keluar':'masuk';require __DIR__.'/../includes/savings_owner_entry.php';
