<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_disposable_audit_database($koneksi);

function riwayat_sqli_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function riwayat_sqli_request(string $url, array &$cookies): array {
    $headers = ['Accept: text/html'];
    if ($cookies) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(
            static fn(string $name, string $value): string => $name . '=' . $value,
            array_keys($cookies),
            array_values($cookies)
        ));
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Request riwayat tabungan gagal.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
            $status = (int)$match[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => $body];
}

function riwayat_sqli_login(string $baseUrl, array &$cookies): void {
    $login = riwayat_sqli_request($baseUrl . '/login.php', $cookies);
    riwayat_sqli_assert($login['status'] === 200, 'Login GET tidak mengembalikan 200.');
    riwayat_sqli_assert(
        preg_match('/<form\b[^>]*id="form-login"[^>]*>.*?name="csrf_token"\s+value="([^"]+)"/si', $login['body'], $match) === 1,
        'Token login tidak ditemukan.'
    );
    $postHeaders = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $postHeaders[] = 'Cookie: ' . implode('; ', array_map(
            static fn(string $name, string $value): string => $name . '=' . $value,
            array_keys($cookies),
            array_values($cookies)
        ));
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $postHeaders),
            'content' => http_build_query([
                'csrf_token' => html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),
                'username' => (string)getenv('SPP_TEST_ADMIN_USERNAME'),
                'password' => (string)getenv('SPP_TEST_ADMIN_PASSWORD'),
            ]),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($baseUrl . '/login.php', false, $context);
    if ($body === false) {
        throw new RuntimeException('Login POST gagal.');
    }
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $statusMatch) && (int)$statusMatch[1] !== 302) {
            throw new RuntimeException('Login POST tidak mengembalikan redirect.');
        }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $cookieMatch)) {
            $cookies[$cookieMatch[1]] = $cookieMatch[2];
        }
    }
}

function riwayat_sqli_table_rows(string $html): int {
    if (!preg_match('/<table\b[^>]*id="tbl-riwayat"[^>]*>.*?<tbody>(.*?)<\/tbody>/si', $html, $tableMatch)) {
        throw new RuntimeException('Tabel riwayat tidak ditemukan.');
    }
    return substr_count($tableMatch[1], 'class="badge-nis"');
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
riwayat_sqli_assert($baseUrl === 'http://127.0.0.1:8099', 'Test hanya boleh memakai server audit 8099.');
riwayat_sqli_assert($username !== '' && $password !== '', 'Kredensial test wajib tersedia melalui environment.');

$source = $koneksi->query("SELECT NO_INDUK, YEAR(TANGGAL) tahun, MONTH(TANGGAL) bulan
    FROM (SELECT NO_INDUK,TANGGAL FROM transaksi_m UNION ALL SELECT NO_INDUK,TANGGAL FROM transaksi_k) tx
    WHERE TANGGAL IS NOT NULL ORDER BY TANGGAL DESC LIMIT 1")->fetch_assoc();
riwayat_sqli_assert((bool)$source, 'Fixture audit tidak memiliki transaksi tabungan untuk DAST filter.');
$nis = (string)$source['NO_INDUK'];
$month = (string)(int)$source['bulan'];
$year = (string)(int)$source['tahun'];

$cookies = [];
try {
    riwayat_sqli_login($baseUrl, $cookies);
    $query = ['bulan' => $month, 'tahun' => $year, 'per_page' => 50];
    $unfiltered = riwayat_sqli_request($baseUrl . '/tabungan/riwayat.php?' . http_build_query($query), $cookies);
    riwayat_sqli_assert($unfiltered['status'] === 200, 'Filter kosong tidak mengembalikan 200.');
    $unfilteredRows = riwayat_sqli_table_rows($unfiltered['body']);

    $valid = riwayat_sqli_request($baseUrl . '/tabungan/riwayat.php?' . http_build_query($query + ['nis' => $nis]), $cookies);
    riwayat_sqli_assert($valid['status'] === 200, 'Filter NIS valid tidak mengembalikan 200.');
    $validRows = riwayat_sqli_table_rows($valid['body']);
    riwayat_sqli_assert($validRows > 0 && $validRows <= $unfilteredRows, 'Filter NIS valid tidak mengisolasi transaksi yang ada.');
    riwayat_sqli_assert(substr_count($valid['body'], 'badge-nis">' . htmlspecialchars($nis, ENT_QUOTES, 'UTF-8')) >= $validRows, 'NIS valid tidak tampil sebagai hasil exact match.');

    $payload = "' OR 1=1 --";
    $malicious = riwayat_sqli_request($baseUrl . '/tabungan/riwayat.php?' . http_build_query($query + ['nis' => $payload]), $cookies);
    riwayat_sqli_assert($malicious['status'] === 200, 'Payload SQL tidak menghasilkan response normal.');
    $maliciousRows = riwayat_sqli_table_rows($malicious['body']);
    riwayat_sqli_assert($maliciousRows === 0, 'Payload SQL memperluas hasil transaksi.');
    riwayat_sqli_assert(!preg_match('/SQLSTATE|mysqli_sql_exception|syntax error|Fatal error/i', $malicious['body']), 'Payload SQL membocorkan error database.');

    echo "OK: filter kosong, NIS exact, dan payload SQL literal tanpa perluasan hasil.\n";
} finally {
    $cookies = [];
}
