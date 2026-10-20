<?php
/** Financial mutation acceptance, restricted to the owned restored audit server. */
if(PHP_SAPI!=='cli'||getenv('SPP_DB_PORT')!=='58360'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')throw new RuntimeException('Owned clone required.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/legacy_transactions.php';require_once __DIR__.'/../includes/legacy_operational_bank.php';require_once __DIR__.'/../includes/spp_billing.php';require_once __DIR__.'/../includes/komite_billing.php';require_once __DIR__.'/../includes/savings_book_print.php';
$db=$koneksi;if(!str_contains(str_replace('\\','/',$db->query('SELECT @@datadir')->fetch_row()[0]),'/import_audit_20261020/'))throw new RuntimeException('Unexpected instance.');
$checks=[];$fixture=[];
$assert=function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL;};
$reject=function(callable $action,string $label)use($assert){try{$action();}catch(DomainException $e){$assert(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
foreach([1,2,3] as $unit){
    $_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);
    $p=lf_one($db,"SELECT * FROM legacy_op_payments WHERE source_table='bayar' AND cancelled=0 AND JSON_LENGTH(items)>0 ORDER BY id LIMIT 1");$p['items']=json_decode($p['items'],true);$id=(int)$p['id'];
    $input=['date'=>$p['source_date'],'period'=>$p['source_period'],'method'=>$p['method'],'note'=>$p['note'],'items'=>$p['items'],'reason'=>'Pengujian koreksi pada salinan audit impor'];$input['items'][0]['amount']=lf_money(lf_cents($input['items'][0]['amount'])+13);$key=bin2hex(random_bytes(32));
    lt_submit($db,1,$unit,$id,(int)$p['version'],$input,$key);$edited=lop_record($db,'payment',$id);
    $assert(lf_cents($edited['total'])===lf_cents($p['total'])+13,"Unit $unit Legacy correction exact cents");
    $assert(lt_submit($db,1,$unit,$id,(int)$p['version'],$input,$key)['replayed'],"Unit $unit correction replay applies once");
    $reject(fn()=>lt_submit($db,1,$unit,$id,(int)$p['version'],$input,bin2hex(random_bytes(32))),"Unit $unit stale correction rejected");
    $input['items']=$p['items'];lt_submit($db,1,$unit,$id,(int)$edited['version'],$input,bin2hex(random_bytes(32)));
    $assert(lf_cents(lop_record($db,'payment',$id)['total'])===lf_cents($p['total']),"Unit $unit correction reverses to original amount");
    $latest=lop_record($db,'payment',$id);$cancel=$input+['cancelled'=>true];$cancel['preview_expires']=time()+300;$cancel['confirmation']='SIMPAN KOREKSI';$db->begin_transaction();
    try{$preview=lop_correction_preview($db,1,$unit,'payment',$id,(int)$latest['version'],$cancel);lop_correction_apply($db,1,$unit,'payment',$id,(int)$latest['version'],$cancel,$preview['digest'],bin2hex(random_bytes(32)),false);$assert((bool)lop_record($db,'payment',$id)['cancelled'],"Unit $unit cancellation changes working copy only");}finally{$db->rollback();}
    $owner=lf_one($db,'SELECT o.id FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE o.student_id IS NULL AND a.balance>100 LIMIT 1');$oid=(int)$owner['id'];$balance=lf_cents(lf_one($db,'SELECT balance FROM legacy_op_accounts WHERE owner_id=?',[$oid])['balance']);
    $bank=['amount'=>'123.13','direction'=>'in','date'=>date('Y-m-d H:i:s'),'note'=>'Salinan audit impor'];$bankKey=bin2hex(random_bytes(32));$saved=lop_bank_new($db,1,$unit,$oid,$bank,$bankKey);
    $assert(lop_bank_new($db,1,$unit,$oid,$bank,$bankKey)['id']===$saved['id'],"Unit $unit savings replay has one journal");
    $bank['direction']='out';lop_bank_new($db,1,$unit,$oid,$bank,bin2hex(random_bytes(32)));[$o,$book]=savings_book_owner_load($db,$oid);
    $assert($book['balance']===$balance,"Unit $unit deposit and withdrawal reconcile book");
    $bank['amount']='9999999999999.99';$reject(fn()=>lop_bank_new($db,1,$unit,$oid,$bank,bin2hex(random_bytes(32))),"Unit $unit insufficient savings rejected");
    // Create independent native pupils for the HTTP payment and savings lifecycle.
    $nis='99'.(string)$unit.substr(bin2hex(random_bytes(4)),0,6);$class=lf_one($db,"SELECT * FROM master_kelas WHERE jenis='reguler' AND tingkat>0 AND is_active=1 ORDER BY tingkat,id LIMIT 1");$year=lf_one($db,"SELECT y.id,y.label,m.id master_id FROM tahun_ajaran y JOIN master_spp_tahun m ON m.tahun_ajaran_id=y.id AND m.unit_id=y.unit_id WHERE y.status='published' ORDER BY y.label DESC LIMIT 1");
    $db->begin_transaction();try{
        lf_query($db,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,PSB) VALUES(?,?,?,?,10000,1000,100000)',[$nis,'Audit HTTP Unit '.$unit,$class['tingkat'],$class['id']]);$sid=(int)$db->insert_id;
        lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,10000,1000,'aktif')",[$year['id'],$nis,$class['tingkat'],$class['id'],$class['tingkat'].$class['kode_rombel']]);$placement=(int)$db->insert_id;
        komite_sync_placement($db,$placement);spp_publish_students($db,(int)$year['master_id'],[$nis]);$bill=lf_one($db,"SELECT * FROM tagihan_spp WHERE no_induk=? AND status='open' ORDER BY tahun,bulan LIMIT 1",[$nis]);$komite=lf_one($db,'SELECT nominal_tagihan FROM tagihan_komite WHERE no_induk=? AND bulan=? AND tahun=?',[$nis,$bill['bulan'],$bill['tahun']]);
        $db->commit();$fixture[$unit]=['student_id'=>$sid,'nis'=>$nis,'month'=>$bill['bulan'],'year'=>$bill['tahun'],'spp'=>$bill['nominal_tagihan'],'komite'=>$komite['nominal_tagihan'],'legacy_owner_id'=>$oid];
    }catch(Throwable $e){$db->rollback();throw $e;}
    $assert((float)$bill['nominal_tagihan']>0,"Unit $unit native published bill ready");
}
file_put_contents(getenv('SPP_IMPORT_MUTATION_RESULT'),json_encode(['checks'=>count($checks),'passed'=>$checks,'fixture'=>$fixture],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
