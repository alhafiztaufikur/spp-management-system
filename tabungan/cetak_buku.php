<?php
session_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/savings_book_print.php';
requireRole(['admin', 'kasir', 'bendahara']);
header('Cache-Control: private, no-store');
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');book_error(405,'Gunakan GET untuk membuka buku tabungan.');}

$nis = $_GET['nis'] ?? '';
if (!is_string($nis) || ($nis = trim($nis)) === '' || strlen($nis) > 10) {
    book_error(400, 'NIS siswa tidak valid. Pilih siswa dari menu Cetak Tabungan.');
}
$output = $_GET['output'] ?? 'preview';
if (!is_string($output) || !in_array($output, ['preview', 'pdf', 'guide'], true)) {
    book_error(400, 'Format keluaran tidak dikenal.');
}
$studentId = $_GET['student_id'] ?? '0';
if (!is_string($studentId) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', $studentId)) {
    book_error(400, 'Identitas siswa tidak valid.');
}
[$student, $book] = savings_book_http_load($koneksi, $nis, (int)$studentId);
require_once __DIR__.'/../includes/savings_book_layout.php';
try {$settings=savings_book_print_settings($_GET,count($book['entries']));}
catch(InvalidArgumentException $e){book_error(400,$e->getMessage());}
$pdfUrl = 'cetak_buku.php?'.http_build_query(['nis'=>$nis, 'student_id'=>(int)$student['id'], 'output'=>'pdf']+$settings);
savings_book_output($student, $book, $output, $pdfUrl, 'cetak.php', 'Kembali ke Cetak Tabungan', $settings);
