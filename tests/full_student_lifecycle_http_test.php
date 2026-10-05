<?php

/** End-to-end HTTP school-year simulation; run only on a disposable db_spp_audit_* clone. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: use CLI, SPP_TEST_ALLOW_MUTATION=1 and a db_spp_audit_* clone.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';

$unitId = (int)(getenv('SPP_TEST_UNIT_ID') ?: 1);
if (!in_array($unitId, [1, 2, 3], true)) throw new RuntimeException('SPP_TEST_UNIT_ID must be 1, 2, or 3.');
[$firstLevel, $lastLevel] = unit_level_bounds($unitId);
$username = [1 => 'admin', 2 => 'admin.smp', 3 => 'admin.sma'][$unitId];
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($password === '' && ($file = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') $password = trim(file_get_contents($file));
if ($password === '') throw new RuntimeException('SPP_TEST_ADMIN_PASSWORD is required.');
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8098'), '/');

function lifecycle_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function lifecycle_request(string $url, ?array $data, array &$cookies, string $academicYear = ''): array {
    $headers = [];
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($academicYear !== '') $headers[] = 'X-SPP-Test-Current-Year: ' . $academicYear;
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $key => $value) $pairs[] = $key . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    lifecycle_assert($body !== false, 'HTTP request failed: ' . $url);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function lifecycle_token(string $html, string $formId): string {
    lifecycle_assert(preg_match('/<form\b[^>]*\bid="' . preg_quote($formId, '/') . '"[^>]*>(.*?)<\/form>/s',
        $html, $form) === 1, 'Expected form missing: ' . $formId);
    lifecycle_assert(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form[1], $match) === 1,
        'CSRF token missing in ' . $formId . '.');
    return $match[1];
}

function lifecycle_flash(string $html): string {
    if(preg_match('/window\.sppFlashWarning = (.*?);/s',$html,$status)){$data=json_decode($status[1],true);if(is_array($data))return ($data['code']??'').': '.($data['message']??'');}
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $html, $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    return '';
}

function lifecycle_class(mysqli $db, int $unitId, int $level): int {
    $stmt = $db->prepare("SELECT id FROM master_kelas WHERE unit_id=? AND tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
    $stmt->bind_param('ii', $unitId, $level); $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    lifecycle_assert($id > 0, 'Class ' . $level . 'A missing.');
    return $id;
}

function lifecycle_report(mysqli $db, string $template, string $nis, string $year, string $status): array {
    return report_build($db, $template, report_filters($db, [
        'q' => $nis, 'tahun_ajaran' => $year, 'siswa_status' => $status,
        'kategori' => 'spp', 'bulan_awal' => '07',
    ]))['rows'];
}

function lifecycle_promotion_context(mysqli $db,string $nis,string $source):array {
    $q=$db->prepare("SELECT p.id source_placement_id,p.tahun_ajaran_id source_year_id FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.no_induk=? AND y.label=?");
    $q->bind_param('ss',$nis,$source);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();lifecycle_assert((bool)$r,'Source fixture missing');return $r;
}

$cookies = [];
$nis = (string)random_int(9900000000, 9999999999);
$firstYear = '2030/2031';
try {
    $_SESSION['active_unit_id'] = $unitId;
    unit_set_context($koneksi, $unitId);
    $identity = lifecycle_request($base . '/tests/browser_clone_identity.php', null, $cookies, $firstYear);
    $target = json_decode($identity['body'], true);
    lifecycle_assert($identity['status'] === 200 && is_array($target) && ($target['database'] ?? '') === DB_NAME,
        'HTTP server does not point to the named clone.');
    $account = $koneksi->prepare("SELECT id FROM admin WHERE username=? AND role='admin' AND unit_id=? AND is_active=1 LIMIT 1");
    $account->bind_param('si', $username, $unitId); $account->execute();
    $accountId = (int)($account->get_result()->fetch_assoc()['id'] ?? 0); $account->close();
    lifecycle_assert($accountId > 0, 'Unit admin is unavailable.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $setPassword = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $setPassword->bind_param('si', $hash, $accountId); $setPassword->execute(); $setPassword->close();
    $login = lifecycle_request($base . '/login.php', ['username' => $username, 'password' => $password], $cookies);
    lifecycle_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Admin HTTP login failed.');
    foreach (range($firstLevel, $lastLevel) as $level) lifecycle_class($koneksi, $unitId, $level);

    $masterPage = lifecycle_request($base . '/master_spp.php?tahun=' . rawurlencode($firstYear), null, $cookies, $firstYear);
    lifecycle_assert($masterPage['status'] === 200, 'First SPP master page failed.');
    $rates = array_fill_keys(range($firstLevel, $lastLevel), 250000);
    $save = lifecycle_request($base . '/master_spp.php', [
        'aksi' => 'simpan_tarif', 'csrf_token' => lifecycle_token($masterPage['body'], 'spp-rate-form'),
        'tahun_ajaran' => $firstYear, 'jumlah' => $rates,
    ], $cookies, $firstYear);
    lifecycle_assert($save['status'] === 302, 'First SPP rates request failed.');

    $studentPage = lifecycle_request($base . '/siswa/daftar.php', null, $cookies, $firstYear);
    lifecycle_assert($studentPage['status'] === 200, 'Student registration page failed.');
    if(getenv('SPP_TEST_LEGACY_START')==='1'){
        $legacy=$koneksi->query("SELECT s.id,s.NO_INDUK FROM siswa s JOIN legacy_student_import m ON m.student_id=s.id AND m.unit_id=s.unit_id WHERE s.legacy_pending=1 AND m.source_name LIKE '%-5.dat' ORDER BY s.id LIMIT 1")->fetch_assoc();
        lifecycle_assert((bool)$legacy,'Imported Legacy candidate unavailable');$nis=$legacy['NO_INDUK'];$legacyId=(int)$legacy['id'];
        lifecycle_request($base.'/siswa/daftar.php',['aksi'=>'toggle_status','id'=>$legacyId,'target_active'=>'1','csrf_token'=>lifecycle_token($studentPage['body'],'form-master-siswa')],$cookies,$firstYear);
        lifecycle_assert((int)$koneksi->query("SELECT legacy_pending FROM siswa WHERE id=$legacyId")->fetch_row()[0]===1,'Normal restore bypassed Legacy activation');
        $pendingPayment=lifecycle_request($base.'/pembayaran/form.php',null,$cookies,$firstYear);preg_match('/name="request_key" value="([a-f0-9]{32})"/',$pendingPayment['body'],$pk);
        lifecycle_request($base.'/pembayaran/proses.php',['aksi'=>'input','payment_plan'=>'monthly','no_induk'=>$nis,'uang_psb'=>1000,'bulan_bayar'=>'07','tahun_bayar'=>'2030','sistem_pembayaran'=>'Tunai','csrf_token'=>lifecycle_token($pendingPayment['body'],'form-bayar'),'request_key'=>$pk[1]],$cookies,$firstYear);
        lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='".$koneksi->real_escape_string($nis)."'")->fetch_row()[0]===0,'Legacy payment accepted');
        $activation=lifecycle_request($base.'/siswa/aktivasi_legacy.php?id='.$legacyId,null,$cookies,$firstYear);
        lifecycle_assert($activation['status']===200,'Legacy activation page failed');
        $yearId=(int)$koneksi->query("SELECT id FROM tahun_ajaran WHERE label='$firstYear'")->fetch_row()[0];
        $activate=lifecycle_request($base.'/siswa/aktivasi_legacy.php',['id'=>$legacyId,'class_id'=>lifecycle_class($koneksi,$unitId,$firstLevel),'year_id'=>$yearId,'spp'=>250000,'psb'=>0,'komite'=>0,'du'=>0,'confirmed'=>'1','csrf_token'=>lifecycle_token($activation['body'],'legacy-activation')],$cookies,$firstYear);
        lifecycle_assert($activate['status']===302,'Legacy manual activation failed');
        lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM tagihan_spp WHERE no_induk='".$koneksi->real_escape_string($nis)."'")->fetch_row()[0]===0,'Activation issued bills automatically');
    }else{
    $register = lifecycle_request($base . '/siswa/daftar.php', [
        'aksi' => 'tambah', 'csrf_token' => lifecycle_token($studentPage['body'], 'form-master-siswa'),
        'no_induk' => $nis, 'nama' => 'UJI SIKLUS LENGKAP',
        'master_kelas_id' => lifecycle_class($koneksi, $unitId, $firstLevel),
        'advanced_enabled' => '1', 'spp_perbulan' => 250000,
        'psb' => 0, 'pomg' => 0, 'daftar_ulang' => 0,
        'potong_du' => 0,
    ], $cookies, $firstYear);
    lifecycle_assert($register['status'] === 302, 'Student registration request failed.');
    }
    $registered = $koneksi->query("SELECT KELAS,is_active FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc();
    lifecycle_assert($registered && $registered['KELAS'] === (string)$firstLevel && (int)$registered['is_active'] === 1,
        'First-level student was not registered.');
    lifecycle_assert(count(lifecycle_report($koneksi, 'status', $nis, $firstYear, 'active')) === 1, 'First year placement/report missing.');

    $publish = lifecycle_request($base . '/master_spp.php', [
        'aksi' => 'terbitkan', 'csrf_token' => lifecycle_token($masterPage['body'], 'spp-publish-form'),
        'tahun_ajaran' => $firstYear, 'selected_students' => [$nis],
    ], $cookies, $firstYear);
    lifecycle_assert($publish['status'] === 302, 'First SPP publication request failed.');
    $billCount = (int)$koneksi->query("SELECT COUNT(*) n FROM tagihan_spp WHERE no_induk='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc()['n'];
    lifecycle_assert($billCount === 12, 'First SPP publication did not create 12 months.');
    $paymentForm = lifecycle_request($base . '/pembayaran/form.php', null, $cookies, $firstYear);
    lifecycle_assert($paymentForm['status'] === 200
        && preg_match('/name="request_key" value="([a-f0-9]{32})"/', $paymentForm['body'], $paymentKey) === 1,
        'Payment form request key is missing.');
    $payment = lifecycle_request($base . '/pembayaran/proses.php', [
        'aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
        'csrf_token' => lifecycle_token($paymentForm['body'], 'form-bayar'), 'request_key' => $paymentKey[1],
        'bulan_bayar' => '07', 'tahun_bayar' => '2030',
        'sistem_pembayaran' => 'Tunai', 'uang_spp' => 250000, 'uang_komite' => 0,
        'spp_action' => 'bayar',
    ], $cookies, $firstYear);
    lifecycle_assert($payment['status'] === 302, 'First SPP payment request failed.');
    $paid = (int)$koneksi->query("SELECT COUNT(*) n FROM bayar WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "' AND U_SPP=250000")->fetch_assoc()['n'];
    if ($paid !== 1) {
        $paymentPage = lifecycle_request($base . '/pembayaran/form.php', null, $cookies, $firstYear);
        throw new RuntimeException('First SPP payment was not recorded: ' . lifecycle_flash($paymentPage['body']));
    }
    $yearRows = lifecycle_report($koneksi, 'spp-tahunan', $nis, $firstYear, 'active');
    lifecycle_assert(count($yearRows) === 1 && $yearRows[0]['kelas'] === $firstLevel . 'A'
        && (float)$yearRows[0]['total_bayar'] === 250000.0, 'First year report is incorrect after payment.');

    for ($level = $firstLevel; $level < $lastLevel; $level++) {
        $sourceStart = 2030 + ($level - $firstLevel);
        $sourceYear = $sourceStart . '/' . ($sourceStart + 1);
        $targetYear = ($sourceStart + 1) . '/' . ($sourceStart + 2);
        if($level===$firstLevel){
            $earlyMaster=lifecycle_request($base.'/master_spp.php?tahun='.rawurlencode($targetYear),null,$cookies,$sourceYear);
            lifecycle_request($base.'/master_spp.php',[
                'aksi'=>'terbitkan','csrf_token'=>lifecycle_token($earlyMaster['body'],'spp-publish-form'),
                'tahun_ajaran'=>$targetYear,'selected_students'=>[$nis],'confirm_previous_debt'=>'1',
            ],$cookies,$sourceYear);
            $targetCount=(int)$koneksi->query("SELECT COUNT(*) FROM tagihan_spp ts JOIN tahun_ajaran ta ON ta.id=ts.tahun_ajaran_id WHERE ts.no_induk='$nis' AND ta.label='$targetYear'")->fetch_row()[0];
            lifecycle_assert($targetCount===0,'Target year published without official placement.');
        }
        $sourceCtx=lifecycle_promotion_context($koneksi,$nis,$sourceYear);
        $classPage = lifecycle_request($base . '/master_kelas.php?'.http_build_query(['source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$level]), null, $cookies, $sourceYear);
        lifecycle_assert($classPage['status'] === 200, 'Promotion page failed for class ' . $level . '.');
        $promote = lifecycle_request($base . '/master_kelas.php', [
            'source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$level,'source_placement_id'=>$sourceCtx['source_placement_id'],
            'aksi' => 'naikkan_siswa', 'csrf_token' => lifecycle_token($classPage['body'], 'promotion-batch-form'),
            'no_induk' => $nis, 'target_tahun_ajaran' => $targetYear,
            'target_master_kelas_id' => lifecycle_class($koneksi, $unitId, $level + 1),
        ], $cookies, $sourceYear);
        lifecycle_assert($promote['status'] === 302, 'Promotion request failed for class ' . $level . '.');
        $current = $koneksi->query("SELECT KELAS FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc();
        lifecycle_assert($current && (int)$current['KELAS'] === $level + 1, 'Promotion did not reach class ' . ($level + 1) . '.');
        $oldRows = lifecycle_report($koneksi, 'spp-tahunan', $nis, $sourceYear, 'active');
        lifecycle_assert(count($oldRows) === 1 && $oldRows[0]['kelas'] === $level . 'A', 'Source year report lost historical class ' . $level . 'A.');

        $masterPage = lifecycle_request($base . '/master_spp.php?tahun=' . rawurlencode($targetYear), null, $cookies, $targetYear);
        lifecycle_assert($masterPage['status'] === 200, 'SPP master page failed for ' . $targetYear . '.');
        $rate = 250000 + (($level - $firstLevel + 1) * 10000);
        $save = lifecycle_request($base . '/master_spp.php', [
            'aksi' => 'simpan_tarif', 'csrf_token' => lifecycle_token($masterPage['body'], 'spp-rate-form'),
            'tahun_ajaran' => $targetYear, 'jumlah' => array_fill_keys(range($firstLevel, $lastLevel), $rate),
        ], $cookies, $targetYear);
        lifecycle_assert($save['status'] === 302, 'SPP rates failed for ' . $targetYear . '.');
        $publish = lifecycle_request($base . '/master_spp.php', [
            'aksi' => 'terbitkan', 'csrf_token' => lifecycle_token($masterPage['body'], 'spp-publish-form'),
            'tahun_ajaran' => $targetYear, 'selected_students' => [$nis],
            'confirm_previous_debt' => '1',
        ], $cookies, $targetYear);
        lifecycle_assert($publish['status'] === 302, 'SPP publication failed for ' . $targetYear . '.');
        if($level===$firstLevel){
            $payMonth=static function(string $month,int $year,float $amount) use ($base,&$cookies,$nis,$sourceYear,$koneksi):void{
                $form=lifecycle_request($base.'/pembayaran/form.php',null,$cookies,$sourceYear);
                lifecycle_assert(preg_match('/name="request_key" value="([a-f0-9]{32})"/',$form['body'],$key)===1,'Missing cash request key.');
                lifecycle_request($base.'/pembayaran/proses.php',[
                    'aksi'=>'input','no_induk'=>$nis,'payment_plan'=>'monthly',
                    'csrf_token'=>lifecycle_token($form['body'],'form-bayar'),'request_key'=>$key[1],
                    'bulan_bayar'=>$month,'tahun_bayar'=>(string)$year,'uang_spp'=>$amount,'uang_komite'=>0,'sistem_pembayaran'=>'Tunai',
                ],$cookies,$sourceYear);
            };
            $before=(int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0];
            $payMonth('07',$sourceStart+1,(float)$rate);
            lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0]===$before,'Future year skipped source arrears.');
            foreach(spp_academic_periods($sourceYear) as $period){
                if($period['bulan']==='07')continue;
                $payMonth($period['bulan'],(int)$period['tahun'],250000.0);
            }
            $payMonth('07',$sourceStart+1,(float)$rate);
            $early=$koneksi->query("SELECT b.*,ta.label FROM bayar b JOIN spp_alokasi_batch ab ON ab.bayar_id=b.id AND ab.status='active' JOIN spp_alokasi a ON a.batch_id=ab.id JOIN tagihan_spp ts ON ts.id=a.tagihan_spp_id JOIN tahun_ajaran ta ON ta.id=ts.tahun_ajaran_id WHERE b.NO_INDUK='$nis' AND b.BULAN='07' AND b.TAHUN='".($sourceStart+1)."'")->fetch_assoc();
            lifecycle_assert($early && $early['kelas_rombel_snapshot']===($level+1).'A' && (float)$early['U_SPP']===(float)$rate && $early['label']===$targetYear && (int)substr($early['TGL_BYR'],0,4)<$sourceStart+1,'Early future payment has incorrect class, tariff, year, or reception date.');
            $receipt=lifecycle_request($base.'/laporan/cetak_struk.php?id='.$early['id'],null,$cookies,$sourceYear);
            lifecycle_assert($receipt['status']===200&&str_contains($receipt['body'],'Juli '.($sourceStart+1))&&!str_contains($receipt['body'],'Titipan SPP'),'Future receipt is incorrect.');
        }
        $targetRows = lifecycle_report($koneksi, 'spp-tahunan', $nis, $targetYear, 'active');
        lifecycle_assert(count($targetRows) === 1 && $targetRows[0]['kelas'] === ($level + 1) . 'A'
            && (float)$targetRows[0]['total_tagihan'] === (float)(12 * $rate),
            'Target year report is incorrect for ' . $targetYear . ': ' . json_encode($targetRows));
        $perItem = report_build($koneksi, 'per-item', report_filters($koneksi, [
            'q' => $nis, 'kategori' => 'spp', 'bulan_awal' => '06', 'tahun_awal' => $sourceStart + 1,
            'bulan_akhir' => '07', 'tahun_akhir' => $sourceStart + 1,
        ]))['rows'];
        lifecycle_assert(count($perItem) === 2 && $perItem[0]['tahun_ajaran'] === $sourceYear
            && $perItem[1]['tahun_ajaran'] === $targetYear, 'Cross-year Per Item rows failed.');
    }

    $lastStart = 2030 + ($lastLevel - $firstLevel);
    $lastYear = $lastStart . '/' . ($lastStart + 1);
    $graduationTargetYear = ($lastStart + 1) . '/' . ($lastStart + 2);
    $sourceCtx=lifecycle_promotion_context($koneksi,$nis,$lastYear);
    $classPage = lifecycle_request($base . '/master_kelas.php?'.http_build_query(['source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$lastLevel]), null, $cookies, $lastYear);
    $graduate = lifecycle_request($base . '/master_kelas.php', [
        'source_year_id'=>$sourceCtx['source_year_id'],'source_level'=>$lastLevel,'source_placement_id'=>$sourceCtx['source_placement_id'],
        'aksi' => 'luluskan_siswa', 'csrf_token' => lifecycle_token($classPage['body'], 'promotion-batch-form'),
        'no_induk' => $nis, 'target_tahun_ajaran' => $graduationTargetYear,
    ], $cookies, $lastYear);
    lifecycle_assert($graduate['status'] === 302, 'Graduation request failed.');
    $graduateState = $koneksi->query("SELECT KELAS,is_active FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc();
    lifecycle_assert($graduateState && (int)$graduateState['is_active'] === 0
        && $graduateState['KELAS'] === (string)$lastLevel, 'Student was not graduated.');
    lifecycle_assert(lifecycle_report($koneksi, 'status', $nis, $firstYear, 'active') === [], 'Graduate remains in active historical report.');
    lifecycle_assert(count(lifecycle_report($koneksi, 'status', $nis, $firstYear, 'archived')) === 1, 'Graduate missing from archived historical report.');
    $placementCount = (int)$koneksi->query("SELECT COUNT(*) n FROM siswa_tahun_ajaran WHERE no_induk='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc()['n'];
    lifecycle_assert($placementCount === $lastLevel - $firstLevel + 1,
        'Full student cycle has an incorrect number of year placements.');
    echo 'OK: unit ' . unit_label($unitId) . ' HTTP student registration, SPP publication/payment, '
        . ($lastLevel - $firstLevel) . " yearly promotions, historical reports, and graduation.\n";
    echo 'NIS ' . $nis . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
