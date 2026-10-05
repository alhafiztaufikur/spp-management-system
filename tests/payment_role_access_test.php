<?php
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "SKIPPED: gunakan database disposable db_spp_audit_* dengan SPP_TEST_ALLOW_MUTATION=1.\n");
    exit(0);
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/http_form_scope.php';

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

$selectedDatabase = (string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0];
role_test_assert($selectedDatabase === DB_NAME, 'Koneksi tes tidak menuju database disposable yang diminta.');
$passwordSeed = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($passwordSeed === '' && ($passwordFile = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') {
    $passwordSeed = trim((string)file_get_contents($passwordFile));
}
role_test_assert($passwordSeed !== '', 'SPP_TEST_ADMIN_PASSWORD atau SPP_TEST_ADMIN_PASSWORD_FILE wajib diisi.');
// Password per-run memastikan server HTTP memakai clone yang akun-akunnya diatur di bawah ini.
$testPassword = hash_hmac('sha256', bin2hex(random_bytes(16)), $passwordSeed);
$baseUrl = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8766'), '/');
spp_test_assert_http_clone($baseUrl, DB_NAME);
$urlParts = parse_url($baseUrl);
role_test_assert(($urlParts['scheme'] ?? '') === 'http'
    && in_array($urlParts['host'] ?? '', ['127.0.0.1', 'localhost'], true),
    'SPP_TEST_BASE_URL harus menunjuk server HTTP lokal untuk database latihan.');
$nis = (string)random_int(9800000000, 9899999999);
$paymentId = 0;
$originalHashes = [];
$changedAccountIds = [];
$failure = null;
try {
    $accounts = ['admin'=>'admin', 'kasir1'=>'kasir', 'kasir2'=>'kasir',
        'kasir3'=>'kasir', 'kasir4'=>'kasir', 'bendahara'=>'bendahara'];
    $findAccount = $koneksi->prepare('SELECT id,password,role,is_active FROM admin WHERE username=?');
    foreach ($accounts as $username => $expectedRole) {
        $findAccount->bind_param('s', $username);
        $findAccount->execute();
        $account = $findAccount->get_result()->fetch_assoc();
        role_test_assert($account && $account['role'] === $expectedRole && (int)$account['is_active'] === 1,
            'Akun uji ' . $username . ' tidak tersedia dengan role aktif yang sesuai.');
        $originalHashes[(int)$account['id']] = (string)$account['password'];
    }
    $findAccount->close();
    $hash = password_hash($testPassword, PASSWORD_DEFAULT);
    $setPassword = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    foreach (array_keys($originalHashes) as $accountId) {
        $setPassword->bind_param('si', $hash, $accountId);
        $setPassword->execute();
        $changedAccountIds[] = $accountId;
    }
    $setPassword->close();

    $class = $koneksi->query("SELECT id,kode_rombel FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$class, 'Rombel kelas 1 tidak tersedia.');
    $name = 'UJI AKSES MUTASI'; $level = '1'; $classId = (int)$class['id']; $pangkal = 1000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,?,?,?)');
    $stmt->bind_param('sssid', $nis, $name, $level, $classId, $pangkal);
    $stmt->execute(); $stmt->close();
    $amount = 100.0; $date = date('Y-m-d H:i:s'); $month = date('m'); $year = date('Y'); $method = 'Tunai';
    $stmt = $koneksi->prepare('INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,U_PSB,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,1)');
    $stmt->bind_param('ssidssssd', $nis, $level, $classId, $amount, $date, $month, $year, $method, $amount);
    $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();

    foreach (['kasir1', 'kasir2', 'kasir3', 'kasir4', 'bendahara'] as $username) {
        $cookies = role_test_login($baseUrl, $username, $testPassword);
        $edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cookies);
        $isCashier = str_starts_with($username, 'kasir');
        role_test_assert($edit['status'] === ($isCashier ? 200 : 302), ucfirst($username) . ' memiliki akses edit pembayaran yang salah.');
        $masterSpp = role_test_request($baseUrl . '/master_spp.php', [], $cookies);
        role_test_assert($masterSpp['status'] === ($isCashier ? 200 : 302), ucfirst($username) . ' memiliki akses Master SPP yang tidak sesuai.');
        foreach (['update','hapus'] as $action) {
            $response = role_test_request($baseUrl . '/pembayaran/proses.php', [
                'aksi'=>$action, 'id'=>$paymentId, 'no_induk'=>$nis,
                'uang_psb'=>200, 'sistem_pembayaran'=>'Tunai', 'csrf_token'=>'invalid',
            ], $cookies);
            role_test_assert($response['status'] === 302, ucfirst($username) . ' tidak ditolak dari endpoint ' . $action . '.');
            $stored = $koneksi->query('SELECT U_PSB FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
            role_test_assert($stored && abs((float)$stored['U_PSB'] - 100) < .001, ucfirst($username) . ' berhasil memutasi pembayaran.');
        }
        if ($isCashier) {
            role_test_assert(role_test_request($baseUrl . '/pembayaran/form.php', [], $cookies)['status'] === 200, 'Kasir tidak dapat membuka input pembayaran.');
            role_test_assert(role_test_request($baseUrl . '/pembayaran/lihat.php', [], $cookies)['status'] === 200, 'Kasir tidak dapat melihat pembayaran.');
            role_test_assert(role_test_request($baseUrl . '/laporan/cetak_struk.php?id=' . $paymentId, [], $cookies)['status'] === 200, 'Kasir tidak dapat mencetak pembayaran.');
            foreach (['/siswa/daftar.php', '/master_kelas.php', '/master_spp.php', '/master_biaya_lain.php', '/master_daftar_ulang.php', '/siswa/export_excel.php'] as $masterPath) {
                role_test_assert(role_test_request($baseUrl . $masterPath, [], $cookies)['status'] === 200, 'Kasir tidak dapat membuka ' . $masterPath . '.');
            }
            role_test_assert(role_test_request($baseUrl . '/role_management.php', [], $cookies)['status'] === 302, 'Kasir tidak boleh membuka Role Management.');
            $queue = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $cookies);
            role_test_assert($queue['status'] === 200 && !str_contains($queue['body'], 'Setujui dan Terapkan'), 'Kasir tidak boleh menyetujui pengajuan.');
        } else {
            $queue = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $cookies);
            role_test_assert($queue['status'] === 200 && !str_contains($queue['body'], 'Setujui dan Terapkan'), 'Bendahara harus melihat antrean tanpa tombol persetujuan.');
        }
    }

    $adminCookies = role_test_login($baseUrl, 'admin', $testPassword);
    role_test_assert(role_test_request($baseUrl . '/master_spp.php', [], $adminCookies)['status'] === 200, 'Administrator tidak dapat membuka Master Penerbitan SPP.');
    $edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $adminCookies);
    role_test_assert($edit['status'] === 200, 'Administrator tidak dapat membuka edit pembayaran.');
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($edit['body'], 'no_induk'), $match), 'Token CSRF admin tidak ditemukan.');
    $token = $match[1];
    $updatedAmount = 150.0;
    $update = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_psb'=>$updatedAmount,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$token,
    ], $adminCookies);
    role_test_assert($update['status'] === 302, 'Perubahan langsung administrator gagal.');
    $stored = $koneksi->query('SELECT U_PSB FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PSB'] - $updatedAmount) < .001, 'Perubahan administrator tidak langsung diterapkan.');
    role_test_assert((int)$koneksi->query("SELECT COUNT(*) total FROM transaksi_otorisasi WHERE bayar_id={$paymentId}")->fetch_assoc()['total'] === 0, 'Perubahan administrator masuk antrean.');

    $cashier1 = role_test_login($baseUrl, 'kasir1', $testPassword);
    $cashierEdit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cashier1);
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($cashierEdit['body'], 'no_induk'), $cashierMatch), 'Token CSRF kasir tidak ditemukan.');
    $cashierToken = $cashierMatch[1];
    $requestEdit = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_psb'=>200,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$cashierToken,
        'authorization_reason'=>'Koreksi nominal oleh kasir satu.',
    ], $cashier1);
    role_test_assert($requestEdit['status'] === 302, 'Pengajuan perubahan kasir gagal.');
    $stored = $koneksi->query('SELECT U_PSB FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PSB'] - $updatedAmount) < .001, 'Pengajuan kasir langsung mengubah transaksi.');
    $pending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='edit' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$pending, 'Pengajuan kasir tidak masuk antrean.');
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_psb'=>250,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$token,
    ], $adminCookies);
    $stored = $koneksi->query('SELECT U_PSB FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PSB'] - $updatedAmount) < .001, 'Perubahan langsung admin menimpa pengajuan kasir.');

    $authorizationPage = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $adminCookies);
    role_test_assert($authorizationPage['status'] === 200 && str_contains($authorizationPage['body'], 'Setujui dan Terapkan'), 'Administrator tidak melihat tindakan persetujuan.');
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($authorizationPage['body'], 'request_id'), $authorizationMatch), 'Token CSRF otorisasi tidak ditemukan.');
    $authorizationToken = $authorizationMatch[1];
    $cashierQueue = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $cashier1);
    role_test_assert(str_contains($cashierQueue['body'], 'Koreksi nominal oleh kasir satu.') && !str_contains($cashierQueue['body'], 'Setujui dan Terapkan'), 'Kasir harus melihat pengajuannya tanpa hak persetujuan.');
    $cashier2 = role_test_login($baseUrl, 'kasir2', $testPassword);
    $otherQueue = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $cashier2);
    role_test_assert(!str_contains($otherQueue['body'], 'Koreksi nominal oleh kasir satu.'), 'Kasir melihat pengajuan kasir lain.');
    $cashierApprove = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$pending['id'], 'csrf_token'=>$authorizationToken,
    ], $cashier2);
    role_test_assert($cashierApprove['status'] === 302, 'Percobaan persetujuan kasir tidak ditolak.');
    $treasurerCookies = role_test_login($baseUrl, 'bendahara', $testPassword);
    $treasurerQueue = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $treasurerCookies);
    role_test_assert($treasurerQueue['status'] === 200 && !str_contains($treasurerQueue['body'], 'Setujui dan Terapkan'), 'Bendahara harus dapat memeriksa tanpa tombol otorisasi.');
    role_test_assert(!preg_match('/<form\b[^>]*>.*?name="request_id".*?<\/form>/s', $treasurerQueue['body']), 'Halaman baca bendahara tidak boleh memuat formulir keputusan.');
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$pending['id'], 'csrf_token'=>'invalid',
    ], $treasurerCookies);
    $treasurerReject = role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$pending['id'], 'action'=>'reject', 'decision_note'=>'Percobaan bendahara.',
        'csrf_token'=>'invalid',
    ], $treasurerCookies);
    role_test_assert($treasurerReject['status'] === 302, 'Penolakan bendahara tidak dialihkan.');
    $treasurerAfter = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $treasurerCookies);
    role_test_assert(str_contains($treasurerAfter['body'], 'Bendahara hanya dapat memeriksa data otorisasi.'),
        'Bendahara tidak ditolak oleh aturan role sebelum validasi token.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$pending['id']." AND status='pending'")->fetch_assoc()['total'] === 1, 'Bendahara atau kasir dapat memutuskan pengajuan.');
    $approveEdit = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$pending['id'],
        'csrf_token'=>$authorizationToken, 'decision_note'=>'Koreksi kasir disetujui.',
    ], $adminCookies);
    role_test_assert($approveEdit['status'] === 302, 'Persetujuan perubahan administrator gagal.');
    $stored = $koneksi->query('SELECT U_PSB FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PSB'] - 200) < .001, 'Persetujuan administrator tidak menerapkan perubahan.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$pending['id']." AND status='approved'")->fetch_assoc()['total'] === 1, 'Audit persetujuan edit tidak tersimpan.');

    $cashier2Edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cashier2);
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($cashier2Edit['body'], 'no_induk'), $cashier2Match), 'Token kasir dua tidak ditemukan.');
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_psb'=>225,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$cashier2Match[1],
        'authorization_reason'=>'Pengajuan untuk pengujian pembatalan.',
    ], $cashier2);
    $cancelPending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$cancelPending, 'Pengajuan pembatalan tidak terbentuk.');
    $cashier2Queue = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $cashier2);
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($cashier2Queue['body'], 'request_id'), $cashier2QueueMatch), 'Token antrean kasir dua tidak ditemukan.');
    role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$cancelPending['id'], 'action'=>'cancel', 'csrf_token'=>$cashier2QueueMatch[1],
    ], $cashier2);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$cancelPending['id']." AND status='cancelled'")->fetch_assoc()['total'] === 1, 'Kasir tidak dapat membatalkan pengajuannya.');

    $cashier3 = role_test_login($baseUrl, 'kasir3', $testPassword);
    $cashier3Edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cashier3);
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($cashier3Edit['body'], 'no_induk'), $cashier3Match), 'Token kasir tiga tidak ditemukan.');
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_psb'=>230,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$cashier3Match[1],
        'authorization_reason'=>'Pengajuan untuk pengujian penolakan.',
    ], $cashier3);
    $rejectPending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$rejectPending, 'Pengajuan penolakan tidak terbentuk.');
    role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$rejectPending['id'], 'action'=>'reject', 'decision_note'=>'',
        'csrf_token'=>$authorizationToken,
    ], $adminCookies);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$rejectPending['id']." AND status='pending'")->fetch_assoc()['total'] === 1, 'Penolakan tanpa catatan tetap diproses.');
    role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$rejectPending['id'], 'action'=>'reject',
        'decision_note'=>'Nominal belum didukung bukti koreksi.', 'csrf_token'=>$authorizationToken,
    ], $adminCookies);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$rejectPending['id']." AND status='rejected'")->fetch_assoc()['total'] === 1, 'Admin gagal menolak pengajuan dengan catatan.');

    $cashier4 = role_test_login($baseUrl, 'kasir4', $testPassword);
    $cashier4Edit = role_test_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cashier4);
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', spp_test_form_scope($cashier4Edit['body'], 'no_induk'), $cashier4Match), 'Token kasir empat tidak ditemukan.');
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'hapus', 'id'=>$paymentId, 'csrf_token'=>$cashier4Match[1],
        'authorization_reason'=>'Uji snapshot transaksi yang sudah berubah.',
    ], $cashier4);
    $stalePending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='hapus' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$stalePending, 'Pengajuan untuk uji snapshot tidak terbentuk.');
    $previousNote = (string)($koneksi->query('SELECT KETERANGAN FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['KETERANGAN'] ?? '');
    $conflictNote = 'TEST:SNAPSHOT-CONFLICT';
    $stmt = $koneksi->prepare('UPDATE bayar SET KETERANGAN=? WHERE id=?');
    $stmt->bind_param('si', $conflictNote, $paymentId); $stmt->execute(); $stmt->close();
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$stalePending['id'],
        'csrf_token'=>$authorizationToken, 'decision_note'=>'Uji konflik snapshot.',
    ], $adminCookies);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 1, 'Pengajuan kedaluwarsa tetap menghapus transaksi.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$stalePending['id']." AND status='failed'")->fetch_assoc()['total'] === 1, 'Pengajuan kedaluwarsa tidak ditandai gagal.');
    $stmt = $koneksi->prepare('UPDATE bayar SET KETERANGAN=? WHERE id=?');
    $stmt->bind_param('si', $previousNote, $paymentId); $stmt->execute(); $stmt->close();
    $delete = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'hapus', 'id'=>$paymentId, 'csrf_token'=>$cashier4Match[1],
        'authorization_reason'=>'Transaksi uji perlu dihapus setelah verifikasi.',
    ], $cashier4);
    role_test_assert($delete['status'] === 302, 'Pengajuan hapus kasir gagal.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 1, 'Pengajuan hapus langsung menghapus transaksi.');
    $deletePending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='hapus' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$deletePending, 'Antrean hapus tidak terbentuk.');
    $approve = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$deletePending['id'],
        'csrf_token'=>$authorizationToken, 'decision_note'=>'Penghapusan disetujui administrator.',
    ], $adminCookies);
    role_test_assert($approve['status'] === 302, 'Persetujuan hapus administrator gagal.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 0, 'Persetujuan tidak menghapus transaksi.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$deletePending['id']." AND status='approved' AND bayar_id IS NULL")->fetch_assoc()['total'] === 1, 'Audit penghapusan tidak dipertahankan.');
    $paymentId = 0;

    $directAmount = 100.0;
    $stmt = $koneksi->prepare('INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,U_PSB,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,1)');
    $stmt->bind_param('ssidssssd', $nis, $level, $classId, $directAmount, $date, $month, $year, $method, $directAmount);
    $stmt->execute(); $paymentId = (int)$koneksi->insert_id; $stmt->close();
    $directDelete = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'hapus', 'id'=>$paymentId, 'csrf_token'=>$token,
    ], $adminCookies);
    role_test_assert($directDelete['status'] === 302, 'Penghapusan langsung administrator gagal.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 0, 'Administrator tidak langsung menghapus transaksi.');
    $paymentId = 0;
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        if ($paymentId > 0) $koneksi->query('DELETE FROM bayar WHERE id=' . $paymentId);
        $koneksi->query("DELETE FROM transaksi_otorisasi WHERE no_induk_snapshot='" . $koneksi->real_escape_string($nis) . "'");
        $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
    } catch (Throwable $cleanupError) {
        $failure ??= $cleanupError;
    }
    try {
        $restorePassword = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        foreach ($changedAccountIds as $accountId) {
            $originalHash = $originalHashes[$accountId];
            $restorePassword->bind_param('si', $originalHash, $accountId);
            $restorePassword->execute();
        }
        $restorePassword->close();
    } catch (Throwable $cleanupError) {
        $failure ??= $cleanupError;
    }
}
if ($failure !== null) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: admin menyetujui pengajuan kasir dan dapat mengubah langsung; bendahara hanya memeriksa.\n";
