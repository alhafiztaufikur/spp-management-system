<?php
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/report_history_page.php';
$checks=0;$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$f=['_billing_years'=>['*'],'_billing_as_of'=>'2026-11-10','_multi'=>['komponen_tagihan'=>['spp','komite']]];
$row=['unit_id'=>1,'nis'=>'00001','nama'=>'Fixture advance','kelas'=>'5','tingkat'=>5,'master_kelas_id'=>0,'komponen_key'=>'spp','komponen'=>'SPP','tahun_ajaran'=>'2027/2028','periode_code'=>'2027-07','sisa'=>0.0,'terbayar'=>300000.0,'status'=>'Lunas'];
$check(billing_history_period_visible($row,$f),'Paid future period visible');
$unpaid=$row;$unpaid['terbayar']=0;$unpaid['sisa']=300000;$unpaid['periode_code']='2027-08';$check(!billing_history_period_visible($unpaid,$f),'Unpaid future period not current arrears');
$partial=$unpaid;$partial['terbayar']=100000;$partial['sisa']=200000;$check(!billing_history_period_visible($partial,$f),'Future partial Legacy credit does not inflate current arrears');
$check(!billing_history_period_visible($row,$f+['_debt_only'=>true]),'Debt readers retain their cutoff');
$view=billing_history_project([$row,$unpaid],$f,[],1);$check(count($view['rows'])===1,'One future receipt context');$check($view['rows'][0]['keterangan']==='Pembayaran di muka','Existing description distinguishes advance settlement');$check($view['grand']['total']['value']===0.0,'Future bills add no current arrears');
$unknown=$row;$unknown['sisa']=null;$unknown['status']='Ada Pembayaran';$view=billing_history_project([$unknown],$f,[],1);$check($view['grand']['total']['unknown'],'Unproven future obligation remains unknown');
$aggregate=report_history_monthly_row(['nis'=>'00001','nama'=>'Fixture advance','kelas'=>'5','master_kelas_id'=>0,'tingkat'=>5,'first_period'=>202607,'read_bill_status'=>'open','read_bill'=>300000,'read_paid'=>300000,'read_fractional'=>0,'tagihan'=>600000,'terbayar'=>600000,'sisa'=>0,'read_count'=>2,'tahun_ajaran'=>'2026/2027','tanggal_dibuat'=>null,'advance_paid'=>1],'spp');
$check($aggregate['_advance_paid'],'Grouped reader retains future settlement evidence');$view=billing_history_project([$aggregate],$f,[],1);$check($view['rows'][0]['keterangan']==='Pembayaran di muka','Grouped periods preserve advance description even with an earlier first month');
echo "PASS $checks future settlement/history cutoff checks.\n";
