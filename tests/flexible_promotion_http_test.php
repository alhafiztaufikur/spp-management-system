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

$cookies=[];
unit_set_context($koneksi,$unitId);
// Reserve a clean three-year window so repeat runs do not inherit tariff fixtures.
$sourceYear='';
for($start=2030;$start<2080;$start++){
 $past=($start-1).'/'.$start;$candidate=$start.'/'.($start+1);$next=($start+1).'/'.($start+2);
 $q=$koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE unit_id=? AND label IN (?,?,?)');
 $q->bind_param('isss',$unitId,$past,$candidate,$next);$q->execute();$used=(int)$q->get_result()->fetch_row()[0];$q->close();
 if(!$used){$sourceYear=$candidate;break;}
}
lifecycle_assert($sourceYear!=='','No clean academic-year window available; use a new clone.');
$targetYear=class_next_academic_year_label($sourceYear);$followingYear=class_next_academic_year_label($targetYear);
$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR']=$sourceYear;$_SESSION['active_unit_id']=$unitId;unit_set_context($koneksi,$unitId);
$identity=json_decode(lifecycle_request($base.'/tests/browser_clone_identity.php',null,$cookies)['body'],true);
lifecycle_assert(($identity['database']??'')===DB_NAME,'HTTP database identity mismatch');
$q=$koneksi->prepare("SELECT id FROM admin WHERE username=? AND unit_id=? AND role='admin' AND is_active=1");$q->bind_param('si',$username,$unitId);$q->execute();$actor=(int)$q->get_result()->fetch_row()[0];$q->close();
$hash=password_hash($password,PASSWORD_DEFAULT);$q=$koneksi->prepare('UPDATE admin SET password=? WHERE id=?');$q->bind_param('si',$hash,$actor);$q->execute();$q->close();
lifecycle_assert(lifecycle_request($base.'/login.php',['username'=>$username,'password'=>$password],$cookies)['status']===302,'Login failed');
$sourceId=class_ensure_academic_year($koneksi,$sourceYear);$targetMaster=spp_master_ensure_year($koneksi,$targetYear);spp_master_save_rates($koneksi,(int)$targetMaster['id'],array_fill_keys(range($firstLevel,$lastLevel),300000));
function fh_fixture(int $level,int $yearId,string $name):array {
 global $koneksi,$unitId;
 $nis='00'.(string)random_int(83000000,83999999);$grade=(string)$level;$class=lifecycle_class($koneksi,$unitId,$level);
 $q=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,potongan_spp_nominal,POMG,is_active) VALUES(?,?,?,?,250000,25000,10000,1)');$q->bind_param('sssi',$nis,$name,$grade,$class);$q->execute();$id=(int)$koneksi->insert_id;$q->close();
 $label=$level.'A';$q=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,250000,10000,'aktif')");$q->bind_param('issis',$yearId,$nis,$grade,$class,$label);$q->execute();$p=(int)$koneksi->insert_id;$q->close();
 return ['nis'=>$nis,'id'=>$id,'placement'=>$p,'class'=>$class,'level'=>$level];
}
$a=fh_fixture($lastLevel-1,$sourceId,'HTTP FLEX A '.$unitId);$b=fh_fixture($lastLevel-1,$sourceId,'HTTP FLEX B '.$unitId);$senior=fh_fixture($lastLevel,$sourceId,'HTTP FLEX SENIOR '.$unitId);
function fh_page(int $year,int $level,string $clock=''):array {global $base,$cookies,$sourceYear;if($clock==='')$clock=$sourceYear;return lifecycle_request($base.'/master_kelas.php?'.http_build_query(['source_year_id'=>$year,'source_level'=>$level,'promotion_q'=>'HTTP FLEX']),null,$cookies,$clock);}
function fh_post(array $p,string $clock=''):array {global $base,$cookies,$sourceYear;if($clock==='')$clock=$sourceYear;return lifecycle_request($base.'/master_kelas.php',$p,$cookies,$clock);}
$page=fh_page($sourceId,$lastLevel-1);lifecycle_assert($page['status']===200,'Selected source view failed');
$token=lifecycle_token($page['body'],'promotion-batch-form');
$target=lifecycle_class($koneksi,$unitId,$lastLevel);
$post=['aksi'=>'proses_siswa_batch','csrf_token'=>$token,'source_year_id'=>$sourceId,'source_level'=>$lastLevel-1,'target_tahun_ajaran'=>$targetYear,'selected_students'=>[$a['nis']],'source_placement_id'=>[$a['nis']=>$a['placement']],'target_master_kelas_id'=>[$a['nis']=>$target],'promotion_q'=>'HTTP FLEX'];
$bad=$post;unset($bad['source_year_id']);fh_post($bad);
lifecycle_assert((int)$koneksi->query("SELECT KELAS FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===$lastLevel-1,'Old form promoted');
fh_post(array_replace($post,['csrf_token'=>'wrong']));lifecycle_assert((int)$koneksi->query("SELECT KELAS FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===$lastLevel-1,'CSRF accepted');
$bad=array_replace($post,['target_tahun_ajaran'=>$followingYear]);fh_post($bad);lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='{$a['nis']}'")->fetch_row()[0]===1,'Wrong target year accepted');
$response=fh_post($post);lifecycle_assert($response['status']===302,'Batch did not redirect');
lifecycle_assert((int)$koneksi->query("SELECT KELAS FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===$lastLevel,'Lower grade did not promote before senior');
lifecycle_assert((float)$koneksi->query("SELECT SPP_PERBULAN FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===275000.0,'Target tariff not applied');
lifecycle_assert((int)$koneksi->query("SELECT is_active FROM siswa WHERE id={$senior['id']}")->fetch_row()[0]===1,'Unselected senior graduated');
$seniors=fh_page($sourceId,$lastLevel);
lifecycle_assert(!str_contains($seniors['body'],'name="selected_students[]" value="'.$a['nis'].'"'),'New senior shown in old source graduation');
$grad=['aksi'=>'luluskan_siswa','csrf_token'=>lifecycle_token($seniors['body'],'promotion-batch-form'),'source_year_id'=>$sourceId,'source_level'=>$lastLevel,'target_tahun_ajaran'=>$targetYear,'no_induk'=>$a['nis'],'source_placement_id'=>$a['placement']];
fh_post($grad);lifecycle_assert((int)$koneksi->query("SELECT is_active FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===1,'New senior graduated in same source year');
fh_post($post);lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='{$a['nis']}'")->fetch_row()[0]===2,'Replay duplicated');
$second=$post;$second['selected_students']=[$b['nis']];$second['source_placement_id']=[$b['nis']=>$b['placement']];$second['target_master_kelas_id']=[$b['nis']=>$target];fh_post($second);
lifecycle_assert((int)$koneksi->query("SELECT KELAS FROM siswa WHERE id={$b['id']}")->fetch_row()[0]===$lastLevel,'Separate lower promotion failed');
$grad['no_induk']=$senior['nis'];$grad['source_placement_id']=$senior['placement'];fh_post($grad);
lifecycle_assert((int)$koneksi->query("SELECT is_active FROM siswa WHERE id={$senior['id']}")->fetch_row()[0]===0,'Original senior did not graduate');
lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE no_induk='{$senior['nis']}'")->fetch_row()[0]===1,'Graduation made target placement');
$nextId=(int)$koneksi->query("SELECT id FROM tahun_ajaran WHERE label='$targetYear'")->fetch_row()[0];
$newPlacement=(int)$koneksi->query("SELECT id FROM siswa_tahun_ajaran WHERE no_induk='{$a['nis']}' AND tahun_ajaran_id=$nextId")->fetch_row()[0];
$grad=array_replace($grad,['source_year_id'=>$nextId,'target_tahun_ajaran'=>$followingYear,'no_induk'=>$a['nis'],'source_placement_id'=>$newPlacement]);
fh_post($grad);lifecycle_assert((int)$koneksi->query("SELECT is_active FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===1,'Future source accepted');
fh_post($grad,$targetYear);lifecycle_assert((int)$koneksi->query("SELECT is_active FROM siswa WHERE id={$a['id']}")->fetch_row()[0]===0,'Real next cycle rejected');
$yearPast=class_ensure_academic_year($koneksi,class_previous_academic_year_label($sourceYear));$junior=fh_fixture($firstLevel,$yearPast,'HTTP FLEX CATCHUP '.$unitId);
$past=fh_page($yearPast,$firstLevel);$catchup=['aksi'=>'naikkan_siswa','csrf_token'=>lifecycle_token($past['body'],'promotion-batch-form'),'source_year_id'=>$yearPast,'source_level'=>$firstLevel,'target_tahun_ajaran'=>$sourceYear,'no_induk'=>$junior['nis'],'source_placement_id'=>$junior['placement'],'target_master_kelas_id'=>lifecycle_class($koneksi,$unitId,$firstLevel+1)];
fh_post($catchup);lifecycle_assert((int)$koneksi->query("SELECT KELAS FROM siswa WHERE id={$junior['id']}")->fetch_row()[0]===$firstLevel+1,'Historical catchup failed');
lifecycle_assert((float)$koneksi->query("SELECT SPP_PERBULAN FROM siswa WHERE id={$junior['id']}")->fetch_row()[0]===0.0,'Missing target master copied guessed tariff');
lifecycle_assert((int)$koneksi->query("SELECT COUNT(*) FROM siswa_audit_log WHERE siswa_id IN ({$a['id']},{$b['id']},{$senior['id']},{$junior['id']}) AND aksi IN ('naik_kelas','lulus')")->fetch_row()[0]===5,'Missing movement audit');
echo 'PASS: unit '.$unitId.' HTTP context/CSRF/stale year, flexible lower-first/split graduation, no repeated senior, real next cycle, catchup and no guessed SPP'.PHP_EOL;

