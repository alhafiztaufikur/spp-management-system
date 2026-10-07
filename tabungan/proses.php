<?php
// ============================================
// tabungan/proses.php — Handler Tabungan Masuk/Keluar
// ============================================
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/financial_request.php';
require_once '../includes/savings_notes.php';
requireRole(['admin', 'kasir']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: masuk.php');
    exit;
}

$aksi      = $_POST['aksi'] ?? '';
$no_induk  = trim($_POST['no_induk'] ?? '');
$tanggal   = date('Y-m-d');
$rawNominal = trim((string)($_POST['nominal'] ?? ''));
$nominal   = ctype_digit($rawNominal) ? (float)$rawNominal : NAN;
$keterangan = trim($_POST['keterangan'] ?? '');
$user_id   = (string)($_SESSION['admin_id'] ?? '');
$requestKey = (string)($_POST['request_key'] ?? '');
$fallback = $aksi === 'keluar' ? 'keluar.php' : 'masuk.php';

if (empty($_SESSION['csrf_savings']) || !hash_equals($_SESSION['csrf_savings'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['flash'] = ['type'=>'error', 'msg'=>'Permintaan tabungan tidak valid atau sesi telah kedaluwarsa. Muat ulang formulir.'];
    header('Location: ' . $fallback);
    exit;
}

// Validasi dasar
if (!$no_induk || !is_finite($nominal) || $nominal <= 0 || !in_array($aksi, ['masuk', 'keluar'], true)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data tidak valid! Pastikan siswa dipilih dan nominal diisi.'];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}
if (mb_strlen($keterangan, 'UTF-8') > 255) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Keterangan maksimal 255 karakter.'];
    header('Location: ' . $fallback);
    exit;
}
if (!savings_notes_ready($koneksi)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Penyimpanan keterangan tabungan belum tersedia. Hubungi administrator sistem.'];
    header('Location: ' . $fallback);
    exit;
}

$tanggal_dt = $tanggal . ' ' . date('H:i:s');

// Mulai DB transaction untuk konsistensi
$koneksi->begin_transaction();

try {
    financial_request_reserve($koneksi, $requestKey, 'tabungan_' . $aksi, (int)$_SESSION['admin_id']);
    $stmtStudent = $koneksi->prepare('SELECT id FROM siswa WHERE NO_INDUK = ? AND is_active = 1 FOR UPDATE');
    $stmtStudent->bind_param('s', $no_induk);
    $stmtStudent->execute();
    $activeStudent = $stmtStudent->get_result()->fetch_assoc();
    $stmtStudent->close();
    if (!$activeStudent) {
        throw new RuntimeException('Siswa tidak ditemukan atau sudah diarsipkan.');
    }

    // 1) Ambil atau buat record saldo tabungan siswa
    $stmt = $koneksi->prepare("SELECT SALDO FROM tabungan WHERE NO_INDUK = ? FOR UPDATE");
    $stmt->bind_param('s', $no_induk);
    $stmt->execute();
    $res   = $stmt->get_result()->fetch_assoc();
    $saldo = (float)($res['SALDO'] ?? 0);
    $stmt->close();

    if ($aksi === 'keluar' && $saldo <= 0) {
        throw new Exception('Saldo tabungan kosong. Penarikan tidak bisa diproses.');
    }

    if ($aksi === 'keluar' && $nominal > $saldo) {
        throw new Exception('Nominal penarikan melebihi saldo tabungan! Saldo: Rp ' . number_format(max($saldo, 0), 0, ',', '.'));
    }

    // 2) Hitung saldo baru
    $saldo_baru = ($aksi === 'masuk') ? $saldo + $nominal : $saldo - $nominal;
    if ($saldo_baru < -0.001) {
        throw new Exception('Transaksi ditolak karena akan membuat saldo tabungan minus.');
    }
    if ($saldo_baru < 0) {
        $saldo_baru = 0;
    }

    // 3) Upsert tabel tabungan
    $stmt2 = $koneksi->prepare(
        "INSERT INTO tabungan (NO_INDUK, SALDO) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE SALDO = ?"
    );
    $stmt2->bind_param('sdd', $no_induk, $saldo_baru, $saldo_baru);
    $stmt2->execute();
    $stmt2->close();

    // 4) Insert ke transaksi_m (masuk) atau transaksi_k (keluar)
    if ($aksi === 'masuk') {
        $stmt3 = $koneksi->prepare(
            "INSERT INTO transaksi_m (NO_INDUK, TANGGAL, MASUK, KELUAR, user_id, keterangan) VALUES (?, ?, ?, 0, ?, ?)"
        );
        $stmt3->bind_param('ssdss', $no_induk, $tanggal_dt, $nominal, $user_id, $keterangan);
    } else {
        $stmt3 = $koneksi->prepare(
            "INSERT INTO transaksi_k (NO_INDUK, TANGGAL, MASUK, KELUAR, user_id, keterangan) VALUES (?, ?, 0, ?, ?, ?)"
        );
        $stmt3->bind_param('ssdss', $no_induk, $tanggal_dt, $nominal, $user_id, $keterangan);
    }
    $stmt3->execute();
    $journalId = (int)$koneksi->insert_id;
    $stmt3->close();

    financial_request_complete($koneksi, $requestKey, $journalId);
    $koneksi->commit();

    $_SESSION['savings_print_prompt'] = ['jenis'=>$aksi, 'id'=>$journalId, 'unit_id'=>unit_active_id()];

    $label = $aksi === 'masuk' ? 'masuk' : 'keluar';
    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => 'Tabungan ' . $label . ' Rp ' . number_format($nominal, 0, ',', '.') . ' berhasil disimpan!'
    ];
    header('Location: riwayat.php?' . http_build_query(['jenis'=>$aksi, 'id'=>$journalId]));
    exit;

} catch (Throwable $e) {
    $koneksi->rollback();
    if ($e instanceof mysqli_sql_exception) {
        error_log('Gagal menyimpan tabungan: kesalahan database ' . $e->getCode());
    }
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $e instanceof mysqli_sql_exception
        ? 'Tabungan gagal disimpan. Periksa data dan hubungi administrator jika masalah berulang.'
        : $e->getMessage()];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}
