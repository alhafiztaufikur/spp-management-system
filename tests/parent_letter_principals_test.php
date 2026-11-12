<?php
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/parent_letter_principals.php';
require_once __DIR__.'/../includes/parent_letter_drafts.php';
require_once __DIR__.'/../includes/pdf.php';
function plpt_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function plpt_reject(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException($message);}
$db=$koneksi;$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
plpt_assert(plp_schema_ready($db),'Schema not installed');
$initial=[];foreach([1,2,3] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);$initial[$unit]=plp_state($db,$unit);}
foreach([1=>'Hairul Laka',2=>'Ahmad Hubbudin',3=>'Nur Qomariyah'] as $unit=>$name){$s=$initial[$unit];plpt_assert(count($s['names'])===1&&$s['names'][0]['name']===$name,'Seed mismatch');}
$_SESSION['active_unit_id']=1;unit_set_context($db,1);$s=$initial[1];$original=$s['selected_id'];
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'delete','id'=>$original,'version'=>$s['version']]),'Last name deleted');
foreach(['',str_repeat('x',151),"\0bad"] as $bad)plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>$bad,'version'=>$s['version']]),'Invalid name accepted');
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>'  hairul laka  ','version'=>$s['version']]),'Duplicate accepted');
$s=plp_mutate($db,1,['action'=>'add','name'=>'Kepala Sekolah Uji','version'=>$s['version']]);$new=$s['selected_id'];
plpt_assert($new!==$original&&count($s['names'])===2,'Add did not select new name');
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'select','id'=>$original,'version'=>1]),'Stale version accepted');
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
plpt_assert($s['selected_id']===$original&&count($s['names'])===1,'Replacement failed');
plpt_assert((int)$db->query("SELECT COUNT(*) FROM parent_letter_principals WHERE id=$new AND deleted_at IS NOT NULL")->fetch_row()[0]===1,'Not soft deleted');
plpt_assert(parent_letter_draft_read($token)['signatories']===$snapshots,'Deleted name changed snapshot');
$replay=plp_schema_apply($db);plpt_assert($replay===['schema_changes'=>0,'seeded_units'=>[]],'Replay changed state');
$_SESSION['active_unit_id']=0;unit_set_context($db,0);$mixed=[];foreach([1,2,3] as $unit){$row=$student;$row['unit_id']=$unit;$mixed[]=$row;}
$mixedSnapshots=plp_snapshots($db,$mixed);$mixedHtml=report_parent_letters_html($mixed,'2026-11-12',[],$mixedSnapshots);
foreach(['Hairul Laka','Ahmad Hubbudin','Nur Qomariyah','SMP IT Mutiara Hikmah','SMA IT Mutiara Hikmah'] as $text)plpt_assert(str_contains($mixedHtml,$text),'Mixed unit missing '.$text);
plpt_reject(fn()=>plp_mutate($db,1,['action'=>'add','name'=>'Forbidden','version'=>1]),'All-unit write accepted');
$old=report_parent_letters_html([$student],'2026-11-12');plpt_assert(str_contains($old,'(________________________)')&&!str_contains($old,'Hairul Laka'),'Legacy draft changed');
unit_set_context($db,1);plpt_assert(report_principal_letter_html([],'2026-11-12')===$principalBefore,'Other report changed');
// Verify unit guards at the SQL layer as well as service validation.
unit_set_context($db,1);$blocked=false;try{$db->query("INSERT INTO parent_letter_principals_data(unit_id,name) VALUES(2,'Foreign')");}catch(mysqli_sql_exception $e){$blocked=true;}plpt_assert($blocked,'SQL unit guard missing');
$_SESSION['active_unit_id']=1;unit_set_context($db,1);
$all=parent_letter_collect($db,['mode'=>'all']);plpt_assert(count($all['students'])>0&&$all['signatories'][1]['principal_name']==='Hairul Laka','All mode has no snapshot');
$key=unit_student_key($all['students'][0]);foreach(['single','selected'] as $mode){$selection=parent_letter_collect($db,['mode'=>$mode,'students'=>$key]);plpt_assert(count($selection['students'])===1&&$selection['signatories']===$all['signatories'],'Selection snapshot missing');}
$realClass=null;foreach($all['students'] as $row)if((int)($row['master_kelas_id']??0)>0){$realClass=(int)$row['master_kelas_id'];break;}
if($realClass){$class=parent_letter_collect($db,['mode'=>'class','kelas'=>'rombel:'.$realClass]);plpt_assert($class['students']&&$class['signatories']===$all['signatories'],'Class snapshot missing');}
$_SESSION['active_unit_id']=0;unit_set_context($db,0);
if($out=getenv('SPP_UI_OUTPUT')){
    require_pdf_library();foreach(['mixed'=>$mixedHtml,'snapshot'=>$html,'escaped'=>$current,'legacy'=>$old] as $file=>$content){$pdf=new \Dompdf\Dompdf();$pdf->loadHtml($content,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();file_put_contents($out.'/principal-'.$file.'.pdf',$pdf->output());}
}
echo "PASS: seeds, CRUD, validation, duplicate names, versions, units, SQL guards, replacement, soft delete, immutable drafts, mixed PDF, legacy compatibility, replay.\n";
