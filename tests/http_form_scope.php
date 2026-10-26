<?php
/** Strict opt-in for a separately launched test server that retains the original database name. */
function spp_test_isolated_manifest(?mysqli $db = null): array {
    $file = (string)getenv('SPP_TEST_ISOLATED_INSTANCE');
    $resolved = $file !== '' ? realpath($file) : false;
    $normal = static fn(string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    $repository = $normal((string)realpath(__DIR__ . '/..'));
    if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || $resolved === false || !is_file($resolved)
        || is_link($file) || !preg_match('~^[a-zA-Z]:[\\\\/]~', $file)
        || preg_match('~(^|[\\\\/])\.\.?([\\\\/]|$)~', $file)
        || $normal($file) !== $normal($resolved)
        || str_starts_with($normal($resolved), $repository . '/')
        || str_starts_with($normal($resolved), 'c:/laragon/www/')) {
        throw new RuntimeException('Use a private explicit isolated-instance manifest outside the web root.');
    }
    $manifest = json_decode((string)file_get_contents($resolved), true, 32, JSON_THROW_ON_ERROR);
    foreach (['database', 'host', 'port', 'server_uuid', 'datadir', 'source_server_uuid', 'source_datadir'] as $key) {
        if (!isset($manifest[$key]) || !is_scalar($manifest[$key])) throw new RuntimeException('Isolated-instance manifest is incomplete.');
    }
    if (($manifest['version'] ?? 0) !== 1 || ($manifest['isolated_test'] ?? null) !== true
        || !preg_match('/^db_spp(?:_test[0-9]+)?$/D',(string)$manifest['database']) || $manifest['host'] !== '127.0.0.1'
        || !preg_match('/^[a-f0-9-]{36}$/Di', (string)$manifest['server_uuid'])
        || !preg_match('/^[a-f0-9-]{36}$/Di', (string)$manifest['source_server_uuid'])
        || strtolower($manifest['server_uuid']) === strtolower($manifest['source_server_uuid'])
        || $normal($manifest['datadir']) === $normal($manifest['source_datadir'])
        || !preg_match('~^[a-zA-Z]:[\\\\/]~', $manifest['datadir'])
        || !preg_match('~^[a-zA-Z]:[\\\\/]~', $manifest['source_datadir'])
        || filter_var($manifest['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]) === false
        || (int)$manifest['port'] === 3306
        || getenv('SPP_DB_HOST') !== $manifest['host']
        || (int)getenv('SPP_DB_PORT') !== (int)$manifest['port']
        || getenv('SPP_DB_NAME') !== $manifest['database']) {
        throw new RuntimeException('Isolated-instance environment or source identity differs.');
    }
    if ($db !== null) {
        $actual = $db->query('SELECT DATABASE() database_name,@@server_uuid server_uuid,@@datadir datadir,@@port port')->fetch_assoc();
        if ($actual['database_name'] !== $manifest['database']
            || strtolower($actual['server_uuid']) !== strtolower($manifest['server_uuid'])
            || $normal($actual['datadir']) !== $normal($manifest['datadir'])
            || (int)$actual['port'] !== (int)$manifest['port']) {
            throw new RuntimeException('Connected database is not the owned isolated test instance.');
        }
    }
    return $manifest;
}

function spp_test_assert_database_target(?mysqli $db = null): void {
    $name = (string)getenv('SPP_DB_NAME');
    if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
        throw new RuntimeException('Tests require explicit CLI mutation opt-in.');
    }
    if (getenv('SPP_TEST_ISOLATED_INSTANCE')) {
        spp_test_isolated_manifest($db);
        return;
    }
    if (!preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $name)) {
        throw new RuntimeException('Use an audit clone or a separately owned isolated test instance.');
    }
    if ($db !== null && $db->query('SELECT DATABASE()')->fetch_row()[0] !== $name) {
        throw new RuntimeException('The CLI connection does not use the requested audit database.');
    }
}

/** Prove the HTTP process and CLI test use the same disposable database. */
function spp_test_assert_http_clone(string $base, string $database): void {
    $url = parse_url($base);
    if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
        || ($url['scheme'] ?? '') !== 'http'
        || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('HTTP test requires a flagged disposable clone and loopback server.');
    }
    spp_test_assert_database_target();
    $body = file_get_contents(rtrim($base, '/') . '/tests/browser_clone_identity.php', false,
        stream_context_create(['http'=>['ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]));
    $identity = json_decode((string)$body, true);
    if (!str_contains($http_response_header[0] ?? '', ' 200 ')
        || $body === false || ($identity['database'] ?? '') !== $database) {
        throw new RuntimeException('HTTP server does not use the named disposable database.');
    }
    if (getenv('SPP_TEST_ISOLATED_INSTANCE')) {
        $manifest = spp_test_isolated_manifest();
        if (($identity['isolated_test'] ?? null) !== true
            || strtolower((string)($identity['server_uuid'] ?? '')) !== strtolower($manifest['server_uuid'])
            || strtolower(rtrim(str_replace('\\', '/', (string)($identity['datadir'] ?? '')), '/')) !== strtolower(rtrim(str_replace('\\', '/', $manifest['datadir']), '/'))
            || (int)($identity['port'] ?? 0) !== (int)$manifest['port']) {
            throw new RuntimeException('HTTP server does not use the owned isolated test instance.');
        }
    }
}

/** Select an application form so navigation CSRF tokens cannot satisfy a test. */
function spp_test_form_scope(string $html, string $requiredField): string {
    preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $forms);
    foreach ($forms[0] ?? [] as $form) {
        if (preg_match('/\bname=["\x27]' . preg_quote($requiredField, '/') . '["\x27]/i', $form)) {
            return $form;
        }
    }
    throw new RuntimeException('Expected application form with field ' . $requiredField . ' is missing.');
}
