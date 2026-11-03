<?php
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/billing_history_print.php';
function bp_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function bp_item(string $nis,string $class,string $year='2026/2027',int $unit=1):array{
    return ['unit_id'=>$unit,'nis'=>$nis,'nama'=>'Nama <panjang> '.$nis,'kelas'=>$class,'tingkat'=>4,'master_kelas_id'=>$class==='4A'?1:2,'komponen_key'=>'spp','komponen'=>'SPP','tahun_ajaran'=>$year,'periode_code'=>'2026-11','sisa'=>100,'status'=>'Belum Bayar'];
}
$filters=['_billing_years'=>['*'],'_multi'=>['komponen_tagihan'=>['psb','daftar_ulang','spp','komite']]];
$items=[bp_item('001','4A'),bp_item('002','4A'),bp_item('003','4B'),bp_item('004','4B','2025/2026'),bp_item('005','4A','2026/2027',2)];
$view=billing_history_project($items,$filters,[],1);$before=serialize($view);$html=billing_history_print_table($view);
$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
bp_assert($xp->query('//table[@class="billing-print-table"]')->length===3,'One flowing print table per unit/year');
$numbers=[];foreach($xp->query('//tr[contains(@class,"billing-print-student")]/td[1]') as $n)$numbers[]=$n->textContent;
bp_assert($numbers===['1','2','3','4','5'],'Numbering continues across rombels and contexts');
bp_assert($xp->query('//tr[@class="billing-print-group"]')->length===4&&$xp->query('//tr[@class="billing-print-subtotal"]')->length===4,'Every class group has one subtotal');
bp_assert(str_contains($html,'JUMLAH:')&&str_contains($html,'KELAS: 4A')&&str_contains($html,'>100</span>')&&!str_contains($html,'Rp ')&&str_contains($html,'&lt;panjang&gt;'),'Labels, currency-free numbers and escaping');
bp_assert(serialize($view)===$before,'Print layout does not modify the financial projection');
$sameYear=billing_history_project(array_slice($items,0,3),$filters,[],1);
$first=billing_history_print_table($sameYear,array_slice($sameYear['rows'],0,1),0,true,false);
$next=billing_history_print_table($sameYear,array_slice($sameYear['rows'],1),1,true,true);
bp_assert(!str_contains($first,'JUMLAH:')&&!str_contains($first,'billing-grand-total'),'An incomplete fragment has no premature totals');
bp_assert(substr_count($next,'JUMLAH:')===2&&substr_count($next,'billing-grand-total')===1&&str_contains($next,'(lanjutan)'),'Continuation closes each class and the report exactly once');
bp_assert(str_contains($next,'class="billing-print-no">2</td>'),'Fragment numbering uses its original offset');
bp_assert(str_contains(billing_history_print_amount(['known'=>1,'value'=>50,'unknown'=>true]),'>50</span>')&&str_contains(billing_history_print_amount(['known'=>1,'value'=>50,'unknown'=>true]),'belum diketahui'),'Mixed amounts retain the unknown portion');
bp_assert(str_contains(billing_history_print_amount(['unknown'=>true],'psb'),'Belum diketahui')&&!str_contains(billing_history_print_amount(['unknown'=>true],'psb'),'>0</span>'),'Unknown PSB never becomes zero');
bp_assert(str_contains(billing_history_print_amount([],'psb'),'>0</span>'),'Absent PSB keeps its display zero');
bp_assert(str_contains(billing_history_print_table(['rows'=>[],'sections'=>[],'grand'=>['total'=>[]]]),'Tidak ada data sesuai filter.'),'Empty print report');
$psb=['rows'=>[['unit_id'=>1,'nis'=>'0000123','nama'=>'Siswa','kelas'=>'1A','tagihan'=>null,'terbayar'=>100,'sisa'=>null]],'columns'=>[]];
require_once __DIR__.'/../includes/report_psb.php';
$psbHtml=report_psb_print_document($psb,1,'','03/11/2026','QA');
bp_assert(!str_contains($psbHtml,'JUMLAH merupakan')&&!str_contains($psbHtml,'psb-note'),'Requested PSB note and its unused style are removed');
bp_assert(str_contains($psbHtml,'0000123')&&str_contains($psbHtml,'>—</td>'),'PSB identity and unknown state remain');
echo "PASS print contexts, continuous numbering, fragment totals, unknown amounts and PSB note removal.\n";
