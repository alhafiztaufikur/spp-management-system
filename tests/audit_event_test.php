<?php

declare(strict_types=1);

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
require_once __DIR__ . '/../includes/audit.php';

test_require_audit_database($koneksi);

function audit_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$actor = $koneksi->query(
    "SELECT id, username, nama FROM admin
     WHERE role = 'admin' AND password_reset_required = 0
     ORDER BY id LIMIT 1"
)->fetch_assoc();
audit_test_assert((bool)$actor, 'Akun admin modern untuk fixture audit tidak tersedia.');

$_SESSION = [
    'admin_id' => (int)$actor['id'],
    'admin_username' => (string)$actor['username'],
    'admin_nama' => (string)$actor['nama'],
];

$eventId = 0;
$failure = null;
$koneksi->begin_transaction();

try {
    audit_test_assert(
        audit_require_reason('  Koreksi kas uji  ') === 'Koreksi kas uji',
        'Normalisasi alasan audit tidak sesuai.'
    );
    $arrayReasonRejected = false;
    try {
        audit_require_reason(['reason' => 'Koreksi kas uji']);
    } catch (InvalidArgumentException) {
        $arrayReasonRejected = true;
    }
    audit_test_assert($arrayReasonRejected, 'Alasan audit berbentuk array harus ditolak sebelum disimpan.');

    $eventId = audit_event_write(
        $koneksi,
        'test.audit_event',
        'test_fixture',
        'fixture-1',
        'verify',
        [
            'amount' => 1000,
            'password' => 'SECRET-BEFORE',
            'nested' => ['csrf_token' => 'SECRET-CSRF'],
        ],
        [
            'amount' => 1500,
            'session_id' => 'SECRET-SESSION',
        ],
        'Koreksi kas uji',
        ['result' => 'rolled_back_test', 'cookie' => 'SECRET-COOKIE']
    );
    audit_test_assert($eventId > 0, 'Insert audit tidak menghasilkan ID.');

    $stmt = $koneksi->prepare('SELECT * FROM audit_event WHERE id = ?');
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    audit_test_assert((bool)$event, 'Event audit yang baru dibuat tidak ditemukan.');
    audit_test_assert((int)$event['actor_admin_id'] === (int)$actor['id'], 'Actor ID audit tidak sesuai session.');
    audit_test_assert($event['actor_name_snapshot'] === $actor['nama'], 'Snapshot nama actor tidak sesuai.');
    audit_test_assert($event['request_id'] === security_request_id(), 'Request ID audit tidak sama dengan correlation ID request.');
    audit_test_assert(preg_match('/^[a-f0-9]{24}$/', $event['request_id']) === 1, 'Format request ID audit tidak valid.');
    audit_test_assert($event['reason'] === 'Koreksi kas uji', 'Alasan audit tidak tersimpan utuh.');

    $serialized = implode('|', [
        (string)$event['before_data'],
        (string)$event['after_data'],
        (string)$event['metadata'],
    ]);
    foreach (['SECRET-BEFORE', 'SECRET-CSRF', 'SECRET-SESSION', 'SECRET-COOKIE'] as $secret) {
        audit_test_assert(!str_contains($serialized, $secret), 'Secret bocor ke payload audit: ' . $secret);
    }
    audit_test_assert(substr_count($serialized, '[REDACTED]') >= 4, 'Redaksi payload audit belum mencakup seluruh key sensitif.');
    audit_test_assert(
        json_decode((string)$event['before_data'], true, 512, JSON_THROW_ON_ERROR)['amount'] === 1000,
        'Payload before audit tidak dapat dibaca kembali.'
    );

    $updateBlocked = false;
    try {
        $stmt = $koneksi->prepare("UPDATE audit_event SET reason = 'diubah' WHERE id = ?");
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        $updateBlocked = str_contains($exception->getMessage(), 'append-only');
    }
    audit_test_assert($updateBlocked, 'Trigger append-only tidak memblokir UPDATE audit_event.');

    $deleteBlocked = false;
    try {
        $stmt = $koneksi->prepare('DELETE FROM audit_event WHERE id = ?');
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        $deleteBlocked = str_contains($exception->getMessage(), 'append-only');
    }
    audit_test_assert($deleteBlocked, 'Trigger append-only tidak memblokir DELETE audit_event.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
}

if ($eventId > 0) {
    $stmt = $koneksi->prepare('SELECT COUNT(*) AS total FROM audit_event WHERE id = ?');
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $remaining = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    audit_test_assert($remaining === 0, 'Fixture audit transaksional tidak ter-rollback.');
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK: audit_event menyimpan actor/request/reason, meredaksi secret, append-only, dan rollback bersih.\n";
