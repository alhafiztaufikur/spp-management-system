<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Sesi habis. Silakan masuk kembali.']);
    exit;
}
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/payment_period.php';
require_once '../includes/payment_permissions.php';
requireRole(['admin','kasir']);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || (string)unit_active_id() !== ($_GET['unit_id'] ?? null)) {
        throw new DomainException('Unit formulir berubah. Muat ulang halaman.');
    }
    $nis = $_GET['no_induk'] ?? '';
    $mode = $_GET['mode'] ?? '';
    $choice = $_GET['choice'] ?? '';
    $edit = (int)($_GET['edit_id'] ?? 0);
    if (!is_string($nis) || ($mode !== 'range' && !($edit > 0 && $mode === 'academic')) || !is_string($choice)) {
        throw new DomainException('Pilihan periode tidak valid.');
    }
    $old = null;
    if ($edit) {
        payment_assert_owner($koneksi,$edit,(int)$_SESSION['admin_id']);
        $meta = payment_period_meta($koneksi,$edit);
        if (!$meta) throw new DomainException('Pembayaran Multi Bulan tidak ditemukan.');
        $old = lf_one($koneksi,'SELECT NO_INDUK FROM bayar WHERE id=?',[$edit])['NO_INDUK'] ?? null;
        if ($old === null) throw new DomainException('Pembayaran Multi Bulan tidak ditemukan.');
        payment_assert_edit_student((string)$old, $nis);
    }
    session_write_close();
    $koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $student = payment_period_student($koneksi,$nis,false,$old);
    $nis = $student['NO_INDUK'];
    lb_scope_student_read($koneksi,$nis);
    $start = $edit && $old === $nis && $meta['mode'] === 'range' && $mode === 'range' ? $meta['mulai'] : null;
    $preview = payment_period_plan($koneksi,$nis,$mode,$choice,$edit,false,$start);
    if ($start) $preview['oldest'] = $start;
    $koneksi->commit();
    echo json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (DomainException|InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Rincian tagihan gagal dimuat. Coba lagi.']);
}
