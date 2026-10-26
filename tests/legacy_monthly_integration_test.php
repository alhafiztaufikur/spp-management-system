<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||(int)getenv('SPP_DB_PORT')===3306)throw new RuntimeException('Isolated restored server required.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>2];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_billing_reconstruction.php';require_once __DIR__.'/../includes/legacy_transactions.php';require_once __DIR__.'/../includes/komite_billing.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/tagihan_sekali.php';
$db=$koneksi;require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($db);
$checks=[];function icheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
unit_set_context($db,2);$nis='252607034';$s=hp_one($db,'SELECT * FROM siswa WHERE NO_INDUK=?',[$nis]);
icheck((float)$s['PSB']===14295000.0&&(float)$s['potongan_psb_nominal']===6900000.0&&$s['KELAS']==='8','Raisa PSB gross/discount; class retained');
$psb=one_time_fee_status($db,$nis)['psb'];icheck($psb['total']===7395000.0&&$psb['paid']===7395000.0&&$psb['remaining']==0.0,'PSB cumulative paid counted once');
foreach(['07','08','09'] as $month){$st=spp_published_period_status($db,$nis,$month,'2026');$k=komite_bill($db,$nis,$month,'2026');icheck($st['status']==='already_paid'&&$st['selected']['paid']===327500.0&&$k['remaining']==0.0&&$k['paid']===15000.0,"Raisa $month SPP/Komite paid by period");}
$st=spp_published_period_status($db,$nis,'10','2026');$k=komite_bill($db,$nis,'10','2026');icheck($st['status']==='payable'&&$st['selected']['remaining']===327500.0&&$k['remaining']===15000.0,'October due 327500 plus 15000');
$f=report_filters($db,['template'=>'spp-tahunan','tahun_ajaran'=>'2026/2027','siswa_status'=>'active','kategori'=>'spp']);
$psbFilters=$f;$psbFilters['kategori']='psb';$psbRows=report_annual_evidence($db,$psbFilters)['rows'];$psbRow=array_values(array_filter($psbRows,static fn($r)=>$r['nis']==='252607034'))[0];
icheck($psbRow['tagihan']===$psb['total']&&$psbRow['terbayar']===$psb['paid']&&$psbRow['sisa']===$psb['remaining'],'Global PSB uses the same canonical position as cashier');
$rows=report_monthly_evidence($db,'spp',[[7,2026],[8,2026],[9,2026],[10,2026]],$f);$raisa=array_values(array_filter($rows,static fn($r)=>$r['nis']==='252607034'))[0];
icheck($raisa['periods']['2026-07']['remaining']==0.0&&$raisa['periods']['2026-10']['bill']===327500.0&&$raisa['periods']['2026-10']['remaining']===327500.0,'Global SPP report equals cashier');
$rows=report_monthly_evidence($db,'komite',[[7,2026],[10,2026]],$f);$r=array_values(array_filter($rows,static fn($r)=>$r['nis']==='252607034'))[0];icheck($r['periods']['2026-07']['remaining']==0.0&&$r['periods']['2026-10']['remaining']===15000.0,'Global Komite report equals cashier');
icheck(lop_tariff($db,$nis,'2025/2026')===297500.0&&lop_tariff($db,$nis,'2026/2027')===327500.0,'Historical rate never replaced by current rate');
$p=hp_one($db,"SELECT p.* FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id WHERE o.student_id=? AND p.source_period='09 2026' AND p.cancelled=0",[$s['id']]);
if(!$p)foreach(hp_rows($db,'SELECT * FROM legacy_op_payments WHERE owner_id=? AND cancelled=0',[lb_student($db,$nis)['id']]) as $candidate)if(lb_payment_month($candidate['source_period'])==='2026-09'){$p=$candidate;break;}
$db->begin_transaction();try{
    $placement=hp_one($db,'SELECT p.*,m.id master_id FROM siswa_tahun_ajaran p JOIN master_spp_tahun m ON m.tahun_ajaran_id=p.tahun_ajaran_id AND m.unit_id=p.unit_id WHERE p.no_induk=? AND p.status=\'aktif\' LIMIT 1',[$nis]);
    komite_set_start_month($db,(int)$placement['id'],'10');icheck(hp_one($db,'SELECT komite_mulai_bulan FROM siswa_tahun_ajaran WHERE id=?',[$placement['id']])['komite_mulai_bulan']==='10','Existing start month edit succeeds and preserves paid history');
    $first=spp_publish_students($db,(int)$placement['master_id'],[$nis],[$nis=>'10']);$second=spp_publish_students($db,(int)$placement['master_id'],[$nis],[$nis=>'10']);icheck($first['created']===0&&$second['created']===0,'Existing publication keeps prior bills, no duplicates');
    $kb=komite_validate_amount($db,$nis,'10','2026',15000,true);icheck((float)$kb['remaining']===15000.0,'October payment pair accepted');
    lf_query($db,'UPDATE tagihan_komite SET nominal_tagihan=30000 WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,'07','2026']);
    $priorRejected=false;try{komite_validate_amount($db,$nis,'10','2026',15000,true);}catch(SppPaymentException $e){$priorRejected=true;}icheck($priorRejected,'Older unpaid Komite blocks a later pair even when older SPP is paid');
    lf_query($db,'UPDATE tagihan_komite SET nominal_tagihan=15000 WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,'07','2026']);
    $rejected=false;try{komite_validate_amount($db,$nis,'10','2026',0,true);}catch(Throwable $e){$rejected=true;}icheck($rejected,'SPP without Komite rejected');
    $allocation=spp_allocate_payment($db,$nis,null,'10','2026',327500,date('Y-m-d H:i:s'),'Tunai','1');icheck($allocation['cash_allocated']===327500.0,'New October SPP allocates actual new money');
    $rejected=false;try{spp_allocate_payment($db,$nis,null,'10','2026',327500,date('Y-m-d H:i:s'),'Tunai','1');}catch(Throwable $e){$rejected=true;}icheck($rejected,'Duplicate SPP payment rejected');
}finally{$db->rollback();lb_cache($db,true);}
$db->begin_transaction();try{
    $record=lop_record($db,'payment',(int)$p['id']);$input=['date'=>$record['source_date'],'period'=>$record['source_period'],'items'=>$record['items'],'cancelled'=>true,'reason'=>'Isolated monthly cancellation regression','confirmation'=>'SIMPAN KOREKSI','preview_expires'=>time()+300];$preview=lop_correction_preview($db,1,2,'payment',(int)$p['id'],(int)$p['version'],$input);lop_correction_apply($db,1,2,'payment',(int)$p['id'],(int)$p['version'],$input,$preview['digest'],bin2hex(random_bytes(32)),false);
    $st=spp_published_period_status($db,$nis,'09','2026');$k=komite_bill($db,$nis,'09','2026');icheck($st['selected']['remaining']===327500.0&&$k['remaining']===15000.0,'Legacy cancellation restores both components');
    $o=hp_one($db,"SELECT remaining FROM legacy_op_obligations WHERE owner_id=? AND category='komite' AND period='2026-09'",[$p['owner_id']]);icheck((float)$o['remaining']===15000.0,'Stored obligation follows cancellation');
}finally{$db->rollback();lb_cache($db,true);}
icheck((int)$db->query('SELECT COUNT(*) FROM (SELECT unit_id,NO_INDUK FROM siswa_data GROUP BY unit_id,NO_INDUK HAVING COUNT(*)>1) d')->fetch_row()[0]===0,'No duplicate NIS inside a unit');
$db->begin_transaction();try{
    $past=hp_one($db,"SELECT p.*,m.id master_id FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id JOIN master_spp_tahun m ON m.tahun_ajaran_id=y.id AND m.unit_id=y.unit_id WHERE p.no_induk=? AND y.label='2025/2026'",[$nis]);
    spp_publish_students($db,(int)$past['master_id'],[$nis],[$nis=>'07']);$pastSpp=hp_one($db,"SELECT nominal_tagihan FROM tagihan_spp WHERE no_induk=? AND bulan='07' AND tahun='2025'",[$nis]);
    icheck((float)$pastSpp['nominal_tagihan']===297500.0&&komite_bill($db,$nis,'07','2025')['remaining']===15000.0,'Manual historical fill uses that year SPP and Komite rates');
}finally{$db->rollback();lb_cache($db,true);}
$db->begin_transaction();try{
    $current=hp_one($db,"SELECT id FROM siswa_tahun_ajaran WHERE no_induk=? AND status='aktif'",[$nis]);
    komite_sync_student_rate($db,$nis,10000,(int)$current['id']);lf_query($db,"DELETE FROM tagihan_komite WHERE no_induk=? AND bulan='10' AND tahun='2026'",[$nis]);komite_sync_placement($db,(int)$current['id']);
    icheck(komite_bill($db,$nis,'10','2026')['remaining']===10000.0&&komite_bill($db,$nis,'07','2026')['paid']===15000.0,'Explicit current Komite correction fills gaps and preserves paid source periods');
}finally{$db->rollback();lb_cache($db,true);}
$db->begin_transaction();try{
    $source=lb_student($db,$nis);$future=['year'=>'2027/2028','class'=>'8A','period'=>'2027-07','principal'=>32750000];
    icheck(lbr_placement($db,$source,$future)===null,'Future-year payment does not invent future placement');
    // A fresh source migration has individual Legacy tariffs, not an approved
    // class tariff. Seed the explicit CRUD tariff fixture inside this rollback.
    $fixtureMaster=spp_master_ensure_year($db,'2026/2027');$fixtureTariff=lop_tariff($db,$nis,'2026/2027');
    if($fixtureTariff===null||$fixtureTariff<=0)throw new RuntimeException('Known student tariff required for isolated CRUD fixture.');
    foreach(spp_master_rates($db,(int)$fixtureMaster['id']) as $level=>$amount)if($amount<=0)lf_query($db,'INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar) VALUES(?,?,?) ON DUPLICATE KEY UPDATE nominal_dasar=VALUES(nominal_dasar)',[$fixtureMaster['id'],$level,$fixtureTariff]);
    $class=class_find($db,(int)$s['master_kelas_id']);$rate=spp_current_effective_rate($db,(string)$class['tingkat'],0,'2026/2027');
    lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,?,15000,1)',['9999999998','Guru Uji Penetapan Manual',(string)$class['tingkat'],$class['id'],$rate['net']]);
    $sync=null;$placement=class_sync_student_current_year($db,'9999999998',(int)$class['id'],(float)$rate['net'],15000,true,$sync);$master=hp_one($db,'SELECT m.id FROM master_spp_tahun m JOIN siswa_tahun_ajaran p ON p.tahun_ajaran_id=m.tahun_ajaran_id AND p.unit_id=m.unit_id WHERE p.id=?',[$placement]);
    $publication=spp_publish_students($db,(int)$master['id'],['9999999998'],['9999999998'=>'10']);
    icheck($publication['created']===9&&komite_bill($db,'9999999998','10','2026')['remaining']===15000.0,'Explicit CRUD assignment allowed irrespective of teacher label');
}finally{$db->rollback();lb_cache($db,true);}
foreach([1,2,3] as $u){$_SESSION['active_unit_id']=$u;unit_set_context($db,$u);$data=lb_cache($db,true);$found=false;
    foreach($data['students'] as $student){foreach($data['paid'][$student['id']]['spp']??[] as $period=>$credit){if($period<='2026-10'||!isset($data['native_monthly'][$u.'|'.$student['NO_INDUK']]['spp|'.$period]))continue;[$year,$month]=explode('-',$period);$bill=$data['native_monthly'][$u.'|'.$student['NO_INDUK']]['spp|'.$period];$status=spp_published_period_status($db,$student['NO_INDUK'],$month,$year);icheck(abs($status['selected']['paid']-((float)$bill['paid']+$credit/100))<.001,"Unit $u future-period credit counted once");$found=true;break 2;}}
    icheck($found,"Unit $u future payment fixture retained");
}
echo json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
