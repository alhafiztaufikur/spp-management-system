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
        role_test_assert($masterSpp['status'] === ($username === 'kasir' ? 200 : 302), ucfirst($username) . ' memiliki akses Master SPP yang tidak sesuai.');
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
            foreach (['/siswa/daftar.php', '/master_kelas.php', '/master_spp.php', '/master_biaya_lain.php', '/master_daftar_ulang.php', '/siswa/export_excel.php'] as $masterPath) {
                role_test_assert(role_test_request($baseUrl . $masterPath, [], $cookies)['status'] === 200, 'Kasir tidak dapat membuka ' . $masterPath . '.');
            }
            role_test_assert(role_test_request($baseUrl . '/role_management.php', [], $cookies)['status'] === 302, 'Kasir tidak boleh membuka Role Management.');
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
        'authorization_reason'=>'Koreksi nominal untuk pengujian otorisasi.',
    ], $adminCookies);
    role_test_assert($update['status'] === 302, 'Pengajuan update administrator tidak selesai.');
    $stored = $koneksi->query('SELECT U_PANGKAL FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PANGKAL'] - 100) < .001, 'Pengajuan update langsung mengubah transaksi.');
    $pending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='edit' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$pending, 'Antrean otorisasi update tidak terbentuk.');

    $authorizationPage = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $adminCookies);
    role_test_assert($authorizationPage['status'] === 200, 'Administrator tidak dapat membuka antrean otorisasi.');
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $authorizationPage['body'], $authorizationMatch), 'Token CSRF otorisasi tidak ditemukan.');
    $authorizationToken = $authorizationMatch[1];
    $selfApprove = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$pending['id'],
        'csrf_token'=>$authorizationToken,
    ], $adminCookies);
    role_test_assert($selfApprove['status'] === 302, 'Persetujuan mandiri tidak ditangani.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$pending['id']." AND status='pending'")->fetch_assoc()['total'] === 1, 'Administrator dapat menyetujui permintaannya sendiri.');

    $treasurerCookies = role_test_login($baseUrl, 'bendahara', 'bendahara123');
    $treasurerPage = role_test_request($baseUrl . '/otorisasi_transaksi.php', [], $treasurerCookies);
    role_test_assert($treasurerPage['status'] === 200, 'Bendahara tidak dapat membuka antrean otorisasi.');
    role_test_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $treasurerPage['body'], $treasurerMatch), 'Token CSRF bendahara tidak ditemukan.');
    $approveEdit = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$pending['id'],
        'csrf_token'=>$treasurerMatch[1], 'decision_note'=>'Koreksi transaksi uji disetujui.',
    ], $treasurerCookies);
    role_test_assert($approveEdit['status'] === 302, 'Persetujuan perubahan oleh bendahara tidak selesai.');
    $stored = $koneksi->query('SELECT U_PANGKAL FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PANGKAL'] - $updatedAmount) < .001, 'Persetujuan tidak menerapkan perubahan transaksi.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$pending['id']." AND status='approved'")->fetch_assoc()['total'] === 1, 'Permintaan edit tidak ditandai disetujui.');

    $secondUpdate = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_pangkal'=>175,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$token,
        'authorization_reason'=>'Memastikan pemohon dapat membatalkan pengajuan.',
    ], $adminCookies);
    role_test_assert($secondUpdate['status'] === 302, 'Pengajuan update kedua tidak selesai.');
    $cancelPending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='edit' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$cancelPending, 'Permintaan edit untuk pembatalan tidak terbentuk.');
    $cancel = role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$cancelPending['id'], 'action'=>'cancel', 'csrf_token'=>$authorizationToken,
    ], $adminCookies);
    role_test_assert($cancel['status'] === 302, 'Pembatalan permintaan edit tidak selesai.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$cancelPending['id']." AND status='cancelled'")->fetch_assoc()['total'] === 1, 'Permintaan edit tidak dibatalkan.');
    $stored = $koneksi->query('SELECT U_PANGKAL FROM bayar WHERE id=' . $paymentId)->fetch_assoc();
    role_test_assert($stored && abs((float)$stored['U_PANGKAL'] - $updatedAmount) < .001, 'Pembatalan mengubah transaksi yang sudah tersimpan.');

    $rejectedUpdate = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'update', 'id'=>$paymentId, 'no_induk'=>$nis, 'uang_pangkal'=>180,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>$date,
        'sistem_pembayaran'=>'Tunai', 'csrf_token'=>$token,
        'authorization_reason'=>'Memastikan penolakan wajib memiliki catatan.',
    ], $adminCookies);
    role_test_assert($rejectedUpdate['status'] === 302, 'Pengajuan edit untuk penolakan tidak selesai.');
    $rejectPending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='edit' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$rejectPending, 'Permintaan edit untuk penolakan tidak terbentuk.');
    role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$rejectPending['id'], 'action'=>'reject', 'decision_note'=>'',
        'csrf_token'=>$treasurerMatch[1],
    ], $treasurerCookies);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$rejectPending['id']." AND status='pending'")->fetch_assoc()['total'] === 1, 'Penolakan tanpa catatan tetap diproses.');
    role_test_request($baseUrl . '/otorisasi_transaksi.php', [
        'request_id'=>(int)$rejectPending['id'], 'action'=>'reject',
        'decision_note'=>'Nominal usulan belum didukung bukti koreksi.', 'csrf_token'=>$treasurerMatch[1],
    ], $treasurerCookies);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$rejectPending['id']." AND status='rejected'")->fetch_assoc()['total'] === 1, 'Permintaan edit tidak berhasil ditolak dengan catatan.');

    $staleDelete = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'hapus', 'id'=>$paymentId, 'csrf_token'=>$token,
        'authorization_reason'=>'Memastikan snapshot transaksi kedaluwarsa ditolak.',
    ], $adminCookies);
    role_test_assert($staleDelete['status'] === 302, 'Pengajuan hapus untuk uji konflik tidak selesai.');
    $stalePending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='hapus' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$stalePending, 'Permintaan hapus untuk uji konflik tidak terbentuk.');
    $previousNote = (string)($koneksi->query('SELECT KETERANGAN FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['KETERANGAN'] ?? '');
    $conflictNote = 'TEST:SNAPSHOT-CONFLICT';
    $stmt = $koneksi->prepare('UPDATE bayar SET KETERANGAN=? WHERE id=?');
    $stmt->bind_param('si', $conflictNote, $paymentId); $stmt->execute(); $stmt->close();
    role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$stalePending['id'],
        'csrf_token'=>$treasurerMatch[1], 'decision_note'=>'Uji konflik snapshot.',
    ], $treasurerCookies);
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 1, 'Permintaan kedaluwarsa tetap menghapus transaksi.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$stalePending['id']." AND status='failed'")->fetch_assoc()['total'] === 1, 'Permintaan kedaluwarsa tidak ditandai gagal.');
    $stmt = $koneksi->prepare('UPDATE bayar SET KETERANGAN=? WHERE id=?');
    $stmt->bind_param('si', $previousNote, $paymentId); $stmt->execute(); $stmt->close();

    $delete = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'hapus', 'id'=>$paymentId, 'csrf_token'=>$token,
        'authorization_reason'=>'Transaksi uji perlu dihapus setelah verifikasi.',
    ], $adminCookies);
    role_test_assert($delete['status'] === 302, 'Pengajuan hapus administrator tidak selesai.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 1, 'Pengajuan hapus langsung menghapus transaksi.');
    $deletePending = $koneksi->query("SELECT id FROM transaksi_otorisasi WHERE bayar_id={$paymentId} AND action='hapus' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    role_test_assert((bool)$deletePending, 'Antrean otorisasi hapus tidak terbentuk.');

    $approve = role_test_request($baseUrl . '/pembayaran/proses.php', [
        'aksi'=>'otorisasi_setujui', 'request_id'=>(int)$deletePending['id'],
        'csrf_token'=>$treasurerMatch[1], 'decision_note'=>'Penghapusan transaksi uji disetujui.',
    ], $treasurerCookies);
    role_test_assert($approve['status'] === 302, 'Persetujuan bendahara tidak selesai.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $paymentId)->fetch_assoc()['total'] === 0, 'Persetujuan tidak menghapus transaksi.');
    role_test_assert((int)$koneksi->query('SELECT COUNT(*) total FROM transaksi_otorisasi WHERE id='.(int)$deletePending['id']." AND status='approved' AND bayar_id IS NULL")->fetch_assoc()['total'] === 1, 'Audit penghapusan tidak dipertahankan.');
    $paymentId = 0;

    echo "OK: perubahan transaksi memerlukan otorisasi pihak lain; audit penghapusan tetap tersimpan.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($paymentId > 0) $koneksi->query('DELETE FROM bayar WHERE id=' . $paymentId);
    $koneksi->query("DELETE FROM transaksi_otorisasi WHERE no_induk_snapshot='" . $koneksi->real_escape_string($nis) . "'");
    $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
}
