<?php
/** Verify that the rombel toggle leaves occupied classes active. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
session_start();
require_once __DIR__ . '/../koneksi.php';
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', DB_NAME)) {
    throw new RuntimeException('Tes status rombel hanya untuk clone audit dengan flag mutasi.');
}

$occupied = in_array('--occupied', $argv, true);
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$sql = $occupied
    ? "SELECT mk.id FROM master_kelas mk WHERE mk.tingkat>0 AND mk.is_active=1 AND mk.is_placeholder=0
        AND EXISTS(SELECT 1 FROM siswa s WHERE s.master_kelas_id=mk.id AND s.is_active=1)
        ORDER BY mk.id LIMIT 1"
    : "SELECT mk.id FROM master_kelas mk WHERE mk.tingkat>0 AND mk.is_active=1 AND mk.is_placeholder=0
        AND NOT EXISTS(SELECT 1 FROM siswa s WHERE s.master_kelas_id=mk.id AND s.is_active=1)
        ORDER BY mk.id DESC LIMIT 1";
$row = $koneksi->query($sql)->fetch_assoc();
if (!$row) throw new RuntimeException('Rombel fixture tidak ditemukan.');
$classId = (int)$row['id'];
$token = bin2hex(random_bytes(32));
$_SESSION['csrf_master_kelas'] = $token;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = '/master_kelas.php';
$_POST = ['aksi'=>'toggle', 'id'=>(string)$classId, 'target_active'=>'0', 'csrf_token'=>$token];

register_shutdown_function(static function () use ($koneksi, $classId, $occupied): void {
    while (ob_get_level() > 0) ob_end_clean();
    try {
        $stmt = $koneksi->prepare('SELECT is_active FROM master_kelas WHERE id=?');
        $stmt->bind_param('i', $classId); $stmt->execute();
        $active = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
        $flashType = (string)($_SESSION['flash']['type'] ?? '');
        $valid = $occupied
            ? $active === 1 && $flashType === 'error'
            : $active === 0 && $flashType === 'success';
        if (!$occupied && $active === 0) {
            $stmt = $koneksi->prepare('UPDATE master_kelas SET is_active=1 WHERE id=?');
            $stmt->bind_param('i', $classId); $stmt->execute(); $stmt->close();
        }
        if (!$valid) throw new RuntimeException('Status rombel atau pesan toggle tidak sesuai.');
        echo $occupied
            ? "PASS: rombel dengan siswa aktif tidak dapat dinonaktifkan.\n"
            : "PASS: rombel kosong dapat dinonaktifkan dan dipulihkan.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
});

ob_start();
include __DIR__ . '/../master_kelas.php';
