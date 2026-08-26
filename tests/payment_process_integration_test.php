<?php
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/support/assert_audit_database.php';
test_require_disposable_audit_database($koneksi);

function payment_process_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$paymentProcessIdempotencyKeys = [];

function payment_process_request(string $url, array $data, array &$cookies): array {
    global $paymentProcessIdempotencyKeys;
    if (str_contains($url, '/pembayaran/proses.php') && !isset($data['idempotency_key'])) {
        $data['idempotency_key'] = bin2hex(random_bytes(32));
    }
    if (str_contains($url, '/pembayaran/proses.php') && preg_match('/^[a-f0-9]{64}$/', (string)($data['idempotency_key'] ?? ''))) {
        $paymentProcessIdempotencyKeys[] = (string)$data['idempotency_key'];
    }
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create([
        'http' => [
            'method' => $data ? 'POST' : 'GET',
            'header' => implode("\r\n", $headers),
            'content' => $data ? http_build_query($data) : '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP request ke aplikasi gagal.');

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => $body];
}

function payment_process_csrf(string $body, string $formId): string {
    if (!preg_match('/<form\b[^>]*id="' . preg_quote($formId, '/') . '"[^>]*>(.*?)<\/form>/si', $body, $formMatch)
        || !preg_match('/name="csrf_token"\s+value="([^"]+)"/', $formMatch[1], $match)) {
        throw new RuntimeException('Token CSRF tidak ditemukan pada respons aplikasi.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function payment_process_idempotency_key(string $body, string $formId = 'form-bayar'): string {
    if (!preg_match('/<form\b[^>]*id="' . preg_quote($formId, '/') . '"[^>]*>(.*?)<\/form>/si', $body, $formMatch)
        || !preg_match('/name="idempotency_key"\s+value="([a-f0-9]{64})"/', $formMatch[1], $match)) {
        throw new RuntimeException('Kunci idempotency pembayaran tidak ditemukan pada form.');
    }
    return $match[1];
}

do {
    $noInduk = (string)random_int(9900000000, 9999999999);
    $stmtCheck = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
    $stmtCheck->bind_param('s', $noInduk);
    $stmtCheck->execute();
    $exists = (bool)$stmtCheck->get_result()->fetch_row();
    $stmtCheck->close();
} while ($exists);
do {
    $targetNoInduk = (string)random_int(9900000000, 9999999999);
    $stmtTargetCheck = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
    $stmtTargetCheck->bind_param('s', $targetNoInduk);
    $stmtTargetCheck->execute();
    $targetExists = $targetNoInduk === $noInduk || (bool)$stmtTargetCheck->get_result()->fetch_row();
    $stmtTargetCheck->close();
} while ($targetExists);

$failure = null;
$otherFeeMasterIds = [];
$otherFeeBillIds = [];
$manualSavingsId = 0;
try {
    $name = 'UJI INTEGRASI CICILAN';
    $class = '1';
    $monthlyFee = 250000.0;
    $classRow = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_active=1 ORDER BY is_placeholder DESC, id LIMIT 1")->fetch_assoc();
    payment_process_assert((bool)$classRow, 'Fixture audit tidak memiliki master kelas 1 aktif.');
    $classId = (int)$classRow['id'];
    $stmtStudent = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, master_kelas_id, SPP_PERBULAN) VALUES (?, ?, ?, ?, ?)');
    $stmtStudent->bind_param('sssid', $noInduk, $name, $class, $classId, $monthlyFee);
    $stmtStudent->execute();
    $stmtStudent->close();
    $targetName = 'UJI TARGET CICILAN';
    $stmtTarget = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, master_kelas_id, SPP_PERBULAN) VALUES (?, ?, ?, ?, ?)');
    $stmtTarget->bind_param('sssid', $targetNoInduk, $targetName, $class, $classId, $monthlyFee);
    $stmtTarget->execute();
    $stmtTarget->close();

    $stmtOtherFee = $koneksi->prepare('INSERT INTO master_biaya_lain (nama, nominal, is_active) VALUES (?, ?, 1)');
    for ($index = 1; $index <= 5; $index++) {
        $otherFeeName = 'UJI BIAYA LEGACY ' . $noInduk . ' #' . $index;
        $otherFeeLimit = 100000.0;
        $stmtOtherFee->bind_param('sd', $otherFeeName, $otherFeeLimit);
        $stmtOtherFee->execute();
        $otherFeeMasterIds[] = (int)$koneksi->insert_id;
    }
    $stmtOtherFee->close();

    $stmtBill=$koneksi->prepare("INSERT INTO tagihan_biaya_lain (master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,status) SELECT id,?,?,nama,nominal,'Kelas 1 (Belum Ditentukan)','open' FROM master_biaya_lain WHERE id=?");
    foreach($otherFeeMasterIds as $masterId){$stmtBill->bind_param('sii',$noInduk,$classId,$masterId);$stmtBill->execute();$otherFeeBillIds[]=(int)$koneksi->insert_id;}$stmtBill->close();

    $baseUrl = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
    $username = (string)getenv('SPP_TEST_ADMIN_USERNAME');
    $password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
    payment_process_assert($baseUrl === 'http://127.0.0.1:8099', 'Tes mutasi hanya boleh memakai server audit http://127.0.0.1:8099.');
    payment_process_assert($username !== '' && $password !== '', 'Kredensial akun audit wajib disediakan lewat environment.');
    $cookies = [];
    $loginPage = payment_process_request($baseUrl . '/login.php', [], $cookies);
    $loginToken = payment_process_csrf($loginPage['body'], 'form-login');
    $login = payment_process_request($baseUrl . '/login.php', [
        'csrf_token' => $loginToken,
        'username' => $username,
        'password' => $password,
    ], $cookies);
    payment_process_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin untuk tes integrasi gagal.');

    $paymentForm = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    $paymentToken = payment_process_csrf($paymentForm['body'], 'form-bayar');
    payment_process_assert((bool)preg_match('/name="idempotency_key"\s+value="[a-f0-9]{64}"/', $paymentForm['body']), 'Form pembayaran tidak menyediakan key idempotency kuat.');

    $common = [
        'csrf_token' => $paymentToken,
        'aksi' => 'input',
        'payment_plan' => 'monthly',
        'no_induk' => $noInduk,
        'tanggal_bayar' => '2000-01-01',
        'bulan_bayar' => '08',
        'tahun_bayar' => '2026',
        'sistem_pembayaran' => 'Tunai',
        'audit_reason' => 'UJI OTOMATIS KOREKSI PEMBAYARAN',
    ];
    $annualIdempotencyKey = bin2hex(random_bytes(32));
    $beforeAnnual = (int)$koneksi->query('SELECT COUNT(*) AS total FROM bayar')->fetch_assoc()['total'];
    $annualResponse = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'payment_plan' => 'annual',
        'uang_spp' => 3000000,
        'idempotency_key' => $annualIdempotencyKey,
    ]), $cookies);
    payment_process_assert($annualResponse['status'] === 302, 'Payment plan annual crafted request tidak mengikuti alur penolakan.');
    $afterAnnual = (int)$koneksi->query('SELECT COUNT(*) AS total FROM bayar')->fetch_assoc()['total'];
    payment_process_assert($afterAnnual === $beforeAnnual, 'Payment plan annual crafted request masih membuat pembayaran.');
    $stmtAnnualClaim = $koneksi->prepare("SELECT COUNT(*) AS total FROM mutation_request WHERE scope='payment' AND request_key=?");
    $stmtAnnualClaim->bind_param('s', $annualIdempotencyKey);
    $stmtAnnualClaim->execute();
    $annualClaimCount = (int)$stmtAnnualClaim->get_result()->fetch_assoc()['total'];
    $stmtAnnualClaim->close();
    payment_process_assert($annualClaimCount === 0, 'Rollback payment annual meninggalkan claim idempotency.');
    $annualFeedback = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(str_contains($annualFeedback['body'], 'Pembayaran banyak bulan sedang ditangguhkan'), 'Penolakan backend untuk payment plan annual tidak tampil.');
    $legacySavingsResponse = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + [
        'uang_spp' => 100000,
        'tabungan_wajib' => 20000,
    ], $cookies);
    payment_process_assert($legacySavingsResponse['status'] === 302, 'POST tabungan legacy tidak mengembalikan redirect yang diharapkan.');
    $legacySavingsFeedback = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(str_contains($legacySavingsFeedback['body'], 'Input tabungan lewat pembayaran sudah dinonaktifkan'), 'POST tabungan legacy tidak ditolak backend.');
    payment_process_assert(!str_contains($legacySavingsFeedback['body'], 'name="tabungan_wajib"'), 'Form input masih memiliki field tabungan pembayaran.');
    payment_process_assert(!str_contains($legacySavingsFeedback['body'], 'id="tab-wajib"'), 'Form input masih memiliki kontrol tabungan pembayaran.');

    $invalidPaymentCount = (int)$koneksi->query('SELECT COUNT(*) AS total FROM bayar')->fetch_assoc()['total'];
    foreach (['-1', 'NaN', '999999999999999999999999999999'] as $invalidAmount) {
        $invalidResponse = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
            'uang_spp' => $invalidAmount,
        ]), $cookies);
        payment_process_assert($invalidResponse['status'] === 302, 'Nominal payment invalid tidak mengikuti PRG penolakan.');
    }
    $invalidPaymentCountAfter = (int)$koneksi->query('SELECT COUNT(*) AS total FROM bayar')->fetch_assoc()['total'];
    payment_process_assert($invalidPaymentCountAfter === $invalidPaymentCount, 'Nominal negatif/NaN/overflow membuat row pembayaran.');

    $blockedAugust = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'no_induk' => $targetNoInduk,
        'uang_spp' => 50000,
    ]), $cookies);
    payment_process_assert($blockedAugust['status'] === 302, 'POST SPP Agustus tanpa Juli lunas tidak mengembalikan redirect.');
    $blockedFeedback = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(
        str_contains($blockedFeedback['body'], 'belum bisa dibayar karena Juli 2026 belum lunas'),
        'SPP Agustus tidak ditolak saat Juli belum lunas.'
    );

    $julyResponse = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'bulan_bayar' => '07',
        'uang_spp' => 250000,
    ]), $cookies);
    payment_process_assert($julyResponse['status'] === 302, 'Pelunasan SPP Juli sebagai prasyarat Agustus gagal.');

    $response = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + [
        'uang_spp' => 100000,
        'biaya_lain_detail_id' => [0, 0, 0, 0, 0],
        'biaya_lain_tagihan_id' => $otherFeeBillIds,
        'biaya_lain_nominal' => [11000, 12000, 13000, 14000, 15000],
        'biaya_lain_keterangan' => ['', '', '', '', ''],
    ], $cookies);
    payment_process_assert($response['status'] === 302, 'Endpoint pembayaran tidak mengembalikan redirect yang diharapkan.');
    foreach ([150000, 10000] as $amount) {
        $response = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + ['uang_spp' => $amount], $cookies);
        payment_process_assert($response['status'] === 302, 'Endpoint pembayaran tidak mengembalikan redirect yang diharapkan.');
    }

    $stmtResult = $koneksi->prepare("
        SELECT COUNT(*) AS payment_count, COALESCE(SUM(U_SPP), 0) AS paid,
               MIN(DATE(TGL_BYR)) AS payment_date
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtResult->bind_param('s', $noInduk);
    $stmtResult->execute();
    $result = $stmtResult->get_result()->fetch_assoc();
    $stmtResult->close();
    payment_process_assert((int)$result['payment_count'] === 2, 'Pembayaran ketiga setelah lunas tidak ditolak.');
    payment_process_assert(abs((float)$result['paid'] - 250000.0) < 0.001, 'Dua cicilan tidak berjumlah Rp250.000.');
    payment_process_assert($result['payment_date'] === date('Y-m-d'), 'Tanggal manipulasi dari browser tidak diabaikan backend.');

    $stmtClaims = $koneksi->prepare("SELECT COUNT(*) AS total FROM bayar_spp_periode WHERE no_induk = ? AND tahun = '2026' AND bulan = '08'");
    $stmtClaims->bind_param('s', $noInduk);
    $stmtClaims->execute();
    $claimCount = (int)$stmtClaims->get_result()->fetch_assoc()['total'];
    $stmtClaims->close();
    payment_process_assert($claimCount === 2, 'Dua cicilan tidak memiliki dua pemetaan periode.');

    $form = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(str_contains($form['body'], 'melebihi sisa tagihan'), 'Pesan penolakan pembayaran setelah lunas tidak tampil.');

    $stmtJuly = $koneksi->prepare("
        SELECT MIN(id) AS id
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '07' OR BULAN = '7' OR BULAN = 'Juli')
    ");
    $stmtJuly->bind_param('s', $noInduk);
    $stmtJuly->execute();
    $julyPaymentId = (int)$stmtJuly->get_result()->fetch_assoc()['id'];
    $stmtJuly->close();
    $deleteJuly = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'csrf_token' => $paymentToken,
        'aksi' => 'hapus',
        'id' => $julyPaymentId,
        'audit_reason' => 'UJI OTOMATIS HAPUS PRASYARAT',
    ], $cookies);
    payment_process_assert($deleteJuly['status'] === 302, 'Hapus SPP Juli yang menjadi prasyarat tidak mengembalikan redirect.');
    $deleteJulyFeedback = payment_process_request($baseUrl . '/pembayaran/lihat.php', [], $cookies);
    payment_process_assert(
        str_contains($deleteJulyFeedback['body'], 'tidak bisa dihapus karena Agustus 2026 sudah memiliki pembayaran'),
        'Hapus SPP Juli tidak ditolak saat Agustus sudah memiliki pembayaran.'
    );

    $stmtFirst = $koneksi->prepare("
        SELECT MIN(id) AS id
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtFirst->bind_param('s', $noInduk);
    $stmtFirst->execute();
    $firstPaymentId = (int)$stmtFirst->get_result()->fetch_assoc()['id'];
    $stmtFirst->close();

    $stmtPaymentTime = $koneksi->prepare('SELECT TGL_BYR FROM bayar WHERE id = ?');
    $stmtPaymentTime->bind_param('i', $firstPaymentId);
    $stmtPaymentTime->execute();
    $paymentTime = (string)$stmtPaymentTime->get_result()->fetch_assoc()['TGL_BYR'];
    $stmtPaymentTime->close();
    $manualSavingsAmount = 43210.0;
    $manualSavingsUser = 'manual-audit-fixture';
    $stmtManualSavings = $koneksi->prepare('INSERT INTO transaksi_m (bayar_id, NO_INDUK, TANGGAL, MASUK, KELUAR, user_id) VALUES (NULL, ?, ?, ?, 0, ?)');
    $stmtManualSavings->bind_param('ssds', $noInduk, $paymentTime, $manualSavingsAmount, $manualSavingsUser);
    $stmtManualSavings->execute();
    $manualSavingsId = (int)$koneksi->insert_id;
    $stmtManualSavings->close();

    $stmtAdmin = $koneksi->prepare('SELECT id, nama FROM admin WHERE username = ? LIMIT 1');
    $stmtAdmin->bind_param('s', $username);
    $stmtAdmin->execute();
    $auditAdmin = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
    payment_process_assert((bool)$auditAdmin, 'Akun admin audit tidak ditemukan.');
    $adminId = (string)$auditAdmin['id'];
    $stmtOperator = $koneksi->prepare("
        SELECT b.user_id AS payment_operator,
               (SELECT COUNT(*) FROM transaksi_m WHERE bayar_id = b.id) AS linked_savings
        FROM bayar b
        WHERE b.id = ?
    ");
    $stmtOperator->bind_param('i', $firstPaymentId);
    $stmtOperator->execute();
    $operatorRow = $stmtOperator->get_result()->fetch_assoc();
    $stmtOperator->close();
    payment_process_assert(
        (string)$operatorRow['payment_operator'] === $adminId
        && (int)$operatorRow['linked_savings'] === 0,
        'Transaksi pembayaran belum menyimpan ID kasir atau masih membuat tabungan terkait.'
    );

    $mutatedMasterName = 'UJI MASTER BIAYA BERUBAH ' . $noInduk;
    $mutatedMasterNominal = 999999.0;
    $stmtMutateMaster = $koneksi->prepare('UPDATE master_biaya_lain SET nama = ?, nominal = ? WHERE id = ?');
    $stmtMutateMaster->bind_param('sdi', $mutatedMasterName, $mutatedMasterNominal, $otherFeeMasterIds[0]);
    $stmtMutateMaster->execute();
    $stmtMutateMaster->close();
    $stmtSnapshotDetail = $koneksi->prepare('SELECT nama_biaya_snapshot, nominal_snapshot FROM bayar_biaya_lain WHERE bayar_id = ? AND urutan = 1');
    $stmtSnapshotDetail->bind_param('i', $firstPaymentId);
    $stmtSnapshotDetail->execute();
    $snapshotDetail = $stmtSnapshotDetail->get_result()->fetch_assoc();
    $stmtSnapshotDetail->close();
    payment_process_assert(
        $snapshotDetail
        && str_ends_with((string)$snapshotDetail['nama_biaya_snapshot'], '#1')
        && abs((float)$snapshotDetail['nominal_snapshot'] - 11000.0) < 0.001,
        'Snapshot detail Biaya Lain berubah mengikuti master setelah pembayaran.'
    );
    $overpayOther = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'aksi' => 'update',
        'id' => $firstPaymentId,
        'tanggal_bayar' => date('Y-m-d'),
        'uang_spp' => 100000,
        'biaya_lain_detail_id' => [0],
        'biaya_lain_tagihan_id' => [$otherFeeBillIds[0]],
        'biaya_lain_nominal' => [999999],
        'biaya_lain_keterangan' => [''],
    ]), $cookies);
    payment_process_assert($overpayOther['status'] === 302, 'Overpay Biaya Lain tidak mengikuti PRG penolakan.');
    $stmtOtherPaid = $koneksi->prepare('SELECT COALESCE(SUM(nominal_snapshot), 0) AS total FROM bayar_biaya_lain WHERE bayar_id = ?');
    $stmtOtherPaid->bind_param('i', $firstPaymentId);
    $stmtOtherPaid->execute();
    $otherPaidAfterReject = (float)$stmtOtherPaid->get_result()->fetch_assoc()['total'];
    $stmtOtherPaid->close();
    payment_process_assert(abs($otherPaidAfterReject - 65000.0) < 0.001, 'Overpay Biaya Lain mengubah detail pembayaran secara parsial.');

    $stmtLegacyOther = $koneksi->prepare("\n        SELECT U_LAIN, LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2,\n               LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,\n               (SELECT COUNT(*) FROM bayar_biaya_lain WHERE bayar_id = bayar.id) AS detail_count\n        FROM bayar WHERE id = ?\n    ");
    $stmtLegacyOther->bind_param('i', $firstPaymentId);
    $stmtLegacyOther->execute();
    $legacyOther = $stmtLegacyOther->get_result()->fetch_assoc();
    $stmtLegacyOther->close();
    payment_process_assert(
        abs((float)$legacyOther['U_LAIN'] - 65000.0) < 0.001
        && (int)$legacyOther['detail_count'] === 5
        && str_ends_with((string)$legacyOther['LAIN_LAIN1'], '#1')
        && str_ends_with((string)$legacyOther['LAIN_LAIN4'], '#4')
        && abs((float)$legacyOther['JUMLAH4'] - 14000.0) < 0.001,
        'Input Biaya Lain tidak mencerminkan total dan empat detail pertama ke kolom legacy.'
    );

    $stmtSavings = $koneksi->prepare("
        SELECT
            COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS saldo,
            COALESCE((SELECT MASUK FROM transaksi_m WHERE bayar_id = ?), 0) AS linked_saving
    ");
    $stmtSavings->bind_param('si', $noInduk, $firstPaymentId);
    $stmtSavings->execute();
    $savings = $stmtSavings->get_result()->fetch_assoc();
    $stmtSavings->close();
    payment_process_assert(
        abs((float)$savings['saldo']) < 0.001
        && abs((float)$savings['linked_saving']) < 0.001,
        'Pembayaran masih membuat saldo atau jurnal tabungan terkait.'
    );

    $receipt = payment_process_request($baseUrl . '/laporan/cetak_struk.php?id=' . $firstPaymentId, [], $cookies);
    payment_process_assert($receipt['status'] === 200, 'Struk pembayaran tidak dapat dibuka.');
    payment_process_assert(!str_contains($receipt['body'], 'Tabungan'), 'Struk pembayaran masih menampilkan tabungan.');
    payment_process_assert(!str_contains($receipt['body'], 'Sisa SPP'), 'Struk masih menampilkan Sisa SPP pada bagian Sisa Pembayaran.');
    payment_process_assert(str_contains($receipt['body'], htmlspecialchars((string)$auditAdmin['nama'])), 'Struk belum menampilkan operator dari ID transaksi.');

    $update = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'aksi' => 'update',
        'id' => $firstPaymentId,
        'tanggal_bayar' => date('Y-m-d'),
        'uang_spp' => 50000,
        'biaya_lain_detail_id' => [0, 0],
        'biaya_lain_tagihan_id' => array_slice($otherFeeBillIds, 0, 2),
        'biaya_lain_nominal' => [21000, 22000],
        'biaya_lain_keterangan' => ['', ''],
    ]), $cookies);
    payment_process_assert($update['status'] === 302, 'Edit cicilan tidak mengembalikan redirect yang diharapkan.');

    $stmtAfterEdit = $koneksi->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(U_SPP), 0) AS paid
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtAfterEdit->bind_param('s', $noInduk);
    $stmtAfterEdit->execute();
    $afterEdit = $stmtAfterEdit->get_result()->fetch_assoc();
    $stmtAfterEdit->close();
    payment_process_assert((int)$afterEdit['total'] === 2 && abs((float)$afterEdit['paid'] - 200000.0) < 0.001, 'Edit cicilan tidak menyesuaikan total menjadi Rp200.000.');

    $stmtLegacyEdit = $koneksi->prepare("\n        SELECT U_LAIN, LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2,\n               LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,\n               (SELECT COUNT(*) FROM bayar_biaya_lain WHERE bayar_id = bayar.id) AS detail_count\n        FROM bayar WHERE id = ?\n    ");
    $stmtLegacyEdit->bind_param('i', $firstPaymentId);
    $stmtLegacyEdit->execute();
    $legacyEdit = $stmtLegacyEdit->get_result()->fetch_assoc();
    $stmtLegacyEdit->close();
    payment_process_assert(
        abs((float)$legacyEdit['U_LAIN'] - 43000.0) < 0.001
        && (int)$legacyEdit['detail_count'] === 2
        && abs((float)$legacyEdit['JUMLAH2'] - 22000.0) < 0.001
        && $legacyEdit['LAIN_LAIN3'] === null
        && abs((float)$legacyEdit['JUMLAH3']) < 0.001
        && $legacyEdit['LAIN_LAIN4'] === null
        && abs((float)$legacyEdit['JUMLAH4']) < 0.001,
        'Edit Biaya Lain tidak memperbarui total atau membersihkan slot legacy yang tidak dipakai.'
    );

    $stmtSavingsEdit = $koneksi->prepare("
        SELECT
            COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS saldo,
            COALESCE((SELECT MASUK FROM transaksi_m WHERE bayar_id = ?), 0) AS linked_saving
    ");
    $stmtSavingsEdit->bind_param('si', $noInduk, $firstPaymentId);
    $stmtSavingsEdit->execute();
    $savingsEdit = $stmtSavingsEdit->get_result()->fetch_assoc();
    $stmtSavingsEdit->close();
    payment_process_assert(
        abs((float)$savingsEdit['saldo']) < 0.001
        && abs((float)$savingsEdit['linked_saving']) < 0.001,
        'Edit pembayaran masih membuat saldo atau jurnal tabungan terkait.'
    );

    $finalInstallment = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + ['uang_spp' => 50000], $cookies);
    payment_process_assert($finalInstallment['status'] === 302, 'Pelunasan sisa setelah edit gagal disimpan.');
    $stmtLast = $koneksi->prepare('SELECT MAX(id) AS id FROM bayar WHERE NO_INDUK = ?');
    $stmtLast->bind_param('s', $noInduk);
    $stmtLast->execute();
    $lastPaymentId = (int)$stmtLast->get_result()->fetch_assoc()['id'];
    $stmtLast->close();

    $delete = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'csrf_token' => $paymentToken,
        'aksi' => 'hapus',
        'id' => $lastPaymentId,
        'audit_reason' => 'UJI OTOMATIS HAPUS CICILAN',
    ], $cookies);
    payment_process_assert($delete['status'] === 302, 'Hapus cicilan tidak mengembalikan redirect yang diharapkan.');
    $stmtAfterDelete = $koneksi->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(U_SPP), 0) AS paid,
               (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ? AND tahun = '2026' AND bulan = '08') AS claims
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtAfterDelete->bind_param('ss', $noInduk, $noInduk);
    $stmtAfterDelete->execute();
    $afterDelete = $stmtAfterDelete->get_result()->fetch_assoc();
    $stmtAfterDelete->close();
    payment_process_assert(
        (int)$afterDelete['total'] === 2
        && abs((float)$afterDelete['paid'] - 200000.0) < 0.001
        && (int)$afterDelete['claims'] === 2,
        'Hapus cicilan tidak memulihkan total atau pemetaan periode.'
    );

    $move = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'aksi' => 'update',
        'id' => $firstPaymentId,
        'no_induk' => $targetNoInduk,
        'tanggal_bayar' => date('Y-m-d'),
        'bulan_bayar' => '07',
        'uang_spp' => 50000,
    ]), $cookies);
    payment_process_assert($move['status'] === 302, 'Pemindahan cicilan ke siswa atau periode lain gagal.');
    $moveFeedback = payment_process_request($baseUrl . '/pembayaran/lihat.php', [], $cookies);
    $moveMessage = '';
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $moveFeedback['body'], $match)) {
        $moveMessage = trim(html_entity_decode(strip_tags($match[1])));
    }
    $stmtMoved = $koneksi->prepare("
        SELECT
          (SELECT COALESCE(SUM(U_SPP), 0) FROM bayar WHERE NO_INDUK = ? AND TAHUN = '2026' AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')) AS old_paid,
          (SELECT COALESCE(SUM(U_SPP), 0) FROM bayar WHERE NO_INDUK = ? AND TAHUN = '2026' AND (BULAN = '07' OR BULAN = '7' OR BULAN = 'Juli')) AS new_paid,
          (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ? AND bulan = '08') AS old_claims,
          (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ? AND bulan = '07') AS new_claims
    ");
    $stmtMoved->bind_param('ssss', $noInduk, $targetNoInduk, $noInduk, $targetNoInduk);
    $stmtMoved->execute();
    $moved = $stmtMoved->get_result()->fetch_assoc();
    $stmtMoved->close();
    payment_process_assert(
        abs((float)$moved['old_paid'] - 150000.0) < 0.001
        && abs((float)$moved['new_paid'] - 50000.0) < 0.001
        && (int)$moved['old_claims'] === 1
        && (int)$moved['new_claims'] === 1,
        'Pemindahan cicilan tidak memperbarui siswa lama, siswa baru, atau periode: '
        . json_encode($moved) . ($moveMessage !== '' ? ' | ' . $moveMessage : '')
    );

    $deleteMoved = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'csrf_token' => $paymentToken,
        'aksi' => 'hapus',
        'id' => $firstPaymentId,
        'audit_reason' => 'UJI OTOMATIS HAPUS PEMBAYARAN PINDAH',
    ], $cookies);
    payment_process_assert($deleteMoved['status'] === 302, 'Hapus pembayaran tidak mengembalikan redirect yang diharapkan.');
    $stmtSavingsDelete = $koneksi->prepare('SELECT COUNT(*) AS linked_count FROM transaksi_m WHERE bayar_id = ?');
    $stmtSavingsDelete->bind_param('i', $firstPaymentId);
    $stmtSavingsDelete->execute();
    $linkedAfterDelete = (int)$stmtSavingsDelete->get_result()->fetch_assoc()['linked_count'];
    $stmtSavingsDelete->close();
    payment_process_assert(
        $linkedAfterDelete === 0,
        'Hapus pembayaran masih meninggalkan jurnal tabungan terkait.'
    );
    $stmtManualCheck = $koneksi->prepare('SELECT COUNT(*) AS total, COALESCE(MAX(MASUK), 0) AS amount, bayar_id FROM transaksi_m WHERE id = ? GROUP BY bayar_id');
    $stmtManualCheck->bind_param('i', $manualSavingsId);
    $stmtManualCheck->execute();
    $manualCheck = $stmtManualCheck->get_result()->fetch_assoc();
    $stmtManualCheck->close();
    payment_process_assert(
        $manualCheck && (int)$manualCheck['total'] === 1 && abs((float)$manualCheck['amount'] - $manualSavingsAmount) < 0.001 && $manualCheck['bayar_id'] === null,
        'Jurnal tabungan manual pada timestamp sama ikut berubah saat pembayaran dikoreksi/dihapus.'
    );

    // Replay payment: key yang sama tidak boleh membuat header/audit kedua.
    $replayForm = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    $replayToken = payment_process_csrf($replayForm['body'], 'form-bayar');
    $replayKey = payment_process_idempotency_key($replayForm['body']);
    $replayFields = [
        'csrf_token' => $replayToken,
        'idempotency_key' => $replayKey,
        'aksi' => 'input',
        'payment_plan' => 'monthly',
        'no_induk' => $noInduk,
        'tanggal_bayar' => '2000-01-01',
        'bulan_bayar' => '07',
        'tahun_bayar' => '2027',
        'sistem_pembayaran' => 'Tunai',
        'uang_spp' => 100000,
    ];
    $replayFirst = payment_process_request($baseUrl . '/pembayaran/proses.php', $replayFields, $cookies);
    $replaySecond = payment_process_request($baseUrl . '/pembayaran/proses.php', $replayFields, $cookies);
    payment_process_assert($replayFirst['status'] === 302 && $replaySecond['status'] === 302, 'Replay payment tidak memberi response PRG aman.');
    $replayStmt = $koneksi->prepare(
        "SELECT COUNT(*) AS rows_count, COALESCE(SUM(U_SPP),0) AS total,
                (SELECT COUNT(*) FROM audit_event WHERE event_type='payment.created' AND entity_type='bayar'
                    AND after_data LIKE ? AND after_data LIKE ? AND after_data LIKE ?) AS audit_count
         FROM bayar WHERE NO_INDUK=? AND BULAN='07' AND TAHUN='2027'"
    );
    $replayPattern = '%\"NO_INDUK\":\"' . $noInduk . '\"%';
    $replayPeriodPattern = '%\"BULAN\":\"07\"%';
    $replayYearPattern = '%\"TAHUN\":\"2027\"%';
    $replayStmt->bind_param('ssss', $replayPattern, $replayPeriodPattern, $replayYearPattern, $noInduk);
    $replayStmt->execute();
    $replayState = $replayStmt->get_result()->fetch_assoc();
    $replayStmt->close();
    payment_process_assert(
        (int)$replayState['rows_count'] === 1
        && abs((float)$replayState['total'] - 100000.0) < 0.001
        && (int)$replayState['audit_count'] === 1,
        'Replay payment menggandakan header, nominal, atau audit event: ' . json_encode($replayState)
    );

    // Legacy guard: a version-0 payment must remain immutable from both UI and
    // crafted handlers. The fixture is restored only by the exact cleanup below.
    $stmtMarkLegacy = $koneksi->prepare('UPDATE bayar SET payment_link_version=0 WHERE id=?');
    $stmtMarkLegacy->bind_param('i', $julyPaymentId);
    $stmtMarkLegacy->execute();
    $stmtMarkLegacy->close();
    $legacyEdit = payment_process_request($baseUrl . '/pembayaran/edit.php?id=' . $julyPaymentId, [], $cookies);
    payment_process_assert($legacyEdit['status'] === 302, 'Akses edit pembayaran legacy tidak ditolak dengan redirect.');
    $legacyDelete = payment_process_request($baseUrl . '/pembayaran/proses.php', [
        'csrf_token' => $paymentToken,
        'aksi' => 'hapus',
        'id' => $julyPaymentId,
        'audit_reason' => 'UJI OTOMATIS LEGACY IMMUTABLE',
    ], $cookies);
    payment_process_assert($legacyDelete['status'] === 302, 'Delete pembayaran legacy tidak mengembalikan PRG aman.');
    $stmtLegacyState = $koneksi->prepare("SELECT COUNT(*) AS rows_count, MIN(payment_link_version) AS version,
        (SELECT COUNT(*) FROM audit_event WHERE entity_type='bayar' AND entity_id=? AND event_type='payment.deleted') AS delete_events
        FROM bayar WHERE id=?");
    $stmtLegacyState->bind_param('ii', $julyPaymentId, $julyPaymentId);
    $stmtLegacyState->execute();
    $legacyState = $stmtLegacyState->get_result()->fetch_assoc();
    $stmtLegacyState->close();
    payment_process_assert(
        (int)$legacyState['rows_count'] === 1
        && (int)$legacyState['version'] === 0
        && (int)$legacyState['delete_events'] === 0,
        'Pembayaran legacy berubah atau mencatat delete event: ' . json_encode($legacyState)
    );

    $stmtAudit = $koneksi->prepare(
        "SELECT event_type, actor_admin_id, request_id, reason, before_data, after_data
         FROM audit_event
         WHERE entity_type = 'bayar' AND entity_id = ?
         ORDER BY id"
    );
    $firstPaymentIdText = (string)$firstPaymentId;
    $stmtAudit->bind_param('s', $firstPaymentIdText);
    $stmtAudit->execute();
    $auditRows = $stmtAudit->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtAudit->close();
    $auditTypes = array_column($auditRows, 'event_type');
    payment_process_assert(
        count(array_filter($auditTypes, static fn(string $type): bool => $type === 'payment.created')) === 1
        && count(array_filter($auditTypes, static fn(string $type): bool => $type === 'payment.updated')) === 2
        && count(array_filter($auditTypes, static fn(string $type): bool => $type === 'payment.deleted')) === 1,
        'Lifecycle pembayaran tidak menghasilkan event create/update/delete yang lengkap.'
    );
    foreach ($auditRows as $auditRow) {
        payment_process_assert((int)$auditRow['actor_admin_id'] === (int)$adminId, 'Actor audit pembayaran tidak sesuai akun login.');
        payment_process_assert(preg_match('/^[a-f0-9]{24}$/', (string)$auditRow['request_id']) === 1, 'Request ID audit pembayaran tidak valid.');
        if ($auditRow['event_type'] === 'payment.created') {
            payment_process_assert($auditRow['before_data'] === null && $auditRow['after_data'] !== null, 'Snapshot create pembayaran tidak sesuai.');
        } elseif ($auditRow['event_type'] === 'payment.updated') {
            payment_process_assert($auditRow['before_data'] !== null && $auditRow['after_data'] !== null && mb_strlen((string)$auditRow['reason']) >= 5, 'Snapshot/reason update pembayaran tidak lengkap.');
        } elseif ($auditRow['event_type'] === 'payment.deleted') {
            payment_process_assert($auditRow['before_data'] !== null && $auditRow['after_data'] === null && mb_strlen((string)$auditRow['reason']) >= 5, 'Snapshot/reason delete pembayaran tidak lengkap.');
        }
        payment_process_assert(!str_contains(implode('|', $auditRow), 'AuditTest-Only-2026!'), 'Credential uji bocor ke audit pembayaran.');
    }

    $stmtRejectedAudit = $koneksi->prepare(
        "SELECT COUNT(*) AS total FROM audit_event
         WHERE entity_type = 'bayar' AND entity_id = ? AND event_type = 'payment.deleted'"
    );
    $julyPaymentIdText = (string)$julyPaymentId;
    $stmtRejectedAudit->bind_param('s', $julyPaymentIdText);
    $stmtRejectedAudit->execute();
    $rejectedDeleteAudit = (int)$stmtRejectedAudit->get_result()->fetch_assoc()['total'];
    $stmtRejectedAudit->close();
    payment_process_assert($rejectedDeleteAudit === 0, 'Penghapusan pembayaran yang ditolak tetap membuat event committed.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    foreach (array_unique($paymentProcessIdempotencyKeys) as $idempotencyKey) {
        $stmt = $koneksi->prepare("DELETE FROM mutation_request WHERE scope = 'payment' AND request_key = ?");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $stmt->close();
    }
    $stmtPaymentCleanup=$koneksi->prepare('DELETE FROM bayar WHERE NO_INDUK IN (?,?)');$stmtPaymentCleanup->bind_param('ss',$noInduk,$targetNoInduk);$stmtPaymentCleanup->execute();$stmtPaymentCleanup->close();
    if ($manualSavingsId > 0) {
        $stmtManualCleanup = $koneksi->prepare('DELETE FROM transaksi_m WHERE id = ? AND bayar_id IS NULL');
        $stmtManualCleanup->bind_param('i', $manualSavingsId);
        $stmtManualCleanup->execute();
        $stmtManualCleanup->close();
    }
    $stmtBillCleanup=$koneksi->prepare('DELETE FROM tagihan_biaya_lain WHERE no_induk IN (?,?)');$stmtBillCleanup->bind_param('ss',$noInduk,$targetNoInduk);$stmtBillCleanup->execute();$stmtBillCleanup->close();
    $stmtCleanup = $koneksi->prepare("
        DELETE FROM siswa
        WHERE (NO_INDUK = ? AND NAMA = 'UJI INTEGRASI CICILAN')
           OR (NO_INDUK = ? AND NAMA = 'UJI TARGET CICILAN')
    ");
    $stmtCleanup->bind_param('ss', $noInduk, $targetNoInduk);
    $stmtCleanup->execute();
    $stmtCleanup->close();
    if ($otherFeeMasterIds) {
        $placeholders = implode(',', array_fill(0, count($otherFeeMasterIds), '?'));
        $types = str_repeat('i', count($otherFeeMasterIds));
        $stmtMasterCleanup = $koneksi->prepare("DELETE FROM master_biaya_lain WHERE id IN ($placeholders)");
        $stmtMasterCleanup->bind_param($types, ...$otherFeeMasterIds);
        $stmtMasterCleanup->execute();
        $stmtMasterCleanup->close();
    }
}

$stmtRemaining = $koneksi->prepare('SELECT COUNT(*) AS total FROM siswa WHERE NO_INDUK IN (?, ?)');
$stmtRemaining->bind_param('ss', $noInduk, $targetNoInduk);
$stmtRemaining->execute();
$remaining = (int)$stmtRemaining->get_result()->fetch_assoc()['total'];
$stmtRemaining->close();
if ($remaining !== 0) {
    fwrite(STDERR, "FAILED: data siswa uji tidak terhapus.\n");
    exit(1);
}
if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK: endpoint cicilan menangani input, overlimit, edit, pindah periode/siswa, hapus, tanggal server, penolakan tabungan legacy, Biaya Lain legacy, dan struk.\n";
