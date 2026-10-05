<?php
// Reject a zero-value payment at the HTTP boundary without leaving a header or request key.
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "SKIPPED: hanya untuk database audit disposable.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/http_form_scope.php';

function zero_payment_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function zero_payment_http(string $url, string $sessionId, ?array $post = null): array {
    $headers = ['Cookie: ' . session_name() . '=' . $sessionId];
    $options = ['method' => $post === null ? 'GET' : 'POST', 'follow_location' => 0,
        'ignore_errors' => true, 'timeout' => 30];
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($post);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
    return [$http_response_header[0] ?? '', $body === false ? '' : $body];
}

$base = rtrim((string)getenv('SPP_HTTP_BASE'), '/');
spp_test_assert_http_clone($base, DB_NAME);
$parts = parse_url($base);
zero_payment_assert(in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true), 'Server HTTP harus lokal.');
zero_payment_assert((string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0] === getenv('SPP_DB_NAME'), 'Koneksi CLI salah database.');
unit_set_context($koneksi, 1);
$account = $koneksi->query("SELECT id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
$student = $koneksi->query("SELECT NO_INDUK FROM siswa WHERE is_active=1 LIMIT 1")->fetch_assoc();
zero_payment_assert((bool)$account && (bool)$student, 'Akun atau siswa latihan SD tidak tersedia.');
$beforePayments = (int)$koneksi->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0];
$beforeRequests = (int)$koneksi->query('SELECT COUNT(*) FROM keuangan_request')->fetch_row()[0];
$sessionId = 'zeropay' . bin2hex(random_bytes(12));
session_id($sessionId);
session_start();
$_SESSION = ['admin_id' => (int)$account['id'], 'admin_role' => 'admin',
    'admin_nama' => 'Tes Nominal Nol', 'admin_unit_id' => 1, 'active_unit_id' => 1];
session_write_close();

$passed = false;
try {
    [$status, $form] = zero_payment_http($base . '/pembayaran/form.php', $sessionId);
    zero_payment_assert(str_contains($status, '200'), 'Form tidak tampil.');
    $form = spp_test_form_scope($form, 'request_key');
    zero_payment_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form, $csrf), 'Token CSRF tidak tersedia.');
    zero_payment_assert((bool)preg_match('/name="request_key" value="([a-f0-9]+)"/', $form, $key), 'Kunci permintaan tidak tersedia.');
    [$status] = zero_payment_http($base . '/pembayaran/proses.php', $sessionId, [
        'aksi' => 'input', 'csrf_token' => $csrf[1], 'request_key' => $key[1],
        'no_induk' => $student['NO_INDUK'], 'bulan_bayar' => '09', 'tahun_bayar' => '2026',
        'payment_plan' => 'monthly', 'spp_action' => 'bayar', 'sistem_pembayaran' => 'Tunai',
        'uang_psb' => '0', 'uang_spp' => '0',
        'uang_komite' => '0', 'uang_du' => '0',
    ]);
    zero_payment_assert(str_contains($status, '302'), 'Pembayaran nol tidak dialihkan dengan pesan gagal.');
    session_id($sessionId);
    session_start();
    $flash = (string)($_SESSION['flash']['msg'] ?? '');
    session_write_close();
    zero_payment_assert(str_contains($flash, 'Isi setidaknya satu nominal pembayaran'), 'Alasan nominal nol hilang: ' . $flash);
    zero_payment_assert((int)$koneksi->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0] === $beforePayments, 'Pembayaran nol menyimpan header.');
    zero_payment_assert((int)$koneksi->query('SELECT COUNT(*) FROM keuangan_request')->fetch_row()[0] === $beforeRequests, 'Kunci pembayaran nol tidak rollback.');
    $passed = true;
} finally {
    session_id($sessionId);
    session_start();
    $_SESSION = [];
    session_destroy();
    session_write_close();
}
if ($passed) echo "OK: nominal nol ditolak tanpa header atau kunci transaksi.\n";
