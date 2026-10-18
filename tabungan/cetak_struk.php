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
// Legacy transaction URL resolves identity and permissions before opening the complete book.
[$student,$book]=savings_book_http_load($koneksi,$row['NO_INDUK'],(int)$row['student_id'],(int)$row['unit_id']);
$url='cetak_buku.php?'.http_build_query(['nis'=>$student['NO_INDUK'],'student_id'=>(int)$student['id'],'output'=>$output]);
header('Location: '.$url,true,303);exit;
