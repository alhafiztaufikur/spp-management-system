<?php
// tabungan/get_saldo.php — AJAX: ambil saldo tabungan siswa
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Silakan masuk kembali.']);
    exit;
}
if (!hasRole(['admin', 'kasir'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses saldo tabungan tidak diizinkan.']);
    exit;
}

require_once __DIR__.'/../includes/savings_owners.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
if(empty($_GET['owner_id'])&&empty($_GET['student_id'])&&($_GET['nis']??'')===''){echo json_encode(['saldo'=>0,'raw_saldo'=>0,'saldo_minus'=>false,'warning'=>'']);exit;}
try{$r=so_resolve($koneksi,$_GET);$raw=(float)$r['balance'];echo json_encode(['saldo'=>max(0,$raw),'raw_saldo'=>$raw,'saldo_minus'=>$raw<0,'warning'=>$r['state']!=='ready'?'Rekening belum siap atau diblokir.':($raw<0?'Saldo perlu direkonsiliasi.':''),'owner_id'=>(int)$r['owner_id'],'student_id'=>(int)$r['student_id'],'owner_type'=>$r['owner_type'],'unit_id'=>(int)$r['unit_id'],'state'=>$r['state']]);}
catch(Throwable $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}
