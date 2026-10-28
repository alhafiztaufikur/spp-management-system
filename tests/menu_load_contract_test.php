<?php
/** Exact read-model comparison against a private checkout of the pre-change code. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
$root=(string)getenv('SPP_QA_DIR');$mode=$argv[1]??'';$unit=(int)($argv[2]??1);
if(!$root||!is_dir($root.'/baseline')||!in_array($mode,['baseline','after'],true)||!in_array($unit,[0,1,2,3],true))throw new RuntimeException('Private baseline, mode and unit required.');
$source=$mode==='baseline'?$root.'/baseline':dirname(__DIR__);
require_once $source.'/koneksi.php';spp_test_assert_database_target($koneksi);
require_once $source.'/includes/reports.php';
require_once $source.'/includes/registration_combined.php';
require_once $source.'/includes/payment_student_details.php';
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);$results=[];
function load_contract_hash(mixed $value): string {return hash('sha256',json_encode($value,JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
try{
    foreach(array_keys(report_registry()) as $template){
        foreach(['period','full'] as $range){
            if($range==='full'&&!in_array($template,['riwayat-tagihan','setoran','tabungan-siswa'],true))continue;
            $_GET=['template'=>$template,'tahun_ajaran'=>'2026/2027','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-27'];
            if($range==='full')$_GET=['template'=>$template,'siswa_status'=>'all','tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2026-10-27'];
            $model=report_build($koneksi,$template,report_filters($koneksi,$_GET));
            $results[$template.'/'.$range]=['count'=>count($model['rows']),'hash'=>load_contract_hash($model)];
            echo $template.'/'.$range.': '.count($model['rows']).PHP_EOL;
        }
    }
    $registration=rc_page($koneksi,[]);unset($registration['read_sql'],$registration['read_args']);
    $results['registration']=load_contract_hash($registration);
    unit_set_context($koneksi,$unit);
    $results['prior_debt']=load_contract_hash(report_prior_debt_summary($koneksi,'2026/2027',report_active_regular_student_nis($koneksi)));
    if($unit===0){
        // The existing planner is unit-specific; global consumers compose the
        // three supported unit plans rather than calling it under scope zero.
        $plans=[];foreach([1,2,3] as $planUnit){$_SESSION['active_unit_id']=$planUnit;unit_set_context($koneksi,$planUnit);$plans[$planUnit]=ldu_plan($koneksi,'2026/2027');}
        $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$results['du_plan']=load_contract_hash($plans);
    }else $results['du_plan']=load_contract_hash(ldu_plan($koneksi,'2026/2027'));
    $prior=$GLOBALS['lb_report_compact'][$koneksi->thread_id]??false;
    $GLOBALS['lb_report_compact'][$koneksi->thread_id]=true;
    try{
        du_psb_invalidate($koneksi);
        $results['du_coverage_compact']=load_contract_hash(du_psb_states($koneksi,'2026/2027'));
        $results['prior_debt_compact']=load_contract_hash(report_prior_debt_summary($koneksi,'2026/2027',report_active_regular_student_nis($koneksi)));
    }finally{$GLOBALS['lb_report_compact'][$koneksi->thread_id]=$prior;}
    foreach(hp_rows($koneksi,"SELECT NO_INDUK,unit_id FROM siswa WHERE is_active=1 AND KELAS NOT IN ('CUSTOM','OUT') ORDER BY unit_id,NAMA LIMIT 8") as $s){
        unit_set_context($koneksi,(int)$s['unit_id']);
        $results['student/'.$s['unit_id'].'/'.$s['NO_INDUK']]=load_contract_hash(payment_student_details($koneksi,$s['NO_INDUK']));
    }
}finally{$koneksi->rollback();}
$file=$root.'/contract-unit'.$unit.'.json';
if($mode==='baseline')file_put_contents($file,json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
else{
    $before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if($before!==$results){$changed=[];foreach($results as $key=>$value)if(($before[$key]??null)!==$value)$changed[]=$key;throw new RuntimeException('Read output changed: '.implode(', ',$changed));}
    file_put_contents($root.'/contract-verified-unit'.$unit.'.json',json_encode(['passed'=>count($results),'results'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo 'PASS exact model, totals, labels and row order: '.count($results).' contracts'.PHP_EOL;
}
