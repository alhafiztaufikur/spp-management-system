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
require_once '../includes/spp_billing.php';
require_once '../includes/komite_billing.php';
require_once '../includes/payment_obligations.php';
requireRole(['admin', 'kasir']);

if(isset($_GET['unit_id'])&&(!is_string($_GET['unit_id'])||(int)$_GET['unit_id']!==unit_active_id())){http_response_code(409);echo json_encode(['ok'=>false,'code'=>'unit_changed','message'=>'Unit formulir berubah. Muat ulang Input Pembayaran.']);exit;}
if(!is_string($_GET['no_induk']??'')||strlen($_GET['no_induk']??'')>10||!is_string($_GET['bulan']??'')||!is_string($_GET['tahun']??'')){http_response_code(400);echo json_encode(['ok'=>false,'code'=>'invalid_request','message'=>'Parameter status tidak valid.']);exit;}
session_write_close();
$noInduk = trim((string)($_GET['no_induk'] ?? ''));
$bulan = spp_sequence_month_code((string)($_GET['bulan'] ?? ''));
$tahun = trim((string)($_GET['tahun'] ?? ''));
$editId = max(0, (int)($_GET['edit_id'] ?? 0));
$transactionStarted = false;

try { unit_resolve_student($koneksi,$noInduk,(int)($_GET['student_id']??0)); }catch(Throwable $e){http_response_code(409);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);exit;}

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

    $status = spp_billing_schema_ready($koneksi)
        ? spp_published_period_status($koneksi,$noInduk,$bulan,$tahun,$editId)
        : spp_payment_status($koneksi, $noInduk, $bulan, $tahun, $editId, false, $allowInactive);
    if (spp_billing_schema_ready($koneksi)) {
        $komite = komite_bill($koneksi,$noInduk,$bulan,$tahun);
        $komitePaid = (float)($komite['paid'] ?? 0);
        if ($komite && $editId > 0) {
            $stmt = $koneksi->prepare('SELECT COALESCE(SUM(nominal),0) nominal FROM bayar_komite WHERE bayar_id=? AND tagihan_komite_id=?');
            $komiteId=(int)$komite['id'];$stmt->bind_param('ii',$editId,$komiteId);$stmt->execute();
            $komitePaid -= (float)($stmt->get_result()->fetch_assoc()['nominal'] ?? 0);$stmt->close();
        }
        $status['komite'] = ['exists'=>(bool)$komite,'total'=>(float)($komite['nominal_tagihan'] ?? 0),'paid'=>max(0,$komitePaid)];
    }
    $status['obligations']=payment_obligations($koneksi,$noInduk);
    $status['edit_dependency'] = spp_billing_schema_ready($koneksi) ? null : ($editId > 0 ? spp_edit_dependency($koneksi, $editId, false) : null);
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
