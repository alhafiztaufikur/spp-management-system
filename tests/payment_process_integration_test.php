<?php

/**
 * Tes HTTP ini sengaja memutasi database. Jalankan hanya pada database
 * disposable dengan SPP_TEST_ALLOW_MUTATION=1 dan kredensial admin test.
 */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/spp_billing.php';
require_once __DIR__ . '/../includes/komite_billing.php';

function payment_process_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function payment_process_request(string $url, array $data, array &$cookies): array {
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
    if ($body === false) throw new RuntimeException('HTTP request ke aplikasi gagal.');

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function payment_process_flash(string $baseUrl, array &$cookies): string {
    $page = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $page['body'], $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    if (preg_match('/window\.sppFlashWarning\s*=\s*([^\r\n]+);/', $page['body'], $match)) {
        $warning = json_decode(trim($match[1]), true);
        if (is_array($warning)) return (string)($warning['message'] ?? '');
    }
    return '';
}

function payment_process_csrf(string $baseUrl, int $paymentId, array &$cookies): string {
    $page = payment_process_request($baseUrl . '/pembayaran/edit.php?id=' . $paymentId, [], $cookies);
    payment_process_assert($page['status'] === 200, 'Halaman edit admin tidak dapat dibuka.');
    if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $match)) {
        throw new RuntimeException('Token CSRF pembayaran tidak ditemukan.');
    }
    return $match[1];
}

function payment_process_unique_nis(mysqli $db): string {
    do {
        $nis = (string)random_int(9900000000, 9999999999);
        $stmt = $db->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
        $stmt->bind_param('s', $nis);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    } while ($exists);
    return $nis;
}

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    fwrite(STDERR, "SKIPPED: set SPP_TEST_ALLOW_MUTATION=1 hanya pada database disposable.\n");
    exit(0);
}
if (!(string)getenv('SPP_TEST_ADMIN_PASSWORD')) {
    fwrite(STDERR, "FAILED: SPP_TEST_ADMIN_PASSWORD wajib diisi untuk akun admin database test.\n");
    exit(1);
}

$baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1/sppaman/spp-management-system';
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');

