<?php
/** CLI parsing must work without a database or a maintenance marker. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$script = realpath(__DIR__ . '/../scripts/migrate_legacy_operational.php');
$environment = getenv();
$environment['SPP_DB_HOST'] = '127.0.0.1';
$environment['SPP_DB_PORT'] = '1'; // Help/invalid options must not attempt a connection.
foreach ([
    [['--help'], 0, 'Skema Migrasi Operasional Legacy'],
    [['-h'], 0, '--expect-db'],
    [['--aplpy'], 1, 'Opsi tidak dikenal'],
    [['--expect-db='], 1, 'Opsi tidak dikenal'],
    [['--expect-db=../../db_spp'], 1, 'Opsi tidak dikenal'],
    [['--expect-db=db_spp', '--expect-db=db_spp'], 1, 'hanya boleh diberikan sekali'],
    [['--check', '--apply'], 1, 'Pilih --check atau --apply'],
] as [$arguments, $expectedExit, $expectedOutput]) {
    $process = proc_open(array_merge([PHP_BINARY, $script], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Tidak dapat menjalankan CLI.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $actualExit = proc_close($process);
    if ($actualExit !== $expectedExit || !str_contains($output, $expectedOutput)
        || str_contains($output, 'Koneksi database')) {
        throw new RuntimeException('Regresi parsing CLI: ' . implode(' ', $arguments));
    }
}
echo "PASS: bantuan CLI tanpa database, opsi salah, target tidak valid/ganda, dan mode bertentangan.\n";
