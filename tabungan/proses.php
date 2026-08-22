<?php
// ============================================
// tabungan/proses.php — Handler Tabungan Masuk/Keluar
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'kasir']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: masuk.php');
    exit;
}

$aksi      = $_POST['aksi'] ?? '';
$no_induk  = trim($_POST['no_induk'] ?? '');
$tanggal   = $_POST['tanggal'] ?? date('Y-m-d');
$nominal   = (float)($_POST['nominal'] ?? 0);
$keterangan = trim($_POST['keterangan'] ?? '');
$user_id   = (string)($_SESSION['admin_id'] ?? '');

// Validasi dasar
if (!$no_induk || $nominal <= 0 || !in_array($aksi, ['masuk', 'keluar'], true)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data tidak valid! Pastikan siswa dipilih dan nominal diisi.'];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}

$tanggal_dt = $tanggal . ' ' . date('H:i:s');

// Mulai DB transaction untuk konsistensi
$koneksi->begin_transaction();

try {
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
            "INSERT INTO transaksi_m (NO_INDUK, TANGGAL, MASUK, KELUAR, user_id) VALUES (?, ?, ?, 0, ?)"
        );
        $stmt3->bind_param('ssds', $no_induk, $tanggal_dt, $nominal, $user_id);
    } else {
        $stmt3 = $koneksi->prepare(
            "INSERT INTO transaksi_k (NO_INDUK, TANGGAL, MASUK, KELUAR, user_id) VALUES (?, ?, 0, ?, ?)"
        );
        $stmt3->bind_param('ssds', $no_induk, $tanggal_dt, $nominal, $user_id);
    }
    $stmt3->execute();
    $stmt3->close();

    $koneksi->commit();

    $label = $aksi === 'masuk' ? 'masuk' : 'keluar';
    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => 'Tabungan ' . $label . ' Rp ' . number_format($nominal, 0, ',', '.') . ' berhasil disimpan!'
    ];
    header('Location: riwayat.php');
    exit;

} catch (Exception $e) {
    $koneksi->rollback();
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}
