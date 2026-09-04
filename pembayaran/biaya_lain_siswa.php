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

$noInduk = trim((string)($_GET['no_induk'] ?? ''));
if ($noInduk === '') {
    echo json_encode(['ok' => true, 'rows' => []]);
    exit;
}

try {
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
        ];
    }

    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
