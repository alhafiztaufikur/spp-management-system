<?php
// ============================================
// tabungan/proses.php — Handler Tabungan Masuk/Keluar
// ============================================
require_once __DIR__ . '/../includes/security.php';
security_bootstrap_session();
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/audit.php';
require_once '../includes/idempotency.php';
requireRole(['admin', 'kasir']);
security_require_post();
security_require_csrf('savings');

$aksi      = security_input_scalar($_POST, 'aksi');
$no_induk  = trim((string)security_input_scalar($_POST, 'no_induk'));
$tanggal   = security_input_scalar($_POST, 'tanggal', date('Y-m-d'));
$nominal   = (float)security_input_scalar($_POST, 'nominal', 0);
$keterangan = trim((string)security_input_scalar($_POST, 'keterangan'));
$user_id   = (string)($_SESSION['admin_id'] ?? '');
$idempotencyKey = trim((string)security_input_scalar($_POST, 'idempotency_key'));

// Validasi dasar
if (!$no_induk || $nominal <= 0 || !in_array($aksi, ['masuk', 'keluar'], true)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data tidak valid! Pastikan siswa dipilih dan nominal diisi.'];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}
if (mb_strlen($keterangan) > 255) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Keterangan maksimal 255 karakter.'];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}

$tanggal_dt = $tanggal . ' ' . date('H:i:s');

// Mulai DB transaction untuk konsistensi
$koneksi->begin_transaction();

try {
    idempotency_claim($koneksi, 'savings', $idempotencyKey, $user_id !== '' ? (int)$user_id : null);

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

    if ($aksi === 'keluar' && $nominal > $saldo) {
        throw new Exception('Nominal penarikan melebihi saldo tabungan! Saldo: Rp ' . number_format($saldo, 0, ',', '.'));
    }

    // 2) Hitung saldo baru
    $saldo_baru = ($aksi === 'masuk') ? $saldo + $nominal : $saldo - $nominal;

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
    $journalId = (int)$koneksi->insert_id;
    $stmt3->close();

    $journalTable = $aksi === 'masuk' ? 'transaksi_m' : 'transaksi_k';
    $auditReason = $keterangan !== ''
        ? $keterangan
        : ($aksi === 'masuk' ? 'Setoran tabungan manual' : 'Penarikan tabungan manual');
    audit_event_write(
        $koneksi,
        $aksi === 'masuk' ? 'savings.deposited' : 'savings.withdrawn',
        $journalTable,
        $journalId,
        $aksi === 'masuk' ? 'deposit' : 'withdraw',
        ['no_induk' => $no_induk, 'balance' => $saldo],
        [
            'no_induk' => $no_induk,
            'transaction_at' => $tanggal_dt,
            'amount' => $nominal,
            'balance' => $saldo_baru,
            'operator_id' => $user_id,
        ],
        $auditReason,
        [
            'result' => 'committed',
            'source' => 'tabungan/proses.php',
            'linked_payment' => false,
        ]
    );

    $koneksi->commit();

    $label = $aksi === 'masuk' ? 'masuk' : 'keluar';
    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => 'Tabungan ' . $label . ' Rp ' . number_format($nominal, 0, ',', '.') . ' berhasil disimpan!'
    ];
    header('Location: riwayat.php');
    exit;

} catch (Throwable $e) {
    $koneksi->rollback();
    $_SESSION['flash'] = ['type' => 'error', 'msg' => security_exception_message($e, 'Transaksi tabungan gagal diproses.', 'savings-mutation')];
    header('Location: ' . ($aksi === 'keluar' ? 'keluar.php' : 'masuk.php'));
    exit;
}
