<?php
/** HTTP journey from PSB registration through unit graduation; disposable clone only. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: use CLI, SPP_TEST_ALLOW_MUTATION=1, and a db_spp_audit_* clone.\n");
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';
$unitId = (int)(getenv('SPP_TEST_UNIT_ID') ?: 1);
if (!in_array($unitId, [1, 2, 3], true)) throw new RuntimeException('SPP_TEST_UNIT_ID must be 1, 2, or 3.');
[$firstLevel, $lastLevel] = unit_level_bounds($unitId);
$coveredFirstYear = $firstLevel === 1;
$username = [1 => 'admin', 2 => 'admin.smp', 3 => 'admin.sma'][$unitId];
$_SESSION['active_unit_id'] = $unitId;
unit_set_context($koneksi, $unitId);

function psb_promotion_context(mysqli $db,string $nis,string $source):array {
    $q=$db->prepare("SELECT p.id source_placement_id,p.tahun_ajaran_id source_year_id FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND y.label=?");
    $q->bind_param('ss',$nis,$source);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();if(!$r)throw new RuntimeException('Missing PSB source fixture');return $r;
}
function psb_cycle_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function psb_cycle_request(string $base, string $path, ?array $post, array &$cookies, string $academicYear): array {
    $headers = ['X-SPP-Test-Current-Year: ' . $academicYear];
    if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $key => $value) $pairs[] = $key . '=' . $value;
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
    $body = file_get_contents($base . $path, false, $context);
    psb_cycle_assert($body !== false, 'HTTP request failed: ' . $path);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function psb_cycle_token(string $html, string $formId, string $name = 'csrf_token'): string {
    psb_cycle_assert(preg_match('/<form\b[^>]*\bid="' . preg_quote($formId, '/') . '"[^>]*>(.*?)<\/form>/s',
        $html, $form) === 1, $formId . ' form missing.');
    psb_cycle_assert(preg_match('/name="' . preg_quote($name, '/') . '" value="([a-f0-9]+)"/',
        $form[1], $match) === 1, $name . ' missing from ' . $formId . '.');
    return $match[1];
}

function psb_cycle_flash(string $html): string {
    if (!preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $html, $match)) return '';
    return trim(html_entity_decode(strip_tags($match[1])));
}

function psb_cycle_payment_feedback(string $html): string {
    $flash = psb_cycle_flash($html);
    if ($flash !== '') return $flash;
    if (preg_match('/window\.sppFlashWarning\s*=\s*([^\n;]+);/', $html, $match)) {
        $warning = json_decode($match[1], true);
        if (is_array($warning)) return (string)($warning['message'] ?? '');
    }
    return '';
}

function psb_cycle_class(mysqli $db, int $unitId, int $level, string $code): int {
    $stmt = $db->prepare('SELECT id FROM master_kelas WHERE unit_id=? AND tingkat=? AND kode_rombel=? AND is_active=1 AND is_placeholder=0 LIMIT 1');
    $stmt->bind_param('iis', $unitId, $level, $code);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    psb_cycle_assert($id > 0, "Class {$level}{$code} is missing.");
    return $id;
}

function psb_cycle_student(mysqli $db, string $nis): ?array {
    $stmt = $db->prepare('SELECT * FROM siswa WHERE NO_INDUK=? LIMIT 1');
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function psb_cycle_placements(mysqli $db, string $nis): array {
    $stmt = $db->prepare('SELECT sta.*, ta.label FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk=? ORDER BY ta.label');
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function psb_cycle_bills(mysqli $db, string $table, string $nis, ?int $placementId = null): array {
    psb_cycle_assert(in_array($table, ['tagihan_spp', 'tagihan_komite', 'tagihan_daftar_ulang'], true), 'Unknown bill table.');
    $sql = "SELECT * FROM {$table} WHERE no_induk=?" . ($placementId !== null ? ' AND penempatan_id=?' : '') . ' ORDER BY id';
    $stmt = $db->prepare($sql);
    if ($placementId !== null) $stmt->bind_param('si', $nis, $placementId);
    else $stmt->bind_param('s', $nis);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function psb_cycle_save_rates(string $base, array &$cookies, string $year, int $rate): void {
    $page = psb_cycle_request($base, '/master_spp.php?tahun=' . rawurlencode($year), null, $cookies, $year);
    psb_cycle_assert($page['status'] === 200, 'SPP master page unavailable for ' . $year);
    $post = psb_cycle_request($base, '/master_spp.php', [
        'aksi' => 'simpan_tarif', 'csrf_token' => psb_cycle_token($page['body'], 'spp-rate-form'),
        'tahun_ajaran' => $year, 'jumlah' => array_fill_keys(range(...unit_level_bounds()), $rate),
    ], $cookies, $year);
    psb_cycle_assert($post['status'] === 302, 'SPP rate save failed for ' . $year);
    $check = psb_cycle_request($base, '/master_spp.php?tahun=' . rawurlencode($year), null, $cookies, $year);
    psb_cycle_assert(str_contains(psb_cycle_flash($check['body']), 'Tarif tersimpan'), 'SPP rate save rejected for ' . $year . ': ' . psb_cycle_flash($check['body']));
}

function psb_cycle_publish(string $base, array &$cookies, string $year, string $nis, bool $priorDebt): void {
    $page = psb_cycle_request($base, '/master_spp.php?tahun=' . rawurlencode($year), null, $cookies, $year);
    psb_cycle_assert($page['status'] === 200, 'SPP publication page unavailable for ' . $year);
    $fields = [
        'aksi' => 'terbitkan', 'csrf_token' => psb_cycle_token($page['body'], 'spp-publish-form'),
        'tahun_ajaran' => $year, 'selected_students' => [$nis], 'start_month' => [$nis => '07'],
    ];
    if ($priorDebt) {
        $unconfirmed = psb_cycle_request($base, '/master_spp.php', $fields, $cookies, $year);
        psb_cycle_assert($unconfirmed['status'] === 302, 'Unconfirmed prior-debt publication returned unexpected HTTP status.');
        $page = psb_cycle_request($base, '/master_spp.php?tahun=' . rawurlencode($year), null, $cookies, $year);
        psb_cycle_assert(str_contains(psb_cycle_flash($page['body']), 'Konfirmasi diperlukan'),
            'Earlier debt did not require publication confirmation for ' . $year . '.');
        $fields['csrf_token'] = psb_cycle_token($page['body'], 'spp-publish-form');
        $fields['confirm_previous_debt'] = '1';
    }
    $post = psb_cycle_request($base, '/master_spp.php', $fields, $cookies, $year);
    psb_cycle_assert($post['status'] === 302, 'SPP publication failed for ' . $year);
    $check = psb_cycle_request($base, '/master_spp.php?tahun=' . rawurlencode($year), null, $cookies, $year);
    psb_cycle_assert(str_contains(psb_cycle_flash($check['body']), '12 tagihan SPP baru'),
        'SPP publication did not create 12 bills for ' . $year . ': ' . psb_cycle_flash($check['body']));
}

function psb_cycle_pay(string $base, array &$cookies, string $academicYear, string $nis, string $month, string $calendarYear, array $amounts): string {
    $form = psb_cycle_request($base, '/pembayaran/form.php', null, $cookies, $academicYear);
    psb_cycle_assert($form['status'] === 200, 'Payment form unavailable.');
    $post = psb_cycle_request($base, '/pembayaran/proses.php', [
        'aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
        'csrf_token' => psb_cycle_token($form['body'], 'form-bayar'), 'request_key' => psb_cycle_token($form['body'], 'form-bayar', 'request_key'),
        'bulan_bayar' => $month, 'tahun_bayar' => $calendarYear,
        'sistem_pembayaran' => 'Tunai', 'spp_action' => 'bayar',
    ] + $amounts, $cookies, $academicYear);
    psb_cycle_assert($post['status'] === 302, 'Payment POST returned unexpected HTTP status.');
    $next = psb_cycle_request($base, '/pembayaran/form.php', null, $cookies, $academicYear);
    return psb_cycle_payment_feedback($next['body']);
}

$base = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$parts = parse_url($base);
psb_cycle_assert(is_array($parts) && ($parts['scheme'] ?? '') === 'http'
    && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true),
    'SPP_TEST_BASE_URL must be a local HTTP server.');
$passwordFile = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE');
psb_cycle_assert($passwordFile !== '' && is_file($passwordFile), 'Clone admin password file missing.');
$password = trim((string)file_get_contents($passwordFile));
psb_cycle_assert($password !== '', 'Clone admin password is empty.');
$nis = (string)random_int(9800000000, 9899999999);
$cookies = [];

try {
    $identity = psb_cycle_request($base, '/tests/browser_clone_identity.php', null, $cookies, '2026/2027');
    $target = json_decode($identity['body'], true);
    psb_cycle_assert($identity['status'] === 200 && is_array($target) && ($target['database'] ?? '') === DB_NAME,
        'HTTP server does not point to the named clone.');
    psb_cycle_assert(psb_cycle_student($koneksi, $nis) === null, 'Random NIS already exists.');

    $account = $koneksi->prepare("SELECT id FROM admin WHERE username=? AND role='admin' AND unit_id=? AND is_active=1 LIMIT 1");
    $account->bind_param('si', $username, $unitId); $account->execute();
    $accountId = (int)($account->get_result()->fetch_assoc()['id'] ?? 0); $account->close();
    psb_cycle_assert($accountId > 0, 'Unit admin is unavailable.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $setPassword = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $setPassword->bind_param('si', $hash, $accountId); $setPassword->execute(); $setPassword->close();
    $login = psb_cycle_request($base, '/login.php', ['username' => $username, 'password' => $password], $cookies, '2026/2027');
    psb_cycle_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Clone admin login failed.');

    $psbClass = psb_cycle_class($koneksi, $unitId, 0, 'PSB');
    $gradeOneClass = psb_cycle_class($koneksi, $unitId, $firstLevel, 'A');
    $gradeTwoClass = psb_cycle_class($koneksi, $unitId, $firstLevel + 1, 'A');
    foreach (range($firstLevel, $lastLevel) as $level) psb_cycle_class($koneksi, $unitId, $level, 'A');
    $studentPage = psb_cycle_request($base, '/siswa/daftar.php', null, $cookies, '2026/2027');
    psb_cycle_assert($studentPage['status'] === 200, 'Student registration page unavailable.');
    $studentPost = [
        'aksi' => 'tambah', 'csrf_token' => psb_cycle_token($studentPage['body'], 'form-master-siswa'),
        'no_induk' => $nis, 'nama' => 'UJI SIKLUS PSB', 'master_kelas_id' => $psbClass,
        'advanced_enabled' => '1', 'potongan_spp_nominal' => 0,
        'psb' => 3600000, 'pomg' => 100000, 'daftar_ulang' => 0,
        'potong_du' => 0,
    ];
    $registered = psb_cycle_request($base, '/siswa/daftar.php', $studentPost, $cookies, '2026/2027');
    psb_cycle_assert($registered['status'] === 302, 'PSB registration POST failed.');
    $student = psb_cycle_student($koneksi, $nis);
    psb_cycle_assert($student && $student['KELAS'] === '0' && (int)$student['asal_psb'] === 1
        && (int)$student['is_active'] === 1 && (float)$student['PSB'] === 3600000.0,
        'PSB origin/fee/student status incorrect: ' . psb_cycle_flash(psb_cycle_request($base, '/siswa/daftar.php', null, $cookies, '2026/2027')['body']));
    psb_cycle_assert(psb_cycle_placements($koneksi, $nis) === []
        && psb_cycle_bills($koneksi, 'tagihan_spp', $nis) === []
        && psb_cycle_bills($koneksi, 'tagihan_komite', $nis) === [],
        'PSB registration created a regular placement or monthly bills.');

    $firstPayment = psb_cycle_pay($base, $cookies, '2026/2027', $nis, '07', '2026', ['uang_psb' => 1000000]);
    psb_cycle_assert(str_contains($firstPayment, 'berhasil'), 'PSB first installment failed: ' . $firstPayment);
    $paidBefore = $koneksi->query("SELECT COUNT(*) n, COALESCE(SUM(U_PSB),0) paid FROM bayar WHERE NO_INDUK='{$nis}'")->fetch_assoc();
    psb_cycle_assert((int)$paidBefore['n'] === 1 && (float)$paidBefore['paid'] === 1000000.0,
        'PSB installment is missing or duplicated.');

    // PSB has no annual placement. The first regular level begins in the next known year.
    psb_cycle_save_rates($base, $cookies, '2027/2028', 250000);
    $studentEdit = psb_cycle_request($base, '/siswa/daftar.php?edit=' . (int)$student['id'], null, $cookies, '2027/2028');
    psb_cycle_assert($studentEdit['status'] === 200, 'PSB edit page unavailable.');
    $studentPost['aksi'] = 'update';
    $studentPost['id'] = (int)$student['id'];
    $studentPost['csrf_token'] = psb_cycle_token($studentEdit['body'], 'form-master-siswa');
    $studentPost['master_kelas_id'] = $gradeOneClass;
    $studentPost['komite_mulai_bulan'] = '07';
    $transition = psb_cycle_request($base, '/siswa/daftar.php', $studentPost, $cookies, '2027/2028');
    psb_cycle_assert($transition['status'] === 302, 'PSB to 1A update POST failed.');
    $student = psb_cycle_student($koneksi, $nis);
    $placements = psb_cycle_placements($koneksi, $nis);
    psb_cycle_assert($student && $student['KELAS'] === (string)$firstLevel && (int)$student['asal_psb'] === 1
        && (float)$student['SPP_PERBULAN'] === 250000.0 && count($placements) === 1,
        'PSB transition changed origin incorrectly or failed to create one placement.');
    $first = $placements[0];
    psb_cycle_assert($first['label'] === '2027/2028' && $first['kelas_rombel_snapshot'] === $firstLevel . 'A'
        && $first['status'] === 'aktif' && (int)$first['spp_covered_by_psb'] === (int)$coveredFirstYear
        && (float)$first['spp_perbulan_snapshot'] === ($coveredFirstYear ? 0.0 : 250000.0),
        'First regular year does not follow the unit PSB coverage rule.');
    psb_cycle_assert(count(psb_cycle_bills($koneksi, 'tagihan_komite', $nis, (int)$first['id'])) === 12
        && psb_cycle_bills($koneksi, 'tagihan_spp', $nis) === [],
        'Grade 1 Komite was not issued or SPP was auto-issued.');
    psb_cycle_assert((int)$koneksi->query("SELECT COUNT(*) n FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id WHERE sta.no_induk='{$nis}' AND ta.label='2026/2027'")->fetch_assoc()['n'] === 0,
        'The PSB year was reconstructed as a regular placement.');

    psb_cycle_publish($base, $cookies, '2027/2028', $nis, true);
    $coveredBills = psb_cycle_bills($koneksi, 'tagihan_spp', $nis, (int)$first['id']);
    psb_cycle_assert(count($coveredBills) === 12, 'First grade did not get 12 PSB-covered SPP periods.');
    foreach ($coveredBills as $bill) {
        psb_cycle_assert($bill['status'] === ($coveredFirstYear ? 'covered_psb' : 'open')
            && (float)$bill['nominal_tagihan'] === ($coveredFirstYear ? 0.0 : 250000.0)
            && (int)$bill['penempatan_id'] === (int)$first['id'],
            'First grade SPP is not covered or bill linkage is wrong.');
    }
    $firstYearReport = report_build($koneksi, 'spp-tahunan', report_filters($koneksi, [
        'q' => $nis, 'tahun_ajaran' => '2027/2028', 'siswa_status' => 'active',
    ]))['rows'];
    psb_cycle_assert(count($firstYearReport) === 1 && $firstYearReport[0]['kelas'] === $firstLevel . 'A'
        && (float)$firstYearReport[0]['total_tagihan'] === ($coveredFirstYear ? 0.0 : 3000000.0)
        && $firstYearReport[0]['m07_2027']['text'] === ($coveredFirstYear ? 'Tercakup Uang PSB' : 'Belum Bayar'),
        'Grade 1 historical report does not show PSB coverage.');

    $blocked = psb_cycle_pay($base, $cookies, '2027/2028', $nis, '07', '2027', ['uang_spp' => 250000]);
    psb_cycle_assert($blocked !== '' && !str_contains($blocked, 'berhasil'),
        'Cashier accepted an SPP charge covered by PSB: ' . $blocked);
    $balancePayment = psb_cycle_pay($base, $cookies, '2027/2028', $nis, '07', '2027', [
        'uang_psb' => 2600000, 'uang_komite' => 100000, 'uang_spp' => $coveredFirstYear ? 0 : 250000,
    ]);
    psb_cycle_assert(str_contains($balancePayment, 'berhasil'), 'PSB balance + grade 1 Komite payment failed: ' . $balancePayment);
    $paid = $koneksi->query("SELECT COUNT(*) n, COALESCE(SUM(U_PSB),0) psb, COALESCE(SUM(U_SPP),0) spp, COALESCE(SUM(U_KOMITE),0) komite FROM bayar WHERE NO_INDUK='{$nis}'")->fetch_assoc();
    psb_cycle_assert((int)$paid['n'] === 2 && (float)$paid['psb'] === 3600000.0
        && (float)$paid['spp'] === ($coveredFirstYear ? 0.0 : 250000.0) && (float)$paid['komite'] === 100000.0,
        'PSB/Komite payments or blocked SPP changed financial totals incorrectly.');
    if (!$coveredFirstYear) {
        foreach (array_slice(spp_academic_periods('2027/2028'), 1) as $period) {
            $feedback = psb_cycle_pay($base, $cookies, '2027/2028', $nis, $period['bulan'], $period['tahun'], [
                'uang_spp' => 250000, 'uang_komite' => 100000,
            ]);
            psb_cycle_assert(str_contains($feedback, 'berhasil'), 'First-level settlement failed: ' . $feedback);
        }
    }

    $sourceCtx=psb_promotion_context($koneksi,$nis,'2027/2028');
    $promotionPage = psb_cycle_request($base, '/master_kelas.php?'.http_build_query(['source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$firstLevel]), null, $cookies, '2027/2028');
    psb_cycle_assert($promotionPage['status'] === 200, 'Promotion page unavailable.');
    $promotion = psb_cycle_request($base, '/master_kelas.php', [
        'source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$firstLevel,'source_placement_id'=>$sourceCtx['source_placement_id'],
        'aksi' => 'naikkan_siswa', 'csrf_token' => psb_cycle_token($promotionPage['body'], 'promotion-batch-form'),
        'no_induk' => $nis, 'target_tahun_ajaran' => '2028/2029',
        'target_master_kelas_id' => $gradeTwoClass,
    ], $cookies, '2027/2028');
    psb_cycle_assert($promotion['status'] === 302, 'Promotion POST failed.');
    $student = psb_cycle_student($koneksi, $nis);
    $placements = psb_cycle_placements($koneksi, $nis);
    psb_cycle_assert($student && $student['KELAS'] === (string)($firstLevel + 1) && (int)$student['asal_psb'] === 1
        && count($placements) === 2 && $placements[0]['status'] === 'pindah'
        && $placements[1]['label'] === '2028/2029' && $placements[1]['kelas_rombel_snapshot'] === ($firstLevel + 1) . 'A'
        && $placements[1]['status'] === 'aktif' && (int)$placements[1]['spp_covered_by_psb'] === 0,
        'Promotion did not preserve grade 1 history or release PSB SPP coverage in grade 2.');

    psb_cycle_save_rates($base, $cookies, '2028/2029', 280000);
    $student = psb_cycle_student($koneksi, $nis);
    $placements = psb_cycle_placements($koneksi, $nis);
    psb_cycle_assert((float)$student['SPP_PERBULAN'] === 280000.0
        && (float)$placements[1]['spp_perbulan_snapshot'] === 280000.0,
        'Saving the destination-year master did not synchronize an unpaid active placement.');
    psb_cycle_publish($base, $cookies, '2028/2029', $nis, $coveredFirstYear);
    $gradeTwoBills = psb_cycle_bills($koneksi, 'tagihan_spp', $nis, (int)$placements[1]['id']);
    psb_cycle_assert(count($gradeTwoBills) === 12, 'Grade 2 did not get 12 SPP bills.');
    foreach ($gradeTwoBills as $bill) {
        psb_cycle_assert($bill['status'] === 'open' && (float)$bill['nominal_tagihan'] === 280000.0,
            'Grade 2 SPP did not use its own-year tariff.');
    }
    $student = psb_cycle_student($koneksi, $nis);
    $placements = psb_cycle_placements($koneksi, $nis);
    psb_cycle_assert((float)$student['SPP_PERBULAN'] === 280000.0
        && (float)$placements[1]['spp_perbulan_snapshot'] === 280000.0,
        'Active/placement SPP tariff differs from the grade 2 published bills.');
    $gradeTwoPayment = psb_cycle_pay($base, $cookies, '2028/2029', $nis, '07', '2028', [
        'uang_spp' => 280000, 'uang_komite' => 100000,
    ]);
    psb_cycle_assert(str_contains($gradeTwoPayment, 'berhasil'), 'Grade 2 SPP/Komite payment failed: ' . $gradeTwoPayment);
    $gradeTwoReport = report_build($koneksi, 'spp-tahunan', report_filters($koneksi, [
        'q' => $nis, 'tahun_ajaran' => '2028/2029', 'siswa_status' => 'active',
    ]))['rows'];
    $oldYearReport = report_build($koneksi, 'spp-tahunan', report_filters($koneksi, [
        'q' => $nis, 'tahun_ajaran' => '2027/2028', 'siswa_status' => 'active',
    ]))['rows'];
    psb_cycle_assert(count($gradeTwoReport) === 1 && $gradeTwoReport[0]['kelas'] === ($firstLevel + 1) . 'A'
        && (float)$gradeTwoReport[0]['total_tagihan'] === 3360000.0
        && (float)$gradeTwoReport[0]['total_bayar'] === 280000.0,
        'Grade 2 report does not match new-year bills/payment.');
    psb_cycle_assert(count($oldYearReport) === 1 && $oldYearReport[0]['kelas'] === $firstLevel . 'A'
        && (float)$oldYearReport[0]['total_tagihan'] === ($coveredFirstYear ? 0.0 : 3000000.0)
        && $oldYearReport[0]['m07_2027']['text'] === ($coveredFirstYear ? 'Tercakup Uang PSB' : 'Lunas'),
        'Promotion rewrote the PSB-covered grade 1 report.');

    // Clear the oldest year before paying a later one; each grade exercises the cashier path.
    foreach (array_slice(spp_academic_periods('2028/2029'), 1) as $period) {
        $feedback = psb_cycle_pay($base, $cookies, '2028/2029', $nis, $period['bulan'], $period['tahun'], [
            'uang_spp' => 280000, 'uang_komite' => 100000,
        ]);
        psb_cycle_assert(str_contains($feedback, 'berhasil'), 'Grade 2 monthly settlement failed: ' . $feedback);
    }
    for ($level = $firstLevel + 1; $level < $lastLevel; $level++) {
        $sourceStart = 2027 + ($level - $firstLevel);
        $sourceYear = $sourceStart . '/' . ($sourceStart + 1);
        $targetYear = ($sourceStart + 1) . '/' . ($sourceStart + 2);
        $sourceCtx=psb_promotion_context($koneksi,$nis,$sourceYear);
        $classPage = psb_cycle_request($base, '/master_kelas.php?'.http_build_query(['source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$level]), null, $cookies, $sourceYear);
        psb_cycle_assert($classPage['status'] === 200, 'Promotion page unavailable in ' . $sourceYear);
        $promote = psb_cycle_request($base, '/master_kelas.php', [
            'source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$level,'source_placement_id'=>$sourceCtx['source_placement_id'],
            'aksi' => 'naikkan_siswa', 'csrf_token' => psb_cycle_token($classPage['body'], 'promotion-batch-form'),
            'no_induk' => $nis, 'target_tahun_ajaran' => $targetYear,
            'target_master_kelas_id' => psb_cycle_class($koneksi, $unitId, $level + 1, 'A'),
        ], $cookies, $sourceYear);
        psb_cycle_assert($promote['status'] === 302, 'Promotion POST failed for ' . $sourceYear);
        $placements = psb_cycle_placements($koneksi, $nis);
        $student = psb_cycle_student($koneksi, $nis);
        psb_cycle_assert($student && $student['KELAS'] === (string)($level + 1)
            && count($placements) === $level - $firstLevel + 2
            && $placements[$level - $firstLevel + 1]['label'] === $targetYear
            && $placements[$level - $firstLevel + 1]['kelas_rombel_snapshot'] === ($level + 1) . 'A'
            && (int)$placements[$level - $firstLevel + 1]['spp_covered_by_psb'] === 0,
            'Promotion from grade ' . $level . ' did not create exactly one correct placement.');

        $targetRate = 280000 + ($level - $firstLevel) * 10000;
        psb_cycle_save_rates($base, $cookies, $targetYear, $targetRate);
        psb_cycle_publish($base, $cookies, $targetYear, $nis, $coveredFirstYear);
        $bills = psb_cycle_bills($koneksi, 'tagihan_spp', $nis, (int)$placements[$level - $firstLevel + 1]['id']);
        psb_cycle_assert(count($bills) === 12 && (float)$bills[0]['nominal_tagihan'] === (float)$targetRate,
            'Published SPP for ' . $targetYear . ' has the wrong rate or period count.');
        foreach (spp_academic_periods($targetYear) as $period) {
            $feedback = psb_cycle_pay($base, $cookies, $targetYear, $nis, $period['bulan'], $period['tahun'], [
                'uang_spp' => $targetRate, 'uang_komite' => 100000,
            ]);
            psb_cycle_assert(str_contains($feedback, 'berhasil'),
                'Monthly settlement failed for ' . $targetYear . ' ' . $period['label'] . ': ' . $feedback);
        }
        $report = report_build($koneksi, 'spp-tahunan', report_filters($koneksi, [
            'q' => $nis, 'tahun_ajaran' => $targetYear, 'siswa_status' => 'active',
        ]))['rows'];
        psb_cycle_assert(count($report) === 1 && $report[0]['kelas'] === ($level + 1) . 'A'
            && (float)$report[0]['total_tagihan'] === 12.0 * $targetRate
            && (float)$report[0]['total_bayar'] === 12.0 * $targetRate,
            'Yearly SPP report did not match 12 settled months in ' . $targetYear . '.');
    }

    $lastStart = 2027 + ($lastLevel - $firstLevel);
    $graduationYear = $lastStart . '/' . ($lastStart + 1);
    $sourceCtx=psb_promotion_context($koneksi,$nis,$graduationYear);
    $classPage = psb_cycle_request($base, '/master_kelas.php?'.http_build_query(['source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$lastLevel]), null, $cookies, $graduationYear);
    psb_cycle_assert($classPage['status'] === 200, 'Graduation page unavailable.');
    $graduate = psb_cycle_request($base, '/master_kelas.php', [
        'source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$lastLevel,'source_placement_id'=>$sourceCtx['source_placement_id'],
        'aksi' => 'luluskan_siswa', 'csrf_token' => psb_cycle_token($classPage['body'], 'promotion-batch-form'),
        'no_induk' => $nis, 'target_tahun_ajaran' => ($lastStart + 1) . '/' . ($lastStart + 2),
    ], $cookies, $graduationYear);
    psb_cycle_assert($graduate['status'] === 302, 'Graduation POST failed.');
    $student = psb_cycle_student($koneksi, $nis);
    $placements = psb_cycle_placements($koneksi, $nis);
    psb_cycle_assert($student && (int)$student['is_active'] === 0 && $student['KELAS'] === (string)$lastLevel
        && count($placements) === $lastLevel - $firstLevel + 1
        && $placements[$lastLevel - $firstLevel]['status'] === 'lulus',
        'Graduation did not preserve unit placements and PSB origin.');
    $archived = report_build($koneksi, 'spp-tahunan', report_filters($koneksi, [
        'q' => $nis, 'tahun_ajaran' => '2027/2028', 'siswa_status' => 'archived',
    ]))['rows'];
    psb_cycle_assert(count($archived) === 1 && $archived[0]['kelas'] === $firstLevel . 'A'
        && $archived[0]['m07_2027']['text'] === ($coveredFirstYear ? 'Tercakup Uang PSB' : 'Lunas'),
        'Graduation rewrote the first regular year or lost archived history.');
    echo 'OK: unit ' . unit_label($unitId) . ' HTTP PSB registration/payment, regular transition, '
        . ($lastLevel - $firstLevel) . " promotions, monthly settlements, graduation, and historical reports.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
