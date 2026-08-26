<?php

declare(strict_types=1);

const SPP_LOGIN_MAX_FAILURES = 5;
const SPP_LOGIN_WINDOW_SECONDS = 900;
const SPP_LOGIN_BLOCK_SECONDS = 900;
const SPP_LOGIN_DUMMY_HASH = '$2y$10$n9bXn8uB/Op8FH2mSgoqx.fuYcpLA9xrjLz6xhf1edZcLPOW3vz/C';

function login_rate_limit_key(): string
{
    $configuredKey = (string)(getenv('SPP_RATE_LIMIT_KEY') ?: '');
    if ($configuredKey === '') {
        $databaseSecret = defined('DB_PASS') ? (string)DB_PASS : '';
        $configuredKey = hash('sha256', 'SistemSPP-rate-limit-v1|' . $databaseSecret);
    }
    return $configuredKey;
}

function login_rate_limit_buckets(string $username, ?string $source = null): array
{
    $identity = strtolower(trim($username));
    $source = $source ?? (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = login_rate_limit_key();

    return [
        'account' => hash_hmac('sha256', "account\0" . $identity, $key),
        'source' => hash_hmac('sha256', "source\0" . $source, $key),
        'pair' => hash_hmac('sha256', "pair\0" . $identity . "\0" . $source, $key),
    ];
}

function login_rate_limit_cleanup(mysqli $database): void
{
    $database->query(
        'DELETE FROM login_rate_limit
         WHERE updated_at < (NOW() - INTERVAL 1 DAY)
         ORDER BY updated_at
         LIMIT 100'
    );
}

function login_rate_limit_lock(mysqli $database, array $bucketHashes): array
{
    $orderedHashes = array_values(array_unique(array_values($bucketHashes)));
    sort($orderedHashes, SORT_STRING);
    if (!$orderedHashes) {
        throw new InvalidArgumentException('Bucket pembatasan login tidak tersedia.');
    }

    $database->begin_transaction();

    $stmtInsert = $database->prepare(
        'INSERT IGNORE INTO login_rate_limit
         (bucket_hash, failure_count, window_started_at, blocked_until)
         VALUES (?, 0, NOW(), NULL)'
    );
    foreach ($orderedHashes as $bucketHash) {
        $stmtInsert->bind_param('s', $bucketHash);
        $stmtInsert->execute();
    }
    $stmtInsert->close();

    $stmtSelect = $database->prepare(
        'SELECT failure_count, window_started_at, blocked_until
         FROM login_rate_limit
         WHERE bucket_hash = ?
         FOR UPDATE'
    );
    $stmtReset = $database->prepare(
        'UPDATE login_rate_limit
         SET failure_count = 0, window_started_at = NOW(), blocked_until = NULL
         WHERE bucket_hash = ?'
    );

    $failureCounts = [];
    $retryAfter = 0;
    $now = time();
    foreach ($orderedHashes as $bucketHash) {
        $stmtSelect->bind_param('s', $bucketHash);
        $stmtSelect->execute();
        $state = $stmtSelect->get_result()->fetch_assoc();
        if (!$state) {
            throw new RuntimeException('Status pembatasan login tidak tersedia.');
        }

        $blockedUntil = $state['blocked_until'] !== null ? strtotime((string)$state['blocked_until']) : false;
        if ($blockedUntil !== false && $blockedUntil > $now) {
            $retryAfter = max($retryAfter, $blockedUntil - $now);
            $failureCounts[$bucketHash] = (int)$state['failure_count'];
            continue;
        }

        $windowStarted = strtotime((string)$state['window_started_at']) ?: 0;
        if ($blockedUntil !== false || ($now - $windowStarted) >= SPP_LOGIN_WINDOW_SECONDS) {
            $stmtReset->bind_param('s', $bucketHash);
            $stmtReset->execute();
            $state['failure_count'] = 0;
        }
        $failureCounts[$bucketHash] = (int)$state['failure_count'];
    }
    $stmtSelect->close();
    $stmtReset->close();

    return ['failure_counts' => $failureCounts, 'retry_after' => max(0, $retryAfter)];
}

function login_rate_limit_record_failure(mysqli $database, array $failureCounts): bool
{
    ksort($failureCounts, SORT_STRING);
    $blocked = false;
    foreach ($failureCounts as $bucketHash => $currentFailures) {
        $nextFailures = (int)$currentFailures + 1;
        if ($nextFailures >= SPP_LOGIN_MAX_FAILURES) {
            $stmt = $database->prepare(
                'UPDATE login_rate_limit
                 SET failure_count = ?, blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE bucket_hash = ?'
            );
            $blockSeconds = SPP_LOGIN_BLOCK_SECONDS;
            $stmt->bind_param('iis', $nextFailures, $blockSeconds, $bucketHash);
            $blocked = true;
        } else {
            $stmt = $database->prepare(
                'UPDATE login_rate_limit
                 SET failure_count = ?, blocked_until = NULL
                 WHERE bucket_hash = ?'
            );
            $stmt->bind_param('is', $nextFailures, $bucketHash);
        }
        $stmt->execute();
        $stmt->close();
    }
    return $blocked;
}

function login_rate_limit_clear(mysqli $database, array $bucketHashes): void
{
    $orderedHashes = array_values(array_unique(array_values($bucketHashes)));
    sort($orderedHashes, SORT_STRING);
    $stmt = $database->prepare('DELETE FROM login_rate_limit WHERE bucket_hash = ?');
    foreach ($orderedHashes as $bucketHash) {
        $stmt->bind_param('s', $bucketHash);
        $stmt->execute();
    }
    $stmt->close();
}
