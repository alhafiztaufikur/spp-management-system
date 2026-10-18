<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis.']);
    exit;
}

require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/biaya_lain.php';
requireRole(['admin', 'kasir']);

if(isset($_GET['unit_id'])&&(!is_string($_GET['unit_id'])||(int)$_GET['unit_id']!==unit_active_id())){http_response_code(409);echo json_encode(['ok'=>false,'code'=>'unit_changed','message'=>'Unit formulir berubah. Muat ulang Input Pembayaran.']);exit;}
if(!is_string($_GET['no_induk']??'')||strlen($_GET['no_induk']??'')>10){http_response_code(400);echo json_encode(['ok'=>false,'message'=>'Identitas siswa tidak valid.']);exit;}
session_write_close();
$noInduk = trim((string)($_GET['no_induk'] ?? ''));
if ($noInduk === '') {
    echo json_encode(['ok' => true, 'rows' => []]);
    exit;
}

try {
    unit_resolve_student($koneksi,$noInduk,(int)($_GET['student_id']??0));
    $stmt = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK=? AND is_active=1 LIMIT 1');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $studentExists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$studentExists) throw new RuntimeException('Siswa tidak ditemukan atau sudah tidak aktif.');

    $rows = [];
    foreach (other_fee_open_bills($koneksi, $noInduk) as $bill) {
        if ((float)($bill['sisa'] ?? 0) <= 0.001) continue;
        $rows[] = [
            'id' => (int)$bill['id'],
            'master_id' => (int)$bill['master_biaya_lain_id'],
            'nama' => (string)$bill['nama'],
            'nominal' => (float)$bill['nominal'],
            'paid' => (float)$bill['terbayar'],
            'sisa' => (float)$bill['sisa'],
            'optional' => false,
        ];
    }

    echo json_encode(['ok' => true, 'rows' => array_merge(other_fee_optional_choices($koneksi),$rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    error_log('Pilihan Biaya Lain: '.get_class($error));
    echo json_encode(['ok' => false, 'message' => 'Pilihan biaya belum dapat dimuat. Periksa siswa dan unit, lalu coba lagi.'], JSON_UNESCAPED_UNICODE);
}
