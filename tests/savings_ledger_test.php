<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';

test_require_disposable_audit_database($koneksi);

function savings_ledger_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, string|int|float> $data
 * @param array<string, string> $cookies
 * @return array{status:int,body:string}
 */
function savings_ledger_request(string $url, array $data, array &$cookies): array
{
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $options = [
        'http' => [
            'method' => $data === [] ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $data === [] ? '' : http_build_query($data),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ];
    $body = file_get_contents($url, false, stream_context_create($options));
    if ($body === false) {
        throw new RuntimeException('Request ke server audit gagal: ' . $url);
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            $status = (int)$match[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }

    return ['status' => $status, 'body' => $body];
}

function savings_ledger_csrf(string $body, ?string $formId = null): string
{
    $subject = $body;
    if ($formId !== null) {
        $quotedId = preg_quote($formId, '/');
        if (!preg_match('/<form\b[^>]*\bid="' . $quotedId . '"[^>]*>(.*?)<\/form>/si', $body, $formMatch)) {
            throw new RuntimeException('Form CSRF tidak ditemukan pada server audit: ' . $formId);
        }
        $subject = $formMatch[1];
    }
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $subject, $match)) {
        throw new RuntimeException('Token CSRF tabungan tidak ditemukan pada server audit.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function savings_ledger_idempotency_key(string $body, string $formId = 'form-tabungan'): string
{
    $quotedId = preg_quote($formId, '/');
    if (!preg_match('/<form\b[^>]*\bid="' . $quotedId . '"[^>]*>(.*?)<\/form>/si', $body, $formMatch)) {
        throw new RuntimeException('Form idempotency tabungan tidak ditemukan pada server audit.');
    }
    if (!preg_match('/name="idempotency_key"\s+value="([a-f0-9]{64})"/', $formMatch[1], $match)) {
        throw new RuntimeException('Kunci idempotency tabungan tidak tersedia atau tidak kuat.');
    }
    return $match[1];
}

function savings_ledger_snapshot(mysqli $database, string $noInduk): array
{
    $stmt = $database->prepare(
        'SELECT
            COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS saldo,
            COALESCE((SELECT SUM(MASUK) FROM transaksi_m WHERE NO_INDUK = ?), 0) AS total_masuk,
            COALESCE((SELECT SUM(KELUAR) FROM transaksi_k WHERE NO_INDUK = ?), 0) AS total_keluar,
            (SELECT COUNT(*) FROM transaksi_m WHERE NO_INDUK = ?) AS jumlah_masuk,
            (SELECT COUNT(*) FROM transaksi_k WHERE NO_INDUK = ?) AS jumlah_keluar,
            (SELECT COUNT(*) FROM transaksi_m WHERE NO_INDUK = ? AND bayar_id IS NOT NULL) AS linked_masuk'
    );
    $stmt->bind_param('ssssss', $noInduk, $noInduk, $noInduk, $noInduk, $noInduk, $noInduk);
    $stmt->execute();
    $snapshot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $snapshot ?: [];
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
$noInduk = '';
$idempotencyKeys = [];
$failure = null;

try {
    savings_ledger_assert(
        $baseUrl === 'http://127.0.0.1:8099',
        'Tes mutasi hanya boleh memakai server audit http://127.0.0.1:8099.'
    );
    savings_ledger_assert($username !== '' && $password !== '', 'Kredensial akun audit wajib disediakan lewat environment.');

    do {
        $noInduk = (string)random_int(9600000000, 9699999999);
        $stmt = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
        $stmt->bind_param('s', $noInduk);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } while ($exists);

    $name = 'UJI LEDGER TABUNGAN';
    $class = '1';
    $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, is_active) VALUES (?, ?, ?, 1)');
    $stmt->bind_param('sss', $noInduk, $name, $class);
    $stmt->execute();
    $stmt->close();

    $cookies = [];
    $loginPage = savings_ledger_request($baseUrl . '/login.php', [], $cookies);
    $loginToken = savings_ledger_csrf($loginPage['body'], 'form-login');
    $login = savings_ledger_request($baseUrl . '/login.php', [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ], $cookies);
    savings_ledger_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin audit gagal.');

    $depositForm = savings_ledger_request($baseUrl . '/tabungan/masuk.php', [], $cookies);
    $savingsToken = savings_ledger_csrf($depositForm['body'], 'form-tabungan');
    $depositKey = savings_ledger_idempotency_key($depositForm['body']);
    $idempotencyKeys[] = $depositKey;
    $common = [
        'no_induk' => $noInduk,
        'tanggal' => '2026-08-20',
        'keterangan' => 'UJI OTOMATIS LEDGER',
    ];

    $deposit = savings_ledger_request($baseUrl . '/tabungan/proses.php', $common + [
        'csrf_token' => $savingsToken,
        'idempotency_key' => $depositKey,
        'aksi' => 'masuk',
        'nominal' => '100000',
    ], $cookies);
    savings_ledger_assert($deposit['status'] === 302, 'Setoran tabungan tidak mengikuti redirect sukses.');

    $afterDeposit = savings_ledger_snapshot($koneksi, $noInduk);
    savings_ledger_assert(
        abs((float)$afterDeposit['saldo'] - 100000.0) < 0.001
        && abs((float)$afterDeposit['total_masuk'] - 100000.0) < 0.001
        && (int)$afterDeposit['jumlah_masuk'] === 1
        && (int)$afterDeposit['linked_masuk'] === 0,
        'Setoran manual tidak menjaga saldo, jurnal masuk, atau kontrak bayar_id NULL.'
    );

    $withdrawalForm = savings_ledger_request($baseUrl . '/tabungan/keluar.php', [], $cookies);
    $withdrawalToken = savings_ledger_csrf($withdrawalForm['body'], 'form-tabungan');
    $withdrawalKey = savings_ledger_idempotency_key($withdrawalForm['body']);
    $idempotencyKeys[] = $withdrawalKey;
    $withdrawal = savings_ledger_request($baseUrl . '/tabungan/proses.php', $common + [
        'csrf_token' => $withdrawalToken,
        'idempotency_key' => $withdrawalKey,
        'aksi' => 'keluar',
        'nominal' => '40000',
    ], $cookies);
    savings_ledger_assert($withdrawal['status'] === 302, 'Penarikan parsial tidak mengikuti redirect sukses.');
    $afterWithdrawal = savings_ledger_snapshot($koneksi, $noInduk);
    savings_ledger_assert(
        abs((float)$afterWithdrawal['saldo'] - 60000.0) < 0.001
        && abs((float)$afterWithdrawal['total_keluar'] - 40000.0) < 0.001
        && (int)$afterWithdrawal['jumlah_keluar'] === 1,
        'Penarikan parsial tidak menjaga saldo dan jurnal keluar.'
    );

    $overdrawForm = savings_ledger_request($baseUrl . '/tabungan/keluar.php', [], $cookies);
    $overdrawToken = savings_ledger_csrf($overdrawForm['body'], 'form-tabungan');
    $overdrawKey = savings_ledger_idempotency_key($overdrawForm['body']);
    $idempotencyKeys[] = $overdrawKey;
    $overdraw = savings_ledger_request($baseUrl . '/tabungan/proses.php', $common + [
        'csrf_token' => $overdrawToken,
        'idempotency_key' => $overdrawKey,
        'aksi' => 'keluar',
        'nominal' => '70000',
    ], $cookies);
    savings_ledger_assert($overdraw['status'] === 302, 'Overdraw tidak mengikuti alur penolakan yang diharapkan.');
    $afterOverdraw = savings_ledger_snapshot($koneksi, $noInduk);
    savings_ledger_assert(
        abs((float)$afterOverdraw['saldo'] - 60000.0) < 0.001
        && abs((float)$afterOverdraw['total_keluar'] - 40000.0) < 0.001
        && (int)$afterOverdraw['jumlah_keluar'] === 1,
        'Overdraw mengubah saldo atau menambah jurnal keluar.'
    );

    $exactForm = savings_ledger_request($baseUrl . '/tabungan/keluar.php', [], $cookies);
    $exactToken = savings_ledger_csrf($exactForm['body'], 'form-tabungan');
    $exactKey = savings_ledger_idempotency_key($exactForm['body']);
    $idempotencyKeys[] = $exactKey;
    $exactWithdrawal = savings_ledger_request($baseUrl . '/tabungan/proses.php', $common + [
        'csrf_token' => $exactToken,
        'idempotency_key' => $exactKey,
        'aksi' => 'keluar',
        'nominal' => '60000',
    ], $cookies);
    savings_ledger_assert($exactWithdrawal['status'] === 302, 'Penarikan saldo pas tidak mengikuti redirect sukses.');
    $final = savings_ledger_snapshot($koneksi, $noInduk);
    savings_ledger_assert(
        abs((float)$final['saldo']) < 0.001
        && abs((float)$final['total_masuk'] - 100000.0) < 0.001
        && abs((float)$final['total_keluar'] - 100000.0) < 0.001
        && (int)$final['jumlah_masuk'] === 1
        && (int)$final['jumlah_keluar'] === 2,
        'Saldo pas tidak menghasilkan ledger seimbang dan saldo nol.'
    );

    $auditPattern = '%"no_induk":"' . $noInduk . '"%';
    $stmtAudit = $koneksi->prepare(
        "SELECT event_type, entity_type, actor_admin_id, request_id, reason,
                before_data, after_data, metadata
         FROM audit_event
         WHERE after_data LIKE ?
           AND event_type IN ('savings.deposited', 'savings.withdrawn')
         ORDER BY id"
    );
    $stmtAudit->bind_param('s', $auditPattern);
    $stmtAudit->execute();
    $auditRows = $stmtAudit->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtAudit->close();
    $eventTypes = array_column($auditRows, 'event_type');
    savings_ledger_assert(
        count($auditRows) === 3
        && count(array_filter($eventTypes, static fn(string $type): bool => $type === 'savings.deposited')) === 1
        && count(array_filter($eventTypes, static fn(string $type): bool => $type === 'savings.withdrawn')) === 2,
        'Lifecycle tabungan tidak menghasilkan tepat tiga event committed.'
    );
    $expectedBalances = [[0.0, 100000.0], [100000.0, 60000.0], [60000.0, 0.0]];
    foreach ($auditRows as $index => $auditRow) {
        $beforeAudit = json_decode((string)$auditRow['before_data'], true, 512, JSON_THROW_ON_ERROR);
        $afterAudit = json_decode((string)$auditRow['after_data'], true, 512, JSON_THROW_ON_ERROR);
        $metadataAudit = json_decode((string)$auditRow['metadata'], true, 512, JSON_THROW_ON_ERROR);
        savings_ledger_assert(abs((float)$beforeAudit['balance'] - $expectedBalances[$index][0]) < 0.001, 'Saldo before pada audit tabungan tidak sesuai ledger.');
        savings_ledger_assert(abs((float)$afterAudit['balance'] - $expectedBalances[$index][1]) < 0.001, 'Saldo after pada audit tabungan tidak sesuai ledger.');
        savings_ledger_assert((string)$afterAudit['no_induk'] === $noInduk, 'NIS pada audit tabungan tidak sesuai fixture.');
        savings_ledger_assert($metadataAudit['result'] === 'committed' && $metadataAudit['linked_payment'] === false, 'Metadata audit tabungan tidak sesuai kontrak manual.');
        savings_ledger_assert(mb_strlen((string)$auditRow['reason']) >= 5, 'Alasan audit tabungan tidak tersimpan.');
        savings_ledger_assert(preg_match('/^[a-f0-9]{24}$/', (string)$auditRow['request_id']) === 1, 'Request ID audit tabungan tidak valid.');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach ($idempotencyKeys as $idempotencyKey) {
        $stmt = $koneksi->prepare("DELETE FROM mutation_request WHERE scope = 'savings' AND request_key = ?");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $stmt->close();
    }
    if ($noInduk !== '') {
        foreach (['transaksi_m', 'transaksi_k', 'tabungan'] as $table) {
            $stmt = $koneksi->prepare("DELETE FROM $table WHERE NO_INDUK = ?");
            $stmt->bind_param('s', $noInduk);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $koneksi->prepare("DELETE FROM siswa WHERE NO_INDUK = ? AND NAMA = 'UJI LEDGER TABUNGAN'");
        $stmt->bind_param('s', $noInduk);
        $stmt->execute();
        $stmt->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

$stmt = $koneksi->prepare('SELECT COUNT(*) AS total FROM siswa WHERE NO_INDUK = ?');
$stmt->bind_param('s', $noInduk);
$stmt->execute();
$remaining = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
savings_ledger_assert($remaining === 0, 'Fixture siswa tabungan tidak terhapus.');

echo "OK: setoran, penarikan parsial, saldo pas, overdraw, ledger/cache, dan cleanup tabungan.\n";
