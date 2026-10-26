<?php
/** Integration writes run only inside a rollback on a verified, separately restored server. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest();
require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/legacy_billing_reconstruction.php';
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/legacy_migration_verification.php';
$root=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));
$f=json_decode(file_get_contents($root.'/tariff-fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$_SESSION=['admin_id'=>$f['actor'],'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
$u=$f['units'][1];$nis=$u['custom']['nis'];lb_scope_student_read($koneksi,$nis);
$payload=spp_payment_payload($koneksi,0,$nis)[$nis]['tagihan'];
$nov=array_values(array_filter($payload,static fn($b)=>$b['bulan']==='11'&&$b['tahun']==='2188'));
$check(count($nov)===1&&$nov[0]['total']===600000.50,'Input Pembayaran reads the custom final bill');
$check(!array_filter($payload,static fn($b)=>$b['bulan']==='01'&&$b['tahun']==='2189'),'Zero waived bill is not requested for payment');
$filters=report_filters($koneksi,['template'=>'spp-tahunan','tahun_ajaran'=>$f['year'],'student_id'=>$u['custom']['id'],'siswa_status'=>'all','kategori'=>'spp']);
$rows=report_monthly_evidence($koneksi,'spp',report_academic_months($f['year']),$filters);
$check(count($rows)===1&&$rows[0]['periods']['2188-11']['bill']===600000.50,'Annual report reads custom tariff for the exact student');
$check($rows[0]['periods']['2189-01']['bill']===0.0,'Annual report preserves explicit zero');
$receipt=spp_payment_allocation_summary($koneksi,$u['payment']);
$check((float)$receipt['allocations'][0]['nominal_dari_bayar']===(float)($u['custom']['base']-25000),'Historical receipt retains original allocated amount');
unset($GLOBALS['lb_read_student'][$koneksi->thread_id]);lb_cache($koneksi,true);
$legacy=$koneksi->query("SELECT s.id,s.NO_INDUK,p.kelas_rombel_snapshot,m.id master_id FROM siswa s JOIN siswa_tahun_ajaran p ON p.no_induk=s.NO_INDUK AND p.unit_id=s.unit_id JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id JOIN master_spp_tahun m ON m.tahun_ajaran_id=y.id AND m.unit_id=y.unit_id JOIN legacy_full_students fs ON fs.student_id=s.id AND fs.unit_id=s.unit_id WHERE y.label='2026/2027' AND p.spp_covered_by_psb=0 AND m.status='published' LIMIT 1")->fetch_assoc();
if(!$legacy)throw new RuntimeException('Restored Legacy fixture required.');
$before=lmr_snapshot($koneksi,1);$koneksi->begin_transaction();
try{
    $context=sct_context_for_student($koneksi,(int)$legacy['master_id'],(int)$legacy['id']);$plan=lb_monthly_plan($koneksi,$legacy['NO_INDUK']);$candidate=null;
    foreach($context['periods'] as $p){$source=$plan['positions']['spp|'.$p['period']]??null;
        if($p['period']>='2026-11'&&!$p['locked']&&$p['bill_id']&&$source&&$source['class']===$legacy['kelas_rombel_snapshot']){$candidate=$p;break;}
    }
    if(!$candidate)throw new RuntimeException('An unpaid, source-proven Legacy month is required.');
    sct_save($koneksi,(int)$legacy['master_id'],(int)$legacy['id'],$candidate['period'],'1111111.25','Reconciliation integration on restored clone',$context['version']);
    // Simulate reconstruction of one missing unpaid bill, never a paid or archived record.
    $s=$koneksi->prepare("DELETE FROM tagihan_spp WHERE id=? AND status='open' AND NOT EXISTS(SELECT 1 FROM spp_alokasi WHERE tagihan_spp_id=tagihan_spp.id)");
    $s->bind_param('i',$candidate['bill_id']);$s->execute();$check($s->affected_rows===1,'Only the unpaid clone fixture bill is removed');$s->close();
    $koneksi->query('SET @legacy_full_apply=1');$result=lbr_reconcile($koneksi,(int)$f['actor']);
    $current=sct_context_for_student($koneksi,(int)$legacy['master_id'],(int)$legacy['id']);
    $rebuilt=array_values(array_filter($current['periods'],static fn($p)=>$p['period']===$candidate['period']))[0];
    $check($rebuilt['bill_id']>0&&$rebuilt['before']===1111111.25&&$rebuilt['custom'],'Official reconstruction engine reads the same custom period tariff');
    foreach($context['periods'] as $i=>$p)if($p['locked']&&$p['bill_id'])$check($current['periods'][$i]['before']===$p['before'],'Reconciliation preserves credited/locked snapshots');
    $check(lmr_snapshot($koneksi,1)===$before,'Reconciliation preserves identity, archive, cash and savings');
}finally{$koneksi->rollback();$koneksi->query('SET @legacy_full_apply=0');sct_invalidate($koneksi);lb_cache($koneksi,true);}
file_put_contents($root.'/tariff-integration-result.json',json_encode(['checks'=>$checks,'passed'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS $checks payment/report/receipt/Legacy integration checks; all integration mutations rolled back.\n";
