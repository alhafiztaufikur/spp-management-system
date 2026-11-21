<?php
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/pagination.php';
require_once __DIR__.'/../includes/excel.php';
function bh_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function bh_row(string $component,?float $remaining,string $period='2026-10',string $year='2026/2027',string $class='4A',string $nis='001'):array{
    return ['unit_id'=>1,'nis'=>$nis,'nama'=>'Siswa '.$nis,'kelas'=>$class,'tingkat'=>4,'master_kelas_id'=>$class==='4A'?1:2,'komponen_key'=>$component,'komponen'=>$component,'tahun_ajaran'=>$year,'periode_code'=>$period,'periode'=>$period,'sisa'=>$remaining,'status'=>'Belum Bayar'];
}
$f=['_billing_years'=>['2026/2027'],'_multi'=>['komponen_tagihan'=>['psb','daftar_ulang','spp','komite']]];
$items=[bh_row('spp',100),bh_row('spp',50,'2026-11'),bh_row('spp',0,'2026-09'),bh_row('daftar_ulang',200,'2026/2027'),bh_row('komite',null),bh_row('psb',70,'',''),bh_row('spp',500,'2025-11','2025/2026'),bh_row('spp',0,'2026-10','2026/2027','4B','002')];
$placements=['1|001'=>['label'=>'2026/2027','kelas'=>'4A','tingkat'=>4,'master_kelas_id'=>1]];
$view=billing_history_project($items,$f,$placements,1);
bh_assert(count($view['sections'])===2&&count($view['rows'])===2,'Separate rombels and one row per student/context');
$r=$view['rows'][0];
bh_assert($r['total']['value']===420.0&&$r['total']['unknown'],'Only chosen-year debt, including one-time PSB; preserve unknown');
bh_assert(str_contains($r['keterangan'],'SPP 2026/2027 (Okt, Nov)')&&!str_contains($r['keterangan'],'Sep'),'Only outstanding months are grouped under their academic year');
bh_assert(str_contains($r['keterangan'],'Daftar Ulang 2026/2027'),'Annual debt keeps its academic-year context');
bh_assert(isset($r['notes']['spp']['periods']['2026-10'],$r['notes']['daftar_ulang']['periods']['2026/2027']),'Source period evidence remains intact');
bh_assert($r['year']==='2026/2027','Academic year remains in the rombel context');
bh_assert(!str_contains($r['keterangan'],'PSB')&&!str_contains($r['keterangan'],'Komite'),'PSB and unproven Komite are omitted from the description');
bh_assert($view['rows'][1]['keterangan']==='Lunas','Proven settled student remains visible');
bh_assert(billing_history_amount($r['amounts']['komite'])==='-','Unknown amounts display a dash');
bh_assert(billing_history_amount($r['total'])==='Rp 420','Mixed subtotal displays the known amounts only');
bh_assert(billing_history_amount([],'psb')==='Rp 0','Absent PSB is a presentation zero');
bh_assert(billing_history_amount([],'komite')==='Rp 0','Absent components use a presentation zero');
bh_assert(billing_history_amount(['known'=>0,'unknown'=>true],'psb')==='-','Unknown PSB displays a dash');
bh_assert(billing_history_amount(['known'=>1,'unknown'=>false,'value'=>70],'psb')==='Rp 70','Recorded PSB keeps its actual amount');
$rollup=$items;$rollup[0]['sisa']=150;$rollup[0]['_outstanding_periods']=['2026-10','2026-11'];unset($rollup[1],$rollup[2]);
$rolled=billing_history_project(array_values($rollup),$f,$placements,1);
bh_assert(billing_history_table($rolled)===billing_history_table($view),'Full reader and monthly rollup yield the same visible model');
$all=$f;$all['_billing_years']=['*'];$allView=billing_history_project($items,$all,$placements,1);
bh_assert(count($allView['rows'])===3&&$allView['grand']['total']['value']===920.0,'All years stay separate; PSB counted once');
$crossYear=billing_history_project([bh_row('spp',100,'2026-12'),bh_row('spp',200,'2027-01'),bh_row('daftar_ulang',300,'2026/2027')],$f,[],1);
bh_assert($crossYear['rows'][0]['keterangan']==='Daftar Ulang 2026/2027 + SPP 2026/2027 (Des, Jan)'&&$crossYear['grand']['total']['value']===600.0,'Cross-calendar months in the same academic year share a label');
$current=array_replace(bh_row('spp',100,'2026-11'),['tagihan'=>300,'terbayar'=>200]);
$future=array_replace(bh_row('spp',200,'2026-12'),['tagihan'=>200,'terbayar'=>0]);
$paymentOnly=array_replace(bh_row('komite',null,'2026-10'),['tagihan'=>null,'terbayar'=>75,'status'=>'Ada Pembayaran']);
$bounds=$f+['_billing_period_projection'=>true,'_billing_show_future'=>true,'_billing_as_of'=>'2026-11-21','periode_awal'=>'2026-10','periode_akhir'=>'2026-12'];
$periodView=billing_history_project([$current,$future,$paymentOnly],$bounds,[],1);
bh_assert(array_column(billing_history_screen_totals($periodView),'value')===[500.0,275.0,300.0],'Screen totals use the full selected period and retain payment evidence with unknown principal');
bh_assert(array_column(billing_history_screen_totals(billing_history_project([],$f,[],1)),'value')===[null,null,null],'Empty results have no fabricated amounts');
bh_assert(billing_history_period_notes('Komite',['2027-07','2027-01','2026-10','2026-12','2027-06'])===['Komite 2026/2027 (Okt, Des, Jan, Jun)','Komite 2027/2028 (Jul)'],'Real academic-year boundaries keep separate groups and missing months are not added');
$unknownOnly=billing_history_project([bh_row('komite',null)],$f,[],1);
bh_assert($unknownOnly['rows'][0]['keterangan']==='-'&&billing_history_amount($unknownOnly['grand']['total'])==='-','Unknown-only records do not imply settlement');
$psbOnly=billing_history_project([bh_row('psb',70,'','')],$f,$placements,1);
bh_assert($psbOnly['rows'][0]['keterangan']==='-'&&$psbOnly['grand']['total']['value']===70.0,'PSB-only debt remains in totals without a note');
$cancelled=bh_row('spp',500);$cancelled['status']='Dibatalkan';$cancelledView=billing_history_project([$cancelled],$f,[],1);
bh_assert($cancelledView['grand']['total']['value']===0.0,'Cancelled bill is not an arrear');
$asOf=$f;$asOf['_billing_as_of']='2026-11-03';
$dueView=billing_history_project([bh_row('spp',100),bh_row('spp',200,'2026-12')],$asOf,[],1);
bh_assert($dueView['grand']['total']['value']===100.0&&!str_contains($dueView['rows'][0]['keterangan'],'Des'),'Future monthly obligations are not current arrears');
$html=billing_history_table($view,[$view['rows'][0]]);
bh_assert(!str_contains($html,'seluruh hasil filter')&&str_contains($html,'Jumlah Rombel')&&str_contains($html,'Keterangan Tagihan'),'Paged display has a concise subtotal and preserves the description');
$dom=new DOMDocument();@$dom->loadHTML(billing_history_table($view));$xp=new DOMXPath($dom);
bh_assert($xp->query('//td[contains(concat(" ",@class," ")," billing-matrix-amount ")]')->length===0,'Amount display classes belong to text, never table cells');
bh_assert($xp->query('//td/span[@class="billing-matrix-amount"]')->length===8,'Every component remains in a separate table cell');
$beforeExport=serialize($view);$doc=spp_excel_report_document(['title'=>'Riwayat Tagihan','_billing_view'=>$view],'riwayat-tagihan',1);
$exported=[];foreach($doc['sheets'][0]['sections'] as $section)foreach($section['rows'] as $row)if(($row['nis']??'')==='002')$exported=$row;
bh_assert(($exported['psb']??null)===0.0,'Excel uses the same absent PSB fallback');
bh_assert($doc['sheets'][0]['sections'][0]['columns'][7]['label']==='TOTAL','Workbook total column uses the same label');
bh_assert(serialize($view)===$beforeExport&&!array_key_exists('psb',$view['rows'][1]['amounts']),'Rendering and export do not add a PSB obligation to the financial model');
foreach(['ordinary','global'] as $presentation){
    $styled=$doc;if($presentation==='global')$styled['presentation']='global';
    $book=spp_excel_workbook($styled);$sheet=$book->getActiveSheet();$checked=0;
    foreach($sheet->getRowIterator() as $excelRow){$n=$excelRow->getRowIndex();if($sheet->getCell('B'.$n)->getValue()!=='001')continue;
        foreach(['A'=>'center','B'=>'center','C'=>'left','I'=>'left'] as $column=>$alignment){$style=$sheet->getStyle($column.$n)->getAlignment();bh_assert($style->getHorizontal()===$alignment&&$style->getVertical()==='center','Excel identity and text alignment agree with the shared report');}
        bh_assert($sheet->getCell('G'.$n)->getValue()==='-'&&(float)$sheet->getCell('H'.$n)->getValue()===420.0&&$sheet->getCell('H'.$n)->getDataType()==='n','Excel keeps known totals numeric and displays a dash for unknown amounts');
        $checked++;
    }
    bh_assert($checked===1,'Styled workbook includes the fixture student');$book->disconnectWorksheets();
}
$pager=pagination_controls(1,3,static fn($p)=>'?page='.$p);
bh_assert(substr_count($pager,'class="desktop-page ')===2&&str_contains($pager,'aria-disabled="true"')&&str_contains($pager,'?page=2'),'Two controls preserve disabled state and target');
bh_assert(pagination_controls(1,1,static fn($p)=>'?page='.$p)==='','No pager for one page');
$chunkView=billing_history_project([bh_row('spp',100),bh_row('spp',200,'2026-10','2026/2027','4A','003')],$f,[],1);
$firstChunk=billing_history_table($chunkView,[$chunkView['rows'][0]],true,true,false);
$lastChunk=billing_history_table($chunkView,[$chunkView['rows'][1]],true,true,true);
bh_assert(str_contains($firstChunk,'<thead><tr class="billing-context-row"><th class="billing-print-context" colspan="9">Tahun Ajaran: 2026/2027')&&!str_contains($firstChunk,'<h3>'),'Plain print context repeats inside the table header instead of becoming an orphan heading');
bh_assert(str_contains(billing_history_table(['rows'=>[],'sections'=>[],'grand'=>['total'=>[]]]),'class="billing-empty" role="status"'),'Empty report uses its own padded status');
bh_assert(!str_contains($firstChunk,'Jumlah Rombel')&&!str_contains($firstChunk,'billing-grand-total')&&str_contains($lastChunk,'Jumlah Rombel'),'Large PDF fragments emit each subtotal/grand total once');
$actions='<!doctype html><nav class="legacy-actions"><button>Simpan</button></nav>';
bh_assert(pagination_legacy_markup($actions)===$actions,'Ordinary Legacy actions are never hidden or rewritten');
$legacy='<!doctype html><nav class="legacy-actions"><a href="?page=2&amp;q=x">Berikutnya</a></nav>';
bh_assert(str_contains(pagination_legacy_markup($legacy),'desktop-pager')&&str_contains(pagination_legacy_markup($legacy),'?page=2&amp;q=x'),'Legacy list links retain filters without JavaScript');
echo "PASS arrears periods, rombel/year grouping, unknown values, PSB, rollup parity and pagination.\n";
