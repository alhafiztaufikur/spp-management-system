<?php

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_disposable_audit_database($koneksi);

function input_corpus_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function input_corpus_request(
    string $url,
    string $method,
    array $data,
    array &$cookies,
    ?string $rawBody = null,
    string $contentType = 'application/x-www-form-urlencoded'
): array
{
    $headers = [];
    if ($rawBody !== null || $data) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            if ($value !== '') {
                $pairs[] = $name . '=' . $value;
            }
        }
        if ($pairs) {
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $rawBody !== null ? $rawBody : ($data ? http_build_query($data) : ''),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $startedAt = hrtime(true);
    $body = file_get_contents($url, false, $context);
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
    if ($body === false) {
        throw new RuntimeException('Request corpus input gagal.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
            $status = (int)$match[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => $body, 'elapsed_ms' => $elapsedMs];
}

function input_corpus_csrf(string $body): string
{
    if (!preg_match('/<form\b[^>]*id="form-login"[^>]*>(.*?)<\/form>/si', $body, $formMatch)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $formMatch[1], $match)) {
        throw new RuntimeException('Token corpus login tidak ditemukan.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function input_corpus_form_csrf(string $body): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $match)) {
        throw new RuntimeException('Token corpus form tidak ditemukan.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function input_corpus_mutation_snapshot(mysqli $db): array
{
    $tables = ['bayar', 'bayar_du', 'transaksi_m', 'transaksi_k', 'master_kelas', 'master_biaya_lain', 'tahun_ajaran', 'siswa', 'admin'];
    $snapshot = [];
    foreach ($tables as $table) {
        $row = $db->query('SELECT COUNT(*) AS total FROM `' . $table . '`')->fetch_assoc();
        $snapshot[$table] = (int)$row['total'];
    }
    return $snapshot;
}

$baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
    $payload = "' OR 1=1 --";
    $payloads = [
        $payload,
        "' AND 1=2 --",
        '"',
        '\\',
        '%00',
        "\0",
    ];
$failure = null;

try {
    input_corpus_assert($baseUrl === 'http://127.0.0.1:8099', 'Input corpus hanya memakai server audit 8099.');
    input_corpus_assert($username !== '' && $password !== '', 'Credential corpus wajib melalui environment.');
    $cookies = [];
    $loginPage = input_corpus_request($baseUrl . '/login.php', 'GET', [], $cookies);
    $login = input_corpus_request($baseUrl . '/login.php', 'POST', [
        'csrf_token' => input_corpus_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookies);
    input_corpus_assert($login['status'] === 302, 'Login corpus gagal.');
    $sqlMarkers = '/SQLSTATE|mysqli_sql_exception|You have an error in your SQL syntax|Unknown column|Fatal error/i';

    $getOnlyYear = '2098/2099';
    $yearProbe = $koneksi->prepare('SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label=?');
    $yearProbe->bind_param('s', $getOnlyYear);
    $yearProbe->execute();
    $yearBefore = (int)$yearProbe->get_result()->fetch_assoc()['total'];
    $yearProbe->close();
    $getOnlyResponse = input_corpus_request($baseUrl . '/master_daftar_ulang.php?' . http_build_query(['tahun' => $getOnlyYear]), 'GET', [], $cookies);
    input_corpus_assert($getOnlyResponse['status'] !== 500 && !preg_match($sqlMarkers, $getOnlyResponse['body']), 'GET Master Daftar Ulang menghasilkan error.');
    $yearProbe = $koneksi->prepare('SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label=?');
    $yearProbe->bind_param('s', $getOnlyYear);
    $yearProbe->execute();
    $yearAfter = (int)$yearProbe->get_result()->fetch_assoc()['total'];
    $yearProbe->close();
    input_corpus_assert($yearAfter === $yearBefore, 'GET Master Daftar Ulang memutasi tahun ajaran.');

    $cases = [
        ['/tabungan/riwayat.php', ['nis' => $payload, 'bulan' => '8', 'tahun' => '2026']],
        ['/pembayaran/lihat.php', ['search' => $payload, 'bulan' => '8', 'tahun' => '2026']],
        ['/pembayaran/riwayat_daftar_ulang.php', ['q' => $payload, 'tahun_ajaran' => $payload]],
        ['/siswa/daftar.php', ['q' => $payload, 'kelas' => $payload, 'page' => $payload]],
        ['/laporan/index.php', ['tanggal_awal' => $payload, 'tanggal_akhir' => $payload, 'q' => $payload]],
        ['/laporan/template.php', ['template' => 'penerimaan', 'q' => $payload, 'operator' => $payload, 'kelas' => $payload]],
        ['/laporan/rekap_kelas.php', ['kelas' => $payload, 'q' => $payload, 'bulan' => $payload]],
        ['/laporan/detail_siswa.php', ['nis' => $payload, 'q' => $payload]],
        ['/laporan/cetak_struk.php', ['id' => $payload]],
        ['/laporan/cetak_struk_tahunan.php', ['batch' => $payload]],
        ['/index.php', ['q' => $payload]],
        ['/dashboard.php', ['q' => $payload]],
        ['/role_management.php', ['q' => $payload]],
        ['/master_kelas.php', ['edit' => $payload, 'q' => $payload]],
        ['/master_biaya_lain.php', ['edit' => $payload, 'q' => $payload]],
        ['/master_daftar_ulang.php', ['tahun' => $payload, 'tahun_ajaran' => $payload]],
        ['/pembayaran/form.php', ['q' => $payload]],
        ['/pembayaran/edit.php', ['id' => $payload]],
        ['/pembayaran/riwayat_daftar_ulang.php', ['q' => $payload, 'tahun_ajaran' => $payload]],
        ['/tabungan/masuk.php', ['nis' => $payload]],
        ['/tabungan/keluar.php', ['nis' => $payload]],
        ['/tabungan/get_saldo.php', ['nis' => $payload]],
        ['/laporan/global.php', ['q' => $payload]],
        ['/laporan/export_global.php', ['template' => 'penerimaan', 'format' => 'print', 'q' => $payload, 'operator' => $payload]],
        ['/laporan/export_excel.php', ['tanggal_awal' => $payload, 'tanggal_akhir' => $payload, 'download' => $payload]],
        ['/laporan/export_pdf.php', ['tanggal_awal' => $payload, 'tanggal_akhir' => $payload, 'contoh' => $payload, 'mode' => $payload]],
    ];
    foreach ($cases as [$path, $query]) {
        foreach ($payloads as $payloadVariant) {
            $variantQuery = array_map(
                static fn($value) => $value === $payload ? $payloadVariant : $value,
                $query
            );
            $response = input_corpus_request($baseUrl . $path . '?' . http_build_query($variantQuery), 'GET', [], $cookies);
            input_corpus_assert($response['status'] !== 500, "Input corpus {$path} menghasilkan HTTP 500.");
            input_corpus_assert(!preg_match($sqlMarkers, $response['body']), "Input corpus {$path} membocorkan error SQL.");
        }
    }

    // Time-based SQLi probes run only against simple read-only pages. A
    // prepared statement must treat the payload as a literal and remain close
    // to the baseline latency; an injected SLEEP(3) would exceed this bound.
    $timePayload = "' OR SLEEP(3) OR '1'='1";
    $timeCases = [
        ['/tabungan/riwayat.php', ['nis' => 'literal', 'bulan' => '8', 'tahun' => '2026'], 'nis'],
        ['/pembayaran/lihat.php', ['search' => 'literal', 'bulan' => '8', 'tahun' => '2026'], 'search'],
        ['/siswa/daftar.php', ['q' => 'literal'], 'q'],
        ['/laporan/template.php', ['template' => 'penerimaan', 'q' => 'literal'], 'q'],
    ];
    foreach ($timeCases as [$path, $baselineQuery, $timeField]) {
        $baseline = input_corpus_request($baseUrl . $path . '?' . http_build_query($baselineQuery), 'GET', [], $cookies);
        input_corpus_assert($baseline['status'] !== 500 && !preg_match($sqlMarkers, $baseline['body']), "Baseline time probe {$path} menghasilkan error.");
        $timeQuery = $baselineQuery;
        $timeQuery[$timeField] = $timePayload;
        $timeResponse = input_corpus_request($baseUrl . $path . '?' . http_build_query($timeQuery), 'GET', [], $cookies);
        input_corpus_assert($timeResponse['status'] !== 500 && !preg_match($sqlMarkers, $timeResponse['body']), "Time-based probe {$path} menghasilkan error SQL/HTTP 500.");
        $timeLimitMs = max(2500.0, ((float)$baseline['elapsed_ms'] * 6) + 500.0);
        input_corpus_assert(
            (float)$timeResponse['elapsed_ms'] <= $timeLimitMs,
            "Time-based probe {$path} melampaui batas latency (" . round((float)$timeResponse['elapsed_ms']) . "ms > " . round($timeLimitMs) . "ms)."
        );
    }
    $arrayResponse = input_corpus_request($baseUrl . '/tabungan/riwayat.php?' . http_build_query(['nis' => [$payload], 'page' => ['1']]), 'GET', [], $cookies);
    input_corpus_assert($arrayResponse['status'] !== 500 && !preg_match($sqlMarkers, $arrayResponse['body']), 'Array/scalar confusion pada riwayat tabungan menghasilkan error.');
    foreach (['/tabungan/get_saldo.php', '/tabungan/masuk.php', '/tabungan/keluar.php', '/pembayaran/lihat.php'] as $scalarRoute) {
        $scalarResponse = input_corpus_request($baseUrl . $scalarRoute . '?' . http_build_query(['nis' => [$payload]]), 'GET', [], $cookies);
        input_corpus_assert($scalarResponse['status'] !== 500 && !preg_match($sqlMarkers, $scalarResponse['body']), 'Array/scalar confusion pada ' . $scalarRoute . ' menghasilkan error.');
    }
    $reportArrayCases = [
        ['/laporan/index.php', ['bulan' => [$payload], 'tahun' => [$payload], 'jenis_laporan' => [$payload]]],
        ['/laporan/template.php', ['template' => 'penerimaan', 'q' => [$payload], 'kelas' => [$payload], 'per_page' => [$payload]]],
        ['/laporan/export_global.php', ['template' => 'penerimaan', 'format' => 'print', 'q' => [$payload], 'operator' => [$payload]]],
        ['/laporan/export_excel.php', ['bulan' => [$payload], 'tahun' => [$payload], 'tanggal_awal' => [$payload], 'download' => [$payload]]],
        ['/laporan/export_pdf.php', ['bulan' => [$payload], 'tahun' => [$payload], 'tanggal_awal' => [$payload], 'contoh' => [$payload]]],
        ['/laporan/detail_siswa.php', ['nis' => [$payload], 'q' => [$payload]]],
        ['/laporan/rekap_kelas.php', ['kelas' => [$payload], 'bulan' => [$payload], 'tahun' => [$payload], 'q' => [$payload]]],
        ['/laporan/cetak_struk.php', ['id' => [$payload]]],
        ['/laporan/cetak_struk_tahunan.php', ['batch' => [$payload]]],
    ];
    foreach ($reportArrayCases as [$path, $query]) {
        $arrayReportResponse = input_corpus_request($baseUrl . $path . '?' . http_build_query($query), 'GET', [], $cookies);
        input_corpus_assert($arrayReportResponse['status'] !== 500 && !preg_match($sqlMarkers, $arrayReportResponse['body']), 'Array/scalar confusion pada ' . $path . ' menghasilkan error.');
    }
    $savingsForm = input_corpus_request($baseUrl . '/tabungan/masuk.php', 'GET', [], $cookies);
    $savingsPost = input_corpus_request($baseUrl . '/tabungan/proses.php', 'POST', [
        'csrf_token' => input_corpus_form_csrf($savingsForm['body']),
        'aksi' => [$payload], 'no_induk' => [$payload], 'tanggal' => [$payload],
        'nominal' => [$payload], 'keterangan' => [$payload], 'idempotency_key' => [$payload],
    ], $cookies);
    input_corpus_assert($savingsPost['status'] !== 500 && !preg_match($sqlMarkers, $savingsPost['body']), 'Array/scalar confusion pada POST tabungan menghasilkan error.');
    $paymentForm = input_corpus_request($baseUrl . '/pembayaran/form.php', 'GET', [], $cookies);
    $paymentPost = input_corpus_request($baseUrl . '/pembayaran/proses.php', 'POST', [
        'csrf_token' => input_corpus_form_csrf($paymentForm['body']),
        'aksi' => ['input'], 'idempotency_key' => [$payload], 'no_induk' => [$payload],
        'bulan_bayar' => [$payload], 'tahun_bayar' => [$payload], 'sistem_pembayaran' => [$payload],
        'uang_spp' => [$payload], 'uang_du' => [$payload], 'catatan' => [$payload],
    ], $cookies);
    input_corpus_assert($paymentPost['status'] !== 500 && !preg_match($sqlMarkers, $paymentPost['body']), 'Array/scalar confusion pada POST pembayaran menghasilkan error.');
    $postArrayCases = [
        ['/master_kelas.php', 'tambah', ['tingkat' => [$payload], 'kode_rombel' => [$payload]]],
        ['/master_biaya_lain.php', 'tambah', ['nama' => [$payload], 'nominal' => [$payload]]],
        ['/master_daftar_ulang.php', 'simpan_tarif', ['tahun_ajaran' => [$payload], 'jumlah' => [1 => [$payload]]]],
        ['/siswa/daftar.php', 'tambah', ['no_induk' => [$payload], 'nama' => [$payload], 'master_kelas_id' => [$payload]]],
        ['/role_management.php', 'tambah', ['nama' => [$payload], 'username' => [$payload], 'role' => [$payload], 'password' => [$payload], 'password_confirmation' => [$payload]]],
    ];
    foreach ($postArrayCases as [$path, $action, $fields]) {
        $formPage = input_corpus_request($baseUrl . $path, 'GET', [], $cookies);
        $postData = array_merge(['csrf_token' => input_corpus_form_csrf($formPage['body']), 'aksi' => $action], $fields);
        $arrayPostResponse = input_corpus_request($baseUrl . $path, 'POST', $postData, $cookies);
        input_corpus_assert($arrayPostResponse['status'] !== 500 && !preg_match($sqlMarkers, $arrayPostResponse['body']), 'Array/scalar confusion pada POST ' . $path . ' menghasilkan error.');
    }
    // JSON/text bodies must not be implicitly treated as $_POST form data.
    // These requests intentionally omit a form CSRF token; acceptance is a
    // safe rejection/redirect without SQL errors or server exceptions.
    $structuredPostCases = [
        '/pembayaran/proses.php',
        '/tabungan/proses.php',
        '/master_kelas.php',
        '/master_biaya_lain.php',
        '/master_daftar_ulang.php',
        '/siswa/daftar.php',
        '/role_management.php',
    ];
    $structuredBefore = input_corpus_mutation_snapshot($koneksi);
    foreach ($structuredPostCases as $path) {
        foreach ([
            ['application/json', '{"aksi":"tambah","csrf_token":"missing"}'],
            ['text/plain', 'aksi=tambah&csrf_token=missing'],
        ] as [$contentType, $rawBody]) {
            $structuredResponse = input_corpus_request(
                $baseUrl . $path,
                'POST',
                [],
                $cookies,
                $rawBody,
                $contentType
            );
            input_corpus_assert(
                $structuredResponse['status'] !== 500 && !preg_match($sqlMarkers, $structuredResponse['body']),
                'Structured body pada POST ' . $path . ' menghasilkan SQL error/HTTP 500.'
            );
        }
    }
    input_corpus_assert(
        input_corpus_mutation_snapshot($koneksi) === $structuredBefore,
        'Structured body menghasilkan mutasi bisnis walau form CSRF tidak diparse.'
    );
    // CSRF must be checked before master DU ensures/creates an academic year.
    // Use a valid-looking but isolated label so an invalid POST can be proven
    // not to create state even when the selected year did not exist before.
    $csrfYear = '2091/2092';
    $yearProbe = $koneksi->prepare('SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label=?');
    $yearProbe->bind_param('s', $csrfYear);
    $yearProbe->execute();
    $csrfYearBefore = (int)$yearProbe->get_result()->fetch_assoc()['total'];
    $yearProbe->close();
    $csrfRejected = input_corpus_request($baseUrl . '/master_daftar_ulang.php', 'POST', [
        'csrf_token' => 'invalid-csrf-token',
        'aksi' => 'simpan_tarif',
        'tahun_ajaran' => $csrfYear,
        'jumlah' => [1 => '100000'],
    ], $cookies);
    input_corpus_assert($csrfRejected['status'] === 302, 'POST Master Daftar Ulang dengan CSRF salah tidak ditolak sesuai kontrak.');
    $yearProbe = $koneksi->prepare('SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label=?');
    $yearProbe->bind_param('s', $csrfYear);
    $yearProbe->execute();
    $csrfYearAfter = (int)$yearProbe->get_result()->fetch_assoc()['total'];
    $yearProbe->close();
    input_corpus_assert($csrfYearAfter === $csrfYearBefore, 'POST Master Daftar Ulang dengan CSRF salah membuat tahun ajaran.');
    // A valid CSRF token with an invalid action must also roll back the
    // year-en ensure operation performed inside the business transaction.
    $actionYear = '2092/2093';
    $yearProbe = $koneksi->prepare('SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label=?');
    $yearProbe->bind_param('s', $actionYear);
    $yearProbe->execute();
    $actionYearBefore = (int)$yearProbe->get_result()->fetch_assoc()['total'];
    $yearProbe->close();
    $duForm = input_corpus_request($baseUrl . '/master_daftar_ulang.php', 'GET', [], $cookies);
    $invalidActionResponse = input_corpus_request($baseUrl . '/master_daftar_ulang.php', 'POST', [
        'csrf_token' => input_corpus_form_csrf($duForm['body']),
        'aksi' => 'aksi-tidak-valid',
        'tahun_ajaran' => $actionYear,
    ], $cookies);
    input_corpus_assert($invalidActionResponse['status'] === 302, 'POST Master Daftar Ulang dengan aksi invalid tidak mengembalikan redirect.');
    $yearProbe = $koneksi->prepare('SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label=?');
    $yearProbe->bind_param('s', $actionYear);
    $yearProbe->execute();
    $actionYearAfter = (int)$yearProbe->get_result()->fetch_assoc()['total'];
    $yearProbe->close();
    input_corpus_assert($actionYearAfter === $actionYearBefore, 'Kegagalan aksi Master Daftar Ulang meninggalkan tahun ajaran baru.');
    $oversized = str_repeat('A', 4096);
    $oversizedResponse = input_corpus_request($baseUrl . '/pembayaran/lihat.php?' . http_build_query(['search' => $oversized]), 'GET', [], $cookies);
    input_corpus_assert($oversizedResponse['status'] !== 500 && !preg_match($sqlMarkers, $oversizedResponse['body']), 'Input oversized pada histori pembayaran menghasilkan error SQL/500.');
    $duplicateResponse = input_corpus_request($baseUrl . '/tabungan/riwayat.php?nis=' . rawurlencode($payload) . '&nis=' . rawurlencode('literal') . '&bulan=8&tahun=2026', 'GET', [], $cookies);
    input_corpus_assert($duplicateResponse['status'] !== 500 && !preg_match($sqlMarkers, $duplicateResponse['body']), 'Parameter duplikat pada riwayat tabungan menghasilkan error SQL/500.');
    $xssPayload = '<script>alert(1)</script>';
    $xssResponse = input_corpus_request($baseUrl . '/siswa/daftar.php?' . http_build_query(['q' => $xssPayload]), 'GET', [], $cookies);
    input_corpus_assert($xssResponse['status'] !== 500 && !str_contains($xssResponse['body'], $xssPayload), 'Payload reflected XSS tampil mentah pada daftar siswa.');
    echo "OK: corpus SQLi boolean/error/time-based/quote/encoding pada route GET termasuk export, array/scalar laporan/tabungan/pembayaran, POST boundary, duplicate, oversized, dan reflected-XSS fokus tidak menghasilkan SQL error, HTTP 500, delay injeksi, atau payload mentah.\n";
} catch (Throwable $error) {
    $failure = $error;
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
