<?php
/** Real correction/replay/cancellation service, inside a transaction that always rolls back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470)throw new RuntimeException('Owned Global Report instance required.');
require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/legacy_operational_corrections.php';
$checks=[];function gc_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;echo 'PASS '.$label.PHP_EOL;}
function gc_row(string $nis):array{global $koneksi;$f=report_filters($koneksi,['template'=>'status','kategori'=>'spp','q'=>$nis,'tahun_ajaran'=>'2026/2027','bulan_awal'=>'11','siswa_status'=>'all']);$r=report_build($koneksi,'status',$f);return $r['rows'][0];}
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>1];
foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
 $p=hp_one($koneksi,"SELECT p.*,s.NO_INDUK FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE p.cancelled=0 AND p.source_period='11 2026' AND p.items LIKE '%spp%' AND s.is_active=1 LIMIT 1");if(!$p)continue;
 $before=gc_row($p['NO_INDUK']);$items=json_decode($p['items'],true);$delta=0;
 foreach($items as &$item)if($item['category']==='spp'&&(float)$item['amount']>1){$item['amount']=number_format((float)$item['amount']-1.25,2,'.','');$delta=1.25;break;}unset($item);if(!$delta)continue;
 $input=['owner_id'=>(int)$p['owner_id'],'items'=>$items,'date'=>$p['source_date'],'period'=>$p['source_period'],'reason'=>'QA Global correction readback and rollback.','preview_expires'=>time()+300,'confirmation'=>'SIMPAN KOREKSI'];
 $koneksi->begin_transaction();
 try{
    $preview=lop_correction_preview($koneksi,$actor,$unit,'payment',(int)$p['id'],(int)$p['version'],$input);
    gc_check(gc_row($p['NO_INDUK'])['terbayar']===$before['terbayar'],"Unit $unit preview does not alter report amounts");
    $key=bin2hex(random_bytes(32));lop_correction_apply($koneksi,$actor,$unit,'payment',(int)$p['id'],(int)$p['version'],$input,$preview['digest'],$key,false);
    $after=gc_row($p['NO_INDUK']);gc_check(abs($after['terbayar']-($before['terbayar']-$delta))<.001,"Unit $unit approved correction invalidates report evidence immediately");
    $replay=lop_correction_apply($koneksi,$actor,$unit,'payment',(int)$p['id'],(int)$p['version'],$input,$preview['digest'],$key,false);gc_check($replay['replayed']&&gc_row($p['NO_INDUK'])['terbayar']===$after['terbayar'],"Unit $unit replay applies no second correction");
    $cancel=$input;$cancel['cancelled']=true;$cancel['reason']='QA Global cancellation readback and rollback.';$cancel['preview_expires']=time()+300;
    $preview=lop_correction_preview($koneksi,$actor,$unit,'payment',(int)$p['id'],(int)$p['version']+1,$cancel);
    lop_correction_apply($koneksi,$actor,$unit,'payment',(int)$p['id'],(int)$p['version']+1,$cancel,$preview['digest'],bin2hex(random_bytes(32)),false);
    $total=0;foreach($items as $item)if($item['category']==='spp')$total+=(float)$item['amount'];gc_check(abs(gc_row($p['NO_INDUK'])['terbayar']-($after['terbayar']-$total))<.001,"Unit $unit cancelled Legacy payment is no longer counted");
 }finally{$koneksi->rollback();lb_cache($koneksi,true);}
 gc_check(gc_row($p['NO_INDUK'])['terbayar']===$before['terbayar'],"Unit $unit rollback restores the original report and credit");
}
file_put_contents(getenv('SPP_GLOBAL_QA_DIR').'/correction-tests.json',json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT));echo 'OK: '.count($checks)." correction checks; all writes rolled back\n";
