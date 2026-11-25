<?php
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
require_once __DIR__.'/../includes/global_report_print.php';

$categories=['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang'];
$columns=[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas'],['tanggal','Tanggal Transaksi','datetime']];
foreach($categories as $key=>$label)$columns[]=[$key,$label,'money_optional'];
$columns[]=['total_penerimaan','Total','money_optional'];
$components=[];
foreach([1,2,3,4,5,-1,-1000000001] as $id){
    $components[]=['id'=>$id,'unit_id'=>1,'nis'=>'00123','nama'=>'QA Penerimaan','kelas'=>'6A','tanggal'=>'2026-11-21 09:00:00','kategori_key'=>'spp','nominal'=>100.25];
}
$components[5]['tanggal']='2026-11-21 00:00:00';$components[5]['time_precision']='date';
$components[6]['tanggal']='2026-11-21 00:00:00';$components[6]['time_precision']='datetime';
$components[]=array_replace($components[0],['kategori_key'=>'daftar_ulang','nominal'=>250,'kelas'=>'7A']);
$components[]=array_replace($components[0],['kategori_key'=>'komite','nominal'=>40]);
$components[]=array_replace($components[0],['kategori_key'=>'komite','nominal'=>5]);
$components[]=array_replace($components[0],['unit_id'=>2,'tanggal'=>'2026-11-22 13:00:00']);
$rows=report_receipt_rows($components,$categories,1);
if(count($rows)!==8||count(array_filter($rows,static fn($row)=>$row['unit_id']===1))!==7)throw new RuntimeException('Seven distinct transactions or unit identity lost.');
$mixed=array_values(array_filter($rows,static fn($row)=>$row['unit_id']===1&&$row['_receipt_id']===1))[0];
if($mixed['kelas']!=='6A / 7A'||$mixed['komite']!==45.0||$mixed['total_penerimaan']!==395.25)throw new RuntimeException('Mixed receipt components lost or double counted.');
if(array_sum(array_column($components,'nominal'))!==array_sum(array_column($rows,'total_penerimaan')))throw new RuntimeException('Cash total changed.');
$model=['title'=>'Rekap Penerimaan Harian','subtitle'=>'Tanggal transaksi 21/11/2026 – 22/11/2026','columns'=>$columns,'rows'=>$rows];
$matrix=global_report_matrix($model,'penerimaan',0);
$identity=array_slice(array_column($matrix['columns'],0),0,$matrix['identity_count']);
if(end($identity)!=='tanggal'||$matrix['rows'][0]['tanggal']!=='2026-11-21 00:00:00'||$matrix['rows'][0]['time_precision']!=='datetime'||$matrix['rows'][1]['time_precision']!=='date')throw new RuntimeException('Date or precision missing from repeated identities.');
$screen=global_report_matrix_html($matrix);
if(!str_contains($screen,'<span class="report-time">00:00:00 WIB</span>')||!str_contains($screen,'<span class="report-time">Jam tidak tercatat</span>'))throw new RuntimeException('Screen must distinguish midnight from date-only Legacy.');
$doc=global_report_matrix_excel($matrix,$model,'penerimaan',0);
$doc=global_report_clean_excel_document($doc,'penerimaan');
$book=spp_excel_workbook($doc);$sheet=$book->getActiveSheet();
$dateColumn=array_search('tanggal',array_column($doc['sheets'][0]['sections'][0]['columns'],'key'),true)+1;
foreach(["00:00:00 WIB","Jam tidak tercatat","09:00:00 WIB"] as $offset=>$time){
    $date=$sheet->getCellByColumnAndRow($dateColumn,12+$offset);
    if($date->getDataType()!=='n'||$date->getFormattedValue()!=="21/11/2026\n".$time)throw new RuntimeException('Receipt timestamp cannot be sorted or displayed on two lines in Excel. '.json_encode($date->getFormattedValue()));
    if($date->getStyle()->getAlignment()->getHorizontal()!=='center'||!$date->getStyle()->getAlignment()->getWrapText())throw new RuntimeException('Excel dates must be centered and wrapped.');
}
$book->disconnectWorksheets();
$wide=$model;
for($i=0;$i<16;$i++)$wide['columns'][]=['fee_'.$i,'Komponen '.$i,'money_optional'];
$wideMatrix=global_report_matrix($wide,'penerimaan',0);
$wideMatrix['_clean_layout']=global_report_print_layout($wideMatrix,'landscape');
$bands=global_report_receipt_print_bands($wideMatrix,'landscape');
$leafKeys=[];
if(count($bands)<2)throw new RuntimeException('Wide receipts do not paginate by component.');
foreach($bands as $band){
    $printed=global_report_clean_table($band);
    $hasDateHeader = preg_match('/<th\b[^>]*data-column="tanggal"[^>]*>TANGGAL<\/th>/', $printed);
    if (!$hasDateHeader || !str_contains($printed, '21/11/2026')
        || !str_contains($printed, '<span class="global-clean-transaction-time">Jam tidak tercatat</span>')) {
        throw new RuntimeException('Date or time lost on a component continuation.');
    }
    array_push($leafKeys,...array_column(array_slice($band['columns'],$band['identity_count']),0));
}
if($leafKeys!==array_column(array_slice($wideMatrix['columns'],$wideMatrix['identity_count']),0))throw new RuntimeException('Print bands lost or duplicated receipt components.');
echo "PASS seven receipts, multi-component values, unit identity, report totals, typed dates, and wide print bands\n";
