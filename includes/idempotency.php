<?php

final class IdempotencyReplayException extends RuntimeException
{
}

function idempotency_generate_key(): string
{
    return bin2hex(random_bytes(32));
}

function idempotency_claim(mysqli $database, string $scope, string $requestKey, ?int $actorAdminId): void
{
    if (!preg_match('/^[a-z][a-z0-9._-]{1,39}$/', $scope)) {
        throw new InvalidArgumentException('Scope idempotency tidak valid.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $requestKey)) {
        throw new InvalidArgumentException('Form transaksi sudah tidak valid. Muat ulang halaman lalu coba kembali.');
    }

    try {
        $statement = $database->prepare(
            'INSERT INTO mutation_request (scope, request_key, actor_admin_id) VALUES (?, ?, ?)'
        );
        $statement->bind_param('ssi', $scope, $requestKey, $actorAdminId);
        $statement->execute();
        $statement->close();
    } catch (mysqli_sql_exception $exception) {
        if ((int)$exception->getCode() === 1062) {
            throw new IdempotencyReplayException(
                'Transaksi ini sudah pernah diproses. Muat ulang halaman untuk membuat transaksi baru.',
                0,
                $exception
            );
        }
        throw $exception;
    }
}
