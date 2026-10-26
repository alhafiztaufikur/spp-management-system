<?php
/** Only a separately restored server; all test mutations are rolled back. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/spp_billing.php';
$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$f=json_decode(file_get_contents($root.'/tariff-fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
foreach([1,2,3] as $unit){
 $_SESSION=['admin_id'=>$f['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$u=$f['units'][$unit];$id=$u['new']['id'];$nis=$u['new']['nis'];$master=$u['master'];
 $context=static fn()=>sct_context_for_student($koneksi,$master,$id);
 $save=static function($start,$amount)use($context,$koneksi,$master,$id){$c=$context();return sct_save($koneksi,$master,$id,$start,$amount,'Penerapan tarif khusus melalui Master Penerbitan SPP.',$c['version']);};
 $support=static fn()=>[$koneksi->query('SELECT * FROM siswa_tahun_ajaran WHERE id='.(int)$u['new']['placement'])->fetch_assoc(),$koneksi->query("SELECT * FROM tagihan_komite WHERE no_induk='$nis'")->fetch_all(MYSQLI_ASSOC),$koneksi->query("SELECT * FROM bayar WHERE NO_INDUK='$nis'")->fetch_all(MYSQLI_ASSOC)];
 $before=$support();$koneksi->begin_transaction();
 try{
  $koneksi->query("UPDATE master_spp_tahun SET status='draft',published_at=NULL WHERE id=".(int)$master);
  $r=$save('2188-11','765432.10');$c=$context();
  $check($r['created']===8&&$r['updated']===0,'One action issues November to June');
  $check($c['periods'][0]['bill_id']===0&&$c['periods'][4]['before']===765432.10,'No earlier month is issued');
  $check($support()===$before,'Komite, start month, placement snapshots and cash remain identical');
  $check(spp_master_state($koneksi,$master)['status']==='published','First application publishes a draft year');
  $summary=sct_year_summary($koneksi,$master,$f['year']);$check($summary['pending']===0&&$summary['published']===4,'Summary includes newly published student');
  $audit=(int)$koneksi->query('SELECT COUNT(*) FROM spp_audit_log')->fetch_row()[0];$r=$save('2188-11','765432.10');
  $check($r['created']===0&&$r['updated']===0&&$r['rules']===0,'Equivalent retry is idempotent');
  $check((int)$koneksi->query('SELECT COUNT(*) FROM spp_audit_log')->fetch_row()[0]===$audit,'Equivalent retry adds no audit noise');
  $koneksi->query("DELETE FROM tagihan_spp WHERE no_induk='$nis' AND bulan='03' AND tahun='2189'");
  $r=$save('2188-11','765432.10');$check($r['rules']===0&&$r['created']===1,'Same tariff still repairs a missing eligible month');
  $save('2189-01','0.00');$c=$context();$check($c['periods'][6]['status']==='waived'&&$c['periods'][4]['before']===765432.10,'Zero waiver preserves earlier custom months');
  $koneksi->query("DELETE FROM tagihan_spp WHERE no_induk='$nis' AND bulan='04' AND tahun='2189'");
  $r=$save('2189-03',null);$c=$context();
  $check($r['created']===1&&$c['periods'][9]['before']===(float)($u['new']['base']-25000),'Reset applies and issues the default including its discount');
  $check($c['periods'][7]['before']===0.0,'Partial reset preserves February');
  $check($support()===$before,'Repeated apply/reset never creates Komite or cash');
  $auditRows=$koneksi->query("SELECT after_data FROM spp_audit_log WHERE no_induk='$nis' ORDER BY id DESC LIMIT 1")->fetch_assoc();$a=json_decode($auditRows['after_data'],true);
  $check(count(array_filter($a['outcomes'],static fn($p)=>$p['result']==='created'))===1,'Audit records the emitted bill outcome');
 }finally{$koneksi->rollback();sct_invalidate($koneksi);lb_cache($koneksi,true);}
}
// Fail publication after changing existing snapshots and rules, then prove full rollback.
class TariffFailingEmitter extends mysqli { public bool $fail=false; public function prepare(string $query): mysqli_stmt|false {if($this->fail&&str_contains($query,'INSERT IGNORE INTO tagihan_spp'))throw new RuntimeException('Injected publication failure');return parent::prepare($query);} }
$d=new TariffFailingEmitter(DB_HOST,DB_USER,DB_PASS,DB_NAME,(int)getenv('SPP_DB_PORT'));$d->set_charset('utf8mb4');unit_set_context($d,1);$_SESSION['active_unit_id']=1;$u=$f['units'][1];$nis=$u['custom']['nis'];$master=$u['master'];$id=$u['custom']['id'];
$before=$d->query("SELECT * FROM tagihan_spp WHERE no_induk='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC);$rules=$d->query("SELECT * FROM spp_tarif_siswa WHERE student_id=$id ORDER BY period")->fetch_all(MYSQLI_ASSOC);
$d->begin_transaction();try{
 $d->query("DELETE FROM tagihan_spp WHERE no_induk='$nis' AND bulan='09' AND tahun='2188'");
 $c=sct_context_for_student($d,$master,$id);$d->fail=true;
 try{sct_save($d,$master,$id,'2188-08','999999.00','Rollback test after publication failure',$c['version']);throw new LogicException('Failure injection did not run.');}catch(RuntimeException $e){$check($e->getMessage()==='Injected publication failure','Publication failure propagates');}
}finally{$d->rollback();sct_invalidate($d);}
$check($d->query("SELECT * FROM tagihan_spp WHERE no_induk='$nis' ORDER BY id")->fetch_all(MYSQLI_ASSOC)===$before,'Existing and missing bills restored after failure');
$check($d->query("SELECT * FROM spp_tarif_siswa WHERE student_id=$id ORDER BY period")->fetch_all(MYSQLI_ASSOC)===$rules,'Rules restored after failure');$d->close();
file_put_contents($root.'/tariff-apply-model-result.json',json_encode(['checks'=>$checks,'passed'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "PASS $checks one-action tariff application checks; all mutations rolled back.\n";
