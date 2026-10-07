<?php
/** Shared, typed report model. No HTML is imported into the XLSX writer. */
function spp_excel_palette(int $unit): array {
    return [1=>['168A46','0B5437','ECF8F0'],2=>['2449D8','203880','EEF2FF'],3=>['C9252D','7F2025','FFF0F1'],0=>['7228D9','472178','F4EEFF']][$unit] ?? ['168A46','0B5437','ECF8F0'];
}
function spp_excel_column(string $key,string $label,string $type='text'): array {
    if($type==='text'&&in_array($key,['jumlah_siswa','jumlah_transaksi'],true))$type='count';
    $width=$type==='datetime'?32:(in_array($type,['money','money_optional'],true)?22:($type==='date'?18:(in_array($type,['number','count'],true)?10:28)));
    if(in_array($key,['nama','NAMA','komponen','nama_biaya','keterangan'],true))$width=38;
    if($key==='no')$width=8;
    if(in_array($key,['kelas','KELAS','export_class','unit','export_unit'],true))$width=16;
    if(in_array($key,['nis','NO_INDUK'],true))$width=22;
    if(in_array($key,['nis_diknas','NO_induk_diknas','export_diknas'],true))$width=24;
    return ['key'=>$key,'label'=>$label,'type'=>$type,'width'=>$width];
}
function spp_excel_document(string $title,string $subtitle,int $unit,array $sheets): array {
    return ['title'=>$title,'subtitle'=>$subtitle,'unit'=>$unit,'school'=>unit_school_name($unit),'unit_label'=>unit_label($unit),
        'generated'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i:s').' WIB',
        'operator'=>(string)($_SESSION['admin_nama']??$_SESSION['admin_username']??'Pengguna'),'sheets'=>$sheets];
}
function spp_excel_section(string $title,array $columns,array $rows,array $totals=[],bool $detail=true): array {
    return compact('title','columns','rows','totals','detail');
}
function spp_excel_filter_text(mysqli $db,array $filters,string $template): string {
    $parts=[];if(in_array($template,['status','per-item','penerimaan'],true)){ $category=(string)($filters['kategori']??'');$parts[]='Kategori: '.($category==='semua'?'Semua':(report_categories($db)[$category]??$category));}$classId=report_class_filter_rombel_id($filters);$level=report_class_filter_level($filters);
    if($classId){foreach(report_classes($db) as $class)if((int)$class['id']===$classId){$parts[]='Kelas: '.class_label($class);break;}}
    elseif($level)$parts[]='Tingkat: '.$level;
    foreach(['status'=>'Status pembayaran','siswa_status'=>'Status siswa','metode'=>'Metode','mutasi'=>'Mutasi','saldo_status'=>'Saldo','q'=>'Pencarian'] as $key=>$label){
        $value=(string)($filters[$key]??'');if($value==='')continue;
        if($key!=='q')$value=['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua','belum_bayar'=>'Belum Bayar','lunas'=>'Lunas','cicilan'=>'Cicilan','saldo_nol'=>'Saldo Nol','ada_saldo'=>'Ada Saldo'][$value]??ucfirst(str_replace('_',' ',$value));
        $parts[]=$label.': '.$value;
    }
    if($filters['operator']??'')foreach(report_operators($db) as $operator)if((string)$operator['id']===(string)$filters['operator']){$parts[]='Operator: '.$operator['nama'].' (@'.$operator['username'].')';break;}
    foreach($filters['_multi']??[] as $key=>$values){$config=$GLOBALS['spp_filter_choices'][$key]??[];$labels=[];foreach($values as $value)$labels[]=$value==='*'?'Semua':($config['options'][$value]??$value);$parts[]=ucfirst(str_replace('_',' ',$key)).': '.implode(', ',$labels);}
    return implode(' | ',$parts);
}
function spp_excel_report_document(array $report,string $template,int $unit): array {
    if(isset($report['sections'])){
        $sections=[];foreach($report['sections'] as $part){$sub=spp_excel_report_document($part,$template,$unit);foreach($sub['sheets'][0]['sections'] as $section){$section['title']=($part['section_title']??$part['title']).' | '.$part['subtitle'];$sections[]=$section;}}
        return spp_excel_document($report['title'],$report['subtitle'],$unit,[['name'=>'Laporan','sections'=>$sections,'signatures'=>false]]);
    }
    $sections=[];$totals=report_money_totals($report,$template);
    if(in_array($template,['setoran','kas-tabungan'],true)){
        $savings=$template==='kas-tabungan';$components=$report['component_rows']??$report['component_summary']??[];
        foreach($components as $i=>&$row)$row['no']=$i+1;unset($row);
        $sections[]=spp_excel_section($savings?'Arus Tabungan':'Komponen Pembayaran',[spp_excel_column('no','No','number'),spp_excel_column('komponen','Komponen Pembayaran'),spp_excel_column('nominal','Nominal','money')],$components,[['label'=>$savings?'Mutasi Bersih':'Total Pembayaran','value'=>$report['component_total']??0]],false);
        $summary=$savings?($report['transaction_summary']??[]):array_map(static fn($r)=>['label'=>$r['metode'],'value'=>$r['nominal']],$report['method_summary']??[]);
        $sections[]=spp_excel_section($savings?'Jumlah Transaksi':'Metode Pembayaran',[spp_excel_column('label',$savings?'Jenis Transaksi':'Metode'),spp_excel_column('value',$savings?'Jumlah':'Nominal',$savings?'count':'money')],$summary,$totals,false);
    }elseif($template==='riwayat-tagihan'){
        $groups=report_billing_history_group_students($report['rows']);$components=report_billing_history_component_columns($groups);
        $columns=[spp_excel_column('no','No','number')];if($unit===0)$columns[]=spp_excel_column('unit','Unit');$columns=array_merge($columns,[spp_excel_column('nis','NIS'),spp_excel_column('nis_diknas','NIS Diknas'),spp_excel_column('nama','Nama Siswa'),spp_excel_column('kelas','Kelas')]);$rows=[];
        foreach($components as $i=>$column)$columns[]=spp_excel_column('component_'.$i,$column['komponen'],'money_optional');$columns[]=spp_excel_column('total_tagihan','Total Tagihan','money');
        foreach($groups as $i=>$student){$student['no']=$i+1;$student['unit']=unit_label($student['unit_id']);$map=array_column($student['components'],null,'komponen_key');foreach($components as $j=>$column)$student['component_'.$j]=isset($map[$column['komponen_key']])?$map[$column['komponen_key']]['tagihan']:null;$rows[]=$student;}
        $sections[]=spp_excel_section('Tagihan per Siswa dan Komponen',$columns,$rows,array_values(array_filter($totals,static fn($t)=>($t['key']??'')==='tagihan')));
    }else{
        $columns=[spp_excel_column('no','No','number')];$rows=$report['rows'];
        foreach($report['columns'] as $c){$columns[]=spp_excel_column($c[0],$c[1],$c[2]??'text');if($c[0]==='nis')$columns[]=spp_excel_column('export_diknas','NIS Diknas');}
        foreach($rows as $i=>&$row){$row['no']=$i+1;$row['export_diknas']=$row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'';}unset($row);
        $sections[]=spp_excel_section($report['title'],$columns,$rows,$totals);
    }
    return spp_excel_document($report['title'],$report['subtitle'],$unit,[['name'=>'Laporan','sections'=>$sections,'signatures'=>in_array($template,['setoran','kas-tabungan'],true)]]);
}
function spp_excel_text($value): string {
    if(is_array($value))$value=(string)($value['text']??'').(($value['sub']??'')!==''?"\n".(string)$value['sub']:'');
    // XML 1.0 cannot represent these characters. Formula-like text remains literal.
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)$value)??'';
}
function spp_excel_library(): void {
    $autoload=dirname(__DIR__).'/vendor/autoload.php';if(is_file($autoload))require_once $autoload;
    if(class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))return;
    http_response_code(503);header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Ekspor Excel belum tersedia</title><main style="max-width:620px;margin:10vh auto;font-family:Arial;padding:24px"><h1>Ekspor Excel belum tersedia</h1><p>Komponen Excel belum terpasang. Jalankan <code>composer install</code> dari folder project, lalu coba kembali.</p></main></html>';exit;
}
function spp_excel_workbook(array $doc): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    spp_excel_library();
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$book->removeSheetByIndex(0);
    $book->getProperties()->setCreator('SistemSPP')->setTitle($doc['title'])->setSubject($doc['subtitle']);
    $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
    [$accent,$dark,$pale]=spp_excel_palette($doc['unit']);
    $line='D9E2EB';$used=[];
    foreach($doc['sheets'] as $definition){
        $sheet=new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($book);
        $name=mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u',' ', $definition['name']),0,31);$name=$name?:'Laporan';$base=$name;$suffix=1;
        while(isset($used[$name]))$name=mb_substr($base,0,27).' '.(++$suffix);$used[$name]=true;
        $sheet->setTitle($name);$book->addSheet($sheet);$sheet->setShowGridlines(false);$sheet->getSheetView()->setZoomScale(90);
        $n=3;$hasDetail=false;foreach($definition['sections'] as $section){$n=max($n,count($section['columns']));$hasDetail=$hasDetail||$section['detail'];}if(!$hasDetail)$n=max(6,$n);
        $letter=static fn(int $c)=>\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $last=$letter($n);$sheet->getDefaultRowDimension()->setRowHeight(23);
        $sheet->getStyle('A1:'.$last.'8')->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->mergeCells('B1:'.$last.'2')->setCellValueExplicit('B1',$doc['school'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('B1')->getFont()->setSize(18)->setBold(true)->getColor()->setRGB($dark);$sheet->getRowDimension(1)->setRowHeight(32);$sheet->getRowDimension(2)->setRowHeight(32);
        $sheet->mergeCells('B3:'.$last.'3')->setCellValue('B3','Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466');
        $sheet->getStyle('B3')->getFont()->setSize(10);$sheet->getRowDimension(3)->setRowHeight(30);
        $logo=dirname(__DIR__).'/assets/img/school-logo.png';
        if(is_file($logo)){$drawing=new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();$drawing->setName('Logo sekolah')->setPath($logo)->setHeight(54)->setCoordinates('A1')->setOffsetX(5)->setOffsetY(5)->setWorksheet($sheet);}
        $sheet->mergeCells('A5:'.$last.'5')->setCellValueExplicit('A5',$doc['title'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A5:'.$last.'5')->getFill()->setFillType('solid')->getStartColor()->setRGB($dark);
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');$sheet->getRowDimension(5)->setRowHeight(38);
        $sheet->mergeCells('A6:'.$last.'6')->setCellValueExplicit('A6','Unit: '.$doc['unit_label'].' | '.$doc['subtitle'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->mergeCells('A7:'.$last.'7')->setCellValueExplicit('A7','Dibuat: '.$doc['generated'].' | Petugas: '.$doc['operator'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A6:'.$last.'7')->getFill()->setFillType('solid')->getStartColor()->setRGB($pale);$sheet->getRowDimension(6)->setRowHeight(34);
        $row=9;$filterSet=false;$widths=[];
        foreach($definition['sections'] as $section){
            $sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValueExplicit('A'.$row,$section['title'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB($pale);$sheet->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB($dark);$row++;
            $header=$row;$headerHeight=38;$count=count($section['columns']);$positions=[];
            foreach($section['columns'] as $i=>$column){
                // Short summary tables span the same width as the main table.
                $start=1+(int)floor($i*$n/$count);$end=(int)floor(($i+1)*$n/$count);$positions[]=[$start,$end];
                $range=$letter($start).$row.':'.$letter($end).$row;if($end>$start)$sheet->mergeCells($range);$headerHeight=max($headerHeight,15*(int)ceil(mb_strlen($column['label'])/max(8,$column['width'])));
                $sheet->setCellValueExplicit($letter($start).$row,$column['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                if($count===$n)$widths[$start]=max($widths[$start]??0,$column['width']);
            }
            $sheet->getStyle('A'.$row.':'.$last.$row)->applyFromArray(['fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$accent]],'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'alignment'=>['horizontal'=>'center','vertical'=>'center','wrapText'=>true]]);$sheet->getRowDimension($row)->setRowHeight($headerHeight);$row++;
            foreach($section['rows'] as $data){$height=23;
                foreach($section['columns'] as $i=>$column){[$start,$end]=$positions[$i];$cell=$letter($start).$row;$range=$cell.':'.$letter($end).$row;if($end>$start)$sheet->mergeCells($range);
                    $value=array_key_exists($column['key'],$data)?$data[$column['key']]:'';$type=$column['type'];$numeric=in_array($type,['money','money_optional','number','count'],true)&&$value!==null&&$value!=='';
                    if($numeric){$sheet->setCellValueExplicit($cell,(float)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$sheet->getStyle($cell)->getNumberFormat()->setFormatCode(in_array($type,['money','money_optional'],true)?'"Rp "#,##0;[Red]"Rp "-#,##0;"Rp "0':'#,##0');}
                    elseif(in_array($type,['date','datetime'],true)&&$value){try{$date=new DateTimeImmutable((string)$value,new DateTimeZone('Asia/Jakarta'));$sheet->setCellValueExplicit($cell,\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$sheet->getStyle($cell)->getNumberFormat()->setFormatCode($type==='date'?'dd/mm/yyyy':'dd/mm/yyyy hh:mm:ss "WIB"');}catch(Throwable $e){$sheet->setCellValueExplicit($cell,spp_excel_text($value),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}}
                    else{$text=$value===null?'—':spp_excel_text($value);$sheet->setCellValueExplicit($cell,$text,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$height=max($height,16*(substr_count($text,"\n")+1+(int)floor(mb_strlen($text)/max(12,$column['width']))));}
                    $align=in_array($type,['number','count'],true)?'center':($numeric?'right':(in_array($column['key'],['no','kelas','KELAS','export_class','unit','export_unit','jenis','status','periode'],true)||$type==='html'?'center':'left'));$alignment=$sheet->getStyle($range)->getAlignment();$alignment->setHorizontal($align)->setVertical('center')->setWrapText(true);if($align!=='center')$alignment->setIndent(1);
                }
                if(($row-$header)%2===0)$sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB('F5F7FA');
                $sheet->getRowDimension($row)->setRowHeight(min(180,$height+5));$row++;
            }
            if(!$section['rows']){$sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValue('A'.$row,'Tidak ada data sesuai filter.');$sheet->getStyle('A'.$row)->getAlignment()->setHorizontal('center');$row++;}
            $endData=$row-1;$sheet->getStyle('A'.$header.':'.$last.$endData)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB($line);
            if($section['detail']&&!$filterSet){$sheet->setAutoFilter('A'.$header.':'.$last.($section['rows']?$endData:$header));$sheet->freezePane('B'.($header+1));$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header,$header);$filterSet=true;}
            foreach($section['totals'] as $total){$sheet->mergeCells('A'.$row.':'.$letter($n-1).$row)->setCellValueExplicit('A'.$row,$total['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$sheet->setCellValueExplicit($last.$row,(float)$total['value'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$sheet->getStyle($last.$row)->getNumberFormat()->setFormatCode('"Rp "#,##0;[Red]"Rp "-#,##0;"Rp "0');$sheet->getStyle('A'.$row.':'.$last.$row)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$dark]],'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$pale]]]);$sheet->getStyle($last.$row)->getAlignment()->setHorizontal('right');$row++;}
            $row+=2;
        }
        $shortWidths=[1=>8,2=>8,3=>32,4=>32,5=>16,6=>16];foreach(range(1,$n) as $c)$sheet->getColumnDimension($letter($c))->setWidth($widths[$c]??(!$hasDetail&&$n===6?$shortWidths[$c]:($c===1?10:32)));
        if(!empty($definition['signatures'])){$mid=max(1,(int)floor($n/2));$sheet->mergeCells('A'.$row.':'.$letter($mid).$row)->setCellValue('A'.$row,'Kasir/Petugas,');$sheet->mergeCells($letter($mid+1).$row.':'.$last.$row)->setCellValue($letter($mid+1).$row,'Bagian Keuangan,');$sheet->getStyle('A'.$row.':'.$last.($row+3))->getAlignment()->setHorizontal('center');$row+=3;$sheet->mergeCells('A'.$row.':'.$letter($mid).$row)->setCellValue('A'.$row,'(________________________)');$sheet->mergeCells($letter($mid+1).$row.':'.$last.$row)->setCellValue($letter($mid+1).$row,'(________________________)');$row+=2;}
        $sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValue('A'.$row,'SistemSPP | Data mengikuti filter dan catatan transaksi saat laporan dibuat.');$sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $totalWidth=0;foreach($sheet->getColumnDimensions() as $dimension)$totalWidth+=$dimension->getWidth();$setup=$sheet->getPageSetup();$setup->setOrientation($n>6?'landscape':'portrait')->setPaperSize($n>9||$totalWidth>160?\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3:\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        if($totalWidth<=220)$setup->setFitToWidth(1)->setFitToHeight(0);else $setup->setScale(90)->setColumnsToRepeatAtLeftByStartAndEnd('A','D');
        $setup->setPrintArea('A1:'.$last.$row);$sheet->getPageMargins()->setTop(.35)->setBottom(.35)->setLeft(.3)->setRight(.3);$sheet->getHeaderFooter()->setOddFooter('&L'.$doc['unit_label'].' | SistemSPP&RHalaman &P / &N');
    }
    $book->setActiveSheetIndex(0);return $book;
}
function spp_excel_preview_html(array $doc): string {
    $e=static fn($v)=>htmlspecialchars(spp_excel_text($v),ENT_QUOTES,'UTF-8');[$accent,$dark,$pale]=spp_excel_palette($doc['unit']);$logo=dirname(__DIR__).'/assets/img/school-logo.png';$image=is_file($logo)?'data:image/png;base64,'.base64_encode(file_get_contents($logo)):'';
    ob_start(); ?>
    <!doctype html><html lang="id"><meta charset="utf-8"><style>body{margin:0;padding:26px;font:14px Calibri,Arial,sans-serif;color:#1e293b;background:#fff}*{box-sizing:border-box}.head{display:flex;gap:18px;align-items:center;margin-bottom:20px}.head img{width:64px;height:64px;object-fit:contain}.head h1{font-size:23px;color:#<?= $dark ?>;margin:0 0 5px}.head p{margin:0;font-size:12px}.banner{background:#<?= $dark ?>;color:#fff;padding:15px 18px;font-size:20px;font-weight:bold}.meta{background:#<?= $pale ?>;padding:10px 18px;line-height:1.6;font-size:13px}.sheet{margin:24px 0}.sheet h2{font-size:18px;color:#<?= $dark ?>}.section-title{background:#<?= $pale ?>;color:#<?= $dark ?>;padding:9px 12px;font-size:15px;margin:20px 0 0}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;margin:0 0 14px}th,td{border:1px solid #d9e2eb;padding:9px 12px;vertical-align:middle}th{background:#<?= $accent ?>;color:#fff;text-align:center}td{white-space:pre-line}tbody tr:nth-child(even){background:#f5f7fa}.money{text-align:right;white-space:nowrap}.center{text-align:center}.total td{background:#<?= $pale ?>;color:#<?= $dark ?>;font-weight:bold}.sign{display:flex;justify-content:space-around;text-align:center;margin:30px 0}.sign p{margin-top:55px}.foot{font-size:11px;color:#64748b;border-top:1px solid #d9e2eb;padding-top:10px}@media(max-width:600px){body{padding:14px}.head h1{font-size:18px}.banner{font-size:17px}table{min-width:600px}.meta{padding:10px}.head{gap:10px}}</style><div class="head"><img src="<?= $image ?>" alt="Logo sekolah"><div><h1><?= $e($doc['school']) ?></h1><p>Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466</p></div></div><div class="banner"><?= $e($doc['title']) ?></div><div class="meta">Unit: <?= $e($doc['unit_label']) ?> | <?= $e($doc['subtitle']) ?><br>Dibuat: <?= $e($doc['generated']) ?> | Petugas: <?= $e($doc['operator']) ?></div>
    <?php foreach($doc['sheets'] as $sheet): ?><section class="sheet"><h2><?= $e($sheet['name']) ?></h2><?php foreach($sheet['sections'] as $section): ?><h3 class="section-title"><?= $e($section['title']) ?></h3><div class="scroll"><table><thead><tr><?php foreach($section['columns'] as $col): ?><th><?= $e($col['label']) ?></th><?php endforeach; ?></tr></thead><tbody><?php if(!$section['rows']): ?><tr><td colspan="<?= count($section['columns']) ?>" class="center">Tidak ada data sesuai filter.</td></tr><?php endif; ?><?php foreach($section['rows'] as $row): ?><tr><?php foreach($section['columns'] as $col): $v=array_key_exists($col['key'],$row)?$row[$col['key']]:'';$money=in_array($col['type'],['money','money_optional'],true); ?><td class="<?= $money?'money':(in_array($col['key'],['no','kelas','KELAS','export_class','unit','export_unit','jenis','status','periode'],true)||in_array($col['type'],['html','number','count'],true)?'center':'') ?>"><?= $money&&$v!==''&&$v!==null?'Rp '.number_format((float)$v,0,',','.'):($v===null?'—':(in_array($col['type'],['date','datetime'],true)&&$v?spp_date_label($v,$col['type']==='datetime'):$e($v))) ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php foreach($section['totals'] as $total): ?><tr class="total"><td colspan="<?= max(1,count($section['columns'])-1) ?>"><?= $e($total['label']) ?></td><td class="money">Rp <?= number_format($total['value'],0,',','.') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endforeach; ?><?php if(!empty($sheet['signatures'])): ?><div class="sign"><div>Kasir/Petugas,<p>(________________________)</p></div><div>Bagian Keuangan,<p>(________________________)</p></div></div><?php endif; ?></section><?php endforeach; ?><p class="foot">SistemSPP | Data mengikuti filter dan catatan transaksi saat laporan dibuat.</p></html>
    <?php return ob_get_clean();
}
function spp_excel_respond(array $doc,bool $download,string $filename,string $downloadUrl,string $backUrl,int $rowCount): void {
    if($download){$book=spp_excel_workbook($doc);$writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$writer->setPreCalculateFormulas(false);
        // Finish generation before sending attachment headers; errors cannot become corrupt workbooks.
        $temp=tempnam(sys_get_temp_dir(),'spp_xlsx_');try{$writer->save($temp);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-zA-Z0-9_-]/','-',$filename).'.xlsx"');header('Cache-Control: no-store, private');header('Content-Length: '.filesize($temp));readfile($temp);}finally{if(is_file($temp))unlink($temp);$book->disconnectWorksheets();}exit;
    }
    require_once __DIR__.'/report_preview.php';render_report_export_preview(spp_excel_preview_html($doc),['file_type'=>'EXCEL','show_print'=>false,'title'=>$doc['title'],'subtitle'=>$doc['subtitle'],'generated'=>$doc['generated'],'row_count'=>$rowCount,'orientation'=>'landscape','download_url'=>$downloadUrl,'back_url'=>$backUrl]);exit;
}
