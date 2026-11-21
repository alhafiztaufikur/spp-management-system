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
bp_assert(str_contains($html,'JUMLAH:')&&str_contains($html,'KELAS: 4A')&&str_contains($html,'>100</span>')&&str_contains($html,'Total Tunggakan Berjalan: Rp 500')&&!str_contains($html,'seluruh hasil filter')&&str_contains($html,'&lt;panjang&gt;'),'Concise grand total, currency-free detail numbers and escaping');
bp_assert(serialize($view)===$before,'Print layout does not modify the financial projection');
$sameYear=billing_history_project(array_slice($items,0,3),$filters,[],1);
$first=billing_history_print_table($sameYear,array_slice($sameYear['rows'],0,1),0,true,false);
$next=billing_history_print_table($sameYear,array_slice($sameYear['rows'],1),1,true,true);
bp_assert(!str_contains($first,'JUMLAH:')&&!str_contains($first,'billing-grand-total'),'An incomplete fragment has no premature totals');
bp_assert(substr_count($next,'JUMLAH:')===2&&substr_count($next,'billing-grand-total')===1&&str_contains($next,'(lanjutan)'),'Continuation closes each class and the report exactly once');
bp_assert(str_contains($next,'class="billing-print-no">2</td>'),'Fragment numbering uses its original offset');
bp_assert(billing_history_print_amount(['known'=>1,'value'=>50,'unknown'=>true])==='<span class="billing-print-money">50</span>','Mixed amounts print only the known sum');
bp_assert(str_contains(billing_history_print_amount(['unknown'=>true],'psb'),'>-</span>'),'Unknown PSB prints as a dash');
bp_assert(str_contains(billing_history_print_amount([],'psb'),'>0</span>'),'Absent PSB keeps its display zero');
bp_assert(str_contains(billing_history_print_table(['rows'=>[],'sections'=>[],'grand'=>['total'=>[]]]),'Tidak ada data sesuai filter.'),'Empty print report');
$psb=['rows'=>[['unit_id'=>1,'nis'=>'0000123','nama'=>'Siswa','kelas'=>'1A','tagihan'=>null,'terbayar'=>100,'sisa'=>null]],'columns'=>[]];
require_once __DIR__.'/../includes/report_psb.php';
$psbHtml=report_psb_print_document($psb,1,'','03/11/2026','QA');
bp_assert(!str_contains($psbHtml,'JUMLAH merupakan')&&!str_contains($psbHtml,'psb-note'),'Requested PSB note and its unused style are removed');
bp_assert(str_contains($psbHtml,'0000123')&&str_contains($psbHtml,'>—</td>'),'PSB identity and unknown state remain');
require_once __DIR__.'/../includes/excel.php';
$doc=spp_excel_report_document(['title'=>'Riwayat Tagihan Siswa','_billing_view'=>$view],'riwayat-tagihan',1);
$doc['presentation']='global';$doc['subtitle']=$doc['period_caption']='Periode tagihan November 2026 · Tahun ajaran 2026/2027';
$book=spp_excel_workbook($doc);$sheet=$book->getActiveSheet();
bp_assert($sheet->getCell('A6')->getValue()===$doc['period_caption']&&$sheet->getRowDimension(6)->getRowHeight()>0,'Workbook must display the period directly below the report title');
bp_assert(str_starts_with($sheet->getCell('A7')->getValue(),'Dibuat: '),'Workbook must keep the generation date on a separate line');
$book->disconnectWorksheets();
$current=bp_item('007','4A');
$future=array_replace($current,['periode_code'=>'2026-12','sisa'=>200]);
$range=['_billing_period_projection'=>true,'_billing_show_future'=>true,'_billing_as_of'=>'2026-11-21',
    'periode_awal'=>'2026-11','periode_akhir'=>'2026-12','_billing_years'=>['2026/2027','2027/2028']];
$rangedView=billing_history_project([$current,$future],$range,[],1);
$rangedHtml=billing_history_print_table($rangedView);
bp_assert(substr_count($rangedHtml,'Total Tunggakan Berjalan')===1&&!str_contains($rangedHtml,'Total Tagihan Mendatang'),'Print renders only one closing total');
bp_assert(str_contains($rangedHtml,'SPP 2026/2027 (Nov, Des)')&&str_contains($rangedHtml,'>300</span>')&&str_contains($rangedHtml,'Rp 100'),'Selected December detail remains within the student total');
bp_assert(str_contains($rangedHtml,'Tahun ajaran 2027/2028'),'Empty-year message remains visible');
$rangedExcel=spp_excel_report_document(['title'=>'Riwayat Tagihan Siswa','_billing_view'=>$rangedView],'riwayat-tagihan',1);
$sections=$rangedExcel['sheets'][0]['sections'];
$summary=end($sections)['rows'];
bp_assert(count($summary)===2&&$summary[0]===['label'=>'Total Tunggakan Berjalan','amount'=>100.0],'Excel includes only the current total and the empty-year note');
echo "PASS print contexts, continuous numbering, fragment totals, unknown amounts and PSB note removal.\n";
