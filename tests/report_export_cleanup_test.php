<?php
/** Output regression: rich month cells, typed money and identity remain faithful. */
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/excel.php';
require_once __DIR__.'/../includes/global_report_print.php';
function cleanup_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$columns=[spp_excel_column('no','No','number'),spp_excel_column('nis','Identitas'),spp_excel_column('nama','Nama Pemilik'),spp_excel_column('kelas','Kelas'),spp_excel_column('month','November 2026','html'),spp_excel_column('amount','Saldo','money_optional')];
$rows=[['no'=>1,'nis'=>'000123','nama'=>'Nama Panjang Untuk Memeriksa Pembungkusan Teks Pemilik','kelas'=>'1A','month'=>['text'=>'Cicilan','sub'=>'Rp -125.000,25'],'amount'=>-125000.25],['no'=>2,'nis'=>'000124','nama'=>'Pemilik Kedua','kelas'=>'1A','month'=>['text'=>'Belum diketahui','sub'=>'Rp 0'],'amount'=>null]];
$source=serialize($rows);
foreach(['status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','setoran','kas-tabungan'] as $template){
    $section=spp_excel_section('Rincian',$columns,$rows,[['label'=>'Total','value'=>-125000.25]],true);
    $section['identity_count']=4;
    $doc=spp_excel_document('Laporan','Status siswa: Aktif | Tahun ajaran: 2026/2027',1,[['name'=>'Laporan','sections'=>[$section],'signatures'=>false]]);$doc['presentation']='global';
    $doc=global_report_clean_excel_document($doc,$template);$preview=global_report_clean_excel_preview($doc);
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$preview);$text=$dom->textContent;
    cleanup_assert(!str_contains($text,'Rp ')&&!str_contains($text,'Status siswa:')&&!str_contains($text,'Unit: SD'),$template.' unwanted display text');
    cleanup_assert(str_contains($text,'-125.000,25')&&str_contains($text,'Belum diketahui')&&str_contains($text,'000123'),$template.' preview values');
    $book=spp_excel_workbook($doc);$sheet=$book->getActiveSheet();$found=0;$unknown=false;
    foreach($sheet->getRowIterator() as $row)foreach($row->getCellIterator() as $cell){
        cleanup_assert(!str_contains((string)$cell->getFormattedValue(),'Rp '),$template.' workbook currency');
        if($cell->getValue()===-125000.25){$found++;cleanup_assert($cell->getDataType()==='n',$template.' money must stay numeric');cleanup_assert(str_contains($cell->getFormattedValue(),'.25')||str_contains($cell->getFormattedValue(),',25'),$template.' precision');}
        if($cell->getValue()==='000123')cleanup_assert($cell->getDataType()==='s',$template.' identity must stay text');
        if($cell->getValue()==='—')$unknown=true;
    }
    cleanup_assert($found===2&&$unknown,$template.' amount, total and unknown retained');
    cleanup_assert($sheet->getCell('A6')->getValue()===null&&$sheet->getRowDimension(6)->getRowHeight()==0,$template.' header space');
    if(global_report_large_address($template))cleanup_assert($sheet->getStyle('A3')->getFont()->getSize()===11.0,$template.' address size');
    if($template==='saldo-tabungan'){
        cleanup_assert($sheet->getStyle('C11')->getAlignment()->getHorizontal()==='center'&&$sheet->getStyle('C11')->getAlignment()->getWrapText(),'Owner name centers and wraps');
        cleanup_assert(str_contains($preview,'global-clean-balance'),'Balance preview alignment');
    }
    cleanup_assert($sheet->getPageSetup()->getFitToWidth()===1&&$sheet->getPageSetup()->getFitToHeight()===0,$template.' pagination');
    $book->disconnectWorksheets();
    cleanup_assert(serialize($doc['sheets'][0]['sections'][0]['rows'])===$source,$template.' source mutated');
}
cleanup_assert(!global_report_hide_context('psb')&&!global_report_hide_context('riwayat-tagihan'),'Unrelated reports remain isolated');
$literal=spp_excel_document('Laporan','',1,[['name'=>'Laporan','sections'=>[spp_excel_section('Catatan',[spp_excel_column('nama','Nama'),spp_excel_column('keterangan','Keterangan'),spp_excel_column('amount','Nominal','money')],[['nama'=>'Pemilik','keterangan'=>'Rp 25 merupakan catatan asli','amount'=>25]],[],true)],'signatures'=>false]]);$literal['presentation']='global';$literal=global_report_clean_excel_document($literal,'tabungan-siswa');$literalBook=spp_excel_workbook($literal);
cleanup_assert($literalBook->getActiveSheet()->getCell('B11')->getValue()==='Rp 25 merupakan catatan asli','Literal transaction notes must not be rewritten');$literalBook->disconnectWorksheets();
cleanup_assert(global_report_print_text(['text'=>'Cicilan','sub'=>'Rp -125.000,25'],['m','Bulan','html'],[],false)==="Cicilan\n-125.000,25",'Layout measures the displayed rich amount');
$artifactDir=(string)getenv('SPP_BROWSER_ARTIFACT_ROOT');
if($artifactDir!==''){
    if(!is_dir($artifactDir)||!str_contains(str_replace('\\','/',$artifactDir),'/backups/spp-management-system/pagination_desktop_'))throw new RuntimeException('Private QA artifact directory required');
    require_once __DIR__.'/../includes/global_report_layout.php';
    $cols=[['no','No','number'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas']];$months=[];
    foreach(['Jul','Agu','Sep','Okt','Nov','Des','Jan','Feb','Mar','Apr','Mei','Jun'] as $i=>$month)$months[]=['m'.$i,$month.' '.($i<6?'2026':'2027'),'html'];
    $cols=array_merge($cols,$months,[['amount','Total Tagihan','money_optional'],['paid','Total Bayar','money'],['balance','Tunggakan','money_optional']]);$fixtureRows=[];
    for($i=1;$i<=270;$i++){
        $row=['no'=>$i,'nis'=>'000'.str_pad((string)$i,6,'0',STR_PAD_LEFT),'nama'=>$i===1?'Fixture Nama Panjang Dengan Banyak Kata Untuk Pembungkusan':'Fixture '.$i,'kelas'=>'4A','amount'=>$i===3?null:123456789012.25,'paid'=>-125000.25,'balance'=>$i===3?null:123456914012.50];
        foreach($months as $c)$row[$c[0]]=['text'=>$i===3?'Belum diketahui':'Cicilan','sub'=>'Rp -125.000,25'];$fixtureRows[]=$row;
    }
    $matrix=['columns'=>$cols,'identity_count'=>4,'groups'=>[['label'=>'Fixture SPP 2026/2027','subtitle'=>'','columns'=>array_slice($cols,4)]],'rows'=>$fixtureRows,'subtotals'=>['amount'=>['value'=>269*123456789012.25],'paid'=>['value'=>270*-125000.25],'balance'=>['value'=>269*123456914012.50]],'currency_prefix'=>false];
    $before=serialize($matrix);$matrix['_clean_layout']=global_report_print_layout($matrix);
    $prefix='<!doctype html><html lang="id"><meta charset="utf-8"><style>@page{margin:10mm 10mm 14mm}body{margin:0}'.global_report_clean_styles(true).'</style><body class="global-clean-report">';$documents=[];$blocks=array_chunk($fixtureRows,80);
    foreach($blocks as $i=>$block)$documents[]=$prefix.global_report_clean_table($matrix,$block,$i*80,$i===count($blocks)-1).'</body></html>';
    file_put_contents($artifactDir.'/final/fixture-large.pdf',global_report_render_pdf_fragments($documents,$matrix['_clean_layout']['orientation'],true));
    unset($matrix['_clean_layout']);cleanup_assert(serialize($matrix)===$before,'Large rendering mutated the input');
    file_put_contents($artifactDir.'/fixture-expected.json',json_encode($matrix,JSON_THROW_ON_ERROR));
}
echo "PASS eight reports: context/currency, typed amounts/identities, unknowns, rich month cells, address and owner alignment.\n";
