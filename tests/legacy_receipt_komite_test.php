<?php
/** Source-backed regression; mutations are restricted to an owned clone and rolled back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/payment_obligations.php';
require_once __DIR__.'/../includes/payment_history.php';
require_once __DIR__.'/../includes/receipt_balances.php';
require_once __DIR__.'/../includes/receipt_print.php';
require_once __DIR__.'/../includes/transaction_report.php';
$db=$koneksi;$checks=0;$report=['units'=>[]];
function rkc_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rkc_komite_rows(array $summary): array {return array_values(array_filter($summary['rows'],static fn($r)=>$r['kind']==='komite'));}
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>2];unit_set_context($db,2);
$nis='252607034';$raisa=payment_obligations($db,$nis);
rkc_check(!$raisa['rows']&&$raisa['oldest_period']===null,'Raisa has no false arrears or old blocking period');
$periods=hp_rows($db,"SELECT bulan,tahun FROM tagihan_komite WHERE no_induk=? AND CONCAT(tahun,'-',LPAD(bulan,2,'0')) BETWEEN '2025-08' AND '2026-09' ORDER BY tahun,bulan",[$nis]);
rkc_check(count($periods)===14,'All 14 Raisa periods are present');
foreach($periods as $p){$bill=komite_bill($db,$nis,$p['bulan'],$p['tahun']);rkc_check((float)$bill['remaining']===0.0&&$bill['legacy_paid']===15000.0,'Raisa historic Komite already settled');}
rkc_check(spp_published_period_status($db,$nis,'10','2026')['selected']['remaining']===327500.0&&komite_bill($db,$nis,'10','2026')['remaining']===15000.0,'October SPP and Komite still payable');
// Compare the popup with the existing form payload on every student having historical bills.
foreach([1,2,3] as $unit){
    unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;unset($GLOBALS['lb_read_student'][$db->thread_id]);lb_cache($db,true);
    $students=hp_rows($db,"SELECT DISTINCT s.NO_INDUK FROM siswa s JOIN tagihan_komite k ON k.no_induk=s.NO_INDUK AND k.unit_id=s.unit_id WHERE k.status='open' ORDER BY s.NO_INDUK");
    $stats=['students'=>0,'periods'=>0,'real_arrears'=>0,'previous_false_rows'=>0];
    foreach($students as $student){$n=$student['NO_INDUK'];$payload=komite_payment_payload($db,0,$n)[$n]??[];$expected=[];
        foreach($payload as $key=>$position){[$month,$year]=explode('-',$key);if((int)$year*100+(int)$month>=(int)date('Ym'))continue;
            $stats['periods']++;$remaining=max(0,$position['total']-$position['paid']);
            if($remaining>.001)$expected['Komite '.spp_month_label($month).' '.$year]=$remaining;
            elseif(lb_monthly_credit($db,$n,'komite',$month,$year)>.001)$stats['previous_false_rows']++;
        }
        $summary=payment_obligations($db,$n);$actual=[];foreach(rkc_komite_rows($summary) as $r)$actual[$r['label']]=$r['remaining'];ksort($actual);ksort($expected);
        rkc_check($actual===$expected,'Popup differs from canonical Komite position in unit '.$unit.' NIS '.$n);$stats['students']++;$stats['real_arrears']+=count($actual);
    }$report['units'][$unit]=$stats;fwrite(STDERR,'Verified unit '.$unit.': '.json_encode($stats).PHP_EOL);
}
unit_set_context($db,2);$_SESSION['active_unit_id']=2;lb_cache($db,true);
$payment=hp_one($db,"SELECT p.id FROM legacy_op_payments p JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(source_id VARCHAR(40) PATH '$.migration.source_record_id')) j WHERE j.source_id='55313' LIMIT 1");
rkc_check((bool)$payment,'Source receipt 55313 available');$virtual=-1000000000-(int)$payment['id'];$model=payment_history_model($db,$virtual,false);$record=$model['legacy_record'];
rkc_check($model['components']===[['Formulir Pendaftaran','75000.00']],'Receipt item label contains only its name');
$trace=$record['items'][0]['source_trace'];rkc_check($trace['transaction_id']==='55313'&&$trace['code']==='0018'&&$trace['slot']===1&&$trace['master_id']>0,'Transaction source trace survives presentation change');
$remaining=payment_receipt_remaining($db,$model['payment']);$du=array_values(array_filter($remaining,fn($r)=>$r[0]==='Sisa DU'))[0];rkc_check($du[1]===null&&$du[2]==='-','Unknown DU remains null and displays dash');
ob_start();payment_history_render($model,[]);$html=ob_get_clean();rkc_check(!str_contains($html,'Legacy #55313')&&!str_contains($html,'kode 0018')&&str_contains($html,'Formulir Pendaftaran'),'History detail has clean labels');
$export=transaction_report_components($db,[['id'=>$virtual,'unit_id'=>2]]);rkc_check(str_contains($export[$virtual],'Formulir Pendaftaran')&&!str_contains($export[$virtual],'Legacy #55313')&&!str_contains($export[$virtual],'kode 0018'),'Transaction export has clean labels');
$receipt=['payment'=>$model['payment'],'school'=>'SMP Mutiara Hikmah','primary'=>$model['components'],'remaining'=>$remaining,'other'=>[],'period'=>'10 2026','words'=>receipt_money_words(75000),'date'=>'14-10-2026','signer'=>'2009'];
foreach(['single'=>[$receipt],'annual'=>array_fill(0,12,$receipt)] as $label=>$documents){ob_start();receipt_print_document($documents,'Test','Test',true);$html=ob_get_clean();rkc_check(!str_contains($html,'Belum dapat ditentukan')&&!str_contains($html,'Legacy #55313')&&str_contains($html,'class="amount">-</td>'),'Clean unknown DU on '.$label.' receipt');}
// Genuine outstanding amounts still appear; mixed native/Legacy credit is counted once.
$db->begin_transaction();try{
    $bill=komite_bill($db,$nis,'09','2026');lf_query($db,'UPDATE tagihan_komite SET nominal_tagihan=30000 WHERE id=?',[$bill['id']]);
    $rows=rkc_komite_rows(payment_obligations($db,$nis));rkc_check(count($rows)===1&&$rows[0]['remaining']===15000.0,'Real remainder retained after Legacy credit');
    $oldest=payment_obligations($db,$nis)['oldest_period'];rkc_check($oldest['bulan']==='09'&&$oldest['tahun']==='2026','Oldest period recalculated from real remaining bills');
    lf_query($db,"INSERT INTO bayar(unit_id,NO_INDUK,TGL_BYR,BULAN,TAHUN,user_id,U_KOMITE,total_jumlah) VALUES(2,?,'2026-10-26','September','2026','1',15000,15000)",[$nis]);$native=(int)$db->insert_id;
    komite_save_payment($db,$native,$bill,15000);rkc_check(!rkc_komite_rows(payment_obligations($db,$nis)),'Native plus Legacy settles once');
    lf_query($db,'DELETE FROM bayar_komite WHERE bayar_id=?',[$native]);rkc_check(rkc_komite_rows(payment_obligations($db,$nis))[0]['remaining']===15000.0,'Removing native credit restores the actual remainder');
}finally{$db->rollback();lb_cache($db,true);}
$db->begin_transaction();try{
    $owner=lb_student($db,$nis);$p=null;foreach(hp_rows($db,'SELECT * FROM legacy_op_payments WHERE owner_id=? AND cancelled=0',[$owner['id']]) as $candidate)if(lb_payment_month($candidate['source_period'])==='2026-09'){$p=$candidate;break;}
    rkc_check((bool)$p,'September Legacy payment located');$r=lop_record($db,'payment',(int)$p['id']);$input=['date'=>$r['source_date'],'period'=>$r['source_period'],'items'=>$r['items'],'cancelled'=>true,'reason'=>'Isolated popup cancellation regression','confirmation'=>'SIMPAN KOREKSI','preview_expires'=>time()+300];$preview=lop_correction_preview($db,1,2,'payment',(int)$p['id'],(int)$p['version'],$input);lop_correction_apply($db,1,2,'payment',(int)$p['id'],(int)$p['version'],$input,$preview['digest'],bin2hex(random_bytes(32)),false);
    $rows=rkc_komite_rows(payment_obligations($db,$nis));rkc_check(count($rows)===1&&$rows[0]['label']==='Komite September 2026'&&$rows[0]['remaining']===15000.0,'Authorized cancellation updates popup from the same credit source');
}finally{$db->rollback();lb_cache($db,true);}
foreach([1,2,3] as $unit){unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;lb_cache($db,true);$data=lb_cache($db);$found=false;
    foreach($data['students'] as $student)foreach($data['paid'][$student['id']]['komite']??[] as $period=>$credit)if($period>date('Y-m')&&$credit>0){$found=true;[$year,$month]=explode('-',$period);rkc_check(abs(lb_monthly_credit($db,$student['NO_INDUK'],'komite',$month,$year)-$credit/100)<.001,'Future Komite credit remains on its source period');break 2;}
    rkc_check($found,'Future Komite source evidence present on unit '.$unit);
}
$report['checks']=$checks;$report['source_receipt']=['virtual_id'=>$virtual,'unit_id'=>2,'source_id'=>'55313','amount'=>75000];
if($out=getenv('SPP_RECEIPT_KOMITE_REPORT'))file_put_contents($out,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
