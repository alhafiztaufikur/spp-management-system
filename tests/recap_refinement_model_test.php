<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/general_report_sections.php';
require_once __DIR__.'/../includes/legacy_operational_corrections.php';
$qa=(string)getenv('SPP_RECAP_QA');$fixture=json_decode(file_get_contents($qa.'/fixture.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;function rr_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rr_denied(callable $call,string $label):void{try{$call();}catch(DomainException $e){rr_check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>1];$_GET=[];
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{
 foreach([1,2,3,0] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($koneksi,$unit);$_GET=[];
  $sections=general_sections($koneksi,['*'],'2026-10-01','2026-10-22','','nama',10,0,[]);
  rr_check(array_column($sections,'type')===['semua','belum_spp','belum_komite','belum_du','belum_biaya_lain'],'All five sections present');
  foreach($sections as $section){rr_check(count($section['rows'])<=10,'Bounded rows per section');if($section['type']!=='semua')rr_check(!empty($section['notes']),'Coverage explained');}
  $next=general_sections($koneksi,['*'],'2026-10-01','2026-10-22','','nama',10,0,['semua'=>2]);
  rr_check($next[0]['page']===2,'Only payment page changes');foreach(array_slice($sections,1) as $i=>$section)rr_check($section===$next[$i+1],'Other section is identical');
  $outside=general_sections($koneksi,['belum_du'],'2026-10-01','2026-10-22','','nama',10,0,['belum_du'=>999999])[0];rr_check($outside['page']===$outside['total_pages'],'Page clamped before reading detail');
  foreach(['belum_spp','belum_komite'] as $type){$section=general_sections($koneksi,[$type],'2026-10-01','2026-10-22','','nama',10,0,[])[0];rr_check((bool)preg_match('/belum diterbitkan|bukti belum lengkap|pokok belum diketahui/',implode(' ',$section['notes'])),'No native bill does not mean settled');}
  $_GET=['all_periods'=>'1'];$summary=general_payment_summary($koneksi,'2026-10-01','2026-10-22');
  $expected=hp_one($koneksi,"SELECT SUM(n) n,SUM(total) total FROM (SELECT COUNT(*) n,COALESCE(SUM(total_jumlah),0) total FROM bayar UNION ALL SELECT COUNT(*),COALESCE(SUM(p.total),0) FROM legacy_full_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' UNION ALL SELECT COUNT(*),COALESCE(SUM(p.total),0) FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0) x");
  rr_check((int)$summary['jml_tx']===(int)$expected['n'],'All historical/native records counted');rr_check(lf_cents((string)$summary['total'])===lf_cents((string)$expected['total']),'All-period cash exact source cents');
  $_GET=['include_undated'=>'1'];$with=general_payment_summary($koneksi,'2026-10-01','2026-10-22');$_GET=[];$without=general_payment_summary($koneksi,'2026-10-01','2026-10-22');rr_check($with['jml_tx']>$without['jml_tx'],'Undated proven period is accessible');
 }
 // The indexed period reader must return the same source cents as the bulk reader.
 foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$bulk=report_legacy_periods($koneksi);
  foreach(['2026-10','2026-11'] as $period){$scoped=report_legacy_periods($koneksi,[$period]);$amounts=static function(array $source)use($period):array{$result=[];foreach($source['payments'] as $nis=>$categories)foreach(['spp','komite'] as $category)if(isset($categories[$category][$period]))$result[$nis.'|'.$category]=$categories[$category][$period]['paid'];ksort($result);return $result;};rr_check($amounts($bulk)===$amounts($scoped),'Period-limited reader equals bulk source cents');}
 }
 foreach(['0'=>'0.00','100.000'=>'100000.00','100.000,13'=>'100000.13','100000.13'=>'100000.13'] as $raw=>$canonical)rr_check(lop_correction_amount((string)$raw)===$canonical,'Canonical money adapter');
 foreach(['-1','100000.131','1.0000','abc'] as $raw)rr_denied(static fn()=>lop_correction_amount($raw),'Invalid money retained/rejected');
}finally{$koneksi->rollback();}
// Exercise the actual journal service on the disposable clone, rolling back all changes.
foreach([1,2,3] as $unit){
 $_GET=[];$_SESSION=['admin_role'=>'admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$actor=$fixture['actors']['admin'][$unit];$id=(int)$fixture['samples'][$unit]['id'];
 $koneksi->begin_transaction();
 try{
  $before=lop_record($koneksi,'journal',$id);$input=['owner_id'=>(int)$before['owner_id'],'date'=>$before['source_date'],'incoming'=>lf_money(lf_cents($before['incoming'])+13),'outgoing'=>$before['outgoing'],'cancelled'=>(bool)$before['cancelled'],'note'=>'QA correction with exact cents','reason'=>'Pengujian koreksi Administrator pada salinan terisolasi.','preview_expires'=>time()+300];
  $preview=lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],$input);
  rr_check($preview['after']['incoming']===$input['incoming'],'Admin previews exact cents');
  rr_denied(static fn()=>lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],array_replace($input,['owner_id'=>0])),'Admin cannot transfer owner');
  rr_denied(static fn()=>lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],array_replace($input,['cancelled'=>true])),'Admin cannot cancel');
  rr_denied(static fn()=>lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],array_replace($input,['preview_expires'=>time()-1])),'Expired preview');
  rr_denied(static fn()=>lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],array_replace($input,['incoming'=>'0.00','outgoing'=>'9999999999999.99'])),'Negative balance rejected');
  rr_check(journal_time_precision($koneksi,-1000000000-$id,'legacy',$before['source_date'])==='date','Archive midnight padding is date-only');
  rr_denied(static fn()=>lop_correction_actor($koneksi,$actor,$unit,'payment'),'Payment gate unchanged');
  rr_denied(static fn()=>lf_actor($koneksi,$actor,$unit,true),'Archive write gate unchanged');
  $koneksi->query('SAVEPOINT balance_changed_since_preview');
  lf_query($koneksi,'UPDATE legacy_op_accounts SET balance=balance+0.01 WHERE owner_id=?',[$before['owner_id']]);
  lf_query($koneksi,'UPDATE tabungan t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id JOIN legacy_op_owners o ON o.student_id=s.id AND o.unit_id=s.unit_id SET t.SALDO=t.SALDO+0.01 WHERE o.id=?',[$before['owner_id']]);
  rr_denied(static fn()=>lop_correction_apply($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],array_replace($input,['confirmation'=>'SIMPAN KOREKSI']),$preview['digest'],bin2hex(random_bytes(32)),false),'Balance changed after preview rejects the old digest');
  $koneksi->query('ROLLBACK TO SAVEPOINT balance_changed_since_preview');
  $input['confirmation']='SIMPAN KOREKSI';$key=bin2hex(random_bytes(32));
  lop_correction_apply($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],$input,$preview['digest'],$key,false);
  $after=lop_record($koneksi,'journal',$id);rr_check($after['incoming']===$input['incoming'],'Admin saves journal');rr_check($after['source_operator']===$before['source_operator'],'Original operator retained');
  rr_check(lop_correction_apply($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],$input,$preview['digest'],$key,false)['replayed'],'Replay is idempotent');
  rr_denied(static fn()=>lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],$input),'Stale version rejected');
  $audit=lf_one($koneksi,"SELECT actor_id,reason FROM legacy_op_audit WHERE request_key=?",[$key]);rr_check((int)$audit['actor_id']===$actor&&$audit['reason']===$input['reason'],'Actor and reason audited');
  $clock=array_replace($input,['time_precision'=>'datetime']);$clockPreview=lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$after['version'],$clock);lop_correction_apply($koneksi,$actor,$unit,'journal',$id,(int)$after['version'],$clock,$clockPreview['digest'],bin2hex(random_bytes(32)),false);
  rr_check(journal_time_precision($koneksi,-1000000000-$id,'legacy',$before['source_date'])==='datetime','Explicit known midnight is preserved');
  $other=$unit===1?2:1;unit_set_context($koneksi,$other);rr_denied(static fn()=>lop_correction_actor($koneksi,$actor,$other,'journal'),'Cross-unit administrator denied');
 }finally{$koneksi->rollback();unit_set_context($koneksi,$unit);}
}
// The same policy must work for alumni and non-students without matching names.
foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);
 foreach(['alumni'=>"mk.legacy_kind='alumni'",'nonstudent'=>'o.student_id IS NULL'] as $ownerKind=>$where){
  $sample=lf_one($koneksi,"SELECT j.id FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id WHERE j.origin='legacy' AND j.cancelled=0 AND j.incoming>0 AND o.state='ready' AND $where ORDER BY j.id LIMIT 1");
  rr_check((bool)$sample,"Source $ownerKind journal available in unit $unit");
  foreach(['admin','super_admin'] as $role){$_SESSION=['admin_role'=>$role,'active_unit_id'=>$unit];$actor=$fixture['actors'][$role][$unit];$id=(int)$sample['id'];$koneksi->begin_transaction();
   try{$before=lop_record($koneksi,'journal',$id);$input=['owner_id'=>(int)$before['owner_id'],'date'=>$before['source_date'],'incoming'=>lf_money(lf_cents($before['incoming'])+13),'outgoing'=>$before['outgoing'],'cancelled'=>false,'note'=>$before['note'],'reason'=>'Pengujian cakupan pemilik dan peran pada salinan.','preview_expires'=>time()+300];$preview=lop_correction_preview($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],$input);$input['confirmation']='SIMPAN KOREKSI';lop_correction_apply($koneksi,$actor,$unit,'journal',$id,(int)$before['version'],$input,$preview['digest'],bin2hex(random_bytes(32)),false);$after=lop_record($koneksi,'journal',$id);rr_check($after['incoming']===$input['incoming']&&$after['owner_id']===$before['owner_id'],"$role $ownerKind journal corrects exact identity and cents");}finally{$koneksi->rollback();}
  }
 }
}
echo "PASS $checks recap model checks: five sections, independent pages, source totals, undated evidence, money, direct Administrator journal corrections and access boundaries.\n";