if (spp_billing_schema_ready($koneksi)) {
    $nis = payment_process_unique_nis($koneksi);
    $yearId = 0;
    $masterId = 0;
    $failure = null;
    try {
        $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
        payment_process_assert((bool)$class, 'Rombel kelas 1 tidak tersedia untuk tes pembayaran SPP terbit.');
        $classId = (int)$class['id'];
        $start = (int)date('Y') + 8;
        $label = $start . '/' . ($start + 1);

        $koneksi->begin_transaction();
        $master = spp_master_ensure_year($koneksi, $label, true);
        $masterId = (int)$master['id'];
        $yearId = (int)$master['tahun_ajaran_id'];
        spp_master_save_rates($koneksi, $masterId, array_fill(1, 6, 250000));
        $name = 'UJI HTTP SPP TERBIT';
        $level = '1';
        $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG) VALUES(?,?,?,?,250000,15000)');
        $stmt->bind_param('sssi', $nis, $name, $level, $classId); $stmt->execute(); $stmt->close();
        $snapshot = '1A'; $status = 'aktif'; $spp = 250000.0; $komite = 0.0;
        $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->bind_param('issisdds', $yearId, $nis, $level, $classId, $snapshot, $spp, $komite, $status); $stmt->execute(); $placementId=(int)$koneksi->insert_id; $stmt->close();
        komite_sync_placement($koneksi,$placementId);
        $published = spp_publish_students($koneksi, $masterId, [$nis]);
        payment_process_assert($published['created'] === 12, 'Penerbitan HTTP tidak menyiapkan 12 tagihan.');
        $koneksi->commit();

        $cookies = [];
        $login = payment_process_request($baseUrl . '/login.php', ['username'=>'admin','password'=>$password], $cookies);
        payment_process_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin database test gagal.');
        $post = static function (string $month,float $money,float $komite=0,bool $useDeposit=false,string $action='bayar') use ($baseUrl, &$cookies, $nis, $start): array {
            return payment_process_request($baseUrl . '/pembayaran/proses.php', [
                'aksi'=>'input', 'payment_plan'=>'monthly', 'no_induk'=>$nis,
                'bulan_bayar'=>$month, 'tahun_bayar'=>(string)$start,
                'sistem_pembayaran'=>'Tunai', 'uang_spp'=>$money,'uang_komite'=>$komite,'spp_action'=>$action,
                'gunakan_titipan_spp'=>$useDeposit ? '1' : '0',
            ], $cookies);
        };

        payment_process_assert($post('08',250000,15000)['status']===302,'Permintaan tunggakan tidak mengembalikan respons.');
        $blockedFlash=payment_process_flash($baseUrl,$cookies);
        payment_process_assert(str_contains($blockedFlash,'Lunasi dahulu SPP Juli'),'Bulan baru tidak diblokir oleh tunggakan lama: '.$blockedFlash);
        payment_process_assert($post('07',600000,15000)['status']===302,'Permintaan kelebihan SPP tidak mengembalikan respons.');
        payment_process_assert(str_contains(payment_process_flash($baseUrl,$cookies),'harus dilunasi tepat'),'Kelebihan SPP tidak diblokir.');
        payment_process_assert($post('07',250000,0)['status']===302,'Permintaan tanpa Komite tidak mengembalikan respons.');
        payment_process_assert(str_contains(payment_process_flash($baseUrl,$cookies),'SPP dan Komite bulan ini'),'SPP tanpa Komite tidak diblokir.');
        payment_process_assert($post('07',250000,15000)['status']===302,'Pembayaran Juli gagal.');
        $first=$koneksi->query("SELECT id,U_SPP,U_TITIPAN_SPP,U_KOMITE,total_jumlah FROM bayar WHERE NO_INDUK='{$nis}' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        payment_process_assert($first && (float)$first['U_SPP']===250000.0 && (float)$first['U_KOMITE']===15000.0 && (float)$first['total_jumlah']===265000.0,'SPP dan Komite Juli tidak dicatat tepat.');
        payment_process_assert($post('08',250000,15000)['status']===302,'Pembayaran Agustus gagal.');
        payment_process_assert($post('09',100000,0,false,'titipan')['status']===302,'Titipan eksplisit gagal.');
        $deposit=$koneksi->query("SELECT id,U_SPP,U_TITIPAN_SPP,total_jumlah FROM bayar WHERE NO_INDUK='{$nis}' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        payment_process_assert($deposit && (float)$deposit['U_SPP']===0.0 && (float)$deposit['U_TITIPAN_SPP']===100000.0,'Titipan tidak dicatat terpisah.');
        payment_process_assert($post('09',150000,15000,true)['status']===302,'Penggunaan Titipan SPP gagal.');
        $second=$koneksi->query("SELECT id,U_SPP,U_TITIPAN_SPP,U_KOMITE,total_jumlah FROM bayar WHERE NO_INDUK='{$nis}' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        payment_process_assert($second && (float)$second['U_SPP']===150000.0 && (float)$second['U_KOMITE']===15000.0 && (float)$second['total_jumlah']===165000.0,'Uang baru, Komite, dan titipan terpakai tercampur.');
        payment_process_assert(abs(spp_deposit_balance($koneksi,$nis))<.001,'Saldo titipan setelah pemakaian tidak nol.');

        $editPage = payment_process_request($baseUrl . '/pembayaran/edit.php?id=' . (int)$second['id'], [], $cookies);
        payment_process_assert($editPage['status'] === 200 && preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $editPage['body'], $csrf) === 1, 'Form edit SPP admin atau token CSRF tidak tersedia.');
        $edit = payment_process_request($baseUrl . '/pembayaran/proses.php', [
            'aksi'=>'update', 'id'=>(int)$second['id'], 'csrf_token'=>$csrf[1], 'no_induk'=>$nis,
            'tanggal_bayar'=>date('Y-m-d H:i:s'), 'bulan_bayar'=>'09', 'tahun_bayar'=>(string)$start,
            'sistem_pembayaran'=>'Tunai', 'uang_spp'=>250000, 'uang_komite'=>15000,'gunakan_titipan_spp'=>'0',
        ], $cookies);
        payment_process_assert($edit['status'] === 302, 'Edit pembayaran SPP tidak selesai.');
        $batchState = $koneksi->query('SELECT status,COUNT(*) total FROM spp_alokasi_batch WHERE bayar_id=' . (int)$second['id'] . ' GROUP BY status')->fetch_all(MYSQLI_ASSOC);
        $states = array_column($batchState, 'total', 'status');
        payment_process_assert((int)($states['active']??0)===1 && (int)($states['reversed']??0)===1, 'Edit tidak mempertahankan satu batch aktif dan histori batch terbalik.');

        payment_process_assert($post('10',0,7500)['status']===302,'Komite parsial tidak mengembalikan respons.');
        payment_process_assert(str_contains(payment_process_flash($baseUrl,$cookies),'harus dibayar tepat'),'Komite parsial tidak ditolak.');
        payment_process_assert($post('10',0,15000)['status']===302,'Komite mandiri gagal.');
        $komiteOnly=$koneksi->query("SELECT id FROM bayar WHERE NO_INDUK='{$nis}' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        payment_process_assert($post('10',250000,0)['status']===302,'SPP setelah Komite mandiri gagal.');
        $deleteToken=payment_process_csrf($baseUrl,(int)$komiteOnly['id'],$cookies);
        payment_process_assert(payment_process_request($baseUrl.'/pembayaran/proses.php',['aksi'=>'hapus','id'=>(int)$komiteOnly['id'],'csrf_token'=>$deleteToken],$cookies)['status']===302,'Hapus Komite tidak mengembalikan respons.');
        payment_process_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id='.(int)$komiteOnly['id'])->fetch_assoc()['total']===1,'Komite mandiri terhapus padahal SPP sudah dibayar.');

        $receipt = payment_process_request($baseUrl . '/laporan/cetak_struk.php?id=' . (int)$first['id'], [], $cookies);
        payment_process_assert($receipt['status'] === 200 && str_contains($receipt['body'], 'SPP Juli ' . $start) && str_contains($receipt['body'],'Komite Sekolah (Juli '.$start.')'), 'Struk tidak memuat periode SPP dan Komite.');
    } catch (Throwable $error) {
        $failure = $error;
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
    } finally {
        $stmt = $koneksi->prepare('DELETE FROM bayar WHERE NO_INDUK=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM tagihan_spp WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM tagihan_komite WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM spp_audit_log WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM siswa_tahun_ajaran WHERE no_induk=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?'); $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        if ($masterId > 0) $koneksi->query('DELETE FROM master_spp_tahun WHERE id=' . $masterId);
        if ($yearId > 0) $koneksi->query('DELETE FROM tahun_ajaran WHERE id=' . $yearId);
    }
    if ($failure) {
        fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
        exit(1);
    }
    echo "OK: endpoint satu bulan, Komite wajib, titipan eksplisit, edit admin, dan struk periode.\n";
    exit(0);
}

$testNis = [payment_process_unique_nis($koneksi), payment_process_unique_nis($koneksi), payment_process_unique_nis($koneksi), payment_process_unique_nis($koneksi)];
$createdYearIds = [];
$failure = null;

try {
    $currentYear = (int)date('Y');
    $startYear = 0;
    for ($candidate = $currentYear + 2; $candidate <= $currentYear + 9; $candidate++) {
        $labels = [($candidate - 2) . '/' . ($candidate - 1), ($candidate - 1) . '/' . $candidate, $candidate . '/' . ($candidate + 1)];
        $placeholders = implode(',', array_fill(0, count($labels), '?'));
        $stmt = $koneksi->prepare("SELECT COUNT(*) AS total FROM tahun_ajaran WHERE label IN ($placeholders)");
        $types = str_repeat('s', count($labels));
        $stmt->bind_param($types, ...$labels);
        $stmt->execute();
        $exists = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        if ($exists === 0) { $startYear = $candidate; break; }
    }
    payment_process_assert($startYear > 0, 'Tidak menemukan tiga tahun ajaran kosong untuk tes disposable.');

    $labels = [($startYear - 2) . '/' . ($startYear - 1), ($startYear - 1) . '/' . $startYear, $startYear . '/' . ($startYear + 1)];
    $stmtYear = $koneksi->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'published')");
    foreach ($labels as $label) {
        [$start, $end] = array_map('intval', explode('/', $label));
        $dateStart = $start . '-07-01';
        $dateEnd = $end . '-06-30';
        $stmtYear->bind_param('sss', $label, $dateStart, $dateEnd);
        $stmtYear->execute();
        $createdYearIds[$label] = (int)$koneksi->insert_id;
    }
    $stmtYear->close();

    $classId = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 AND is_active=1 LIMIT 1")->fetch_assoc()['id'];
    payment_process_assert($classId > 0, 'Rombel kelas 1 aktif tidak tersedia untuk tes.');
    $stmtStudent = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, master_kelas_id, SPP_PERBULAN) VALUES (?, ?, \'1\', ?, 275000)');
    foreach (['UJI SPP LINTAS', 'UJI SPP HISTORI', 'UJI SPP PINDAH', 'UJI SPP BARU'] as $index => $name) {
        $stmtStudent->bind_param('ssi', $testNis[$index], $name, $classId);
        $stmtStudent->execute();
    }
    $stmtStudent->close();

    $stmtPlacement = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, komite_snapshot, status) VALUES (?, ?, \'1\', ?, \'1A\', ?, 0, ?)');
    $addPlacement = static function (int $yearId, string $nis, float $tariff, string $status) use ($stmtPlacement, $classId): void {
        $stmtPlacement->bind_param('isids', $yearId, $nis, $classId, $tariff, $status);
        $stmtPlacement->execute();
    };

    $addPlacement($createdYearIds[$labels[1]], $testNis[0], 250000, 'aktif');
    $addPlacement($createdYearIds[$labels[2]], $testNis[0], 275000, 'aktif');
    foreach ($labels as $label) $addPlacement($createdYearIds[$label], $testNis[1], 250000, 'aktif');
    $addPlacement($createdYearIds[$labels[1]], $testNis[2], 250000, 'pindah');
    $addPlacement($createdYearIds[$labels[2]], $testNis[2], 275000, 'aktif');
    $addPlacement($createdYearIds[$labels[2]], $testNis[3], 275000, 'aktif');
    $stmtPlacement->close();

    $stmtPaid = $koneksi->prepare('INSERT INTO bayar (NO_INDUK, KELAS, U_SPP, TGL_BYR, BULAN, TAHUN, total_jumlah, payment_link_version) VALUES (?, \'1\', 250000, ?, ?, ?, 250000, 1)');
    for ($month = 7; $month <= 12; $month++) {
        $date = ($startYear - 1) . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-10 10:00:00';
        $code = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        $year = (string)($startYear - 1);
        $stmtPaid->bind_param('ssss', $testNis[0], $date, $code, $year);
        $stmtPaid->execute();
    }
    for ($month = 1; $month <= 5; $month++) {
        $date = $startYear . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-10 10:00:00';
        $code = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        $year = (string)$startYear;
        $stmtPaid->bind_param('ssss', $testNis[0], $date, $code, $year);
        $stmtPaid->execute();
    }
    $stmtPaid->close();

    $cookies = [];
    $login = payment_process_request($baseUrl . '/login.php', ['username' => 'admin', 'password' => $password], $cookies);
    payment_process_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin database test gagal.');

    $payment = static function (string $nis, string $month, string $year, float $amount) use ($baseUrl, &$cookies): array {
        return payment_process_request($baseUrl . '/pembayaran/proses.php', [
            'aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
            'bulan_bayar' => $month, 'tahun_bayar' => $year,
            'sistem_pembayaran' => 'Tunai', 'uang_spp' => $amount,
        ], $cookies);
    };

    $blockedJuly = $payment($testNis[0], '07', (string)$startYear, 275000);
    payment_process_assert($blockedJuly['status'] === 302, 'SPP Juli lintas tahun tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tunggakan Juni ' . $startYear), 'SPP Juli tahun baru tidak ditolak saat Juni tahun sebelumnya menunggak.');

    $statusUrl = $baseUrl . '/pembayaran/status_spp.php?' . http_build_query([
        'no_induk' => $testNis[0], 'bulan' => '07', 'tahun' => (string)$startYear,
    ]);
    $liveBlocked = payment_process_request($statusUrl, [], $cookies);
    $liveBlockedPayload = json_decode($liveBlocked['body'], true);
    payment_process_assert($liveBlocked['status'] === 200 && ($liveBlockedPayload['status'] ?? '') === 'arrears', 'Endpoint status tidak melaporkan tunggakan Juli.');
    payment_process_assert(($liveBlockedPayload['blocking_period']['label'] ?? '') === 'Juni ' . $startYear, 'Endpoint status tidak memilih tunggakan pertama yang tepat.');

    payment_process_assert($payment($testNis[0], '06', (string)$startYear, 250000)['status'] === 302, 'Pelunasan Juni tahun sebelumnya gagal.');
    $livePayable = payment_process_request($statusUrl, [], $cookies);
    $livePayablePayload = json_decode($livePayable['body'], true);
    payment_process_assert($livePayable['status'] === 200 && ($livePayablePayload['status'] ?? '') === 'payable', 'Endpoint status belum berubah menjadi dapat dibayar setelah Juni lunas.');
    payment_process_assert($payment($testNis[0], '07', (string)$startYear, 275000)['status'] === 302, 'SPP Juli setelah Juni lunas gagal.');

    $stmtJuly = $koneksi->prepare("SELECT id, U_SPP FROM bayar WHERE NO_INDUK=? AND BULAN='07' AND TAHUN=? ORDER BY id DESC LIMIT 1");
    $targetYear = (string)$startYear;
    $stmtJuly->bind_param('ss', $testNis[0], $targetYear);
    $stmtJuly->execute();
    $julyPayment = $stmtJuly->get_result()->fetch_assoc();
    $stmtJuly->close();
    payment_process_assert(abs((float)($julyPayment['U_SPP'] ?? 0) - 275000) < 0.001, 'SPP Juli tidak memakai tarif snapshot tahun ajaran baru.');

    $stmtJune = $koneksi->prepare("SELECT id FROM bayar WHERE NO_INDUK=? AND BULAN='06' AND TAHUN=? ORDER BY id DESC LIMIT 1");
    $stmtJune->bind_param('ss', $testNis[0], $targetYear);
    $stmtJune->execute();
    $junePaymentId = (int)$stmtJune->get_result()->fetch_assoc()['id'];
    $stmtJune->close();

    $csrfToken = payment_process_csrf($baseUrl, $junePaymentId, $cookies);
    $editJune = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'aksi' => 'update', 'id' => $junePaymentId, 'no_induk' => $testNis[0],
        'bulan_bayar' => '06', 'tahun_bayar' => (string)$startYear,
        'sistem_pembayaran' => 'Tunai', 'uang_spp' => 0, 'csrf_token' => $csrfToken,
    ], $cookies);
    payment_process_assert($editJune['status'] === 302, 'Edit prasyarat lintas tahun tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tidak bisa dikosongkan karena Juli ' . $startYear . ' sudah dibayar'), 'Edit Juni tidak ditolak saat Juli tahun berikutnya sudah dibayar.');

    $deleteViaGet = payment_process_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=' . $junePaymentId, [], $cookies);
    payment_process_assert($deleteViaGet['status'] === 302, 'Hapus via URL langsung tidak ditolak dengan redirect.');
    payment_process_assert((int)$koneksi->query('SELECT COUNT(*) total FROM bayar WHERE id=' . $junePaymentId)->fetch_assoc()['total'] === 1, 'Hapus via GET masih memutasi transaksi.');

    $deleteJune = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'aksi' => 'hapus', 'id' => $junePaymentId, 'csrf_token' => $csrfToken,
    ], $cookies);
    payment_process_assert($deleteJune['status'] === 302, 'Hapus POST prasyarat lintas tahun tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tidak bisa dihapus karena Juli ' . $startYear . ' sudah dibayar'), 'Hapus Juni tidak ditolak saat Juli tahun berikutnya sudah dibayar.');

    $blockedHistory = $payment($testNis[1], '07', (string)$startYear, 250000);
    payment_process_assert($blockedHistory['status'] === 302, 'Tunggakan historis tidak mengembalikan redirect.');
    payment_process_assert(str_contains(payment_process_flash($baseUrl, $cookies), 'tunggakan Juli ' . ($startYear - 2)), 'Periode tunggakan aktif paling awal tidak dipilih sebagai penghalang.');

    payment_process_assert($payment($testNis[2], '07', (string)$startYear, 275000)['status'] === 302, 'Penempatan pindah justru memblokir SPP tahun aktif.');
    payment_process_assert($payment($testNis[3], '07', (string)$startYear, 275000)['status'] === 302, 'Siswa baru tanpa penempatan aktif sebelumnya justru terblokir.');
    payment_process_assert($payment($testNis[0], '08', (string)$startYear, 275000)['status'] === 302, 'Urutan setelah Juli tidak dapat diteruskan.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($testNis) {
        $placeholders = implode(',', array_fill(0, count($testNis), '?'));
        $types = str_repeat('s', count($testNis));
        $stmt = $koneksi->prepare("DELETE FROM bayar WHERE NO_INDUK IN ($placeholders)");
        $stmt->bind_param($types, ...$testNis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("DELETE FROM siswa_tahun_ajaran WHERE no_induk IN ($placeholders)");
        $stmt->bind_param($types, ...$testNis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("DELETE FROM siswa WHERE NO_INDUK IN ($placeholders)");
        $stmt->bind_param($types, ...$testNis); $stmt->execute(); $stmt->close();
    }
    if ($createdYearIds) {
        $yearIds = array_values($createdYearIds);
        $placeholders = implode(',', array_fill(0, count($yearIds), '?'));
        $types = str_repeat('i', count($yearIds));
        $stmt = $koneksi->prepare("DELETE FROM tahun_ajaran WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$yearIds); $stmt->execute(); $stmt->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK: endpoint SPP penuh menegakkan urutan lintas tahun, snapshot tarif, dan penempatan aktif.\n";
