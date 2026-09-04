<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis.']);
    exit;
}
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/reports.php';
requireRole(['admin', 'kasir']);

function history_money(float $value): string {
    return 'Rp ' . number_format($value, 0, ',', '.');
}

$noInduk = trim((string)($_GET['no_induk'] ?? ''));
if ($noInduk === '') {
    echo json_encode(['ok' => true, 'period' => 'Pilih siswa untuk melihat transaksi.', 'rows' => []]);
    exit;
}

try {
    $stmt = $koneksi->prepare("SELECT NO_INDUK,NAMA FROM siswa WHERE NO_INDUK=? LIMIT 1");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Siswa tidak ditemukan.');

    $stmt = $koneksi->prepare("SELECT b.*, COALESCE(a.nama,b.user_id) operator_nama
        FROM bayar b
        LEFT JOIN admin a ON CAST(a.id AS CHAR)=CAST(b.user_id AS CHAR) OR a.username=CAST(b.user_id AS CHAR) OR a.nama=CAST(b.user_id AS CHAR)
        WHERE b.NO_INDUK=?
        ORDER BY b.TGL_BYR DESC, b.id DESC
        LIMIT 50");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rows = [];
    foreach ($payments as $payment) {
        $components = [];
        $map = [
            'U_SPP' => 'SPP',
            'U_PANGKAL' => 'Pangkal',
            'U_BANGUNAN' => 'Bangunan',
            'U_SERAGAM' => 'Seragam',
            'U_KEGIATAN' => 'Kegiatan',
            'U_KOMITE' => 'Komite',
            'U_MAKAN' => 'Makan',
            'U_SORGA' => 'Sorga',
            'U_INFAQ' => 'Infaq',
            'U_LAIN' => 'Biaya Lain',
        ];
        foreach ($map as $column => $label) {
            if ((float)($payment[$column] ?? 0) > 0.001) $components[] = $label;
        }
        $stmtDu = $koneksi->prepare('SELECT COALESCE(SUM(jumlah),0) total FROM bayar_du WHERE bayar_id=?');
        $paymentId = (int)$payment['id'];
        $stmtDu->bind_param('i', $paymentId);
        $stmtDu->execute();
        $duAmount = (float)($stmtDu->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtDu->close();
        if ($duAmount > 0.001) $components[] = 'Daftar Ulang';

        $rows[] = [
            'tanggal' => date('d/m/Y H:i', strtotime((string)$payment['TGL_BYR'])),
            'periode' => (report_months()[report_month_code((string)$payment['BULAN'])] ?? (string)$payment['BULAN']) . ' ' . $payment['TAHUN'],
            'komponen' => $components ? implode(', ', $components) : 'Koreksi',
            'metode' => (string)($payment['sistem_pembayaran'] ?? ''),
            'operator' => (string)($payment['operator_nama'] ?? $payment['user_id'] ?? ''),
            'total' => history_money((float)($payment['total_jumlah'] ?? 0)),
        ];
    }

    echo json_encode([
        'ok' => true,
        'period' => 'History pembayaran ' . $student['NAMA'] . ': ' . count($rows) . ' transaksi terakhir',
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
}
