<?php
/** Read-only comparison with the pre-change model on the verified desktop clone. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58660')throw new RuntimeException('Use the isolated desktop QA instance.');
$root=(string)getenv('SPP_DESKTOP_QA');
if(!str_contains($root,'pagination_desktop_'))throw new RuntimeException('Missing private QA artifacts.');
$proof=json_decode(file_get_contents($root.'/restore-proof.json'),true,512,JSON_THROW_ON_ERROR);
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require_once __DIR__.'/../koneksi.php';
$identity=$koneksi->query('SELECT @@server_uuid,@@port,@@datadir')->fetch_row();
if(empty($proof['verified_restore'])||$identity[0]!==explode("\t",$proof['identity'])[0]||(int)$identity[1]!==58660)throw new RuntimeException('Unverified database identity.');
require_once __DIR__.'/../includes/registration_combined.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/report_history_page.php';
$reference=json_decode(file_get_contents($root.'/model-before.json'),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['lb_report_compact'][$koneksi->thread_id]=true;$GLOBALS['lb_read_projection'][$koneksi->thread_id]=true;
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
$checks=0;$projections=[];
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
    $page=rc_page($koneksi,[]);$row=$page['rows'][0]??null;$old=$reference[$unit][0];
    foreach(['total','summary','page','rows'] as $key){if($page[$key]!==$old[$key])throw new RuntimeException('DU parity mismatch: '.$unit.' '.$key);$checks++;}
    $canonical=static function($rows){foreach($rows as &$item)ksort($item);unset($item);return $rows;};
    if($row&&$canonical(rc_receipts($koneksi,$row))!==$canonical($old['receipts']))throw new RuntimeException('Receipt/date order mismatch');$checks++;
    if(du_psb_states($koneksi,du_current_academic_year())!==$reference[$unit]['coverage'])throw new RuntimeException('Coverage decision mismatch');$checks++;
    if($unit===0)continue;
    $level=[1=>4,2=>8,3=>11][$unit];
    $filters=report_filters($koneksi,['template'=>'riwayat-tagihan','kelas'=>'tingkat:'.$level,'siswa_status'=>'all']);
    $full=report_build($koneksi,'riwayat-tagihan',$filters);$fullView=billing_history_view($koneksi,$full,$filters);
    $rollup=report_history_page_data($koneksi,$filters,true,true);
    if(!$rollup)throw new RuntimeException('Rollup unexpectedly unavailable.');
    $rollupView=billing_history_view($koneksi,$rollup['report'],$filters);
    if(billing_history_table($fullView)!==billing_history_table($rollupView))throw new RuntimeException('Full/rollup arrears mismatch, unit '.$unit);$checks++;
    $projections[$unit]=$fullView;
    echo 'Verified unit '.$unit."\n";
}
$koneksi->rollback();file_put_contents($root.'/billing-expected.json',json_encode($projections,JSON_THROW_ON_ERROR));
echo 'PASS '.$checks." DU parity and full/rollup financial projection checks.\n";
