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
$filtered=[
    '_billing_period_projection'=>true,'_billing_show_future'=>true,'_billing_as_of'=>'2026-11-10',
    '_billing_years'=>['2026/2027','2027/2028','2028/2029'],'periode_awal'=>'2026-07','periode_akhir'=>'2028-06',
];
$july=array_replace($unpaid,['tahun_ajaran'=>'2026/2027','periode_code'=>'2026-07','tanggal_dibuat'=>'2026-11-21','sisa'=>200000]);
$futureDu=array_replace($unpaid,['komponen_key'=>'daftar_ulang','komponen'=>'Daftar Ulang','periode_code'=>'','periode'=>'2027/2028','sisa'=>125000]);
$psb=array_replace($july,['komponen_key'=>'psb','komponen'=>'PSB','periode'=>'Sekali saat masuk','periode_code'=>'','tahun_ajaran'=>'','sisa'=>50000]);
$placement=['1|00001'=>['label'=>'2026/2027','kelas'=>'5','tingkat'=>5,'master_kelas_id'=>0]];
$check(billing_history_matches_period($july,$filtered),'July is selected by its obligation month');
$check(!billing_history_matches_period($july,array_replace($filtered,['periode_awal'=>'2026-11'])),'November creation should not select July');
$check(billing_history_month_bounds(['periode_awal'=>'2027-06','periode_akhir'=>'2026-07'])===['2026-07','2027-06'],'Cross-calendar bounds normalized');
$view=billing_history_project([$july,$unpaid,$futureDu,$psb],$filtered,$placement,1);
$check($view['grand']['total']['value']===250000.0&&$view['future_grand']['total']['value']===425000.0,'Amounts split between current and upcoming');
$check($view['rows'][0]['keterangan']==='SPP 2026/2027 (Jul)','Academic year and month display without a PSB note');
$check(str_contains($view['rows'][1]['keterangan'],'SPP 2027/2028 (Agu)'),'Upcoming billing month visible under its academic year');
$check($view['empty_years']===['2028/2029'],'A selected academic year without rows is identified');
$julyNext=array_replace($unpaid,['periode_code'=>'2027-07']);
$view=billing_history_project([$julyNext,$unpaid,$futureDu],array_replace($filtered,['_billing_as_of'=>'2027-07-01']),[],1);
$check($view['grand']['total']['value']===425000.0&&$view['future_grand']['total']['value']===300000.0,'July and annual DU become current while August is upcoming');
$unknownNext=array_replace($unpaid,['sisa'=>null,'terbayar'=>500,'status'=>'Ada Pembayaran']);
$view=billing_history_project([$unknownNext],$filtered,[],1);
$check($view['future_grand']['total']['unknown']&&!$view['future_grand']['total']['known'],'Upcoming Legacy credit does not fabricate a bill');
$calendar=array_replace($filtered,['_billing_years'=>['2025/2026','2026/2027'],'periode_awal'=>'2026-01','periode_akhir'=>'2026-12']);
$june=array_replace($row,['tahun_ajaran'=>'2025/2026','periode_code'=>'2026-06']);
$months=[];
foreach(['07','08','09','10','11','12'] as $month){
    $months[]=array_replace($july,['periode_code'=>'2026-'.$month,'sisa'=>200000]);
    $months[]=array_replace($july,['komponen_key'=>'komite','komponen'=>'Komite','periode_code'=>'2026-'.$month,'sisa'=>15000]);
}
$withDecember=billing_history_project([$june,...$months,$psb],$calendar,$placement,1);
$student=$withDecember['rows'][1];
$check(count($withDecember['rows'])===2&&$student['keterangan']==='SPP 2026/2027 (Jul, Agu, Sep, Okt, Nov, Des) + Komite 2026/2027 (Jul, Agu, Sep, Okt, Nov, Des)','A June payment and bills through December keep one row per academic year');
$check($student['total']['value']===1340000.0&&$withDecember['grand']['total']['value']===1125000.0&&$withDecember['future_grand']['total']['value']===215000.0,'Student totals include December and the summaries partition the total');
$check($withDecember['detail_count']===14,'Original detail count retained');
$nextPeriod=array_replace($calendar,['_billing_years'=>['2026/2027'],'periode_awal'=>'2026-12','periode_akhir'=>'2027-01']);
$january=array_replace($july,['periode_code'=>'2027-01','sisa'=>50000]);
$cross=billing_history_project([...$months,$january],$nextPeriod,[],1);
$check(count($cross['rows'])===1&&$cross['rows'][0]['keterangan']==='SPP 2026/2027 (Des, Jan) + Komite 2026/2027 (Des)','Future-only cross-calendar periods share the same academic-year label per component');
echo "PASS $checks future settlement/history cutoff checks.\n";
