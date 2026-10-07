<?php
require_once __DIR__.'/excel_test_helpers.php';
/** Savings note persistence, display, validation, role and unit isolation. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/http_form_scope.php';
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !str_starts_with(DB_NAME, 'db_spp_audit_')) {
    throw new RuntimeException('Tes mutasi hanya untuk db_spp_audit_* dengan flag tes.');
}
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($password === '' && ($file = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') {
    $password = trim((string)file_get_contents($file));
}
if ($password === '') throw new RuntimeException('Password akun tes belum disiapkan.');
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8766'), '/');
spp_test_assert_http_clone($base, DB_NAME);

function note_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function note_http(string $url, ?array $post, array &$cookies): array {
    $headers = [];
    if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    note_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}
function note_login(string $base, string $username, string $password): array {
    $cookies = [];
    $response = note_http($base . '/login.php', ['username'=>$username, 'password'=>$password], $cookies);
    note_assert($response['status'] === 302 && isset($cookies['PHPSESSID']), 'Login akun latihan gagal.');
    return $cookies;
}
function note_form(string $base, string $path, array &$cookies, string $nis): array {
    $response = note_http($base . $path, null, $cookies);
    note_assert($response['status'] === 200 && str_contains($response['body'], $nis),
        'Server HTTP tidak memakai database latihan yang berisi siswa uji.');
    $form = spp_test_form_scope($response['body'], 'request_key');
    note_assert(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form, $csrf) === 1,
        'Token CSRF tabungan tidak ada.');
    note_assert(preg_match('/name="request_key" value="([a-f0-9]{32})"/', $form, $key) === 1,
        'Kunci idempotensi tabungan tidak ada.');
    return ['csrf_token'=>$csrf[1], 'request_key'=>$key[1]];
}
function note_journal(mysqli $db, string $table, string $nis): array {
    $stmt = $db->prepare("SELECT id, keterangan FROM `{$table}` WHERE NO_INDUK=? ORDER BY id");
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function note_flash(string $base, array &$cookies): string {
    $response = note_http($base . '/tabungan/masuk.php', null, $cookies);
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $response['body'], $match)) {
        return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

$nis = 'SN' . strtoupper(bin2hex(random_bytes(4)));
$keys = [];
$fixtureCreated = false;
$oldRoleHash = null;
$roleId = 30; // bendahara.smp, hanya pada clone.
$failure = null;
try {
    $columns = $koneksi->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transaksi_m','transaksi_k') AND COLUMN_NAME='keterangan'")->fetch_all(MYSQLI_ASSOC);
    note_assert(count($columns) === 2, 'Jalankan migrasi catatan tabungan pada clone terlebih dahulu.');
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_assoc();
    note_assert((bool)$class, 'Kelas 1A latihan tidak tersedia.');
    $name = 'UJI CATATAN TABUNGAN'; $level = '1'; $classId = (int)$class['id'];
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES(?,?,?,?)');
    $stmt->bind_param('sssi', $nis, $name, $level, $classId);
    $stmt->execute(); $stmt->close(); $fixtureCreated = true;

    $admin = note_login($base, 'admin', $password);
    $anonymous = [];
    $response = note_http($base . '/tabungan/get_saldo.php?nis=' . rawurlencode($nis), null, $anonymous);
    note_assert($response['status'] === 401, 'API saldo menerima pengguna belum login.');
    $response = note_http($base . '/tabungan/get_saldo.php?nis=SMP260004', null, $admin);
    note_assert($response['status'] === 200 && (float)(json_decode($response['body'], true)['saldo'] ?? -1) === 0.0,
        'API saldo SD mengungkap saldo unit SMP.');

    $role = $koneksi->query("SELECT id,password FROM admin WHERE id=30 AND role='bendahara' AND unit_id=2 AND is_active=1")->fetch_assoc();
    note_assert((bool)$role, 'Akun bendahara SMP latihan tidak tersedia.');
    $oldRoleHash = (string)$role['password'];
    $testHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $stmt->bind_param('si', $testHash, $roleId); $stmt->execute(); $stmt->close();
    $bendahara = note_login($base, 'bendahara.smp', $password);
    $response = note_http($base . '/tabungan/get_saldo.php?nis=' . rawurlencode($nis), null, $bendahara);
    note_assert($response['status'] === 403, 'API saldo dapat diakses bendahara yang tidak berwenang menginput tabungan.');

    $incoming = 'Setoran <script>alert(1)</script> & sekolah';
    $form = note_form($base, '/tabungan/masuk.php', $admin, $nis); $keys[] = $form['request_key'];
    $response = note_http($base . '/tabungan/proses.php', [
        'aksi'=>'masuk', 'no_induk'=>$nis, 'nominal'=>'12345', 'keterangan'=>$incoming,
    ] + $form, $admin);
    note_assert($response['status'] === 302, 'Setoran catatan tidak diproses.');
    $inRows = note_journal($koneksi, 'transaksi_m', $nis);
    note_assert(count($inRows) === 1 && $inRows[0]['keterangan'] === $incoming,
        'Keterangan setoran tidak tersimpan utuh di jurnal: ' . json_encode($inRows)
        . '; flash=' . note_flash($base, $admin));
    $response = note_http($base . '/tabungan/get_saldo.php?nis=' . rawurlencode($nis), null, $admin);
    note_assert($response['status'] === 200 && (float)(json_decode($response['body'], true)['saldo'] ?? -1) === 12345.0,
        'Saldo setoran tidak sesuai jurnal.');

    $outgoing = '=1+1 & buku';
    $form = note_form($base, '/tabungan/keluar.php', $admin, $nis); $keys[] = $form['request_key'];
    $response = note_http($base . '/tabungan/proses.php', [
        'aksi'=>'keluar', 'no_induk'=>$nis, 'nominal'=>'2345', 'keterangan'=>$outgoing,
    ] + $form, $admin);
    note_assert($response['status'] === 302, 'Penarikan catatan tidak diproses.');
    $outRows = note_journal($koneksi, 'transaksi_k', $nis);
    note_assert(count($outRows) === 1 && $outRows[0]['keterangan'] === $outgoing,
        'Keterangan penarikan tidak tersimpan utuh di jurnal.');
    $response = note_http($base . '/tabungan/get_saldo.php?nis=' . rawurlencode($nis), null, $admin);
    note_assert($response['status'] === 200 && (float)(json_decode($response['body'], true)['saldo'] ?? -1) === 10000.0,
        'Saldo setelah penarikan tidak sesuai jurnal.');

    $today = date('Y-m-d');
    $history = note_http($base . '/tabungan/riwayat.php?' . http_build_query([
        'nis'=>$nis, 'tanggal_awal'=>$today, 'tanggal_akhir'=>$today,
    ]), null, $admin);
    note_assert($history['status'] === 200
        && str_contains($history['body'], htmlspecialchars($incoming, ENT_QUOTES, 'UTF-8'))
        && str_contains($history['body'], htmlspecialchars($outgoing, ENT_QUOTES, 'UTF-8'))
        && !str_contains($history['body'], $incoming),
        'Riwayat tabungan tidak menampilkan kedua catatan dengan escaping aman.');

    $filters = report_filters($koneksi, ['template'=>'tabungan-siswa',
        'tanggal_awal'=>$today, 'tanggal_akhir'=>$today, 'q'=>$nis]);
    $report = report_savings_student_data($koneksi, $filters);
    note_assert(in_array(['keterangan', 'Keterangan'], $report['columns'], true),
        'Laporan transaksi tabungan belum memiliki kolom Keterangan.');
    $reportRows = array_values(array_filter($report['rows'], static fn(array $row): bool => $row['nis'] === $nis));
    note_assert(count($reportRows) === 2 && array_column($reportRows, 'keterangan') === [$incoming, $outgoing],
        'Keterangan laporan transaksi tabungan tidak sama dengan kedua jurnal.');
    $reportQuery = http_build_query(['template'=>'tabungan-siswa','tanggal_awal'=>$today,
        'tanggal_akhir'=>$today,'q'=>$nis]);
    $screen = note_http($base . '/laporan/template.php?' . $reportQuery, null, $admin);
    note_assert($screen['status'] === 200
        && str_contains($screen['body'], htmlspecialchars($incoming, ENT_QUOTES, 'UTF-8'))
        && str_contains($screen['body'], htmlspecialchars($outgoing, ENT_QUOTES, 'UTF-8'))
        && !str_contains($screen['body'], $incoming),
        'Layar laporan tabungan tidak menampilkan kedua keterangan dengan aman.');
    foreach (['export_global.php?' . $reportQuery . '&format=excel&download=1',
              'export_excel.php?' . http_build_query(['tanggal_awal'=>$today,
                  'tanggal_akhir'=>$today,'q'=>$nis,'download'=>'1'])] as $path) {
        $excel = note_http($base . '/laporan/' . $path, null, $admin);
        $excel['body']=test_excel_html($excel['body']);
        note_assert($excel['status'] === 200
            && str_contains($excel['body'], htmlspecialchars($incoming, ENT_QUOTES, 'UTF-8'))
            && str_contains($excel['body'], '=1+1 &amp; buku')
            && !str_contains($excel['body'], $incoming)
            && str_contains($excel['body'], '2.345'),
            'Excel tabungan tidak mengamankan rumus atau kehilangan catatan/nominal: ' . $path
            . '; cek=' . json_encode(['status'=>$excel['status'],
                'setoran'=>str_contains($excel['body'], htmlspecialchars($incoming, ENT_QUOTES, 'UTF-8')),
                'rumus'=>str_contains($excel['body'], '=1+1 &amp; buku'),
                'nominal'=>str_contains($excel['body'], '2.345'),
                'cuplikan'=>substr($excel['body'], max(0, (int)strpos($excel['body'], '1+1')-40), 100)]));
    }

    $form = note_form($base, '/tabungan/masuk.php', $admin, $nis); $keys[] = $form['request_key'];
    note_http($base . '/tabungan/proses.php', [
        'aksi'=>'masuk', 'no_induk'=>$nis, 'nominal'=>'1000', 'keterangan'=>str_repeat('x', 256),
    ] + $form, $admin);
    note_assert(count(note_journal($koneksi, 'transaksi_m', $nis)) === 1,
        'Keterangan terlalu panjang tetap menambah jurnal.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($oldRoleHash !== null) {
        $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        $stmt->bind_param('si', $oldRoleHash, $roleId); $stmt->execute(); $stmt->close();
    }
    foreach ($keys as $key) {
        $stmt = $koneksi->prepare('DELETE FROM keuangan_request WHERE request_key=?');
        $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close();
    }
    if ($fixtureCreated) {
        foreach (['transaksi_m', 'transaksi_k', 'tabungan', 'siswa'] as $table) {
            $stmt = $koneksi->prepare("DELETE FROM `{$table}` WHERE NO_INDUK=?");
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        }
    }
}
if ($failure) throw $failure;
echo "OK: catatan setoran/penarikan tersimpan dan tampil aman, panjang dibatasi, API saldo membatasi role/unit.\n";
