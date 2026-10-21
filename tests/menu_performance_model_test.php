<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/payment_student_details.php';
require_once __DIR__.'/../includes/spp_billing.php';
require_once __DIR__.'/../includes/komite_billing.php';
require_once __DIR__.'/../includes/payment_obligations.php';
require_once __DIR__.'/../includes/general_multiple.php';
$checks=0;$times=[];
function menu_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>1];$_GET=[];
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{
    foreach([1,2,3] as $unit){
        $_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);unset($GLOBALS['lb_read_student'][$koneksi->thread_id]);
        $fullPsb=one_time_fee_payload_for_options($koneksi);$fullSpp=spp_payment_payload($koneksi);$fullKomite=komite_payment_payload($koneksi);$fullDu=du_selectable_bills_payload($koneksi);$fullAnnual=annual_fee_payload_for_options($koneksi);
        $students=$koneksi->query("SELECT NO_INDUK FROM siswa WHERE is_active=1 AND KELAS NOT IN ('CUSTOM','OUT') ORDER BY NAMA LIMIT 8")->fetch_all(MYSQLI_ASSOC);
        $reference=[];
        foreach($students as $student){$nis=$student['NO_INDUK'];$reference[$nis]=['status'=>spp_published_period_status($koneksi,$nis,'10','2026'),'obligations'=>payment_obligations($koneksi,$nis),'psb'=>lb_psb_source($koneksi,$nis),'du'=>lb_du_source($koneksi,$nis,'2026/2027')];}
        foreach($students as $student){
            $nis=$student['NO_INDUK'];lb_scope_student_read($koneksi,$nis);$start=microtime(true);
            $details=payment_student_details($koneksi,$nis);$times[]=(microtime(true)-$start)*1000;
            menu_check(spp_published_period_status($koneksi,$nis,'10','2026')==$reference[$nis]['status'],"SPP status unchanged unit $unit");
            menu_check(payment_obligations($koneksi,$nis)==$reference[$nis]['obligations'],"Obligations unchanged unit $unit");
            menu_check(lb_psb_source($koneksi,$nis)==$reference[$nis]['psb'],"PSB evidence unchanged unit $unit");
            menu_check(lb_du_source($koneksi,$nis,'2026/2027')==$reference[$nis]['du'],"DU evidence unchanged unit $unit");
            foreach(['sppBilling'=>$fullSpp[$nis]??['tagihan'=>[]],'komiteBilling'=>$fullKomite[$nis]??[],'duBills'=>$fullDu[$nis]??[],'annualFees'=>$fullAnnual[$nis]??[]] as $key=>$expected)menu_check($details[$key]==$expected,"Scoped $key matches bulk unit $unit");
            menu_check((float)$details['totalPsb']===(float)$fullPsb[$nis]['psb']['total']&&(float)$details['paidPsb']===(float)$fullPsb[$nis]['psb']['paid'],'PSB option amounts unchanged');
            menu_check(count(lb_cache($koneksi)['students'])<=1,'Read scope only contains selected student');
        }
        unset($GLOBALS['lb_read_student'][$koneksi->thread_id]);
        $rows=general_payment_rows($koneksi,'2026-10-01','2026-10-22','','nominal_terkecil',50);
        $amounts=array_map('floatval',array_column($rows,'total_jumlah'));$ordered=$amounts;sort($ordered,SORT_NUMERIC);menu_check($amounts===$ordered,'Ascending nominal sort');
        menu_check($rows===general_payment_rows($koneksi,'2026-10-01','2026-10-22','','nominal_terkecil',50),'Deterministic sort');
        foreach(['belum_spp','belum_komite','belum_du','belum_biaya_lain'] as $kind){
            $unpaid=general_unpaid($koneksi,$kind,'10','2026','Oktober','2026/2027','',[],'nominal_terkecil');
            $amounts=array_map('floatval',array_column($unpaid,'sisa'));$ordered=$amounts;sort($ordered,SORT_NUMERIC);
            menu_check($amounts===$ordered,'Ascending remaining amount after historical credits: '.$kind);
        }
    }
    unit_set_context($koneksi,1);$_SESSION['active_unit_id']=1;
    $sections=general_sections($koneksi,['*'],'2026-10-01','2026-10-22','','nama');$allRows=[];
    foreach($sections as $section)$allRows=array_merge($allRows,$section['rows']);
    $offset=max(0,count($sections[0]['rows'])-1);
    $bounded=general_sections($koneksi,['*'],'2026-10-01','2026-10-22','','nama',10,$offset);$pageRows=[];
    foreach($bounded as $section)$pageRows=array_merge($pageRows,$section['rows']);
    menu_check($pageRows==array_slice($allRows,$offset,10),'Pagination across payment and debt sections preserves exact records');
    menu_check(array_sum(array_column($bounded,'row_count'))===count($allRows),'Bounded page retains complete row count');
    foreach($bounded as $i=>$section)menu_check($section['totals']==$sections[$i]['totals'],'Pagination retains section totals');
}finally{$koneksi->rollback();}
sort($times);echo json_encode(['checks'=>$checks,'student_details_median_ms'=>round($times[intdiv(count($times),2)],2),'student_details_max_ms'=>round(max($times),2)],JSON_PRETTY_PRINT).PHP_EOL;
