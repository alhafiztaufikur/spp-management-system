<?php

declare(strict_types=1);

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
student_lifecycle_require_disposable($koneksi);

function student_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function student_lifecycle_request(string $url, string $method, array $data, array &$cookies): array
{
    $headers = [];
    if ($data) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) if ($value !== '') $pairs[] = $name . '=' . $value;
        if ($pairs) $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $data ? http_build_query($data) : '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP lifecycle siswa gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function student_lifecycle_csrf(string $body): string
{
    if (!preg_match('/<form\b[^>]*id="form-master-siswa"[^>]*>(.*?)<\/form>/si', $body, $form)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $form[1], $match)) {
        throw new RuntimeException('Token CSRF master siswa tidak ditemukan.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function student_lifecycle_login(string $baseUrl, string $username, string $password, array &$cookies): void
{
    $page = student_lifecycle_request($baseUrl . '/login.php', 'GET', [], $cookies);
    student_lifecycle_assert($page['status'] === 200, 'Halaman login lifecycle siswa gagal.');
    $token = '';
    if (!preg_match('/<form\b[^>]*id="form-login"[^>]*>(.*?)<\/form>/si', $page['body'], $form)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $form[1], $match)) {
        throw new RuntimeException('Token login lifecycle siswa tidak ditemukan.');
    }
    $token = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    $response = student_lifecycle_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => $token, 'username' => $username, 'password' => $password,
    ], $cookies);
    student_lifecycle_assert($response['status'] === 302, 'Login lifecycle siswa gagal.');
}

