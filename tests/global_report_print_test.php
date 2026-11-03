<?php
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/../includes/global_report_print.php';
function gp_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$columns=[['no','No','number'],['nis','NIS','text'],['nama','Nama Siswa','text']];$groups=[];$row=['no'=>1,'nis'=>'000123','nama'=>'Nama panjang dengan beberapa kata'];$totals=[];
foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i=>$month){
    $c=['m'.$i,$month.' 2026','money_optional'];$columns[]=$c;$groups[]=['label'=>$c[1],'subtitle'=>'','columns'=>[$c]];$row[$c[0]]=$i===0?null:($i===1?-123456789.25:100.0);$totals[$c[0]]=['value'=>$row[$c[0]]??0];
}
$matrix=['columns'=>$columns,'identity_count'=>3,'groups'=>$groups,'rows'=>[$row],'subtotals'=>$totals];$before=serialize($matrix);$layout=global_report_print_layout($matrix);$html=global_report_clean_table($matrix);
gp_assert(serialize($matrix)===$before,'Layout must not mutate source values or periods');
gp_assert($layout['orientation']==='landscape'&&$layout['font_pt']<=9&&$layout['font_pt']>0,'Wide output scales within A4');
gp_assert($layout['natural_width']*$layout['font_pt']/9<=785.1,'Measured columns fit the A4 content width');
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
gp_assert($xp->query('//tbody/tr/td')->length===15,'All twelve months and all identities remain in the same row');
gp_assert(str_contains($html,'Jan 2026')&&str_contains($html,'Des 2026')&&!str_contains($html,'Januari'),'Only header month names are abbreviated');
gp_assert(str_contains($html,'000123')&&str_contains($html,'-123.456.789,25')&&str_contains($html,'—'),'Leading zero, negative decimals and unknown values remain intact');
gp_assert(global_report_short_months('01/11/2026 | November 2026')==='01/11/2026 | Nov 2026','Dates and years remain intact');
gp_assert(!global_report_clean_template('psb')&&!global_report_clean_template('riwayat-tagihan'),'Special tables keep their own renderer');
$excelColumns=[];foreach($columns as $c)$excelColumns[]=spp_excel_column($c[0],$c[1],$c[2]);
$section=spp_excel_section('Tabungan',$excelColumns,[$row],[],true);
$section['header_groups']=[['label'=>'Januari–Desember 2026','keys'=>array_column(array_slice($columns,3),0)]];$section['identity_count']=3;
$doc=spp_excel_document('Transaksi Tabungan','Status siswa: Aktif | Periode: 2026',1,[['name'=>'Laporan','sections'=>[$section],'signatures'=>false]]);$doc['presentation']='global';$source=serialize($doc['sheets'][0]['sections'][0]['rows']);$doc=global_report_clean_excel_document($doc,'tabungan-siswa');
gp_assert(serialize($doc['sheets'][0]['sections'][0]['rows'])===$source&&$doc['subtitle']==='','Excel presentation leaves rows intact and hides only the requested header');
$book=spp_excel_workbook($doc);$s=$book->getActiveSheet();
gp_assert($s->getCell('B12')->getValue()==='000123'&&$s->getCell('B12')->getDataType()==='s','NIS remains literal text');
gp_assert($s->getCell('E12')->getValue()===-123456789.25&&$s->getCell('E12')->getDataType()==='n','Money remains a signed numeric value');
gp_assert($s->getStyle('E12')->getAlignment()->getShrinkToFit()&&!$s->getStyle('E12')->getAlignment()->getWrapText(),'Native Excel must fit long numbers instead of replacing them with hashes');
gp_assert($s->getCell('D12')->getValue()==='—','Unknown Excel money does not become zero');
gp_assert($s->getPageSetup()->getFitToWidth()===1&&$s->getPageSetup()->getFitToHeight()===0,'Excel fits one page horizontally with unlimited height');
gp_assert($s->getPageSetup()->getRowsToRepeatAtTop()===[1,11],'Both grouped and leaf headers repeat');
gp_assert($s->getStyle('E12')->getFont()->getName()===billing_history_print_font()['family']&&$s->getStyle('E12')->getFill()->getFillType()==='none','Black and white serif body');
gp_assert($s->getCell('A6')->getValue()===null&&!str_contains($s->getHeaderFooter()->getOddFooter(),'SistemSPP'),'Requested header and footer are removed');
gp_assert($s->getStyle('A3')->getFont()->getSize()===10.0,'Savings transaction address uses 10 pt');
$book->disconnectWorksheets();
echo "PASS A4 widths, twelve-month headers, preserved identity/money/unknowns and typed Excel print settings.\n";
