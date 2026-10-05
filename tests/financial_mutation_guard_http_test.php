<?php
/** Mutating HTTP regression: only run against a disposable clone with two PHP servers. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/http_form_scope.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !str_starts_with(DB_NAME, 'db_spp_audit_')) {
    throw new RuntimeException('Tes mutasi hanya boleh dijalankan pada database db_spp_audit_* dengan flag tes.');
}
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($password === '' && ($file = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') $password = trim(file_get_contents($file));
if ($password === '') throw new RuntimeException('Password admin tes belum disiapkan.');
$base = (string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8766');
spp_test_assert_http_clone($base, DB_NAME);
$secondBase = (string)(getenv('SPP_TEST_SECOND_BASE_URL') ?: '');
spp_test_assert_http_clone($secondBase, DB_NAME);
if ($secondBase === '') throw new RuntimeException('SPP_TEST_SECOND_BASE_URL diperlukan untuk tes serentak.');

function guard_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function guard_http(string $url, ?array $data, array &$cookies): array {
    $headers = [];
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    guard_assert($body !== false, 'HTTP gagal: ' . $url);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m)) $status = (int)$m[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $m)) $cookies[$m[1]] = $m[2];
    }
    return ['status' => $status, 'body' => $body];
}
function guard_form(string $base, string $path, array &$cookies): array {
    $page = guard_http($base . $path, null, $cookies);
    guard_assert($page['status'] === 200, 'Form tidak tersedia: ' . $path);
    $form = spp_test_form_scope($page['body'], 'request_key');
    guard_assert(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form, $csrf) === 1, 'Token CSRF tidak ada: ' . $path);
    guard_assert(preg_match('/name="request_key" value="([a-f0-9]{32})"/', $form, $key) === 1, 'Kunci idempotensi tidak ada: ' . $path);
    return ['csrf_token' => $csrf[1], 'request_key' => $key[1]];
}
function guard_login(string $base, string $password): array {
    $cookies = [];
    $response = guard_http($base . '/login.php', ['username' => 'admin', 'password' => $password], $cookies);
    guard_assert($response['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin tes gagal.');
    return $cookies;
}
function guard_flash(string $base, array &$cookies): string {
    $page = guard_http($base . '/pembayaran/form.php', null, $cookies);
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $page['body'], $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    return '';
}
function guard_count(mysqli $db, string $table, string $nis): int {
    $field = $table === 'bayar' ? 'NO_INDUK' : 'NO_INDUK';
    $stmt = $db->prepare("SELECT COUNT(*) n FROM `$table` WHERE `$field`=?");
    $stmt->bind_param('s', $nis); $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
    return $count;
}
function guard_balance(mysqli $db, string $nis): float {
    $stmt = $db->prepare('SELECT SALDO FROM tabungan WHERE NO_INDUK=?');
    $stmt->bind_param('s', $nis); $stmt->execute();
    $balance = (float)($stmt->get_result()->fetch_assoc()['SALDO'] ?? 0); $stmt->close();
    return $balance;
}
function guard_bill_paid(mysqli $db, int $billId): float {
    $stmt = $db->prepare('SELECT COALESCE(SUM(nominal_snapshot),0) paid FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=?');
    $stmt->bind_param('i', $billId); $stmt->execute();
    $paid = (float)$stmt->get_result()->fetch_assoc()['paid']; $stmt->close();
    return $paid;
}
function guard_parallel(array $requests): array {
    $multi = curl_multi_init(); $handles = [];
    foreach ($requests as $request) {
        [$url, $data, $cookies] = $request;
        $handle = curl_init($url);
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . implode('; ', $pairs)]]);
        curl_multi_add_handle($multi, $handle); $handles[] = $handle;
    }
    do { $status = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.2); } while ($running && $status === CURLM_OK);
    $results = [];
    foreach ($handles as $handle) {
        $results[] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => curl_multi_getcontent($handle), 'error' => curl_error($handle)];
        curl_multi_remove_handle($multi, $handle); curl_close($handle);
    }
    curl_multi_close($multi);
    return $results;
}

$nis = (string)random_int(9900000000, 9999999999);
$keys = [];
$masterId = 0;
$billId = 0;
$failure = null;
try {
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_assoc();
    guard_assert((bool)$class, 'Kelas 1A tidak tersedia.');
    $classId = (int)$class['id']; $name = 'UJI GUARD KEUANGAN'; $level = '1'; $pangkal = 1000000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,?,?,?)');
    $stmt->bind_param('sssid', $nis, $name, $level, $classId, $pangkal);
    $stmt->execute(); $stmt->close();
    $masterName = 'UJI GUARD ' . $nis;
    $stmt = $koneksi->prepare('INSERT INTO master_biaya_lain(nama,nominal) VALUES(?,1000000)');
    $stmt->bind_param('s', $masterName); $stmt->execute(); $masterId = (int)$koneksi->insert_id; $stmt->close();
    $stmt = $koneksi->prepare('INSERT INTO tagihan_biaya_lain(master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot) VALUES(?,?,?, ?,1000000,?)');
    $classLabel = '1A';
    $stmt->bind_param('isiss', $masterId, $nis, $classId, $masterName, $classLabel);
    $stmt->execute(); $billId = (int)$koneksi->insert_id; $stmt->close();
    $first = guard_login($base, $password);
    $second = guard_login($secondBase, $password);
    $period = ['bulan_bayar' => date('m'), 'tahun_bayar' => date('Y')];
    $paymentData = ['aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
        'sistem_pembayaran' => 'Tunai', 'uang_psb' => 100000] + $period;
    $paymentForm = guard_form($base, '/pembayaran/form.php', $first); $keys[] = $paymentForm['request_key'];
    guard_http($base . '/pembayaran/proses.php?aksi=input&no_induk=' . rawurlencode($nis), null, $first);
    guard_assert(guard_count($koneksi, 'bayar', $nis) === 0, 'Input pembayaran lewat GET mengubah data.');
    guard_assert(guard_http($base . '/pembayaran/proses.php', $paymentData + ['request_key' => $paymentForm['request_key']], $first)['status'] === 302, 'CSRF input tidak ditolak.');
    guard_assert(guard_count($koneksi, 'bayar', $nis) === 0, 'Input tanpa CSRF mengubah pembayaran.');
    $validPayment = $paymentData + $paymentForm;
    guard_http($base . '/pembayaran/proses.php', $validPayment, $first);
    guard_assert(guard_count($koneksi, 'bayar', $nis) === 1, 'Pembayaran valid gagal.');
    guard_http($base . '/pembayaran/proses.php', $validPayment, $first);
    guard_assert(guard_count($koneksi, 'bayar', $nis) === 1, 'Replay pembayaran mencatat dua transaksi.');

    $savingsData = ['aksi' => 'masuk', 'no_induk' => $nis, 'nominal' => '200000'];
    $savingsForm = guard_form($base, '/tabungan/masuk.php', $first); $keys[] = $savingsForm['request_key'];
    guard_http($base . '/tabungan/proses.php', $savingsData + ['request_key' => $savingsForm['request_key']], $first);
    guard_assert(guard_count($koneksi, 'transaksi_m', $nis) === 0, 'Setoran tanpa CSRF mengubah jurnal.');
    $validSavings = $savingsData + $savingsForm;
    guard_http($base . '/tabungan/proses.php', $validSavings, $first);
    guard_assert(guard_count($koneksi, 'transaksi_m', $nis) === 1 && guard_balance($koneksi, $nis) === 200000.0, 'Setoran valid tidak cocok dengan saldo.');
    guard_http($base . '/tabungan/proses.php', $validSavings, $first);
    guard_assert(guard_count($koneksi, 'transaksi_m', $nis) === 1 && guard_balance($koneksi, $nis) === 200000.0, 'Replay setoran menggandakan saldo.');
    $malformedForm = guard_form($base, '/tabungan/masuk.php', $first); $keys[] = $malformedForm['request_key'];
    guard_http($base . '/tabungan/proses.php', ['aksi'=>'masuk','no_induk'=>$nis,'nominal'=>'1.000'] + $malformedForm, $first);
    guard_assert(guard_balance($koneksi, $nis) === 200000.0, 'Nominal berformat ambigu diterima sebagai angka berbeda.');

    $withdrawal = ['aksi' => 'keluar', 'no_induk' => $nis, 'nominal' => '50000'];
    $withdrawalForm = guard_form($base, '/tabungan/keluar.php', $first); $keys[] = $withdrawalForm['request_key'];
    guard_http($base . '/tabungan/proses.php', $withdrawal + $withdrawalForm, $first);
    guard_http($base . '/tabungan/proses.php', $withdrawal + $withdrawalForm, $first);
    guard_assert(guard_count($koneksi, 'transaksi_k', $nis) === 1 && guard_balance($koneksi, $nis) === 150000.0, 'Replay penarikan menggandakan mutasi.');

    $retryForm = guard_form($base, '/tabungan/keluar.php', $first); $keys[] = $retryForm['request_key'];
    guard_http($base . '/tabungan/proses.php', ['aksi'=>'keluar','no_induk'=>$nis,'nominal'=>'500000'] + $retryForm, $first);
    guard_assert(guard_balance($koneksi, $nis) === 150000.0, 'Penarikan melebihi saldo mengubah saldo.');
    guard_http($base . '/tabungan/proses.php', ['aksi'=>'keluar','no_induk'=>$nis,'nominal'=>'50000'] + $retryForm, $first);
    guard_assert(guard_balance($koneksi, $nis) === 100000.0, 'Kunci yang di-rollback tidak dapat dipakai ulang.');

    $parallelPaymentA = guard_form($base, '/pembayaran/form.php', $first);
    $parallelPaymentB = guard_form($secondBase, '/pembayaran/form.php', $second);
    $keys[] = $parallelPaymentA['request_key'];
    $beforePayments = guard_count($koneksi, 'bayar', $nis);
    $paymentPair = guard_parallel([
        [$base . '/pembayaran/proses.php', $paymentData + $parallelPaymentA, $first],
        [$secondBase . '/pembayaran/proses.php', $paymentData + ['csrf_token'=>$parallelPaymentB['csrf_token'],'request_key'=>$parallelPaymentA['request_key']], $second],
    ]);
    guard_assert($paymentPair[0]['status'] === 302 && $paymentPair[1]['status'] === 302, 'Tes serentak pembayaran tidak selesai.');
    guard_assert(guard_count($koneksi, 'bayar', $nis) === $beforePayments + 1, 'Dua kasir mencatat pembayaran dengan kunci sama dua kali.');

    $parallelSavingA = guard_form($base, '/tabungan/masuk.php', $first);
    $parallelSavingB = guard_form($secondBase, '/tabungan/masuk.php', $second);
    $keys[] = $parallelSavingA['request_key'];
    $beforeSaving = guard_count($koneksi, 'transaksi_m', $nis); $beforeBalance = guard_balance($koneksi, $nis);
    $savingPair = guard_parallel([
        [$base . '/tabungan/proses.php', $savingsData + $parallelSavingA, $first],
        [$secondBase . '/tabungan/proses.php', $savingsData + ['csrf_token'=>$parallelSavingB['csrf_token'],'request_key'=>$parallelSavingA['request_key']], $second],
    ]);
    guard_assert($savingPair[0]['status'] === 302 && $savingPair[1]['status'] === 302, 'Tes serentak tabungan tidak selesai.');
    guard_assert(guard_count($koneksi, 'transaksi_m', $nis) === $beforeSaving + 1 && guard_balance($koneksi, $nis) === $beforeBalance + 200000.0,
        'Dua kasir mencatat setoran dengan kunci sama dua kali.');

    $differentPaymentA = guard_form($base, '/pembayaran/form.php', $first);
    $differentPaymentB = guard_form($secondBase, '/pembayaran/form.php', $second);
    $keys[] = $differentPaymentA['request_key']; $keys[] = $differentPaymentB['request_key'];
    $beforePayments = guard_count($koneksi, 'bayar', $nis);
    $competingPayment = $paymentData; $competingPayment['uang_psb'] = 600000;
    $paymentPair = guard_parallel([
        [$base . '/pembayaran/proses.php', $competingPayment + $differentPaymentA, $first],
        [$secondBase . '/pembayaran/proses.php', $competingPayment + $differentPaymentB, $second],
    ]);
    guard_assert($paymentPair[0]['status'] === 302 && $paymentPair[1]['status'] === 302, 'Tes dua kasir pada tagihan PSB tidak selesai.');
    $afterPayments = guard_count($koneksi, 'bayar', $nis);
    guard_assert($afterPayments === $beforePayments + 1,
        'Dua kasir melampaui sisa tagihan PSB: sebelum=' . $beforePayments . ', sesudah=' . $afterPayments
        . ', respons=' . json_encode($paymentPair)
        . ', flash=' . guard_flash($base, $first) . ' / ' . guard_flash($secondBase, $second));

    $otherA = guard_form($base, '/pembayaran/form.php', $first);
    $otherB = guard_form($secondBase, '/pembayaran/form.php', $second);
    $keys[] = $otherA['request_key']; $keys[] = $otherB['request_key'];
    $otherData = $paymentData; unset($otherData['uang_psb']);
    $otherData += ['biaya_lain_detail_id'=>[''], 'biaya_lain_tagihan_id'=>[(string)$billId],
        'biaya_lain_nominal'=>['600000'], 'biaya_lain_keterangan'=>['']];
    $otherPair = guard_parallel([
        [$base . '/pembayaran/proses.php', $otherData + $otherA, $first],
        [$secondBase . '/pembayaran/proses.php', $otherData + $otherB, $second],
    ]);
    guard_assert(guard_bill_paid($koneksi, $billId) === 600000.0, 'Dua kasir melampaui tagihan Biaya Lain: ' . json_encode($otherPair));

    $differentSavingA = guard_form($base, '/tabungan/keluar.php', $first);
    $differentSavingB = guard_form($secondBase, '/tabungan/keluar.php', $second);
    $keys[] = $differentSavingA['request_key']; $keys[] = $differentSavingB['request_key'];
    $beforeWithdrawals = guard_count($koneksi, 'transaksi_k', $nis);
    $competingWithdrawal = ['aksi'=>'keluar','no_induk'=>$nis,'nominal'=>'200000'];
    $savingPair = guard_parallel([
        [$base . '/tabungan/proses.php', $competingWithdrawal + $differentSavingA, $first],
        [$secondBase . '/tabungan/proses.php', $competingWithdrawal + $differentSavingB, $second],
    ]);
    guard_assert($savingPair[0]['status'] === 302 && $savingPair[1]['status'] === 302, 'Tes dua kasir pada penarikan tidak selesai.');
    guard_assert(guard_count($koneksi, 'transaksi_k', $nis) === $beforeWithdrawals + 1 && guard_balance($koneksi, $nis) === 100000.0,
        'Dua kasir menarik dana melebihi saldo.');

} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach ($keys as $key) {
        $stmt = $koneksi->prepare('DELETE FROM keuangan_request WHERE request_key=?');
        $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close();
    }
    $stmt = $koneksi->prepare('DELETE FROM spp_audit_log WHERE no_induk=?'); $stmt->bind_param('s',$nis); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=?'); $stmt->bind_param('i',$billId); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM bayar WHERE NO_INDUK=?'); $stmt->bind_param('s',$nis); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM tagihan_biaya_lain WHERE id=?'); $stmt->bind_param('i',$billId); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM master_biaya_lain WHERE id=?'); $stmt->bind_param('i',$masterId); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM transaksi_m WHERE NO_INDUK=?'); $stmt->bind_param('s',$nis); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM transaksi_k WHERE NO_INDUK=?'); $stmt->bind_param('s',$nis); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM tabungan WHERE NO_INDUK=?'); $stmt->bind_param('s',$nis); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?'); $stmt->bind_param('s',$nis); $stmt->execute(); $stmt->close();
}
if ($failure) throw $failure;
echo "OK: CSRF, replay berurutan, rollback, serta dua permintaan serentak untuk pembayaran dan tabungan.\n";
