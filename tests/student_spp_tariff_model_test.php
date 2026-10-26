<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();
require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/spp_billing.php';
$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$f=json_decode(file_get_contents($root.'/tariff-fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$reject=static function(callable $run,string $label)use($check){try{$run();}catch(DomainException $e){$check(true,$label);return;}throw new RuntimeException($label);};
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$f['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);$u=$f['units'][$unit];$master=$u['master'];$id=$u['custom']['id'];$nis=$u['custom']['nis'];
    $context=static fn($student)=>sct_context_for_student($koneksi,$master,$student);
    $save=static function($student,$start,$amount)use($context,$koneksi,$master){$c=$context($student);return sct_save($koneksi,$master,$student,$start,$amount,'Pengujian tarif khusus terisolasi.',$c['version']);};
    $bill=static function($month,$year='2188')use($koneksi,$nis){$s=$koneksi->prepare('SELECT * FROM tagihan_spp WHERE no_induk=? AND bulan=? AND tahun=?');$s->bind_param('sss',$nis,$month,$year);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r;};
    $koneksi->begin_transaction();
    try{
        // Restore fixture defaults within this rollback, so browser tests may run first.
        $save($id,'2188-07',null);
        $original=$context($id);$paid=$bill('07');$cancelled=$bill('12');
        $r=$save($id,'2188-11','600000.50');$check($r['updated']===7&&$r['rules']===8&&$r['locked']===1,'Seven unpaid bills updated; cancelled December protected');
        $check($bill('07')===$paid&&$bill('12')===$cancelled,'Paid and cancelled snapshots unchanged');
        $check((float)$bill('10')['nominal_tagihan']===(float)($u['custom']['base']-25000),'Earlier October unchanged');
        $check((float)$bill('11')['nominal_tagihan']===600000.50&&(float)$bill('11')['potongan_nominal_snapshot']===0.0,'Custom final price is not discounted again');
        $reject(static fn()=>sct_save($koneksi,$master,$id,'2188-11','700000.00','Stale snapshot test',$original['version']),'Stale version rejected');
        $audit=(int)$koneksi->query('SELECT COUNT(*) FROM spp_audit_log')->fetch_row()[0];$r=$save($id,'2188-11','600000.50');
        $check($r['rules']===0&&(int)$koneksi->query('SELECT COUNT(*) FROM spp_audit_log')->fetch_row()[0]===$audit,'Equivalent save is a no-op');
        $save($id,'2189-01','0.00');$check($bill('01','2189')['status']==='waived'&&(float)$bill('01','2189')['nominal_tagihan']===0.0,'Explicit zero is a waiver');
        $check((float)$bill('11')['nominal_tagihan']===600000.50,'Later range edit preserves earlier custom month');
        $s=$koneksi->prepare('UPDATE siswa SET potongan_spp_nominal=30000 WHERE id=?');$s->bind_param('i',$id);$s->execute();$s->close();
        spp_sync_student_discount($koneksi,$nis,30000,$u['custom']['placement']);
        $check((float)$bill('11')['nominal_tagihan']===600000.50&&(float)$bill('01','2189')['nominal_tagihan']===0.0,'Discount synchronization preserves custom rates');
        $state=spp_master_state($koneksi,$master);$rates=spp_master_rates($koneksi,$master);$version=spp_master_rate_version($state,$rates);foreach($rates as &$value)$value=530000.0;unset($value);
        spp_master_correct_published_rates($koneksi,$master,$rates,$version,true);
        $check((float)$bill('11')['nominal_tagihan']===600000.50&&(float)$bill('01','2189')['nominal_tagihan']===0.0,'Master correction preserves custom periods');
        $control=$context($u['control']['id']);$check(abs($control['periods'][0]['before']-505000)<.001,'Non-custom student still follows normal master correction');
        $save($id,'2189-03',null);$check((float)$bill('03','2189')['nominal_tagihan']===500000.0&&(float)$bill('02','2189')['nominal_tagihan']===0.0,'Partial reset restores current default only from chosen month');
        $check(sct_rule($koneksi,$nis,'03','2189')===null&&sct_rule($koneksi,$nis,'02','2189')!==null,'Partial reset removes only target rules');
        $new=$u['new'];$r=$save($new['id'],'2188-09','123456.75');$check($r['updated']===0&&$r['created']===10,'Applying a rate publishes exactly September to June');
        $newContext=$context($new['id']);
        $check($newContext['periods'][0]['bill_id']===0&&$newContext['periods'][2]['before']===123456.75&&$newContext['periods'][2]['custom'],'Applied price is immediately issued without earlier months');
        $normal=spp_publish_students($koneksi,$master,[$new['nis']]);$check($normal['created']===2,'Normal publication still fills earlier months through the shared emitter');
        $again=spp_publish_students($koneksi,$master,[$new['nis']]);$check($again['created']===0,'Re-publication does not duplicate bills');
        $covered=$u['covered'];$r=$save($covered['id'],'2188-07','999999.00');$check($r['updated']===0&&$r['locked']===12,'PSB coverage stays protected');
        $reject(static fn()=>$save($id,'2187-11','1.00'),'Wrong academic period rejected');
        $other=$f['units'][$unit===1?2:1]['custom']['id'];$reject(static fn()=>$save($other,'2188-11','1.00'),'Cross-unit student rejected');
        $koneksi->query("UPDATE master_spp_tahun SET status='closed' WHERE id=".(int)$master);$c=$context($id);
        $reject(static fn()=>sct_save($koneksi,$master,$id,'2188-11','1.00','Closed year guard test',$c['version']),'Closed year rejected');
        $check($bill('07')===$paid&&$bill('12')===$cancelled,'Protected snapshots still exact after all edits');
    }finally{$koneksi->rollback();sct_invalidate($koneksi);lb_cache($koneksi,true);}
}
// The real Legacy tariff is retained as the reset source, and credits lock their months.
$_SESSION['active_unit_id']=1;unit_set_context($koneksi,1);
$legacy=$koneksi->query("SELECT s.id,s.NO_INDUK,m.id master_id FROM siswa s JOIN siswa_tahun_ajaran p ON p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id JOIN master_spp_tahun m ON m.tahun_ajaran_id=y.id AND m.unit_id=y.unit_id JOIN legacy_full_students fs ON fs.student_id=s.id AND fs.unit_id=s.unit_id WHERE y.label='2026/2027' AND p.spp_covered_by_psb=0 AND m.status<>'closed' LIMIT 1")->fetch_assoc();
if(!$legacy)throw new RuntimeException('Restored Legacy fixture required.');
$koneksi->begin_transaction();try{
    $c=sct_context_for_student($koneksi,(int)$legacy['master_id'],(int)$legacy['id']);$before=$c['periods'];
    $r=sct_save($koneksi,(int)$legacy['master_id'],(int)$legacy['id'],'2026-11','777777.00','Legacy credit protection test',$c['version']);
    $after=sct_context_for_student($koneksi,(int)$legacy['master_id'],(int)$legacy['id']);
    foreach($before as $i=>$p)if($p['locked']&&$p['bill_id'])$check($after['periods'][$i]['before']===$p['before'],'Legacy credited period retained');
    sct_save($koneksi,(int)$legacy['master_id'],(int)$legacy['id'],'2026-11',null,'Restore proven Legacy tariff',$after['version']);
    $reset=sct_context_for_student($koneksi,(int)$legacy['master_id'],(int)$legacy['id']);
    foreach($reset['periods'] as $p)if($p['period']>='2026-11'&&$p['bill_id']&&!$p['locked'])$check(abs($p['before']-$p['default']['net'])<.001,'Reset uses proven Legacy default');
}finally{$koneksi->rollback();sct_invalidate($koneksi);}
file_put_contents($root.'/tariff-model-result.json',json_encode(['checks'=>$checks,'passed'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS $checks isolated student tariff model checks; all model mutations rolled back.\n";
