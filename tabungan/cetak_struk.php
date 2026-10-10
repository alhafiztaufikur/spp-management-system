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
require_once __DIR__.'/../includes/savings_book_print.php';
$output = $_GET['output'] ?? 'preview';
if (!is_string($output) || !in_array($output, ['preview', 'pdf'], true)) {
    book_error(400, 'Format keluaran tidak dikenal.');
}
// Student identity comes exclusively from the authorized transaction, never URL overrides.
[$student, $book] = savings_book_http_load($koneksi, (string)$row['NO_INDUK'], 0, (int)$row['unit_id']);
$identity = ['jenis'=>$kind, 'id'=>$id];
$pdfUrl = 'cetak_struk.php?'.http_build_query($identity + ['output'=>'pdf']);
$backUrl = 'riwayat.php?'.http_build_query($identity);
savings_book_output($student, $book, $output, $pdfUrl, $backUrl, 'Kembali ke Riwayat');