function student_lifecycle_require_disposable(mysqli $database): void
{
    test_require_disposable_audit_database($database);
    if (rtrim((string)getenv('SPP_TEST_BASE_URL'), '/') !== 'http://127.0.0.1:8099') {
        throw new RuntimeException('Lifecycle siswa hanya boleh memakai server audit 8099.');
    }
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
$oldNis = (string)random_int(9600000000, 9699999999);
$newNis = (string)random_int(9700000000, 9799999999);
$studentId = 0;
$paymentId = 0;
$journalId = 0;
$auditIds = [];
$failure = null;

try {
    student_lifecycle_assert($username !== '' && $password !== '', 'Kredensial audit siswa wajib tersedia.');
    $class = $koneksi->query("SELECT id, tingkat FROM master_kelas WHERE tingkat=1 AND is_active=1 ORDER BY is_placeholder DESC, id LIMIT 1")->fetch_assoc();
    student_lifecycle_assert((bool)$class, 'Fixture tidak memiliki master kelas aktif tingkat 1.');
    $classId = (int)$class['id'];

    $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,is_active) VALUES (?,?,?,?,?,1)');
    $name = 'UJI LIFECYCLE SISWA'; $kelas = '1'; $spp = 250000.0;
    $stmt->bind_param('sssid', $oldNis, $name, $kelas, $classId, $spp); $stmt->execute();
    $studentId = (int)$koneksi->insert_id; $stmt->close();

    $paymentDate = '2026-08-20 10:15:00'; $paymentAmount = 250000.0; $paymentYear = '2026'; $paymentMonth = '08';
    $stmt = $koneksi->prepare("INSERT INTO bayar (NO_INDUK,KELAS,master_kelas_id,U_SPP,total_jumlah,TGL_BYR,BULAN,TAHUN,payment_link_version) VALUES (?,?,?,?,?,?,?,?,0)");
    $stmt->bind_param('ssiddsss', $oldNis, $kelas, $classId, $paymentAmount, $paymentAmount, $paymentDate, $paymentMonth, $paymentYear);
    $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();

    $journalAmount = 125000.0;
    $stmt = $koneksi->prepare('INSERT INTO transaksi_m (NO_INDUK,TANGGAL,MASUK,KELUAR,user_id) VALUES (?,?,?,?,?)');
    $operator = 'student-lifecycle-test';
    $stmt->bind_param('ssdds', $oldNis, $paymentDate, $journalAmount, $journalAmount, $operator);
    $stmt->execute(); $journalId = (int)$koneksi->insert_id; $stmt->close();

    $cookies = [];
    student_lifecycle_login($baseUrl, $username, $password, $cookies);
    $page = student_lifecycle_request($baseUrl . '/siswa/daftar.php?edit=' . $studentId, 'GET', [], $cookies);
    student_lifecycle_assert($page['status'] === 200, 'Form edit siswa tidak dapat dibuka.');
    $csrf = student_lifecycle_csrf($page['body']);

    $update = student_lifecycle_request($baseUrl . '/siswa/daftar.php', 'POST', [
        'csrf_token' => $csrf, 'aksi' => 'update', 'id' => $studentId,
        'no_induk' => $newNis, 'nama' => $name . ' UPDATED', 'master_kelas_id' => $classId,
    ], $cookies);
    student_lifecycle_assert($update['status'] === 302, 'Update NIS siswa tidak mengembalikan redirect sukses.');

    $stmt = $koneksi->prepare('SELECT NO_INDUK,NAMA,is_active FROM siswa WHERE id=?');
    $stmt->bind_param('i', $studentId); $stmt->execute(); $student = $stmt->get_result()->fetch_assoc(); $stmt->close();
    student_lifecycle_assert(($student['NO_INDUK'] ?? '') === $newNis, 'NIS baru tidak tersimpan.');
    student_lifecycle_assert(($student['NAMA'] ?? '') === $name . ' UPDATED', 'Nama siswa tidak tersimpan.');

    $stmt = $koneksi->prepare('SELECT COUNT(*) AS total FROM bayar WHERE id=? AND NO_INDUK=?');
    $stmt->bind_param('is', $paymentId, $newNis); $stmt->execute(); $paymentCascade = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    $stmt = $koneksi->prepare('SELECT COUNT(*) AS total FROM transaksi_m WHERE id=? AND NO_INDUK=?');
    $stmt->bind_param('is', $journalId, $newNis); $stmt->execute(); $journalCascade = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
    student_lifecycle_assert($paymentCascade === 1 && $journalCascade === 1, 'Referensi histori tidak mengikuti perubahan NIS secara atomik.');

    $stmt = $koneksi->prepare('SELECT id,before_data,after_data FROM siswa_audit_log WHERE siswa_id=? AND aksi=\'update\' ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $studentId); $stmt->execute(); $audit = $stmt->get_result()->fetch_assoc(); $stmt->close();
    student_lifecycle_assert((bool)$audit, 'Audit update siswa tidak tercatat.');
    $auditIds[] = (int)$audit['id'];
    $before = json_decode((string)$audit['before_data'], true); $after = json_decode((string)$audit['after_data'], true);
    student_lifecycle_assert(($before['NO_INDUK'] ?? '') === $oldNis && ($after['NO_INDUK'] ?? '') === $newNis, 'Snapshot audit NIS sebelum/sesudah tidak tepat.');

    $archive = student_lifecycle_request($baseUrl . '/siswa/daftar.php', 'POST', [
        'csrf_token' => $csrf, 'aksi' => 'toggle_status', 'id' => $studentId,
    ], $cookies);
    student_lifecycle_assert($archive['status'] === 302, 'Arsip siswa tidak mengembalikan redirect.');
    $status = $koneksi->query('SELECT is_active FROM siswa WHERE id=' . $studentId)->fetch_assoc();
    student_lifecycle_assert((int)($status['is_active'] ?? 1) === 0, 'Siswa tidak menjadi arsip.');

    $page = student_lifecycle_request($baseUrl . '/siswa/daftar.php?status=archived&q=' . rawurlencode($newNis), 'GET', [], $cookies);
    student_lifecycle_assert($page['status'] === 200 && str_contains($page['body'], 'UJI LIFECYCLE SISWA UPDATED'), 'Siswa arsip tidak muncul pada filter arsip.');
    $csrf = student_lifecycle_csrf($page['body']);
    $restore = student_lifecycle_request($baseUrl . '/siswa/daftar.php', 'POST', [
        'csrf_token' => $csrf, 'aksi' => 'toggle_status', 'id' => $studentId,
    ], $cookies);
    student_lifecycle_assert($restore['status'] === 302, 'Restore siswa tidak mengembalikan redirect.');
    $status = $koneksi->query('SELECT is_active FROM siswa WHERE id=' . $studentId)->fetch_assoc();
    student_lifecycle_assert((int)($status['is_active'] ?? 0) === 1, 'Siswa tidak dipulihkan.');

    $auditCount = (int)$koneksi->query('SELECT COUNT(*) AS total FROM siswa_audit_log WHERE siswa_id=' . $studentId . ' AND aksi IN (\'arsipkan\',\'pulihkan\')')->fetch_assoc()['total'];
    student_lifecycle_assert($auditCount === 2, 'Audit arsip/restore tidak lengkap.');
    $ids = $koneksi->query('SELECT id FROM siswa_audit_log WHERE siswa_id=' . $studentId)->fetch_all(MYSQLI_ASSOC);
    foreach ($ids as $row) $auditIds[] = (int)$row['id'];
    echo "OK: lifecycle NIS, cascade histori, filter arsip/restore, dan snapshot audit siswa tervalidasi.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($studentId > 0) {
        $koneksi->query('DELETE FROM siswa_audit_log WHERE siswa_id=' . $studentId);
        $koneksi->query('DELETE FROM transaksi_m WHERE id=' . $journalId);
        $koneksi->query('DELETE FROM bayar WHERE id=' . $paymentId);
        $quotedOldNis = "'" . $koneksi->real_escape_string($oldNis) . "'";
        $quotedNewNis = "'" . $koneksi->real_escape_string($newNis) . "'";
        $koneksi->query('DELETE FROM tagihan_daftar_ulang WHERE no_induk IN (' . $quotedOldNis . ',' . $quotedNewNis . ')');
        $koneksi->query('DELETE FROM siswa_tahun_ajaran WHERE no_induk IN (' . $quotedOldNis . ',' . $quotedNewNis . ')');
        $koneksi->query('DELETE FROM siswa WHERE id=' . $studentId);
    }
}
if ($failure) throw $failure;
