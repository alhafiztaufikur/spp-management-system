<?php
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/parent_letter_principals.php';
require_once __DIR__.'/../includes/parent_letter_drafts.php';
require_once __DIR__.'/../includes/pdf.php';
function plpt_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function plpt_reject(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException($message);}
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
plpt_assert(plp_schema_ready($db)&&plp_schema_ready($db,'finance'),'Schema not installed');
$initial=[];foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);$initial[$unit]=plp_state($db,$unit);}
$initialNames=array_map(static fn($state)=>array_column($state['names'],'name','id')[$state['selected_id']],$initial);
$_SESSION['active_unit_id']=1;unit_set_context($db,1);$s=$initial[1];$original=$s['selected_id'];
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'delete','id'=>$original,'version'=>$s['version']]),'Name deleted without replacement');
foreach(['',str_repeat('x',151),"\0bad"] as $bad)plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>$bad,'version'=>$s['version']]),'Invalid name accepted');
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>'  '.mb_strtolower($initialNames[1]).'  ','version'=>$s['version']]),'Duplicate accepted');
$s=plp_mutate($db,1,['action'=>'add','name'=>'Kepala Sekolah Uji','version'=>$s['version']]);$new=$s['selected_id'];
plpt_assert($new!==$original&&count($s['names'])===count($initial[1]['names'])+1,'Add did not select new name');
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'select','id'=>$original,'version'=>$initial[1]['version']]),'Stale version accepted');
plpt_reject(fn()=>plp_mutate($db,2,['action'=>'select','id'=>$initial[2]['selected_id'],'version'=>$initial[2]['version']]),'Cross-unit mutation accepted');
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'select','id'=>$initial[2]['selected_id'],'version'=>$s['version']]),'Foreign principal accepted');
$student=['unit_id'=>1,'nis'=>'QA','nama'=>'Siswa Uji','kelas'=>'1A','total_tunggakan'=>100,'items'=>[['komponen'=>'SPP','periode'=>'November 2026','sisa'=>100]]];
$snapshots=plp_snapshots($db,[$student]);$token=parent_letter_draft_create(['students'=>[$student],'today'=>'2026-11-12','unit'=>1,'signatories'=>$snapshots]);
$principalBefore=report_principal_letter_html([],'2026-11-12');
$html=report_parent_letters_html([$student],'2026-11-12',[],$snapshots);
plpt_assert(str_contains($html,'Kepala Sekolah Uji')&&str_contains($html,'SD Al-Qur&#039;an (SDA) Mutiara Hikmah'),'Snapshot or kop missing');
$s=plp_mutate($db,1,['action'=>'edit','id'=>$new,'name'=>'Nama Baru & Gelar <uji>','version'=>$s['version']]);
plpt_assert(parent_letter_draft_read($token)['signatories']===$snapshots,'Existing draft changed');
$current=report_parent_letters_html([$student],'2026-11-12',[],plp_snapshots($db,[$student]));
plpt_assert(str_contains($current,'Nama Baru &amp; Gelar &lt;uji&gt;'),'Name not escaped');
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'delete','id'=>$new,'version'=>$s['version']]),'Active deleted without replacement');
$s=plp_mutate($db,1,['action'=>'delete','id'=>$new,'replacement_id'=>$original,'version'=>$s['version']]);
plpt_assert($s['selected_id']===$original&&count($s['names'])===count($initial[1]['names']),'Replacement failed');
plpt_assert((int)$db->query("SELECT COUNT(*) FROM parent_letter_principals WHERE id=$new AND deleted_at IS NOT NULL")->fetch_row()[0]===1,'Not soft deleted');
plpt_assert(parent_letter_draft_read($token)['signatories']===$snapshots,'Deleted name changed snapshot');
$replay=plp_schema_apply($db);plpt_assert($replay===['schema_changes'=>0,'seeded_units'=>[]],'Replay changed state');
$_SESSION['active_unit_id']=0;unit_set_context($db,0);$mixed=[];foreach([1,2,3] as $unit){$row=$student;$row['unit_id']=$unit;$mixed[]=$row;}
$mixedSnapshots=plp_snapshots($db,$mixed);$mixedHtml=report_parent_letters_html($mixed,'2026-11-12',[],$mixedSnapshots);
foreach(array_merge($initialNames,['SMP IT Mutiara Hikmah','SMA IT Mutiara Hikmah']) as $text)plpt_assert(str_contains($mixedHtml,report_e($text)),'Mixed unit missing '.$text);
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>'Forbidden','version'=>1]),'All-unit write accepted');
$old=report_parent_letters_html([$student],'2026-11-12');plpt_assert(str_contains($old,'(________________________)')&&!str_contains($old,report_e($initialNames[1])),'Legacy draft changed');
unit_set_context($db,1);plpt_assert(report_principal_letter_html([],'2026-11-12')===$principalBefore,'Other report changed');
// Verify unit guards at the SQL layer as well as service validation.
unit_set_context($db,1);$blocked=false;try{$db->query("INSERT INTO parent_letter_principals_data(unit_id,name) VALUES(2,'Foreign')");}catch(mysqli_sql_exception $e){$blocked=true;}plpt_assert($blocked,'SQL unit guard missing');
$_SESSION['active_unit_id']=1;unit_set_context($db,1);
$all=parent_letter_collect($db,['mode'=>'all']);plpt_assert(count($all['students'])>0&&$all['signatories'][1]['principal_name']===$initialNames[1]&&$all['layout_version']===4,'All mode has no snapshot');
$key=unit_student_key($all['students'][0]);foreach(['single','selected'] as $mode){$selection=parent_letter_collect($db,['mode'=>$mode,'students'=>$key]);plpt_assert(count($selection['students'])===1&&$selection['signatories']===$all['signatories'],'Selection snapshot missing');}
$realClass=null;foreach($all['students'] as $row)if((int)($row['master_kelas_id']??0)>0){$realClass=(int)$row['master_kelas_id'];break;}
if($realClass){$class=parent_letter_collect($db,['mode'=>'class','kelas'=>'rombel:'.$realClass]);plpt_assert($class['students']&&$class['signatories']===$all['signatories'],'Class snapshot missing');}
$financeStates=[];
foreach([1,2,3] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);
    $state=plp_state($db,$unit,'finance');$id=$state['selected_id'];
    plpt_assert(array_column($state['names'],'name','id')[$id]===PLP_DEFAULT_FINANCE_NAME,'Default Finance signer missing');
    plpt_reject(fn()=>plp_mutate($db,$unit,['action'=>'delete','id'=>$id,'version'=>$state['version']],'finance'),'Last Finance signer deleted');
    $finance=plp_mutate($db,$unit,['action'=>'add','name'=>"Petugas Keuangan Uji $unit",'version'=>$state['version']],'finance');
    plpt_reject(fn()=>plp_mutate($db,$unit,['action'=>'edit','id'=>$finance['selected_id'],'name'=>'Konflik','version'=>$state['version']],'finance'),'Stale Finance version accepted');
    plpt_assert(plp_state($db,$unit)['selected_id']===$initial[$unit]['selected_id'],'Finance changed principal selection');
    $financeStates[$unit]=[$id,$finance];
}
$_SESSION['active_unit_id']=0;unit_set_context($db,0);
$signers=plp_snapshots($db,$mixed,true);$notice=report_parent_letters_html($mixed,'2026-11-12',[],$signers,3);
foreach([1,2,3] as $unit){
    plpt_assert($signers[$unit]['finance_name']==="Petugas Keuangan Uji $unit"
        &&str_contains($notice,"Petugas Keuangan Uji $unit"),'Mixed draft finance signer missing');
}
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>'Forbidden','version'=>1],'finance'),'All-unit Finance write accepted');
foreach($financeStates as $unit=>[$original,$state]){
    $_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);
    $state=plp_mutate($db,$unit,['action'=>'edit','id'=>$state['selected_id'],'name'=>"Penanda Tangan Baru $unit",'version'=>$state['version']],'finance');
    $unchanged=report_parent_letters_html($mixed,'2026-11-12',[],$signers,3);
    plpt_assert($unchanged===$notice,'Signer edit changed a snapshotted letter');
    $state=plp_mutate($db,$unit,['action'=>'delete','id'=>$state['selected_id'],'replacement_id'=>$original,'version'=>$state['version']],'finance');
    plpt_assert($state['selected_id']===$original,'Finance replacement failed');
}
plpt_assert(plp_schema_apply($db,'finance')===['schema_changes'=>0,'seeded_units'=>[]],'Finance replay changed state');
$_SESSION['active_unit_id']=0;unit_set_context($db,0);
if($out=getenv('SPP_UI_OUTPUT')){
    require_pdf_library();foreach(['mixed'=>$mixedHtml,'snapshot'=>$html,'escaped'=>$current,'legacy'=>$old,'reminder'=>$notice] as $file=>$content){$pdf=new \Dompdf\Dompdf();$pdf->loadHtml($content,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();file_put_contents($out.'/principal-'.$file.'.pdf',$pdf->output());}
}
echo "PASS: seeds, CRUD, validation, duplicate names, versions, units, SQL guards, replacement, soft delete, immutable drafts, mixed PDF, legacy compatibility, replay.\n";
