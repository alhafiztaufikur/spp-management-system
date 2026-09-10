<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'session_expired', 'message' => 'Sesi habis. Silakan masuk kembali.']);
    exit;
}

require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/spp_payment_status.php';
requireRole(['admin', 'kasir']);

$noInduk = trim((string)($_GET['no_induk'] ?? ''));
$bulan = spp_sequence_month_code((string)($_GET['bulan'] ?? ''));
$tahun = trim((string)($_GET['tahun'] ?? ''));
$editId = max(0, (int)($_GET['edit_id'] ?? 0));
$transactionStarted = false;

try {
    if ($noInduk === '' || $bulan === '' || !preg_match('/^\d{4}$/', $tahun)) {
        throw new InvalidArgumentException('Data siswa dan periode SPP belum lengkap.');
    }

    $koneksi->begin_transaction();
    $transactionStarted = true;
    $allowInactive = false;
    if ($editId > 0) {
        $stmt = $koneksi->prepare('SELECT NO_INDUK FROM bayar WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editedPayment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$editedPayment) {
            $koneksi->rollback();
            $transactionStarted = false;
            http_response_code(404);
            echo json_encode(['ok' => false, 'code' => 'payment_not_found', 'message' => 'Data pembayaran tidak ditemukan.']);
            exit;
        }
        $allowInactive = (string)$editedPayment['NO_INDUK'] === $noInduk;
    }

    $status = spp_payment_status($koneksi, $noInduk, $bulan, $tahun, $editId, false, $allowInactive);
    $status['edit_dependency'] = $editId > 0 ? spp_edit_dependency($koneksi, $editId, false) : null;
    $koneksi->commit();
    $transactionStarted = false;
    echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    if ($transactionStarted) $koneksi->rollback();
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'code' => 'invalid_request',
        'message' => 'Data siswa dan periode SPP belum lengkap.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if ($transactionStarted) $koneksi->rollback();
    http_response_code(500);
    error_log('SPP status check failed: ' . $error->getMessage());
    echo json_encode([
        'ok' => false,
        'code' => 'status_unavailable',
        'message' => 'Status SPP belum dapat diperiksa. Coba lagi.',
    ], JSON_UNESCAPED_UNICODE);
}
