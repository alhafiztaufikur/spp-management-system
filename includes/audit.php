<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function audit_redact_value(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $redacted = [];
    foreach ($value as $key => $item) {
        $keyText = strtolower((string)$key);
        if (preg_match('/password|passwd|secret|token|cookie|csrf|hash|^session$|^session_(id|token|cookie)$/', $keyText)) {
            $redacted[$key] = '[REDACTED]';
            continue;
        }
        $redacted[$key] = audit_redact_value($item);
    }
    return $redacted;
}

function audit_json(?array $value): ?string
{
    if ($value === null) {
        return null;
    }

    $encoded = json_encode(
        audit_redact_value($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (strlen($encoded) > 65535) {
        throw new LengthException('Payload audit melebihi batas yang diizinkan.');
    }
    return $encoded;
}

function audit_require_reason(mixed $value, string $label = 'Alasan perubahan'): string
{
    if (!is_scalar($value)) {
        throw new InvalidArgumentException($label . ' wajib berupa teks tunggal.');
    }
    $reason = trim((string)$value);
    $length = mb_strlen($reason);
    if ($length < 5 || $length > 255) {
        throw new InvalidArgumentException($label . ' wajib berisi 5 sampai 255 karakter.');
    }
    return $reason;
}

function audit_event_write(
    mysqli $database,
    string $eventType,
    string $entityType,
    int|string|null $entityId,
    string $action,
    ?array $before = null,
    ?array $after = null,
    ?string $reason = null,
    ?array $metadata = null
): int {
    $eventType = trim($eventType);
    $entityType = trim($entityType);
    $action = trim($action);
    $entityIdText = $entityId === null ? null : trim((string)$entityId);
    $reason = $reason === null ? null : trim($reason);
    if ($reason === '') {
        $reason = null;
    }

    if (!preg_match('/^[a-z0-9._-]{1,50}$/', $eventType)
        || !preg_match('/^[a-z0-9._-]{1,40}$/', $entityType)
        || !preg_match('/^[a-z0-9._-]{1,40}$/', $action)
        || ($entityIdText !== null && strlen($entityIdText) > 64)
        || ($reason !== null && mb_strlen($reason) > 255)) {
        throw new InvalidArgumentException('Metadata audit tidak valid.');
    }

    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $actorIdValue = $actorId > 0 ? $actorId : null;
    $actorName = trim((string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system'));
    if ($actorName === '') {
        $actorName = 'system';
    }
    $actorName = mb_substr($actorName, 0, 100);
    $requestId = function_exists('security_request_id')
        ? security_request_id()
        : strtoupper(bin2hex(random_bytes(12)));

    $beforeJson = audit_json($before);
    $afterJson = audit_json($after);
    $auditContext = [
        'actor_username_snapshot' => (string)($_SESSION['admin_username'] ?? 'system'),
        'actor_role_snapshot' => (string)($_SESSION['admin_role'] ?? 'system'),
    ];
    $metadataJson = audit_json($auditContext + ($metadata ?? []));

    $stmt = $database->prepare(
        'INSERT INTO audit_event
         (event_type, entity_type, entity_id, action, actor_admin_id,
          actor_name_snapshot, request_id, reason, before_data, after_data, metadata)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'ssssissssss',
        $eventType,
        $entityType,
        $entityIdText,
        $action,
        $actorIdValue,
        $actorName,
        $requestId,
        $reason,
        $beforeJson,
        $afterJson,
        $metadataJson
    );
    $stmt->execute();
    $id = (int)$database->insert_id;
    $stmt->close();
    return $id;
}
