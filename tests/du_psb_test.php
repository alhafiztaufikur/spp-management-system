<?php
require_once __DIR__.'/../includes/daftar_ulang.php';
require_once __DIR__.'/../includes/du_master_service.php';
$checks=0;
function dpp_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$year=du_current_academic_year();
foreach([1=>1,2=>7,3=>10] as $unit=>$first){
    $p=['unit_id'=>$unit,'kelas'=>(string)$first,'status'=>'aktif','is_active'=>1,'year_status'=>'draft','du_covered_by_psb'=>0];
    dpp_check(du_psb_decision($p,$year)['covered_psb'],'Initial placement is included even before publication or PSB settlement');
    foreach([[true,false],[false,true],[true,true]] as [$bill,$cash])dpp_check(du_psb_decision($p,$year,$bill,$cash)['conflict'],'Separate evidence conflicts without erasing history');
    dpp_check(du_psb_decision(array_replace($p,['kelas'=>(string)($first+1)]),$year)['status']==='normal','Promotion uses regular DU');
    dpp_check(!du_psb_decision(array_replace($p,['is_active'=>0]),$year)['covered_psb'],'Inactive pupils are not backfilled');
    dpp_check(!du_psb_decision(array_replace($p,['year_status'=>'closed']),$year)['covered_psb'],'Closed years are preserved');
    dpp_check(!du_psb_decision($p,'2025/2026')['covered_psb'],'Older unsaved history is preserved');
    dpp_check(du_psb_decision(array_replace($p,['du_covered_by_psb'=>1,'is_active'=>0,'year_status'=>'closed']),'2026/2027')['covered_psb'],'Saved coverage remains a historical snapshot');
    dpp_check(!du_psb_decision(array_replace($p,['status'=>'dibatalkan','du_covered_by_psb'=>1]),$year)['covered_psb'],'Cancelled placement does not grant coverage');
}
echo "PASS $checks pure DU/PSB policy checks.\n";
