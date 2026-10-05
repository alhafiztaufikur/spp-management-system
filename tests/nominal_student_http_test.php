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

$cookies=[];$nis=(string)random_int(9800000000,9899999999);$year='2034/2035';
try{
 $_SESSION['active_unit_id']=$unitId;unit_set_context($koneksi,$unitId);
 $target=json_decode(lifecycle_request($base.'/tests/browser_clone_identity.php',null,$cookies)['body'],true);lifecycle_assert(($target['database']??'')===DB_NAME,'HTTP target mismatch');
 $q=$koneksi->prepare("SELECT id FROM admin WHERE username=? AND unit_id=? AND role='admin' AND is_active=1");$q->bind_param('si',$username,$unitId);$q->execute();$actor=(int)$q->get_result()->fetch_row()[0];$q->close();lifecycle_assert($actor>0,'Actor unavailable');
 $hash=password_hash($password,PASSWORD_DEFAULT);$q=$koneksi->prepare('UPDATE admin SET password=? WHERE id=?');$q->bind_param('si',$hash,$actor);$q->execute();$q->close();
 lifecycle_assert(lifecycle_request($base.'/login.php',['username'=>$username,'password'=>$password],$cookies)['status']===302,'Login');
 $master=lifecycle_request($base.'/master_spp.php?tahun='.rawurlencode($year),null,$cookies,$year);$rateToken=lifecycle_token($master['body'],'spp-rate-form');
 $rates=array_fill_keys(range($firstLevel,$lastLevel),250000);
 lifecycle_request($base.'/master_spp.php',['aksi'=>'simpan_tarif','csrf_token'=>$rateToken,'tahun_ajaran'=>$year,'jumlah'=>$rates],$cookies,$year);
 $page=lifecycle_request($base.'/siswa/daftar.php',null,$cookies,$year);$token=lifecycle_token($page['body'],'form-master-siswa');
 $post=['aksi'=>'tambah','csrf_token'=>$token,'no_induk'=>$nis,'nama'=>'TEST NOMINAL '.$unitId,'master_kelas_id'=>lifecycle_class($koneksi,$unitId,$firstLevel),'advanced_enabled'=>'1','potongan_spp_nominal'=>'25.000','psb'=>0,'pomg'=>0,'daftar_ulang'=>0,'potong_du'=>0,'no_induk_diknas'=>''];
 lifecycle_request($base.'/siswa/daftar.php',$post,$cookies,$year);
 $row=$koneksi->query("SELECT * FROM siswa WHERE NO_INDUK='$nis'")->fetch_assoc();lifecycle_assert($row&&$row['NO_induk_diknas']===null&&(float)$row['potongan_spp_nominal']===25000.0&&(float)$row['SPP_PERBULAN']===225000.0,'Nominal registration/optional Diknas');
 $id=(int)$row['id'];$update=$post;unset($update['no_induk_diknas']);$update['aksi']='update';$update['id']=$id;
 foreach([['potongan_spp_nominal'=>'300.000'],['no_induk_diknas'=>'123'],['pangkal'=>0],['potongan_spp_persen'=>10]] as $invalid){
  lifecycle_request($base.'/siswa/daftar.php',array_replace($update,$invalid),$cookies,$year);
  $r=$koneksi->query("SELECT SPP_PERBULAN,potongan_spp_nominal,NO_induk_diknas FROM siswa WHERE id=$id")->fetch_assoc();lifecycle_assert((float)$r['potongan_spp_nominal']===25000.0&&$r['NO_induk_diknas']===null,'Invalid/stale input wrote data');
 }
 $diknas='00'.(string)random_int(70000000,79999999);
 lifecycle_request($base.'/siswa/daftar.php',array_replace($update,['no_induk_diknas'=>$diknas]),$cookies,$year);
 lifecycle_assert($koneksi->query("SELECT NO_induk_diknas FROM siswa WHERE id=$id")->fetch_row()[0]===$diknas,'Leading-zero Diknas');
 $dupeNis=(string)random_int(9700000000,9799999999);
 lifecycle_request($base.'/siswa/daftar.php',array_replace($post,['no_induk'=>$dupeNis,'no_induk_diknas'=>$diknas]),$cookies,$year);
 lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa WHERE NO_INDUK='$dupeNis'")->fetch_row()[0]===0,'Duplicate Diknas accepted in same unit');
 lifecycle_request($base.'/siswa/daftar.php',array_replace($update,['no_induk_diknas'=>'']),$cookies,$year);
 lifecycle_assert($koneksi->query("SELECT NO_induk_diknas FROM siswa WHERE id=$id")->fetch_row()[0]===null,'Cleared Diknas is not NULL');
 $master=lifecycle_request($base.'/master_spp.php?tahun='.rawurlencode($year),null,$cookies,$year);
 lifecycle_request($base.'/master_spp.php',['aksi'=>'terbitkan','csrf_token'=>lifecycle_token($master['body'],'spp-publish-form'),'tahun_ajaran'=>$year,'selected_students'=>[$nis]],$cookies,$year);
 $bill=$koneksi->query("SELECT id,nominal_tagihan,potongan_nominal_ditetapkan_snapshot FROM tagihan_spp WHERE no_induk='$nis' AND bulan='07'")->fetch_assoc();
 lifecycle_assert($bill&&(float)$bill['nominal_tagihan']===225000.0&&(float)$bill['potongan_nominal_ditetapkan_snapshot']===25000.0,'Published nominal');
 $form=lifecycle_request($base.'/pembayaran/form.php',null,$cookies,$year);preg_match('/name="request_key" value="([a-f0-9]{32})"/',$form['body'],$pk);
 $pay=['aksi'=>'input','csrf_token'=>lifecycle_token($form['body'],'form-bayar'),'request_key'=>$pk[1],'payment_plan'=>'monthly','no_induk'=>$nis,'bulan_bayar'=>'07','tahun_bayar'=>'2034','sistem_pembayaran'=>'Tunai','uang_spp'=>225000,'uang_komite'=>0];
 lifecycle_request($base.'/pembayaran/proses.php',$pay,$cookies,$year);
 lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis' AND U_SPP=225000")->fetch_row()[0]===1,'Nominal paid once: '.lifecycle_flash(lifecycle_request($base.'/pembayaran/form.php',null,$cookies,$year)['body']));
 $paidId=(int)$bill['id'];$paidBefore=$koneksi->query("SELECT * FROM tagihan_spp WHERE id=$paidId")->fetch_assoc();
 $masterId=(int)$koneksi->query("SELECT mst.id FROM master_spp_tahun mst JOIN tahun_ajaran y ON y.id=mst.tahun_ajaran_id WHERE y.label='$year'")->fetch_row()[0];
 spp_master_save_rates($koneksi,$masterId,array_fill_keys(range($firstLevel,$lastLevel),300000));
 lifecycle_assert((float)$koneksi->query("SELECT nominal_tagihan FROM tagihan_spp WHERE no_induk='$nis' AND bulan='08'")->fetch_row()[0]===275000.0,'Fixed discount survived rate raise');
 $update['potongan_spp_nominal']='300.000';lifecycle_request($base.'/siswa/daftar.php',$update,$cookies,$year);
 lifecycle_assert((float)$koneksi->query("SELECT nominal_tagihan FROM tagihan_spp WHERE no_induk='$nis' AND bulan='08'")->fetch_row()[0]===0.0,'Full nominal discount');
 spp_master_save_rates($koneksi,$masterId,array_fill_keys(range($firstLevel,$lastLevel),200000));
 spp_master_save_rates($koneksi,$masterId,array_fill_keys(range($firstLevel,$lastLevel),400000));
 $future=$koneksi->query("SELECT nominal_tagihan,potongan_nominal_ditetapkan_snapshot FROM tagihan_spp WHERE no_induk='$nis' AND bulan='08'")->fetch_assoc();
 lifecycle_assert((float)$future['nominal_tagihan']===100000.0&&(float)$future['potongan_nominal_ditetapkan_snapshot']===300000.0,'Requested nominal lost after tariff cap');
 lifecycle_assert($paidBefore===$koneksi->query("SELECT * FROM tagihan_spp WHERE id=$paidId")->fetch_assoc(),'Paid snapshot changed');
 $q=$koneksi->prepare("SELECT id FROM siswa WHERE NO_INDUK=? AND id<>?");$q->bind_param('si',$nis,$id);$q->execute();lifecycle_assert($q->get_result()->num_rows===0,'Duplicate identity');$q->close();
 echo 'PASS: unit '.$unitId.' nominal SPP, invalid/stale input, optional Diknas, fixed/capped rate and paid snapshot'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAILED: '.$e->getMessage().PHP_EOL);exit(1);}

