<?php
require_once __DIR__ . '/../koneksi.php';

function role_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function role_test_request(string $url, array $data, array &$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $data ? 'POST' : 'GET',
        'header' => implode("\r\n", $headers),
        'content' => $data ? http_build_query($data) : '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP request gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status'=>$status, 'body'=>$body];
}

function role_test_login(string $baseUrl, string $username, string $password): array {
    $cookies = [];
    $response = role_test_request($baseUrl . '/login.php', ['username'=>$username, 'password'=>$password], $cookies);
    role_test_assert($response['status'] === 302 && isset($cookies['PHPSESSID']), 'Login ' . $username . ' gagal.');
    return $cookies;
}

function role_test_flash(string $baseUrl, array &$cookies): string {
    $page = role_test_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $page['body'], $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    return '';
}

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    fwrite(STDERR, "SKIPPED: jalankan hanya pada database disposable.\n");
    exit(0);
}

$baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8097';
$nis = (string)random_int(9800000000, 9899999999);
$paymentId = 0;
try {
    $class = $koneksi->query("SELECT id,kode_rombel FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$class, 'Rombel kelas 1 tidak tersedia.');
    $name = 'UJI AKSES MUTASI'; $level = '1'; $classId = (int)$class['id']; $pangkal = 1000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PANGKAL,tot_pangkal) VALUES(?,?,?,?,?,?)');
    $stmt->bind_param('sssidd', $nis, $name, $level, $classId, $pangkal, $pangkal);
    $stmt->execute(); $stmt->close();
    $amount = 100.0; $date = date('Y-m-d H:i:s'); $month = date('m'); $year = date('Y'); $method = 'Tunai';
    $stmt = $koneksi->prepare('INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,U_PANGKAL,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,1)');
    $stmt->bind_param('ssidssssd', $nis, $level, $classId, $amount, $date, $month, $year, $method, $amount);
    $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();

    foreach ([['kasir','kasir123'], ['bendahara','bendahara123']] as [$username,$password]) {
        $cookies = role_test_login($baseUrl, $username, $password);
        $edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cookies);
        role_test_assert($edit['status'] === 302, ucfirst($username) . ' masih dapat membuka edit pembayaran.');
        $masterSpp = role_test_request($baseUrl . '/master_spp.php', [], $cookies);
        role_test_assert($masterSpp['status'] === 302, ucfirst($username) . ' masih dapat membuka Master Penerbitan SPP.');
        foreach (['update','hapus'] as $action) {
            $response = role_test_request($baseUrl . '/pembayaran/proses.php', [
                'aksi'=>$action, 'id'=>$paymentId, 'no_induk'=>$nis,
                'uang_pangkal'=>200, 'sistem_pembayaran'=>'Tunai', 'csrf_token'=>'invalid',
            ], $cookies);
            role_test_assert($response['status'] === 302, ucfirst($username) . ' tidak ditolak dari endpoint ' . $action . '.');
            $stored = $koneksi->query('SELECT U_PANGKAL FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
            role_test_assert($stored && abs((float)$stored['U_PANGKAL'] - 100) < .001, ucfirst($username) . ' berhasil memutasi pembayaran.');
        }
        if ($username === 'kasir') {
            role_test_assert(role_test_request($baseUrl . '/pembayaran/form.php', [], $cookies)['status'] === 200, 'Kasir tidak dapat membuka input pembayaran.');
            role_test_assert(role_test_request($baseUrl . '/pembayaran/lihat.php', [], $cookies)['status'] === 200, 'Kasir tidak dapat melihat pembayaran.');
            role_test_assert(role_test_request($baseUrl . '/laporan/cetak_struk.php?id=' . $paymentId, [], $cookies)['status'] === 200, 'Kasir tidak dapat mencetak pembayaran.');
        }
    }

    $adminCookies = role_test_login($baseUrl, 'admin', 'admin123');
    role_test_assert(role_test_request($baseUrl . '/master_spp.php', [], $adminCookies)['status'] === 200, 'Administrator tidak dapat membuka Master Penerbitan SPP.');
    $edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $adminCookies);
    role_test_assert($edit['status'] === 200, 'Administrator tidak dapat membuka edit pembayaran.');
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $edit['body'], $match), 'Token CSRF admin tidak ditemukan.');
    $token = $match[1];
    $updatedAmount = 150.0;
    $update = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_pangkal'=>$updatedAmount,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$token,
    ], $adminCookies);
    role_test_assert($update['status'] === 302, 'Update administrator tidak selesai.');
    $stored = $koneksi->query('SELECT U_PANGKAL FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PANGKAL'] - $updatedAmount) < .001, 'Update administrator tidak tersimpan: ' . role_test_flash($baseUrl, $adminCookies));

    $delete = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'hapus', 'id'=>$paymentId, 'csrf_token'=>$token,
    ], $adminCookies);
    role_test_assert($delete['status'] === 302, 'Hapus administrator tidak selesai.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 0, 'Hapus administrator tidak tersimpan.');
    $paymentId = 0;

    echo "OK: hanya administrator dapat mengedit/menghapus; kasir tetap dapat input, lihat, dan cetak.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($paymentId > 0) $koneksi->query('DELETE FROM bayar WHERE id=' . $paymentId);
    $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
}
