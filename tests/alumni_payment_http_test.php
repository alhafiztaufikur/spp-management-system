<?php
/** Official payment routes against a disposable, verified MySQL instance. */
ob_start();
define('WORKFLOW_HELPERS_ONLY', true);
require __DIR__.'/workflow_update_http_test.php';
require_once __DIR__.'/../includes/promotion.php';
require_once __DIR__.'/../includes/komite_billing.php';

function alumni_test_session(int $actor, int $unit): array {
    session_save_path(getenv('SPP_QA_SESSION_PATH'));
    session_id('alumni'.bin2hex(random_bytes(12)));
    session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = [
        'admin_id'=>$actor, 'admin_role'=>'super_admin', 'active_unit_id'=>$unit,
        'csrf_payment'=>$csrf, 'csrf_transaction_authorization'=>$csrf,
    ];
    $id = session_id();
    session_write_close();
    return ['id'=>$id, 'csrf'=>$csrf];
}

function alumni_test_fixture(mysqli $db, int $grade, string $academicYear, bool $legacy = false): array {
    $class = hp_one($db, "SELECT id FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1", [$grade]);
    wf_assert((bool)$class, 'Final grade has an active rombel');
    $classId = (int)$class['id'];
    $nis = 'A'.bin2hex(random_bytes(4));
    $db->begin_transaction();
    try {
        ofb_write($db, 'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,250000,15000,1)',
            [$nis,'QA ALUMNI PAYMENT '.$nis,(string)$grade,$classId]);
        $placementId = class_sync_student_current_year($db, $nis, $classId, 250000, 15000);
        $placement = hp_one($db, 'SELECT p.*,s.potongan_spp_nominal FROM siswa_tahun_ajaran p
            JOIN siswa s ON s.unit_id=p.unit_id AND s.NO_INDUK=p.no_induk WHERE p.id=?', [$placementId]);
        $master = spp_master_ensure_year($db, $academicYear, true);
        $year = substr($academicYear, 0, 4);
        $issued = spp_publish_placement_periods($db, (int)$master['id'], $master, $placement, [$grade=>250000], '07',
            [$year.'-07', $year.'-08', $year.'-09']);
        wf_assert($issued['created']===3, 'Three SPP bills published before graduation');
        $du = du_create_bill_for_placement($db, $placementId);
        wf_assert($du!==null, 'DU issued before graduation');
        $ctx = promotion_context($db, (int)$master['tahun_ajaran_id']);
        if ($legacy) {
            // Model imported membership with published bills and no graduation event.
            $alumni = promotion_alumni_class($db, $ctx);
            ofb_write($db, "UPDATE siswa_tahun_ajaran SET status='pindah' WHERE id=?", [$placementId]);
            ofb_write($db, "UPDATE siswa SET KELAS='0',master_kelas_id=?,is_active=0 WHERE NO_INDUK=?", [$alumni['id'], $nis]);
        } else {
            $result = promotion_apply_student($db, $nis, 0, $ctx, $placementId, $grade, true);
            wf_assert($result['target']==='Alumni'.substr($academicYear, 5), 'Official graduation sets the Alumni class');
        }
        $db->commit();
        return compact('nis','du','year');
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function alumni_test_paid_component(mysqli $db, string $nis, string $month, string $year, int $actor, bool $spp): void {
    $db->begin_transaction();
    try {
        $date = date('Y-m-d H:i:s');
        $amount = $spp ? 250000 : 15000;
        ofb_write($db, "INSERT INTO bayar(NO_INDUK,KELAS,U_SPP,U_KOMITE,total_jumlah,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran)
            VALUES(?,'0',?,?,?,?,?,?,?,'Tunai')", [$nis,$spp ? $amount : 0,$spp ? 0 : $amount,$amount,$date,$month,$year,$actor]);
        $paymentId = (int)$db->insert_id;
        if ($spp) spp_allocate_payment($db,$nis,$paymentId,$month,$year,$amount,$date,'Tunai',(string)$actor);
        else komite_save_payment($db,$paymentId,komite_bill($db,$nis,$month,$year,true),$amount);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function alumni_test_post(array $session, int $unit, string $nis, string $month, string $year, array $amounts): array {
    $post = array_replace($session['input'], [
        'aksi'=>'input', '_operational_unit'=>(string)$unit, 'payment_plan'=>'monthly', 'no_induk'=>$nis,
        'bulan_bayar'=>$month, 'tahun_bayar'=>$year, 'tanggal_bayar'=>date('Y-m-d'), 'sistem_pembayaran'=>'Tunai',
        'uang_psb'=>0, 'uang_spp'=>0, 'uang_komite'=>0, 'uang_du'=>0, 'potongan_spp'=>0,
        'tagihan_daftar_ulang_id'=>0, 'request_key'=>bin2hex(random_bytes(16)),
    ], $amounts);
    [$status] = wf_http($session['id'],'pembayaran/proses.php',$post);
    wf_assert($status===302, 'Payment returns to the normal UI');
    return $post;
}

function alumni_test_receipts(mysqli $db, string $nis): int {
    return (int)hp_one($db,'SELECT COUNT(*) n FROM bayar WHERE NO_INDUK=?',[$nis])['n'];
}

$db = $koneksi;
unit_set_context($db, 0);
$actor = (int)hp_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")['id'];
$units = [];
foreach ([[1,6,false], [2,9,false], [3,12,false], [1,6,true], [2,9,true], [3,12,true]] as [$unit,$grade,$legacy]) {
    $session = alumni_test_session($actor,$unit);
    $_SESSION = ['admin_id'=>$actor, 'admin_role'=>'super_admin', 'active_unit_id'=>$unit];
    unit_set_context($db,$unit);
    ['nis'=>$nis,'du'=>$du,'year'=>$year] = alumni_test_fixture($db,$grade,du_current_academic_year(),$legacy);
    [$code,$form] = wf_http($session['id'],'pembayaran/form.php');
    wf_assert($code===200 && str_contains($form,'data-nis="'.$nis.'"'),'Alumni with arrears is selectable');
    if ($legacy) {
        wf_assert(preg_match('/data-nis="'.preg_quote($nis,'/').'"[^>]*data-kelas="Alumni\d{4}"\s+data-is-graduate="1"/s',$form)===1,
            'Imported alumni keeps the recorded class label and arrears-only controls');
    }
    $session['input'] = wf_fields($form,'request_key');
    [$code,$body] = wf_http($session['id'],'pembayaran/status_spp.php?'.http_build_query([
        'no_induk'=>$nis,'bulan'=>'07','tahun'=>$year,'unit_id'=>$unit,'details'=>1,
    ]));
    $details = json_decode($body,true,512,JSON_THROW_ON_ERROR);
    wf_assert($code===200 && $details['status']==='payable' && $details['payment_details']['admissionPlan']===null,
        'Alumni payment status uses published arrears');
    alumni_test_post($session,$unit,$nis,'07',$year,['uang_spp'=>250000]);
    wf_assert(alumni_test_receipts($db,$nis)===0,'SPP cannot bypass outstanding Komite');
    alumni_test_post($session,$unit,$nis,'07',$year,['uang_komite'=>15000]);
    wf_assert(alumni_test_receipts($db,$nis)===0,'Komite cannot bypass outstanding SPP');
    $post = alumni_test_post($session,$unit,$nis,'07',$year,['uang_spp'=>250000,'uang_komite'=>15000]);
    $receipt = hp_one($db,'SELECT id,U_SPP,U_KOMITE,total_jumlah FROM bayar WHERE NO_INDUK=?',[$nis]);
    wf_assert($receipt && (float)$receipt['U_SPP']===250000.0 && (float)$receipt['U_KOMITE']===15000.0
        && (float)$receipt['total_jumlah']===265000.0,'SPP and Komite paid on one receipt');
    wf_http($session['id'],'pembayaran/proses.php',$post);
    wf_assert(alumni_test_receipts($db,$nis)===1,'Retry adds no extra receipt');

    [$code,$form] = wf_http($session['id'],'pembayaran/edit.php?id='.$receipt['id']);
    wf_assert($code===200,'Alumni receipt edit available');
    $document = new DOMDocument();
    @$document->loadHTML($form);
    $editForm = $document->getElementById('form-bayar');
    wf_assert($editForm!==null,'Correction form is rendered');
    $fields = [];
    foreach ($editForm->getElementsByTagName('input') as $input) {
        $name = $input->getAttribute('name');
        if ($input->getAttribute('type')==='hidden' && $name!=='' && !str_contains($name,'[')) {
            $fields[$name] = $input->getAttribute('value');
        }
    }
    $edit = array_replace($fields, $post, [
        'aksi'=>'update','id'=>(int)$receipt['id'],'catatan'=>'QA alumni correction',
    ]);
    wf_http($session['id'],'pembayaran/proses.php',$edit);
    $corrected = hp_one($db,'SELECT KETERANGAN,total_jumlah FROM bayar WHERE id=?',[$receipt['id']]);
    wf_assert($corrected['KETERANGAN']==='QA alumni correction' && (float)$corrected['total_jumlah']===265000.0,'Paired payment correction succeeds');
    [$code,$printed] = wf_http($session['id'],'laporan/cetak_struk.php?id='.$receipt['id']);
    wf_assert($code===200 && str_contains($printed,'SPP') && str_contains($printed,'Komite'),'One printed receipt includes both components');

    alumni_test_paid_component($db,$nis,'08',$year,$actor,true);
    alumni_test_post($session,$unit,$nis,'08',$year,['uang_komite'=>15000]);
    wf_assert((float)komite_bill($db,$nis,'08',$year)['remaining']===0.0,'Alumni can pay Komite when SPP is already settled');
    alumni_test_paid_component($db,$nis,'09',$year,$actor,false);
    alumni_test_post($session,$unit,$nis,'09',$year,['uang_spp'=>250000,'uang_komite'=>15000]);
    wf_assert(alumni_test_receipts($db,$nis)===4,'Settled Komite cannot be charged twice');
    alumni_test_post($session,$unit,$nis,'09',$year,['uang_spp'=>250000]);
    wf_assert(spp_published_period_status($db,$nis,'09',$year)['status']==='already_paid','Alumni can pay SPP when Komite is already settled');

    $duBefore = du_find_bill_by_id($db,$du);
    alumni_test_post($session,$unit,$nis,'07',$year,[
        'uang_du'=>10000,'tagihan_daftar_ulang_id'=>$du,
        'du_expected_total'=>$duBefore['nominal_tagihan'],'du_expected_paid'=>$duBefore['terbayar'],
    ]);
    wf_assert(abs((float)du_find_bill_by_id($db,$du)['sisa']-((float)$duBefore['sisa']-10000.0))<.001,'Alumni can pay DU');
    $units[$unit] = $unit;
    echo 'PASS '.($legacy ? 'imported' : 'officially graduated').' alumni payment lifecycle for unit '.$unit.PHP_EOL;
}
if ($output = getenv('SPP_ALUMNI_QA_RESULT')) {
    $uuid = spp_test_isolated_manifest($db)['server_uuid'];
    file_put_contents($output,json_encode(['ok'=>true,'restore_uuid'=>$uuid,'units'=>array_values($units),'checks'=>$checks],JSON_THROW_ON_ERROR));
}
echo 'PASS '.$checks.' alumni payment checks.'.PHP_EOL;
